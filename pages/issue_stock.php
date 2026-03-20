<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

// Get all categories
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

$selected_category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

// Get items based on selected category (only items with stock > 0)
$items = [];
if ($selected_category_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM items WHERE category_id = ? AND current_quantity > 0 ORDER BY item_name");
    $stmt->execute([$selected_category_id]);
    $items = $stmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_id = (int)$_POST['item_id'];
    $quantity = (int)$_POST['quantity'];
    $issued_to = trim($_POST['issued_to']);
    $purpose = trim($_POST['purpose']);
    $date_issued = $_POST['date_issued'];

    $item_row = $pdo->prepare("SELECT current_quantity, item_name FROM items WHERE id=?");
    $item_row->execute([$item_id]);
    $item = $item_row->fetch();

    if (!$item) {
        $error = 'Item not found.';
    } elseif ($item['current_quantity'] < $quantity) {
        $error = 'Insufficient stock! Available: ' . $item['current_quantity'];
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO stock_issuing (item_id, quantity, issued_to, purpose, date_issued, issued_by) VALUES (?,?,?,?,?,?)")
                ->execute([$item_id, $quantity, $issued_to, $purpose, $date_issued, $_SESSION['user_id']]);
            $pdo->prepare("UPDATE items SET current_quantity = current_quantity - ? WHERE id=?")
                ->execute([$quantity, $item_id]);
            logAudit($pdo, $_SESSION['user_id'], 'ISSUE_STOCK', $item_id, $quantity, "Issued $quantity of {$item['item_name']} to: $issued_to — $purpose");
            $pdo->commit();
            $success = 'Stock issued successfully!';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error issuing stock: ' . $e->getMessage();
        }
    }
}

// Get recent issuing transactions
$recent_issuing = $pdo->prepare("
    SELECT i.*, it.item_name, c.name as category_name, u.full_name as issued_by_name
    FROM stock_issuing i
    JOIN items it ON i.item_id = it.id
    LEFT JOIN categories c ON it.category_id = c.id
    JOIN users u ON i.issued_by = u.id
    ORDER BY i.created_at DESC
    LIMIT 20
");
$recent_issuing->execute();
$recent_issuing = $recent_issuing->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<h1>Issue Stock</h1>

<!-- Category Selection -->
<div style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #ddd;">
    <h3 style="margin-top: 0; margin-bottom: 15px;">Select Category</h3>
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="issue_stock.php" style="padding: 8px 16px; background: <?php echo $selected_category_id == 0 ? '#2563eb' : '#f0f0f0'; ?>; color: <?php echo $selected_category_id == 0 ? 'white' : '#333'; ?>; text-decoration: none; border-radius: 20px; font-weight: 500;">All Categories</a>
        <?php foreach ($categories as $cat): ?>
        <a href="?category_id=<?php echo $cat['id']; ?>" 
           style="padding: 8px 16px; background: <?php echo $selected_category_id == $cat['id'] ? '#2563eb' : '#f0f0f0'; ?>; color: <?php echo $selected_category_id == $cat['id'] ? 'white' : '#333'; ?>; text-decoration: none; border-radius: 20px; font-weight: 500;">
            <?php echo htmlspecialchars($cat['name']); ?>
        </a>
        <?php endforeach; ?>
    </div>
    
    <?php if ($selected_category_id > 0): 
        $selected_cat = array_filter($categories, function($c) use ($selected_category_id) { return $c['id'] == $selected_category_id; });
        $selected_cat_name = reset($selected_cat)['name'] ?? '';
    ?>
    <div style="margin-top: 15px; padding: 10px; background: #e6f7ff; border-radius: 5px;">
        <strong>Selected Category:</strong> <?php echo htmlspecialchars($selected_cat_name); ?>
        <?php if (empty($items)): ?>
        <span style="margin-left: 10px; color: #dc2626;"> No items with stock in this category.</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<div class="form-container">
    <?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success;?></div><?php endif; ?>
    <?php if (isset($error)): ?><div class="alert alert-danger"><?php echo $error;?></div><?php endif; ?>
    
    <form method="POST">
        <div class="form-group">
            <label>Select Item</label>
            <select name="item_id" id="itemSelect" required onchange="updateMax()">
                <option value="">— Choose item —</option>
                <?php foreach ($items as $item): ?>
                <option value="<?php echo $item['id'];?>" data-max="<?php echo $item['current_quantity'];?>" <?php echo (isset($_GET['item']) && $_GET['item'] == $item['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($item['item_name']);?> (Available: <?php echo $item['current_quantity'];?>)
                </option>
                <?php endforeach; ?>
            </select>
            <?php if (empty($items) && $selected_category_id > 0): ?>
            <small style="color: #dc2626;">No items with stock available in this category.</small>
            <?php endif; ?>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Quantity</label>
                <input type="number" name="quantity" id="quantity" min="1" required placeholder="Enter quantity">
                <small id="maxNote"></small>
            </div>
            <div class="form-group">
                <label>Date Issued</label>
                <input type="date" name="date_issued" value="<?php echo date('Y-m-d');?>" required>
            </div>
        </div>
        <div class="form-group">
            <label>Issued To</label>
            <input type="text" name="issued_to" required placeholder="Customer / Department">
        </div>
        <div class="form-group">
            <label>Purpose</label>
            <textarea name="purpose" required rows="3" placeholder="Reason for issue"></textarea>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Issue Stock</button>
            <a href="dashboard.php" class="btn">Cancel</a>
        </div>
    </form>
</div>

<script>
function updateMax(){
    var sel=document.getElementById('itemSelect'),q=document.getElementById('quantity'),n=document.getElementById('maxNote');
    if(sel.selectedIndex>0){var m=sel.options[sel.selectedIndex].getAttribute('data-max');q.max=m;n.textContent='Max available: '+m;}
    else{q.removeAttribute('max');n.textContent='';}
}
</script>

<!-- Recent Issuing Transactions Table -->
<div class="card">
    <h3>Recent Issuing Transactions</h3>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Quantity</th>
                    <th>Issued To</th>
                    <th>Purpose</th>
                    <th>Issued By</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_issuing as $trans): ?>
                <tr>
                    <td><?php echo date('d/m/Y H:i', strtotime($trans['created_at'])); ?></td>
                    <td style="font-weight:500"><?php echo htmlspecialchars($trans['item_name']); ?></td>
                    <td><span class="cat-pill <?php echo catClass($trans['category_name'] ?? 'Other');?>"><?php echo htmlspecialchars($trans['category_name'] ?? 'Other');?></span></td>
                    <td><?php echo $trans['quantity']; ?></td>
                    <td><?php echo htmlspecialchars($trans['issued_to'] ?: '—'); ?></td>
                    <td style="max-width:250px; white-space:normal"><?php echo htmlspecialchars($trans['purpose']); ?></td>
                    <td><?php echo htmlspecialchars($trans['issued_by_name']); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($recent_issuing)): ?>
                <tr><td colspan="7" style="text-align:center;color:var(--muted);padding:24px">No issuing transactions yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div style="margin-top:15px; text-align:right">
        <a href="view_transactions.php?type=issuing" class="btn btn-sm">View All Issuing Transactions →</a>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>