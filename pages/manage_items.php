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
            $success = "Category added.";
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
    $success = "Category updated.";
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
        $success = "Category deleted.";
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
        $success = "Item added.";
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
    $success = 'Item updated.';
}

// Delete Item
if (isset($_GET['delete_item'])) {
    $id  = (int)$_GET['delete_item'];
    $rc  = $pdo->prepare("SELECT COUNT(*) FROM stock_receiving WHERE item_id=?"); $rc->execute([$id]);
    $ic  = $pdo->prepare("SELECT COUNT(*) FROM stock_issuing WHERE item_id=?");   $ic->execute([$id]);
    if ($rc->fetch()['COUNT(*)'] > 0 || $ic->fetch()['COUNT(*)'] > 0) {
        $error = 'Cannot delete an item with transaction history.';
    } else {
        $pdo->prepare("DELETE FROM items WHERE id=?")->execute([$id]);
        $success = 'Item deleted.';
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

<div class="page-header">
    <h1>Manage Items</h1>
    <div style="display:flex; gap:8px;">
        <button onclick="document.getElementById('categoryModal').style.display='flex'" class="btn btn-sm">Manage Categories</button>
        <button onclick="document.getElementById('addItemModal').style.display='flex'" class="btn btn-sm btn-primary">Add Item</button>
    </div>
</div>

<!-- Category filter -->
<div class="pill-tabs">
    <a href="manage_items.php" class="pill-tab <?php echo $filter_category==0?'active':''; ?>">All</a>
    <?php foreach ($categories as $cat): ?>
    <a href="?category_id=<?php echo $cat['id']; ?>" class="pill-tab <?php echo $filter_category==$cat['id']?'active':''; ?>">
        <?php echo htmlspecialchars($cat['name']); ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
<?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<!-- Edit Item inline form -->
<?php if ($edit_item): ?>
<div class="card" style="border-left:3px solid var(--warning); margin-bottom:16px;">
    <div class="form-title">Edit: <?php echo htmlspecialchars($edit_item['item_name']); ?></div>
    <form method="POST">
        <input type="hidden" name="item_id" value="<?php echo $edit_item['id']; ?>">
        <div class="form-row">
            <div class="form-group">
                <label>Item Name</label>
                <input type="text" name="item_name" value="<?php echo htmlspecialchars($edit_item['item_name']); ?>" required>
            </div>
            <div class="form-group">
                <label>Category</label>
                <select name="category_id" required>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo ($edit_item['category_id']==$cat['id'])?'selected':''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Unit Price (K)</label>
                <input type="number" name="unit_price" step="0.01" min="0" value="<?php echo $edit_item['unit_price']; ?>" required>
            </div>
            <div class="form-group">
                <label>Reorder Level</label>
                <input type="number" name="reorder_level" min="0" value="<?php echo $edit_item['reorder_level']; ?>" required>
            </div>
        </div>
        <div class="form-group">
            <label>Description</label>
            <textarea name="description" rows="2"><?php echo htmlspecialchars($edit_item['description']??''); ?></textarea>
        </div>
        <div class="form-actions">
            <button type="submit" name="edit_item" class="btn btn-warning">Update Item</button>
            <a href="manage_items.php<?php echo $filter_category?'?category_id='.$filter_category:''; ?>" class="btn">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- Items table -->
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:8px;">
        <h3 style="margin:0">Items <span style="font-weight:400; color:var(--muted)">(<?php echo count($items); ?>)</span></h3>
    </div>

    <?php if (count($items) > 0): ?>
    <div class="table-container">
        <table>
            <thead>
                <tr><th>Item Name</th><th>Category</th><th>Stock</th><th>Price</th><th>Value</th><th>Reorder</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']); ?></td>
                    <td><span class="cat-pill"><?php echo htmlspecialchars($item['category']); ?></span></td>
                    <td><?php echo $item['current_quantity']; ?></td>
                    <td style="font-family:var(--mono); font-size:.78rem">K<?php echo number_format($item['unit_price'],2); ?></td>
                    <td style="font-family:var(--mono); font-size:.78rem">K<?php echo number_format($item['current_quantity']*$item['unit_price'],2); ?></td>
                    <td><?php echo $item['reorder_level']; ?></td>
                    <td>
                        <?php if ($item['current_quantity'] <= $item['reorder_level']): ?>
                            <span class="status-pending">Low</span>
                        <?php else: ?>
                            <span class="status-receiving">OK</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap">
                        <a href="?edit_item=<?php echo $item['id']; ?><?php echo $filter_category?'&category_id='.$filter_category:''; ?>" class="btn btn-sm">Edit</a>
                        <a href="?delete_item=<?php echo $item['id']; ?>" class="btn btn-sm" onclick="return confirm('Delete this item?')">Delete</a>
                        <a href="receive_stock.php?item=<?php echo $item['id']; ?>" class="btn btn-sm">Receive</a>
                        <a href="issue_stock.php?item=<?php echo $item['id']; ?>" class="btn btn-sm">Issue</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><p>No items found. Add items using the button above.</p></div>
    <?php endif; ?>
</div>

<!-- Manage Categories Modal -->
<div id="categoryModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Manage Categories</h3>
            <button class="modal-close" onclick="document.getElementById('categoryModal').style.display='none'">&times;</button>
        </div>

        <div class="section-divider">Add Category</div>
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>Name</label>
                    <input type="text" name="category_name" required placeholder="Category name">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" name="category_desc" placeholder="Optional">
                </div>
            </div>
            <button type="submit" name="add_category" class="btn btn-primary btn-sm">Add Category</button>
        </form>

        <div class="section-divider" style="margin-top:18px">Existing Categories</div>
        <div>
            <?php foreach ($categories as $cat): ?>
            <?php
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM items WHERE category_id=?");
            $count_stmt->execute([$cat['id']]);
            $count = $count_stmt->fetchColumn();
            ?>
            <div style="display:flex; justify-content:space-between; align-items:center; padding:8px 0; border-bottom:1px solid var(--border);">
                <div>
                    <span style="font-weight:500; font-size:.84rem"><?php echo htmlspecialchars($cat['name']); ?></span>
                    <span style="font-size:.72rem; color:var(--muted); margin-left:6px"><?php echo $count; ?> items</span>
                </div>
                <div style="display:flex; gap:6px;">
                    <a href="?edit_category=<?php echo $cat['id']; ?>" class="btn btn-sm">Edit</a>
                    <?php if ($count == 0): ?>
                    <a href="?delete_category=<?php echo $cat['id']; ?>" class="btn btn-sm" onclick="return confirm('Delete category?')">Delete</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($edit_category): ?>
        <div class="section-divider" style="margin-top:18px">Edit Category</div>
        <form method="POST">
            <input type="hidden" name="category_id" value="<?php echo $edit_category['id']; ?>">
            <div class="form-row">
                <div class="form-group">
                    <label>Name</label>
                    <input type="text" name="category_name" value="<?php echo htmlspecialchars($edit_category['name']); ?>" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" name="category_desc" value="<?php echo htmlspecialchars($edit_category['description']??''); ?>">
                </div>
            </div>
            <div class="form-actions">
                <button type="submit" name="edit_category" class="btn btn-warning btn-sm">Update</button>
                <a href="manage_items.php" class="btn btn-sm">Cancel</a>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Add Item Modal -->
<div id="addItemModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add New Item</h3>
            <button class="modal-close" onclick="document.getElementById('addItemModal').style.display='none'">&times;</button>
        </div>
        <form method="POST">
            <div class="form-group">
                <label>Item Name *</label>
                <input type="text" name="item_name" required placeholder="e.g., A4 Paper Ream">
            </div>
            <div class="form-group">
                <label>Category *</label>
                <select name="category_id" required>
                    <option value="">— Select —</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo ($filter_category==$cat['id'])?'selected':''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Unit Price (K)</label>
                    <input type="number" name="unit_price" step="0.01" min="0" required placeholder="0.00">
                </div>
                <div class="form-group">
                    <label>Reorder Level</label>
                    <input type="number" name="reorder_level" min="0" required value="10">
                </div>
            </div>
            <div class="form-group">
                <label>Initial Quantity</label>
                <input type="number" name="initial_quantity" min="0" value="0">
                <small>Set to 0 if item has no initial stock</small>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" rows="2" placeholder="Optional description"></textarea>
            </div>
            <div class="form-actions">
                <button type="submit" name="add_item" class="btn btn-success">Add Item</button>
                <button type="button" onclick="document.getElementById('addItemModal').style.display='none'" class="btn">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
window.addEventListener('click', function(e) {
    ['categoryModal','addItemModal'].forEach(function(id) {
        var m = document.getElementById(id);
        if (e.target === m) m.style.display = 'none';
    });
});
<?php if ($edit_item || $edit_category): ?>
document.getElementById('categoryModal').style.display = 'flex';
<?php endif; ?>
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>