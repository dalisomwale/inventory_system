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
            $success = "Issued successfully. Previous stock: $old_stock → New stock: $new_stock";

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
    <a href="dashboard.php" class="btn btn-outline btn-sm">← Back to Dashboard</a>
</div>

<!-- Category Filter Tabs -->
<div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 24px; padding: 8px 0;">
    <a href="issue_stock.php" class="pill-tab <?php echo $selected_cat_id == 0 ? 'active' : ''; ?>" style="padding: 8px 20px; background: <?php echo $selected_cat_id == 0 ? '#2563eb' : '#f1f5f9'; ?>; color: <?php echo $selected_cat_id == 0 ? 'white' : '#475569'; ?>; border-radius: 40px; text-decoration: none; font-size: 13px; font-weight: 500; transition: all 0.2s;">
        All
    </a>
    <?php foreach ($categories as $cat): ?>
    <a href="?category_id=<?php echo $cat['id']; ?>" class="pill-tab <?php echo $selected_cat_id == $cat['id'] ? 'active' : ''; ?>" style="padding: 8px 20px; background: <?php echo $selected_cat_id == $cat['id'] ? '#2563eb' : '#f1f5f9'; ?>; color: <?php echo $selected_cat_id == $cat['id'] ? 'white' : '#475569'; ?>; border-radius: 40px; text-decoration: none; font-size: 13px; font-weight: 500; transition: all 0.2s;">
        <?php echo htmlspecialchars($cat['name']); ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if (isset($success)): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>
<?php if (isset($error)): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<!-- Select Existing Item -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
        <h3>Select Item to Issue</h3>
    </div>
    <div class="card-body">
        <form method="GET">
            <input type="hidden" name="category_id" value="<?php echo $selected_cat_id; ?>">
            <div class="form-group">
                <label>Choose an item to issue from stock</label>
                <select name="item_id" onchange="this.form.submit()" style="width: 100%; padding: 12px; border-radius: 10px; border: 1px solid #e2e8f0; font-size: 14px;">
                    <option value="">— Select an item —</option>
                    <?php foreach ($items as $item): ?>
                    <option value="<?php echo $item['id']; ?>" <?php echo ($selected_item_id == $item['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($item['item_name']); ?> (Available: <?php echo $item['current_quantity']; ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($items) && $selected_cat_id > 0): ?>
                <small style="display: block; margin-top: 8px; color: #dc2626;">
                    ⚠️ No items with available stock in this category.
                </small>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if ($selected_item): ?>
<!-- Issue Stock Form -->
<div class="card">
    <div class="card-header">
        <h3>Issue Stock: <?php echo htmlspecialchars($selected_item['item_name']); ?></h3>
    </div>
    <div class="card-body">
        <!-- Stock Info Box -->
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; padding: 16px; background: #f8fafc; border-radius: 12px;">
            <div>
                <div style="font-size: 11px; color: #64748b; margin-bottom: 4px;">Available Stock</div>
                <div style="font-size: 24px; font-weight: 700; <?php echo $selected_item['current_quantity'] <= $selected_item['reorder_level'] ? 'color: #dc2626;' : ''; ?>">
                    <?php echo $selected_item['current_quantity']; ?>
                </div>
                <?php if ($selected_item['current_quantity'] <= $selected_item['reorder_level']): ?>
                <div style="font-size: 11px; color: #dc2626; margin-top: 4px;">⚠️ Low Stock Alert</div>
                <?php endif; ?>
            </div>
            <div>
                <div style="font-size: 11px; color: #64748b; margin-bottom: 4px;">Category</div>
                <div style="font-size: 14px; font-weight: 500;"><?php echo htmlspecialchars($selected_item['category']); ?></div>
            </div>
            <div>
                <div style="font-size: 11px; color: #64748b; margin-bottom: 4px;">Reorder Level</div>
                <div style="font-size: 14px; font-weight: 500;"><?php echo $selected_item['reorder_level']; ?></div>
            </div>
        </div>
        
        <form method="POST">
            <input type="hidden" name="item_id" value="<?php echo $selected_item['id']; ?>">
            
            <div class="form-group">
                <label>Quantity to Issue *</label>
                <input type="number" name="quantity" id="qty" min="1" max="<?php echo $selected_item['current_quantity']; ?>" required 
                       placeholder="Enter quantity" oninput="updateNewStock()">
                <small id="newStockMsg" style="display: block; margin-top: 5px;"></small>
            </div>
            
            <div class="form-group">
                <label>Issued To *</label>
                <input type="text" name="issued_to" required placeholder="Department, technician name, or customer">
                <small>Example: Maintenance Bay A, Technician Joseph, or Customer name</small>
            </div>
            
            <div class="form-group">
                <label>Purpose / Reason</label>
                <textarea name="purpose" rows="3" placeholder="Reason for issuing this item (optional)"></textarea>
            </div>
            
            <div class="form-group">
                <label>Date Issued *</label>
                <input type="date" name="date_issued" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            
            <div style="display: flex; gap: 12px; margin-top: 20px;">
                <button type="submit" class="btn btn-danger" onclick="return confirm('Confirm issue? This will reduce stock permanently.')">
                    📤 Issue Stock
                </button>
                <a href="issue_stock.php?category_id=<?php echo $selected_cat_id; ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
function updateNewStock() {
    var current = <?php echo $selected_item['current_quantity'] ?? 0; ?>;
    var qty = parseInt(document.getElementById('qty').value) || 0;
    var msg = document.getElementById('newStockMsg');
    if (qty > current) {
        msg.innerHTML = '⚠️ Cannot issue more than available stock. Maximum: ' + current;
        msg.style.color = '#dc2626';
    } else if (qty > 0) {
        var newStock = current - qty;
        msg.innerHTML = '✓ After issue, remaining stock: <strong>' + newStock + '</strong> units';
        if (newStock <= <?php echo $selected_item['reorder_level']; ?>) {
            msg.innerHTML += ' ⚠️ This will trigger a low stock alert!';
            msg.style.color = '#f59e0b';
        } else {
            msg.style.color = '#10b981';
        }
    } else {
        msg.innerHTML = '';
    }
}
</script>
<?php endif; ?>

<!-- Recent Transactions -->
<div class="card" style="margin-top: 24px;">
    <div class="card-header">
        <h3>Recent Issuing Transactions</h3>
    </div>
    <?php if (count($recent) > 0): ?>
    <div class="table-container">
        <table style="width: 100%;">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Qty</th>
                    <th>Prev Stock</th>
                    <th>New Stock</th>
                    <th>Issued To</th>
                    <th>Purpose</th>
                    <th>Issued By</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $t): ?>
                <tr>
                    <td style="white-space:nowrap; color:#64748b; font-size:12px;"><?php echo date('d/m/Y H:i', strtotime($t['created_at'])); ?></td>
                    <td style="font-weight:500"><?php echo htmlspecialchars($t['item_name']); ?></td>
                    <td><span class="status-badge status-in-stock" style="background:#f1f5f9; color:#475569;"><?php echo htmlspecialchars($t['category_name'] ?? 'Other'); ?></span></td>
                    <td><strong><?php echo $t['quantity']; ?></strong></td>
                    <td style="color:#64748b"><?php echo $t['old_stock'] ?? '—'; ?></td>
                    <td style="color:#dc2626; font-weight:500"><?php echo $t['new_stock'] ?? '—'; ?></td>
                    <td style="color:#64748b"><?php echo htmlspecialchars($t['issued_to'] ?: '—'); ?></td>
                    <td style="color:#64748b; font-size:12px; max-width: 180px; white-space: normal;"><?php echo htmlspecialchars($t['purpose'] ?: '—'); ?></td>
                    <td style="color:#64748b"><?php echo htmlspecialchars($t['issued_by_name']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="card-body" style="text-align: center; padding: 48px; color: #94a3b8;">
        <div style="font-size: 48px; margin-bottom: 16px;">📤</div>
        <p>No issuing transactions yet.</p>
        <p style="font-size: 13px; margin-top: 8px;">Start by issuing stock to technicians or departments.</p>
    </div>
    <?php endif; ?>
</div>

<style>
.pill-tab.active {
    background: #2563eb !important;
    color: white !important;
}
.pill-tab:not(.active):hover {
    background: #e2e8f0 !important;
}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
