<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/requisition_flow.php';
requirePermission('stock.receive');

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
    // Only requisitions that have been paid for can be received
    $ps = $pdo->prepare("SELECT p.* FROM procurement p WHERE p.id = ? AND p.status = 'paid' AND " . req_visible_sql($pdo));
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
function branch_field(array $branches, int $branch_id, string $id = 'branch_id'): string {
    if (count($branches) <= 1) return '<input type="hidden" name="branch_id" value="' . $branch_id . '">';
    $h = '<div class="form-group"><label for="' . $id . '">Receiving at branch *</label><select id="' . $id . '" name="branch_id" required>';
    foreach ($branches as $b) {
        $h .= '<option value="' . $b['id'] . '"' . ((int)$b['id'] === $branch_id ? ' selected' : '') . '>' . htmlspecialchars($b['company_name'] . ' › ' . $b['name']) . '</option>';
    }
    return $h . '</select></div>';
}

/** Mark the requisition as received once its stock is in. */
function complete_purchase(PDO $pdo, ?array $purchase, int $item_id): void {
    if (!$purchase) return;
    $st = $pdo->prepare("UPDATE procurement SET status='received', received_at=NOW(), stage_at=NOW(), last_note=NULL, item_id=? WHERE id=? AND status='paid'");
    $st->execute([$item_id, $purchase['id']]);
    if ($st->rowCount()) req_log($pdo, (int)$purchase['id'], 'received', 'paid', 'received');
    logAudit($pdo, $_SESSION['user_id'], 'REQUISITION_RECEIVED', $item_id, $purchase['quantity'], "Requisition received: {$purchase['item_name']}");
}

// Every item, grouped by category, for the Receive Existing Item form
$items = $pdo->query("SELECT i.id, i.item_name, i.category_id, COALESCE(c.name, i.category) AS category_name, i.unit_price, i.reorder_level
                      FROM items i LEFT JOIN categories c ON c.id = i.category_id ORDER BY category_name, i.item_name")->fetchAll();
$last_supplier = [];
foreach ($pdo->query("SELECT r.item_id, r.supplier FROM stock_receiving r
                      JOIN (SELECT item_id, MAX(id) AS id FROM stock_receiving
                            WHERE supplier IS NOT NULL AND supplier <> '' AND supplier NOT IN ('Opening stock', 'Initial Stock') GROUP BY item_id) m ON m.id = r.id") as $r)
    $last_supplier[$r['item_id']] = $r['supplier'];
$stock_by_branch = [];
foreach ($pdo->query("SELECT item_id, branch_id, quantity FROM branch_stock") as $r) $stock_by_branch[$r['item_id']][$r['branch_id']] = (int)$r['quantity'];
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
            header("Location: receive_stock?category_id=$category_id&item_id=$item_id&branch_id=$branch_id&added=1" . ($purchase ? '&purchase_done=1' : ''));
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
    $unit_price    = $v->money('unit_price', 'Unit price');
    $reorder_level = $v->int('reorder_level', 'Reorder level', 0, 1000000);
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
            ->execute([$item_id, $supplier, $quantity, $unit_price, $date_received, $_SESSION['user_id'], $branch_id, $old_stock, $new_stock]);
        // The delivery sets the item's current price and reorder level
        $was = $pdo->prepare("SELECT unit_price, reorder_level FROM items WHERE id = ?"); $was->execute([$item_id]); $was = $was->fetch();
        $pdo->prepare("UPDATE items SET unit_price = ?, reorder_level = ? WHERE id = ?")->execute([$unit_price, $reorder_level, $item_id]);
        $changes = [];
        if ((float)$was['unit_price'] !== (float)$unit_price) $changes[] = 'price K' . number_format((float)$was['unit_price'], 2) . ' -> K' . number_format($unit_price, 2);
        if ((int)$was['reorder_level'] !== $reorder_level) $changes[] = "reorder level {$was['reorder_level']} -> $reorder_level";
        logAudit($pdo, $_SESSION['user_id'], 'RECEIVE_STOCK', $item_id, $quantity,
                 "Received $quantity of {$item_data['item_name']} at {$branch['name']}. Old: $old_stock, New: $new_stock. Supplier: $supplier" . ($changes ? '. Updated ' . implode(', ', $changes) : ''));
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

$pr = period_from_request('month');   // Recent Receiving covers the chosen period (30 days by default)
$recent = $pdo->prepare("
    SELECT r.*, i.item_name, c.name as category_name, u.full_name as received_by_name
    FROM stock_receiving r
    JOIN items i ON r.item_id = i.id
    LEFT JOIN categories c ON i.category_id = c.id
    JOIN users u ON r.received_by = u.id
    WHERE (? = 0 OR i.category_id = ?) AND DATE(r.created_at) BETWEEN ? AND ?
    ORDER BY r.created_at DESC LIMIT 1000
");
$recent->execute([$selected_cat_id, $selected_cat_id, $pr['from'], $pr['to']]);
$recent = $recent->fetchAll();

if (wants_xlsx()) {
    $cat_label = '';
    foreach ($categories as $c) if ((int)$c['id'] === (int)$selected_cat_id) $cat_label = $c['name'];
    xlsx_download('Recent receiving ' . date('Y-m-d') . ($cat_label ? " $cat_label" : ''), [[
        'name'    => 'Recent receiving',
        'title'   => 'Recent receiving' . ($cat_label ? " - $cat_label" : ''),
        'meta'    => ['Period: ' . $pr['label'] . ' (' . $pr['range'] . ')', count($recent) . ' deliveries', 'Exported: ' . date('d M Y, H:i')],
        'columns' => ['Date', 'Item', 'Category', ['Qty', 'int'], ['Previous stock', 'int'], ['New stock', 'int'], 'Supplier', 'Received by'],
        'rows'    => array_map(fn($t) => [date('d M Y, H:i', strtotime($t['created_at'])), $t['item_name'], $t['category_name'] ?? 'Other', $t['quantity'],
                                          $t['old_stock'], $t['new_stock'], $t['supplier'] ?? '', $t['received_by_name'] ?? ''], $recent),
        'totals'  => ['Total', '', '', array_sum(array_column($recent, 'quantity'))],
    ]]);
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Receive Stock</h1>
    <a href="dashboard" class="btn btn-sm">Back to Dashboard</a>
</div>


<?php if ($purchase): ?>
<div class="info-note">Receiving <a href="requisition?id=<?php echo $purchase['id']; ?>"><?php echo req_ref((int)$purchase['id']); ?></a>: <strong><?php echo htmlspecialchars($purchase['quantity'] . ' × ' . $purchase['item_name']); ?></strong><?php echo $purchase['supplier'] ? ' from ' . htmlspecialchars($purchase['supplier']) : ''; ?>. Check the quantity that actually arrived.</div>
<?php endif; ?>
<?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
<?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<?php $new_err = isset($error) && isset($_POST['add_new_item']); $ex_err = isset($error) && isset($_POST['receive_existing']);
      $ex_item = $ex_err ? (int)($_POST['item_id'] ?? 0) : (int)$selected_item_id; ?>
<div class="receive-forms">
<!-- Receive New Item -->
<div class="form-container receive-card receive-new">
    <div class="form-title">Receive New Item</div>
    <p class="form-hint">For something that isn't in stock yet.</p>
    <form method="POST"
          data-confirm-title="Receive new item"
          data-confirm="Add {item_name} to {category_id} and receive {quantity} from {supplier}?"
          data-confirm-ok="Receive new item">
        <input type="hidden" name="add_new_item" value="1">
        <?php echo branch_field($branches, $branch_id, 'new_branch_id'); ?>
        <?php if ($purchase && $show_new_item): ?><input type="hidden" name="purchase_id" value="<?php echo $purchase['id']; ?>"><?php endif; ?>
        <div class="form-group">
            <label for="new_category_id">Category *</label>
            <select name="category_id" id="new_category_id" required>
                <option value="">— Select category —</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?php echo $cat['id']; ?>" <?php echo ($selected_cat_id == $cat['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($cat['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="new_item_name">Item Name *</label>
            <input type="text" name="item_name" id="new_item_name" required minlength="2" maxlength="200" placeholder="e.g., A4 Paper Ream"
                   value="<?php echo htmlspecialchars($new_err ? ($_POST['item_name'] ?? '') : ($show_new_item ? $purchase['item_name'] ?? '' : '')); ?>">
        </div>
        <div class="form-group">
            <label>Description</label>
            <textarea name="description" rows="2" maxlength="500" placeholder="Brief description (optional)"><?php echo $new_err ? htmlspecialchars($_POST['description'] ?? '') : ''; ?></textarea>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Supplier *</label>
                <input type="text" name="supplier" required minlength="2" maxlength="200" placeholder="Supplier name"
                       value="<?php echo htmlspecialchars($new_err ? ($_POST['supplier'] ?? '') : ($show_new_item ? $purchase['supplier'] ?? '' : '')); ?>">
            </div>
            <div class="form-group">
                <label>Initial Quantity *</label>
                <input type="number" name="quantity" min="1" max="1000000" step="1" required placeholder="0"
                       value="<?php echo htmlspecialchars($new_err ? ($_POST['quantity'] ?? '') : ($show_new_item ? $purchase['quantity'] ?? '' : '')); ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Reorder Level</label>
                <input type="number" name="reorder_level" min="0" max="1000000" step="1" value="<?php echo htmlspecialchars($new_err ? ($_POST['reorder_level'] ?? '5') : '5'); ?>" required>
                <small>Alert threshold for low stock</small>
            </div>
            <div class="form-group">
                <label>Date Received *</label>
                <input type="date" name="date_received" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-success">Receive New Item</button>
        </div>
    </form>
</div>


<!-- Receive Existing Item -->
<div class="form-container receive-card receive-existing">
    <div class="form-title">Receive Existing Item</div>
    <p class="form-hint">For something already in stock.</p>
    <form method="POST" id="receiveExisting"
          data-confirm-title="Receive stock"
          data-confirm="Record {quantity} &times; {item_id} received from {supplier}? This will be added to stock."
          data-confirm-ok="Receive stock">
        <input type="hidden" name="receive_existing" value="1">
        <?php echo branch_field($branches, $branch_id, 'ex_branch_id'); ?>
        <?php if ($purchase && !$show_new_item): ?><input type="hidden" name="purchase_id" value="<?php echo $purchase['id']; ?>"><?php endif; ?>
        <div class="form-group">
            <label for="ex_item_id">Item *</label>
            <select name="item_id" id="ex_item_id" required>
                <option value="">— Select an item —</option>
                <?php $grp = null; foreach ($items as $it):
                    if ($it['category_name'] !== $grp): if ($grp !== null) echo '</optgroup>'; $grp = $it['category_name']; ?>
                <optgroup label="<?php echo htmlspecialchars($grp ?? 'Uncategorised'); ?>">
                <?php endif; ?>
                    <option value="<?php echo $it['id']; ?>" data-price="<?php echo htmlspecialchars(number_format((float)$it['unit_price'], 2, '.', '')); ?>" data-reorder="<?php echo (int)$it['reorder_level']; ?>" <?php echo $ex_item === (int)$it['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($it['item_name']); ?></option>
                <?php endforeach; if ($grp !== null) echo '</optgroup>'; ?>
            </select>
            <?php if (!$items): ?><small>No items yet. Add one with the form on the left.</small><?php endif; ?>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="ex_supplier">Supplier *</label>
                <input type="text" id="ex_supplier" name="supplier" required minlength="2" maxlength="200" placeholder="Supplier name"
                       value="<?php echo htmlspecialchars($ex_err ? ($_POST['supplier'] ?? '') : (!$show_new_item ? ($purchase['supplier'] ?? '') : '')); ?>">
            </div>
            <div class="form-group">
                <label for="ex_available">Available in stock</label>
                <input type="text" id="ex_available" class="readonly-field" readonly tabindex="-1" value="—">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="qty">Quantity Received *</label>
                <input type="number" name="quantity" id="qty" min="1" max="1000000" step="1" required placeholder="0"
                       value="<?php echo htmlspecialchars($ex_err ? ($_POST['quantity'] ?? '') : (!$show_new_item ? ($purchase['quantity'] ?? '') : '')); ?>">
            </div>
            <div class="form-group">
                <label for="ex_price">Unit price (K) *</label>
                <input type="number" id="ex_price" name="unit_price" step="0.01" min="0" max="100000000" required placeholder="0.00"
                       value="<?php echo htmlspecialchars($ex_err ? ($_POST['unit_price'] ?? '') : ''); ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="ex_reorder">Reorder level *</label>
                <input type="number" id="ex_reorder" name="reorder_level" step="1" min="0" max="1000000" required
                       value="<?php echo htmlspecialchars($ex_err ? ($_POST['reorder_level'] ?? '') : ''); ?>">
                <small>You'll be warned when stock falls to this level.</small>
            </div>
            <div class="form-group">
                <label for="ex_date">Date Received *</label>
                <input type="date" id="ex_date" name="date_received" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Receive Stock</button>
        </div>
    </form>
</div>
</div>

<!-- Recent Transactions -->
<div class="card" id="recent">
    <div class="card-head"><h3>Recent Receiving</h3><div style="display:flex; align-items:center; gap:12px"><?php echo period_tabs($pr['key'], '#recent'); ?><?php if ($recent) echo xlsx_button(); ?></div></div>
    <div class="pill-tabs pill-tabs-inline">
        <a href="receive_stock?branch_id=<?php echo $branch_id; ?>&period=<?php echo $pr['key']; ?>#recent" class="pill-tab <?php echo $selected_cat_id == 0 ? 'active' : ''; ?>">All</a>
        <?php foreach ($categories as $cat): ?>
        <a href="?category_id=<?php echo $cat['id']; ?>&branch_id=<?php echo $branch_id; ?>&period=<?php echo $pr['key']; ?>#recent" class="pill-tab <?php echo $selected_cat_id == $cat['id'] ? 'active' : ''; ?>">
            <?php echo htmlspecialchars($cat['name']); ?>
        </a>
        <?php endforeach; ?>
    </div>
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
    <div class="empty-state"><p><?php echo $selected_cat_id ? 'Nothing received in this category in this period.' : 'Nothing received in this period.'; ?></p></div>
    <?php endif; ?>
</div>

<script>
(function () {
    var stock = <?php echo json_encode($stock_by_branch, JSON_FORCE_OBJECT); ?>;
    var suppliers = <?php echo json_encode($last_supplier, JSON_FORCE_OBJECT); ?>;
    var form = document.getElementById('receiveExisting');
    if (!form) return;
    var item = form.elements['item_id'], branch = form.elements['branch_id'];
    var supplier = document.getElementById('ex_supplier'), avail = document.getElementById('ex_available');
    // The supplier is filled from the last delivery of this item, until someone types their own
    var autoFilled = supplier.value === '';
    supplier.addEventListener('input', function () { autoFilled = supplier.value === ''; });
    function showStock() {
        avail.value = item.value ? String((stock[item.value] || {})[branch.value] || 0) : '—';
    }
    // Price and reorder level start at the item's current values
    var price = document.getElementById('ex_price'), reorder = document.getElementById('ex_reorder');
    function fillItemValues(force) {
        var o = item.options[item.selectedIndex];
        if (!o || !o.value) return;
        if (force || price.value === '') price.value = o.dataset.price || '';
        if (force || reorder.value === '') reorder.value = o.dataset.reorder || '';
    }
    item.addEventListener('change', function () {
        if (autoFilled) supplier.value = suppliers[item.value] || '';
        fillItemValues(true);
        showStock();
    });
    fillItemValues(false);
    branch.addEventListener('change', showStock);
    if (autoFilled && item.value && suppliers[item.value]) supplier.value = suppliers[item.value];
    showStock();
})();
</script>


<?php include __DIR__ . '/../includes/footer.php'; ?>