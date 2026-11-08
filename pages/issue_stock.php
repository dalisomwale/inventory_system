<?php
// Issue Stock — give items from a branch to a department.
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

$categories  = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$branches    = org_branches($pdo);
$departments = org_departments($pdo);
$branch_ids  = array_map(fn($b) => (int)$b['id'], $branches);

$selected_cat_id  = (int)($_GET['category_id'] ?? 0);
$selected_item_id = (int)($_POST['item_id'] ?? $_GET['item_id'] ?? 0);
$branch_id        = (int)($_POST['branch_id'] ?? $_GET['branch_id'] ?? 0);
if (!in_array($branch_id, $branch_ids, true)) {
    $mine = user_branch_id();
    $branch_id = in_array($mine, $branch_ids, true) ? $mine : ($branch_ids[0] ?? 0);
}
$branch = null;
foreach ($branches as $b) if ((int)$b['id'] === $branch_id) $branch = $b;

/** Items with stock at the branch (optionally in one category). */
function items_at_branch(PDO $pdo, int $branch_id, int $cat_id): array {
    $sql = "SELECT i.id, i.item_name, i.category, i.reorder_level, bs.quantity AS available
            FROM items i JOIN branch_stock bs ON bs.item_id = i.id AND bs.branch_id = ?
            WHERE bs.quantity > 0" . ($cat_id ? " AND i.category_id = ?" : "") . " ORDER BY i.item_name";
    $st = $pdo->prepare($sql);
    $st->execute($cat_id ? [$branch_id, $cat_id] : [$branch_id]);
    return $st->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v           = new Validator($_POST);
    $item        = $v->exists($pdo, 'item_id', 'item', 'items', 'id, item_name');
    $quantity    = $v->int('quantity', 'Quantity to issue', 1, 1000000);
    $recipient   = $v->text('recipient', 'Person receiving', 150, false);
    $purpose     = $v->textarea('purpose', 'Purpose', 255);
    $date_issued = $v->date('date_issued', 'Date issued');
    if (!$branch) $v->fail('Please choose the branch the stock is issued from.');

    $dept = null; $issued_to = '';
    if ($departments) {
        $did = (int)($_POST['department_id'] ?? 0);
        foreach ($departments as $d) if ((int)$d['id'] === $did) $dept = $d;
        if (!$dept) $v->fail('Please choose the department receiving the stock.');
        else $issued_to = dept_label($dept);
    } else {
        // No departments set up yet: fall back to free text
        $issued_to = $v->text('issued_to', 'Issued to', 150, true, 2);
    }

    if ($v->ok()) {
        $available = branch_qty($pdo, (int)$item['id'], $branch_id);
        if ($quantity > $available) $v->fail("Only $available in stock at {$branch['name']}. You cannot issue $quantity.");
    }

    if (!$v->ok()) {
        $error = $v->message();
    } else {
        $pdo->beginTransaction();
        try {
            [$old_stock, $new_stock] = branch_stock_change($pdo, (int)$item['id'], $branch_id, -$quantity);
            $pdo->prepare("INSERT INTO stock_issuing (item_id, quantity, issued_to, recipient, purpose, date_issued, issued_by, branch_id, department_id, old_stock, new_stock)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$item['id'], $quantity, $issued_to, $recipient ?: null, $purpose, $date_issued, $_SESSION['user_id'],
                           $branch_id, $dept['id'] ?? null, $old_stock, $new_stock]);
            logAudit($pdo, $_SESSION['user_id'], 'ISSUE_STOCK', (int)$item['id'], $quantity,
                     "Issued $quantity of {$item['item_name']} at {$branch['name']} to: $issued_to" . ($recipient ? " ($recipient)" : '') . ". Old: $old_stock, New: $new_stock.");
            $pdo->commit();
            $success = "Issued " . number_format($quantity) . " &times; " . htmlspecialchars($item['item_name']) . " to " . htmlspecialchars($issued_to)
                     . ". " . htmlspecialchars($branch['name']) . " stock: $old_stock &rarr; $new_stock";
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $error = htmlspecialchars($e->getMessage());
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log($e->getMessage());
            $error = 'Could not issue stock. Please try again.';
        }
    }
}

$items = $branch ? items_at_branch($pdo, $branch_id, $selected_cat_id) : [];
$selected_item = null;
if ($selected_item_id) {
    $st = $pdo->prepare("SELECT i.*, COALESCE(bs.quantity, 0) AS available FROM items i
                         LEFT JOIN branch_stock bs ON bs.item_id = i.id AND bs.branch_id = ? WHERE i.id = ?");
    $st->execute([$branch_id, $selected_item_id]);
    $selected_item = $st->fetch() ?: null;
}

$recent = $pdo->query("
    SELECT s.*, it.item_name, c.name AS category_name, u.full_name AS issued_by_name, b.name AS branch_name
    FROM stock_issuing s
    JOIN items it ON s.item_id = it.id
    LEFT JOIN categories c ON it.category_id = c.id
    LEFT JOIN users u ON s.issued_by = u.id
    LEFT JOIN branches b ON b.id = s.branch_id
    ORDER BY s.created_at DESC LIMIT 20")->fetchAll();

$qs = fn(array $over = []) => '?' . http_build_query(array_filter(array_merge(['branch_id' => $branch_id, 'category_id' => $selected_cat_id], $over)));

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Issue Stock</h1>
</div>

<?php if (!$branches): ?>
<div class="alert alert-danger">No branches are set up yet. Ask the System Admin to add one under Organisation.</div>
<?php else: ?>

<?php if (count($branches) > 1): ?>
<form method="GET" class="card branch-bar">
    <label for="branch_pick">Issuing from branch</label>
    <select id="branch_pick" name="branch_id" onchange="this.form.submit()">
        <?php foreach ($branches as $b): ?>
        <option value="<?php echo $b['id']; ?>" <?php echo (int)$b['id'] === $branch_id ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['company_name'] . ' › ' . $b['name']); ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($selected_cat_id): ?><input type="hidden" name="category_id" value="<?php echo $selected_cat_id; ?>"><?php endif; ?>
</form>
<?php endif; ?>

<!-- Category Selector -->
<div class="pill-tabs">
    <a href="<?php echo htmlspecialchars($qs(['category_id' => 0])); ?>" class="pill-tab <?php echo $selected_cat_id == 0 ? 'active' : ''; ?>">All</a>
    <?php foreach ($categories as $cat): ?>
    <a href="<?php echo htmlspecialchars($qs(['category_id' => $cat['id']])); ?>" class="pill-tab <?php echo $selected_cat_id == $cat['id'] ? 'active' : ''; ?>">
        <?php echo htmlspecialchars($cat['name']); ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
<?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<!-- Item Selection -->
<div class="form-container">
    <div class="form-title">Select item</div>
    <form method="GET">
        <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
        <input type="hidden" name="category_id" value="<?php echo $selected_cat_id; ?>">
        <div class="form-group">
            <label for="item_pick">Item in stock at <?php echo htmlspecialchars($branch['name']); ?></label>
            <select id="item_pick" name="item_id" onchange="this.form.submit()">
                <option value="">— Select an item —</option>
                <?php foreach ($items as $it): ?>
                <option value="<?php echo $it['id']; ?>" <?php echo $selected_item_id == $it['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($it['item_name']); ?> (Available: <?php echo number_format($it['available']); ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <?php if (!$items): ?><small style="color:var(--muted)">Nothing in stock at this branch<?php echo $selected_cat_id ? ' in this category' : ''; ?>.</small><?php endif; ?>
        </div>
    </form>
</div>

<?php if ($selected_item): ?>
<div class="form-container">
    <div class="form-title"><?php echo htmlspecialchars($selected_item['item_name']); ?></div>

    <div class="stock-info-box">
        <div class="stock-info-item"><div class="label">Available at <?php echo htmlspecialchars($branch['name']); ?></div><div class="value"><?php echo number_format($selected_item['available']); ?></div></div>
        <div class="stock-info-item"><div class="label">Category</div><div class="value" style="font-size:.88rem"><?php echo htmlspecialchars($selected_item['category']); ?></div></div>
        <div class="stock-info-item"><div class="label">Reorder level</div><div class="value"><?php echo number_format($selected_item['reorder_level']); ?></div></div>
    </div>

    <?php if ($selected_item['available'] <= 0): ?>
    <div class="info-note">There is no <?php echo htmlspecialchars($selected_item['item_name']); ?> in stock at <?php echo htmlspecialchars($branch['name']); ?>.</div>
    <?php else: ?>
    <form method="POST"
          data-confirm-title="Issue stock"
          data-confirm="Issue {quantity} &times; <?php echo htmlspecialchars($selected_item['item_name']); ?> from <?php echo htmlspecialchars($branch['name']); ?> to <?php echo $departments ? '{department_id}' : '{issued_to}'; ?>? This will be deducted from stock."
          data-confirm-ok="Issue stock">
        <input type="hidden" name="item_id" value="<?php echo $selected_item['id']; ?>">
        <input type="hidden" name="branch_id" value="<?php echo $branch_id; ?>">
        <div class="form-row">
            <div class="form-group">
                <label for="qty">Quantity to issue *</label>
                <input type="number" name="quantity" id="qty" min="1" max="<?php echo (int)$selected_item['available']; ?>" step="1" required
                       placeholder="0" oninput="updateNewStock()" value="<?php echo isset($error) ? (int)($_POST['quantity'] ?? 0) ?: '' : ''; ?>">
                <small id="newStockMsg"></small>
            </div>
            <div class="form-group">
                <?php if ($departments): ?>
                <label for="department_id">Department *</label>
                <select id="department_id" name="department_id" required>
                    <option value="">— Select department —</option>
                    <?php
                    $sel = (int)($_POST['department_id'] ?? 0);
                    $groups = [];
                    foreach ($departments as $d) $groups[$d['company_name'] . ' › ' . $d['branch_name']][] = $d;
                    // This branch's departments first
                    uksort($groups, fn($a, $b) => (str_ends_with($b, ' › ' . $branch['name']) <=> str_ends_with($a, ' › ' . $branch['name'])) ?: strcmp($a, $b));
                    foreach ($groups as $g => $ds): ?>
                    <optgroup label="<?php echo htmlspecialchars($g); ?>">
                        <?php foreach ($ds as $d): ?>
                        <option value="<?php echo $d['id']; ?>" <?php echo $sel === (int)$d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(dept_label($d)); ?></option>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <label for="issued_to">Issued to *</label>
                <input type="text" id="issued_to" name="issued_to" required minlength="2" maxlength="150" placeholder="Department or person"
                       value="<?php echo isset($error) ? htmlspecialchars($_POST['issued_to'] ?? '') : ''; ?>">
                <small>Departments haven't been set up yet. The System Admin can add them under Organisation.</small>
                <?php endif; ?>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="recipient">Person receiving</label>
                <input type="text" id="recipient" name="recipient" maxlength="150" placeholder="Optional, e.g. Mary Phiri"
                       value="<?php echo isset($error) ? htmlspecialchars($_POST['recipient'] ?? '') : ''; ?>">
            </div>
            <div class="form-group">
                <label for="date_issued">Date issued *</label>
                <input type="date" id="date_issued" name="date_issued" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
            </div>
        </div>
        <div class="form-group">
            <label for="purpose">Purpose</label>
            <textarea id="purpose" name="purpose" rows="2" maxlength="255" placeholder="Reason for issue (optional)"><?php echo isset($error) ? htmlspecialchars($_POST['purpose'] ?? '') : ''; ?></textarea>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-danger">Issue Stock</button>
            <a href="<?php echo htmlspecialchars($qs()); ?>" class="btn">Cancel</a>
        </div>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- Recent -->
<div class="card">
    <h3 style="margin-bottom:12px">Recent issuing</h3>
    <?php if ($recent): ?>
    <div class="table-container">
        <table>
            <thead><tr><th>Date</th><th>Item</th><th>Branch</th><th class="num">Qty</th><th class="num">Branch stock</th><th>Issued to</th><th>Purpose</th><th>By</th></tr></thead>
            <tbody>
            <?php foreach ($recent as $t): ?>
                <tr>
                    <td style="white-space:nowrap; color:var(--muted); font-size:.76rem"><?php echo date('d/m/Y H:i', strtotime($t['created_at'])); ?></td>
                    <td style="font-weight:500"><?php echo htmlspecialchars($t['item_name']); ?></td>
                    <td class="muted"><?php echo htmlspecialchars($t['branch_name'] ?? '—'); ?></td>
                    <td class="num"><strong><?php echo number_format($t['quantity']); ?></strong></td>
                    <td class="num muted"><?php echo $t['old_stock'] !== null ? $t['old_stock'] . ' &rarr; ' . $t['new_stock'] : '—'; ?></td>
                    <td><?php echo htmlspecialchars($t['issued_to'] ?: '—'); ?><?php if (!empty($t['recipient'])): ?><div class="item-desc"><?php echo htmlspecialchars($t['recipient']); ?></div><?php endif; ?></td>
                    <td style="color:var(--muted); font-size:.76rem; max-width:160px; white-space:normal"><?php echo htmlspecialchars($t['purpose'] ?: '—'); ?></td>
                    <td class="muted"><?php echo htmlspecialchars($t['issued_by_name'] ?? '—'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><p>No issuing transactions yet.</p></div>
    <?php endif; ?>
</div>

<script>
function updateNewStock() {
    var current = <?php echo (int)($selected_item['available'] ?? 0); ?>;
    var qty = parseInt(document.getElementById('qty').value) || 0;
    var msg = document.getElementById('newStockMsg');
    if (qty > current)  { msg.innerHTML = 'Cannot issue more than is available at this branch.'; msg.style.color = 'var(--danger)'; }
    else if (qty > 0)   { msg.innerHTML = 'After issue, branch stock: <strong>' + (current - qty) + '</strong>'; msg.style.color = 'var(--muted)'; }
    else                { msg.innerHTML = ''; }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
