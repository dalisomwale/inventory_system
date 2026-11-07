<?php
// Categories — add, rename and delete stock categories.
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

if (!empty($_SESSION['flash']))       { $success = htmlspecialchars($_SESSION['flash']);       unset($_SESSION['flash']); }
if (!empty($_SESSION['flash_error'])) { $error   = htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); }

function back_to_categories(): void { header('Location: categories.php'); exit(); }

// ── Add ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $v    = new Validator($_POST);
    $name = $v->text('category_name', 'Category name', 100, true, 2);
    $desc = $v->text('category_desc', 'Description', 255, false);
    if ($v->ok()) {
        $c = $pdo->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?)");
        $c->execute([$name]);
        if ($c->fetch()) $v->fail("A category called \"$name\" already exists.");
    }
    if ($v->ok()) {
        $pdo->prepare("INSERT INTO categories (name, description) VALUES (?, ?)")->execute([$name, $desc]);
        logAudit($pdo, $_SESSION['user_id'], 'ADD_CATEGORY', null, null, "Added category: $name");
        $_SESSION['flash'] = "Category \"$name\" added.";
        back_to_categories();
    }
    $error = $v->message(); $form_error = 'add';
}

// ── Rename / edit ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_category'])) {
    $v    = new Validator($_POST);
    $cat  = $v->exists($pdo, 'category_id', 'category', 'categories', 'id, name');
    $name = $v->text('category_name', 'Category name', 100, true, 2);
    $desc = $v->text('category_desc', 'Description', 255, false);
    if ($v->ok()) {
        $c = $pdo->prepare("SELECT id FROM categories WHERE LOWER(name) = LOWER(?) AND id <> ?");
        $c->execute([$name, $cat['id']]);
        if ($c->fetch()) $v->fail("Another category is already called \"$name\".");
    }
    if ($v->ok()) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE categories SET name=?, description=? WHERE id=?")->execute([$name, $desc, $cat['id']]);
            $pdo->prepare("UPDATE items SET category=? WHERE category_id=?")->execute([$name, $cat['id']]);
            $pdo->prepare("UPDATE procurement SET category=? WHERE category_id=?")->execute([$name, $cat['id']]);
            logAudit($pdo, $_SESSION['user_id'], 'EDIT_CATEGORY', null, null, "Renamed category {$cat['name']} to $name");
            $pdo->commit();
            $_SESSION['flash'] = "Category \"$name\" saved.";
        } catch (Exception $e) {
            $pdo->rollBack(); error_log($e->getMessage());
            $_SESSION['flash_error'] = 'Could not save the category. Please try again.';
        }
        back_to_categories();
    }
    $error = $v->message(); $form_error = 'edit';
    $_GET['edit'] = (int)($_POST['category_id'] ?? 0);
}

// ── Delete (only empty categories) ──────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    $v   = new Validator($_POST);
    $cat = $v->exists($pdo, 'category_id', 'category', 'categories', 'id, name');
    if ($cat) {
        $n = $pdo->prepare("SELECT COUNT(*) FROM items WHERE category_id=?");
        $n->execute([$cat['id']]);
        if ($n->fetchColumn() > 0) $v->fail("\"{$cat['name']}\" still has items. Move them to another category first (Manage Stock › Edit).");
    }
    if ($v->ok()) {
        try {
            $pdo->prepare("DELETE FROM categories WHERE id=?")->execute([$cat['id']]);
            logAudit($pdo, $_SESSION['user_id'], 'DELETE_CATEGORY', null, null, "Deleted category: {$cat['name']}");
            $_SESSION['flash'] = "Category \"{$cat['name']}\" deleted.";
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $_SESSION['flash_error'] = "\"{$cat['name']}\" is used by requisitions or reports, so it can't be deleted.";
        }
    } else {
        $_SESSION['flash_error'] = implode(' ', $v->errors());
    }
    back_to_categories();
}

// ── Data ────────────────────────────────────────────────────
$cats = $pdo->query("
    SELECT c.id, c.name, c.description, c.created_at,
           COUNT(i.id) AS items, COALESCE(SUM(i.current_quantity), 0) AS units,
           COALESCE(SUM(i.current_quantity * i.unit_price), 0) AS value
    FROM categories c LEFT JOIN items i ON i.category_id = c.id
    GROUP BY c.id, c.name, c.description, c.created_at
    ORDER BY c.name")->fetchAll();

$editing = null;
if (!empty($_GET['edit'])) {
    foreach ($cats as $c) if ((int)$c['id'] === (int)$_GET['edit']) $editing = $c;
}

$breadcrumbs = $editing ? [['Categories', 'categories.php'], ['Edit ' . $editing['name'], null]] : [['Categories', null]];
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header"><h1>Categories</h1></div>

<?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
<?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<?php if ($editing): ?>
<!-- Edit -->
<div class="form-container wide" id="edit">
    <div class="form-title">Edit category</div>
    <form method="POST"
          data-confirm-title="Save category"
          data-confirm="Save the changes to <?php echo htmlspecialchars($editing['name']); ?>? <?php echo $editing['items'] ? 'Its ' . (int)$editing['items'] . ' item(s) will show the new name.' : ''; ?>"
          data-confirm-ok="Save changes">
        <input type="hidden" name="edit_category" value="1">
        <input type="hidden" name="category_id" value="<?php echo $editing['id']; ?>">
        <div class="form-row">
            <div class="form-group">
                <label for="edit_name">Category name *</label>
                <input type="text" id="edit_name" name="category_name" required minlength="2" maxlength="100"
                       value="<?php echo htmlspecialchars(($form_error ?? '') === 'edit' ? ($_POST['category_name'] ?? '') : $editing['name']); ?>">
            </div>
            <div class="form-group">
                <label for="edit_desc">Description</label>
                <input type="text" id="edit_desc" name="category_desc" maxlength="255" placeholder="Optional"
                       value="<?php echo htmlspecialchars(($form_error ?? '') === 'edit' ? ($_POST['category_desc'] ?? '') : ($editing['description'] ?? '')); ?>">
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Save changes</button>
            <a href="categories.php" class="btn">Cancel</a>
        </div>
    </form>
</div>
<?php else: ?>
<!-- Add -->
<div class="form-container wide" id="add">
    <div class="form-title">Add category</div>
    <form method="POST"
          data-confirm-title="Add category"
          data-confirm="Add the category {category_name}?"
          data-confirm-ok="Add category">
        <input type="hidden" name="add_category" value="1">
        <div class="form-row">
            <div class="form-group">
                <label for="category_name">Category name *</label>
                <input type="text" id="category_name" name="category_name" required minlength="2" maxlength="100" placeholder="e.g. Printer Consumables"
                       value="<?php echo htmlspecialchars(($form_error ?? '') === 'add' ? ($_POST['category_name'] ?? '') : ''); ?>"
                       <?php echo isset($_GET['add']) ? 'autofocus' : ''; ?>>
            </div>
            <div class="form-group">
                <label for="category_desc">Description</label>
                <input type="text" id="category_desc" name="category_desc" maxlength="255" placeholder="Optional, e.g. Toner, ink cartridges, drums"
                       value="<?php echo htmlspecialchars(($form_error ?? '') === 'add' ? ($_POST['category_desc'] ?? '') : ''); ?>">
            </div>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn-primary">Add category</button></div>
    </form>
</div>
<?php endif; ?>

<!-- List -->
<div class="card">
    <div class="card-head"><h3>All categories (<?php echo count($cats); ?>)</h3></div>
    <?php if ($cats): ?>
    <div class="table-container">
        <table>
            <thead><tr><th>Category</th><th>Description</th><th class="num">Items</th><th class="num">Units in stock</th><th class="num">Value</th><th>Date added</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($cats as $c): ?>
                <tr>
                    <td style="font-weight:600"><?php echo htmlspecialchars($c['name']); ?></td>
                    <td class="muted"><?php echo htmlspecialchars($c['description'] ?: '—'); ?></td>
                    <td class="num"><?php echo number_format($c['items']); ?></td>
                    <td class="num"><?php echo number_format($c['units']); ?></td>
                    <td class="num"><?php echo 'K' . number_format($c['value']); ?></td>
                    <td class="muted" style="white-space:nowrap"><?php echo date('d M Y', strtotime($c['created_at'])); ?></td>
                    <td><div class="row-actions">
                        <a href="stock.php?category_id=<?php echo $c['id']; ?>" class="btn btn-sm">View items</a>
                        <a href="categories.php?edit=<?php echo $c['id']; ?>#edit" class="btn btn-sm">Edit</a>
                        <?php if ((int)$c['items'] === 0): ?>
                        <form method="POST" class="inline-form"
                              data-confirm-title="Delete category"
                              data-confirm="Delete the <?php echo htmlspecialchars($c['name']); ?> category? This cannot be undone."
                              data-confirm-ok="Delete" data-confirm-danger="1">
                            <input type="hidden" name="category_id" value="<?php echo $c['id']; ?>">
                            <button type="submit" name="delete_category" value="1" class="btn btn-sm btn-danger-outline">Delete</button>
                        </form>
                        <?php else: ?>
                        <span class="btn btn-sm" aria-disabled="true" data-tip="Has items, so it can't be deleted">Delete</span>
                        <?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><p>No categories yet. Add your first one above.</p></div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
