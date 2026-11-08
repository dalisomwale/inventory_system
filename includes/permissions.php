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
    'requisitions' => ['Requisitions', 'What needs buying', [
        ['No access',      "Can't open Requisitions.", []],
        ['View',           'See what has been requested, ordered and received.', ['requisitions.view']],
        ['Raise & update', 'Everything in View, plus raise requisitions, mark them ordered and cancel them.', ['requisitions.view', 'requisitions.manage']],
    ]],
    'categories' => ['Categories', 'Stock categories', [
        ['No access', "Can't open Categories.", []],
        ['Manage',    'Add, rename and delete categories.', ['categories.manage']],
    ]],
    'transactions' => ['Transactions', 'Every stock movement by date', [
        ['No access', "Can't open Transactions.", []],
        ['View',      'See every receipt and issue, filtered by date, item and category.', ['transactions.view']],
    ]],
    'reports' => ['Monthly reports', 'Month-end category reports', [
        ['No access',     "Can't open Monthly Reports.", []],
        ['View',          'See submitted monthly reports and category activity.', ['reports.view']],
        ['View & submit', 'Everything in View, plus submit or update the month-end report for a category.', ['reports.view', 'reports.submit']],
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
    foreach (ACCESS_AREAS as [, , $levels]) foreach ($levels as [, , $perms]) $all = array_merge($all, $perms);
    return array_values(array_unique($all));
}

/** Access rights for a set of levels, e.g. ['stock' => 2, 'reports' => 1]. */
function perms_for_levels(array $levels): array {
    $perms = [];
    foreach (ACCESS_AREAS as $area => [, , $opts]) {
        $lvl = max(0, min(count($opts) - 1, (int)($levels[$area] ?? 0)));
        $perms = array_merge($perms, $opts[$lvl][2]);
    }
    return array_values(array_unique($perms));
}

/** The level in each area that a set of rights gives (highest level fully covered). */
function levels_from_perms(array $perms): array {
    $out = [];
    foreach (ACCESS_AREAS as $area => [, , $opts]) {
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
