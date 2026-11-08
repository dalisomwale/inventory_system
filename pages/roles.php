<?php
// Roles — create roles and choose their access level in each area.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/insights.php';
requirePermission('admin.roles');

$me = (int)$_SESSION['user_id'];
if (!empty($_SESSION['flash']))       { $success = htmlspecialchars($_SESSION['flash']);       unset($_SESSION['flash']); }
if (!empty($_SESSION['flash_error'])) { $error   = htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); }

function back_to_roles(string $q = ''): void { header('Location: roles.php' . $q); exit(); }

// ── Save (add or edit) ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_role'])) {
    $v    = new Validator($_POST);
    $id   = (int)($_POST['id'] ?? 0);
    $name = $v->text('name', 'Role name', 100, true, 2);
    $desc = $v->text('description', 'Description', 255, false);
    $levels = [];
    foreach (ACCESS_AREAS as $area => [$alabel, , $opts]) {
        $raw = $_POST['level'][$area] ?? '0';
        if (!ctype_digit((string)$raw) || (int)$raw >= count($opts)) $v->fail("Choose an access level for $alabel.");
        $levels[$area] = (int)$raw;
    }
    $perms = perms_for_levels($levels);
    $role = null;
    if ($id) {
        $st = $pdo->prepare("SELECT * FROM roles WHERE id = ?"); $st->execute([$id]); $role = $st->fetch();
        if (!$role) $v->fail('That role no longer exists.');
        elseif ((int)$role['is_system']) $v->fail('The System Admin role always has full access and can\'t be changed.');
    }
    if ($v->ok()) {
        $d = $pdo->prepare("SELECT id FROM roles WHERE LOWER(name) = LOWER(?) AND id <> ?"); $d->execute([$name, $id]);
        if ($d->fetch()) $v->fail("A role called \"$name\" already exists.");
    }
    if ($v->ok() && !$perms) $v->fail('Give the role access to at least one area.');
    // Don't let an admin remove their own access to this page by editing their own role
    if ($v->ok() && $id) {
        $mine = $pdo->prepare("SELECT role_id FROM users WHERE id = ?"); $mine->execute([$me]);
        if ((int)$mine->fetchColumn() === $id && !in_array('admin.roles', $perms, true)) {
            $v->fail('You can\'t set Roles to "No access" on your own role, or you\'d lock yourself out of this page.');
        }
    }
    if ($v->ok()) {
        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare("UPDATE roles SET name = ?, description = ? WHERE id = ?")->execute([$name, $desc ?: null, $id]);
                $pdo->prepare("DELETE FROM role_permissions WHERE role_id = ?")->execute([$id]);
            } else {
                $pdo->prepare("INSERT INTO roles (name, description) VALUES (?, ?)")->execute([$name, $desc ?: null]);
                $id = (int)$pdo->lastInsertId();
            }
            $ins = $pdo->prepare("INSERT INTO role_permissions (role_id, permission) VALUES (?, ?)");
            foreach ($perms as $p) $ins->execute([$id, $p]);
            logAudit($pdo, $me, $role ? 'ROLE_UPDATED' : 'ROLE_CREATED', null, null, "$name: " . implode(', ', array_map(fn($x) => $x[0] . ': ' . $x[1], levels_summary($perms))));
            $pdo->commit();
            $_SESSION['flash'] = "Role \"$name\" " . ($role ? 'saved. Changes apply to its users straight away.' : 'created.');
            back_to_roles();
        } catch (Exception $e) {
            $pdo->rollBack(); error_log($e->getMessage());
            $error = 'Could not save the role. Please try again.';
        }
    } else {
        $error = $v->message();
    }
    $form_error = true;
    if ($id) $_GET['edit'] = $id; else $_GET['new'] = 1;
}

// ── Default for sign-ups / delete ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['role_action'])) {
    $v      = new Validator($_POST);
    $action = $v->choice('role_action', 'action', ['default', 'delete']);
    $id     = (int)($_POST['id'] ?? 0);
    $st = $pdo->prepare("SELECT r.*, (SELECT COUNT(*) FROM users WHERE role_id = r.id) AS users FROM roles r WHERE r.id = ?");
    $st->execute([$id]); $role = $st->fetch();
    if (!$role) $v->fail('That role no longer exists.');
    if ($v->ok() && $action === 'default') {
        if ((int)$role['is_system']) $v->fail('System Admin can\'t be the default for sign-ups.');
        else {
            $pdo->exec("UPDATE roles SET is_default = 0");
            $pdo->prepare("UPDATE roles SET is_default = 1 WHERE id = ?")->execute([$id]);
            logAudit($pdo, $me, 'ROLE_DEFAULT', null, null, "Default sign-up role: {$role['name']}");
            $_SESSION['flash'] = "People who sign up themselves will now get the \"{$role['name']}\" role.";
        }
    }
    if ($v->ok() && $action === 'delete') {
        if ((int)$role['is_system'])      $v->fail('The System Admin role can\'t be deleted.');
        elseif ((int)$role['users'] > 0)  $v->fail("\"{$role['name']}\" is given to {$role['users']} user(s). Move them to another role first.");
        elseif ((int)$role['is_default']) $v->fail("\"{$role['name']}\" is the default for sign-ups. Make another role the default first.");
        else {
            $pdo->prepare("DELETE FROM roles WHERE id = ?")->execute([$id]);
            logAudit($pdo, $me, 'ROLE_DELETED', null, null, "Deleted role: {$role['name']}");
            $_SESSION['flash'] = "Role \"{$role['name']}\" deleted.";
        }
    }
    if (!$v->ok()) $_SESSION['flash_error'] = implode(' ', $v->errors());
    back_to_roles();
}

// ── Data ────────────────────────────────────────────────────
$roles = $pdo->query("
    SELECT r.*, (SELECT COUNT(*) FROM users WHERE role_id = r.id) AS users,
           (SELECT COUNT(*) FROM role_permissions WHERE role_id = r.id) AS perm_count
    FROM roles r ORDER BY r.is_system DESC, r.name")->fetchAll();

$editing = null; $editing_perms = [];
if (!empty($_GET['edit'])) {
    foreach ($roles as $r) if ((int)$r['id'] === (int)$_GET['edit']) $editing = $r;
    if ($editing) $editing_perms = role_permissions($pdo, (int)$editing['id']);
}
$showing_form = $editing || !empty($_GET['new']);
$locked  = $editing && (int)$editing['is_system'];
if (!empty($form_error))  $current = array_map('intval', (array)($_POST['level'] ?? []));
elseif ($editing)         $current = levels_from_perms($editing_perms);
else                      $current = [];
foreach ($roles as &$r) $r['summary'] = levels_summary(role_permissions($pdo, (int)$r['id'])); unset($r);

$breadcrumbs = [['Roles', $showing_form ? 'roles.php' : null]];
if ($showing_form) $breadcrumbs[] = [$editing ? 'Edit ' . $editing['name'] : 'New role', null];
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><?php echo $showing_form ? ($editing ? htmlspecialchars($editing['name']) : 'New role') : 'Roles'; ?></h1>
    <?php if (!$showing_form): ?><a href="roles.php?new=1" class="btn btn-primary">New role</a><?php endif; ?>
</div>

<?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
<?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<?php if ($showing_form): ?>
<form method="POST" class="form-container wide role-form"
      data-confirm-title="<?php echo $editing ? 'Save role' : 'Create role'; ?>"
      data-confirm="<?php echo $editing ? 'Save the access levels for ' . htmlspecialchars($editing['name']) . '? Its ' . (int)$editing['users'] . ' user(s) get the change straight away.' : 'Create the role {name}?'; ?>"
      data-confirm-ok="<?php echo $editing ? 'Save role' : 'Create role'; ?>">
    <input type="hidden" name="save_role" value="1">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?php echo $editing['id']; ?>"><?php endif; ?>
    <?php if ($locked): ?><div class="info-note">System Admin always has every access right and can't be changed, so there is always someone who can manage users and roles.</div><?php endif; ?>
    <div class="form-row">
        <div class="form-group"><label for="name">Role name *</label>
            <input type="text" id="name" name="name" required minlength="2" maxlength="100" placeholder="e.g. Storekeeper" <?php echo $locked ? 'disabled' : ''; ?>
                   value="<?php echo htmlspecialchars(!empty($form_error) ? ($_POST['name'] ?? '') : ($editing['name'] ?? '')); ?>"></div>
        <div class="form-group"><label for="description">Description</label>
            <input type="text" id="description" name="description" maxlength="255" placeholder="Optional, e.g. Receives deliveries at a branch" <?php echo $locked ? 'disabled' : ''; ?>
                   value="<?php echo htmlspecialchars(!empty($form_error) ? ($_POST['description'] ?? '') : ($editing['description'] ?? '')); ?>"></div>
    </div>

    <div class="perm-head">
        <div class="form-title" style="margin:0">Access levels</div>
        <?php if (!$locked): ?><div class="perm-bulk"><button type="button" class="btn btn-sm" data-level-all="max">Full access everywhere</button><button type="button" class="btn btn-sm" data-level-all="0">No access everywhere</button></div><?php endif; ?>
    </div>
    <p class="muted-text" style="margin:-4px 0 12px">Pick one level for each area. Each level includes everything in the levels above it.</p>
    <div class="perm-groups">
        <?php foreach (ACCESS_AREAS as $area => [$alabel, $ahelp, $opts]):
            $sel = $locked ? count($opts) - 1 : (int)($current[$area] ?? 0); ?>
        <fieldset class="perm-group" data-area="<?php echo $area; ?>">
            <legend><?php echo htmlspecialchars($alabel); ?> <small><?php echo htmlspecialchars($ahelp); ?></small></legend>
            <?php foreach ($opts as $i => [$lname, $ldesc]): ?>
            <label class="perm<?php echo $i === 0 ? ' perm-none' : ''; ?>">
                <input type="radio" name="level[<?php echo $area; ?>]" value="<?php echo $i; ?>" <?php echo $i === $sel ? 'checked' : ''; ?> <?php echo $locked ? 'disabled' : ''; ?>>
                <span><strong><?php echo htmlspecialchars($lname); ?></strong><small><?php echo htmlspecialchars($ldesc); ?></small></span>
            </label>
            <?php endforeach; ?>
        </fieldset>
        <?php endforeach; ?>
    </div>

    <div class="form-actions">
        <?php if (!$locked): ?><button type="submit" class="btn btn-primary"><?php echo $editing ? 'Save role' : 'Create role'; ?></button><?php endif; ?>
        <a href="roles.php" class="btn"><?php echo $locked ? 'Back' : 'Cancel'; ?></a>
    </div>
</form>
<script>
document.querySelectorAll('[data-level-all]').forEach(function (b) {
    b.addEventListener('click', function () {
        document.querySelectorAll('.role-form .perm-group').forEach(function (g) {
            var r = g.querySelectorAll('input[type=radio]');
            (b.dataset.levelAll === 'max' ? r[r.length - 1] : r[0]).checked = true;
        });
    });
});
</script>
<?php else: ?>

<div class="card">
    <div class="table-container">
        <table>
            <thead><tr><th>Role</th><th>Description</th><th>Access levels</th><th class="num">Users</th><th>Sign-ups</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($roles as $r): $sys = (int)$r['is_system']; ?>
                <tr>
                    <td style="font-weight:600"><?php echo htmlspecialchars($r['name']); ?><?php echo $sys ? ' <span class="status-ordered">Locked</span>' : ''; ?></td>
                    <td class="muted"><?php echo htmlspecialchars($r['description'] ?: '—'); ?></td>
                    <td><?php if ($sys): ?><span class="level-chip full">Full access everywhere</span>
                        <?php elseif (!$r['summary']): ?><span class="muted-text">No access</span>
                        <?php else: foreach ($r['summary'] as [$al, $ln, $top]): ?><span class="level-chip<?php echo $top ? ' full' : ''; ?>"><?php echo htmlspecialchars($al); ?>: <b><?php echo htmlspecialchars($ln); ?></b></span><?php endforeach; endif; ?></td>
                    <td class="num"><a href="users.php?role_id=<?php echo $r['id']; ?>"><?php echo (int)$r['users']; ?></a></td>
                    <td><?php if ((int)$r['is_default']): ?><span class="status-receiving">Default</span>
                        <?php elseif (!$sys): ?>
                        <form method="POST" class="inline-form" data-confirm-title="Default role for sign-ups"
                              data-confirm="Give the <?php echo htmlspecialchars($r['name']); ?> role to people who create their own account?" data-confirm-ok="Make default">
                            <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                            <button type="submit" name="role_action" value="default" class="btn btn-sm">Make default</button>
                        </form>
                        <?php else: ?><span class="muted-text">—</span><?php endif; ?>
                    </td>
                    <td><div class="row-actions">
                        <a href="roles.php?edit=<?php echo $r['id']; ?>" class="btn btn-icon" aria-label="<?php echo $sys ? 'View' : 'Edit'; ?> <?php echo htmlspecialchars($r['name']); ?>" data-tip="<?php echo $sys ? 'View' : 'Edit'; ?>"><?php echo row_icon($sys ? 'view' : 'edit'); ?></a>
                        <?php if (!$sys && (int)$r['users'] === 0 && !(int)$r['is_default']): ?>
                        <form method="POST" class="inline-form" data-confirm-title="Delete role"
                              data-confirm="Delete the <?php echo htmlspecialchars($r['name']); ?> role? This cannot be undone." data-confirm-ok="Delete" data-confirm-danger="1">
                            <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                            <button type="submit" name="role_action" value="delete" class="btn btn-icon btn-icon-danger" aria-label="Delete <?php echo htmlspecialchars($r['name']); ?>" data-tip="Delete"><?php echo row_icon('delete'); ?></button>
                        </form>
                        <?php else: ?>
                        <span class="btn btn-icon" aria-disabled="true" role="img" aria-label="Can't delete" data-tip="<?php echo $sys ? 'System Admin can\'t be deleted' : ((int)$r['users'] ? 'Has users; move them first' : 'Default for sign-ups'); ?>"><?php echo row_icon('delete'); ?></span>
                        <?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
