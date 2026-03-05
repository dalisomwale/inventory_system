<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();
if (!isManager()) { header('Location: dashboard.php'); exit(); }

// Your specific categories
$DEFAULT_CATS = ['Caterriage', 'Stationery', 'Dry Stores', 'Catering', 'Uniforms', 'Flight Documents', 'Other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category = trim($_POST['category'] ?: 'Other');

    if (isset($_POST['add_item'])) {
        $pdo->prepare("INSERT INTO items (item_name, category, description, unit_price, reorder_level) VALUES (?,?,?,?,?)")
            ->execute([trim($_POST['item_name']), $category, trim($_POST['description']),
                       (float)$_POST['unit_price'], (int)$_POST['reorder_level']]);
        logAudit($pdo, $_SESSION['user_id'], 'ADD_ITEM', (int)$pdo->lastInsertId(), null, "Added: ".$_POST['item_name']);
        $success = 'Item added successfully!';
    }
    if (isset($_POST['edit_item'])) {
        $id = (int)$_POST['item_id'];
        $pdo->prepare("UPDATE items SET item_name=?, category=?, description=?, unit_price=?, reorder_level=? WHERE id=?")
            ->execute([trim($_POST['item_name']), $category, trim($_POST['description']),
                       (float)$_POST['unit_price'], (int)$_POST['reorder_level'], $id]);
        logAudit($pdo, $_SESSION['user_id'], 'EDIT_ITEM', $id, null, "Updated: ".$_POST['item_name']);
        $success = 'Item updated successfully!';
    }
}

if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $rc = $pdo->prepare("SELECT COUNT(*) as c FROM stock_receiving WHERE item_id=?"); $rc->execute([$id]);
    $ic = $pdo->prepare("SELECT COUNT(*) as c FROM stock_issuing   WHERE item_id=?"); $ic->execute([$id]);
    if ($rc->fetch()['c'] > 0 || $ic->fetch()['c'] > 0) {
        $error = 'Cannot delete an item that has transaction history.';
    } else {
        $pdo->prepare("DELETE FROM items WHERE id=?")->execute([$id]);
        logAudit($pdo, $_SESSION['user_id'], 'DELETE_ITEM', $id, null, "Deleted item ID: $id");
        $success = 'Item deleted.';
    }
}

// Active category filter
$filter_cat = $_GET['cat'] ?? 'all';

// Fetch items (filtered or all), ordered by category then name
if ($filter_cat && $filter_cat !== 'all') {
    $stmt = $pdo->prepare("SELECT * FROM items WHERE category = ? ORDER BY item_name");
    $stmt->execute([$filter_cat]);
    $items = $stmt->fetchAll();
} else {
    $items = $pdo->query("SELECT * FROM items ORDER BY category, item_name")->fetchAll();
}

// All distinct categories for the tab bar
$all_cats = getCategories($pdo);

// Item being edited
$edit_item = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare("SELECT * FROM items WHERE id=?"); $s->execute([$_GET['edit']]); $edit_item = $s->fetch();
}

include __DIR__ . '/../includes/header.php';
?>
<h1>Manage Items</h1>

<?php if (isset($success)): ?><div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
<?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

<!-- ADD / EDIT FORM -->
<div class="form-container wide">
    <div class="form-title"><?php echo $edit_item ? 'Edit Item' : 'Add New Item'; ?></div>
    <form method="POST">
        <?php if ($edit_item): ?>
            <input type="hidden" name="item_id" value="<?php echo $edit_item['id']; ?>">
        <?php endif; ?>

        <div class="form-row">
            <div class="form-group">
                <label>Item Name</label>
                <input type="text" name="item_name" required placeholder="e.g. A4 Paper Ream"
                       value="<?php echo htmlspecialchars($edit_item['item_name'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>Category</label>
                <input type="text" name="category" list="cat-list" placeholder="Select or type category"
                       value="<?php echo htmlspecialchars($edit_item['category'] ?? 'Other'); ?>">
                <datalist id="cat-list">
                    <?php foreach ($DEFAULT_CATS as $dc): ?>
                    <option value="<?php echo htmlspecialchars($dc); ?>">
                    <?php endforeach; ?>
                    <?php foreach ($all_cats as $ac): ?>
                    <option value="<?php echo htmlspecialchars($ac); ?>">
                    <?php endforeach; ?>
                </datalist>
                <small>Standard categories: Caterriage, Stationery, Dry Stores, Catering, Uniforms, Flight Documents, Other</small>
            </div>
        </div>

        <div class="form-group">
            <label>Description <span style="font-weight:400;color:var(--muted)">(optional)</span></label>
            <textarea name="description" rows="2" placeholder="Brief description"><?php echo htmlspecialchars($edit_item['description'] ?? ''); ?></textarea>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Unit Price (K)</label>
                <input type="number" name="unit_price" min="0" step="0.01" required
                       value="<?php echo htmlspecialchars($edit_item['unit_price'] ?? '0'); ?>">
            </div>
            <div class="form-group">
                <label>Reorder Level</label>
                <input type="number" name="reorder_level" min="0" required
                       value="<?php echo htmlspecialchars($edit_item['reorder_level'] ?? '10'); ?>">
            </div>
        </div>

        <div class="form-actions">
            <button type="submit" name="<?php echo $edit_item ? 'edit_item' : 'add_item'; ?>"
                    class="btn <?php echo $edit_item ? 'btn-warning' : 'btn-success'; ?>">
                <?php echo $edit_item ? 'Update Item' : 'Add Item'; ?>
            </button>
            <?php if ($edit_item): ?><a href="manage_items.php" class="btn">Cancel</a><?php endif; ?>
        </div>
    </form>
</div>

<!-- CATEGORY FILTER TABS -->
<div class="cat-tabs">
    <a href="manage_items.php" class="cat-tab <?php echo $filter_cat==='all'?'active':''; ?>">All Items
        <span style="font-size:.72rem;margin-left:4px;opacity:.7"><?php echo count($pdo->query('SELECT id FROM items')->fetchAll()); ?></span>
    </a>
    <?php foreach ($all_cats as $i => $cat): ?>
    <a href="?cat=<?php echo urlencode($cat); ?>" class="cat-tab <?php echo $filter_cat===$cat?'active':''; ?>">
        <?php echo htmlspecialchars($cat); ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- ITEMS TABLE -->
<div class="card">
    <h3><?php echo $filter_cat !== 'all' ? htmlspecialchars($filter_cat) : 'All Items'; ?>
        <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.82rem;color:var(--muted)">
            — <?php echo count($items); ?> item<?php echo count($items) !== 1 ? 's' : ''; ?>
        </span>
    </h3>
    <div class="table-container">
        <table>
            <thead>
                <tr><th>Item Name</th><th>Category</th><th>Description</th><th>Stock</th><th>Unit Price</th><th>Total Value</th><th>Reorder</th><th>Status</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']); ?></td>
                    <td><span class="cat-pill <?php echo catClass($item['category']); ?>"><?php echo htmlspecialchars($item['category']); ?></span></td>
                    <td style="color:var(--muted);font-size:.84rem"><?php echo htmlspecialchars($item['description']); ?></td>
                    <td><?php echo $item['current_quantity']; ?></td>
                    <td>K<?php echo number_format($item['unit_price'], 2); ?></td>
                    <td>K<?php echo number_format($item['current_quantity'] * $item['unit_price'], 2); ?></td>
                    <td><?php echo $item['reorder_level']; ?></td>
                    <td>
                        <?php if ($item['current_quantity'] <= $item['reorder_level']): ?>
                            <span class="status-pending">Low Stock</span>
                        <?php else: ?>
                            <span class="status-receiving">OK</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap">
                        <a href="?edit=<?php echo $item['id']; ?><?php echo $filter_cat!=='all'?'&cat='.urlencode($filter_cat):'';?>" class="btn btn-sm">Edit</a>
                        <a href="?delete=<?php echo $item['id']; ?>" class="btn btn-sm btn-danger"
                           onclick="return confirm('Delete this item permanently?')">Delete</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($items)): ?>
                <tr><td colspan="9" style="text-align:center;color:var(--muted);padding:24px">
                    No items found<?php echo $filter_cat!=='all'?' in this category':''; ?>.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>