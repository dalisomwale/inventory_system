<?php
/**
 * Organisation helpers: companies > branches > departments, and stock held per branch.
 * The structure itself is managed by the System Admin on the Organisation page.
 */

/** All branches with their company name. */
function org_branches(PDO $pdo, bool $active_only = true): array {
    return $pdo->query("
        SELECT b.id, b.name, b.location, b.active, b.company_id, c.name AS company_name
        FROM branches b JOIN companies c ON c.id = b.company_id
        " . ($active_only ? "WHERE b.active = 1 AND c.active = 1" : "") . "
        ORDER BY c.name, b.name")->fetchAll();
}

/** All departments with their branch and company. */
function org_departments(PDO $pdo, bool $active_only = true): array {
    return $pdo->query("
        SELECT d.id, d.name, d.active, d.branch_id, b.name AS branch_name, b.company_id, c.name AS company_name
        FROM departments d JOIN branches b ON b.id = d.branch_id JOIN companies c ON c.id = b.company_id
        " . ($active_only ? "WHERE d.active = 1 AND b.active = 1 AND c.active = 1" : "") . "
        ORDER BY c.name, b.name, d.name")->fetchAll();
}

function org_companies(PDO $pdo, bool $active_only = true): array {
    return $pdo->query("SELECT id, name, active FROM companies " . ($active_only ? "WHERE active = 1 " : "") . "ORDER BY name")->fetchAll();
}

/** "Credit — Lusaka" style label for a department row. */
function dept_label(array $d): string {
    return $d['name'] . ' — ' . $d['branch_name'];
}

/** The signed-in user's branch, if they have one. */
function user_branch_id(): int {
    global $pdo;
    $st = $pdo->prepare("SELECT branch_id FROM users WHERE id = ?");
    $st->execute([$_SESSION['user_id'] ?? 0]);
    return (int)$st->fetchColumn();
}

/** True if there is more than one active branch (so branch pickers are worth showing). */
function multi_branch(PDO $pdo): bool {
    return (int)$pdo->query("SELECT COUNT(*) FROM branches b JOIN companies c ON c.id=b.company_id WHERE b.active=1 AND c.active=1")->fetchColumn() > 1;
}

/**
 * Change an item's stock at one branch by $delta (positive = received, negative = issued).
 * Must be called inside a transaction. Locks the rows so two people can't over-issue.
 * Returns [old_branch_qty, new_branch_qty]. Throws RuntimeException if there isn't enough.
 */
function branch_stock_change(PDO $pdo, int $item_id, int $branch_id, int $delta): array {
    // Lock in a fixed order (item, then branch row) to avoid deadlocks
    $pdo->prepare("SELECT id FROM items WHERE id = ? FOR UPDATE")->execute([$item_id]);
    $pdo->prepare("INSERT IGNORE INTO branch_stock (item_id, branch_id, quantity) VALUES (?, ?, 0)")->execute([$item_id, $branch_id]);
    $st = $pdo->prepare("SELECT quantity FROM branch_stock WHERE item_id = ? AND branch_id = ? FOR UPDATE");
    $st->execute([$item_id, $branch_id]);
    $old = (int)$st->fetchColumn();
    $new = $old + $delta;
    if ($new < 0) {
        throw new RuntimeException("Only $old in stock at this branch. Stock may have changed while you were working.");
    }
    $pdo->prepare("UPDATE branch_stock SET quantity = ? WHERE item_id = ? AND branch_id = ?")->execute([$new, $item_id, $branch_id]);
    $pdo->prepare("UPDATE items SET current_quantity = current_quantity + ? WHERE id = ?")->execute([$delta, $item_id]);
    return [$old, $new];
}

/** Stock of one item at one branch. */
function branch_qty(PDO $pdo, int $item_id, int $branch_id): int {
    $st = $pdo->prepare("SELECT quantity FROM branch_stock WHERE item_id = ? AND branch_id = ?");
    $st->execute([$item_id, $branch_id]);
    return (int)$st->fetchColumn();
}

/**
 * Linked company / branch / department dropdowns. Picking a company narrows the branches,
 * picking a branch narrows the departments (script.js does the narrowing).
 * $sel = ['company_id'=>, 'branch_id'=>, 'department_id'=>]; $req = which ones are required.
 */
function org_pickers(PDO $pdo, array $sel = [], array $req = ['company_id', 'branch_id'], bool $show_department = true): string {
    $companies = org_companies($pdo);
    $branches  = org_branches($pdo);
    $depts     = org_departments($pdo);
    $star      = fn($k) => in_array($k, $req, true) ? ' *' : '';
    $r         = fn($k) => in_array($k, $req, true) ? ' required' : '';
    $h  = '<div class="org-pickers" data-org-pickers>';
    $h .= '<div class="form-group"><label for="company_id">Company' . $star('company_id') . '</label><select id="company_id" name="company_id" data-org="company"' . $r('company_id') . '><option value="">— Select —</option>';
    foreach ($companies as $c) {
        $h .= '<option value="' . $c['id'] . '"' . ((int)($sel['company_id'] ?? 0) === (int)$c['id'] ? ' selected' : '') . '>' . htmlspecialchars($c['name']) . '</option>';
    }
    $h .= '</select></div>';
    $h .= '<div class="form-group"><label for="branch_id">Branch' . $star('branch_id') . '</label><select id="branch_id" name="branch_id" data-org="branch"' . $r('branch_id') . '><option value="">— Select —</option>';
    foreach ($branches as $b) {
        $h .= '<option value="' . $b['id'] . '" data-company="' . $b['company_id'] . '"' . ((int)($sel['branch_id'] ?? 0) === (int)$b['id'] ? ' selected' : '') . '>' . htmlspecialchars($b['name']) . '</option>';
    }
    $h .= '</select></div>';
    if ($show_department) {
        $h .= '<div class="form-group"><label for="department_id">Department' . $star('department_id') . '</label><select id="department_id" name="department_id" data-org="department"' . $r('department_id') . '><option value="">' . (in_array('department_id', $req, true) ? '— Select —' : '— None —') . '</option>';
        foreach ($depts as $d) {
            $h .= '<option value="' . $d['id'] . '" data-branch="' . $d['branch_id'] . '"' . ((int)($sel['department_id'] ?? 0) === (int)$d['id'] ? ' selected' : '') . '>' . htmlspecialchars($d['name']) . '</option>';
        }
        $h .= '</select></div>';
    }
    return $h . '</div>';
}

/**
 * Validate company/branch/department ids from a form and check they belong together.
 * Returns [company_id|null, branch_id|null, department_id|null].
 */
function org_validate(PDO $pdo, Validator $v, array $post, array $req = ['company_id', 'branch_id']): array {
    $cid = (int)($post['company_id'] ?? 0); $bid = (int)($post['branch_id'] ?? 0); $did = (int)($post['department_id'] ?? 0);
    foreach (['company_id' => [$cid, 'company'], 'branch_id' => [$bid, 'branch'], 'department_id' => [$did, 'department']] as $k => [$id, $label]) {
        if (!$id && in_array($k, $req, true)) $v->fail("Please choose a $label.");
    }
    if ($bid) {
        $st = $pdo->prepare("SELECT company_id FROM branches WHERE id = ? AND active = 1");
        $st->execute([$bid]);
        $bc = $st->fetchColumn();
        if ($bc === false)               $v->fail('The chosen branch is not available.');
        elseif ($cid && (int)$bc !== $cid) $v->fail('The branch does not belong to the chosen company.');
        else $cid = (int)$bc;
    }
    if ($did) {
        $st = $pdo->prepare("SELECT branch_id FROM departments WHERE id = ? AND active = 1");
        $st->execute([$did]);
        $db = $st->fetchColumn();
        if ($db === false)                 $v->fail('The chosen department is not available.');
        elseif ($bid && (int)$db !== $bid) $v->fail('The department does not belong to the chosen branch.');
    }
    return [$cid ?: null, $bid ?: null, $did ?: null];
}
