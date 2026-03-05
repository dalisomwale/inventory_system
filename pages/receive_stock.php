<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

// Your specific categories - WITHOUT "Other"
$DEFAULT_CATS = ['Caterriage', 'Stationery', 'Dry Stores', 'Catering', 'Uniforms', 'Flight Documents'];

// Get all categories for dropdown
$categories = $pdo->query("SELECT DISTINCT category FROM items WHERE category != '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
// Merge with default categories and remove duplicates
$all_categories = array_unique(array_merge($DEFAULT_CATS, $categories));
sort($all_categories);

// Get selected category from URL if any
$selected_category = isset($_GET['cat']) ? $_GET['cat'] : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Check if we're adding a new item or receiving existing
    if (isset($_POST['new_item']) && $_POST['new_item'] === 'yes') {
        // Adding new item
        $item_name = trim($_POST['item_name']);
        $category = trim($_POST['category']);
        $supplier = trim($_POST['supplier']);
        $quantity = (int)$_POST['quantity'];
        $unit_price = (float)$_POST['unit_price'];
        $date_received = $_POST['date_received'];
        $reorder_level = (int)$_POST['reorder_level'];
        
        $pdo->beginTransaction();
        try {
            // Insert new item
            $pdo->prepare("INSERT INTO items (item_name, category, description, current_quantity, unit_price, reorder_level) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$item_name, $category, 'Added via receive stock', $quantity, $unit_price, $reorder_level]);
            $item_id = $pdo->lastInsertId();
            
            // Record receiving transaction
            $pdo->prepare("INSERT INTO stock_receiving (item_id, supplier, quantity, unit_price, date_received, received_by) VALUES (?,?,?,?,?,?)")
                ->execute([$item_id, $supplier, $quantity, $unit_price, $date_received, $_SESSION['user_id']]);
            
            logAudit($pdo, $_SESSION['user_id'], 'ADD_ITEM_RECEIVE', $item_id, $quantity, "Added new item: $item_name in category: $category from: $supplier");
            $pdo->commit();
            $success = 'New item added and stock received successfully!';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error adding new item: ' . $e->getMessage();
        }
    } else {
        // Receiving existing item
        $item_id       = (int)$_POST['item_id'];
        $supplier      = trim($_POST['supplier']);
        $quantity      = (int)$_POST['quantity'];
        $unit_price    = (float)$_POST['unit_price'];
        $date_received = $_POST['date_received'];
        
        $pdo->beginTransaction();
        try {
            // Get current item details for audit
            $item = $pdo->prepare("SELECT item_name, current_quantity, category FROM items WHERE id=?");
            $item->execute([$item_id]);
            $item_data = $item->fetch();
            
            $pdo->prepare("INSERT INTO stock_receiving (item_id, supplier, quantity, unit_price, date_received, received_by) VALUES (?,?,?,?,?,?)")
                ->execute([$item_id, $supplier, $quantity, $unit_price, $date_received, $_SESSION['user_id']]);
            $pdo->prepare("UPDATE items SET current_quantity = current_quantity + ?, unit_price = ? WHERE id = ?")
                ->execute([$quantity, $unit_price, $item_id]);
            logAudit($pdo, $_SESSION['user_id'], 'RECEIVE_STOCK', $item_id, $quantity, "Received $quantity of {$item_data['item_name']} from: $supplier");
            $pdo->commit();
            $success = 'Stock received successfully!';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error receiving stock: ' . $e->getMessage();
        }
    }
}

// Get items based on category filter for the existing items dropdown
if ($selected_category && $selected_category !== 'all') {
    // Filter by selected category
    $stmt = $pdo->prepare("SELECT * FROM items WHERE category = ? ORDER BY item_name");
    $stmt->execute([$selected_category]);
    $filtered_items = $stmt->fetchAll();
    
    // Also get count of items in other categories for display
    $other_categories_count = $pdo->query("
        SELECT category, COUNT(*) as count 
        FROM items 
        WHERE category != '" . addslashes($selected_category) . "' 
        GROUP BY category
    ")->fetchAll();
} else {
    // No filter - get all items
    $filtered_items = $pdo->query("SELECT * FROM items ORDER BY category, item_name")->fetchAll();
    $other_categories_count = [];
}

// Group filtered items by category for grouped select
$grouped = [];
foreach ($filtered_items as $it) {
    $grouped[$it['category'] ?: 'Uncategorized'][] = $it;
}

// Get recent receiving transactions (unfiltered - show all recent)
$recent_receiving = $pdo->prepare("
    SELECT r.*, i.item_name, i.category, u.full_name as received_by_name
    FROM stock_receiving r
    JOIN items i ON r.item_id = i.id
    JOIN users u ON r.received_by = u.id
    ORDER BY r.created_at DESC
    LIMIT 20
");
$recent_receiving->execute();
$recent_receiving = $recent_receiving->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<h1>Receive Stock</h1>

<!-- Category Filter -->
<div style="background: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #ddd;">
    <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
        <span style="font-weight: bold;">Filter by Category:</span>
        <a href="receive_stock.php" style="padding: 5px 12px; background: <?php echo !$selected_category ? '#2563eb' : '#f0f0f0'; ?>; color: <?php echo !$selected_category ? 'white' : '#333'; ?>; text-decoration: none; border-radius: 20px;">All Categories</a>
        <?php foreach ($all_categories as $cat): ?>
        <a href="?cat=<?php echo urlencode($cat); ?>" 
           style="padding: 5px 12px; background: <?php echo $selected_category === $cat ? '#2563eb' : '#f0f0f0'; ?>; color: <?php echo $selected_category === $cat ? 'white' : '#333'; ?>; text-decoration: none; border-radius: 20px;">
            <?php echo htmlspecialchars($cat); ?>
        </a>
        <?php endforeach; ?>
    </div>
    
    <?php if ($selected_category): ?>
    <div style="margin-top: 15px; padding: 12px; background: #e6f7ff; border-radius: 5px; display: flex; justify-content: space-between; align-items: center;">
        <div>
            <strong>Currently viewing: <?php echo htmlspecialchars($selected_category); ?></strong> category
            <?php if (!empty($other_categories_count)): ?>
            <span style="margin-left: 10px; color: #666; font-size: 0.9rem;">
                (<?php echo count($other_categories_count); ?> other categories hidden)
            </span>
            <?php endif; ?>
        </div>
        <a href="receive_stock.php" style="color: #2563eb; text-decoration: none; font-weight: 500;">Clear filter ✕</a>
    </div>
    <?php endif; ?>
</div>

<!-- JavaScript to toggle between new item and existing item -->
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

// Set the category dropdown in new item form to match the filtered category
window.onload = function() {
    <?php if ($selected_category): ?>
    var categorySelect = document.querySelector('select[name="category"]');
    if (categorySelect) {
        for (var i = 0; i < categorySelect.options.length; i++) {
            if (categorySelect.options[i].value === '<?php echo addslashes($selected_category); ?>') {
                categorySelect.selectedIndex = i;
                break;
            }
        }
    }
    <?php endif; ?>
}
</script>

<!-- Receive Stock Form -->
<div style="background: white; padding: 25px; border-radius: 8px; margin-bottom: 25px; border: 1px solid #ddd;">
    <h3 style="margin-top: 0; margin-bottom: 20px;">📦 Receive Stock</h3>
    
    <?php if (isset($success)): ?>
        <div style="background: #d4edda; color: #155724; padding: 12px; border-radius: 5px; margin-bottom: 20px;"><?php echo $success;?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
        <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 5px; margin-bottom: 20px;"><?php echo $error;?></div>
    <?php endif; ?>
    
    <form method="POST">
        <!-- Hidden field to indicate if it's a new item -->
        <input type="hidden" name="new_item" id="new_item_flag" value="no">
        
        <!-- Item Type Selection -->
        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Item Type:</label>
            <select id="item_type" onchange="toggleItemType()" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                <option value="existing">Select Existing Item</option>
                <option value="new">Add New Item</option>
            </select>
        </div>
        
        <!-- Existing Item Selection (shown by default) - FILTERED BY CATEGORY -->
        <div id="existing_item_div">
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">
                    Select Existing Item 
                    <?php if ($selected_category): ?>
                    <span style="font-weight: normal; color: #2563eb;">(in <?php echo htmlspecialchars($selected_category); ?> category)</span>
                    <?php endif; ?>
                </label>
                <select name="item_id" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                    <option value="">— Choose item —</option>
                    <?php if (!empty($grouped)): ?>
                        <?php foreach ($grouped as $cat => $items): ?>
                        <optgroup label="<?php echo htmlspecialchars($cat); ?>">
                            <?php foreach ($items as $item): ?>
                            <option value="<?php echo $item['id']; ?>" <?php echo (isset($_GET['item']) && $_GET['item'] == $item['id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($item['item_name']); ?> (Stock: <?php echo $item['current_quantity']; ?>)
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <option value="" disabled>No items found in this category</option>
                    <?php endif; ?>
                </select>
                <?php if (empty($filtered_items) && $selected_category): ?>
                <small style="display: block; margin-top: 8px; color: #dc2626;">
                    ⚠️ No existing items found in <strong><?php echo htmlspecialchars($selected_category); ?></strong> category. 
                    You can add a new item using the "Add New Item" option above.
                </small>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- New Item Form (hidden by default) - CATEGORY PRE-SELECTED IF FILTERED -->
        <div id="new_item_div" style="display: none;">
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Item Name <span style="color: #dc2626;">*</span></label>
                <input type="text" name="item_name" placeholder="Enter item name e.g., A4 Paper Ream" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            </div>
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Category <span style="color: #dc2626;">*</span></label>
                <select name="category" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                    <option value="">— Select category —</option>
                    <?php foreach ($DEFAULT_CATS as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo ($selected_category === $cat) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat); ?>
                        <?php if ($selected_category === $cat): ?> (currently filtered)<?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($selected_category): ?>
                <small style="display: block; margin-top: 4px; color: #2563eb;">
                    ⓘ Category pre-selected to match your current filter: <strong><?php echo htmlspecialchars($selected_category); ?></strong>
                </small>
                <?php else: ?>
                <small style="display: block; margin-top: 4px; color: #666;">Choose from: Caterriage, Stationery, Dry Stores, Catering, Uniforms, Flight Documents</small>
                <?php endif; ?>
            </div>
            
            <div style="margin-bottom: 15px;">
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Reorder Level</label>
                <input type="number" name="reorder_level" min="1" value="10" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                <small style="display: block; margin-top: 4px; color: #666;">Minimum stock level before reordering</small>
            </div>
        </div>
        
        <!-- Common fields for both new and existing items -->
        <div style="margin-bottom: 15px;">
            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Supplier/Vendor</label>
            <input type="text" name="supplier" required placeholder="e.g., Office Supplies Ltd" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Quantity Received</label>
                <input type="number" name="quantity" min="1" required placeholder="Enter quantity" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            </div>
            <div>
                <label style="display: block; margin-bottom: 5px; font-weight: bold;">Unit Price (K)</label>
                <input type="number" name="unit_price" min="0" step="0.01" required placeholder="0.00" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
            </div>
        </div>
        
        <div style="margin-bottom: 20px;">
            <label style="display: block; margin-bottom: 5px; font-weight: bold;">Date Received</label>
            <input type="date" name="date_received" value="<?php echo date('Y-m-d');?>" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
        </div>
        
        <div style="display: flex; gap: 10px;">
            <button type="submit" style="background: #28a745; color: white; border: none; padding: 12px 25px; border-radius: 5px; cursor: pointer; font-weight: bold;">📥 Process Receiving</button>
            <a href="dashboard.php" style="background: #6c757d; color: white; text-decoration: none; padding: 12px 25px; border-radius: 5px;">Cancel</a>
        </div>
    </form>
</div>

<!-- Recent Transactions -->
<div style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #ddd;">
    <h3 style="margin-top: 0; margin-bottom: 15px;">📋 Recent Receiving Transactions</h3>
    
    <table style="width: 100%; border-collapse: collapse;">
        <thead>
            <tr style="background: #f8f9fa;">
                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #ddd;">Date</th>
                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #ddd;">Item</th>
                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #ddd;">Category</th>
                <th style="padding: 12px; text-align: left; border-bottom: 2px solid #ddd;">Qty</th>
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
                        <?php echo htmlspecialchars($trans['category']); ?>
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