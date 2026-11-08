<?php
/**
 * Access levels. Each role gets one level per area on Admin › Roles.
 * A level includes everything in the levels below it. Underneath, each level is a set
 * of access rights (the strings checked with can()); roles store those rights.
 * The "System Admin" role always has the highest level everywhere and can't be changed.
 */
const ACCESS_AREAS = [
    'stock' => ['Stock', 'Stock levels, receiving and issuing', [
        ['No access',       "Can't open any stock pages.", []],
        ['View',            'See stock levels per branch, item details and stock history.', ['stock.view']],
        ['Receive & issue', 'Everything in View, plus record deliveries (including new items) and issue stock to departments.', ['stock.view', 'stock.receive', 'stock.issue']],
        ['Full control',    'Everything in Receive & issue, plus add, edit and delete items and change prices and reorder levels.', ['stock.view', 'stock.receive', 'stock.issue', 'items.manage']],
    ]],
    // Requisitions: a role can play any number of parts in the workflow, so these are ticks, not levels
    'requisitions' => ['Requisitions', 'Who does what in the requisition workflow, for their own company', [
        ['Raise for their department', 'Raise requisitions for their own department, attach quotations when it isn\'t IT-related, resubmit after a rejection and cancel before approval. Sees their department\'s requisitions.', ['requisitions.raise']],
        ['Raise for any company or department', 'Raise requisitions for any company, branch and department in the group, not just their own. Sees the requisitions they raised.', ['requisitions.raise', 'requisitions.raise_any']],
        ['IT quotations',              'Attach quotations to IT-related requisitions and send them to the Director.', ['requisitions.it_quotes']],
        ['Director approval',          'Approve a requisition and pick the quotation, or reject it back to the department with a reason.', ['requisitions.approve']],
        ['Accounts',                   'Prepare payment for approved requisitions, then attach the proof of payment once the CFO approves.', ['requisitions.accounts']],
        ['Accounts Manager',           'Check payments from Accounts: approve them on to the CFO or send them back to Accounts.', ['requisitions.accounts_review']],
        ['Chief Finance Officer',      'Final approval of payments, or send them back to the Accounts Manager.', ['requisitions.cfo']],
        ['See all requisitions',       "See every requisition in their company at any stage, without taking part.", ['requisitions.view_all']],
    ], 'multi'],
    'categories' => ['Categories', 'Stock categories', [
        ['No access', "Can't open Categories.", []],
        ['Manage',    'Add, rename and delete categories.', ['categories.manage']],
    ]],
    'transactions' => ['Transactions', 'Every stock movement by date', [
        ['No access', "Can't open Transactions.", []],
        ['View',      'See every receipt and issue, filtered by date, item and category.', ['transactions.view']],
    ]],
    'reports' => ['Monthly reports', 'Monthly stock reports and category activity', [
        ['No access', "Can't open Monthly Reports.", []],
        ['View & export', 'See monthly stock reports and category activity for any month, print them and export them to Excel.', ['reports.view']],
    ]],
    // Narrows Stock, Transactions, Reports and the dashboard to what was issued to the user's own department
    'scope' => ['Department limit', 'Whose stock, transactions and reports they see', [
        ['Whole organisation',   'See stock, transactions and reports for every department (as far as their other access allows).', []],
        ['Own department only',  'See only what was issued to their own department: its stock, its transactions and its monthly report. Set the department on Admin › Users.', ['scope.department']],
    ]],
    'organisation' => ['Organisation', 'Companies, branches and departments', [
        ['No access', "Can't open Companies, Branches or Departments.", []],
        ['Manage',    'Add, rename, disable and delete companies, branches and departments.', ['admin.org']],
    ]],
    'users' => ['Users', 'Staff accounts', [
        ['No access', "Can't open Users.", []],
        ['Manage',    'Add users, choose their role and branch, reset passwords, disable or delete accounts.', ['admin.users']],
    ]],
    'roles' => ['Roles', 'Roles and access levels', [
        ['No access', "Can't open Roles.", []],
        ['Manage',    'Create roles and choose the access level each role has in every area.', ['admin.roles']],
    ]],
];

/** Every access right that exists. */
function all_permissions(): array {
    $all = [];
    foreach (ACCESS_AREAS as $a) foreach ($a[2] as [, , $perms]) $all = array_merge($all, $perms);
    return array_values(array_unique($all));
}

function area_is_multi(string $area): bool { return (ACCESS_AREAS[$area][3] ?? '') === 'multi'; }

/** Access rights for a set of levels, e.g. ['stock' => 2, 'reports' => 1, 'requisitions' => [0, 2]]. */
function perms_for_levels(array $levels): array {
    $perms = [];
    foreach (ACCESS_AREAS as $area => [, , $opts]) {
        if (area_is_multi($area)) {
            foreach ((array)($levels[$area] ?? []) as $i) if (isset($opts[(int)$i])) $perms = array_merge($perms, $opts[(int)$i][2]);
            continue;
        }
        $lvl = max(0, min(count($opts) - 1, (int)($levels[$area] ?? 0)));
        $perms = array_merge($perms, $opts[$lvl][2]);
    }
    return array_values(array_unique($perms));
}

/** The level in each area that a set of rights gives (highest level fully covered). */
function levels_from_perms(array $perms): array {
    $out = [];
    foreach (ACCESS_AREAS as $area => [, , $opts]) {
        if (area_is_multi($area)) {
            $out[$area] = [];
            foreach ($opts as $i => [, , $need]) if (!array_diff($need, $perms)) $out[$area][] = $i;
            continue;
        }
        $out[$area] = 0;
        foreach ($opts as $i => [, , $need]) {
            if ($need && !array_diff($need, $perms)) $out[$area] = $i;
        }
    }
    return $out;
}

/** "Stock: Receive & issue" style summary of a role's levels (areas with access only). */
function levels_summary(array $perms): array {
    $levels = levels_from_perms($perms);
    $out = [];
    foreach (ACCESS_AREAS as $area => [$label, , $opts]) {
        if (area_is_multi($area)) {
            if ($levels[$area]) $out[] = [$label, implode(', ', array_map(fn($i) => $opts[$i][0], $levels[$area])), false];
            continue;
        }
        if ($levels[$area] > 0) $out[] = [$label, $opts[$levels[$area]][0], $levels[$area] === count($opts) - 1];
    }
    return $out;
}

/** Does the signed-in user have this access right? */
function can(string $perm): bool {
    return in_array($perm, $_SESSION['perms'] ?? [], true);
}

/** True if the user has any of the given rights. */
function can_any(string ...$perms): bool {
    foreach ($perms as $p) if (can($p)) return true;
    return false;
}

/**
 * The department a department-limited user is held to, or null if they see everything.
 * Returns 0 when they are limited but have no department yet (so they see nothing).
 */
function my_scope_department(): ?int {
    static $d = false;
    if ($d !== false) return $d;
    if (isAdmin() || !can('scope.department')) return $d = null;
    global $pdo;
    $st = $pdo->prepare("SELECT department_id FROM users WHERE id = ?");
    $st->execute([$_SESSION['user_id'] ?? 0]);
    return $d = (int)$st->fetchColumn();
}

/** The name of a department, e.g. for headings. */
function department_name(int $id): string {
    global $pdo;
    $st = $pdo->prepare("SELECT d.name FROM departments d WHERE d.id = ?");
    $st->execute([$id]);
    return (string)$st->fetchColumn();
}

/** Can the user open the Requisitions pages at all? */
function req_can_open(): bool {
    return can_any('requisitions.raise', 'requisitions.it_quotes', 'requisitions.approve', 'requisitions.accounts',
                   'requisitions.accounts_review', 'requisitions.cfo', 'requisitions.view_all', 'stock.receive');
}

/** Stop here unless the signed-in user has the right. */
function requirePermission(string $perm): void {
    redirectIfNotLoggedIn();
    if (!can($perm)) {
        http_response_code(403);
        $_SESSION['flash_error'] = "You don't have access to that. Ask the System Admin if you need it.";
        header('Location: dashboard.php');
        exit();
    }
}

/** Access rights for a role id. */
function role_permissions(PDO $pdo, int $role_id): array {
    $st = $pdo->prepare("SELECT r.is_system, rp.permission FROM roles r LEFT JOIN role_permissions rp ON rp.role_id = r.id WHERE r.id = ?");
    $st->execute([$role_id]);
    $rows = $st->fetchAll();
    if (!$rows) return [];
    if ((int)$rows[0]['is_system']) return all_permissions();   // System Admin: everything, always
    return array_values(array_intersect(all_permissions(), array_column($rows, 'permission')));
}
