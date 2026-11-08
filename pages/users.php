<?php
// Users — add accounts, choose their role, disable/enable, reset passwords, delete.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/insights.php';
requirePermission('admin.users');

$role_rows = $pdo->query("SELECT id, name, is_system, is_default FROM roles ORDER BY is_system DESC, name")->fetchAll();
$roles = []; foreach ($role_rows as $r) $roles[(string)$r['id']] = $r['name'];
$admin_role_ids = array_map(fn($r) => (int)$r['id'], array_filter($role_rows, fn($r) => (int)$r['is_system']));
$default_role = 0; foreach ($role_rows as $r) if ((int)$r['is_default']) $default_role = (int)$r['id'];
$filter_role = (int)($_GET['role_id'] ?? 0);
$me    = (int)$_SESSION['user_id'];

if (!empty($_SESSION['flash']))       { $success = htmlspecialchars($_SESSION['flash']);       unset($_SESSION['flash']); }
if (!empty($_SESSION['flash_error'])) { $error   = htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); }

function back_to_users(): void { header('Location: users.php'); exit(); }

/** Number of other active admins besides $id. */
function other_admins(PDO $pdo, int $id): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.is_system = 1 AND u.active = 1 AND u.id <> ?");
    $st->execute([$id]);
    return (int)$st->fetchColumn();
}

/** Password rules shared with signup. */
function password_problem(string $pw): ?string {
    if (strlen($pw) < 8) return 'Password must be at least 8 characters.';
    if (!preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) return 'Password must contain at least one letter and one number.';
    return null;
}

// ── Add user ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $v     = new Validator($_POST);
    $name  = $v->text('full_name', 'Full name', 150, true, 3);
    $email = $v->email('email', 'Work email');
    $role  = (int)$v->choice('role_id', 'role', array_map('strval', array_keys($roles)));
    $pw    = (string)($_POST['password'] ?? '');
    [$cid, $bid, $did] = org_validate($pdo, $v, $_POST);
    if ($v->ok() && !email_domain_allowed($email)) $v->fail('Use an Altus work email (' . implode(', ', array_map(fn($d) => '@' . $d, ALLOWED_EMAIL_DOMAINS)) . ').');
    if ($p = password_problem($pw)) $v->fail($p);
    if ($v->ok()) {
        $d = $pdo->prepare("SELECT id FROM users WHERE email = ?"); $d->execute([$email]);
        if ($d->fetch()) $v->fail("An account for $email already exists.");
    }
    if ($v->ok()) {
        $pdo->prepare("INSERT INTO users (full_name, email, password, role, role_id, active, company_id, branch_id, department_id) VALUES (?,?,?,?,?,1,?,?,?)")
            ->execute([$name, $email, password_hash($pw, PASSWORD_DEFAULT), in_array($role, $admin_role_ids, true) ? 'admin' : 'staff', $role, $cid, $bid, $did]);
        logAudit($pdo, $me, 'USER_CREATED', null, null, "Created $email ({$roles[$role]})");
        $_SESSION['flash'] = "Account created for $name ($email). Give them the password you set; they can change it in Settings.";
        back_to_users();
    }
    $error = $v->message(); $form = 'add';
}

// ── Edit user (name, email, role) ───────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
    $v     = new Validator($_POST);
    $u     = $v->exists($pdo, 'id', 'user', 'users', 'id, email, role_id, active');
    $name  = $v->text('full_name', 'Full name', 150, true, 3);
    $email = $v->email('email', 'Work email');
    $role  = (int)$v->choice('role_id', 'role', array_map('strval', array_keys($roles)));
    [$cid, $bid, $did] = org_validate($pdo, $v, $_POST);
    if ($v->ok()) {
        $d = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ?"); $d->execute([$email, $u['id']]);
        if ($d->fetch()) $v->fail("Another account already uses $email.");
        if ($v->ok() && (int)$u['id'] === $me && $role !== (int)$u['role_id']) {
            $v->fail("You can't change your own role.");
        } elseif (in_array((int)$u['role_id'], $admin_role_ids, true) && !in_array($role, $admin_role_ids, true)) {
            if ((int)$u['id'] === $me)                 $v->fail("You can't remove your own System Admin role.");
            elseif (other_admins($pdo, (int)$u['id']) === 0) $v->fail('There must always be at least one System Admin.');
        }
    }
    if ($v->ok()) {
        $pdo->prepare("UPDATE users SET full_name=?, email=?, role=?, role_id=?, company_id=?, branch_id=?, department_id=? WHERE id=?")
            ->execute([$name, $email, in_array($role, $admin_role_ids, true) ? 'admin' : 'staff', $role, $cid, $bid, $did, $u['id']]);
        $what = (int)$u['role_id'] !== $role ? " role → {$roles[$role]}" : '';
        logAudit($pdo, $me, 'USER_UPDATED', null, null, "Updated {$u['email']}$what");
        $_SESSION['flash'] = "$name saved.";
        back_to_users();
    }
    $error = $v->message(); $form = 'edit'; $_GET['edit'] = (int)($_POST['id'] ?? 0);
}

// ── Reset password ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_password'])) {
    $v  = new Validator($_POST);
    $u  = $v->exists($pdo, 'id', 'user', 'users', 'id, full_name, email');
    $pw = (string)($_POST['new_password'] ?? '');
    if ($p = password_problem($pw)) $v->fail($p);
    if ($v->ok() && $pw !== (string)($_POST['confirm_password'] ?? '')) $v->fail('The passwords do not match.');
    if ($v->ok()) {
        $pdo->prepare("UPDATE users SET password=? WHERE id=?")->execute([password_hash($pw, PASSWORD_DEFAULT), $u['id']]);
        logAudit($pdo, $me, 'USER_PASSWORD_RESET', null, null, "Reset password for {$u['email']}");
        $_SESSION['flash'] = "Password reset for {$u['full_name']}.";
        back_to_users();
    }
    $error = $v->message(); $form = 'reset'; $_GET['reset'] = (int)($_POST['id'] ?? 0);
}

// ── Disable / enable / delete ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['user_action'])) {
    $v      = new Validator($_POST);
    $action = $v->choice('user_action', 'action', ['disable', 'enable', 'delete']);
    $u      = $v->exists($pdo, 'id', 'user', 'users', 'id, full_name, email, role_id, active');
    if ($v->ok() && $action !== 'enable') {
        if ((int)$u['id'] === $me) $v->fail("You can't " . $action . " your own account.");
        elseif (in_array((int)$u['role_id'], $admin_role_ids, true) && other_admins($pdo, (int)$u['id']) === 0) $v->fail('There must always be at least one System Admin.');
    }
    if ($v->ok()) {
        if ($action === 'delete') {
            try {
                $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$u['id']]);
                logAudit($pdo, $me, 'USER_DELETED', null, null, "Deleted {$u['email']}");
                $_SESSION['flash'] = "{$u['full_name']}'s account deleted.";
            } catch (PDOException $e) {
                $_SESSION['flash_error'] = "{$u['full_name']} has stock records, so the account can't be deleted. Disable it instead.";
            }
        } else {
            $pdo->prepare("UPDATE users SET active=? WHERE id=?")->execute([$action === 'enable' ? 1 : 0, $u['id']]);
            logAudit($pdo, $me, $action === 'enable' ? 'USER_ENABLED' : 'USER_DISABLED', null, null, ucfirst($action) . "d {$u['email']}");
            $_SESSION['flash'] = "{$u['full_name']}'s account " . ($action === 'enable' ? 'enabled' : 'disabled') . '.';
        }
    } else {
        $_SESSION['flash_error'] = implode(' ', $v->errors());
    }
    back_to_users();
}

// ── Data ────────────────────────────────────────────────────
$users = $pdo->query("
    SELECT u.id, u.full_name, u.email, u.role_id, rl.name AS role_name, rl.is_system, u.active, u.last_login_at, u.created_at,
           u.company_id, u.branch_id, u.department_id, b.name AS branch_name, d.name AS department_name, c.name AS company_name,
           (SELECT COUNT(*) FROM stock_receiving WHERE received_by = u.id) + (SELECT COUNT(*) FROM stock_issuing WHERE issued_by = u.id)
         + (SELECT COUNT(*) FROM procurement WHERE requested_by = u.id OR approved_by = u.id)
         + (SELECT COUNT(*) FROM category_monthly_reports WHERE submitted_by = u.id) AS records
    FROM users u
    LEFT JOIN roles rl ON rl.id = u.role_id
    LEFT JOIN companies c ON c.id = u.company_id
    LEFT JOIN branches b ON b.id = u.branch_id
    LEFT JOIN departments d ON d.id = u.department_id
    " . ($filter_role ? "WHERE u.role_id = " . $filter_role : "") . "
    ORDER BY u.active DESC, rl.is_system DESC, u.full_name")->fetchAll();

$editing = $resetting = null;
foreach ($users as $u) {
    if ((int)($_GET['edit'] ?? 0) === (int)$u['id'])  $editing = $u;
    if ((int)($_GET['reset'] ?? 0) === (int)$u['id']) $resetting = $u;
}
$val = fn($k, $d = '') => ($form ?? '') !== '' ? ($_POST[$k] ?? $d) : $d;

$breadcrumbs = [['Users', ($editing || $resetting) ? 'users.php' : null]];
if ($editing)   $breadcrumbs[] = ['Edit ' . $editing['full_name'], null];
if ($resetting) $breadcrumbs[] = ['Reset password', null];
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Users</h1>
    <span class="card-hint"><?php echo count($users); ?> account<?php echo count($users) === 1 ? '' : 's'; ?><?php echo $filter_role ? ' with the ' . htmlspecialchars($roles[$filter_role] ?? '') . ' role &middot; <a href="users.php">Show all</a>' : ''; ?> &middot; What each role can do is set under <a href="roles.php">Roles</a></span>
</div>

<?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
<?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<?php if ($editing): ?>
<div class="form-container wide">
    <div class="form-title">Edit <?php echo htmlspecialchars($editing['full_name']); ?></div>
    <form method="POST" data-confirm-title="Save user" data-confirm="Save the changes to {full_name} ({email}) as {role_id}?" data-confirm-ok="Save changes">
        <input type="hidden" name="edit_user" value="1"><input type="hidden" name="id" value="<?php echo $editing['id']; ?>">
        <div class="form-row">
            <div class="form-group"><label for="full_name">Full name *</label>
                <input type="text" id="full_name" name="full_name" required minlength="3" maxlength="150" value="<?php echo htmlspecialchars($val('full_name', $editing['full_name'])); ?>"></div>
            <div class="form-group"><label for="email">Work email *</label>
                <input type="email" id="email" name="email" required maxlength="190" value="<?php echo htmlspecialchars($val('email', $editing['email'])); ?>"></div>
        </div>
        <div class="form-group" style="max-width:300px"><label for="role_id">Role *</label>
            <select id="role_id" name="role_id" required <?php echo (int)$editing['id'] === $me ? 'disabled' : ''; ?>>
                <?php foreach ($roles as $k => $label): ?><option value="<?php echo $k; ?>" <?php echo (int)$val('role_id', $editing['role_id']) === (int)$k ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?>
            </select>
            <?php if ((int)$editing['id'] === $me): ?><input type="hidden" name="role_id" value="<?php echo (int)$editing['role_id']; ?>"><small>You can't change your own role.</small><?php endif; ?>
        </div>
        <?php echo org_pickers($pdo, ($form ?? '') ? $_POST : $editing); ?>
        <div class="form-actions"><button type="submit" class="btn btn-primary">Save changes</button><a href="users.php" class="btn">Cancel</a></div>
    </form>
</div>
<?php elseif ($resetting): ?>
<div class="form-container wide">
    <div class="form-title">Reset password for <?php echo htmlspecialchars($resetting['full_name']); ?></div>
    <form method="POST" data-confirm-title="Reset password" data-confirm="Set a new password for <?php echo htmlspecialchars($resetting['full_name']); ?>? Their old password will stop working." data-confirm-ok="Reset password" data-confirm-danger="1">
        <input type="hidden" name="reset_password" value="1"><input type="hidden" name="id" value="<?php echo $resetting['id']; ?>">
        <div class="form-row">
            <div class="form-group"><label for="new_password">New password *</label>
                <input type="password" id="new_password" name="new_password" required minlength="8" maxlength="128" autocomplete="new-password"
                       pattern="(?=.*[A-Za-z])(?=.*\d).{8,}" title="At least 8 characters, with a letter and a number.">
                <small>At least 8 characters, with a letter and a number.</small></div>
            <div class="form-group"><label for="confirm_password">Confirm new password *</label>
                <input type="password" id="confirm_password" name="confirm_password" required data-match="new_password" autocomplete="new-password"></div>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn-primary">Reset password</button><a href="users.php" class="btn">Cancel</a></div>
    </form>
</div>
<?php else: ?>
<div class="form-container wide">
    <div class="form-title">Add user</div>
    <form method="POST" data-confirm-title="Add user" data-confirm="Create an account for {full_name} ({email}) as {role_id} at {branch_id}?" data-confirm-ok="Add user">
        <input type="hidden" name="add_user" value="1">
        <div class="form-row">
            <div class="form-group"><label for="full_name">Full name *</label>
                <input type="text" id="full_name" name="full_name" required minlength="3" maxlength="150" value="<?php echo htmlspecialchars($val('full_name')); ?>"></div>
            <div class="form-group"><label for="email">Work email *</label>
                <input type="email" id="email" name="email" required maxlength="190" placeholder="name@altus.co.zm" value="<?php echo htmlspecialchars($val('email')); ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label for="role_id">Role *</label>
                <select id="role_id" name="role_id" required>
                    <?php foreach ($roles as $k => $label): ?><option value="<?php echo $k; ?>" <?php echo (int)$val('role_id', $default_role) === (int)$k ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option><?php endforeach; ?>
                </select>
                <small><a href="roles.php">Roles and access rights</a></small></div>
            <div class="form-group"><label for="password">Temporary password *</label>
                <input type="password" id="password" name="password" required minlength="8" maxlength="128" autocomplete="new-password"
                       pattern="(?=.*[A-Za-z])(?=.*\d).{8,}" title="At least 8 characters, with a letter and a number.">
                <small>Give this to the person; they can change it in Settings.</small></div>
        </div>
        <?php echo org_pickers($pdo, ($form ?? '') ? $_POST : []); ?>
        <div class="form-actions"><button type="submit" class="btn btn-primary">Add user</button></div>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <div class="table-container">
        <table>
            <thead><tr><th>Name</th><th>Email</th><th>Branch &middot; Department</th><th>Role</th><th>Status</th><th>Last sign-in</th><th>Date added</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($users as $u): $self = (int)$u['id'] === $me; ?>
                <tr class="<?php echo $u['active'] ? '' : 'row-disabled'; ?>">
                    <td style="font-weight:600"><?php echo htmlspecialchars($u['full_name']); ?><?php echo $self ? ' <span class="muted-text">(you)</span>' : ''; ?></td>
                    <td><?php echo htmlspecialchars($u['email'] ?? '—'); ?></td>
                    <td><?php echo $u['branch_name'] ? htmlspecialchars($u['branch_name']) . ($u['department_name'] ? ' &middot; ' . htmlspecialchars($u['department_name']) : '') . '<div class="item-desc">' . htmlspecialchars($u['company_name'] ?? '') . '</div>' : '<span class="muted-text">Not set</span>'; ?></td>
                    <td><?php echo $u['role_name'] ? ((int)$u['is_system'] ? '<span class="status-ordered">' . htmlspecialchars($u['role_name']) . '</span>' : '<span class="cat-pill">' . htmlspecialchars($u['role_name']) . '</span>') : '<span class="status-issuing">No role</span>'; ?></td>
                    <td><?php echo $u['active'] ? '<span class="status-receiving">Active</span>' : '<span class="status-issuing">Disabled</span>'; ?></td>
                    <td class="muted" style="white-space:nowrap"><?php echo $u['last_login_at'] ? date('d M Y, H:i', strtotime($u['last_login_at'])) : 'Never'; ?></td>
                    <td class="muted" style="white-space:nowrap"><?php echo date('d M Y', strtotime($u['created_at'])); ?></td>
                    <td><div class="row-actions">
                        <a href="users.php?edit=<?php echo $u['id']; ?>" class="btn btn-icon" aria-label="Edit <?php echo htmlspecialchars($u['full_name']); ?>" data-tip="Edit"><?php echo row_icon('edit'); ?></a>
                        <a href="users.php?reset=<?php echo $u['id']; ?>" class="btn btn-icon" aria-label="Reset password for <?php echo htmlspecialchars($u['full_name']); ?>" data-tip="Reset password"><?php echo row_icon('key'); ?></a>
                        <?php if (!$self): ?>
                        <form method="POST" class="inline-form"
                              data-confirm-title="<?php echo $u['active'] ? 'Disable account' : 'Enable account'; ?>"
                              data-confirm="<?php echo $u['active'] ? 'Disable ' . htmlspecialchars($u['full_name']) . '? They will be signed out and can\'t sign in until enabled again.' : 'Enable ' . htmlspecialchars($u['full_name']) . '? They will be able to sign in again.'; ?>"
                              data-confirm-ok="<?php echo $u['active'] ? 'Disable' : 'Enable'; ?>" <?php echo $u['active'] ? 'data-confirm-danger="1"' : ''; ?>>
                            <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                            <button type="submit" name="user_action" value="<?php echo $u['active'] ? 'disable' : 'enable'; ?>" class="btn btn-icon"
                                    aria-label="<?php echo ($u['active'] ? 'Disable ' : 'Enable ') . htmlspecialchars($u['full_name']); ?>" data-tip="<?php echo $u['active'] ? 'Disable' : 'Enable'; ?>">
                                <?php echo row_icon($u['active'] ? 'lock' : 'unlock'); ?></button>
                        </form>
                        <?php if ((int)$u['records'] === 0): ?>
                        <form method="POST" class="inline-form" data-confirm-title="Delete account"
                              data-confirm="Delete <?php echo htmlspecialchars($u['full_name']); ?>'s account? This cannot be undone." data-confirm-ok="Delete" data-confirm-danger="1">
                            <input type="hidden" name="id" value="<?php echo $u['id']; ?>">
                            <button type="submit" name="user_action" value="delete" class="btn btn-icon btn-icon-danger" aria-label="Delete <?php echo htmlspecialchars($u['full_name']); ?>" data-tip="Delete"><?php echo row_icon('delete'); ?></button>
                        </form>
                        <?php else: ?>
                        <span class="btn btn-icon" aria-disabled="true" role="img" aria-label="Can't delete: has stock records" data-tip="Can't delete: has stock records. Disable instead."><?php echo row_icon('delete'); ?></span>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
