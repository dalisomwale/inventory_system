<?php
// Monthly Reports, by category, company, branch or department. Worked out from stock movements for any month,
// to view or export to Excel.
require_once __DIR__ . '/../includes/auth.php';
requirePermission('reports.view');
// Department-limited users get their own department's report
if (my_scope_department() !== null) { require __DIR__ . '/../includes/department_report.php'; exit(); }
require_once __DIR__ . '/../includes/insights.php';

const REPORT_VIEWS = ['category' => 'Category', 'company' => 'Company', 'branch' => 'Branch', 'department' => 'Department'];
$by = array_key_exists($_GET['by'] ?? '', REPORT_VIEWS) ? $_GET['by'] : 'category';
// Older links used category_id
$sel = (int)($_GET['id'] ?? ($by === 'category' ? ($_GET['category_id'] ?? 0) : 0));

$month = safe_month($_GET['month'] ?? null);
if ($month > date('Y-m')) $month = date('Y-m');
$from = $month . '-01';
$to   = date('Y-m-t', strtotime($from));
$month_label = date('F Y', strtotime($from));

/** Work out opening/closing from today's quantity and the movements in and after the month. */
function rpt_finish(array $r): array {
    $r['closing'] = (int)$r['current'] - (int)$r['received_after'] + (int)$r['issued_after'];
    $r['opening'] = $r['closing'] - (int)$r['received'] + (int)$r['issued'];
    $r['value']   = $r['closing'] * (float)$r['unit_price'];
    return $r;
}
// Each use needs its own placeholder names
$in_month = fn(int $n) => "SUM(CASE WHEN DATE(created_at) BETWEEN :f$n AND :t$n THEN quantity END)";
$after    = fn(int $n) => "SUM(CASE WHEN DATE(created_at) > :a$n THEN quantity END)";
$params   = ['f1' => $from, 't1' => $to, 'a1' => $to, 'f2' => $from, 't2' => $to, 'a2' => $to];

$groups = [];   // id => ['name', 'sub', metrics...]
$rows   = [];   // item rows for the chosen group (or all items for the category view)

if ($by === 'department') {
    // Departments don't hold stock: what was issued to each one in the month
    $st = $pdo->prepare("
        SELECT d.id AS gid, d.name, b.name AS branch_name, co.name AS company_name, i.id, i.item_name, COALESCE(c.name, i.category, 'Other') AS category_name,
               i.unit_price, SUM(s.quantity) AS units, SUM(s.quantity * i.unit_price) AS value, COUNT(*) AS times
        FROM stock_issuing s JOIN departments d ON d.id = s.department_id JOIN branches b ON b.id = d.branch_id JOIN companies co ON co.id = b.company_id
        JOIN items i ON i.id = s.item_id LEFT JOIN categories c ON c.id = i.category_id
        WHERE DATE(s.created_at) BETWEEN :f AND :t
        GROUP BY d.id, d.name, branch_name, company_name, i.id, i.item_name, category_name, i.unit_price
        ORDER BY d.name, units DESC");
    $st->execute(['f' => $from, 't' => $to]);
    foreach ($st->fetchAll() as $r) {
        $g = &$groups[(int)$r['gid']];
        $g ??= ['name' => $r['name'], 'branch' => $r['branch_name'], 'company' => $r['company_name'], 'items' => 0, 'units' => 0, 'value' => 0.0, 'times' => 0];
        $g['items']++; $g['units'] += $r['units']; $g['value'] += $r['value']; $g['times'] += $r['times'];
        unset($g);
        $rows[] = $r;   // narrowed to the chosen department below
    }
} elseif ($by === 'category') {
    // Per item, from the item's total stock
    $st = $pdo->prepare("
        SELECT i.id, i.item_name, COALESCE(i.category_id, 0) AS gid, COALESCE(c.name, i.category, 'Other') AS category_name,
               i.current_quantity AS current, i.reorder_level, i.unit_price,
               COALESCE(r.m, 0) AS received, COALESCE(r.a, 0) AS received_after, COALESCE(s.m, 0) AS issued, COALESCE(s.a, 0) AS issued_after
        FROM items i LEFT JOIN categories c ON c.id = i.category_id
        LEFT JOIN (SELECT item_id, {$in_month(1)} AS m, {$after(1)} AS a FROM stock_receiving GROUP BY item_id) r ON r.item_id = i.id
        LEFT JOIN (SELECT item_id, {$in_month(2)} AS m, {$after(2)} AS a FROM stock_issuing GROUP BY item_id) s ON s.item_id = i.id
        WHERE DATE(i.created_at) <= :t3
        ORDER BY category_name, i.item_name");
    $st->execute($params + ['t3' => $to]);
    foreach ($st->fetchAll() as $r) {
        $r = rpt_finish($r);
        $r['status'] = $r['closing'] <= 0 ? 'Out of stock' : ($r['closing'] <= $r['reorder_level'] ? 'Below reorder' : 'OK');
        $g = &$groups[(int)$r['gid']];
        $g ??= ['name' => $r['category_name'], 'sub' => '', 'items' => 0, 'opening' => 0, 'received' => 0, 'issued' => 0, 'closing' => 0, 'value' => 0.0, 'low' => 0];
        $g['items']++;
        foreach (['opening', 'received', 'issued', 'closing', 'value'] as $f) $g[$f] += $r[$f];
        if ($r['status'] !== 'OK') $g['low']++;
        unset($g);
        if (!$sel || (int)$r['gid'] === $sel) $rows[] = $r;
    }
} else {
    // Company and branch: from each branch's own stock and movements
    $st = $pdo->prepare("
        SELECT b.id AS branch_id, b.name AS branch_name, co.id AS company_id, co.name AS company_name,
               i.id, i.item_name, COALESCE(c.name, i.category, 'Other') AS category_name, i.unit_price,
               COALESCE(bs.quantity, 0) AS current,
               COALESCE(r.m, 0) AS received, COALESCE(r.a, 0) AS received_after, COALESCE(s.m, 0) AS issued, COALESCE(s.a, 0) AS issued_after
        FROM branches b JOIN companies co ON co.id = b.company_id
        CROSS JOIN items i LEFT JOIN categories c ON c.id = i.category_id
        LEFT JOIN branch_stock bs ON bs.branch_id = b.id AND bs.item_id = i.id
        LEFT JOIN (SELECT item_id, branch_id, {$in_month(1)} AS m, {$after(1)} AS a FROM stock_receiving GROUP BY item_id, branch_id) r ON r.item_id = i.id AND r.branch_id = b.id
        LEFT JOIN (SELECT item_id, branch_id, {$in_month(2)} AS m, {$after(2)} AS a FROM stock_issuing GROUP BY item_id, branch_id) s ON s.item_id = i.id AND s.branch_id = b.id
        WHERE bs.quantity <> 0 OR r.item_id IS NOT NULL OR s.item_id IS NOT NULL
        ORDER BY co.name, b.name, i.item_name");
    $st->execute($params);
    $per_item = [];   // company view: one row per item within the chosen company
    foreach ($st->fetchAll() as $r) {
        $r = rpt_finish($r);
        if (!$r['opening'] && !$r['received'] && !$r['issued'] && !$r['closing']) continue;
        $gid = $by === 'company' ? (int)$r['company_id'] : (int)$r['branch_id'];
        $g = &$groups[$gid];
        $g ??= ['name' => $by === 'company' ? $r['company_name'] : $r['branch_name'], 'sub' => $by === 'company' ? '' : $r['company_name'],
                'items' => [], 'opening' => 0, 'received' => 0, 'issued' => 0, 'closing' => 0, 'value' => 0.0];
        $g['items'][$r['id']] = true;
        foreach (['opening', 'received', 'issued', 'closing', 'value'] as $f) $g[$f] += $r[$f];
        unset($g);
        if ($sel && $gid !== $sel) continue;
        $p = &$per_item[$r['id']];
        $p ??= ['id' => $r['id'], 'item_name' => $r['item_name'], 'category_name' => $r['category_name'], 'unit_price' => $r['unit_price'],
                'opening' => 0, 'received' => 0, 'issued' => 0, 'closing' => 0, 'value' => 0.0];
        foreach (['opening', 'received', 'issued', 'closing', 'value'] as $f) $p[$f] += $r[$f];
        unset($p);
    }
    foreach ($groups as &$g) $g['items'] = count($g['items']);
    unset($g);
    $rows = array_values($per_item);
    usort($rows, fn($a, $b) => strcasecmp($a['category_name'], $b['category_name']) ?: strcasecmp($a['item_name'], $b['item_name']));
}
uasort($groups, fn($a, $b) => strcasecmp($a['name'], $b['name']));
if ($sel && !isset($groups[$sel])) $sel = 0;
$sel_name = $sel ? $groups[$sel]['name'] : '';
// Filter pills: one per name, so a department that exists at several branches appears once
// (branches with the same name in different companies are different places, so they get the company added instead)
$pills = [];
$name_count = array_count_values(array_map(fn($g) => mb_strtolower($g['name']), $groups));
foreach ($groups as $gid => $g) {
    if ($by === 'department') { $pills[mb_strtolower($g['name'])] ??= ['id' => $gid, 'name' => $g['name']]; continue; }
    $pills[$gid] = ['id' => $gid, 'name' => $g['name'] . ($name_count[mb_strtolower($g['name'])] > 1 && !empty($g['sub']) ? " ({$g['sub']})" : '')];
}
if ($by === 'department') {
    // The chosen department covers every branch's department of that name, item by item
    $same = $sel ? array_keys(array_filter($groups, fn($g) => mb_strtolower($g['name']) === mb_strtolower($sel_name))) : [];
    if ($sel) {
        $merged = [];
        foreach ($rows as $r) {
            if (!in_array((int)$r['gid'], $same, true)) continue;
            if (!isset($merged[$r['id']])) { $merged[$r['id']] = $r; continue; }
            foreach (['units', 'value', 'times'] as $f) $merged[$r['id']][$f] += $r[$f];
        }
        $rows = array_values($merged);
        usort($rows, fn($a, $b) => $b['units'] <=> $a['units']);
        $sel_name .= count($same) > 1 ? ' (all branches)' : ' (' . $groups[$sel]['branch'] . ', ' . $groups[$sel]['company'] . ')';
    }
}

$is_issue = $by === 'department';
$sum = fn(array $list, string $f) => array_sum(array_column($list, $f));
$view = REPORT_VIEWS[$by];

// ── Excel ───────────────────────────────────────────────────
if (wants_xlsx()) {
    $title = "Monthly Report by $view - $month_label" . ($sel_name ? " - $sel_name" : '');
    $meta  = [$is_issue ? 'Stock issued to departments, ' . date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($to))
                        : 'Stock from ' . date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($to)),
              'Exported: ' . date('d M Y, H:i')];
    $glist = array_values($groups);
    if ($is_issue) {
        $group_sheet = ['columns' => ['Department', 'Branch', 'Company', ['Items', 'int'], ['Units issued', 'int'], ['Value issued (K)', 'money'], ['Times issued', 'int']],
            'rows'   => array_map(fn($g) => [$g['name'], $g['branch'], $g['company'], $g['items'], $g['units'], $g['value'], $g['times']], $glist),
            'totals' => ['Total', '', '', '', $sum($glist, 'units'), $sum($glist, 'value'), $sum($glist, 'times')]];
        $item_sheet = ['columns' => ['Item', 'Category', ['Units issued', 'int'], ['Unit price (K)', 'money'], ['Value issued (K)', 'money'], ['Times issued', 'int']],
            'rows'   => array_map(fn($r) => [$r['item_name'], $r['category_name'], $r['units'], $r['unit_price'], $r['value'], $r['times']], $rows),
            'totals' => ['Total', '', $sum($rows, 'units'), '', $sum($rows, 'value'), $sum($rows, 'times')]];
    } else {
        $group_sheet = ['columns' => array_merge([$view], $by === 'branch' ? ['Company'] : [], [['Items', 'int'], ['Opening', 'int'], ['Received', 'int'], ['Issued', 'int'], ['Closing', 'int'], ['Closing value (K)', 'money']]),
            'rows'   => array_map(fn($g) => array_merge([$g['name']], $by === 'branch' ? [$g['sub']] : [], [$g['items'], $g['opening'], $g['received'], $g['issued'], $g['closing'], $g['value']]), $glist),
            'totals' => array_merge(['Total'], $by === 'branch' ? [''] : [], ['', $sum($glist, 'opening'), $sum($glist, 'received'), $sum($glist, 'issued'), $sum($glist, 'closing'), $sum($glist, 'value')])];
        $cat_cols = $by === 'category';
        $item_sheet = ['columns' => array_merge(['Item', 'Category', ['Opening', 'int'], ['Received', 'int'], ['Issued', 'int'], ['Closing', 'int']], $cat_cols ? [['Reorder at', 'int'], 'Status'] : [], [['Unit price (K)', 'money'], ['Closing value (K)', 'money']]),
            'rows'   => array_map(fn($r) => array_merge([$r['item_name'], $r['category_name'], $r['opening'], $r['received'], $r['issued'], $r['closing']], $cat_cols ? [$r['reorder_level'], $r['status']] : [], [$r['unit_price'], $r['value']]), $rows),
            'totals' => array_merge(['Total', '', $sum($rows, 'opening'), $sum($rows, 'received'), $sum($rows, 'issued'), $sum($rows, 'closing')], $cat_cols ? ['', ''] : [], ['', $sum($rows, 'value')])];
    }
    $sheets = [];
    // The By item card's button exports just the items
    $items_only = ($_GET['part'] ?? '') === 'items';
    if (!$sel && !$items_only) $sheets[] = ['name' => "By $by", 'title' => $title, 'meta' => $meta] + $group_sheet;
    if ($sel || $by === 'category') $sheets[] = ['name' => 'By item', 'title' => $title, 'meta' => $meta] + $item_sheet;
    xlsx_download("Monthly report by $by $month" . ($sel_name ? " $sel_name" : ''), $sheets);
}

$report_url = fn(array $p = []) => 'reports.php?' . http_build_query(array_filter($p + ['by' => $by, 'month' => $month]));
$kpi_rows = $sel ? $rows : array_values($groups);
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Monthly Report by <?php echo $view; ?><?php echo $sel_name ? ' &mdash; ' . htmlspecialchars($sel_name) : ''; ?></h1>
</div>

<form method="GET" class="card" style="margin-bottom:14px; display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
    <input type="hidden" name="by" value="<?php echo $by; ?>">
    <?php if ($sel): ?><input type="hidden" name="id" value="<?php echo $sel; ?>"><?php endif; ?>
    <label for="month" style="font-size:.78rem; font-weight:500; color:var(--text2)">Month</label>
    <input type="month" id="month" name="month" value="<?php echo $month; ?>" max="<?php echo date('Y-m'); ?>" onchange="this.form.submit()"
           style="padding:6px 10px; border:1px solid var(--border); border-radius:var(--radius-sm); font-size:.84rem; background:var(--surface);">
    <noscript><button type="submit" class="btn btn-sm">Show</button></noscript>
    <span class="muted-text"><?php echo $is_issue
        ? "Stock issued to each department in $month_label."
        : "Opening and closing stock for $month_label, worked out from deliveries and issues" . ($month === date('Y-m') ? ' (closing = stock today)' : '') . '.'; ?></span>
</form>

<div class="kpi-row">
<?php if ($is_issue): ?>
    <div class="kpi"><div class="kpi-label"><?php echo $sel ? 'Items' : 'Departments'; ?></div><div class="kpi-value"><?php echo number_format($sel ? count($rows) : count($groups)); ?></div></div>
    <div class="kpi"><div class="kpi-label">Units issued</div><div class="kpi-value"><?php echo number_format($sum($kpi_rows, 'units')); ?></div></div>
    <div class="kpi"><div class="kpi-label">Value issued</div><div class="kpi-value"><?php echo kwacha($sum($kpi_rows, 'value'), true); ?></div></div>
    <div class="kpi"><div class="kpi-label">Times issued</div><div class="kpi-value"><?php echo number_format($sum($kpi_rows, 'times')); ?></div></div>
<?php else: ?>
    <div class="kpi"><div class="kpi-label"><?php echo $sel ? 'Items' : ['category' => 'Categories', 'company' => 'Companies', 'branch' => 'Branches'][$by]; ?></div><div class="kpi-value"><?php echo number_format($sel ? count($rows) : count($groups)); ?></div></div>
    <div class="kpi"><div class="kpi-label">Received</div><div class="kpi-value"><?php echo number_format($sum($kpi_rows, 'received')); ?> <span class="kpi-unit">units</span></div></div>
    <div class="kpi"><div class="kpi-label">Issued</div><div class="kpi-value"><?php echo number_format($sum($kpi_rows, 'issued')); ?> <span class="kpi-unit">units</span></div></div>
    <div class="kpi"><div class="kpi-label">Closing value</div><div class="kpi-value"><?php echo kwacha($sum($kpi_rows, 'value'), true); ?></div></div>
<?php endif; ?>
</div>

<?php if ($groups): ?>
<div class="pill-tabs pill-tabs-inline">
    <a href="<?php echo htmlspecialchars($report_url()); ?>" class="pill-tab <?php echo $sel ? '' : 'active'; ?>">All</a>
    <?php foreach ($pills as $p): $on = $sel && ($by === 'department' ? mb_strtolower($groups[$sel]['name']) === mb_strtolower($p['name']) : $sel === $p['id']); ?>
    <a href="<?php echo htmlspecialchars($report_url(['id' => $p['id']])); ?>" class="pill-tab <?php echo $on ? 'active' : ''; ?>"><?php echo htmlspecialchars($p['name']); ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (!$groups): ?>
<div class="empty-state card"><p><?php echo $is_issue ? "Nothing was issued to a department in $month_label." : "There was no stock in $month_label."; ?></p></div>
<?php else: ?>

<?php if (!$sel): ?>
<!-- One row per <?php echo $by; ?> -->
<div class="card">
    <div class="card-head"><h3>By <?php echo strtolower($view); ?> &mdash; <?php echo $month_label; ?></h3><?php echo xlsx_button(); ?></div>
    <div class="table-container">
        <table>
        <?php if ($is_issue): ?>
            <thead><tr><th>Department</th><th>Branch</th><th>Company</th><th class="num">Items</th><th class="num">Units issued</th><th class="num">Value issued</th><th class="num">Times</th></tr></thead>
            <tbody>
            <?php foreach ($groups as $gid => $g): ?>
                <tr><td><a href="<?php echo htmlspecialchars($report_url(['id' => $gid])); ?>" style="font-weight:600"><?php echo htmlspecialchars($g['name']); ?></a></td>
                    <td class="muted"><?php echo htmlspecialchars($g['branch']); ?></td>
                    <td class="muted"><?php echo htmlspecialchars($g['company']); ?></td>
                    <td class="num"><?php echo number_format($g['items']); ?></td><td class="num"><strong><?php echo number_format($g['units']); ?></strong></td>
                    <td class="num"><?php echo kwacha($g['value']); ?></td><td class="num muted"><?php echo number_format($g['times']); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="4">Total</td><td class="num"><?php echo number_format($sum($groups, 'units')); ?></td><td class="num"><?php echo kwacha($sum($groups, 'value')); ?></td><td class="num"><?php echo number_format($sum($groups, 'times')); ?></td></tr></tfoot>
        <?php else: ?>
            <thead><tr><th><?php echo $view; ?></th><?php if ($by === 'branch'): ?><th>Company</th><?php endif; ?><th class="num">Items</th><th class="num">Opening</th><th class="num">Received</th><th class="num">Issued</th><th class="num">Closing</th><th class="num">Closing value</th><?php if ($by === 'category'): ?><th class="num">Low or out</th><?php endif; ?></tr></thead>
            <tbody>
            <?php foreach ($groups as $gid => $g): ?>
                <tr><td><a href="<?php echo htmlspecialchars($report_url(['id' => $gid])); ?>" style="font-weight:600"><?php echo htmlspecialchars($g['name']); ?></a></td>
                    <?php if ($by === 'branch'): ?><td class="muted"><?php echo htmlspecialchars($g['sub']); ?></td><?php endif; ?>
                    <td class="num"><?php echo number_format($g['items']); ?></td>
                    <td class="num muted"><?php echo number_format($g['opening']); ?></td>
                    <td class="num"><?php echo number_format($g['received']); ?></td>
                    <td class="num"><?php echo number_format($g['issued']); ?></td>
                    <td class="num"><strong><?php echo number_format($g['closing']); ?></strong></td>
                    <td class="num"><?php echo kwacha($g['value']); ?></td>
                    <?php if ($by === 'category'): ?><td class="num"><?php echo $g['low'] ? '<span style="color:var(--danger)">' . $g['low'] . '</span>' : '0'; ?></td><?php endif; ?></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td<?php echo $by === 'branch' ? ' colspan="2"' : ''; ?>>Total</td><td></td><td class="num"><?php echo number_format($sum($groups, 'opening')); ?></td><td class="num"><?php echo number_format($sum($groups, 'received')); ?></td>
                <td class="num"><?php echo number_format($sum($groups, 'issued')); ?></td><td class="num"><?php echo number_format($sum($groups, 'closing')); ?></td><td class="num"><?php echo kwacha($sum($groups, 'value')); ?></td><?php if ($by === 'category'): ?><td></td><?php endif; ?></tr></tfoot>
        <?php endif; ?>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($sel || $by === 'category'): ?>
<!-- Item by item -->
<div class="card">
    <div class="card-head"><h3>By item<?php echo $sel_name ? ' &mdash; ' . htmlspecialchars($sel_name) : ''; ?></h3><?php echo $sel ? xlsx_button() : xlsx_button(['part' => 'items']); ?></div>
    <div class="table-container">
        <table>
        <?php if ($is_issue): ?>
            <thead><tr><th>Item</th><th>Category</th><th class="num">Units issued</th><th class="num">Unit price</th><th class="num">Value issued</th><th class="num">Times</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr><td><a class="item-link" href="stock_item.php?id=<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['item_name']); ?></a></td>
                    <td><span class="cat-pill"><?php echo htmlspecialchars($r['category_name']); ?></span></td>
                    <td class="num"><strong><?php echo number_format($r['units']); ?></strong></td><td class="num muted"><?php echo kwacha((float)$r['unit_price']); ?></td>
                    <td class="num"><?php echo kwacha((float)$r['value']); ?></td><td class="num muted"><?php echo number_format($r['times']); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="2">Total</td><td class="num"><?php echo number_format($sum($rows, 'units')); ?></td><td></td><td class="num"><?php echo kwacha($sum($rows, 'value')); ?></td><td class="num"><?php echo number_format($sum($rows, 'times')); ?></td></tr></tfoot>
        <?php else: $cat_cols = $by === 'category'; ?>
            <thead><tr><th>Item</th><th>Category</th><th class="num">Opening</th><th class="num">Received</th><th class="num">Issued</th><th class="num">Closing</th><?php if ($cat_cols): ?><th class="num">Reorder at</th><th>Status</th><?php endif; ?><th class="num">Unit price</th><th class="num">Closing value</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
                <tr><td><a class="item-link" href="stock_item.php?id=<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['item_name']); ?></a></td>
                    <td><span class="cat-pill"><?php echo htmlspecialchars($r['category_name']); ?></span></td>
                    <td class="num muted"><?php echo number_format($r['opening']); ?></td>
                    <td class="num"><?php echo number_format($r['received']); ?></td>
                    <td class="num"><?php echo number_format($r['issued']); ?></td>
                    <td class="num"><strong><?php echo number_format($r['closing']); ?></strong></td>
                    <?php if ($cat_cols): ?>
                    <td class="num muted"><?php echo number_format($r['reorder_level']); ?></td>
                    <td><span class="<?php echo $r['status'] === 'OK' ? 'status-receiving' : ($r['status'] === 'Out of stock' ? 'status-issuing' : 'status-pending'); ?>"><?php echo $r['status']; ?></span></td>
                    <?php endif; ?>
                    <td class="num muted"><?php echo kwacha((float)$r['unit_price']); ?></td>
                    <td class="num"><?php echo kwacha($r['value']); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="2">Total</td><td class="num"><?php echo number_format($sum($rows, 'opening')); ?></td><td class="num"><?php echo number_format($sum($rows, 'received')); ?></td>
                <td class="num"><?php echo number_format($sum($rows, 'issued')); ?></td><td class="num"><?php echo number_format($sum($rows, 'closing')); ?></td><?php if ($cat_cols): ?><td colspan="2"></td><?php endif; ?><td></td><td class="num"><?php echo kwacha($sum($rows, 'value')); ?></td></tr></tfoot>
        <?php endif; ?>
        </table>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
