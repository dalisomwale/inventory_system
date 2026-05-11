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
        $success = "Stock received. Previous: $old_stock → New: $new_stock";
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
    <a href="dashboard.php" class="btn btn-outline btn-sm">← Back to Dashboard</a>
</div>

<!-- Category Filter Tabs -->
<div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 24px; padding: 8px 0;">
    <a href="receive_stock.php" class="pill-tab <?php echo $selected_cat_id == 0 ? 'active' : ''; ?>" style="padding: 8px 20px; background: <?php echo $selected_cat_id == 0 ? '#2563eb' : '#f1f5f9'; ?>; color: <?php echo $selected_cat_id == 0 ? 'white' : '#475569'; ?>; border-radius: 40px; text-decoration: none; font-size: 13px; font-weight: 500; transition: all 0.2s;">
            All
        </a>
    <?php foreach ($categories as $cat): ?>
    <a href="?category_id=<?php echo $cat['id']; ?>" class="pill-tab <?php echo $selected_cat_id == $cat['id'] ? 'active' : ''; ?>" style="padding: 8px 20px; background: <?php echo $selected_cat_id == $cat['id'] ? '#2563eb' : '#f1f5f9'; ?>; color: <?php echo $selected_cat_id == $cat['id'] ? 'white' : '#475569'; ?>; border-radius: 40px; text-decoration: none; font-size: 13px; font-weight: 500; transition: all 0.2s;">
        <?php echo htmlspecialchars($cat['name']); ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Mode Toggle Buttons -->
<div style="display: flex; gap: 12px; margin-bottom: 24px;">
    <a href="receive_stock.php?category_id=<?php echo $selected_cat_id; ?>" class="btn <?php echo !$show_new_item ? 'btn-primary' : 'btn-outline'; ?>" style="<?php echo !$show_new_item ? '' : 'border: 1px solid #e2e8f0;'; ?>">
        Receive Existing Item
    </a>
    <a href="receive_stock.php?category_id=<?php echo $selected_cat_id; ?>&new_item=1" class="btn <?php echo $show_new_item ? 'btn-primary' : 'btn-outline'; ?>" style="<?php echo $show_new_item ? '' : 'border: 1px solid #e2e8f0;'; ?>">
        Add New Item
    </a>
</div>

<?php if (isset($success)): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>
<?php if (isset($error)): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<?php if ($show_new_item): ?>
<!-- Add New Item Form -->
<div class="card">
    <div class="card-header">
        <h3>Add New Item to Inventory</h3>
    </div>
    <div class="card-body">
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
                <textarea name="description" rows="3" placeholder="Brief description (optional)"></textarea>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label>Supplier *</label>
                    <input type="text" name="supplier" required placeholder="Supplier name">
                </div>
                <div class="form-group">
                    <label>Initial Quantity *</label>
                    <input type="number" name="quantity" min="1" required placeholder="0">
                </div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
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
            
            <div style="display: flex; gap: 12px; margin-top: 20px;">
                <button type="submit" class="btn btn-success">Add Item & Receive Stock</button>
                <a href="receive_stock.php?category_id=<?php echo $selected_cat_id; ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php else: ?>
<!-- Select Existing Item -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
        <h3>Select Item</h3>
    </div>
    <div class="card-body">
        <form method="GET">
            <input type="hidden" name="category_id" value="<?php echo $selected_cat_id; ?>">
            <div class="form-group">
                <label>Choose an item to receive stock</label>
                <select name="item_id" onchange="this.form.submit()" style="width: 100%; padding: 12px; border-radius: 10px; border: 1px solid #e2e8f0;">
                    <option value="">— Select an item —</option>
                    <?php foreach ($items as $item): ?>
                    <option value="<?php echo $item['id']; ?>" <?php echo ($selected_item_id == $item['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($item['item_name']); ?> (Current Stock: <?php echo $item['current_quantity']; ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($items) && $selected_cat_id > 0): ?>
                <small style="color: #94a3b8;">No items in this category. Use "Add New Item" to get started.</small>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<?php if ($selected_item): ?>
<!-- Receive Stock Form -->
<div class="card">
    <div class="card-header">
        <h3>Receive Stock: <?php echo htmlspecialchars($selected_item['item_name']); ?></h3>
    </div>
    <div class="card-body">
        <!-- Stock Info Box -->
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; padding: 16px; background: #f8fafc; border-radius: 12px;">
            <div>
                <div style="font-size: 11px; color: #64748b; margin-bottom: 4px;">Current Stock</div>
                <div style="font-size: 24px; font-weight: 700;"><?php echo $selected_item['current_quantity']; ?></div>
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
            <input type="hidden" name="receive_existing" value="1">
            <input type="hidden" name="item_id" value="<?php echo $selected_item['id']; ?>">
            
            <div class="form-group">
                <label>Supplier *</label>
                <input type="text" name="supplier" required placeholder="Supplier name">
            </div>
            
            <div class="form-group">
                <label>Quantity Received *</label>
                <input type="number" name="quantity" id="qty" min="1" required placeholder="0" oninput="updateNewStock()">
                <small id="newStockMsg" style="display: block; margin-top: 5px;"></small>
            </div>
            
            <div class="form-group">
                <label>Date Received *</label>
                <input type="date" name="date_received" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            
            <div style="display: flex; gap: 12px; margin-top: 20px;">
                <button type="submit" class="btn btn-success">📥 Receive Stock</button>
                <a href="receive_stock.php?category_id=<?php echo $selected_cat_id; ?>" class="btn btn-outline">Cancel</a>
            </div>
        </form>
    </div>
</div>

<script>
function updateNewStock() {
    var current = <?php echo $selected_item['current_quantity'] ?? 0; ?>;
    var qty = parseInt(document.getElementById('qty').value) || 0;
    var msg = document.getElementById('newStockMsg');
    if (qty > 0) {
        msg.innerHTML = '✓ After receiving, new stock will be: <strong>' + (current + qty) + '</strong> units';
        msg.style.color = '#10b981';
    } else {
        msg.innerHTML = '';
    }
}
</script>
<?php endif; ?>
<?php endif; ?>

<!-- Recent Transactions -->
<div class="card" style="margin-top: 24px;">
    <div class="card-header">
        <h3>Recent Receiving Transactions</h3>
    </div>
    <?php if (count($recent) > 0): ?>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Qty</th>
                    <th>Prev Stock</th>
                    <th>New Stock</th>
                    <th>Supplier</th>
                    <th>Received By</th>
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
                    <td style="color:#10b981; font-weight:500"><?php echo $t['new_stock'] ?? '—'; ?></td>
                    <td style="color:#64748b"><?php echo htmlspecialchars($t['supplier'] ?: '—'); ?></td>
                    <td style="color:#64748b"><?php echo htmlspecialchars($t['received_by_name']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="card-body" style="text-align: center; padding: 48px; color: #94a3b8;">
        <div style="font-size: 48px; margin-bottom: 16px;">📭</div>
        <p>No receiving transactions yet.</p>
        <p style="font-size: 13px; margin-top: 8px;">Start by receiving stock using the form above.</p>
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
