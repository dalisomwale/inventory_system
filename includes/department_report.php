<?php
/**
 * Monthly report for a department-limited user: everything issued to their department in a month,
 * by category and by item, with every issue listed. Included from pages/reports.php.
 */
require_once __DIR__ . '/insights.php';

$dept_id   = (int)my_scope_department();
$dept_name = $dept_id ? department_name($dept_id) : '';
$month     = safe_month($_GET['month'] ?? null);
if ($month > date('Y-m')) $month = date('Y-m');
$cat_id    = (int)($_GET['category_id'] ?? 0);
$from      = $month . '-01';
$to        = date('Y-m-t', strtotime($from));

$categories = $pdo->prepare("
    SELECT COALESCE(c.id, 0) AS id, COALESCE(c.name, i.category, 'Other') AS name,
           COUNT(DISTINCT i.id) AS items, SUM(s.quantity) AS units, SUM(s.quantity * i.unit_price) AS value
    FROM stock_issuing s JOIN items i ON i.id = s.item_id LEFT JOIN categories c ON c.id = i.category_id
    WHERE s.department_id = ? AND DATE(s.created_at) BETWEEN ? AND ?
    GROUP BY COALESCE(c.id, 0), COALESCE(c.name, i.category, 'Other') ORDER BY name");
$categories->execute([$dept_id, $from, $to]);
$categories = $categories->fetchAll();
if (!in_array($cat_id, array_map(fn($c) => (int)$c['id'], $categories), true)) $cat_id = 0;
$cat_sql = $cat_id ? ' AND i.category_id = ' . $cat_id : '';

$items = $pdo->prepare("
    SELECT i.id, i.item_name, COALESCE(c.name, i.category, 'Other') AS category_name, i.unit_price,
           SUM(s.quantity) AS units, SUM(s.quantity * i.unit_price) AS value, COUNT(*) AS times
    FROM stock_issuing s JOIN items i ON i.id = s.item_id LEFT JOIN categories c ON c.id = i.category_id
    WHERE s.department_id = ? AND DATE(s.created_at) BETWEEN ? AND ?$cat_sql
    GROUP BY i.id, i.item_name, category_name, i.unit_price ORDER BY units DESC, i.item_name");
$items->execute([$dept_id, $from, $to]);
$items = $items->fetchAll();

$issues = $pdo->prepare("
    SELECT s.created_at, s.quantity, s.recipient, s.purpose, i.id AS item_id, i.item_name, u.full_name AS by_name
    FROM stock_issuing s JOIN items i ON i.id = s.item_id LEFT JOIN users u ON u.id = s.issued_by
    WHERE s.department_id = ? AND DATE(s.created_at) BETWEEN ? AND ?$cat_sql
    ORDER BY s.created_at DESC");
$issues->execute([$dept_id, $from, $to]);
$issues = $issues->fetchAll();

$tot_units = array_sum(array_column($items, 'units'));
$tot_value = array_sum(array_column($items, 'value'));
$cat_name  = '';
foreach ($categories as $c) if ((int)$c['id'] === $cat_id) $cat_name = $c['name'];
$report_url = fn(array $p) => 'reports.php?' . http_build_query(array_filter($p + ['month' => $month]));

if (wants_xlsx()) {
    $title = 'Monthly Report - ' . ($dept_name ?: 'Your department') . ' - ' . date('F Y', strtotime($from)) . ($cat_name ? " - $cat_name" : '');
    $meta  = ['Everything issued to ' . ($dept_name ?: 'your department') . ' in ' . date('F Y', strtotime($from))];
    xlsx_download('Department report ' . ($dept_name ?: '') . " $month" . ($cat_name ? " $cat_name" : ''), array_values(array_filter([
        $cat_id ? null : ['name' => 'By category', 'title' => $title, 'meta' => $meta,
            'columns' => ['Category', ['Items', 'int'], ['Units', 'int'], ['Value (K)', 'money']],
            'rows'    => array_map(fn($c) => [$c['name'], $c['items'], $c['units'], $c['value']], $categories),
            'totals'  => ['Total', '', $tot_units, $tot_value]],
        ['name' => 'By item', 'title' => $title, 'meta' => $meta,
            'columns' => ['Item', 'Category', ['Units', 'int'], ['Unit price (K)', 'money'], ['Value (K)', 'money'], ['Times issued', 'int']],
            'rows'    => array_map(fn($i) => [$i['item_name'], $i['category_name'], $i['units'], $i['unit_price'], $i['value'], $i['times']], $items),
            'totals'  => ['Total', '', $tot_units, '', $tot_value, count($issues)]],
        ['name' => 'Every issue', 'title' => $title, 'meta' => $meta,
            'columns' => ['Date', 'Item', ['Qty', 'int'], 'Recipient', 'Purpose', 'Issued by'],
            'rows'    => array_map(fn($t) => [date('d M Y, H:i', strtotime($t['created_at'])), $t['item_name'], $t['quantity'], $t['recipient'] ?? '', $t['purpose'] ?? '', $t['by_name'] ?? ''], $issues),
            'totals'  => ['Total', '', array_sum(array_column($issues, 'quantity'))]],
    ])));
}

include __DIR__ . '/header.php';
?>

<div class="page-header">
    <h1>Monthly Report &mdash; <?php echo htmlspecialchars($dept_name ?: 'your department'); ?></h1>
    <div style="display:flex; gap:8px">
        <?php if ($dept_id) echo xlsx_button(); ?>
        <button type="button" onclick="window.print()" class="btn">Print</button>
    </div>
</div>

<?php if (!$dept_id): ?>
<div class="empty-state card"><p>Your account has no department yet. Ask the System Admin to set it on Admin &rsaquo; Users.</p></div>
<?php else: ?>

<form method="GET" class="card" style="margin-bottom:14px; display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
    <label for="month" style="font-size:.78rem; font-weight:500; color:var(--text2)">Month</label>
    <input type="month" id="month" name="month" value="<?php echo $month; ?>" max="<?php echo date('Y-m'); ?>" onchange="this.form.submit()"
           style="padding:6px 10px; border:1px solid var(--border); border-radius:var(--radius-sm); font-size:.84rem; background:var(--surface);">
    <noscript><button type="submit" class="btn btn-sm">Show</button></noscript>
    <span class="muted-text">Everything issued to <?php echo htmlspecialchars($dept_name); ?> in <?php echo date('F Y', strtotime($from)); ?></span>
</form>

<div class="kpi-row">
    <div class="kpi"><div class="kpi-label">Items</div><div class="kpi-value"><?php echo count($items); ?></div></div>
    <div class="kpi"><div class="kpi-label">Units issued</div><div class="kpi-value"><?php echo number_format($tot_units); ?></div></div>
    <div class="kpi"><div class="kpi-label">Value issued</div><div class="kpi-value"><?php echo kwacha($tot_value, true); ?></div></div>
    <div class="kpi"><div class="kpi-label">Times issued</div><div class="kpi-value"><?php echo count($issues); ?></div></div>
</div>

<?php if (!$categories): ?>
<div class="empty-state card"><p>Nothing was issued to <?php echo htmlspecialchars($dept_name); ?> in <?php echo date('F Y', strtotime($from)); ?>.</p></div>
<?php else: ?>

<div class="pill-tabs pill-tabs-inline">
    <a href="<?php echo htmlspecialchars($report_url([])); ?>" class="pill-tab <?php echo $cat_id ? '' : 'active'; ?>">All categories</a>
    <?php foreach ($categories as $c): ?>
    <a href="<?php echo htmlspecialchars($report_url(['category_id' => (int)$c['id']])); ?>" class="pill-tab <?php echo $cat_id === (int)$c['id'] ? 'active' : ''; ?>"><?php echo htmlspecialchars($c['name']); ?></a>
    <?php endforeach; ?>
</div>

<?php if (!$cat_id): ?>
<div class="card">
    <div class="card-head"><h3>By category</h3></div>
    <div class="table-container">
        <table>
            <thead><tr><th>Category</th><th class="num">Items</th><th class="num">Units</th><th class="num">Value</th></tr></thead>
            <tbody>
            <?php foreach ($categories as $c): ?>
                <tr>
                    <td><a href="<?php echo htmlspecialchars($report_url(['category_id' => (int)$c['id']])); ?>" style="font-weight:600"><?php echo htmlspecialchars($c['name']); ?></a></td>
                    <td class="num"><?php echo number_format($c['items']); ?></td>
                    <td class="num"><?php echo number_format($c['units']); ?></td>
                    <td class="num"><?php echo kwacha((float)$c['value']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td>Total</td><td></td><td class="num"><?php echo number_format($tot_units); ?></td><td class="num"><?php echo kwacha($tot_value); ?></td></tr></tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-head"><h3>By item<?php echo $cat_name ? ' &mdash; ' . htmlspecialchars($cat_name) : ''; ?></h3></div>
    <div class="table-container">
        <table>
            <thead><tr><th>Item</th><th>Category</th><th class="num">Units</th><th class="num">Unit price</th><th class="num">Value</th><th class="num">Times</th></tr></thead>
            <tbody>
            <?php foreach ($items as $it): ?>
                <tr>
                    <td><a class="item-link" href="stock_item.php?id=<?php echo $it['id']; ?>"><?php echo htmlspecialchars($it['item_name']); ?></a></td>
                    <td><span class="cat-pill"><?php echo htmlspecialchars($it['category_name']); ?></span></td>
                    <td class="num"><strong><?php echo number_format($it['units']); ?></strong></td>
                    <td class="num muted"><?php echo kwacha((float)$it['unit_price']); ?></td>
                    <td class="num"><?php echo kwacha((float)$it['value']); ?></td>
                    <td class="num muted"><?php echo number_format($it['times']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot><tr><td colspan="2">Total</td><td class="num"><?php echo number_format($tot_units); ?></td><td></td><td class="num"><?php echo kwacha($tot_value); ?></td><td class="num"><?php echo count($issues); ?></td></tr></tfoot>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-head"><h3>Every issue</h3><span class="card-hint"><?php echo count($issues); ?> this month</span></div>
    <div class="table-container">
        <table class="compact">
            <thead><tr><th>Date</th><th>Item</th><th class="num">Qty</th><th>Recipient</th><th>Purpose</th><th>Issued by</th></tr></thead>
            <tbody>
            <?php foreach ($issues as $t): ?>
                <tr>
                    <td class="muted" style="white-space:nowrap"><?php echo date('d M Y, H:i', strtotime($t['created_at'])); ?></td>
                    <td style="font-weight:500"><?php echo htmlspecialchars($t['item_name']); ?></td>
                    <td class="num"><?php echo number_format($t['quantity']); ?></td>
                    <td><?php echo htmlspecialchars($t['recipient'] ?: '—'); ?></td>
                    <td class="muted"><?php echo htmlspecialchars($t['purpose'] ?: '—'); ?></td>
                    <td class="muted"><?php echo htmlspecialchars($t['by_name'] ?? '—'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
