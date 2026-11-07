<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();
if (!isManager()) { header('Location: dashboard.php'); exit(); }

$categories     = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$filter_category = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

// Flash messages carried over a redirect
if (!empty($_SESSION['flash']))       { $success = htmlspecialchars($_SESSION['flash']);       unset($_SESSION['flash']); }
if (!empty($_SESSION['flash_error'])) { $error   = htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); }

function back_to_items(int $cat = 0): void {
    header('Location: manage_items.php' . ($cat ? '?category_id=' . $cat : ''));
    exit();
}

// Add Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $v        = new Validator($_POST);
    $cat_name = $v->text('category_name', 'Category name', 100, true, 2);
    $cat_desc = $v->text('category_desc', 'Description', 255, false);
    if ($v->ok()) {
        $check = $pdo->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?)");
        $check->execute([$cat_name]);
        if ($check->fetch()) $v->fail("A category called \"$cat_name\" already exists.");
    }
    if ($v->ok()) {
        $pdo->prepare("INSERT INTO categories (name, description) VALUES (?, ?)")->execute([$cat_name, $cat_desc]);
        logAudit($pdo, $_SESSION['user_id'], 'ADD_CATEGORY', null, null, "Added category: $cat_name");
        $_SESSION['flash'] = "Category \"$cat_name\" added.";
        back_to_items();
    }
    $error = $v->message();
    $reopen_categories = true;
}

// Edit Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_category'])) {
    $v        = new Validator($_POST);
    $cat      = $v->exists($pdo, 'category_id', 'category', 'categories', 'id, name');
    $cat_name = $v->text('category_name', 'Category name', 100, true, 2);
    $cat_desc = $v->text('category_desc', 'Description', 255, false);
    if ($v->ok()) {
        $check = $pdo->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?) AND id <> ?");
        $check->execute([$cat_name, $cat['id']]);
        if ($check->fetch()) $v->fail("Another category is already called \"$cat_name\".");
    }
    if ($v->ok()) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE categories SET name=?, description=? WHERE id=?")->execute([$cat_name, $cat_desc, $cat['id']]);
            $pdo->prepare("UPDATE items SET category=? WHERE category_id=?")->execute([$cat_name, $cat['id']]);
            logAudit($pdo, $_SESSION['user_id'], 'EDIT_CATEGORY', null, null, "Renamed category {$cat['name']} to $cat_name");
            $pdo->commit();
            $_SESSION['flash'] = "Category \"$cat_name\" updated.";
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log($e->getMessage());
            $_SESSION['flash_error'] = 'Could not update the category. Please try again.';
        }
        back_to_items();
    }
    $error = $v->message();
    $reopen_categories = true;
}

// Delete Category (POST only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    $v   = new Validator($_POST);
    $cat = $v->exists($pdo, 'category_id', 'category', 'categories', 'id, name');
    if ($cat) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM items WHERE category_id=?");
        $check->execute([$cat['id']]);
        if ($check->fetchColumn() > 0) $v->fail("\"{$cat['name']}\" still has items. Move or delete them first.");
    }
    if ($v->ok()) {
        $pdo->prepare("DELETE FROM categories WHERE id=?")->execute([$cat['id']]);
        logAudit($pdo, $_SESSION['user_id'], 'DELETE_CATEGORY', null, null, "Deleted category: {$cat['name']}");
        $_SESSION['flash'] = "Category \"{$cat['name']}\" deleted.";
    } else {
        $_SESSION['flash_error'] = implode(' ', $v->errors());
    }
    back_to_items();
}

// Add Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $v             = new Validator($_POST);
    $item_name     = $v->text('item_name', 'Item name', 200, true, 2);
    $cat           = $v->exists($pdo, 'category_id', 'category', 'categories', 'id, name');
    $unit_price    = $v->money('unit_price', 'Unit price');
    $reorder_level = $v->int('reorder_level', 'Reorder level', 0, 1000000);
    $initial_qty   = $v->int('initial_quantity', 'Initial quantity', 0, 1000000, false) ?? 0;
    $description   = $v->textarea('description', 'Description', 500);
    if ($v->ok()) {
        $dup = $pdo->prepare("SELECT id FROM items WHERE category_id=? AND LOWER(item_name)=LOWER(?)");
        $dup->execute([$cat['id'], $item_name]);
        if ($dup->fetch()) $v->fail("\"$item_name\" already exists in {$cat['name']}.");
    }
    if ($v->ok()) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO items (item_name, category_id, category, description, current_quantity, unit_price, reorder_level) VALUES (?,?,?,?,?,?,?)")
                ->execute([$item_name, $cat['id'], $cat['name'], $description, $initial_qty, $unit_price, $reorder_level]);
            $item_id = $pdo->lastInsertId();
            if ($initial_qty > 0) {
                $pdo->prepare("INSERT INTO stock_receiving (item_id, supplier, quantity, unit_price, date_received, received_by) VALUES (?,?,?,?,?,?)")
                    ->execute([$item_id, 'Initial Stock', $initial_qty, $unit_price, date('Y-m-d'), $_SESSION['user_id']]);
            }
            logAudit($pdo, $_SESSION['user_id'], 'ADD_ITEM', $item_id, $initial_qty, "Added item: $item_name in {$cat['name']}");
            $pdo->commit();
            $_SESSION['flash'] = "\"$item_name\" added.";
            back_to_items($filter_category);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log($e->getMessage());
            $error = 'Could not add the item. Please try again.';
        }
    } else {
        $error = $v->message();
        $reopen_add_item = true;
    }
}

// Edit Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_item'])) {
    $v             = new Validator($_POST);
    $item          = $v->exists($pdo, 'item_id', 'item', 'items', 'id, item_name');
    $item_name     = $v->text('item_name', 'Item name', 200, true, 2);
    $cat           = $v->exists($pdo, 'category_id', 'category', 'categories', 'id, name');
    $unit_price    = $v->money('unit_price', 'Unit price');
    $reorder_level = $v->int('reorder_level', 'Reorder level', 0, 1000000);
    $description   = $v->textarea('description', 'Description', 500);
    if ($v->ok()) {
        $dup = $pdo->prepare("SELECT id FROM items WHERE category_id=? AND LOWER(item_name)=LOWER(?) AND id<>?");
        $dup->execute([$cat['id'], $item_name, $item['id']]);
        if ($dup->fetch()) $v->fail("Another item in {$cat['name']} is already called \"$item_name\".");
    }
    if ($v->ok()) {
        $pdo->prepare("UPDATE items SET item_name=?,category_id=?,category=?,description=?,unit_price=?,reorder_level=? WHERE id=?")
            ->execute([$item_name, $cat['id'], $cat['name'], $description, $unit_price, $reorder_level, $item['id']]);
        logAudit($pdo, $_SESSION['user_id'], 'EDIT_ITEM', $item['id'], null, "Updated item: $item_name");
        $_SESSION['flash'] = "\"$item_name\" updated.";
        back_to_items($filter_category);
    }
    $error = $v->message();
    $_GET['edit_item'] = $_POST['item_id'] ?? 0;
}

// Delete Item (POST only)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    $v    = new Validator($_POST);
    $item = $v->exists($pdo, 'item_id', 'item', 'items', 'id, item_name');
    if ($item) {
        $rc = $pdo->prepare("SELECT COUNT(*) FROM stock_receiving WHERE item_id=?"); $rc->execute([$item['id']]);
        $ic = $pdo->prepare("SELECT COUNT(*) FROM stock_issuing WHERE item_id=?");   $ic->execute([$item['id']]);
        if ($rc->fetchColumn() > 0 || $ic->fetchColumn() > 0) {
            $v->fail("\"{$item['item_name']}\" has stock history, so it can't be deleted. Adjust its stock to 0 instead.");
        }
    }
    if ($v->ok()) {
        $pdo->prepare("DELETE FROM items WHERE id=?")->execute([$item['id']]);
        logAudit($pdo, $_SESSION['user_id'], 'DELETE_ITEM', null, null, "Deleted item: {$item['item_name']}");
        $_SESSION['flash'] = "\"{$item['item_name']}\" deleted.";
    } else {
        $_SESSION['flash_error'] = implode(' ', $v->errors());
    }
    back_to_items($filter_category);
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
<?php if (isset($error) && empty($reopen_add_item) && empty($reopen_categories)): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<!-- Edit Item inline form -->
<?php if ($edit_item): ?>
<div class="card" style="border-left:3px solid var(--warning); margin-bottom:16px;">
    <div class="form-title">Edit: <?php echo htmlspecialchars($edit_item['item_name']); ?></div>
    <form method="POST"
          data-confirm-title="Save changes"
          data-confirm="Save the changes to <?php echo htmlspecialchars($edit_item['item_name']); ?>? Price changes affect stock value in reports."
          data-confirm-ok="Save changes">
        <input type="hidden" name="item_id" value="<?php echo $edit_item['id']; ?>">
        <div class="form-row">
            <div class="form-group">
                <label>Item Name *</label>
                <input type="text" name="item_name" value="<?php echo htmlspecialchars($edit_item['item_name']); ?>" required minlength="2" maxlength="200">
            </div>
            <div class="form-group">
                <label>Category *</label>
                <select name="category_id" required>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo ($edit_item['category_id']==$cat['id'])?'selected':''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Unit Price (K) *</label>
                <input type="number" name="unit_price" step="0.01" min="0" max="100000000" value="<?php echo $edit_item['unit_price']; ?>" required>
            </div>
            <div class="form-group">
                <label>Reorder Level *</label>
                <input type="number" name="reorder_level" min="0" max="1000000" step="1" value="<?php echo $edit_item['reorder_level']; ?>" required>
            </div>
        </div>
        <div class="form-group">
            <label>Description</label>
            <textarea name="description" rows="2" maxlength="500"><?php echo htmlspecialchars($edit_item['description']??''); ?></textarea>
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
                        <form method="POST" class="inline-form"
                              data-confirm-title="Delete item"
                              data-confirm="Delete <?php echo htmlspecialchars($item['item_name']); ?>? This cannot be undone."
                              data-confirm-ok="Delete" data-confirm-danger="1">
                            <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                            <button type="submit" name="delete_item" value="1" class="btn btn-sm">Delete</button>
                        </form>
                        <a href="receive_stock.php?category_id=<?php echo (int)$item['category_id']; ?>&item_id=<?php echo $item['id']; ?>" class="btn btn-sm">Receive</a>
                        <a href="issue_stock.php?category_id=<?php echo (int)$item['category_id']; ?>&item_id=<?php echo $item['id']; ?>" class="btn btn-sm">Issue</a>
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
        <?php if (!empty($reopen_categories) && isset($error)): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

        <div class="section-divider">Add Category</div>
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>Name *</label>
                    <input type="text" name="category_name" required minlength="2" maxlength="100" placeholder="Category name">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" name="category_desc" maxlength="255" placeholder="Optional">
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
                    <form method="POST" class="inline-form"
                          data-confirm-title="Delete category"
                          data-confirm="Delete the <?php echo htmlspecialchars($cat['name']); ?> category? This cannot be undone."
                          data-confirm-ok="Delete" data-confirm-danger="1">
                        <input type="hidden" name="category_id" value="<?php echo $cat['id']; ?>">
                        <button type="submit" name="delete_category" value="1" class="btn btn-sm">Delete</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php if ($edit_category): ?>
        <div class="section-divider" style="margin-top:18px">Edit Category</div>
        <form method="POST"
              data-confirm-title="Rename category"
              data-confirm="Save the changes to <?php echo htmlspecialchars($edit_category['name']); ?>? Every item in this category will show the new name."
              data-confirm-ok="Save changes">
            <input type="hidden" name="category_id" value="<?php echo $edit_category['id']; ?>">
            <div class="form-row">
                <div class="form-group">
                    <label>Name *</label>
                    <input type="text" name="category_name" value="<?php echo htmlspecialchars($edit_category['name']); ?>" required minlength="2" maxlength="100">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" name="category_desc" maxlength="255" value="<?php echo htmlspecialchars($edit_category['description']??''); ?>">
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
        <?php $keep = !empty($reopen_add_item); ?>
        <?php if ($keep && isset($error)): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>
        <form method="POST"
              data-confirm-title="Add item"
              data-confirm="Add {item_name} to {category_id} with {initial_quantity} units in stock?"
              data-confirm-ok="Add item">
            <div class="form-group">
                <label>Item Name *</label>
                <input type="text" name="item_name" required minlength="2" maxlength="200" placeholder="e.g., A4 Paper Ream"
                       value="<?php echo $keep ? htmlspecialchars($_POST['item_name'] ?? '') : ''; ?>">
            </div>
            <div class="form-group">
                <label>Category *</label>
                <select name="category_id" required>
                    <option value="">— Select —</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo (($keep ? (int)($_POST['category_id'] ?? 0) : $filter_category)==$cat['id'])?'selected':''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Unit Price (K) *</label>
                    <input type="number" name="unit_price" step="0.01" min="0" max="100000000" required placeholder="0.00"
                           value="<?php echo $keep ? htmlspecialchars($_POST['unit_price'] ?? '') : ''; ?>">
                </div>
                <div class="form-group">
                    <label>Reorder Level *</label>
                    <input type="number" name="reorder_level" min="0" max="1000000" step="1" required
                           value="<?php echo $keep ? htmlspecialchars($_POST['reorder_level'] ?? '10') : '10'; ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Initial Quantity</label>
                <input type="number" name="initial_quantity" min="0" max="1000000" step="1" value="<?php echo $keep ? htmlspecialchars($_POST['initial_quantity'] ?? '0') : '0'; ?>">
                <small>Set to 0 if item has no initial stock</small>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" rows="2" maxlength="500" placeholder="Optional description"><?php echo $keep ? htmlspecialchars($_POST['description'] ?? '') : ''; ?></textarea>
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
<?php if ($edit_category || !empty($reopen_categories)): ?>
document.getElementById('categoryModal').style.display = 'flex';
<?php endif; ?>
<?php if (!empty($reopen_add_item)): ?>
document.getElementById('addItemModal').style.display = 'flex';
<?php endif; ?>
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>