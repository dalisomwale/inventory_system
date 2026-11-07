<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();
if (!isManager()) { header('Location: dashboard.php'); exit(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_id      = (int)$_POST['item_id'];
    $new_quantity = (int)$_POST['new_quantity'];
    $reason       = trim($_POST['reason']);

    $row = $pdo->prepare("SELECT current_quantity, item_name FROM items WHERE id=?");
    $row->execute([$item_id]); $item = $row->fetch();
    $old_qty = $item['current_quantity'];
    $diff    = $new_quantity - $old_qty;

    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE items SET current_quantity=? WHERE id=?")->execute([$new_quantity, $item_id]);
        logAudit($pdo, $_SESSION['user_id'], 'ADJUST_STOCK', $item_id, $diff,
                 "Adjusted from $old_qty to $new_quantity. Reason: $reason");
        $pdo->commit();
        $success = 'Stock adjusted successfully.';
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Error: ' . $e->getMessage();
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

    <form method="POST">
        <div class="form-group">
            <label>Select Item</label>
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
                <label>New Quantity</label>
                <input type="number" name="new_quantity" id="new_qty" min="0" required oninput="showDiff()">
                <small id="diffNote"></small>
            </div>
        </div>

        <div class="form-group">
            <label>Reason for Adjustment</label>
            <textarea name="reason" required rows="3" placeholder="Explain the reason for this adjustment"></textarea>
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