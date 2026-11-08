<?php
// Manage Stock — every item's stock level in one place, for both roles.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/insights.php';
requirePermission('stock.view');

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

// ── Filters (all from the query string, all sanitised) ──────
$q       = is_string($_GET['q'] ?? null) ? trim(mb_substr($_GET['q'], 0, 100)) : '';
$cat_id  = (int)($_GET['category_id'] ?? 0);
$status  = in_array($_GET['status'] ?? '', ['ok', 'low', 'out', 'soon'], true) ? $_GET['status'] : '';
// Department-limited users see only what was issued to their department
$scope_dept = my_scope_department();
$scoped     = $scope_dept !== null;
$sorts   = $scoped
    ? ['name' => 'Name', 'issued' => 'Most issued', 'value' => 'Value (highest first)', 'last' => 'Last issued (newest first)']
    : ['name' => 'Name', 'stock' => 'Stock (lowest first)', 'value' => 'Value (highest first)', 'added' => 'Date added (newest first)'];
if ($scoped) $status = '';
$sort    = array_key_exists($_GET['sort'] ?? '', $sorts) ? $_GET['sort'] : 'name';
$companies   = org_companies($pdo);
$branches    = org_branches($pdo);
$departments = org_departments($pdo);
$company_id  = (int)($_GET['company_id'] ?? 0);
$branch_id   = (int)($_GET['branch_id'] ?? 0);
$dept_id     = (int)($_GET['department_id'] ?? 0);
if (!in_array($company_id, array_map(fn($c) => (int)$c['id'], $companies), true)) $company_id = 0;
$branch = null; $dept = null;
foreach ($branches as $b) if ((int)$b['id'] === $branch_id && (!$company_id || (int)$b['company_id'] === $company_id)) $branch = $b;
$branch_id = $branch ? (int)$branch['id'] : 0;
foreach ($departments as $d) if ((int)$d['id'] === $dept_id && (!$company_id || (int)$d['company_id'] === $company_id) && (!$branch_id || (int)$d['branch_id'] === $branch_id)) $dept = $d;
$dept_id = $dept ? (int)$dept['id'] : 0;
if ($scoped) {
    $company_id = 0; $branch = null; $branch_id = 0;
    $sd = $pdo->prepare("SELECT d.id, d.name, d.branch_id, b.name AS branch_name, b.company_id FROM departments d JOIN branches b ON b.id = d.branch_id WHERE d.id = ?");
    $sd->execute([$scope_dept]);
    $dept    = $sd->fetch() ?: null;
    $dept_id = $scope_dept;
}
// A department's stock is held at its branch
if ($dept && !$branch_id) { $branch_id = (int)$dept['branch_id']; foreach ($branches as $b) if ((int)$b['id'] === $branch_id) $branch = $b; }
$company_name = '';
foreach ($companies as $c) if ((int)$c['id'] === $company_id) $company_name = $c['name'];
$branch_name = $branch['name'] ?? '';
// Branches whose stock counts: one branch, a company's branches, or all
$scope_branches = $branch_id ? [$branch_id] : ($company_id ? array_map(fn($b) => (int)$b['id'], array_filter($branches, fn($b) => (int)$b['company_id'] === $company_id)) : []);
$scope_sql = $scope_branches ? implode(',', array_map('intval', $scope_branches)) : '';

// ── Data ────────────────────────────────────────────────────
$forecast = [];
foreach (ins_item_forecast($pdo, 30, $branch_id) as $r) $forecast[$r['id']] = $r;

$st = $pdo->query("
    SELECT i.id, i.item_name, i.description, i.category_id, COALESCE(c.name, i.category, 'Other') AS category_name,
           i.current_quantity, i.reorder_level, i.unit_price, i.created_at,
           (SELECT COUNT(*) FROM stock_receiving WHERE item_id = i.id) + (SELECT COUNT(*) FROM stock_issuing WHERE item_id = i.id) AS moves,
           GREATEST(COALESCE((SELECT MAX(created_at) FROM stock_receiving WHERE item_id = i.id), '1970-01-01'),
                    COALESCE((SELECT MAX(created_at) FROM stock_issuing   WHERE item_id = i.id), '1970-01-01')) AS last_move
    FROM items i LEFT JOIN categories c ON c.id = i.category_id");
$all = $st->fetchAll();

// Initial stock = the item's first delivery (within the chosen company or branch)
$initial = array_column($pdo->query("SELECT r.item_id, r.quantity FROM stock_receiving r
    JOIN (SELECT item_id, MIN(id) AS id FROM stock_receiving" . ($scope_sql ? " WHERE branch_id IN ($scope_sql)" : "") . " GROUP BY item_id) f ON f.id = r.id")->fetchAll(), 'quantity', 'item_id');
// Stock for a company = the sum over its branches
$company_qty = ($company_id && !$branch_id)
    ? array_column($pdo->query("SELECT item_id, SUM(quantity) AS q FROM branch_stock" . ($scope_sql ? " WHERE branch_id IN ($scope_sql)" : " WHERE 0") . " GROUP BY item_id")->fetchAll(), 'q', 'item_id')
    : null;
// Department: only items issued to it, with how many it has had
$dept_issued = null; $dept_last = [];
if ($dept_id || $scoped) {
    $di = $pdo->prepare("SELECT item_id, SUM(quantity) AS q, MAX(created_at) AS last FROM stock_issuing WHERE department_id = ? GROUP BY item_id");
    $di->execute([$dept_id]);
    $di = $di->fetchAll();
    $dept_issued = array_column($di, 'q', 'item_id');
    $dept_last   = array_column($di, 'last', 'item_id');
}

$rows = [];
foreach ($all as $r) {
    if ($scoped && empty($dept_issued[$r['id']])) continue;
    $f = $forecast[$r['id']] ?? ['days_left' => null, 'per_day' => 0, 'suggest' => 0];
    $r['initial']   = isset($initial[$r['id']]) ? (int)$initial[$r['id']] : null;
    $r['days_left'] = $f['days_left'];
    $r['per_day']   = $f['per_day'];
    $r['suggest']   = $f['suggest'];
    if ($branch_id) $r['current_quantity'] = (int)($f['current_quantity'] ?? 0); // this branch's stock
    elseif ($company_qty !== null) $r['current_quantity'] = (int)($company_qty[$r['id']] ?? 0);
    $r['issued_to_dept'] = $dept_issued !== null ? (int)($dept_issued[$r['id']] ?? 0) : null;
    $r['value']     = $scoped ? $r['issued_to_dept'] * $r['unit_price'] : $r['current_quantity'] * $r['unit_price'];
    $r['last_issued'] = $dept_last[$r['id']] ?? null;
    $r['state']     = $r['current_quantity'] <= 0 ? 'out' : ($r['current_quantity'] <= $r['reorder_level'] ? 'low' : 'ok');
    $r['last_move'] = $r['last_move'] === '1970-01-01 00:00:00' || $r['last_move'] === '1970-01-01' ? null : $r['last_move'];
    $rows[] = $r;
}

// Totals across everything (before filtering)
$tot_items = count($rows);
$tot_units = array_sum(array_column($rows, 'current_quantity'));
$tot_value = array_sum(array_column($rows, 'value'));
$tot_low   = count(array_filter($rows, fn($r) => $r['state'] === 'low'));
$tot_out   = count(array_filter($rows, fn($r) => $r['state'] === 'out'));

// Apply filters
$rows = array_values(array_filter($rows, function ($r) use ($q, $cat_id, $status, $dept_issued) {
    if ($dept_issued !== null && empty($dept_issued[$r['id']])) return false;
    if ($q !== '' && mb_stripos($r['item_name'] . ' ' . $r['description'], $q) === false) return false;
    if ($cat_id && (int)$r['category_id'] !== $cat_id) return false;
    if ($status === 'soon') return $r['state'] === 'ok' && $r['days_left'] !== null && $r['days_left'] <= 14;
    if ($status && $r['state'] !== $status) return false;
    return true;
}));

usort($rows, function ($a, $b) use ($sort) {
    switch ($sort) {
        case 'stock':  return $a['current_quantity'] <=> $b['current_quantity'] ?: strcasecmp($a['item_name'], $b['item_name']);
        case 'value':  return $b['value'] <=> $a['value'];
        case 'added':  return strcmp($b['created_at'], $a['created_at']);
        case 'issued': return $b['issued_to_dept'] <=> $a['issued_to_dept'] ?: strcasecmp($a['item_name'], $b['item_name']);
        case 'last':   return strcmp((string)$b['last_issued'], (string)$a['last_issued']);
        default:       return strcasecmp($a['item_name'], $b['item_name']);
    }
});

if (!empty($_SESSION['flash']))       { $success = htmlspecialchars($_SESSION['flash']);       unset($_SESSION['flash']); }
if (!empty($_SESSION['flash_error'])) { $error   = htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); }

// Keep current filters on links
function stock_url(array $change = []): string {
    $keep = array_intersect_key($_GET, array_flip(['q', 'category_id', 'status', 'sort', 'company_id', 'branch_id', 'department_id']));
    $params = array_filter(array_merge($keep, $change), fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0');
    return 'stock.php' . ($params ? '?' . http_build_query($params) : '');
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1><?php echo $scoped ? 'Stock &mdash; ' . htmlspecialchars($dept['name'] ?? 'your department') : 'Manage Stock'; ?></h1>
</div>

<?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
<?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<!-- Search and filters -->
<form method="GET" class="card stock-filters" id="stockFilters">
    <div class="form-group grow">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Item name or description" maxlength="100">
    </div>
    <?php if (!$scoped && count($companies) > 1): ?>
    <div class="form-group">
        <label for="company_id">Company</label>
        <select id="company_id" name="company_id">
            <option value="">All companies</option>
            <?php foreach ($companies as $c): ?>
            <option value="<?php echo $c['id']; ?>" <?php echo $company_id === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <?php if (!$scoped && count($branches) > 1): ?>
    <div class="form-group">
        <label for="branch_id">Branch</label>
        <select id="branch_id" name="branch_id">
            <option value="">All branches</option>
            <?php foreach ($branches as $b): if ($company_id && (int)$b['company_id'] !== $company_id) continue; ?>
            <option value="<?php echo $b['id']; ?>" <?php echo $branch_id === (int)$b['id'] && !($dept && empty($_GET['branch_id'])) ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <?php if (!$scoped && $departments): ?>
    <div class="form-group">
        <label for="department_id">Department</label>
        <select id="department_id" name="department_id">
            <option value="">All departments</option>
            <?php foreach ($departments as $d):
                if ($company_id && (int)$d['company_id'] !== $company_id) continue;
                if (!empty($_GET['branch_id']) && $branch_id && (int)$d['branch_id'] !== $branch_id) continue; ?>
            <option value="<?php echo $d['id']; ?>" <?php echo $dept_id === (int)$d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(count($branches) > 1 ? dept_label($d) : $d['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="form-group">
        <label for="category_id">Category</label>
        <select id="category_id" name="category_id">
            <option value="">All categories</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?php echo $c['id']; ?>" <?php echo $cat_id === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php if (!$scoped): ?>
    <div class="form-group">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">Any status</option>
            <option value="ok"   <?php echo $status === 'ok'   ? 'selected' : ''; ?>>In stock</option>
            <option value="soon" <?php echo $status === 'soon' ? 'selected' : ''; ?>>Running out in 2 weeks</option>
            <option value="low"  <?php echo $status === 'low'  ? 'selected' : ''; ?>>Below reorder</option>
            <option value="out"  <?php echo $status === 'out'  ? 'selected' : ''; ?>>Out of stock</option>
        </select>
    </div>
    <?php endif; ?>
    <div class="form-group">
        <label for="sort">Sort by</label>
        <select id="sort" name="sort">
            <?php foreach ($sorts as $k => $label): ?>
            <option value="<?php echo $k; ?>" <?php echo $sort === $k ? 'selected' : ''; ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group filter-buttons">
        <noscript><button type="submit" class="btn btn-primary">Apply</button></noscript>
        <?php if ($q !== '' || $cat_id || $status || $sort !== 'name' || (!$scoped && ($branch_id || $company_id || $dept_id))): ?><a href="stock.php" class="btn">Clear</a><?php endif; ?>
    </div>
</form>

<!-- Stock table -->
<div class="card">
    <div class="card-head">
        <h3>
            <?php echo count($rows) === $tot_items ? 'All items' : 'Showing ' . count($rows) . ' of ' . $tot_items . ' items'; ?><?php echo $scoped ? ' issued to ' . htmlspecialchars($dept['name'] ?? 'your department') : ($dept ? ' issued to ' . htmlspecialchars($dept['name']) . ' (stock at ' . htmlspecialchars($branch_name) . ')' : ($branch_id ? ' at ' . htmlspecialchars($branch_name) : ($company_id ? ' at ' . htmlspecialchars($company_name) : ''))); ?>
        </h3>
    </div>

    <?php if ($rows): ?>
    <div class="table-container">
        <table class="stock-table">
            <thead>
                <?php if ($scoped): ?>
                <tr><th>Item</th><th>Category</th><th class="num">Issued to <?php echo htmlspecialchars($dept['name'] ?? 'department'); ?></th><th class="num">Unit price</th><th class="num">Value</th><th>Last issued</th><th></th></tr>
                <?php else: ?>
                <tr>
                    <th>Item</th><th>Category</th><th class="num">Initial stock</th><th class="num">Currently in stock</th><?php if ($dept): ?><th class="num">Issued to <?php echo htmlspecialchars($dept['name']); ?></th><?php endif; ?><th class="num">Reorder at</th>
                    <th class="num">Value</th><th>Date added</th><th></th>
                </tr>
                <?php endif; ?>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr class="row-<?php echo $r['state']; ?>">
                    <td>
                        <a class="item-link" href="stock_item.php?id=<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['item_name']); ?></a>
                        <?php if ($r['description']): ?><div class="item-desc"><?php echo htmlspecialchars($r['description']); ?></div><?php endif; ?>
                    </td>
                    <td><span class="cat-pill"><?php echo htmlspecialchars($r['category_name']); ?></span></td>
                    <?php if ($scoped): ?>
                    <td class="num"><strong><?php echo number_format($r['issued_to_dept']); ?></strong></td>
                    <td class="num muted"><?php echo kwacha((float)$r['unit_price']); ?></td>
                    <td class="num"><?php echo kwacha_whole($r['value']); ?></td>
                    <td class="muted" style="white-space:nowrap"><?php echo $r['last_issued'] ? date('d M Y', strtotime($r['last_issued'])) : '—'; ?></td>
                    <?php else: ?>
                    <td class="num muted"><?php echo $r['initial'] === null ? '—' : number_format($r['initial']); ?></td>
                    <td class="num"><strong class="qty-<?php echo $r['state']; ?>"><?php echo number_format($r['current_quantity']); ?></strong></td>
                    <?php if ($dept): ?><td class="num"><?php echo number_format($r['issued_to_dept']); ?></td><?php endif; ?>
                    <td class="num muted"><?php echo number_format($r['reorder_level']); ?></td>
                    <td class="num"><?php echo kwacha_whole($r['value']); ?></td>
                    <td class="muted" style="white-space:nowrap"><?php echo date('d M Y', strtotime($r['created_at'])); ?></td>
                    <?php endif; ?>
                    <td><div class="row-actions">
                        <a href="stock_item.php?id=<?php echo $r['id']; ?>" class="btn btn-icon" aria-label="View <?php echo htmlspecialchars($r['item_name']); ?>" data-tip="View"><?php echo row_icon('view'); ?></a>
<?php if (can('items.manage')): ?>
                        <a href="stock_edit.php?id=<?php echo $r['id']; ?>" class="btn btn-icon" aria-label="Edit <?php echo htmlspecialchars($r['item_name']); ?>" data-tip="Edit"><?php echo row_icon('edit'); ?></a>
                        <?php if ((int)$r['moves'] === 0): ?>
                        <form method="POST" action="stock_edit.php" class="inline-form"
                              data-confirm-title="Delete item"
                              data-confirm="Delete <?php echo htmlspecialchars($r['item_name']); ?>? This cannot be undone."
                              data-confirm-ok="Delete" data-confirm-danger="1">
                            <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                            <input type="hidden" name="return" value="<?php echo htmlspecialchars(stock_url()); ?>">
                            <button type="submit" name="delete_item" value="1" class="btn btn-icon btn-icon-danger" aria-label="Delete <?php echo htmlspecialchars($r['item_name']); ?>" data-tip="Delete"><?php echo row_icon('delete'); ?></button>
                        </form>
                        <?php else: ?>
                        <span class="btn btn-icon" aria-disabled="true" role="img" aria-label="Can't delete: has stock history" data-tip="Can't delete: has stock history"><?php echo row_icon('delete'); ?></span>
                        <?php endif; ?>
                        <?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php elseif ($scoped && $tot_items === 0): ?>
    <div class="empty-state"><p><?php echo $dept ? 'Nothing has been issued to ' . htmlspecialchars($dept['name']) . ' yet.' : 'Your account has no department yet. Ask the System Admin to set it on Admin › Users.'; ?></p></div>
    <?php elseif ($tot_items === 0): ?>
    <div class="empty-state">
        <p>There are no items yet.</p>
        <?php if (can('items.manage')): ?><a href="stock_edit.php" class="btn btn-primary">Add your first item</a><?php endif; ?>
    </div>
    <?php else: ?>
    <div class="empty-state"><p><?php echo $dept ? 'Nothing has been issued to ' . htmlspecialchars($dept['name']) . ' yet' . (($q !== '' || $cat_id || $status) ? ' that matches these filters' : '') . '.' : 'No items match these filters.'; ?></p><a href="stock.php" class="btn">Clear filters</a></div>
    <?php endif; ?>
</div>

<script>
// Filters apply as soon as they change; the search waits until typing pauses
(function () {
    var form = document.getElementById('stockFilters');
    if (!form) return;
    function go() {
        // Leave empty filters out of the address
        var any = false;
        form.querySelectorAll('input, select').forEach(function (el) {
            if (el.value === '' || (el.name === 'sort' && el.value === 'name')) el.disabled = true; else if (el.name) any = true;
        });
        if (any) form.submit(); else window.location.href = 'stock.php';
    }
    var narrower = { company_id: ['branch_id', 'department_id'], branch_id: ['department_id'] };
    form.querySelectorAll('select').forEach(function (s) {
        s.addEventListener('change', function () {
            (narrower[s.name] || []).forEach(function (n) { if (form.elements[n]) form.elements[n].value = ''; });
            go();
        });
    });
    var q = document.getElementById('q'), timer;
    q.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () {
            try { sessionStorage.setItem('stockSearchFocus', '1'); } catch (e) {}
            go();
        }, 500);
    });
    q.addEventListener('search', function () { if (q.value === '') { clearTimeout(timer); go(); } });
    // Put the cursor back in the search box after the page reloads
    try {
        if (sessionStorage.getItem('stockSearchFocus')) {
            sessionStorage.removeItem('stockSearchFocus');
            q.focus(); var n = q.value.length; q.setSelectionRange(n, n);
        }
    } catch (e) {}
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
