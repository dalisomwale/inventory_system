<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();
if (!isManager()) { header('Location: dashboard.php'); exit(); }

$current_month   = date('Y-m');
$pending_count   = $pdo->query("SELECT COUNT(*) FROM procurement WHERE status='pending'")->fetchColumn();
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
$idle          = ins_idle_items($pdo, 60, 6);
$adjustments   = ins_recent_adjustments($pdo, 5);
$oldest_days   = ins_oldest_pending_days($pdo);
$week_recv     = array_sum(array_column($weekly, 'received'));
$week_issued   = array_sum(array_column($weekly, 'issued'));
$idle_value    = array_sum(array_column($idle, 'value'));

if (!empty($_SESSION['flash']))       { $success = htmlspecialchars($_SESSION['flash']);       unset($_SESSION['flash']); }
if (!empty($_SESSION['flash_error'])) { $error   = htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); }

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Manager Dashboard</h1>
    <div style="font-size:.78rem; color:var(--muted)"><?php echo date('l, d F Y'); ?></div>
</div>

<?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
<?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<!-- Headline numbers -->
<div class="kpi-row">
    <div class="kpi">
        <div class="kpi-label">Stock value</div>
        <div class="kpi-value"><?php echo kwacha($stock_value, true); ?></div>
        <div class="kpi-note"><?php echo kwacha($stock_value); ?> across <?php echo $total_items; ?> items</div>
    </div>
    <div class="kpi">
        <div class="kpi-label">Issued, last 30 days</div>
        <div class="kpi-value"><?php echo number_format($issued['current']); ?> <span class="kpi-unit">units</span></div>
        <div class="kpi-note">
            <?php if ($issued['change_pct'] === null): ?>
                No issues in the 30 days before
            <?php else: ?>
                <span class="trend <?php echo $issued['change_pct'] > 0 ? 'up' : ($issued['change_pct'] < 0 ? 'down' : ''); ?>">
                    <?php echo $issued['change_pct'] > 0 ? '&#9650;' : ($issued['change_pct'] < 0 ? '&#9660;' : '&#9644;'); ?>
                    <?php echo abs($issued['change_pct']); ?>%
                </span> vs previous 30 days (<?php echo number_format($issued['previous']); ?>)
            <?php endif; ?>
        </div>
    </div>
    <div class="kpi">
        <div class="kpi-label">Need reordering</div>
        <div class="kpi-value <?php echo count($running_low) ? 'is-bad' : ''; ?>"><?php echo count($running_low); ?></div>
        <div class="kpi-note">
            <?php if ($out_of_stock): ?><span class="status-issuing">&#9888; <?php echo $out_of_stock; ?> out of stock</span>
            <?php elseif (count($running_low)): ?>Below reorder level or under 2 weeks left
            <?php else: ?>Every item has at least 2 weeks of stock<?php endif; ?>
        </div>
    </div>
    <div class="kpi">
        <div class="kpi-label">Pending requests</div>
        <div class="kpi-value <?php echo $pending_count ? 'is-warn' : ''; ?>"><?php echo $pending_count; ?></div>
        <div class="kpi-note">
            <?php if ($pending_count): ?>Oldest waiting <?php echo $oldest_days == 0 ? 'since today' : $oldest_days . ' day' . ($oldest_days == 1 ? '' : 's'); ?>
            <?php else: ?>Nothing waiting for approval<?php endif; ?>
        </div>
    </div>
</div>

<!-- Movement + value -->
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
                    <thead><tr><th>Week of</th><th>Received</th><th>Issued</th><th>Net</th></tr></thead>
                    <tbody>
                    <?php foreach ($weekly as $w): $net = $w['received'] - $w['issued']; ?>
                        <tr><td><?php echo $w['week']->format('d M Y'); ?></td><td><?php echo number_format($w['received']); ?></td>
                            <td><?php echo number_format($w['issued']); ?></td><td><?php echo ($net > 0 ? '+' : '') . number_format($net); ?></td></tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </details>
        <?php else: ?>
            <div class="empty-state"><p>No stock received or issued in the last 8 weeks.</p></div>
        <?php endif; ?>
    </div>
    <div class="card">
        <div class="card-head"><h3>Stock value by category</h3></div>
        <?php if ($by_category): ?>
            <?php echo chart_hbars(array_slice($by_category, 0, 8), fn($v) => kwacha_whole((float)$v)); ?>
            <div class="card-foot">Total <?php echo kwacha($stock_value); ?></div>
        <?php else: ?>
            <div class="empty-state"><p>Set unit prices on items to see stock value.</p></div>
        <?php endif; ?>
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
                    <td style="white-space:nowrap"><a href="receive_stock.php?category_id=<?php echo (int)$r['category_id']; ?>&item_id=<?php echo $r['id']; ?>" class="btn btn-sm">Receive</a></td>
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
        <div class="card-head"><h3>Most issued &mdash; last 30 days</h3><a href="view_transactions.php" class="btn btn-sm">Transactions</a></div>
        <?php echo $top_issued ? chart_hbars($top_issued, fn($v) => number_format($v) . ' units') : '<div class="empty-state"><p>Nothing issued in the last 30 days.</p></div>'; ?>
    </div>
    <div class="card">
        <div class="card-head"><h3>Who uses the most &mdash; last 30 days</h3><span class="card-hint">By value issued</span></div>
        <?php echo $top_users ? chart_hbars(array_map(fn($r) => ['label' => $r['label'], 'value' => $r['cost'] > 0 ? $r['cost'] : $r['value'], 'times' => $r['times']], $top_users),
                                             fn($v) => kwacha_whole((float)$v)) : '<div class="empty-state"><p>Nothing issued in the last 30 days.</p></div>'; ?>
    </div>
</div>

<!-- Housekeeping -->
<div class="grid-2">
    <div class="card">
        <div class="card-head">
            <h3>Not moving &mdash; 60+ days</h3>
            <?php if ($idle): ?><span class="card-hint"><?php echo kwacha($idle_value, true); ?> tied up</span><?php endif; ?>
        </div>
        <?php if ($idle): ?>
        <table class="compact">
            <thead><tr><th>Item</th><th class="num">Qty</th><th class="num">Value</th><th>Last issued</th></tr></thead>
            <tbody>
            <?php foreach ($idle as $r): ?>
                <tr><td><?php echo htmlspecialchars($r['item_name']); ?></td><td class="num"><?php echo number_format($r['current_quantity']); ?></td>
                    <td class="num"><?php echo kwacha((float)$r['value']); ?></td>
                    <td class="muted"><?php echo $r['last_issued'] ? date('d M Y', strtotime($r['last_issued'])) : 'Never'; ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state"><p>Every item in stock has been used in the last 60 days.</p></div>
        <?php endif; ?>
    </div>
    <div class="card">
        <div class="card-head"><h3>Recent stock adjustments</h3><a href="adjust_stock.php" class="btn btn-sm">Adjust stock</a></div>
        <?php if ($adjustments): ?>
        <table class="compact">
            <thead><tr><th>When</th><th>Item</th><th class="num">Change</th><th>By</th></tr></thead>
            <tbody>
            <?php foreach ($adjustments as $a):
                $reason = preg_match('/Reason:\s*(.*)$/s', $a['details'] ?? '', $m) ? $m[1] : ''; ?>
                <tr data-tip="<?php echo htmlspecialchars($reason ? 'Reason: ' . $reason : ($a['details'] ?? '')); ?>" tabindex="0">
                    <td class="muted" style="white-space:nowrap"><?php echo date('d M, H:i', strtotime($a['created_at'])); ?></td>
                    <td><?php echo htmlspecialchars($a['item_name'] ?? 'Deleted item'); ?></td>
                    <td class="num <?php echo $a['quantity'] < 0 ? 'neg' : 'pos'; ?>"><?php echo ($a['quantity'] > 0 ? '+' : '') . number_format($a['quantity']); ?></td>
                    <td class="muted"><?php echo htmlspecialchars($a['full_name'] ?? '—'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div class="empty-state"><p>No manual adjustments yet.</p></div>
        <?php endif; ?>
    </div>
</div>

<!-- Report Submission Progress -->
<div class="card" style="margin-bottom:16px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
        <h3 style="margin:0">Monthly Reports &mdash; <?php echo date('F Y'); ?></h3>
        <a href="reports.php" class="btn btn-sm">View All</a>
    </div>
    <div class="progress-track">
        <div class="progress-fill" style="width:<?php echo $total_cats > 0 ? round(($submitted_count / $total_cats) * 100) : 0; ?>%"></div>
    </div>
    <div style="font-size:.74rem; color:var(--muted); margin-bottom:12px"><?php echo $submitted_count; ?> of <?php echo $total_cats; ?> categories submitted</div>
    <div style="display:flex; flex-wrap:wrap; gap:6px;">
        <?php foreach ($reports_data as $r): ?>
        <span style="padding:3px 10px; border-radius:999px; font-size:.72rem; font-weight:500; background:<?php echo $r['submitted'] ? '#f0fdf4' : '#fafafa'; ?>; color:<?php echo $r['submitted'] ? '#166534' : 'var(--muted)'; ?>; border:1px solid <?php echo $r['submitted'] ? '#bbf7d0' : 'var(--border)'; ?>">
            <?php echo htmlspecialchars($r['name']); ?>
        </span>
        <?php endforeach; ?>
    </div>
</div>

<!-- Pending Requests -->
<?php if ($pending_count > 0): ?>
<?php
$pending_requests = $pdo->query("
    SELECT p.*, u.full_name as requester
    FROM procurement p
    JOIN users u ON p.requested_by = u.id
    WHERE p.status = 'pending'
    ORDER BY p.created_at DESC
    LIMIT 5
")->fetchAll();
?>
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:8px;">
        <h3 style="margin:0">Pending Procurement &mdash; <span style="color:var(--warning)"><?php echo $pending_count; ?> request<?php echo $pending_count != 1 ? 's' : ''; ?></span></h3>
        <a href="procurement.php" class="btn btn-sm">View All</a>
    </div>
    <div class="table-container">
        <table>
            <thead>
                <tr><th>Item</th><th>Qty</th><th>Requested By</th><th>Date</th><th></th></tr>
            </thead>
            <tbody>
                <?php foreach ($pending_requests as $req): ?>
                <tr>
                    <td style="font-weight:500"><?php echo htmlspecialchars($req['item_name']); ?></td>
                    <td><?php echo $req['quantity']; ?></td>
                    <td style="color:var(--muted)"><?php echo htmlspecialchars($req['requester']); ?></td>
                    <td style="color:var(--muted); font-size:.76rem; white-space:nowrap"><?php echo date('d/m/Y', strtotime($req['created_at'])); ?></td>
                    <td style="white-space:nowrap">
                        <form method="POST" action="procurement.php" class="inline-form">
                            <input type="hidden" name="id" value="<?php echo $req['id']; ?>">
                            <input type="hidden" name="return" value="manager_dashboard.php">
                            <button type="submit" name="decision" value="approve" class="btn btn-sm btn-success"
                                    data-confirm-title="Approve request"
                                    data-confirm="Approve <?php echo htmlspecialchars($req['quantity'] . ' × ' . $req['item_name']); ?> for <?php echo htmlspecialchars($req['requester']); ?>?"
                                    data-confirm-ok="Approve">Approve</button>
                            <button type="submit" name="decision" value="reject" class="btn btn-sm"
                                    data-confirm-title="Reject request"
                                    data-confirm="Reject the request for <?php echo htmlspecialchars($req['quantity'] . ' × ' . $req['item_name']); ?> from <?php echo htmlspecialchars($req['requester']); ?>?"
                                    data-confirm-ok="Reject" data-confirm-danger="1">Reject</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>