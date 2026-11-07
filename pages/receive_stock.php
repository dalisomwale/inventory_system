<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

$categories        = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$selected_cat_id   = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$selected_item_id  = isset($_GET['item_id'])     ? (int)$_GET['item_id']     : 0;
$show_new_item     = isset($_GET['new_item']);

$items         = [];
$selected_item = null;

if ($selected_cat_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM items WHERE category_id = ? ORDER BY item_name");
    $stmt->execute([$selected_cat_id]);
    $items = $stmt->fetchAll();
}
if ($selected_item_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
    $stmt->execute([$selected_item_id]);
    $selected_item = $stmt->fetch();
}

// Add New Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_new_item'])) {
    $item_name    = trim($_POST['item_name']);
    $category_id  = (int)$_POST['category_id'];
    $supplier     = trim($_POST['supplier']);
    $quantity     = (int)$_POST['quantity'];
    $date_received = $_POST['date_received'];
    $reorder_level = (int)$_POST['reorder_level'];
    $description  = trim($_POST['description']);

    if (empty($item_name) || $category_id <= 0 || empty($supplier) || $quantity <= 0) {
        $error = 'Please fill in all required fields.';
    } else {
        $cat = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
        $cat->execute([$category_id]);
        $cat_name = $cat->fetchColumn();

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO items (item_name, category_id, category, description, current_quantity, unit_price, reorder_level) VALUES (?,?,?,?,?,?,?)")
                ->execute([$item_name, $category_id, $cat_name, $description, $quantity, 0.00, $reorder_level]);
            $item_id = $pdo->lastInsertId();
            $pdo->prepare("INSERT INTO stock_receiving (item_id, supplier, quantity, unit_price, date_received, received_by, old_stock, new_stock) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$item_id, $supplier, $quantity, 0.00, $date_received, $_SESSION['user_id'], 0, $quantity]);
            logAudit($pdo, $_SESSION['user_id'], 'ADD_NEW_ITEM_RECEIVE', $item_id, $quantity,
                     "Added new item: $item_name in category: $cat_name. Initial stock: $quantity from: $supplier");
            $pdo->commit();
            $success = "Item '$item_name' added and stock received.";
            header("Location: receive_stock.php?category_id=$category_id&item_id=$item_id");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error: ' . $e->getMessage();
        }
    }
}

// Receive Existing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['receive_existing'])) {
    $item_id       = (int)$_POST['item_id'];
    $supplier      = trim($_POST['supplier']);
    $quantity      = (int)$_POST['quantity'];
    $date_received = $_POST['date_received'];

    $item_row = $pdo->prepare("SELECT item_name, current_quantity, category FROM items WHERE id=?");
    $item_row->execute([$item_id]);
    $item_data = $item_row->fetch();
    $old_stock = $item_data['current_quantity'];
    $new_stock = $old_stock + $quantity;

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO stock_receiving (item_id, supplier, quantity, unit_price, date_received, received_by, old_stock, new_stock) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$item_id, $supplier, $quantity, 0.00, $date_received, $_SESSION['user_id'], $old_stock, $new_stock]);
        $pdo->prepare("UPDATE items SET current_quantity = current_quantity + ? WHERE id = ?")->execute([$quantity, $item_id]);
        logAudit($pdo, $_SESSION['user_id'], 'RECEIVE_STOCK', $item_id, $quantity,
                 "Received $quantity of {$item_data['item_name']}. Old: $old_stock, New: $new_stock. Supplier: $supplier");
        $pdo->commit();
        $success = "Stock received. Previous: $old_stock &rarr; New: $new_stock";
        $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
        $stmt->execute([$item_id]);
        $selected_item = $stmt->fetch();
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Error: ' . $e->getMessage();
    }
}

$recent = $pdo->prepare("
    SELECT r.*, i.item_name, c.name as category_name, u.full_name as received_by_name
    FROM stock_receiving r
    JOIN items i ON r.item_id = i.id
    LEFT JOIN categories c ON i.category_id = c.id
    JOIN users u ON r.received_by = u.id
    ORDER BY r.created_at DESC LIMIT 20
");
$recent->execute();
$recent = $recent->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Receive Stock</h1>
    <a href="dashboard.php" class="btn btn-sm">Back to Dashboard</a>
</div>

<!-- Category Selector -->
<div class="pill-tabs">
    <a href="receive_stock.php" class="pill-tab <?php echo $selected_cat_id == 0 ? 'active' : ''; ?>">All</a>
    <?php foreach ($categories as $cat): ?>
    <a href="?category_id=<?php echo $cat['id']; ?>" class="pill-tab <?php echo $selected_cat_id == $cat['id'] ? 'active' : ''; ?>">
        <?php echo htmlspecialchars($cat['name']); ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
<?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<!-- Mode toggle -->
<div class="action-group" style="margin-bottom:18px;">
    <a href="receive_stock.php?category_id=<?php echo $selected_cat_id; ?>" class="btn <?php echo !$show_new_item ? 'btn-primary' : ''; ?>">Receive Existing Item</a>
    <a href="receive_stock.php?category_id=<?php echo $selected_cat_id; ?>&new_item=1" class="btn <?php echo $show_new_item ? 'btn-primary' : ''; ?>">Add New Item</a>
</div>

<?php if ($show_new_item): ?>
<!-- Add New Item -->
<div class="form-container">
    <div class="form-title">Add New Item to Inventory</div>
    <form method="POST">
        <input type="hidden" name="add_new_item" value="1">
        <div class="form-group">
            <label>Item Name *</label>
            <input type="text" name="item_name" required placeholder="e.g., A4 Paper Ream">
        </div>
        <div class="form-group">
            <label>Category *</label>
            <select name="category_id" required>
                <option value="">— Select category —</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?php echo $cat['id']; ?>" <?php echo ($selected_cat_id == $cat['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($cat['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Description</label>
            <textarea name="description" rows="2" placeholder="Brief description (optional)"></textarea>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Supplier *</label>
                <input type="text" name="supplier" required placeholder="Supplier name">
            </div>
            <div class="form-group">
                <label>Initial Quantity *</label>
                <input type="number" name="quantity" min="1" required placeholder="0">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Reorder Level</label>
                <input type="number" name="reorder_level" min="1" value="10">
                <small>Alert threshold for low stock</small>
            </div>
            <div class="form-group">
                <label>Date Received *</label>
                <input type="date" name="date_received" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-success">Add Item &amp; Receive</button>
            <a href="receive_stock.php?category_id=<?php echo $selected_cat_id; ?>" class="btn">Cancel</a>
        </div>
    </form>
</div>

<?php else: ?>
<!-- Select Existing Item -->
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
                    <?php echo htmlspecialchars($item['item_name']); ?> (Stock: <?php echo $item['current_quantity']; ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <?php if (empty($items) && $selected_cat_id > 0): ?>
            <small style="color:var(--muted)">No items in this category. Use "Add New Item" to get started.</small>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($selected_item): ?>
<div class="form-container">
    <div class="form-title"><?php echo htmlspecialchars($selected_item['item_name']); ?></div>

    <div class="stock-info-box">
        <div class="stock-info-item">
            <div class="label">Current Stock</div>
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
        <input type="hidden" name="receive_existing" value="1">
        <input type="hidden" name="item_id" value="<?php echo $selected_item['id']; ?>">
        <div class="form-group">
            <label>Supplier *</label>
            <input type="text" name="supplier" required placeholder="Supplier name">
        </div>
        <div class="form-group">
            <label>Quantity Received *</label>
            <input type="number" name="quantity" id="qty" min="1" required placeholder="0" oninput="updateNewStock()">
            <small id="newStockMsg"></small>
        </div>
        <div class="form-group">
            <label>Date Received *</label>
            <input type="date" name="date_received" value="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-success">Receive Stock</button>
            <a href="receive_stock.php?category_id=<?php echo $selected_cat_id; ?>" class="btn">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Recent Transactions -->
<div class="card">
    <h3 style="margin-bottom:12px">Recent Receiving</h3>
    <?php if (count($recent) > 0): ?>
    <div class="table-container">
        <table>
            <thead>
                <tr><th>Date</th><th>Item</th><th>Category</th><th>Qty</th><th>Prev Stock</th><th>New Stock</th><th>Supplier</th><th>Received By</th></tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $t): ?>
                <tr>
                    <td style="white-space:nowrap; color:var(--muted); font-size:.76rem"><?php echo date('d/m/Y H:i', strtotime($t['created_at'])); ?></td>
                    <td style="font-weight:500"><?php echo htmlspecialchars($t['item_name']); ?></td>
                    <td><span class="cat-pill"><?php echo htmlspecialchars($t['category_name'] ?? 'Other'); ?></span></td>
                    <td><strong><?php echo $t['quantity']; ?></strong></td>
                    <td style="color:var(--muted)"><?php echo $t['old_stock'] ?? '—'; ?></td>
                    <td style="color:var(--success); font-weight:500"><?php echo $t['new_stock'] ?? '—'; ?></td>
                    <td style="color:var(--muted)"><?php echo htmlspecialchars($t['supplier'] ?: '—'); ?></td>
                    <td style="color:var(--muted)"><?php echo htmlspecialchars($t['received_by_name']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><p>No receiving transactions yet.</p></div>
    <?php endif; ?>
</div>

<script>
function updateNewStock() {
    var current = <?php echo $selected_item['current_quantity'] ?? 0; ?>;
    var qty = parseInt(document.getElementById('qty').value) || 0;
    var msg = document.getElementById('newStockMsg');
    if (qty > 0) {
        msg.innerHTML = 'After receiving, new stock: <strong>' + (current + qty) + '</strong>';
        msg.style.color = 'var(--success)';
    } else {
        msg.innerHTML = '';
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>