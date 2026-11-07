<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

$categories       = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$selected_cat_id  = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$selected_item_id = isset($_GET['item_id'])     ? (int)$_GET['item_id']     : 0;

$items         = [];
$selected_item = null;

if ($selected_cat_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM items WHERE category_id = ? AND current_quantity > 0 ORDER BY item_name");
    $stmt->execute([$selected_cat_id]);
    $items = $stmt->fetchAll();
}
if ($selected_item_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
    $stmt->execute([$selected_item_id]);
    $selected_item = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_id      = (int)$_POST['item_id'];
    $quantity     = (int)$_POST['quantity'];
    $issued_to    = trim($_POST['issued_to']);
    $purpose      = trim($_POST['purpose']);
    $date_issued  = $_POST['date_issued'];

    $item_row = $pdo->prepare("SELECT current_quantity, item_name, category FROM items WHERE id=?");
    $item_row->execute([$item_id]);
    $item      = $item_row->fetch();
    $old_stock = $item['current_quantity'];
    $new_stock = $old_stock - $quantity;

    if (!$item) {
        $error = 'Item not found.';
    } elseif ($item['current_quantity'] < $quantity) {
        $error = 'Insufficient stock. Available: ' . $item['current_quantity'];
    } else {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO stock_issuing (item_id, quantity, issued_to, purpose, date_issued, issued_by, old_stock, new_stock) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$item_id, $quantity, $issued_to, $purpose, $date_issued, $_SESSION['user_id'], $old_stock, $new_stock]);
            $pdo->prepare("UPDATE items SET current_quantity = current_quantity - ? WHERE id=?")->execute([$quantity, $item_id]);
            logAudit($pdo, $_SESSION['user_id'], 'ISSUE_STOCK', $item_id, $quantity,
                     "Issued $quantity of {$item['item_name']} to: $issued_to. Old: $old_stock, New: $new_stock.");
            $pdo->commit();
            $success = "Issued successfully. Previous stock: $old_stock &rarr; New stock: $new_stock";

            $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
            $stmt->execute([$item_id]);
            $selected_item = $stmt->fetch();

            if ($selected_cat_id > 0) {
                $stmt = $pdo->prepare("SELECT * FROM items WHERE category_id = ? AND current_quantity > 0 ORDER BY item_name");
                $stmt->execute([$selected_cat_id]);
                $items = $stmt->fetchAll();
            }
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

$recent = $pdo->prepare("
    SELECT i.*, it.item_name, c.name as category_name, u.full_name as issued_by_name
    FROM stock_issuing i
    JOIN items it ON i.item_id = it.id
    LEFT JOIN categories c ON it.category_id = c.id
    JOIN users u ON i.issued_by = u.id
    ORDER BY i.created_at DESC LIMIT 20
");
$recent->execute();
$recent = $recent->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Issue Stock</h1>
    <a href="dashboard.php" class="btn btn-sm">Back to Dashboard</a>
</div>

<!-- Category Selector -->
<div class="pill-tabs">
    <a href="issue_stock.php" class="pill-tab <?php echo $selected_cat_id == 0 ? 'active' : ''; ?>">All</a>
    <?php foreach ($categories as $cat): ?>
    <a href="?category_id=<?php echo $cat['id']; ?>" class="pill-tab <?php echo $selected_cat_id == $cat['id'] ? 'active' : ''; ?>">
        <?php echo htmlspecialchars($cat['name']); ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
<?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<!-- Item Selection -->
<div class="form-container">
    <div class="form-title">Select Item</div>
    <form method="GET">
        <input type="hidden" name="category_id" value="<?php echo $selected_cat_id; ?>">
        <div class="form-group">
            <label>Choose Item</label>
            <select name="item_id" onchange="this.form.submit()">
                <option value="">— Select an item —</option>
                <?php foreach ($items as $item): ?>
                <option value="<?php echo $item['id']; ?>" <?php echo ($selected_item_id == $item['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($item['item_name']); ?> (Available: <?php echo $item['current_quantity']; ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <?php if (empty($items) && $selected_cat_id > 0): ?>
            <small style="color:var(--muted)">No items with available stock in this category.</small>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($selected_item): ?>
<div class="form-container">
    <div class="form-title"><?php echo htmlspecialchars($selected_item['item_name']); ?></div>

    <div class="stock-info-box">
        <div class="stock-info-item">
            <div class="label">Available Stock</div>
            <div class="value"><?php echo $selected_item['current_quantity']; ?></div>
        </div>
        <div class="stock-info-item">
            <div class="label">Category</div>
            <div class="value" style="font-size:.88rem"><?php echo htmlspecialchars($selected_item['category']); ?></div>
        </div>
        <div class="stock-info-item">
            <div class="label">Reorder Level</div>
            <div class="value"><?php echo $selected_item['reorder_level']; ?></div>
        </div>
    </div>

    <form method="POST">
        <input type="hidden" name="item_id" value="<?php echo $selected_item['id']; ?>">
        <div class="form-group">
            <label>Quantity to Issue *</label>
            <input type="number" name="quantity" id="qty" min="1" max="<?php echo $selected_item['current_quantity']; ?>" required
                   placeholder="0" oninput="updateNewStock()">
            <small id="newStockMsg"></small>
        </div>
        <div class="form-group">
            <label>Issued To *</label>
            <input type="text" name="issued_to" required placeholder="Department, person or customer name">
        </div>
        <div class="form-group">
            <label>Purpose</label>
            <textarea name="purpose" rows="2" placeholder="Reason for issue (optional)"></textarea>
        </div>
        <div class="form-group">
            <label>Date Issued *</label>
            <input type="date" name="date_issued" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-danger" onclick="return confirm('Confirm issue?')">Issue Stock</button>
            <a href="issue_stock.php?category_id=<?php echo $selected_cat_id; ?>" class="btn">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- Recent Transactions -->
<div class="card">
    <h3 style="margin-bottom:12px">Recent Issuing</h3>
    <?php if (count($recent) > 0): ?>
    <div class="table-container">
        <table>
            <thead>
                <tr><th>Date</th><th>Item</th><th>Category</th><th>Qty</th><th>Prev Stock</th><th>New Stock</th><th>Issued To</th><th>Purpose</th><th>By</th></tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $t): ?>
                <tr>
                    <td style="white-space:nowrap; color:var(--muted); font-size:.76rem"><?php echo date('d/m/Y H:i', strtotime($t['created_at'])); ?></td>
                    <td style="font-weight:500"><?php echo htmlspecialchars($t['item_name']); ?></td>
                    <td><span class="cat-pill"><?php echo htmlspecialchars($t['category_name'] ?? 'Other'); ?></span></td>
                    <td><strong><?php echo $t['quantity']; ?></strong></td>
                    <td style="color:var(--muted)"><?php echo $t['old_stock'] ?? '—'; ?></td>
                    <td style="color:var(--danger); font-weight:500"><?php echo $t['new_stock'] ?? '—'; ?></td>
                    <td><?php echo htmlspecialchars($t['issued_to'] ?: '—'); ?></td>
                    <td style="color:var(--muted); font-size:.76rem; max-width:160px; white-space:normal"><?php echo htmlspecialchars($t['purpose'] ?: '—'); ?></td>
                    <td style="color:var(--muted)"><?php echo htmlspecialchars($t['issued_by_name']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><p>No issuing transactions yet.</p></div>
    <?php endif; ?>
</div>

<script>
function updateNewStock() {
    var current = <?php echo $selected_item['current_quantity'] ?? 0; ?>;
    var qty = parseInt(document.getElementById('qty').value) || 0;
    var msg = document.getElementById('newStockMsg');
    if (qty > current) {
        msg.innerHTML = 'Cannot issue more than available stock.';
        msg.style.color = 'var(--danger)';
    } else if (qty > 0) {
        msg.innerHTML = 'After issue, new stock: <strong>' + (current - qty) + '</strong>';
        msg.style.color = 'var(--muted)';
    } else {
        msg.innerHTML = '';
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>