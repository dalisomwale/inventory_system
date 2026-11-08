<?php
// Requisitions: raise one, and see every requisition you take part in, by stage.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/requisition_flow.php';
redirectIfNotLoggedIn();
if (!req_can_open()) requirePermission('requisitions.raise');

$categories  = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$departments = org_departments($pdo);
// Most people raise for their own department; "raise for any" (and the System Admin) can pick any company and department
$raise_any   = can('requisitions.raise_any');
$my_dept     = $raise_any ? 0 : req_my_department($pdo);
if (!$raise_any && ($my_co = req_my_company($pdo))) $departments = array_values(array_filter($departments, fn($d) => (int)$d['company_id'] === $my_co));

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
        $want_co = (int)($_POST['company_id'] ?? 0);
        // A company without departments (only possible with "raise for any") is raised for the company as a whole
        $co_has_depts = $my_dept || !$want_co || (bool)array_filter($departments, fn($d) => (int)$d['company_id'] === $want_co);
        if (!$my_dept && !$want_co) $v->fail('Please choose the company.');
        elseif ($co_has_depts) {
            foreach ($departments as $d) if ((int)$d['id'] === $want) $dept = $d;
            if (!$dept) $v->fail('Please choose the department that needs this.');
            elseif (!$my_dept && $want_co && (int)$dept['company_id'] !== $want_co) { $v->fail("That department isn't in the company you chose."); $dept = null; }
        }
    }
    $req_branch  = $dept ? (int)$dept['branch_id'] : (user_branch_id() ?: null);
    // The company decides whose IT, Director and Accounts handle it
    $req_company = $dept ? (int)$dept['company_id'] : null;
    if (!$dept) {
        $want_co = (int)($_POST['company_id'] ?? 0);
        if ($want_co && ($raise_any || !req_my_company($pdo) || $want_co === req_my_company($pdo))) {
            $ok_co = $pdo->prepare("SELECT id FROM companies WHERE id = ? AND active = 1"); $ok_co->execute([$want_co]);
            $req_company = (int)$ok_co->fetchColumn() ?: null;
        }
        $req_company = $req_company ?: (req_my_company($pdo) ?: null);
        if (!$req_company) $v->fail('Please choose the company.');
    }
    // No department: use the raiser's branch if it is in that company, otherwise the company's only branch
    if (!$dept && $req_company) {
        $bb = $pdo->prepare("SELECT id FROM branches WHERE company_id = ? AND active = 1");
        $bb->execute([$req_company]);
        $co_branches = array_map('intval', $bb->fetchAll(PDO::FETCH_COLUMN));
        if (!in_array((int)$req_branch, $co_branches, true)) $req_branch = count($co_branches) === 1 ? $co_branches[0] : null;
    }
    if (!$req_company && $req_branch) {
        $bc = $pdo->prepare("SELECT company_id FROM branches WHERE id = ?"); $bc->execute([$req_branch]); $req_company = (int)$bc->fetchColumn() ?: null;
    }

    if ($v->ok()) {
        $dup = $pdo->prepare("SELECT COUNT(*) FROM procurement WHERE LOWER(item_name) = LOWER(?) AND (department_id <=> ?) AND status NOT IN ('received','cancelled')");
        $dup->execute([$item_name, $dept['id'] ?? null]);
        if ($dup->fetchColumn() > 0) $v->fail("There is already an open requisition for \"$item_name\"" . ($dept ? " for {$dept['name']}" : '') . '.');
    }
    // Optional supporting documents (specs, photos, a quote already in hand)
    $attached = [];
    // Not needed when it's IT-related: IT gets the quotations
    if ($v->ok() && !$is_it) {
        $up = $_FILES['attachments'] ?? null;
        $files = [];
        if ($up && is_array($up['name'])) {
            foreach ($up['name'] as $i => $n) {
                if (($up['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                $files[] = ['name' => $n, 'type' => $up['type'][$i], 'tmp_name' => $up['tmp_name'][$i], 'error' => $up['error'][$i], 'size' => $up['size'][$i]];
            }
        }
        if (!$files) $v->fail('Please attach at least one file.');
        elseif (count($files) > REQ_ATTACH_MAX) $v->fail('You can attach up to ' . REQ_ATTACH_MAX . ' files.');
        else foreach ($files as $f) {
            $r = req_store_upload($f);
            if (is_string($r)) { $v->fail($f['name'] . ': ' . $r); break; }
            $attached[] = $r;
        }
        if (!$v->ok()) {
            foreach ($attached as [$stored]) @unlink(req_storage_dir() . '/' . $stored);
            $attached = [];
        }
    }
    if (!$v->ok()) {
        $error = $v->message() . (!empty($_FILES['attachments']['name'][0]) ? ' Please choose your attachments again.' : '');
    } else {
        $match = $pdo->prepare("SELECT id FROM items WHERE LOWER(item_name) = LOWER(?) AND category_id = ? LIMIT 1");
        $match->execute([$item_name, $category['id']]);
        $item_id = $match->fetchColumn() ?: null;
        $status  = $is_it ? 'quotes_it' : 'quotes_dept';

        $pdo->prepare("INSERT INTO procurement (item_name, item_id, quantity, reason, requested_by, category_id, category, company_id, branch_id, department_id, is_it, status, stage_at)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
            ->execute([$item_name, $item_id, $quantity, $notes, $_SESSION['user_id'], $category['id'], $category['name'], $req_company, $req_branch, $dept['id'] ?? null, $is_it, $status]);
        $id = (int)$pdo->lastInsertId();
        req_log($pdo, $id, 'raised', null, $status, $is_it ? 'IT-related' : null);
        $add_file = $pdo->prepare("INSERT INTO requisition_files (requisition_id, kind, original_name, stored_name, mime, size, uploaded_by) VALUES (?,?,?,?,?,?,?)");
        foreach ($attached as [$stored, $orig, $mime, $size]) $add_file->execute([$id, 'doc', $orig, $stored, $mime, $size, $_SESSION['user_id']]);
        if ($attached) req_log($pdo, $id, 'docs_added', null, null, implode(', ', array_column($attached, 1)));
        logAudit($pdo, $_SESSION['user_id'], 'REQUISITION_RAISED', $item_id ? (int)$item_id : null, $quantity, req_ref($id) . " raised: $item_name");
        $_SESSION['flash'] = req_ref($id) . ' raised. ' . ($is_it ? 'It is now with IT for quotations.' : 'Attach the quotations below, then send it to the Director.');
        header('Location: requisition.php?id=' . $id); exit();
    }
}

// ── List ────────────────────────────────────────────────────
$visible = req_visible_sql($pdo);
// Company filter, for people who see more than one company
$companies  = org_companies($pdo);
$co_ids     = array_map('intval', $pdo->query("SELECT DISTINCT p.company_id FROM procurement p WHERE $visible AND p.company_id IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN));
$show_co    = count($companies) > 1 && count($co_ids) > 1;
$company_id = (int)($_GET['company_id'] ?? 0);
if (!in_array($company_id, $co_ids, true)) $company_id = 0;
if ($company_id) $visible .= " AND p.company_id = $company_id";
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
    SELECT p.*, u.full_name AS added_by, c.name AS category_name, d.name AS dept_name, b.name AS branch_name, co.name AS company_name,
           (SELECT COUNT(*) FROM requisition_files f WHERE f.requisition_id = p.id AND f.kind = 'quote') AS quotes,
           ($mine) AS my_turn
    FROM procurement p
    LEFT JOIN users u  ON u.id  = p.requested_by
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN departments d ON d.id = p.department_id
    LEFT JOIN branches b ON b.id = p.branch_id
    LEFT JOIN companies co ON co.id = p.company_id
    WHERE $visible AND {$tabs[$tab][1]}
    ORDER BY FIELD(p.status,'returned','quotes_it','quotes_dept','director','accounts','accounts_manager','cfo','pay','paid','received','cancelled'), p.created_at DESC")->fetchAll();

$items_for_list = $pdo->query("SELECT item_name FROM items ORDER BY item_name")->fetchAll(PDO::FETCH_COLUMN);
$pre_name = is_string($_GET['item_name'] ?? null) ? mb_substr($_GET['item_name'], 0, 200) : '';
$pre_cat  = (int)($_GET['category_id'] ?? 0);
$pre_qty  = max(1, min(100000, (int)($_GET['qty'] ?? 1)));
$my_dept_row = null;
foreach ($departments as $d) if ((int)$d['id'] === $my_dept) $my_dept_row = $d;
// Companies that have departments to raise for
$form_companies = [];
foreach ($departments as $d) $form_companies[(int)$d['company_id']] = $d['company_name'];
if (!$departments || $raise_any) foreach (org_companies($pdo) as $c) if ($raise_any || !req_my_company($pdo) || (int)$c['id'] === req_my_company($pdo)) $form_companies[(int)$c['id']] = $c['name'];
asort($form_companies);
$pre_co = isset($error) ? (int)($_POST['company_id'] ?? 0) : (count($form_companies) === 1 ? (int)array_key_first($form_companies) : ($raise_any ? 0 : req_my_company($pdo)));

if (wants_xlsx()) {
    // Amount of the quotation the Director approved, where there is one
    $ids = array_map(fn($p) => (int)$p['quote_id'], array_filter($list, fn($p) => $p['quote_id']));
    $amounts = $ids ? array_column($pdo->query("SELECT id, amount FROM requisition_files WHERE id IN (" . implode(',', $ids) . ")")->fetchAll(), 'amount', 'id') : [];
    $co_name = '';
    foreach ($companies as $c) if ((int)$c['id'] === $company_id) $co_name = $c['name'];
    xlsx_download('Requisitions ' . $tabs[$tab][0] . ' ' . date('Y-m-d'), [[
        'name'    => 'Requisitions',
        'title'   => 'Requisitions - ' . $tabs[$tab][0],
        'meta'    => array_filter([$co_name ? "Company: $co_name" : '', 'Exported: ' . date('d M Y, H:i')]),
        'columns' => ['Ref', 'Date raised', 'Raised by', 'Item', ['Qty', 'int'], 'Category', 'Company', 'Department', 'Branch', 'IT-related',
                      'Stage', ['Quotations', 'int'], 'Approved supplier', ['Approved amount (K)', 'money'], 'Paid on', 'Reason'],
        'rows'    => array_map(fn($p) => [req_ref((int)$p['id']), date('d M Y', strtotime($p['created_at'])), $p['added_by'] ?? '', $p['item_name'], $p['quantity'],
                      $p['category_name'] ?? $p['category'] ?? '', $p['company_name'] ?? '', $p['dept_name'] ?? '', $p['branch_name'] ?? '', $p['is_it'] ? 'Yes' : 'No',
                      req_status_label($p['status']), $p['quotes'], $p['quote_id'] ? ($p['supplier'] ?? '') : '', $p['quote_id'] ? ($amounts[$p['quote_id']] ?? '') : '',
                      $p['paid_at'] ? date('d M Y', strtotime($p['paid_at'])) : '', $p['reason'] ?? ''], $list),
        'totals'  => ['Total', '', '', '', '', '', '', '', '', '', '', '', '', array_sum(array_map(fn($p) => $p['quote_id'] ? (float)($amounts[$p['quote_id']] ?? 0) : 0, $list))],
    ]]);
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Requisitions</h1>
    <?php echo xlsx_button(); ?>
</div>

<?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
<?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<?php if (can('requisitions.raise')): ?>
<div class="form-container wide">
    <div class="form-title">New requisition</div>
    <form method="POST" enctype="multipart/form-data"
          data-confirm-title="Raise requisition"
          data-confirm="Raise a requisition for {quantity} &times; {item_name} ({category_id})<?php echo $departments && !$my_dept_row ? ' for {department_id}, {company_id}' : ''; ?>?"
          data-confirm-ok="Raise requisition">
        <input type="hidden" name="add_purchase" value="1">
        <div class="form-row">
            <div class="form-group">
                <label for="company_id">Company *</label>
                <?php if ($my_dept_row || count($form_companies) === 1): ?>
                <input type="text" id="company_id" class="readonly-field" readonly tabindex="-1" value="<?php echo htmlspecialchars($my_dept_row['company_name'] ?? reset($form_companies)); ?>">
                <?php if (!$my_dept_row): ?><input type="hidden" name="company_id" value="<?php echo (int)array_key_first($form_companies); ?>"><?php endif; ?>
                <?php else: ?>
                <select id="company_id" name="company_id" required>
                    <option value="">— Select company —</option>
                    <?php foreach ($form_companies as $cid => $cname): ?>
                    <option value="<?php echo $cid; ?>" <?php echo $pre_co === $cid ? 'selected' : ''; ?>><?php echo htmlspecialchars($cname); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
            <?php if ($departments): ?>
            <div class="form-group">
                <label for="department_id">Department *</label>
                <?php if ($my_dept_row): ?>
                <input type="text" id="department_id" class="readonly-field" readonly tabindex="-1" value="<?php echo htmlspecialchars(dept_label($my_dept_row)); ?>">
                <?php else: ?>
                <select id="department_id" name="department_id" required data-placeholder="— Select department —" data-none="— No departments in this company —">
                    <option value="">— Select department —</option>
                    <?php $sel = (int)($_POST['department_id'] ?? 0);
                    foreach ($departments as $d): ?>
                    <option value="<?php echo $d['id']; ?>" data-company="<?php echo (int)$d['company_id']; ?>" <?php echo $sel === (int)$d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(dept_label($d)); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
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
            <div class="form-group" id="attachments-group">
                <label for="attachments">Attachments <span class="attach-star">*</span></label>
                <input type="file" id="attachments" name="attachments[]" multiple required accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx">
            </div>
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
    <?php if ($show_co): ?>
    <form method="GET" class="req-company-filter">
        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
        <div class="form-group">
        <label for="company_filter">Company</label>
        <select id="company_filter" name="company_id" onchange="this.form.submit()">
            <option value="">All companies</option>
            <?php foreach ($companies as $c): if (!in_array((int)$c['id'], $co_ids, true)) continue; ?>
            <option value="<?php echo $c['id']; ?>" <?php echo $company_id === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
            <?php endforeach; ?>
        </select>
        </div>
        <noscript><button type="submit" class="btn btn-sm">Show</button></noscript>
    </form>
    <?php endif; ?>
    <div class="pill-tabs pill-tabs-inline">
        <?php foreach ($tabs as $k => [$label]): ?>
        <a href="?tab=<?php echo $k; ?><?php echo $company_id ? '&company_id=' . $company_id : ''; ?>" class="pill-tab <?php echo $tab === $k ? 'active' : ''; ?>"><?php echo $label; ?> (<?php echo $counts[$k]; ?>)</a>
        <?php endforeach; ?>
    </div>
    <?php if ($list): ?>
    <div class="table-container">
        <table class="req-table">
            <thead><tr><th>Ref</th><th>Date</th><th>Raised by</th><th>Item</th><th class="num">Qty</th><th>Company</th><th>Department</th><th>Stage</th><th></th></tr></thead>
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
                    <td><?php echo $p['company_name'] ? htmlspecialchars($p['company_name']) : '<span class="muted-text">—</span>'; ?></td>
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

<script>
// IT-related: IT gets the quotations, so attachments are greyed out and not needed
(function () {
    var it = document.querySelector('input[name="is_it"]'), file = document.getElementById('attachments'), group = document.getElementById('attachments-group');
    if (!it || !file || !group) return;
    function sync() {
        file.disabled = it.checked;
        file.required = !it.checked;
        if (it.checked) file.value = '';
        group.classList.toggle('is-disabled', it.checked);
        group.title = it.checked ? 'Not needed: IT will get the quotations' : '';
    }
    it.addEventListener('change', sync);
    sync();
})();

// Only show the chosen company's departments
(function () {
    var co = document.querySelector('select#company_id'), dept = document.querySelector('select#department_id');
    if (!co || !dept) return;
    function sync() {
        var any = false;
        Array.prototype.forEach.call(dept.options, function (o) {
            if (!o.value) return;
            var show = co.value !== '' && o.dataset.company === co.value;
            o.hidden = !show; o.disabled = !show;
            if (show) any = true;
            if (!show && o.selected) dept.value = '';
        });
        // A company with no departments is raised for the company as a whole
        var none = co.value !== '' && !any;
        dept.options[0].textContent = none ? dept.dataset.none : dept.dataset.placeholder;
        dept.disabled = co.value === '' || none;
        dept.required = !none;
    }
    co.addEventListener('change', sync);
    sync();
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
