<?php
// Organisation — System Admin only: companies > branches > departments.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/insights.php';
requireAdmin();

$tabs = ['companies' => 'Companies', 'branches' => 'Branches', 'departments' => 'Departments'];
$tab  = array_key_exists($_GET['tab'] ?? '', $tabs) ? $_GET['tab'] : 'companies';
$me   = (int)$_SESSION['user_id'];

if (!empty($_SESSION['flash']))       { $success = htmlspecialchars($_SESSION['flash']);       unset($_SESSION['flash']); }
if (!empty($_SESSION['flash_error'])) { $error   = htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); }

function back_to(string $tab): void { header('Location: organisation.php?tab=' . $tab); exit(); }

/** What still uses a company/branch/department (so it can't be deleted). */
function usage(PDO $pdo, string $type, int $id): array {
    $q = fn($sql) => (function () use ($pdo, $sql, $id) { $s = $pdo->prepare($sql); $s->execute(array_fill(0, substr_count($sql, '?'), $id)); return (int)$s->fetchColumn(); })();
    if ($type === 'companies') return [
        'branches' => $q("SELECT COUNT(*) FROM branches WHERE company_id = ?"),
        'users'    => $q("SELECT COUNT(*) FROM users WHERE company_id = ?"),
    ];
    if ($type === 'branches') return [
        'departments'  => $q("SELECT COUNT(*) FROM departments WHERE branch_id = ?"),
        'users'        => $q("SELECT COUNT(*) FROM users WHERE branch_id = ?"),
        'stock records'=> $q("SELECT (SELECT COUNT(*) FROM stock_receiving WHERE branch_id = ?) + (SELECT COUNT(*) FROM stock_issuing WHERE branch_id = ?) + (SELECT COUNT(*) FROM procurement WHERE branch_id = ?)"),
        'stock held'   => $q("SELECT COALESCE(SUM(quantity),0) FROM branch_stock WHERE branch_id = ?"),
    ];
    return [
        'users'         => $q("SELECT COUNT(*) FROM users WHERE department_id = ?"),
        'stock records' => $q("SELECT (SELECT COUNT(*) FROM stock_issuing WHERE department_id = ?) + (SELECT COUNT(*) FROM procurement WHERE department_id = ?)"),
    ];
}
function usage_text(array $u): string {
    $parts = [];
    foreach ($u as $k => $n) if ($n) $parts[] = "$n $k";
    return implode(', ', $parts);
}

// ── POST handlers ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = array_key_exists($_POST['type'] ?? '', $tabs) ? $_POST['type'] : '';
    $v    = new Validator($_POST);
    if (!$type) { $v->fail('Unknown section.'); }

    // Add or edit
    if ($type && isset($_POST['save'])) {
        $id   = (int)($_POST['id'] ?? 0);
        $name = $v->text('name', 'Name', 150, true, 2);
        $parent = null; $location = null;
        if ($type === 'branches') {
            $p = $v->exists($pdo, 'company_id', 'company', 'companies', 'id, name');
            $parent = $p['id'] ?? null;
            $location = $v->text('location', 'Location', 200, false) ?: null;
        } elseif ($type === 'departments') {
            $p = $v->exists($pdo, 'branch_id', 'branch', 'branches', 'id, name');
            $parent = $p['id'] ?? null;
        }
        if ($v->ok()) {
            [$sql, $args] = match ($type) {
                'companies'   => ["SELECT id FROM companies WHERE LOWER(name)=LOWER(?) AND id<>?", [$name, $id]],
                'branches'    => ["SELECT id FROM branches WHERE company_id=? AND LOWER(name)=LOWER(?) AND id<>?", [$parent, $name, $id]],
                'departments' => ["SELECT id FROM departments WHERE branch_id=? AND LOWER(name)=LOWER(?) AND id<>?", [$parent, $name, $id]],
            };
            $d = $pdo->prepare($sql); $d->execute($args);
            if ($d->fetch()) $v->fail("\"$name\" already exists" . ($type === 'companies' ? '.' : ' there.'));
        }
        if ($v->ok() && $id && $type !== 'companies') {
            // Moving a branch/department to another parent is only safe if nothing uses it yet
            $cur = $pdo->prepare("SELECT " . ($type === 'branches' ? 'company_id' : 'branch_id') . " FROM $type WHERE id=?");
            $cur->execute([$id]);
            if ((int)$cur->fetchColumn() !== (int)$parent && array_sum(usage($pdo, $type, $id)) > 0) {
                $v->fail('This ' . rtrim($type === 'branches' ? 'branch' : 'department') . ' is already in use, so it can\'t be moved. Rename it instead, or create a new one.');
            }
        }
        if ($v->ok()) {
            if ($type === 'companies') {
                $id ? $pdo->prepare("UPDATE companies SET name=? WHERE id=?")->execute([$name, $id])
                    : $pdo->prepare("INSERT INTO companies (name) VALUES (?)")->execute([$name]);
            } elseif ($type === 'branches') {
                $id ? $pdo->prepare("UPDATE branches SET name=?, company_id=?, location=? WHERE id=?")->execute([$name, $parent, $location, $id])
                    : $pdo->prepare("INSERT INTO branches (name, company_id, location) VALUES (?,?,?)")->execute([$name, $parent, $location]);
            } else {
                $id ? $pdo->prepare("UPDATE departments SET name=?, branch_id=? WHERE id=?")->execute([$name, $parent, $id])
                    : $pdo->prepare("INSERT INTO departments (name, branch_id) VALUES (?,?)")->execute([$name, $parent]);
            }
            logAudit($pdo, $me, $id ? 'ORG_UPDATED' : 'ORG_ADDED', null, null, ucfirst(rtrim($type, 's')) . ": $name");
            $_SESSION['flash'] = "\"$name\" " . ($id ? 'saved.' : 'added.');
            back_to($type);
        }
        $error = $v->message(); $tab = $type; $form_error = true;
        if ($id) $_GET['edit'] = $id;
    }

    // Disable / enable / delete
    if ($type && isset($_POST['org_action'])) {
        $action = $v->choice('org_action', 'action', ['disable', 'enable', 'delete']);
        $row = $v->exists($pdo, 'id', 'entry', $type === 'companies' ? 'companies' : ($type === 'branches' ? 'branches' : 'departments'), 'id, name');
        if ($v->ok()) {
            if ($action === 'delete') {
                $u = usage($pdo, $type, (int)$row['id']);
                if (array_sum($u) > 0) {
                    $_SESSION['flash_error'] = "\"{$row['name']}\" is still used (" . usage_text($u) . "), so it can't be deleted. Disable it instead.";
                } else {
                    $pdo->prepare("DELETE FROM $type WHERE id=?")->execute([$row['id']]);
                    logAudit($pdo, $me, 'ORG_DELETED', null, null, ucfirst(rtrim($type, 's')) . ": {$row['name']}");
                    $_SESSION['flash'] = "\"{$row['name']}\" deleted.";
                }
            } else {
                $pdo->prepare("UPDATE $type SET active=? WHERE id=?")->execute([$action === 'enable' ? 1 : 0, $row['id']]);
                logAudit($pdo, $me, 'ORG_' . strtoupper($action) . 'D', null, null, ucfirst(rtrim($type, 's')) . ": {$row['name']}");
                $_SESSION['flash'] = "\"{$row['name']}\" " . ($action === 'enable' ? 'enabled.' : 'disabled. It no longer appears in pickers; existing records keep it.');
            }
        } else {
            $_SESSION['flash_error'] = implode(' ', $v->errors());
        }
        back_to($type);
    }
}

// ── Data ────────────────────────────────────────────────────
$companies = $pdo->query("
    SELECT c.*, (SELECT COUNT(*) FROM branches WHERE company_id=c.id) AS branches,
           (SELECT COUNT(*) FROM users WHERE company_id=c.id) AS users
    FROM companies c ORDER BY c.active DESC, c.name")->fetchAll();
$branches = $pdo->query("
    SELECT b.*, c.name AS company_name,
           (SELECT COUNT(*) FROM departments WHERE branch_id=b.id) AS departments,
           (SELECT COUNT(*) FROM users WHERE branch_id=b.id) AS users,
           (SELECT COALESCE(SUM(quantity),0) FROM branch_stock WHERE branch_id=b.id) AS units
    FROM branches b JOIN companies c ON c.id=b.company_id ORDER BY b.active DESC, c.name, b.name")->fetchAll();
$departments = $pdo->query("
    SELECT d.*, b.name AS branch_name, c.name AS company_name,
           (SELECT COUNT(*) FROM users WHERE department_id=d.id) AS users,
           (SELECT COUNT(*) FROM stock_issuing WHERE department_id=d.id) AS issues
    FROM departments d JOIN branches b ON b.id=d.branch_id JOIN companies c ON c.id=b.company_id
    ORDER BY d.active DESC, c.name, b.name, d.name")->fetchAll();

$rows = ['companies' => $companies, 'branches' => $branches, 'departments' => $departments][$tab];
$editing = null;
foreach ($rows as $r) if ((int)($_GET['edit'] ?? 0) === (int)$r['id']) $editing = $r;
$f = function (string $k, $default = '') use ($editing) {
    if (!empty($GLOBALS['form_error'])) return $_POST[$k] ?? $default;
    return $editing[$k] ?? $default;
};
$singular = ['companies' => 'company', 'branches' => 'branch', 'departments' => 'department'][$tab];

$breadcrumbs = [['Organisation', 'organisation.php'], [$tabs[$tab], $editing ? 'organisation.php?tab=' . $tab : null]];
if ($editing) $breadcrumbs[] = ['Edit ' . $editing['name'], null];
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Organisation</h1>
    <span class="card-hint">Companies contain branches; branches contain departments. Used for users, issuing, requisitions and stock per branch.</span>
</div>

<div class="pill-tabs">
    <?php foreach ($tabs as $k => $label): ?>
    <a href="?tab=<?php echo $k; ?>" class="pill-tab <?php echo $tab === $k ? 'active' : ''; ?>"><?php echo $label; ?> (<?php echo count(['companies' => $companies, 'branches' => $branches, 'departments' => $departments][$k]); ?>)</a>
    <?php endforeach; ?>
</div>

<?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
<?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<!-- Add / edit -->
<div class="form-container wide" id="form">
    <div class="form-title"><?php echo $editing ? 'Edit ' . htmlspecialchars($editing['name']) : 'Add ' . $singular; ?></div>
    <?php if ($tab === 'branches' && !$companies): ?><div class="info-note">Add a company first.</div>
    <?php elseif ($tab === 'departments' && !$branches): ?><div class="info-note">Add a branch first.</div>
    <?php else: ?>
    <form method="POST"
          data-confirm-title="<?php echo $editing ? 'Save ' . $singular : 'Add ' . $singular; ?>"
          data-confirm="<?php echo $editing ? 'Save the changes to ' . htmlspecialchars($editing['name']) . '?' : 'Add the ' . $singular . ' {name}?'; ?>"
          data-confirm-ok="<?php echo $editing ? 'Save changes' : 'Add ' . $singular; ?>">
        <input type="hidden" name="type" value="<?php echo $tab; ?>">
        <input type="hidden" name="save" value="1">
        <?php if ($editing): ?><input type="hidden" name="id" value="<?php echo $editing['id']; ?>"><?php endif; ?>
        <div class="form-row">
            <?php if ($tab === 'branches'): ?>
            <div class="form-group"><label for="company_id">Company *</label>
                <select id="company_id" name="company_id" required><option value="">— Select —</option>
                <?php foreach ($companies as $c): ?><option value="<?php echo $c['id']; ?>" <?php echo (int)$f('company_id') === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']) . ($c['active'] ? '' : ' (disabled)'); ?></option><?php endforeach; ?>
                </select></div>
            <?php elseif ($tab === 'departments'): ?>
            <div class="form-group"><label for="branch_id">Branch *</label>
                <select id="branch_id" name="branch_id" required><option value="">— Select —</option>
                <?php foreach ($branches as $b): ?><option value="<?php echo $b['id']; ?>" <?php echo (int)$f('branch_id') === (int)$b['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['company_name'] . ' › ' . $b['name']) . ($b['active'] ? '' : ' (disabled)'); ?></option><?php endforeach; ?>
                </select></div>
            <?php endif; ?>
            <div class="form-group"><label for="name"><?php echo ucfirst($singular); ?> name *</label>
                <input type="text" id="name" name="name" required minlength="2" maxlength="150" value="<?php echo htmlspecialchars($f('name')); ?>"
                       placeholder="<?php echo ['companies' => 'e.g. Altus Insurance Brokers', 'branches' => 'e.g. Lusaka – Cairo Road', 'departments' => 'e.g. Credit'][$tab]; ?>"></div>
        </div>
        <?php if ($tab === 'branches'): ?>
        <div class="form-group" style="max-width:50%"><label for="location">Location</label>
            <input type="text" id="location" name="location" maxlength="200" placeholder="Optional, e.g. Plot 6106, Great East Road" value="<?php echo htmlspecialchars($f('location')); ?>"></div>
        <?php endif; ?>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary"><?php echo $editing ? 'Save changes' : 'Add ' . $singular; ?></button>
            <?php if ($editing): ?><a href="organisation.php?tab=<?php echo $tab; ?>" class="btn">Cancel</a><?php endif; ?>
        </div>
    </form>
    <?php endif; ?>
</div>

<!-- List -->
<div class="card">
    <?php if ($rows): ?>
    <div class="table-container">
        <table>
            <thead><tr>
                <th>Name</th>
                <?php if ($tab === 'branches'): ?><th>Company</th><th>Location</th><th class="num">Departments</th><th class="num">Units in stock</th>
                <?php elseif ($tab === 'departments'): ?><th>Branch</th><th>Company</th><th class="num">Items issued to</th>
                <?php else: ?><th class="num">Branches</th><?php endif; ?>
                <th class="num">Users</th><th>Status</th><th>Date added</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $in_use = array_sum(usage($pdo, $tab, (int)$r['id'])) > 0; ?>
                <tr class="<?php echo $r['active'] ? '' : 'row-disabled'; ?>">
                    <td style="font-weight:600"><?php echo htmlspecialchars($r['name']); ?></td>
                    <?php if ($tab === 'branches'): ?>
                        <td><?php echo htmlspecialchars($r['company_name']); ?></td>
                        <td class="muted"><?php echo htmlspecialchars($r['location'] ?: '—'); ?></td>
                        <td class="num"><?php echo (int)$r['departments']; ?></td>
                        <td class="num"><?php echo number_format($r['units']); ?></td>
                    <?php elseif ($tab === 'departments'): ?>
                        <td><?php echo htmlspecialchars($r['branch_name']); ?></td>
                        <td class="muted"><?php echo htmlspecialchars($r['company_name']); ?></td>
                        <td class="num"><?php echo (int)$r['issues']; ?></td>
                    <?php else: ?>
                        <td class="num"><?php echo (int)$r['branches']; ?></td>
                    <?php endif; ?>
                    <td class="num"><?php echo (int)$r['users']; ?></td>
                    <td><?php echo $r['active'] ? '<span class="status-receiving">Active</span>' : '<span class="status-issuing">Disabled</span>'; ?></td>
                    <td class="muted" style="white-space:nowrap"><?php echo date('d M Y', strtotime($r['created_at'])); ?></td>
                    <td><div class="row-actions">
                        <a href="organisation.php?tab=<?php echo $tab; ?>&edit=<?php echo $r['id']; ?>#form" class="btn btn-icon" aria-label="Edit <?php echo htmlspecialchars($r['name']); ?>" data-tip="Edit"><?php echo row_icon('edit'); ?></a>
                        <form method="POST" class="inline-form"
                              data-confirm-title="<?php echo $r['active'] ? 'Disable' : 'Enable'; ?> <?php echo $singular; ?>"
                              data-confirm="<?php echo $r['active'] ? 'Disable ' . htmlspecialchars($r['name']) . '? It will stop appearing in pickers. Existing records keep it.' : 'Enable ' . htmlspecialchars($r['name']) . '?'; ?>"
                              data-confirm-ok="<?php echo $r['active'] ? 'Disable' : 'Enable'; ?>" <?php echo $r['active'] ? 'data-confirm-danger="1"' : ''; ?>>
                            <input type="hidden" name="type" value="<?php echo $tab; ?>"><input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                            <button type="submit" name="org_action" value="<?php echo $r['active'] ? 'disable' : 'enable'; ?>" class="btn btn-icon"
                                    aria-label="<?php echo ($r['active'] ? 'Disable ' : 'Enable ') . htmlspecialchars($r['name']); ?>" data-tip="<?php echo $r['active'] ? 'Disable' : 'Enable'; ?>"><?php echo row_icon($r['active'] ? 'lock' : 'unlock'); ?></button>
                        </form>
                        <?php if (!$in_use): ?>
                        <form method="POST" class="inline-form" data-confirm-title="Delete <?php echo $singular; ?>"
                              data-confirm="Delete <?php echo htmlspecialchars($r['name']); ?>? This cannot be undone." data-confirm-ok="Delete" data-confirm-danger="1">
                            <input type="hidden" name="type" value="<?php echo $tab; ?>"><input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                            <button type="submit" name="org_action" value="delete" class="btn btn-icon btn-icon-danger" aria-label="Delete <?php echo htmlspecialchars($r['name']); ?>" data-tip="Delete"><?php echo row_icon('delete'); ?></button>
                        </form>
                        <?php else: ?>
                        <span class="btn btn-icon" aria-disabled="true" role="img" aria-label="Can't delete: in use" data-tip="In use (<?php echo htmlspecialchars(usage_text(usage($pdo, $tab, (int)$r['id']))); ?>). Disable instead."><?php echo row_icon('delete'); ?></span>
                        <?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><p>No <?php echo strtolower($tabs[$tab]); ?> yet. Add the first one above.</p></div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
