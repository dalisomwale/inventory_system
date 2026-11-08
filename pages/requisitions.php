<?php
// Requisitions: raise one, and see every requisition you take part in, by stage.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/requisition_flow.php';
redirectIfNotLoggedIn();
if (!req_can_open()) requirePermission('requisitions.raise');

$categories  = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$departments = org_departments($pdo);
$my_dept     = req_my_department($pdo);

if (!empty($_SESSION['flash']))       { $success = htmlspecialchars($_SESSION['flash']);       unset($_SESSION['flash']); }
if (!empty($_SESSION['flash_error'])) { $error   = htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); }

// ── Raise a requisition ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_purchase'])) {
    if (!can('requisitions.raise')) {
        $_SESSION['flash_error'] = "You can't raise requisitions. Ask the System Admin if you need to.";
        header('Location: requisitions.php'); exit();
    }
    $v         = new Validator($_POST);
    $item_name = $v->text('item_name', 'Item', 200, true, 2);
    $category  = $v->exists($pdo, 'category_id', 'category', 'categories', 'id, name');
    $quantity  = $v->int('quantity', 'Quantity', 1, 100000);
    $notes     = $v->textarea('reason', 'Reason', 500, true, 3);
    $is_it     = !empty($_POST['is_it']) ? 1 : 0;
    $dept = null;
    if ($departments) {
        // A department manager raises for their own department
        $want = $my_dept ?: (int)($_POST['department_id'] ?? 0);
        foreach ($departments as $d) if ((int)$d['id'] === $want) $dept = $d;
        if (!$dept) $v->fail('Please choose the department that needs this.');
    }
    $req_branch = $dept ? (int)$dept['branch_id'] : (user_branch_id() ?: null);

    if ($v->ok()) {
        $dup = $pdo->prepare("SELECT COUNT(*) FROM procurement WHERE LOWER(item_name) = LOWER(?) AND (department_id <=> ?) AND status NOT IN ('received','cancelled')");
        $dup->execute([$item_name, $dept['id'] ?? null]);
        if ($dup->fetchColumn() > 0) $v->fail("There is already an open requisition for \"$item_name\"" . ($dept ? " for {$dept['name']}" : '') . '.');
    }
    if (!$v->ok()) {
        $error = $v->message();
    } else {
        $match = $pdo->prepare("SELECT id FROM items WHERE LOWER(item_name) = LOWER(?) AND category_id = ? LIMIT 1");
        $match->execute([$item_name, $category['id']]);
        $item_id = $match->fetchColumn() ?: null;
        $status  = $is_it ? 'quotes_it' : 'quotes_dept';

        $pdo->prepare("INSERT INTO procurement (item_name, item_id, quantity, reason, requested_by, category_id, category, branch_id, department_id, is_it, status, stage_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$item_name, $item_id, $quantity, $notes, $_SESSION['user_id'], $category['id'], $category['name'], $req_branch, $dept['id'] ?? null, $is_it, $status]);
        $id = (int)$pdo->lastInsertId();
        req_log($pdo, $id, 'raised', null, $status, $is_it ? 'IT-related' : null);
        logAudit($pdo, $_SESSION['user_id'], 'REQUISITION_RAISED', $item_id ? (int)$item_id : null, $quantity, req_ref($id) . " raised: $item_name");
        $_SESSION['flash'] = req_ref($id) . ' raised. ' . ($is_it ? 'It is now with IT for quotations.' : 'Attach the quotations below, then send it to the Director.');
        header('Location: requisition.php?id=' . $id); exit();
    }
}

// ── List ────────────────────────────────────────────────────
$visible = req_visible_sql($pdo);
$mine    = req_my_turn_sql($pdo);
$tabs = [
    'mine'      => ['Waiting for me', $mine . " AND p.status NOT IN ('received','cancelled')"],
    'progress'  => ['In progress',    "p.status NOT IN ('paid','received','cancelled')"],
    'paid'      => ['Paid',           "p.status = 'paid'"],
    'received'  => ['Received',       "p.status = 'received'"],
    'cancelled' => ['Cancelled',      "p.status = 'cancelled'"],
    'all'       => ['All',            '1=1'],
];
$counts = [];
foreach ($tabs as $k => [, $cond]) $counts[$k] = (int)$pdo->query("SELECT COUNT(*) FROM procurement p WHERE $visible AND $cond")->fetchColumn();
$tab = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : ($counts['mine'] ? 'mine' : 'progress');

$list = $pdo->query("
    SELECT p.*, u.full_name AS added_by, c.name AS category_name, d.name AS dept_name, b.name AS branch_name,
           (SELECT COUNT(*) FROM requisition_files f WHERE f.requisition_id = p.id AND f.kind = 'quote') AS quotes,
           ($mine) AS my_turn
    FROM procurement p
    LEFT JOIN users u  ON u.id  = p.requested_by
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN departments d ON d.id = p.department_id
    LEFT JOIN branches b ON b.id = p.branch_id
    WHERE $visible AND {$tabs[$tab][1]}
    ORDER BY FIELD(p.status,'returned','quotes_it','quotes_dept','director','accounts','accounts_manager','cfo','pay','paid','received','cancelled'), p.created_at DESC")->fetchAll();

$items_for_list = $pdo->query("SELECT item_name FROM items ORDER BY item_name")->fetchAll(PDO::FETCH_COLUMN);
$pre_name = is_string($_GET['item_name'] ?? null) ? mb_substr($_GET['item_name'], 0, 200) : '';
$pre_cat  = (int)($_GET['category_id'] ?? 0);
$pre_qty  = max(1, min(100000, (int)($_GET['qty'] ?? 1)));
$my_dept_row = null;
foreach ($departments as $d) if ((int)$d['id'] === $my_dept) $my_dept_row = $d;

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Requisitions</h1>
</div>

<?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
<?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<?php if (can('requisitions.raise')): ?>
<div class="form-container wide">
    <div class="form-title">New requisition</div>
    <form method="POST"
          data-confirm-title="Raise requisition"
          data-confirm="Raise a requisition for {quantity} &times; {item_name} ({category_id})?"
          data-confirm-ok="Raise requisition">
        <input type="hidden" name="add_purchase" value="1">
        <div class="form-row">
            <div class="form-group">
                <label for="item_name">Item *</label>
                <input type="text" id="item_name" name="item_name" list="item-names" required minlength="2" maxlength="200" placeholder="Start typing an item"
                       value="<?php echo htmlspecialchars(isset($error) ? ($_POST['item_name'] ?? '') : $pre_name); ?>">
                <datalist id="item-names"><?php foreach ($items_for_list as $n): ?><option value="<?php echo htmlspecialchars($n); ?>"><?php endforeach; ?></datalist>
            </div>
            <div class="form-group">
                <label for="category_id">Category *</label>
                <select id="category_id" name="category_id" required>
                    <option value="">— Select —</option>
                    <?php $sel = isset($error) ? (int)($_POST['category_id'] ?? 0) : $pre_cat; ?>
                    <?php foreach ($categories as $c): ?>
                    <option value="<?php echo $c['id']; ?>" <?php echo $sel === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="quantity">Quantity *</label>
                <input type="number" id="quantity" name="quantity" min="1" max="100000" step="1" required
                       value="<?php echo isset($error) ? (int)($_POST['quantity'] ?? 1) : $pre_qty; ?>">
            </div>
            <?php if ($departments): ?>
            <div class="form-group">
                <label for="department_id">For department *</label>
                <?php if ($my_dept_row): ?>
                <input type="text" id="department_id" class="readonly-field" readonly tabindex="-1" value="<?php echo htmlspecialchars(dept_label($my_dept_row)); ?>">
                <?php else: ?>
                <select id="department_id" name="department_id" required>
                    <option value="">— Select department —</option>
                    <?php $sel = (int)($_POST['department_id'] ?? 0); $groups = [];
                    foreach ($departments as $d) $groups[$d['company_name'] . ' › ' . $d['branch_name']][] = $d;
                    foreach ($groups as $g => $ds): ?>
                    <optgroup label="<?php echo htmlspecialchars($g); ?>">
                        <?php foreach ($ds as $d): ?><option value="<?php echo $d['id']; ?>" <?php echo $sel === (int)$d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(dept_label($d)); ?></option><?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <div class="form-group">
            <label for="reason">Reason *</label>
            <textarea id="reason" name="reason" rows="2" required minlength="3" maxlength="500" placeholder="Why it is needed, e.g. toner for the Credit department printer"><?php echo isset($error) ? htmlspecialchars($_POST['reason'] ?? '') : ''; ?></textarea>
        </div>
        <label class="check-row">
            <input type="checkbox" name="is_it" value="1" <?php echo !empty($_POST['is_it']) && isset($error) ? 'checked' : ''; ?>>
            <span><strong>IT-related</strong><small>IT will get the quotations. Leave unticked to attach the quotations yourself.</small></span>
        </label>
        <div class="form-actions"><button type="submit" class="btn btn-primary">Raise requisition</button></div>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <div class="pill-tabs pill-tabs-inline">
        <?php foreach ($tabs as $k => [$label]): ?>
        <a href="?tab=<?php echo $k; ?>" class="pill-tab <?php echo $tab === $k ? 'active' : ''; ?>"><?php echo $label; ?> (<?php echo $counts[$k]; ?>)</a>
        <?php endforeach; ?>
    </div>
    <?php if ($list): ?>
    <div class="table-container">
        <table class="req-table">
            <thead><tr><th>Ref</th><th>Date</th><th>Raised by</th><th>Item</th><th class="num">Qty</th><th>Department</th><th>Stage</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($list as $p): ?>
                <tr>
                    <td style="white-space:nowrap"><a class="item-link inline" href="requisition.php?id=<?php echo $p['id']; ?>"><?php echo req_ref((int)$p['id']); ?></a></td>
                    <td class="muted" style="white-space:nowrap"><?php echo date('d M Y', strtotime($p['created_at'])); ?></td>
                    <td><?php echo htmlspecialchars($p['added_by'] ?? '—'); ?></td>
                    <td>
                        <a class="item-link" href="requisition.php?id=<?php echo $p['id']; ?>"><?php echo htmlspecialchars($p['item_name']); ?></a>
                        <div class="item-desc"><?php echo htmlspecialchars($p['category_name'] ?? $p['category'] ?? ''); ?><?php echo $p['is_it'] ? ' · IT-related' : ''; ?></div>
                    </td>
                    <td class="num"><?php echo number_format($p['quantity']); ?></td>
                    <td><?php echo $p['dept_name'] ? htmlspecialchars($p['dept_name']) : '<span class="muted-text">—</span>'; ?><div class="item-desc"><?php echo htmlspecialchars($p['branch_name'] ?? ''); ?></div></td>
                    <td><span class="<?php echo req_status_class($p['status']); ?>"><?php echo req_status_label($p['status']); ?></span>
                        <div class="item-desc">since <?php echo date('d M', strtotime($p['stage_at'] ?? $p['created_at'])); ?></div></td>
                    <td><a href="requisition.php?id=<?php echo $p['id']; ?>" class="btn btn-sm <?php echo $p['my_turn'] && !in_array($p['status'], ['received','cancelled'], true) ? 'btn-primary' : ''; ?>"><?php echo $p['my_turn'] && !in_array($p['status'], ['received','cancelled'], true) ? 'Take action' : 'Open'; ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><p><?php echo $tab === 'mine' ? 'Nothing is waiting for you.' : 'No requisitions here.'; ?></p></div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
