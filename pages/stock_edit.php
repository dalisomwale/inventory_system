<?php
// Add or edit a stock item, and delete items.
require_once __DIR__ . '/../includes/auth.php';
requirePermission('items.manage');

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

/** Number of stock movements recorded for an item. */
function item_history_count(PDO $pdo, int $id): int {
    $st = $pdo->prepare("SELECT (SELECT COUNT(*) FROM stock_receiving WHERE item_id = ?) + (SELECT COUNT(*) FROM stock_issuing WHERE item_id = ?)");
    $st->execute([$id, $id]);
    return (int)$st->fetchColumn();
}

// ── Delete ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item'])) {
    $v    = new Validator($_POST);
    $item = $v->exists($pdo, 'id', 'item', 'items', 'id, item_name');
    if ($item && item_history_count($pdo, (int)$item['id']) > 0) {
        $v->fail("\"{$item['item_name']}\" has received or issued stock, so it can't be deleted. Its history must be kept for the records.");
    }
    if ($v->ok()) {
        try {
            $pdo->prepare("UPDATE procurement SET item_id = NULL WHERE item_id = ?")->execute([$item['id']]);
            $pdo->prepare("DELETE FROM items WHERE id = ?")->execute([$item['id']]);
            logAudit($pdo, $_SESSION['user_id'], 'DELETE_ITEM', null, null, "Deleted item: {$item['item_name']}");
            $_SESSION['flash'] = "\"{$item['item_name']}\" deleted.";
        } catch (PDOException $e) {
            error_log($e->getMessage());
            $_SESSION['flash_error'] = "\"{$item['item_name']}\" couldn't be deleted because other records use it.";
        }
    } else {
        $_SESSION['flash_error'] = implode(' ', $v->errors());
    }
    $back = $_POST['return'] ?? 'stock';
    if (parse_url($back, PHP_URL_PATH) !== 'stock') $back = 'stock';
    header('Location: ' . $back);
    exit();
}

// ── Load (edit) or start blank (add) ────────────────────────
$id   = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$item = null;
if ($id) {
    $st = $pdo->prepare("SELECT * FROM items WHERE id = ?");
    $st->execute([$id]);
    $item = $st->fetch();
    if (!$item) { $_SESSION['flash_error'] = 'That item no longer exists.'; header('Location: stock'); exit(); }
}
$is_new = !$item;
// Once an item has stock history its price is set by deliveries (Receive Existing Item), not edited here
$locked = !$is_new && item_history_count($pdo, (int)$item['id']) > 0;

// ── Save ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_item'])) {
    $v             = new Validator($_POST);
    $item_name     = $v->text('item_name', 'Item name', 200, true, 2);
    $cat           = $v->exists($pdo, 'category_id', 'category', 'categories', 'id, name');
    $description   = $v->textarea('description', 'Description', 500);
    $unit_price    = $locked ? (float)$item['unit_price'] : $v->money('unit_price', 'Unit price');
    $reorder_level = $v->int('reorder_level', 'Reorder level', 0, 1000000);
    $opening       = $is_new ? ($v->int('opening_quantity', 'Opening stock', 0, 1000000, false) ?? 0) : 0;
    $supplier      = $is_new ? $v->text('supplier', 'Supplier', 200, false) : '';
    $open_branch   = 0;
    if ($is_new && $opening > 0) {
        $open_branch = (int)($_POST['branch_id'] ?? 0);
        if (!in_array($open_branch, array_map(fn($b) => (int)$b['id'], org_branches($pdo)), true)) $v->fail('Please choose the branch that holds the opening stock.');
    }

    if ($v->ok()) {
        $dup = $pdo->prepare("SELECT id FROM items WHERE category_id = ? AND LOWER(item_name) = LOWER(?) AND id <> ?");
        $dup->execute([$cat['id'], $item_name, $is_new ? 0 : $item['id']]);
        if ($dup->fetch()) $v->fail("Another item in {$cat['name']} is already called \"$item_name\".");
    }

    if (!$v->ok()) {
        $error = $v->message();
    } elseif ($is_new) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO items (item_name, category_id, category, description, current_quantity, unit_price, reorder_level) VALUES (?,?,?,?,0,?,?)")
                ->execute([$item_name, $cat['id'], $cat['name'], $description, $unit_price, $reorder_level]);
            $new_id = (int)$pdo->lastInsertId();
            if ($opening > 0) {
                [$ob, $nb] = branch_stock_change($pdo, $new_id, $open_branch, $opening);
                $pdo->prepare("INSERT INTO stock_receiving (item_id, supplier, quantity, unit_price, date_received, received_by, branch_id, old_stock, new_stock) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$new_id, $supplier ?: 'Opening stock', $opening, $unit_price, date('Y-m-d'), $_SESSION['user_id'], $open_branch, $ob, $nb]);
            }
            logAudit($pdo, $_SESSION['user_id'], 'ADD_ITEM', $new_id, $opening, "Added item: $item_name in {$cat['name']}");
            $pdo->commit();
            $_SESSION['flash'] = "\"$item_name\" added.";
            header('Location: stock_item?id=' . $new_id);
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log($e->getMessage());
            $error = 'Could not add the item. Please try again.';
        }
    } else {
        $pdo->prepare("UPDATE items SET item_name=?, category_id=?, category=?, description=?, unit_price=?, reorder_level=? WHERE id=?")
            ->execute([$item_name, $cat['id'], $cat['name'], $description, $unit_price, $reorder_level, $item['id']]);
        logAudit($pdo, $_SESSION['user_id'], 'EDIT_ITEM', (int)$item['id'], null, "Updated item: $item_name");
        $_SESSION['flash'] = "\"$item_name\" saved.";
        header('Location: stock_item?id=' . $item['id']);
        exit();
    }
}

// Values for the form: what was typed (after an error), else the item, else defaults
$val = function (string $key, $default = '') use ($item) {
    if (isset($GLOBALS['error'])) return $_POST[$key] ?? $default;
    return $item[$key] ?? $default;
};

$breadcrumbs = $is_new
    ? [['Manage Stock', 'stock'], ['Add item', null]]
    : [['Manage Stock', 'stock'], [$item['item_name'], 'stock_item?id=' . $item['id']], ['Edit', null]];

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><?php echo $is_new ? 'Add item' : 'Edit ' . htmlspecialchars($item['item_name']); ?></h1>
</div>

<?php if (isset($error)): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<div class="form-container wide">
    <form method="POST"
          data-confirm-title="<?php echo $is_new ? 'Add item' : 'Save changes'; ?>"
          data-confirm="<?php echo $is_new ? 'Add {item_name} to {category_id}?' : 'Save the changes to ' . htmlspecialchars($item['item_name']) . '?'; ?>"
          data-confirm-ok="<?php echo $is_new ? 'Add item' : 'Save changes'; ?>">
        <input type="hidden" name="save_item" value="1">
        <?php if (!$is_new): ?><input type="hidden" name="id" value="<?php echo $item['id']; ?>"><?php endif; ?>

        <div class="form-row">
            <div class="form-group">
                <label for="item_name">Item name *</label>
                <input type="text" id="item_name" name="item_name" required minlength="2" maxlength="200"
                       value="<?php echo htmlspecialchars($val('item_name')); ?>" placeholder="e.g. A4 Paper Ream">
            </div>
            <div class="form-group">
                <label for="category_id">Category *</label>
                <select id="category_id" name="category_id" required>
                    <option value="">— Select —</option>
                    <?php foreach ($categories as $c): ?>
                    <option value="<?php echo $c['id']; ?>" <?php echo (int)$val('category_id', (int)($_GET['category_id'] ?? 0)) === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <small><a href="categories">Manage categories</a></small>
            </div>
        </div>

        <div class="form-group">
            <label for="description">Description</label>
            <textarea id="description" name="description" rows="2" maxlength="500" placeholder="Optional"><?php echo htmlspecialchars($val('description')); ?></textarea>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="unit_price">Unit price (K)<?php echo $locked ? '' : ' *'; ?></label>
                <?php if ($locked): ?>
                <input type="text" id="unit_price" class="readonly-field" readonly tabindex="-1" value="<?php echo number_format((float)$item['unit_price'], 2); ?>">
                <?php else: ?>
                <input type="number" id="unit_price" name="unit_price" step="0.01" min="0" max="100000000" required
                       value="<?php echo htmlspecialchars($val('unit_price', '')); ?>" placeholder="0.00">
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label for="reorder_level">Reorder level *</label>
                <input type="number" id="reorder_level" name="reorder_level" step="1" min="0" max="1000000" required
                       value="<?php echo htmlspecialchars($val('reorder_level', '10')); ?>">
                <small>You'll be warned when stock falls to this level.</small>
            </div>
        </div>

        <?php if ($is_new): ?>
        <div class="form-row">
            <div class="form-group">
                <label for="opening_quantity">Opening stock</label>
                <input type="number" id="opening_quantity" name="opening_quantity" step="1" min="0" max="1000000"
                       value="<?php echo htmlspecialchars($_POST['opening_quantity'] ?? '0'); ?>">
                <small>How many you have right now. Leave at 0 if none.</small>
            </div>
            <?php $bl = org_branches($pdo); $mine = user_branch_id(); ?>
            <?php if (count($bl) > 1): ?>
            <div class="form-group">
                <label for="branch_id">Held at branch</label>
                <select id="branch_id" name="branch_id">
                    <?php foreach ($bl as $b): ?><option value="<?php echo $b['id']; ?>" <?php echo (int)($_POST['branch_id'] ?? $mine) === (int)$b['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['company_name'] . ' › ' . $b['name']); ?></option><?php endforeach; ?>
                </select>
                <small>Where the opening stock is kept.</small>
            </div>
            <?php else: ?><input type="hidden" name="branch_id" value="<?php echo (int)($bl[0]['id'] ?? 0); ?>"><?php endif; ?>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="supplier">Supplier</label>
                <input type="text" id="supplier" name="supplier" maxlength="200" placeholder="Optional"
                       value="<?php echo htmlspecialchars($_POST['supplier'] ?? ''); ?>">
            </div>
        </div>
        <?php endif; ?>

        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?php echo $is_new ? 'Add item' : 'Save changes'; ?></button>
            <a href="<?php echo $is_new ? 'stock' : 'stock_item?id=' . $item['id']; ?>" class="btn">Cancel</a>
        </div>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
