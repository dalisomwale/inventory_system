<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

$current_month   = date('Y-m');
$to_order        = (int)$pdo->query("SELECT COUNT(*) FROM procurement WHERE status='needed'")->fetchColumn();
$today = $pdo->query("
    SELECT (SELECT COALESCE(SUM(quantity),0) FROM stock_issuing   WHERE DATE(created_at)=CURDATE()) AS issued,
           (SELECT COALESCE(SUM(quantity),0) FROM stock_receiving WHERE DATE(created_at)=CURDATE()) AS received")->fetch();
$low_stock_count = $pdo->query("SELECT COUNT(*) FROM items WHERE current_quantity <= reorder_level")->fetchColumn();
$total_items     = $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();

$report_status = $pdo->prepare("
    SELECT c.id, c.name,
           CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END as submitted,
           r.submitted_at, r.total_value
    FROM categories c
    LEFT JOIN category_monthly_reports r ON c.id = r.category_id AND DATE_FORMAT(r.report_month, '%Y-%m') = ?
    ORDER BY c.name
");
$report_status->execute([$current_month]);
$reports_data    = $report_status->fetchAll();
$total_cats      = count($reports_data);
$submitted_count = count(array_filter($reports_data, fn($r) => $r['submitted']));

require_once __DIR__ . '/../includes/insights.php';
$stock_value   = ins_stock_value($pdo);
$issued        = ins_issued_compare($pdo, 30);
$forecast      = ins_item_forecast($pdo, 30);
$running_low   = ins_running_low($forecast, 14);
$out_of_stock  = count(array_filter($forecast, fn($r) => $r['current_quantity'] <= 0));
$weekly        = ins_weekly_movement($pdo, 8);
$by_category   = ins_value_by_category($pdo);
$top_issued    = ins_top_issued($pdo, 30, 6);
$top_users     = ins_top_recipients($pdo, 30, 6);
$week_recv     = array_sum(array_column($weekly, 'received'));
$week_issued   = array_sum(array_column($weekly, 'issued'));
$health        = ins_stock_health($forecast);
$by_cat_issued = ins_issued_by_category($pdo, 30);

if (!empty($_SESSION['flash']))       { $success = htmlspecialchars($_SESSION['flash']);       unset($_SESSION['flash']); }
if (!empty($_SESSION['flash_error'])) { $error   = htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); }

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Dashboard</h1>
    <div style="font-size:.78rem; color:var(--muted)"><?php echo date('l, d F Y'); ?></div>
</div>

<?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
<?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<!-- Headline numbers -->
<div class="kpi-row">
    <div class="kpi">
        <div class="kpi-label">Stock value</div>
        <div class="kpi-value"><?php echo kwacha($stock_value, true); ?></div>
    </div>
    <div class="kpi">
        <div class="kpi-label">Issued, last 30 days</div>
        <div class="kpi-value"><?php echo number_format($issued['current']); ?> <span class="kpi-unit">units</span></div>
    </div>
    <div class="kpi">
        <div class="kpi-label">Need reordering</div>
        <div class="kpi-value <?php echo count($running_low) ? 'is-bad' : ''; ?>"><?php echo count($running_low); ?></div>
    </div>
    <div class="kpi">
        <div class="kpi-label">Requisitions</div>
        <div class="kpi-value <?php echo $to_order ? 'is-warn' : ''; ?>"><?php echo $to_order; ?> <span class="kpi-unit">to order</span></div>
    </div>
</div>

<!-- Quick actions -->
<div class="action-tiles stacked">
    <?php if (can('stock.receive')): ?><?php echo action_tile('receive_stock.php', 'Receive stock', 'Record a delivery', icon('receive'), '', 'receive'); ?><?php endif; ?>
    <?php if (can('stock.issue')): ?><?php echo action_tile('issue_stock.php', 'Issue stock', 'Give items to a department', icon('issue'), '', 'issue'); ?><?php endif; ?>
    <?php if (can('stock.view')): ?><?php echo action_tile('stock.php', 'Manage stock', 'All items and levels', icon('stock'), '', 'stock'); ?><?php endif; ?>
    <?php if (can('requisitions.view')): ?><?php echo action_tile('requisitions.php', 'Requisitions', 'What needs buying', icon('request'), $to_order ? $to_order . ' to order' : '', 'request'); ?><?php endif; ?>
    <?php if (can_any('reports.view','reports.submit')): ?><?php echo action_tile('reports.php', 'Monthly reports', $submitted_count . ' of ' . $total_cats . ' submitted', icon('chart'), '', 'reports'); ?><?php endif; ?>
</div>

<!-- Movement + health -->
<div class="grid-2 wide-left">
    <div class="card">
        <div class="card-head">
            <h3>Stock movement &mdash; last 8 weeks</h3>
            <div class="legend">
                <span><i class="swatch received"></i>Received <strong><?php echo number_format($week_recv); ?></strong></span>
                <span><i class="swatch issued"></i>Issued <strong><?php echo number_format($week_issued); ?></strong></span>
            </div>
        </div>
        <?php if ($week_recv + $week_issued > 0): ?>
            <?php echo chart_weekly($weekly); ?>
            <details class="table-view">
                <summary>Show as table</summary>
                <table>
                    <thead><tr><th>Week of</th><th class="num">Received</th><th class="num">Issued</th><th class="num">Net</th></tr></thead>
                    <tbody>
                    <?php foreach ($weekly as $w): $net = $w['received'] - $w['issued']; ?>
                        <tr><td><?php echo $w['week']->format('d M Y'); ?></td><td class="num"><?php echo number_format($w['received']); ?></td>
                            <td class="num"><?php echo number_format($w['issued']); ?></td><td class="num"><?php echo ($net > 0 ? '+' : '') . number_format($net); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        <?php else: ?>
            <div class="empty-state"><p>No stock received or issued in the last 8 weeks.</p></div>
        <?php endif; ?>
    </div>
    <div class="card">
        <div class="card-head"><h3>Stock health</h3><span class="card-hint">Items by stock level</span></div>
        <?php echo chart_donut([
            ['label' => 'In stock',     'value' => $health['ok'],  'color' => '#0ca30c', 'icon' => '&#10003;'],
            ['label' => 'Below reorder','value' => $health['low'], 'color' => '#fab219', 'icon' => '&#9888;'],
            ['label' => 'Out of stock', 'value' => $health['out'], 'color' => '#d03b3b', 'icon' => '&#10005;'],
        ], (string)$total_items, 'items', fn($v) => number_format($v) . ' item' . ($v == 1 ? '' : 's')); ?>
    </div>
</div>

<!-- Category usage + value share -->
<div class="grid-2 wide-left">
    <div class="card">
        <div class="card-head"><h3>Issued by category &mdash; last 30 days</h3><span class="card-hint">Units</span></div>
        <?php echo $by_cat_issued
            ? chart_hbars(array_map(fn($r) => ['label' => $r['label'], 'value' => $r['value']], $by_cat_issued), fn($v) => number_format($v) . ' units')
            : '<div class="empty-state"><p>Nothing issued in the last 30 days.</p></div>'; ?>
    </div>
    <div class="card">
        <div class="card-head"><h3>Where the stock value sits</h3><span class="card-hint">Share by category</span></div>
        <?php echo $by_category
            ? chart_donut(top_n_other($by_category, 5), kwacha($stock_value, true), 'total value', fn($v) => kwacha_whole((float)$v))
            : '<div class="empty-state"><p>Set unit prices on items to see stock value.</p></div>'; ?>
    </div>
</div>

<!-- Running low -->
<div class="card">
    <div class="card-head">
        <h3>Running low &mdash; <?php echo count($running_low); ?> item<?php echo count($running_low) == 1 ? '' : 's'; ?></h3>
        <span class="card-hint">"Days left" is based on how much was issued in the last 30 days</span>
    </div>
    <?php if ($running_low): ?>
    <div class="table-container">
        <table>
            <thead><tr><th>Item</th><th>Category</th><th class="num">In stock</th><th class="num">Reorder at</th><th class="num">Used / week</th><th>Days left</th><th class="num">Suggested order</th><th></th></tr></thead>
            <tbody>
            <?php foreach (array_slice($running_low, 0, 10) as $r):
                $urgent = $r['current_quantity'] <= 0 || ($r['days_left'] !== null && $r['days_left'] < 7); ?>
                <tr>
                    <td style="font-weight:600"><?php echo htmlspecialchars($r['item_name']); ?></td>
                    <td><span class="cat-pill"><?php echo htmlspecialchars($r['category'] ?: 'Other'); ?></span></td>
                    <td class="num"><?php echo number_format($r['current_quantity']); ?></td>
                    <td class="num muted"><?php echo number_format($r['reorder_level']); ?></td>
                    <td class="num muted"><?php echo $r['per_day'] > 0 ? number_format($r['per_day'] * 7, $r['per_day'] * 7 < 10 ? 1 : 0) : '&mdash;'; ?></td>
                    <td><span class="<?php echo $urgent ? 'status-issuing' : 'status-pending'; ?>"><?php echo $urgent ? '&#9888; ' : ''; ?><?php echo days_left_label($r['days_left'], (int)$r['current_quantity']); ?></span></td>
                    <td class="num"><strong><?php echo number_format($r['suggest']); ?></strong></td>
                    <td><div class="row-actions">
                        <?php if (can('requisitions.manage')): ?><a href="requisitions.php?item_name=<?php echo urlencode($r['item_name']); ?>&qty=<?php echo max(1, $r['suggest']); ?>&category_id=<?php echo (int)$r['category_id']; ?>" class="btn btn-sm">Raise requisition</a><?php endif; ?>
                        <?php if (can('stock.receive')): ?><a href="receive_stock.php?category_id=<?php echo (int)$r['category_id']; ?>&item_id=<?php echo $r['id']; ?>" class="btn btn-sm">Receive</a><?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if (count($running_low) > 10): ?><div class="card-foot">Showing the 10 most urgent of <?php echo count($running_low); ?>.</div><?php endif; ?>
    <?php else: ?>
    <div class="empty-state"><p>Nothing is below its reorder level or due to run out in the next 2 weeks.</p></div>
    <?php endif; ?>
</div>

<!-- Usage -->
<div class="grid-2">
    <div class="card">
        <div class="card-head"><h3>Most issued &mdash; last 30 days</h3><?php if (can('transactions.view')): ?><a href="view_transactions.php" class="btn btn-sm">Transactions</a><?php endif; ?></div>
        <?php echo $top_issued ? chart_hbars($top_issued, fn($v) => number_format($v) . ' units') : '<div class="empty-state"><p>Nothing issued in the last 30 days.</p></div>'; ?>
    </div>
    <div class="card">
        <div class="card-head"><h3>Who uses the most &mdash; last 30 days</h3><span class="card-hint">By value issued</span></div>
        <?php echo $top_users ? chart_hbars(array_map(fn($r) => ['label' => $r['label'], 'value' => $r['cost'] > 0 ? $r['cost'] : $r['value'], 'times' => $r['times']], $top_users),
                                             fn($v) => kwacha_whole((float)$v)) : '<div class="empty-state"><p>Nothing issued in the last 30 days.</p></div>'; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>