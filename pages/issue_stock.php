<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

// Get all categories
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

$selected_category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$selected_item_id = isset($_GET['item_id']) ? (int)$_GET['item_id'] : 0;

// Get items based on selected category (only items with stock > 0)
$items = [];
$selected_item = null;

if ($selected_category_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM items WHERE category_id = ? AND current_quantity > 0 ORDER BY item_name");
    $stmt->execute([$selected_category_id]);
    $items = $stmt->fetchAll();
}

if ($selected_item_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
    $stmt->execute([$selected_item_id]);
    $selected_item = $stmt->fetch();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_id = (int)$_POST['item_id'];
    $quantity = (int)$_POST['quantity'];
    $issued_to = trim($_POST['issued_to']);
    $purpose = trim($_POST['purpose']);
    $date_issued = $_POST['date_issued'];

    // Get item details
    $item_row = $pdo->prepare("SELECT current_quantity, item_name, category FROM items WHERE id=?");
    $item_row->execute([$item_id]);
    $item = $item_row->fetch();
    $old_stock = $item['current_quantity'];
    $new_stock = $old_stock - $quantity;

    if (!$item) {
        $error = 'Item not found.';
    } elseif ($item['current_quantity'] < $quantity) {
        $error = 'Insufficient stock! Available: ' . $item['current_quantity'];
    } else {
        $pdo->beginTransaction();
        try {
            // Record issuing transaction with old/new stock
            $insert = $pdo->prepare("
                INSERT INTO stock_issuing (item_id, quantity, issued_to, purpose, date_issued, issued_by, old_stock, new_stock) 
                VALUES (?,?,?,?,?,?,?,?)
            ");
            $insert->execute([$item_id, $quantity, $issued_to, $purpose, $date_issued, $_SESSION['user_id'], $old_stock, $new_stock]);
            
            // Update item quantity
            $update = $pdo->prepare("UPDATE items SET current_quantity = current_quantity - ? WHERE id=?");
            $update->execute([$quantity, $item_id]);
            
            logAudit($pdo, $_SESSION['user_id'], 'ISSUE_STOCK', $item_id, $quantity, 
                     "Issued $quantity of {$item['item_name']} to: $issued_to. Old stock: $old_stock, New stock: $new_stock. Purpose: $purpose");
            $pdo->commit();
            $success = "Stock issued successfully! Old stock: $old_stock, New stock: $new_stock";
            
            // Refresh item data
            $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
            $stmt->execute([$item_id]);
            $selected_item = $stmt->fetch();
            
            // Refresh items list
            if ($selected_category_id > 0) {
                $stmt = $pdo->prepare("SELECT * FROM items WHERE category_id = ? AND current_quantity > 0 ORDER BY item_name");
                $stmt->execute([$selected_category_id]);
                $items = $stmt->fetchAll();
            }
            
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

<style>
.issue-container {
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

.stock-info {
    background: #fef3c7;
    border: 1px solid #fde68a;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 20px;
}

.stock-info h4 {
    margin: 0 0 10px 0;
    color: #92400e;
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
    color: #dc2626;
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

.btn-danger {
    background: #dc2626;
    color: white;
}

.btn-danger:hover {
    background: #b91c1c;
}

.btn-secondary {
    background: #6c757d;
    color: white;
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
    color: #16a34a;
}

.stock-change .new {
    color: #dc2626;
}

.warning-text {
    color: #dc2626;
    font-size: 0.8rem;
    margin-top: 5px;
}
</style>

<div class="issue-container">
    <h1>Issue Stock</h1>

    <!-- Category Selection -->
    <div class="category-selector">
        <strong>Select Category:</strong>
        <div class="category-buttons">
            <a href="issue_stock.php" class="category-btn <?php echo $selected_category_id == 0 ? 'active' : ''; ?>">All Categories</a>
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

    <!-- Item Selection -->
    <div class="form-card">
        <div class="form-title">Select Item to Issue</div>
        <form method="GET" action="">
            <input type="hidden" name="category_id" value="<?php echo $selected_category_id; ?>">
            <div class="form-group">
                <label>Choose Item:</label>
                <select name="item_id" onchange="this.form.submit()" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px;">
                    <option value="">— Select an item —</option>
                    <?php foreach ($items as $item): ?>
                    <option value="<?php echo $item['id']; ?>" <?php echo ($selected_item_id == $item['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($item['item_name']); ?> (Available: <?php echo $item['current_quantity']; ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($items) && $selected_category_id > 0): ?>
                <div class="warning-text">No items with stock available in this category.</div>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Issue Stock Form -->
    <?php if ($selected_item): ?>
    <div class="form-card">
        <div class="form-title">Issue Stock: <?php echo htmlspecialchars($selected_item['item_name']); ?></div>
        
        <!-- Current Stock Info -->
        <div class="stock-info">
            <h4>Current Stock Information</h4>
            <div class="stock-values">
                <div class="stock-value">
                    <span class="label">Available Stock:</span>
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
            <input type="hidden" name="item_id" value="<?php echo $selected_item['id']; ?>">
            
            <div class="form-group">
                <label>Quantity to Issue *</label>
                <input type="number" name="quantity" id="quantity" min="1" max="<?php echo $selected_item['current_quantity']; ?>" required 
                       placeholder="Enter quantity" onchange="updateNewStock()">
                <small id="newStockMsg"></small>
            </div>
            
            <div class="form-group">
                <label>Issued To (Customer/Department) *</label>
                <input type="text" name="issued_to" required placeholder="e.g., IT Department, John Smith, Customer Name">
            </div>
            
            <div class="form-group">
                <label>Purpose/Reason (Optional)</label>
                <textarea name="purpose" rows="3" placeholder="Reason for issue - e.g., Office use, Customer purchase, Department request"></textarea>
            </div>
            
            <div class="form-group">
                <label>Date Issued *</label>
                <input type="date" name="date_issued" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="btn btn-danger" onclick="return confirm('Confirm issue of <?php echo $selected_item['item_name']; ?>?')">Issue Stock</button>
                <a href="issue_stock.php?category_id=<?php echo $selected_category_id; ?>" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Recent Issuing Transactions -->
    <div class="form-card">
        <div class="form-title">Recent Issuing Transactions</div>
        
        <?php if (count($recent_issuing) > 0): ?>
        <table class="recent-table">
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Quantity</th>
                    <th>Old Stock</th>
                    <th>New Stock</th>
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
                    <td><span class="cat-pill"><?php echo htmlspecialchars($trans['category_name'] ?? 'Other'); ?></span></td>
                    <td><strong><?php echo $trans['quantity']; ?></strong></td>
                    <td class="stock-change old"><?php echo $trans['old_stock'] ?? '—'; ?></td>
                    <td class="stock-change new"><?php echo $trans['new_stock'] ?? '—'; ?></td>
                    <td><?php echo htmlspecialchars($trans['issued_to'] ?: '—'); ?></td>
                    <td style="max-width:200px; white-space:normal"><?php echo htmlspecialchars($trans['purpose'] ?: '—'); ?></td>
                    <td><?php echo htmlspecialchars($trans['issued_by_name']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <p style="text-align: center; color: #999; padding: 30px;">No issuing transactions yet.</p>
        <?php endif; ?>
    </div>
</div>

<script>
function updateNewStock() {
    var currentStock = <?php echo $selected_item['current_quantity'] ?? 0; ?>;
    var quantity = document.getElementById('quantity').value;
    var newStock = currentStock - quantity;
    var msgDiv = document.getElementById('newStockMsg');
    
    if (quantity > currentStock) {
        msgDiv.innerHTML = '<span style="color: #dc2626;">Cannot issue more than available stock!</span>';
        msgDiv.style.color = '#dc2626';
    } else if (quantity > 0) {
        msgDiv.innerHTML = 'After issue, new stock will be: <strong>' + newStock + '</strong>';
        msgDiv.style.color = '#666';
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