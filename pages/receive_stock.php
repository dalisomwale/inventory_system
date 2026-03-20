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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['new_item']) && $_POST['new_item'] === 'yes') {
        // Adding new item
        $item_name = trim($_POST['item_name']);
        $category_id = (int)$_POST['category_id'];
        $supplier = trim($_POST['supplier']);
        $quantity = (int)$_POST['quantity'];
        $date_received = $_POST['date_received'];
        $reorder_level = (int)$_POST['reorder_level'];
        
        // Get category name for audit
        $cat = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
        $cat->execute([$category_id]);
        $cat_name = $cat->fetchColumn();
        
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO items (item_name, category_id, category, description, current_quantity, unit_price, reorder_level) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$item_name, $category_id, $cat_name, 'Added via receive stock', $quantity, 0.00, $reorder_level]);
            $item_id = $pdo->lastInsertId();
            
            $pdo->prepare("INSERT INTO stock_receiving (item_id, supplier, quantity, unit_price, date_received, received_by) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$item_id, $supplier, $quantity, 0.00, $date_received, $_SESSION['user_id']]);
            
            logAudit($pdo, $_SESSION['user_id'], 'ADD_ITEM_RECEIVE', $item_id, $quantity, "Added new item: $item_name in category: $cat_name from: $supplier");
            $pdo->commit();
            $success = 'New item added and stock received successfully!';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error adding new item: ' . $e->getMessage();
        }
    } else {
        // Receiving existing item
        $item_id = (int)$_POST['item_id'];
        $supplier = trim($_POST['supplier']);
        $quantity = (int)$_POST['quantity'];
        $date_received = $_POST['date_received'];
        
        $pdo->beginTransaction();
        try {
            $item = $pdo->prepare("SELECT item_name, current_quantity FROM items WHERE id=?");
            $item->execute([$item_id]);
            $item_data = $item->fetch();
            
            $pdo->prepare("INSERT INTO stock_receiving (item_id, supplier, quantity, unit_price, date_received, received_by) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$item_id, $supplier, $quantity, 0.00, $date_received, $_SESSION['user_id']]);
            $pdo->prepare("UPDATE items SET current_quantity = current_quantity + ? WHERE id = ?")
                ->execute([$quantity, $item_id]);
            logAudit($pdo, $_SESSION['user_id'], 'RECEIVE_STOCK', $item_id, $quantity, "Received $quantity of {$item_data['item_name']} from: $supplier");
            $pdo->commit();
            $success = 'Stock received successfully!';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error receiving stock: ' . $e->getMessage();
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

<h1>Receive Stock</h1>

<!-- Category Selection -->
<div style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #ddd;">
    <h3 style="margin-top: 0; margin-bottom: 15px;">Select Category</h3>
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="receive_stock.php" style="padding: 8px 16px; background: <?php echo $selected_category_id == 0 ? '#2563eb' : '#f0f0f0'; ?>; color: <?php echo $selected_category_id == 0 ? 'white' : '#333'; ?>; text-decoration: none; border-radius: 20px; font-weight: 500;">All Categories</a>
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
        <span style="margin-left: 10px; color: #dc2626;"> No items in this category yet. You can add new items below.</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function toggleItemType() {
    var selectType = document.getElementById('item_type');
    var existingItemDiv = document.getElementById('existing_item_div');
    var newItemDiv = document.getElementById('new_item_div');
    
    if (selectType.value === 'existing') {
        existingItemDiv.style.display = 'block';
        newItemDiv.style.display = 'none';
        document.getElementById('new_item_flag').value = 'no';
    } else {
        existingItemDiv.style.display = 'none';
        newItemDiv.style.display = 'block';
        document.getElementById('new_item_flag').value = 'yes';
    }
}
</script>

<!-- Receive Stock Form -->
<div style="background: white; padding: 25px; border-radius: 8px; margin-bottom: 25px; border: 1px solid #ddd;">
    <h3 style="margin-top: 0; margin-bottom: 20px;">Receive Stock</h3>
    
    <?php if (isset($success)): ?>
        <div style="background: #d4edda; color: #155724; padding: 12px; border-radius: 5px; margin-bottom: 20px;"><?php echo $success;?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 5px; margin-bottom: 20px;"><?php echo $error;?></div>
    <?php endif; ?>
    
    <form method="POST">
        <input type="hidden" name="new_item" id="new_item_flag" value="no">
        
        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Item Type:</label>
            <select id="item_type" onchange="toggleItemType()" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                <option value="existing">Select Existing Item</option>
                <option value="new">Add New Item</option>
            </select>
        </div>
        
        <div id="existing_item_div">
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Select Item</label>
                <select name="item_id" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;" required>
                    <option value="">— Choose item —</option>
                    <?php foreach ($items as $item): ?>
                    <option value="<?php echo $item['id']; ?>" <?php echo (isset($_GET['item']) && $_GET['item'] == $item['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($item['item_name']); ?> (Current: <?php echo $item['current_quantity']; ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($items) && $selected_category_id > 0): ?>
                <small style="display: block; margin-top: 5px; color: #dc2626;">
                    No items found in this category. Please add a new item using the option above.
                </small>
                <?php endif; ?>
            </div>
        </div>
        
        <div id="new_item_div" style="display: none;">
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Item Name</label>
                <input type="text" name="item_name" placeholder="Enter item name" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;" required>
            </div>
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Category</label>
                <select name="category_id" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;" required>
                    <option value="">— Select category —</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo ($selected_category_id == $cat['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['name']); ?>
                        <?php if ($selected_category_id == $cat['id']): ?> (currently selected)<?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Reorder Level</label>
                <input type="number" name="reorder_level" min="1" value="10" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                <small>Minimum stock level before reordering</small>
            </div>
        </div>
        
        <div style="margin-bottom: 15px;">
            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Supplier/Vendor</label>
            <input type="text" name="supplier" required placeholder="e.g., Office Supplies Ltd" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
        </div>
        
        <div style="margin-bottom: 15px;">
            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Quantity Received</label>
            <input type="number" name="quantity" min="1" required placeholder="Enter quantity" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
        </div>
        
        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Date Received</label>
            <input type="date" name="date_received" value="<?php echo date('Y-m-d');?>" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
        </div>
        
        <div style="display: flex; gap: 10px;">
            <button type="submit" style="background: #28a745; color: white; border: none; padding: 12px 25px; border-radius: 5px; cursor: pointer; font-weight: bold;">Receive Stock</button>
            <a href="dashboard.php" style="background: #6c757d; color: white; text-decoration: none; padding: 12px 25px; border-radius: 5px;">Cancel</a>
        </div>
    </form>
</div>

<!-- Recent Transactions -->
<div style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #ddd;">
    <h3 style="margin-top: 0; margin-bottom: 15px;">Recent Receiving Transactions</h3>
    
    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background: #f8f9fa;">
                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #ddd;">Date</th>
                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #ddd;">Item</th>
                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #ddd;">Category</th>
                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #ddd;">Quantity</th>
                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #ddd;">Supplier</th>
                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #ddd;">Received By</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($recent_receiving as $trans): ?>
            <tr>
                <td style="padding: 10px; border-bottom: 1px solid #eee;"><?php echo date('d/m/Y H:i', strtotime($trans['created_at'])); ?></td>
                <td style="padding: 10px; border-bottom: 1px solid #eee; font-weight: 500;"><?php echo htmlspecialchars($trans['item_name']); ?></td>
                <td style="padding: 10px; border-bottom: 1px solid #eee;">
                    <span style="background: #e6f7ff; color: #0050b3; padding: 3px 8px; border-radius: 12px; font-size: 0.85rem;">
                        <?php echo htmlspecialchars($trans['category_name'] ?? 'Uncategorized'); ?>
                    </span>
                </td>
                <td style="padding: 10px; border-bottom: 1px solid #eee;"><strong><?php echo $trans['quantity']; ?></strong></td>
                <td style="padding: 10px; border-bottom: 1px solid #eee;"><?php echo htmlspecialchars($trans['supplier'] ?: '—'); ?></td>
                <td style="padding: 10px; border-bottom: 1px solid #eee;"><?php echo htmlspecialchars($trans['received_by_name']); ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if (empty($recent_receiving)): ?>
            <tr><td colspan="6" style="padding: 30px; text-align: center; color: #999;">No receiving transactions yet.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>