<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

// Get all categories
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

$selected_category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$selected_item_id = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;
$show_new_item_form = isset($_GET['new_item']) ? true : false;

// Get items based on selected category
$items = [];
$selected_item = null;
if ($selected_category_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM items WHERE category_id = ? ORDER BY item_name");
    $stmt->execute([$selected_category_id]);
    $items = $stmt->fetchAll();
}

if ($selected_item_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
    $stmt->execute([$selected_item_id]);
    $selected_item = $stmt->fetch();
}

// Handle Add New Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_new_item'])) {
    $item_name = trim($_POST['item_name']);
    $category_id = (int)$_POST['category_id'];
    $supplier = trim($_POST['supplier']);
    $quantity = (int)$_POST['quantity'];
    $date_received = $_POST['date_received'];
    $reorder_level = (int)$_POST['reorder_level'];
    $description = trim($_POST['description']);
    
    // Validate
    if (empty($item_name) || $category_id <= 0 || empty($supplier) || $quantity <= 0) {
        $error = 'Please fill in all required fields.';
    } else {
        // Get category name
        $cat = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
        $cat->execute([$category_id]);
        $cat_name = $cat->fetchColumn();
        
        $pdo->beginTransaction();
        try {
            // Insert new item
            $insert = $pdo->prepare("
                INSERT INTO items (item_name, category_id, category, description, current_quantity, unit_price, reorder_level) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $insert->execute([$item_name, $category_id, $cat_name, $description, $quantity, 0.00, $reorder_level]);
            $item_id = $pdo->lastInsertId();
            
            // Record receiving transaction with old/new stock
            $insert_recv = $pdo->prepare("
                INSERT INTO stock_receiving (item_id, supplier, quantity, unit_price, date_received, received_by, old_stock, new_stock) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insert_recv->execute([$item_id, $supplier, $quantity, 0.00, $date_received, $_SESSION['user_id'], 0, $quantity]);
            
            logAudit($pdo, $_SESSION['user_id'], 'ADD_NEW_ITEM_RECEIVE', $item_id, $quantity, 
                     "Added new item: $item_name in category: $cat_name. Initial stock: $quantity from: $supplier");
            $pdo->commit();
            $success = "New item '$item_name' added and stock received successfully!";
            
            // Redirect to clear the form
            header("Location: receive_stock.php?category_id=$category_id&item_id=$item_id");
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error adding new item: ' . $e->getMessage();
        }
    }
}

// Handle Receive Existing Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['receive_existing'])) {
    $item_id = (int)$_POST['item_id'];
    $supplier = trim($_POST['supplier']);
    $quantity = (int)$_POST['quantity'];
    $date_received = $_POST['date_received'];
    
    // Get current stock before update
    $item = $pdo->prepare("SELECT item_name, current_quantity, category FROM items WHERE id=?");
    $item->execute([$item_id]);
    $item_data = $item->fetch();
    $old_stock = $item_data['current_quantity'];
    $new_stock = $old_stock + $quantity;
    
    $pdo->beginTransaction();
    try {
        // Record receiving transaction
        $insert_recv = $pdo->prepare("
            INSERT INTO stock_receiving (item_id, supplier, quantity, unit_price, date_received, received_by, old_stock, new_stock) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert_recv->execute([$item_id, $supplier, $quantity, 0.00, $date_received, $_SESSION['user_id'], $old_stock, $new_stock]);
        
        // Update item quantity
        $update = $pdo->prepare("UPDATE items SET current_quantity = current_quantity + ? WHERE id = ?");
        $update->execute([$quantity, $item_id]);
        
        logAudit($pdo, $_SESSION['user_id'], 'RECEIVE_STOCK', $item_id, $quantity, 
                 "Received $quantity of {$item_data['item_name']}. Old stock: $old_stock, New stock: $new_stock. Supplier: $supplier");
        $pdo->commit();
        $success = "Stock received successfully! Old stock: $old_stock, New stock: $new_stock";
        
        // Refresh item data
        $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
        $stmt->execute([$item_id]);
        $selected_item = $stmt->fetch();
        
        // Refresh items list
        if ($selected_category_id > 0) {
            $stmt = $pdo->prepare("SELECT * FROM items WHERE category_id = ? ORDER BY item_name");
            $stmt->execute([$selected_category_id]);
            $items = $stmt->fetchAll();
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Error receiving stock: ' . $e->getMessage();
    }
}

// Get recent receiving transactions with old/new stock
$recent_receiving = $pdo->prepare("
    SELECT r.*, i.item_name, c.name as category_name, u.full_name as received_by_name
    FROM stock_receiving r
    JOIN items i ON r.item_id = i.id
    LEFT JOIN categories c ON i.category_id = c.id
    JOIN users u ON r.received_by = u.id
    ORDER BY r.created_at DESC
    LIMIT 20
");
$recent_receiving->execute();
$recent_receiving = $recent_receiving->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<style>
.receive-container {
    max-width: 1200px;
    margin: 0 auto;
}

.category-selector {
    background: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    border: 1px solid #ddd;
}

.category-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 10px;
}

.category-btn {
    padding: 8px 16px;
    border: 1px solid #ddd;
    border-radius: 20px;
    background: white;
    text-decoration: none;
    color: #333;
    transition: all 0.2s;
}

.category-btn:hover {
    border-color: #2563eb;
    background: #eff6ff;
}

.category-btn.active {
    background: #2563eb;
    color: white;
    border-color: #2563eb;
}

.form-card {
    background: white;
    padding: 25px;
    border-radius: 8px;
    margin-bottom: 25px;
    border: 1px solid #ddd;
}

.form-title {
    font-size: 1.2rem;
    font-weight: 600;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid #2563eb;
}

.form-title i {
    font-size: 1rem;
    font-weight: normal;
    color: #666;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    font-size: 0.85rem;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-size: 0.9rem;
}

.form-group input:focus,
.form-group select:focus,
.form-group textarea:focus {
    outline: none;
    border-color: #2563eb;
}

.stock-info {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
}

.stock-info h4 {
    margin: 0 0 10px 0;
    color: #166534;
}

.stock-values {
    display: flex;
    gap: 30px;
    margin-top: 10px;
    flex-wrap: wrap;
}

.stock-value {
    font-size: 1.1rem;
}

.stock-value .label {
    font-weight: 500;
    color: #666;
}

.stock-value .number {
    font-weight: 700;
    font-size: 1.3rem;
    color: #166534;
}

.btn {
    padding: 10px 20px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-size: 0.9rem;
    text-decoration: none;
    display: inline-block;
}

.btn-primary {
    background: #2563eb;
    color: white;
}

.btn-primary:hover {
    background: #1d4ed8;
}

.btn-success {
    background: #16a34a;
    color: white;
}

.btn-success:hover {
    background: #15803d;
}

.btn-warning {
    background: #f59e0b;
    color: white;
}

.btn-warning:hover {
    background: #d97706;
}

.btn-secondary {
    background: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background: #5a6268;
}

.form-actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
    justify-content: flex-end;
}

.alert {
    padding: 12px 15px;
    border-radius: 6px;
    margin-bottom: 20px;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-danger {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.recent-table {
    width: 100%;
    border-collapse: collapse;
}

.recent-table th {
    text-align: left;
    padding: 12px;
    background: #f8f9fa;
    border-bottom: 2px solid #ddd;
    font-weight: 600;
}

.recent-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #eee;
}

.cat-pill {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    background: #e6f7ff;
    color: #0050b3;
}

.stock-change .old {
    color: #dc2626;
}

.stock-change .new {
    color: #16a34a;
}

.action-buttons {
    display: flex;
    gap: 15px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.toggle-btn {
    padding: 10px 20px;
    border-radius: 6px;
    text-decoration: none;
    font-weight: 500;
}

.toggle-btn.active {
    background: #2563eb;
    color: white;
}

.toggle-btn.inactive {
    background: #e5e7eb;
    color: #333;
}

.new-item-form {
    background: #fef3c7;
    border: 1px solid #fde68a;
}

.new-item-form .form-title {
    border-bottom-color: #f59e0b;
}
</style>

<div class="receive-container">
    <h1>Receive Stock</h1>

    <!-- Category Selection -->
    <div class="category-selector">
        <strong>Select Category:</strong>
        <div class="category-buttons">
            <a href="receive_stock.php" class="category-btn <?php echo $selected_category_id == 0 ? 'active' : ''; ?>">All Categories</a>
            <?php foreach ($categories as $cat): ?>
            <a href="?category_id=<?php echo $cat['id']; ?>" class="category-btn <?php echo $selected_category_id == $cat['id'] ? 'active' : ''; ?>">
                <?php echo htmlspecialchars($cat['name']); ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($success)): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>

    <!-- Action Buttons -->
    <div class="action-buttons">
        <a href="receive_stock.php?category_id=<?php echo $selected_category_id; ?>" 
           class="toggle-btn <?php echo !$show_new_item_form ? 'active' : 'inactive'; ?>">
            Receive Existing Item
        </a>
        <a href="receive_stock.php?category_id=<?php echo $selected_category_id; ?>&new_item=1" 
           class="toggle-btn <?php echo $show_new_item_form ? 'active' : 'inactive'; ?>">
            + Add New Item
        </a>
    </div>

    <?php if ($show_new_item_form): ?>
    <!-- Add New Item Form -->
    <div class="form-card new-item-form">
        <div class="form-title">Add New Item to Inventory</div>
        
        <form method="POST">
            <input type="hidden" name="add_new_item" value="1">
            
            <div class="form-group">
                <label>Item Name *</label>
                <input type="text" name="item_name" required placeholder="e.g., A4 Paper Ream, USB Cable, Laptop">
            </div>
            
            <div class="form-group">
                <label>Category *</label>
                <select name="category_id" required>
                    <option value="">— Select category —</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo ($selected_category_id == $cat['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Description (Optional)</label>
                <textarea name="description" rows="2" placeholder="Brief description of the item"></textarea>
            </div>
            
            <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label>Supplier/Vendor *</label>
                    <input type="text" name="supplier" required placeholder="e.g., Office Supplies Ltd">
                </div>
                <div class="form-group">
                    <label>Initial Quantity *</label>
                    <input type="number" name="quantity" min="1" required placeholder="Enter quantity">
                </div>
            </div>
            
            <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label>Reorder Level</label>
                    <input type="number" name="reorder_level" min="1" value="10">
                    <small>Minimum stock level before reordering</small>
                </div>
                <div class="form-group">
                    <label>Date Received *</label>
                    <input type="date" name="date_received" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-success">Add Item & Receive Stock</button>
                <a href="receive_stock.php?category_id=<?php echo $selected_category_id; ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
    <?php else: ?>
    
    <!-- Item Selection for Existing Items -->
    <div class="form-card">
        <div class="form-title">Select Item to Receive</div>
        <form method="GET" action="">
            <input type="hidden" name="category_id" value="<?php echo $selected_category_id; ?>">
            <div class="form-group">
                <label>Choose Item:</label>
                <select name="item_id" onchange="this.form.submit()" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                    <option value="">— Select an item —</option>
                    <?php foreach ($items as $item): ?>
                    <option value="<?php echo $item['id']; ?>" <?php echo ($selected_item_id == $item['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($item['item_name']); ?> (Current Stock: <?php echo $item['current_quantity']; ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($items) && $selected_category_id > 0): ?>
                <small style="color: #dc2626;">No items in this category. Click "Add New Item" to add items.</small>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Receive Existing Stock Form -->
    <?php if ($selected_item): ?>
    <div class="form-card">
        <div class="form-title">Receive Stock: <?php echo htmlspecialchars($selected_item['item_name']); ?></div>
        
        <!-- Current Stock Info -->
        <div class="stock-info">
            <h4>Current Stock Information</h4>
            <div class="stock-values">
                <div class="stock-value">
                    <span class="label">Current Stock:</span>
                    <span class="number"><?php echo $selected_item['current_quantity']; ?></span>
                </div>
                <div class="stock-value">
                    <span class="label">Category:</span>
                    <span><?php echo htmlspecialchars($selected_item['category']); ?></span>
                </div>
                <div class="stock-value">
                    <span class="label">Reorder Level:</span>
                    <span><?php echo $selected_item['reorder_level']; ?></span>
                </div>
            </div>
        </div>
        
        <form method="POST">
            <input type="hidden" name="receive_existing" value="1">
            <input type="hidden" name="item_id" value="<?php echo $selected_item['id']; ?>">
            
            <div class="form-group">
                <label>Supplier/Vendor *</label>
                <input type="text" name="supplier" required placeholder="e.g., Office Supplies Ltd">
            </div>
            
            <div class="form-group">
                <label>Quantity Received *</label>
                <input type="number" name="quantity" id="quantity" min="1" required placeholder="Enter quantity" 
                       oninput="updateNewStock()">
                <small id="newStockMsg"></small>
            </div>
            
            <div class="form-group">
                <label>Date Received *</label>
                <input type="date" name="date_received" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-success">Receive Stock</button>
                <a href="receive_stock.php?category_id=<?php echo $selected_category_id; ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <!-- Recent Transactions -->
    <div class="form-card">
        <div class="form-title">Recent Receiving Transactions</div>
        
        <?php if (count($recent_receiving) > 0): ?>
        <table class="recent-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Quantity</th>
                    <th>Old Stock</th>
                    <th>New Stock</th>
                    <th>Supplier</th>
                    <th>Received By</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_receiving as $trans): ?>
                <tr>
                    <td><?php echo date('d/m/Y H:i', strtotime($trans['created_at'])); ?></td>
                    <td style="font-weight:500"><?php echo htmlspecialchars($trans['item_name']); ?></td>
                    <td><span class="cat-pill"><?php echo htmlspecialchars($trans['category_name'] ?? 'Uncategorized'); ?></span></td>
                    <td><strong><?php echo $trans['quantity']; ?></strong></td>
                    <td class="stock-change old"><?php echo $trans['old_stock'] ?? '—'; ?></td>
                    <td class="stock-change new"><?php echo $trans['new_stock'] ?? '—'; ?></td>
                    <td><?php echo htmlspecialchars($trans['supplier'] ?: '—'); ?></td>
                    <td><?php echo htmlspecialchars($trans['received_by_name']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p style="text-align: center; color: #999; padding: 30px;">No receiving transactions yet.</p>
        <?php endif; ?>
    </div>
</div>

<script>
function updateNewStock() {
    var currentStock = <?php echo $selected_item['current_quantity'] ?? 0; ?>;
    var quantity = document.getElementById('quantity').value;
    var newStock = currentStock + parseInt(quantity || 0);
    var msgDiv = document.getElementById('newStockMsg');
    
    if (quantity > 0) {
        msgDiv.innerHTML = 'After receiving, new stock will be: <strong>' + newStock + '</strong>';
        msgDiv.style.color = '#166534';
    } else {
        msgDiv.innerHTML = '';
    }
}

// Auto-hide alerts
setTimeout(function() {
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        alert.style.opacity = '0';
        setTimeout(function() { alert.remove(); }, 400);
    });
}, 5000);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>