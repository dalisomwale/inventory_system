<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

// Get all categories from database
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

// Get selected category from URL
$selected_category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

// Get items based on selected category
$items = [];
if ($selected_category_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM items WHERE category_id = ? ORDER BY item_name");
    $stmt->execute([$selected_category_id]);
    $items = $stmt->fetchAll();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if it's a new item or existing
    $is_new_item = isset($_POST['new_item']) && $_POST['new_item'] === 'yes';
    
    if ($is_new_item) {
        // Adding new item
        $item_name = trim($_POST['item_name']);
        $category_id = (int)$_POST['category_id'];
        $supplier = trim($_POST['supplier']);
        $quantity = (int)$_POST['quantity'];
        $date_received = $_POST['date_received'];
        $reorder_level = (int)$_POST['reorder_level'];
        
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
                $insert->execute([$item_name, $category_id, $cat_name, 'Added via receive stock', $quantity, 0.00, $reorder_level]);
                $item_id = $pdo->lastInsertId();
                
                // Record receiving transaction
                $insert_recv = $pdo->prepare("
                    INSERT INTO stock_receiving (item_id, supplier, quantity, unit_price, date_received, received_by) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $insert_recv->execute([$item_id, $supplier, $quantity, 0.00, $date_received, $_SESSION['user_id']]);
                
                logAudit($pdo, $_SESSION['user_id'], 'ADD_ITEM_RECEIVE', $item_id, $quantity, 
                         "Added new item: $item_name in category: $cat_name from: $supplier");
                $pdo->commit();
                $success = 'New item added and stock received successfully!';
                
                // Refresh items list
                if ($selected_category_id > 0) {
                    $stmt = $pdo->prepare("SELECT * FROM items WHERE category_id = ? ORDER BY item_name");
                    $stmt->execute([$selected_category_id]);
                    $items = $stmt->fetchAll();
                }
                
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Error adding new item: ' . $e->getMessage();
            }
        }
    } else {
        // Receiving existing item
        $item_id = (int)$_POST['item_id'];
        $supplier = trim($_POST['supplier']);
        $quantity = (int)$_POST['quantity'];
        $date_received = $_POST['date_received'];
        
        // Validate
        if ($item_id <= 0 || empty($supplier) || $quantity <= 0) {
            $error = 'Please select an item and fill in all fields.';
        } else {
            $pdo->beginTransaction();
            try {
                // Get item details
                $item = $pdo->prepare("SELECT item_name, current_quantity FROM items WHERE id=?");
                $item->execute([$item_id]);
                $item_data = $item->fetch();
                
                if (!$item_data) {
                    throw new Exception('Item not found');
                }
                
                // Record receiving transaction
                $insert_recv = $pdo->prepare("
                    INSERT INTO stock_receiving (item_id, supplier, quantity, unit_price, date_received, received_by) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $insert_recv->execute([$item_id, $supplier, $quantity, 0.00, $date_received, $_SESSION['user_id']]);
                
                // Update item quantity
                $update = $pdo->prepare("UPDATE items SET current_quantity = current_quantity + ? WHERE id = ?");
                $update->execute([$quantity, $item_id]);
                
                logAudit($pdo, $_SESSION['user_id'], 'RECEIVE_STOCK', $item_id, $quantity, 
                         "Received $quantity of {$item_data['item_name']} from: $supplier");
                $pdo->commit();
                $success = 'Stock received successfully!';
                
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
    }
}

// Get recent receiving transactions
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

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 15px;
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
.form-group select:focus {
    outline: none;
    border-color: #2563eb;
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
        <?php if ($selected_category_id > 0): ?>
        <div style="margin-top: 10px; padding: 8px; background: #e6f7ff; border-radius: 5px; font-size: 0.85rem;">
            <strong>Selected Category:</strong> <?php 
            $cat_name = '';
            foreach ($categories as $cat) {
                if ($cat['id'] == $selected_category_id) {
                    $cat_name = $cat['name'];
                    break;
                }
            }
            echo htmlspecialchars($cat_name);
            ?>
            <?php if (empty($items)): ?>
            <span style="color: #dc2626; margin-left: 10px;">No items in this category. You can add new items below.</span>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($success)): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>

    <!-- Receive Stock Form -->
    <div class="form-card">
        <div class="form-title">Receive Stock</div>
        
        <form method="POST" action="" id="receiveForm">
            <input type="hidden" name="new_item" id="new_item_flag" value="no">
            
            <div class="form-group">
                <label>Item Type:</label>
                <select id="item_type" name="item_type" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                    <option value="existing">Select Existing Item</option>
                    <option value="new">Add New Item</option>
                </select>
            </div>
            
            <!-- Existing Item Selection -->
            <div id="existing_item_div">
                <div class="form-group">
                    <label>Select Item *</label>
                    <select name="item_id" required>
                        <option value="">— Choose item —</option>
                        <?php foreach ($items as $item): ?>
                        <option value="<?php echo $item['id']; ?>" <?php echo (isset($_GET['item']) && $_GET['item'] == $item['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($item['item_name']); ?> (Current: <?php echo $item['current_quantity']; ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($items) && $selected_category_id > 0): ?>
                    <small style="color: #dc2626;">No items found in this category. Please add a new item using the option above.</small>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- New Item Form -->
            <div id="new_item_div" style="display: none;">
                <div class="form-row">
                    <div class="form-group">
                        <label>Item Name *</label>
                        <input type="text" name="item_name" placeholder="Enter item name">
                    </div>
                    <div class="form-group">
                        <label>Category *</label>
                        <select name="category_id">
                            <option value="">— Select category —</option>
                            <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo $cat['id']; ?>" <?php echo ($selected_category_id == $cat['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['name']); ?>
                                <?php if ($selected_category_id == $cat['id']): ?> (currently selected)<?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Reorder Level</label>
                    <input type="number" name="reorder_level" min="1" value="10">
                    <small>Minimum stock level before reordering</small>
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Supplier/Vendor *</label>
                    <input type="text" name="supplier" required placeholder="e.g., Office Supplies Ltd">
                </div>
                <div class="form-group">
                    <label>Quantity Received *</label>
                    <input type="number" name="quantity" min="1" required placeholder="Enter quantity">
                </div>
            </div>
            
            <div class="form-group">
                <label>Date Received *</label>
                <input type="date" name="date_received" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-success" onclick="return validateForm()">Process Receiving</button>
                <a href="dashboard.php" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>

    <!-- Recent Transactions -->
    <div class="form-card">
        <div class="form-title">Recent Receiving Transactions</div>
        
        <?php if (count($recent_receiving) > 0): ?>
        <table class="recent-table">
            <thead>
                32
                    <th>Date</th>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Quantity</th>
                    <th>Supplier</th>
                    <th>Received By</th>
                </thead>
            <tbody>
                <?php foreach ($recent_receiving as $trans): ?>
                <tr>
                    <td><?php echo date('d/m/Y H:i', strtotime($trans['created_at'])); ?></td>
                    <td style="font-weight:500"><?php echo htmlspecialchars($trans['item_name']); ?></td>
                    <td><span class="cat-pill"><?php echo htmlspecialchars($trans['category_name'] ?? 'Uncategorized'); ?></span></td>
                    <td><strong><?php echo $trans['quantity']; ?></strong></td>
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
// Toggle between existing item and new item forms
document.getElementById('item_type').addEventListener('change', function() {
    var existingDiv = document.getElementById('existing_item_div');
    var newDiv = document.getElementById('new_item_div');
    var newItemFlag = document.getElementById('new_item_flag');
    
    if (this.value === 'existing') {
        existingDiv.style.display = 'block';
        newDiv.style.display = 'none';
        newItemFlag.value = 'no';
        // Make existing item fields required
        document.querySelector('select[name="item_id"]').required = true;
        document.querySelector('input[name="item_name"]').required = false;
        document.querySelector('select[name="category_id"]').required = false;
    } else {
        existingDiv.style.display = 'none';
        newDiv.style.display = 'block';
        newItemFlag.value = 'yes';
        // Make new item fields required
        document.querySelector('select[name="item_id"]').required = false;
        document.querySelector('input[name="item_name"]').required = true;
        document.querySelector('select[name="category_id"]').required = true;
    }
});

// Form validation
function validateForm() {
    var itemType = document.getElementById('item_type').value;
    var newItemFlag = document.getElementById('new_item_flag').value;
    var supplier = document.querySelector('input[name="supplier"]').value;
    var quantity = document.querySelector('input[name="quantity"]').value;
    var date = document.querySelector('input[name="date_received"]').value;
    
    if (!supplier || supplier.trim() === '') {
        alert('Please enter the supplier name.');
        return false;
    }
    
    if (!quantity || quantity <= 0) {
        alert('Please enter a valid quantity (must be at least 1).');
        return false;
    }
    
    if (!date) {
        alert('Please select the date received.');
        return false;
    }
    
    if (itemType === 'existing') {
        var itemId = document.querySelector('select[name="item_id"]').value;
        if (!itemId || itemId === '') {
            alert('Please select an item.');
            return false;
        }
    } else {
        var itemName = document.querySelector('input[name="item_name"]').value;
        var categoryId = document.querySelector('select[name="category_id"]').value;
        
        if (!itemName || itemName.trim() === '') {
            alert('Please enter the item name.');
            return false;
        }
        
        if (!categoryId || categoryId === '') {
            alert('Please select a category for the new item.');
            return false;
        }
    }
    
    return true;
}

// Initialize form on page load
document.addEventListener('DOMContentLoaded', function() {
    // Set initial state
    var itemType = document.getElementById('item_type');
    var existingDiv = document.getElementById('existing_item_div');
    var newDiv = document.getElementById('new_item_div');
    var newItemFlag = document.getElementById('new_item_flag');
    
    existingDiv.style.display = 'block';
    newDiv.style.display = 'none';
    newItemFlag.value = 'no';
    
    // Make existing item fields required
    document.querySelector('select[name="item_id"]').required = true;
    document.querySelector('input[name="item_name"]').required = false;
    document.querySelector('select[name="category_id"]').required = false;
});

// Auto-hide alerts after 5 seconds
setTimeout(function() {
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        alert.style.opacity = '0';
        setTimeout(function() { alert.remove(); }, 400);
    });
}, 5000);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>