<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();
if (!isManager()) { header('Location: dashboard.php'); exit(); }

$categories     = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$filter_category = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

// Add Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $cat_name = trim($_POST['category_name']);
    $cat_desc = trim($_POST['category_desc']);
    if (!empty($cat_name)) {
        $check = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
        $check->execute([$cat_name]);
        if (!$check->fetch()) {
            $pdo->prepare("INSERT INTO categories (name, description) VALUES (?, ?)")->execute([$cat_name, $cat_desc]);
            $success = "Category added successfully.";
            logAudit($pdo, $_SESSION['user_id'], 'ADD_CATEGORY', null, null, "Added category: $cat_name");
            $categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
        } else { $error = "Category already exists."; }
    }
}

// Edit Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_category'])) {
    $cat_id = (int)$_POST['category_id'];
    $cat_name = trim($_POST['category_name']);
    $cat_desc = trim($_POST['category_desc']);
    $pdo->prepare("UPDATE categories SET name=?, description=? WHERE id=?")->execute([$cat_name, $cat_desc, $cat_id]);
    $pdo->prepare("UPDATE items SET category=? WHERE category_id=?")->execute([$cat_name, $cat_id]);
    $success = "Category updated successfully.";
    logAudit($pdo, $_SESSION['user_id'], 'EDIT_CATEGORY', null, null, "Updated category: $cat_name");
    $categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
}

// Delete Category
if (isset($_GET['delete_category'])) {
    $cat_id = (int)$_GET['delete_category'];
    $check  = $pdo->prepare("SELECT COUNT(*) FROM items WHERE category_id=?");
    $check->execute([$cat_id]);
    if ($check->fetchColumn() == 0) {
        $pdo->prepare("DELETE FROM categories WHERE id=?")->execute([$cat_id]);
        $success = "Category deleted successfully.";
        logAudit($pdo, $_SESSION['user_id'], 'DELETE_CATEGORY', null, null, "Deleted category ID: $cat_id");
        $categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
    } else { $error = "Cannot delete a category that has items."; }
}

// Add Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $item_name       = trim($_POST['item_name']);
    $category_id     = (int)$_POST['category_id'];
    $description     = trim($_POST['description']);
    $unit_price      = (float)$_POST['unit_price'];
    $reorder_level   = (int)$_POST['reorder_level'];
    $initial_qty     = (int)$_POST['initial_quantity'];
    $cat             = $pdo->prepare("SELECT name FROM categories WHERE id=?");
    $cat->execute([$category_id]);
    $cat_name        = $cat->fetchColumn();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO items (item_name, category_id, category, description, current_quantity, unit_price, reorder_level) VALUES (?,?,?,?,?,?,?)")
            ->execute([$item_name, $category_id, $cat_name, $description, $initial_qty, $unit_price, $reorder_level]);
        $item_id = $pdo->lastInsertId();
        if ($initial_qty > 0) {
            $pdo->prepare("INSERT INTO stock_receiving (item_id, supplier, quantity, unit_price, date_received, received_by) VALUES (?,?,?,?,?,?)")
                ->execute([$item_id, 'Initial Stock', $initial_qty, $unit_price, date('Y-m-d'), $_SESSION['user_id']]);
        }
        logAudit($pdo, $_SESSION['user_id'], 'ADD_ITEM', $item_id, $initial_qty, "Added item: $item_name in $cat_name");
        $pdo->commit();
        $success = "Item added successfully.";
    } catch (Exception $e) { $pdo->rollBack(); $error = 'Error: '.$e->getMessage(); }
}

// Edit Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_item'])) {
    $item_id       = (int)$_POST['item_id'];
    $item_name     = trim($_POST['item_name']);
    $category_id   = (int)$_POST['category_id'];
    $description   = trim($_POST['description']);
    $unit_price    = (float)$_POST['unit_price'];
    $reorder_level = (int)$_POST['reorder_level'];
    $cat           = $pdo->prepare("SELECT name FROM categories WHERE id=?");
    $cat->execute([$category_id]);
    $cat_name      = $cat->fetchColumn();
    $pdo->prepare("UPDATE items SET item_name=?,category_id=?,category=?,description=?,unit_price=?,reorder_level=? WHERE id=?")
        ->execute([$item_name, $category_id, $cat_name, $description, $unit_price, $reorder_level, $item_id]);
    logAudit($pdo, $_SESSION['user_id'], 'EDIT_ITEM', $item_id, null, "Updated item: $item_name");
    $success = 'Item updated successfully.';
}

// Delete Item
if (isset($_GET['delete_item'])) {
    $id  = (int)$_GET['delete_item'];
    $rc  = $pdo->prepare("SELECT COUNT(*) FROM stock_receiving WHERE item_id=?"); $rc->execute([$id]);
    $ic  = $pdo->prepare("SELECT COUNT(*) FROM stock_issuing WHERE item_id=?");   $ic->execute([$id]);
    if ($rc->fetchColumn() > 0 || $ic->fetchColumn() > 0) {
        $error = 'Cannot delete an item with transaction history.';
    } else {
        $pdo->prepare("DELETE FROM items WHERE id=?")->execute([$id]);
        $success = 'Item deleted successfully.';
    }
}

// Build items query
$query  = "SELECT i.*, c.name as category_name FROM items i LEFT JOIN categories c ON i.category_id=c.id WHERE 1=1";
$params = [];
if ($filter_category > 0) { $query .= " AND i.category_id=?"; $params[] = $filter_category; }
$query .= " ORDER BY i.category, i.item_name";
$items_stmt = $pdo->prepare($query);
$items_stmt->execute($params);
$items = $items_stmt->fetchAll();

$edit_item = null;
if (isset($_GET['edit_item'])) {
    $stmt = $pdo->prepare("SELECT * FROM items WHERE id=?");
    $stmt->execute([$_GET['edit_item']]);
    $edit_item = $stmt->fetch();
}

$edit_category = null;
if (isset($_GET['edit_category'])) {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id=?");
    $stmt->execute([$_GET['edit_category']]);
    $edit_category = $stmt->fetch();
}

include __DIR__ . '/../includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 15px;">
    <h1 style="margin: 0;">Manage Inventory</h1>
    <div style="display: flex; gap: 12px;">
        <button onclick="document.getElementById('categoryModal').style.display='flex'" class="btn" style="background: #263A8E; display: flex; align-items: center; gap: 8px;">
            Manage Categories
        </button>
    </div>
</div>

<!-- Category Filter Tabs -->
<div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 24px; padding: 8px 0;">
    <a href="manage_items.php" class="pill-tab <?php echo $filter_category==0?'active':''; ?>" style="padding: 8px 20px; background: <?php echo $filter_category==0?'#263A8E':'#f1f5f9'; ?>; color: <?php echo $filter_category==0?'white':'#475569'; ?>; border-radius: 40px; text-decoration: none; font-size: 13px; font-weight: 500;">
        All Categories
    </a>
    <?php foreach ($categories as $cat): ?>
    <a href="?category_id=<?php echo $cat['id']; ?>" class="pill-tab <?php echo $filter_category==$cat['id']?'active':''; ?>" style="padding: 8px 20px; background: <?php echo $filter_category==$cat['id']?'#263A8E':'#f1f5f9'; ?>; color: <?php echo $filter_category==$cat['id']?'white':'#475569'; ?>; border-radius: 40px; text-decoration: none; font-size: 13px; font-weight: 500;">
        <?php echo htmlspecialchars($cat['name']); ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if (isset($success)): ?>
    <div style="background: #d4edda; color: #155724; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;"><?php echo $success; ?></div>
<?php endif; ?>
<?php if (isset($error)): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;"><?php echo $error; ?></div>
<?php endif; ?>

<!-- Edit Item Inline Form -->
<?php if ($edit_item): ?>
<div style="background: white; border-radius: 8px; margin-bottom: 24px; border-left: 4px solid #f59e0b; border: 1px solid #e0e0e0;">
    <div style="padding: 16px 20px; border-bottom: 1px solid #e0e0e0; background: #fafafa;">
        <h3 style="margin: 0;">Edit Item: <?php echo htmlspecialchars($edit_item['item_name']); ?></h3>
    </div>
    <div style="padding: 20px;">
        <form method="POST">
            <input type="hidden" name="item_id" value="<?php echo $edit_item['id']; ?>">
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label>Item Name</label>
                    <input type="text" name="item_name" value="<?php echo htmlspecialchars($edit_item['item_name']); ?>" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                </div>
                <div class="form-group">
                    <label>Category</label>
                    <select name="category_id" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo ($edit_item['category_id']==$cat['id'])?'selected':''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                <div class="form-group">
                    <label>Unit Price (K)</label>
                    <input type="number" name="unit_price" step="0.01" min="0" value="<?php echo $edit_item['unit_price']; ?>" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                </div>
                <div class="form-group">
                    <label>Reorder Level</label>
                    <input type="number" name="reorder_level" min="0" value="<?php echo $edit_item['reorder_level']; ?>" required style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;">
                </div>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" rows="2" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;"><?php echo htmlspecialchars($edit_item['description']??''); ?></textarea>
            </div>
            <div style="display: flex; gap: 12px; margin-top: 20px;">
                <button type="submit" name="edit_item" class="btn" style="background: #f59e0b;">Update Item</button>
                <a href="manage_items.php<?php echo $filter_category?'?category_id='.$filter_category:''; ?>" class="btn" style="background: #6c757d;">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Items Table -->
<div style="background: white; border-radius: 8px; overflow: hidden; border: 1px solid #e0e0e0;">
    <div style="padding: 16px 20px; border-bottom: 1px solid #e0e0e0; background: #fafafa;">
        <h3 style="margin: 0;">Items <span style="font-weight: 400; color: #94a3b8;">(<?php echo count($items); ?> total)</span></h3>
    </div>

    <?php if (count($items) > 0): ?>
    <div style="padding: 20px; overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #fafafa; border-bottom: 2px solid #e0e0e0;">
                    <th style="padding: 12px; text-align: left;">Item Name</th>
                    <th style="padding: 12px; text-align: left;">Category</th>
                    <th style="padding: 12px; text-align: left;">Stock</th>
                    <th style="padding: 12px; text-align: left;">Price</th>
                    <th style="padding: 12px; text-align: left;">Value</th>
                    <th style="padding: 12px; text-align: left;">Reorder</th>
                    <th style="padding: 12px; text-align: left;">Status</th>
                    <th style="padding: 12px; text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): 
                    $total_value = $item['current_quantity'] * $item['unit_price'];
                    $is_low = $item['current_quantity'] <= $item['reorder_level'];
                    $is_out = $item['current_quantity'] == 0;
                ?>
                <tr style="border-bottom: 1px solid #e0e0e0;">
                    <td style="padding: 12px; font-weight: 500;"><?php echo htmlspecialchars($item['item_name']); ?></td>
                    <td style="padding: 12px;">
                        <span style="padding: 4px 12px; background: #f1f5f9; border-radius: 20px; font-size: 12px;">
                            <?php echo htmlspecialchars($item['category']); ?>
                        </span>
                    </td>
                    <td style="padding: 12px; font-weight: 600;"><?php echo $item['current_quantity']; ?> </td>
                    <td style="padding: 12px; font-family: monospace;">K<?php echo number_format($item['unit_price'], 2); ?> </td>
                    <td style="padding: 12px; font-family: monospace;">K<?php echo number_format($total_value, 2); ?> </td>
                    <td style="padding: 12px;"><?php echo $item['reorder_level']; ?> </td>
                    <td style="padding: 12px;">
                        <?php if ($is_out): ?>
                            <span style="background: #f8d7da; color: #721c24; padding: 3px 10px; border-radius: 12px; font-size: 11px;">Out of Stock</span>
                        <?php elseif ($is_low): ?>
                            <span style="background: #fef3c7; color: #856404; padding: 3px 10px; border-radius: 12px; font-size: 11px;">Low Stock</span>
                        <?php else: ?>
                            <span style="background: #d4edda; color: #155724; padding: 3px 10px; border-radius: 12px; font-size: 11px;">In Stock</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 12px;">
                        <div style="display: flex; gap: 6px; justify-content: center;">
                            <a href="?edit_item=<?php echo $item['id']; ?><?php echo $filter_category?'&category_id='.$filter_category:''; ?>" class="btn-icon" style="background: #263A8E; color: white; padding: 5px 12px; border-radius: 5px; text-decoration: none; font-size: 12px;">Edit</a>
                            <a href="?delete_item=<?php echo $item['id']; ?>" class="btn-icon" style="background: #dc2626; color: white; padding: 5px 12px; border-radius: 5px; text-decoration: none; font-size: 12px;" onclick="return confirm('Delete this item? This cannot be undone.')">Delete</a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div style="padding: 60px; text-align: center; color: #94a3b8;">
        <p>No items found in this category.</p>
    </div>
    <?php endif; ?>
</div>

<!-- Manage Categories Modal -->
<div id="categoryModal" class="modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); backdrop-filter: blur(4px); z-index: 1000; justify-content: center; align-items: center;">
    <div style="background: white; border-radius: 12px; width: 90%; max-width: 600px; max-height: 90vh; overflow-y: auto;">
        <div style="padding: 20px; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center;">
            <h3 style="margin: 0;">Manage Categories</h3>
            <button onclick="document.getElementById('categoryModal').style.display='none'" style="background: none; border: none; font-size: 24px; cursor: pointer;">&times;</button>
        </div>
        <div style="padding: 20px;">
            <!-- Add New Category Section -->
            <div style="margin-bottom: 24px; padding: 16px; background: #f8fafc; border-radius: 8px;">
                <h4 style="margin-bottom: 16px; font-size: 14px;">Add New Category</h4>
                <form method="POST">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                        <div class="form-group">
                            <label style="display: block; margin-bottom: 5px; font-size: 13px;">Category Name</label>
                            <input type="text" name="category_name" required placeholder="e.g., Electronics" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px;">
                        </div>
                        <div class="form-group">
                            <label style="display: block; margin-bottom: 5px; font-size: 13px;">Description (Optional)</label>
                            <input type="text" name="category_desc" placeholder="Brief description" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px;">
                        </div>
                    </div>
                    <button type="submit" name="add_category" class="btn" style="background: #263A8E; margin-top: 10px;">Add Category</button>

                </form>
            </div>
            
            <!-- Existing Categories List -->
            <div>
                <h4 style="margin-bottom: 16px; font-size: 14px;">Existing Categories</h4>
                <div style="display: flex; flex-direction: column; gap: 8px;">
                    <?php foreach ($categories as $cat):
                        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE category_id=?");
                        $count_stmt->execute([$cat['id']]);
                        $count = $count_stmt->fetchColumn();
                    ?>
                    <div style="display: flex; justify-content: space-between; align-items: center; padding: 12px; background: #f8fafc; border-radius: 8px;">
                        <div>
                            <span style="font-weight: 500;"><?php echo htmlspecialchars($cat['name']); ?></span>
                            <span style="font-size: 11px; color: #94a3b8; margin-left: 8px;"><?php echo $count; ?> items</span>
                        </div>
                        <div style="display: flex; gap: 6px;">
                            <a href="?edit_category=<?php echo $cat['id']; ?>" class="btn-icon" style="background: #f59e0b; color: white; padding: 5px 12px; border-radius: 5px; text-decoration: none; font-size: 12px;">Edit</a>
                            <?php if ($count == 0): ?>
                            <a href="?delete_category=<?php echo $cat['id']; ?>" class="btn-icon" style="background: #dc2626; color: white; padding: 5px 12px; border-radius: 5px; text-decoration: none; font-size: 12px;" onclick="return confirm('Delete category?')">Delete</a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if ($edit_category): ?>
            <div style="margin-top: 24px; padding-top: 24px; border-top: 1px solid #e2e8f0;">
                <h4 style="margin-bottom: 16px; font-size: 14px;">Edit Category</h4>
                <form method="POST">
                    <input type="hidden" name="category_id" value="<?php echo $edit_category['id']; ?>">
                    <div class="form-group">
                        <label>Category Name</label>
                        <input type="text" name="category_name" value="<?php echo htmlspecialchars($edit_category['name']); ?>" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px;">
                    </div>
                    <div class="form-group">
                        <label>Description</label>
                        <input type="text" name="category_desc" value="<?php echo htmlspecialchars($edit_category['description']??''); ?>" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px;">
                    </div>
                    <div style="display: flex; gap: 8px; margin-top: 12px;">
                        <button type="submit" name="edit_category" class="btn" style="background: #f59e0b;">Update Category</button>
                        <a href="manage_items.php" class="btn" style="background: #6c757d;">Cancel</a>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
.pill-tab.active {
    background: #263A8E !important;
    color: white !important;
}
.pill-tab:not(.active):hover {
    background: #e2e8f0 !important;
}
.modal {
    display: none;
}
</style>

<script>
<?php if ($edit_category): ?>
document.getElementById('categoryModal').style.display = 'flex';
<?php endif; ?>

window.addEventListener('click', function(e) {
    if (e.target === document.getElementById('categoryModal')) {
        document.getElementById('categoryModal').style.display = 'none';
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>