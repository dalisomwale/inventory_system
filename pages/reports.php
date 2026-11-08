<?php
// Monthly Reports: worked out from stock movements for any month, to view, print or export to Excel.
require_once __DIR__ . '/../includes/auth.php';
requirePermission('reports.view');
// Department-limited users get their own department's report
if (my_scope_department() !== null) { require __DIR__ . '/../includes/department_report.php'; exit(); }
require_once __DIR__ . '/../includes/insights.php';

$month = safe_month($_GET['month'] ?? null);
if ($month > date('Y-m')) $month = date('Y-m');
$from   = $month . '-01';
$to     = date('Y-m-t', strtotime($from));
$cat_id = (int)($_GET['category_id'] ?? 0);

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
if (!in_array($cat_id, array_map(fn($c) => (int)$c['id'], $categories), true)) $cat_id = 0;
$cat_name = '';
foreach ($categories as $c) if ((int)$c['id'] === $cat_id) $cat_name = $c['name'];

// Per item: received and issued in the month, and movements since, to work back from today's stock
$st = $pdo->prepare("
    SELECT i.id, i.item_name, i.category_id, COALESCE(c.name, i.category, 'Other') AS category_name,
           i.current_quantity, i.reorder_level, i.unit_price,
           COALESCE(r.in_month, 0) AS received, COALESCE(r.after, 0) AS received_after,
           COALESCE(s.in_month, 0) AS issued,   COALESCE(s.after, 0) AS issued_after
    FROM items i
    LEFT JOIN categories c ON c.id = i.category_id
    LEFT JOIN (SELECT item_id, SUM(CASE WHEN DATE(created_at) BETWEEN :f1 AND :t1 THEN quantity END) AS in_month,
                               SUM(CASE WHEN DATE(created_at) > :t2 THEN quantity END) AS after
               FROM stock_receiving GROUP BY item_id) r ON r.item_id = i.id
    LEFT JOIN (SELECT item_id, SUM(CASE WHEN DATE(created_at) BETWEEN :f2 AND :t3 THEN quantity END) AS in_month,
                               SUM(CASE WHEN DATE(created_at) > :t4 THEN quantity END) AS after
               FROM stock_issuing GROUP BY item_id) s ON s.item_id = i.id
    WHERE DATE(i.created_at) <= :t5" . ($cat_id ? " AND i.category_id = $cat_id" : "") . "
    ORDER BY category_name, i.item_name");
$st->execute(['f1' => $from, 't1' => $to, 't2' => $to, 'f2' => $from, 't3' => $to, 't4' => $to, 't5' => $to]);
$items = [];
foreach ($st->fetchAll() as $r) {
    $r['closing'] = (int)$r['current_quantity'] - (int)$r['received_after'] + (int)$r['issued_after'];
    $r['opening'] = $r['closing'] - (int)$r['received'] + (int)$r['issued'];
    $r['value']   = $r['closing'] * (float)$r['unit_price'];
    $r['status']  = $r['closing'] <= 0 ? 'Out of stock' : ($r['closing'] <= $r['reorder_level'] ? 'Below reorder' : 'OK');
    $items[] = $r;
}

// Totals per category
$by_cat = [];
foreach ($items as $r) {
    $k = $r['category_name'];
    $by_cat[$k] ??= ['id' => (int)$r['category_id'], 'name' => $k, 'items' => 0, 'opening' => 0, 'received' => 0, 'issued' => 0, 'closing' => 0, 'value' => 0.0, 'low' => 0];
    $by_cat[$k]['items']++;
    foreach (['opening', 'received', 'issued', 'closing', 'value'] as $f) $by_cat[$k][$f] += $r[$f];
    if ($r['status'] !== 'OK') $by_cat[$k]['low']++;
}
$sum = fn(string $f) => array_sum(array_column($items, $f));
$month_label = date('F Y', strtotime($from));

if (wants_xlsx()) {
    $title = "Monthly Report - $month_label" . ($cat_name ? " - $cat_name" : '');
    $meta  = ['Stock from ' . date('d M Y', strtotime($from)) . ' to ' . date('d M Y', strtotime($to)), 'Exported: ' . date('d M Y, H:i')];
    $item_sheet = ['name' => 'By item', 'title' => $title, 'meta' => $meta,
        'columns' => ['Item', 'Category', ['Opening', 'int'], ['Received', 'int'], ['Issued', 'int'], ['Closing', 'int'], ['Reorder at', 'int'], 'Status', ['Unit price (K)', 'money'], ['Closing value (K)', 'money']],
        'rows'    => array_map(fn($r) => [$r['item_name'], $r['category_name'], $r['opening'], $r['received'], $r['issued'], $r['closing'], $r['reorder_level'], $r['status'], $r['unit_price'], $r['value']], $items),
        'totals'  => ['Total', '', $sum('opening'), $sum('received'), $sum('issued'), $sum('closing'), '', '', '', $sum('value')]];
    $sheets = $cat_id ? [$item_sheet] : [
        ['name' => 'By category', 'title' => $title, 'meta' => $meta,
         'columns' => ['Category', ['Items', 'int'], ['Opening', 'int'], ['Received', 'int'], ['Issued', 'int'], ['Closing', 'int'], ['Closing value (K)', 'money'], ['Low or out', 'int']],
         'rows'    => array_map(fn($c) => [$c['name'], $c['items'], $c['opening'], $c['received'], $c['issued'], $c['closing'], $c['value'], $c['low']], array_values($by_cat)),
         'totals'  => ['Total', count($items), $sum('opening'), $sum('received'), $sum('issued'), $sum('closing'), $sum('value'), array_sum(array_column($by_cat, 'low'))]],
        $item_sheet,
    ];
    xlsx_download("Monthly report $month" . ($cat_name ? " $cat_name" : ''), $sheets);
}

$report_url = fn(array $p) => 'reports.php?' . http_build_query(array_filter($p + ['month' => $month]));
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Monthly Reports</h1>
    <div style="display:flex; gap:8px; flex-wrap:wrap">
        <a href="category_reports.php" class="btn btn-sm">Category activity</a>
        <?php echo xlsx_button(); ?>
        <button type="button" onclick="window.print()" class="btn btn-sm">Print</button>
    </div>
</div>

<form method="GET" class="card" style="margin-bottom:14px; display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
    <?php if ($cat_id): ?><input type="hidden" name="category_id" value="<?php echo $cat_id; ?>"><?php endif; ?>
    <label for="month" style="font-size:.78rem; font-weight:500; color:var(--text2)">Month</label>
    <input type="month" id="month" name="month" value="<?php echo $month; ?>" max="<?php echo date('Y-m'); ?>" onchange="this.form.submit()"
           style="padding:6px 10px; border:1px solid var(--border); border-radius:var(--radius-sm); font-size:.84rem; background:var(--surface);">
    <noscript><button type="submit" class="btn btn-sm">Show</button></noscript>
    <span class="muted-text">Opening and closing stock for <?php echo $month_label; ?>, worked out from deliveries and issues<?php echo $month === date('Y-m') ? ' (closing = stock today)' : ''; ?>.</span>
</form>

<div class="kpi-row">
    <div class="kpi"><div class="kpi-label">Items</div><div class="kpi-value"><?php echo number_format(count($items)); ?></div></div>
    <div class="kpi"><div class="kpi-label">Received</div><div class="kpi-value"><?php echo number_format($sum('received')); ?> <span class="kpi-unit">units</span></div></div>
    <div class="kpi"><div class="kpi-label">Issued</div><div class="kpi-value"><?php echo number_format($sum('issued')); ?> <span class="kpi-unit">units</span></div></div>
    <div class="kpi"><div class="kpi-label">Closing value</div><div class="kpi-value"><?php echo kwacha($sum('value'), true); ?></div></div>
</div>

<div class="pill-tabs pill-tabs-inline">
    <a href="<?php echo htmlspecialchars($report_url([])); ?>" class="pill-tab <?php echo $cat_id ? '' : 'active'; ?>">All categories</a>
    <?php foreach ($categories as $c): ?>
    <a href="<?php echo htmlspecialchars($report_url(['category_id' => (int)$c['id']])); ?>" class="pill-tab <?php echo $cat_id === (int)$c['id'] ? 'active' : ''; ?>"><?php echo htmlspecialchars($c['name']); ?></a>
    <?php endforeach; ?>
</div>

<?php if (!$items): ?>
<div class="empty-state card"><p>There were no items<?php echo $cat_name ? ' in ' . htmlspecialchars($cat_name) : ''; ?> in <?php echo $month_label; ?>.</p></div>
<?php else: ?>

<?php if (!$cat_id): ?>
<div class="card">
    <div class="card-head"><h3>By category &mdash; <?php echo $month_label; ?></h3></div>
    <div class="table-container">
        <table>
            <thead><tr><th>Category</th><th class="num">Items</th><th class="num">Opening</th><th class="num">Received</th><th class="num">Issued</th><th class="num">Closing</th><th class="num">Closing value</th><th class="num">Low or out</th></tr></thead>
            <tbody>
            <?php foreach ($by_cat as $c): ?>
                <tr>
                    <td><a href="<?php echo htmlspecialchars($report_url(['category_id' => $c['id']])); ?>" style="font-weight:600"><?php echo htmlspecialchars($c['name']); ?></a></td>
                    <td class="num"><?php echo number_format($c['items']); ?></td>
                    <td class="num muted"><?php echo number_format($c['opening']); ?></td>
                    <td class="num"><?php echo number_format($c['received']); ?></td>
                    <td class="num"><?php echo number_format($c['issued']); ?></td>
                    <td class="num"><strong><?php echo number_format($c['closing']); ?></strong></td>
                    <td class="num"><?php echo kwacha($c['value']); ?></td>
                    <td class="num"><?php echo $c['low'] ? '<span style="color:var(--danger)">' . $c['low'] . '</span>' : '0'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td>Total</td><td class="num"><?php echo number_format(count($items)); ?></td><td class="num"><?php echo number_format($sum('opening')); ?></td><td class="num"><?php echo number_format($sum('received')); ?></td>
                <td class="num"><?php echo number_format($sum('issued')); ?></td><td class="num"><?php echo number_format($sum('closing')); ?></td><td class="num"><?php echo kwacha($sum('value')); ?></td><td></td></tr></tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-head"><h3>By item<?php echo $cat_name ? ' &mdash; ' . htmlspecialchars($cat_name) : ''; ?></h3></div>
    <div class="table-container">
        <table>
            <thead><tr><th>Item</th><?php if (!$cat_id): ?><th>Category</th><?php endif; ?><th class="num">Opening</th><th class="num">Received</th><th class="num">Issued</th><th class="num">Closing</th><th class="num">Reorder at</th><th>Status</th><th class="num">Unit price</th><th class="num">Closing value</th></tr></thead>
            <tbody>
            <?php foreach ($items as $r): ?>
                <tr>
                    <td><a class="item-link" href="stock_item.php?id=<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['item_name']); ?></a></td>
                    <?php if (!$cat_id): ?><td><span class="cat-pill"><?php echo htmlspecialchars($r['category_name']); ?></span></td><?php endif; ?>
                    <td class="num muted"><?php echo number_format($r['opening']); ?></td>
                    <td class="num"><?php echo number_format($r['received']); ?></td>
                    <td class="num"><?php echo number_format($r['issued']); ?></td>
                    <td class="num"><strong><?php echo number_format($r['closing']); ?></strong></td>
                    <td class="num muted"><?php echo number_format($r['reorder_level']); ?></td>
                    <td><span class="<?php echo $r['status'] === 'OK' ? 'status-receiving' : ($r['status'] === 'Out of stock' ? 'status-issuing' : 'status-pending'); ?>"><?php echo $r['status']; ?></span></td>
                    <td class="num muted"><?php echo kwacha((float)$r['unit_price']); ?></td>
                    <td class="num"><?php echo kwacha($r['value']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td<?php echo $cat_id ? '' : ' colspan="2"'; ?>>Total</td><td class="num"><?php echo number_format($sum('opening')); ?></td><td class="num"><?php echo number_format($sum('received')); ?></td>
                <td class="num"><?php echo number_format($sum('issued')); ?></td><td class="num"><?php echo number_format($sum('closing')); ?></td><td colspan="3"></td><td class="num"><?php echo kwacha($sum('value')); ?></td></tr></tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
