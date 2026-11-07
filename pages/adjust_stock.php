<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();
if (!isManager()) { header('Location: dashboard.php'); exit(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v            = new Validator($_POST);
    $item         = $v->exists($pdo, 'item_id', 'item', 'items', 'id, item_name, current_quantity');
    $new_quantity = $v->int('new_quantity', 'New quantity', 0, 1000000);
    $reason       = $v->textarea('reason', 'Reason for adjustment', 500, true, 5);
    $item_id      = $item ? (int)$item['id'] : 0;

    if ($v->ok() && $new_quantity === (int)$item['current_quantity']) {
        $v->fail('The new quantity is the same as the current quantity. Nothing to adjust.');
    }

    if (!$v->ok()) {
        $error = $v->message();
    } else {
    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare("SELECT current_quantity FROM items WHERE id=? FOR UPDATE");
        $lock->execute([$item_id]);
        $old_qty = (int)$lock->fetchColumn();
        $diff    = $new_quantity - $old_qty;
        $pdo->prepare("UPDATE items SET current_quantity=? WHERE id=?")->execute([$new_quantity, $item_id]);
        logAudit($pdo, $_SESSION['user_id'], 'ADJUST_STOCK', $item_id, $diff,
                 "Adjusted from $old_qty to $new_quantity. Reason: $reason");
        $pdo->commit();
        $success = 'Stock for ' . htmlspecialchars($item['item_name']) . " adjusted from $old_qty to $new_quantity.";
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log($e->getMessage());
        $error = 'Could not adjust stock. Please try again.';
    }
    }
}

$all_items = $pdo->query("SELECT * FROM items ORDER BY category, item_name")->fetchAll();
$grouped   = [];
foreach ($all_items as $it) { $grouped[$it['category']?:'General'][] = $it; }

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Adjust Stock</h1>
    <a href="manager_dashboard.php" class="btn btn-sm">Back to Dashboard</a>
</div>

<div class="form-container">
    <?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
    <?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

    <form method="POST"
          data-confirm-title="Adjust stock"
          data-confirm="Change the stock of {item_id} to {new_quantity}? This overrides the current count and is recorded in the audit trail."
          data-confirm-ok="Adjust stock"
          data-confirm-danger="1">
        <div class="form-group">
            <label>Select Item *</label>
            <select name="item_id" id="itemSelect" required onchange="updateCurrent()">
                <option value="">— Choose item —</option>
                <?php foreach ($grouped as $cat => $items): ?>
                <optgroup label="<?php echo htmlspecialchars($cat); ?>">
                    <?php foreach ($items as $item): ?>
                    <option value="<?php echo $item['id']; ?>" data-current="<?php echo $item['current_quantity']; ?>">
                        <?php echo htmlspecialchars($item['item_name']); ?> (Current: <?php echo $item['current_quantity']; ?>)
                    </option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label>Current Quantity</label>
                <input type="number" id="current_qty" readonly>
            </div>
            <div class="form-group">
                <label>New Quantity *</label>
                <input type="number" name="new_quantity" id="new_qty" min="0" max="1000000" step="1" required oninput="showDiff()">
                <small id="diffNote"></small>
            </div>
        </div>

        <div class="form-group">
            <label>Reason for Adjustment *</label>
            <textarea name="reason" required minlength="5" maxlength="500" rows="3" placeholder="e.g. Stock count on 08/11 found 3 damaged reams"><?php echo isset($error) ? htmlspecialchars($_POST['reason'] ?? '') : ''; ?></textarea>
        </div>

        <div class="form-actions">
            <button type="submit" class="btn btn-warning">Adjust Stock</button>
            <a href="manager_dashboard.php" class="btn">Cancel</a>
        </div>
    </form>
</div>

<script>
function updateCurrent() {
    var sel = document.getElementById('itemSelect');
    var c   = document.getElementById('current_qty');
    var n   = document.getElementById('new_qty');
    var d   = document.getElementById('diffNote');
    if (sel.selectedIndex > 0) {
        var v = sel.options[sel.selectedIndex].getAttribute('data-current');
        c.value = v; n.value = v; d.textContent = 'No change'; d.style.color = '';
    } else {
        c.value = ''; n.value = ''; d.textContent = '';
    }
}
function showDiff() {
    var c = document.getElementById('current_qty').value;
    var n = document.getElementById('new_qty').value;
    var d = document.getElementById('diffNote');
    if (c === '' || n === '') { d.textContent = ''; return; }
    var diff = Number(n) - Number(c);
    if (diff > 0)      { d.textContent = 'Increase by ' + diff;         d.style.color = 'var(--success)'; }
    else if (diff < 0) { d.textContent = 'Decrease by ' + Math.abs(diff); d.style.color = 'var(--danger)';  }
    else               { d.textContent = 'No change';                    d.style.color = 'var(--muted)';   }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>