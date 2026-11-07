<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();
if (isManager()) { header('Location: manager_dashboard.php'); exit(); }

$total_items = $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();

require_once __DIR__ . '/../includes/insights.php';
$forecast  = ins_item_forecast($pdo, 30);
$low_stock = ins_running_low($forecast, 14);
$out_count = count(array_filter($forecast, fn($r) => $r['current_quantity'] <= 0));
$top_issued = ins_top_issued($pdo, 30, 6);
$health     = ins_stock_health($forecast);

$today = $pdo->prepare("
    SELECT
      (SELECT COALESCE(SUM(quantity),0) FROM stock_issuing   WHERE DATE(created_at)=CURDATE()) AS issued_all,
      (SELECT COUNT(*)                  FROM stock_issuing   WHERE DATE(created_at)=CURDATE() AND issued_by=?)   AS issued_mine,
      (SELECT COALESCE(SUM(quantity),0) FROM stock_receiving WHERE DATE(created_at)=CURDATE()) AS received_all");
$today->execute([$_SESSION['user_id']]);
$today = $today->fetch();

$my_pending = $pdo->prepare("SELECT COUNT(*) FROM procurement WHERE status='pending' AND requested_by=?");
$my_pending->execute([$_SESSION['user_id']]);
$my_pending = (int)$my_pending->fetchColumn();

$healthy_count = $pdo->query("SELECT COUNT(*) FROM items WHERE current_quantity > reorder_level")->fetchColumn();
$critical_count = $pdo->query("SELECT COUNT(*) FROM items WHERE current_quantity = 0 OR current_quantity <= reorder_level/2")->fetchColumn();
$pending_procurement = $pdo->query("SELECT COUNT(*) FROM procurement WHERE status = 'pending'")->fetchColumn();

$current_month = date('Y-m');
$submitted_reports = $pdo->prepare("SELECT COUNT(*) FROM category_monthly_reports WHERE DATE_FORMAT(report_month, '%Y-%m') = ? AND submitted_by = ?");
$submitted_reports->execute([$current_month, $_SESSION['user_id']]);
$reports_submitted = $submitted_reports->fetchColumn();
$total_categories = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();

$stmt = $pdo->prepare("
    (SELECT 'Received' as type, i.item_name, i.category, s.quantity, s.created_at, s.supplier
     FROM stock_receiving s JOIN items i ON s.item_id=i.id WHERE s.received_by=? ORDER BY s.created_at DESC LIMIT 5)
    UNION ALL
    (SELECT 'Issued' as type, i.item_name, i.category, s.quantity, s.created_at, s.issued_to as supplier
     FROM stock_issuing s JOIN items i ON s.item_id=i.id WHERE s.issued_by=? ORDER BY s.created_at DESC LIMIT 5)
    ORDER BY created_at DESC LIMIT 10
");
$stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
$recent = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Dashboard</h1>
    <div style="font-size:.78rem; color:var(--muted)"><?php echo date('l, d F Y'); ?></div>
</div>

<!-- Headline numbers -->
<div class="kpi-row">
    <div class="kpi">
        <div class="kpi-label">Need reordering</div>
        <div class="kpi-value <?php echo count($low_stock) ? 'is-bad' : ''; ?>"><?php echo count($low_stock); ?></div>
        <div class="kpi-note"><?php echo count($low_stock) ? 'Below reorder level or under 2 weeks left' : 'All items have at least 2 weeks of stock'; ?></div>
    </div>
    <div class="kpi">
        <div class="kpi-label">Out of stock</div>
        <div class="kpi-value <?php echo $out_count ? 'is-bad' : ''; ?>"><?php echo $out_count; ?></div>
        <div class="kpi-note">of <?php echo $total_items; ?> items</div>
    </div>
    <div class="kpi">
        <div class="kpi-label">Today</div>
        <div class="kpi-value"><?php echo number_format($today['issued_all']); ?> <span class="kpi-unit">issued</span></div>
        <div class="kpi-note"><?php echo number_format($today['received_all']); ?> received &middot; <?php echo (int)$today['issued_mine']; ?> issue<?php echo $today['issued_mine'] == 1 ? '' : 's'; ?> by you</div>
    </div>
    <div class="kpi">
        <div class="kpi-label">Reports this month</div>
        <div class="kpi-value"><?php echo $reports_submitted; ?><span class="kpi-unit">/ <?php echo $total_categories; ?></span></div>
        <div class="kpi-note"><?php echo $my_pending; ?> of your requests awaiting approval</div>
    </div>
</div>

<!-- Quick Actions -->
<div class="action-tiles">
    <?php echo action_tile('receive_stock.php', 'Receive stock', 'Record a delivery', icon('receive')); ?>
    <?php echo action_tile('issue_stock.php', 'Issue stock', 'Give items to a department', icon('issue')); ?>
    <?php echo action_tile('procurement.php', 'Request items', 'Ask the manager to buy', icon('request'), $my_pending ? $my_pending . ' pending' : ''); ?>
    <?php echo action_tile('submit_category_report.php', 'Monthly report', $reports_submitted . ' of ' . $total_categories . ' submitted', icon('report')); ?>
    <?php echo action_tile('category_reports.php', 'View reports', 'Category activity', icon('chart')); ?>
</div>

<!-- Low Stock -->
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:8px;">
        <h3 style="margin:0">
            <?php if (count($low_stock) > 0): ?>
                Running low &mdash; <?php echo count($low_stock); ?> item<?php echo count($low_stock) != 1 ? 's' : ''; ?>
            <?php else: ?>
                Stock Levels
            <?php endif; ?>
        </h3>
        <span class="card-hint">"Days left" is based on how much was issued in the last 30 days</span>
    </div>

    <?php if (count($low_stock) > 0): ?>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Category</th>
                    <th class="num">In stock</th>
                    <th class="num">Reorder at</th>
                    <th>Days left</th>
                    <th class="num">Suggested order</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($low_stock as $item): ?>
                <tr>
                    <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']); ?></td>
                    <td><span class="cat-pill <?php echo catClass($item['category']??'Other');?>"><?php echo htmlspecialchars($item['category']??'Other');?></span></td>
                    <td class="num"><strong><?php echo number_format($item['current_quantity']); ?></strong></td>
                    <td class="num muted"><?php echo number_format($item['reorder_level']); ?></td>
                    <td>
                        <?php $urgent = $item['current_quantity'] <= 0 || ($item['days_left'] !== null && $item['days_left'] < 7); ?>
                        <span class="<?php echo $urgent ? 'status-issuing' : 'status-pending'; ?>"><?php echo $urgent ? '&#9888; ' : ''; ?><?php echo days_left_label($item['days_left'], (int)$item['current_quantity']); ?></span>
                    </td>
                    <td class="num"><?php echo number_format($item['suggest']); ?></td>
                    <td style="white-space:nowrap">
                        <a href="receive_stock.php?category_id=<?php echo (int)$item['category_id']; ?>&item_id=<?php echo $item['id']; ?>" class="btn btn-sm">Receive</a>
                        <a href="procurement.php?item_name=<?php echo urlencode($item['item_name']); ?>&qty=<?php echo max(1, $item['suggest']); ?>&category_id=<?php echo (int)$item['category_id']; ?>" class="btn btn-sm">Request</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <p style="color:var(--success); font-weight:500">All <?php echo $healthy_count; ?> items are above reorder levels.</p>
        <div style="display:flex; gap:8px; justify-content:center; margin-top:12px;">
            <a href="receive_stock.php" class="btn btn-sm btn-success">Receive Stock</a>
            <a href="issue_stock.php" class="btn btn-sm btn-primary">Issue Stock</a>
        </div>
    </div>
    <?php endif; ?>

    <div style="margin-top:12px; padding-top:12px; border-top:1px solid var(--border); display:flex; gap:18px; flex-wrap:wrap; font-size:.76rem; color:var(--muted)">
        <span>Total: <strong style="color:var(--text)"><?php echo $total_items; ?></strong></span>
        <span>Healthy: <strong style="color:var(--success)"><?php echo $healthy_count; ?></strong></span>
        <?php if (count($low_stock) > 0): ?><span>Need Attention: <strong style="color:var(--danger)"><?php echo count($low_stock); ?></strong></span><?php endif; ?>
        <?php if ($pending_procurement > 0): ?><span>Pending Requests: <strong style="color:var(--warning)"><?php echo $pending_procurement; ?></strong></span><?php endif; ?>
    </div>
</div>

<!-- Health + usage -->
<div class="grid-2">
<div class="card">
    <div class="card-head"><h3>Stock health</h3><span class="card-hint">Items by stock level</span></div>
    <?php echo chart_donut([
        ['label' => 'In stock',      'value' => $health['ok'],  'color' => '#0ca30c', 'icon' => '&#10003;'],
        ['label' => 'Below reorder', 'value' => $health['low'], 'color' => '#fab219', 'icon' => '&#9888;'],
        ['label' => 'Out of stock',  'value' => $health['out'], 'color' => '#d03b3b', 'icon' => '&#10005;'],
    ], (string)$total_items, 'items', fn($v) => number_format($v) . ' item' . ($v == 1 ? '' : 's')); ?>
</div>
<div class="card">
    <div class="card-head"><h3>Most issued &mdash; last 30 days</h3></div>
    <?php echo $top_issued ? chart_hbars($top_issued, fn($v) => number_format($v) . ' units') : '<div class="empty-state"><p>Nothing issued in the last 30 days.</p></div>'; ?>
</div>
</div>

<!-- Recent Transactions -->
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
        <h3 style="margin:0">Recent Transactions</h3>
        <a href="view_transactions.php" class="btn btn-sm">View All</a>
    </div>

    <?php if (!empty($recent)): ?>
    <div class="table-container">
        <table>
            <thead>
                <tr><th>Type</th><th>Item</th><th>Category</th><th>Qty</th><th>Details</th><th>Date</th></tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $t): ?>
                <tr>
                    <td><span class="status-<?php echo strtolower($t['type']);?>"><?php echo $t['type'];?></span></td>
                    <td style="font-weight:500"><?php echo htmlspecialchars($t['item_name']);?></td>
                    <td><span class="cat-pill <?php echo catClass($t['category']??'Other');?>"><?php echo htmlspecialchars($t['category']??'Other');?></span></td>
                    <td><?php echo $t['quantity'];?></td>
                    <td style="color:var(--muted); font-size:.76rem"><?php echo $t['type'] === 'Received' ? 'From: '.htmlspecialchars($t['supplier']??'') : 'To: '.htmlspecialchars($t['supplier']??''); ?></td>
                    <td style="white-space:nowrap; color:var(--muted); font-size:.76rem"><?php echo date('d/m/Y H:i', strtotime($t['created_at']));?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><p>No transactions yet. Start by receiving or issuing stock.</p></div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>