<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

$categories        = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$selected_cat_id   = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$selected_item_id  = isset($_GET['item_id'])     ? (int)$_GET['item_id']     : 0;
$show_new_item     = isset($_GET['new_item']);

// Which branch the stock arrives at: chosen, else the requisition's branch, else the user's own branch
$branches   = org_branches($pdo);
$branch_ids = array_map(fn($b) => (int)$b['id'], $branches);
$branch_id  = (int)($_POST['branch_id'] ?? $_GET['branch_id'] ?? 0);

$items         = [];
$selected_item = null;

// Receiving a requisition
$purchase = null;
$pid = (int)($_POST['purchase_id'] ?? $_GET['purchase_id'] ?? 0);
if ($pid > 0) {
    $ps = $pdo->prepare("SELECT * FROM procurement WHERE id = ? AND status IN ('needed','ordered')");
    $ps->execute([$pid]);
    $purchase = $ps->fetch() ?: null;
    if ($purchase && $_SERVER['REQUEST_METHOD'] !== 'POST') {
        $item_id = (int)$purchase['item_id'];
        if (!$item_id) {
            $m = $pdo->prepare("SELECT id FROM items WHERE LOWER(item_name)=LOWER(?) ORDER BY category_id = ? DESC LIMIT 1");
            $m->execute([$purchase['item_name'], (int)$purchase['category_id']]);
            $item_id = (int)$m->fetchColumn();
        }
        if ($item_id) {
            $selected_item_id = $item_id;
            $c = $pdo->prepare("SELECT category_id FROM items WHERE id=?"); $c->execute([$item_id]);
            $selected_cat_id = (int)$c->fetchColumn();
            $show_new_item = false;
        } else {
            $selected_cat_id = (int)$purchase['category_id'];
            $show_new_item = true;
        }
    }
}

if (!in_array($branch_id, $branch_ids, true) && $purchase && in_array((int)$purchase['branch_id'], $branch_ids, true)) $branch_id = (int)$purchase['branch_id'];
if (!in_array($branch_id, $branch_ids, true)) {
    $mine = user_branch_id();
    $branch_id = in_array($mine, $branch_ids, true) ? $mine : ($branch_ids[0] ?? 0);
}
$branch = null;
foreach ($branches as $b) if ((int)$b['id'] === $branch_id) $branch = $b;

/** Branch picker for the receive forms (hidden when there is only one branch). */
function branch_field(array $branches, int $branch_id): string {
    if (count($branches) <= 1) return '<input type="hidden" name="branch_id" value="' . $branch_id . '">';
    $h = '<div class="form-group"><label for="branch_id">Receiving at branch *</label><select id="branch_id" name="branch_id" required>';
    foreach ($branches as $b) {
        $h .= '<option value="' . $b['id'] . '"' . ((int)$b['id'] === $branch_id ? ' selected' : '') . '>' . htmlspecialchars($b['company_name'] . ' › ' . $b['name']) . '</option>';
    }
    return $h . '</select></div>';
}

/** Mark the requisition as received once its stock is in. */
function complete_purchase(PDO $pdo, ?array $purchase, int $item_id): void {
    if (!$purchase) return;
    $pdo->prepare("UPDATE procurement SET status='received', received_at=NOW(), item_id=? WHERE id=? AND status IN ('needed','ordered')")
        ->execute([$item_id, $purchase['id']]);
    logAudit($pdo, $_SESSION['user_id'], 'REQUISITION_RECEIVED', $item_id, $purchase['quantity'], "Requisition received: {$purchase['item_name']}");
}

if ($selected_cat_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM items WHERE category_id = ? ORDER BY item_name");
    $stmt->execute([$selected_cat_id]);
    $items = $stmt->fetchAll();
}
if ($selected_item_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
    $stmt->execute([$selected_item_id]);
    $selected_item = $stmt->fetch();
}

// Add New Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_new_item'])) {
    $v             = new Validator($_POST);
    $item_name     = $v->text('item_name', 'Item name', 200, true, 2);
    $category      = $v->exists($pdo, 'category_id', 'category', 'categories', 'id, name');
    $description   = $v->textarea('description', 'Description', 500);
    $supplier      = $v->text('supplier', 'Supplier', 200, true, 2);
    $quantity      = $v->int('quantity', 'Initial quantity', 1, 1000000);
    $reorder_level = $v->int('reorder_level', 'Reorder level', 0, 1000000);
    $date_received = $v->date('date_received', 'Date received');
    $category_id   = $category ? (int)$category['id'] : 0;
    $cat_name      = $category['name'] ?? '';
    if (!$branch) $v->fail('Please choose the branch receiving the stock.');

    if ($v->ok()) {
        $dup = $pdo->prepare("SELECT id FROM items WHERE category_id = ? AND LOWER(item_name) = LOWER(?)");
        $dup->execute([$category_id, $item_name]);
        if ($dup_id = $dup->fetchColumn()) {
            $v->fail("\"$item_name\" already exists in $cat_name. Use Receive Existing Item instead.");
        }
    }

    if (!$v->ok()) {
        $error = $v->message();
    } else {

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO items (item_name, category_id, category, description, current_quantity, unit_price, reorder_level) VALUES (?,?,?,?,0,?,?)")
                ->execute([$item_name, $category_id, $cat_name, $description, 0.00, $reorder_level]);
            $item_id = (int)$pdo->lastInsertId();
            [$old_b, $new_b] = branch_stock_change($pdo, $item_id, $branch_id, $quantity);
            $pdo->prepare("INSERT INTO stock_receiving (item_id, supplier, quantity, unit_price, date_received, received_by, branch_id, old_stock, new_stock) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$item_id, $supplier, $quantity, 0.00, $date_received, $_SESSION['user_id'], $branch_id, $old_b, $new_b]);
            logAudit($pdo, $_SESSION['user_id'], 'ADD_NEW_ITEM_RECEIVE', $item_id, $quantity,
                     "Added new item: $item_name in category: $cat_name. Initial stock: $quantity from: $supplier");
            $pdo->commit();
            complete_purchase($pdo, $purchase, (int)$item_id);
            header("Location: receive_stock.php?category_id=$category_id&item_id=$item_id&branch_id=$branch_id&added=1" . ($purchase ? '&purchase_done=1' : ''));
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log($e->getMessage());
            $error = 'Could not add the item. Please try again.';
        }
    }
}
if (isset($_GET['added']) && $selected_item) {
    $success = 'Item "' . htmlspecialchars($selected_item['item_name']) . '" added and stock received.'
             . (isset($_GET['purchase_done']) ? ' The requisition is marked as received.' : '');
}

// Receive Existing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['receive_existing'])) {
    $v             = new Validator($_POST);
    $item_data     = $v->exists($pdo, 'item_id', 'item', 'items', 'id, item_name');
    $supplier      = $v->text('supplier', 'Supplier', 200, true, 2);
    $quantity      = $v->int('quantity', 'Quantity received', 1, 1000000);
    $date_received = $v->date('date_received', 'Date received');
    $item_id       = $item_data ? (int)$item_data['id'] : 0;
    if (!$branch) $v->fail('Please choose the branch receiving the stock.');

    if (!$v->ok()) {
        $error = $v->message();
    } else {
    $pdo->beginTransaction();
    try {
        [$old_stock, $new_stock] = branch_stock_change($pdo, $item_id, $branch_id, $quantity);
        $pdo->prepare("INSERT INTO stock_receiving (item_id, supplier, quantity, unit_price, date_received, received_by, branch_id, old_stock, new_stock) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$item_id, $supplier, $quantity, 0.00, $date_received, $_SESSION['user_id'], $branch_id, $old_stock, $new_stock]);
        logAudit($pdo, $_SESSION['user_id'], 'RECEIVE_STOCK', $item_id, $quantity,
                 "Received $quantity of {$item_data['item_name']} at {$branch['name']}. Old: $old_stock, New: $new_stock. Supplier: $supplier");
        $pdo->commit();
        complete_purchase($pdo, $purchase, $item_id);
        $success = "Stock received at " . htmlspecialchars($branch['name']) . ". Previous: $old_stock &rarr; New: $new_stock" . ($purchase ? '. The requisition is marked as received.' : '');
        $purchase = null;
        $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
        $stmt->execute([$item_id]);
        $selected_item = $stmt->fetch();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log($e->getMessage());
        $error = 'Could not record the stock received. Please try again.';
    }
    }
}

$recent = $pdo->prepare("
    SELECT r.*, i.item_name, c.name as category_name, u.full_name as received_by_name
    FROM stock_receiving r
    JOIN items i ON r.item_id = i.id
    LEFT JOIN categories c ON i.category_id = c.id
    JOIN users u ON r.received_by = u.id
    ORDER BY r.created_at DESC LIMIT 20
");
$recent->execute();
$recent = $recent->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Receive Stock</h1>
    <a href="dashboard.php" class="btn btn-sm">Back to Dashboard</a>
</div>

<!-- Category Selector -->
<div class="pill-tabs">
    <a href="receive_stock.php?branch_id=<?php echo $branch_id; ?>" class="pill-tab <?php echo $selected_cat_id == 0 ? 'active' : ''; ?>">All</a>
    <?php foreach ($categories as $cat): ?>
    <a href="?category_id=<?php echo $cat['id']; ?>&branch_id=<?php echo $branch_id; ?>" class="pill-tab <?php echo $selected_cat_id == $cat['id'] ? 'active' : ''; ?>">
        <?php echo htmlspecialchars($cat['name']); ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($purchase): ?>
<div class="info-note">Receiving requisition: <strong><?php echo htmlspecialchars($purchase['quantity'] . ' × ' . $purchase['item_name']); ?></strong><?php echo $purchase['supplier'] ? ' from ' . htmlspecialchars($purchase['supplier']) : ''; ?>. Check the quantity that actually arrived.</div>
<?php endif; ?>
<?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
<?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<!-- Mode toggle -->
<div class="action-group" style="margin-bottom:18px;">
    <a href="receive_stock.php?category_id=<?php echo $selected_cat_id; ?>&branch_id=<?php echo $branch_id; ?>" class="btn <?php echo !$show_new_item ? 'btn-primary' : ''; ?>">Receive Existing Item</a>
    <a href="receive_stock.php?category_id=<?php echo $selected_cat_id; ?>&branch_id=<?php echo $branch_id; ?>&new_item=1" class="btn <?php echo $show_new_item ? 'btn-primary' : ''; ?>">Add New Item</a>
</div>

<?php if ($show_new_item): ?>
<!-- Add New Item -->
<div class="form-container">
    <div class="form-title">Add New Item to Inventory</div>
    <form method="POST"
          data-confirm-title="Add new item"
          data-confirm="Add {item_name} to {category_id} with {quantity} units received from {supplier}?"
          data-confirm-ok="Add item">
        <input type="hidden" name="add_new_item" value="1">
        <?php echo branch_field($branches, $branch_id); ?>
        <?php if ($purchase): ?><input type="hidden" name="purchase_id" value="<?php echo $purchase['id']; ?>"><?php endif; ?>
        <div class="form-group">
            <label>Item Name *</label>
            <input type="text" name="item_name" required minlength="2" maxlength="200" placeholder="e.g., A4 Paper Ream"
                   value="<?php echo htmlspecialchars(isset($error) ? ($_POST['item_name'] ?? '') : ($purchase['item_name'] ?? '')); ?>">
        </div>
        <div class="form-group">
            <label>Category *</label>
            <select name="category_id" required>
                <option value="">— Select category —</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?php echo $cat['id']; ?>" <?php echo ($selected_cat_id == $cat['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($cat['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Description</label>
            <textarea name="description" rows="2" maxlength="500" placeholder="Brief description (optional)"><?php echo isset($error) ? htmlspecialchars($_POST['description'] ?? '') : ''; ?></textarea>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Supplier *</label>
                <input type="text" name="supplier" required minlength="2" maxlength="200" placeholder="Supplier name"
                       value="<?php echo htmlspecialchars(isset($error) ? ($_POST['supplier'] ?? '') : ($purchase['supplier'] ?? '')); ?>">
            </div>
            <div class="form-group">
                <label>Initial Quantity *</label>
                <input type="number" name="quantity" min="1" max="1000000" step="1" required placeholder="0"
                       value="<?php echo htmlspecialchars(isset($error) ? ($_POST['quantity'] ?? '') : ($purchase['quantity'] ?? '')); ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Reorder Level</label>
                <input type="number" name="reorder_level" min="0" max="1000000" step="1" value="10" required>
                <small>Alert threshold for low stock</small>
            </div>
            <div class="form-group">
                <label>Date Received *</label>
                <input type="date" name="date_received" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-success">Add Item &amp; Receive</button>
            <a href="receive_stock.php?category_id=<?php echo $selected_cat_id; ?>&branch_id=<?php echo $branch_id; ?>" class="btn">Cancel</a>
        </div>
    </form>
</div>

<?php else: ?>
<!-- Select Existing Item -->
<div class="form-container">
    <div class="form-title">Select Item</div>
    <form method="GET">
        <input type="hidden" name="category_id" value="<?php echo $selected_cat_id; ?>">
        <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
        <div class="form-group">
            <label>Choose Item</label>
            <select name="item_id" onchange="this.form.submit()">
                <option value="">— Select an item —</option>
                <?php foreach ($items as $item): ?>
                <option value="<?php echo $item['id']; ?>" <?php echo ($selected_item_id == $item['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($item['item_name']); ?> (Stock: <?php echo $item['current_quantity']; ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <?php if (empty($items) && $selected_cat_id > 0): ?>
            <small style="color:var(--muted)">No items in this category. Use "Add New Item" to get started.</small>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($selected_item): ?>
<div class="form-container">
    <div class="form-title"><?php echo htmlspecialchars($selected_item['item_name']); ?></div>

    <div class="stock-info-box">
        <div class="stock-info-item">
            <div class="label">Stock at <?php echo htmlspecialchars($branch['name'] ?? 'branch'); ?></div>
            <div class="value"><?php echo number_format(branch_qty($pdo, (int)$selected_item['id'], $branch_id)); ?></div>
        </div>
        <div class="stock-info-item">
            <div class="label">All branches</div>
            <div class="value"><?php echo number_format($selected_item['current_quantity']); ?></div>
        </div>
        <div class="stock-info-item">
            <div class="label">Category</div>
            <div class="value" style="font-size:.88rem"><?php echo htmlspecialchars($selected_item['category']); ?></div>
        </div>
        <div class="stock-info-item">
            <div class="label">Reorder Level</div>
            <div class="value"><?php echo $selected_item['reorder_level']; ?></div>
        </div>
    </div>

    <form method="POST"
          data-confirm-title="Receive stock"
          data-confirm="Record {quantity} &times; <?php echo htmlspecialchars($selected_item['item_name']); ?> received from {supplier}? This will be added to stock."
          data-confirm-ok="Receive stock">
        <input type="hidden" name="receive_existing" value="1">
        <?php echo branch_field($branches, $branch_id); ?>
        <?php if ($purchase): ?><input type="hidden" name="purchase_id" value="<?php echo $purchase['id']; ?>"><?php endif; ?>
        <input type="hidden" name="item_id" value="<?php echo $selected_item['id']; ?>">
        <div class="form-group">
            <label>Supplier *</label>
            <input type="text" name="supplier" required minlength="2" maxlength="200" placeholder="Supplier name"
                   value="<?php echo htmlspecialchars($purchase['supplier'] ?? ''); ?>">
        </div>
        <div class="form-group">
            <label>Quantity Received *</label>
            <input type="number" name="quantity" id="qty" min="1" max="1000000" step="1" required placeholder="0" oninput="updateNewStock()"
                   value="<?php echo htmlspecialchars($purchase['quantity'] ?? ''); ?>">
            <small id="newStockMsg"></small>
        </div>
        <div class="form-group">
            <label>Date Received *</label>
            <input type="date" name="date_received" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-success">Receive Stock</button>
            <a href="receive_stock.php?category_id=<?php echo $selected_cat_id; ?>&branch_id=<?php echo $branch_id; ?>" class="btn">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Recent Transactions -->
<div class="card">
    <h3 style="margin-bottom:12px">Recent Receiving</h3>
    <?php if (count($recent) > 0): ?>
    <div class="table-container">
        <table>
            <thead>
                <tr><th>Date</th><th>Item</th><th>Category</th><th>Qty</th><th>Prev Stock</th><th>New Stock</th><th>Supplier</th><th>Received By</th></tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $t): ?>
                <tr>
                    <td style="white-space:nowrap; color:var(--muted); font-size:.76rem"><?php echo date('d/m/Y H:i', strtotime($t['created_at'])); ?></td>
                    <td style="font-weight:500"><?php echo htmlspecialchars($t['item_name']); ?></td>
                    <td><span class="cat-pill"><?php echo htmlspecialchars($t['category_name'] ?? 'Other'); ?></span></td>
                    <td><strong><?php echo $t['quantity']; ?></strong></td>
                    <td style="color:var(--muted)"><?php echo $t['old_stock'] ?? '—'; ?></td>
                    <td style="color:var(--success); font-weight:500"><?php echo $t['new_stock'] ?? '—'; ?></td>
                    <td style="color:var(--muted)"><?php echo htmlspecialchars($t['supplier'] ?: '—'); ?></td>
                    <td style="color:var(--muted)"><?php echo htmlspecialchars($t['received_by_name']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><p>No receiving transactions yet.</p></div>
    <?php endif; ?>
</div>

<script>
function updateNewStock() {
    var current = <?php echo $selected_item ? branch_qty($pdo, (int)$selected_item['id'], $branch_id) : 0; ?>;
    var qty = parseInt(document.getElementById('qty').value) || 0;
    var msg = document.getElementById('newStockMsg');
    if (qty > 0) {
        msg.innerHTML = 'After receiving, new stock: <strong>' + (current + qty) + '</strong>';
        msg.style.color = 'var(--success)';
    } else {
        msg.innerHTML = '';
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>