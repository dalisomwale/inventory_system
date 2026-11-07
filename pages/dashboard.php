<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();
if (isManager()) { header('Location: manager_dashboard.php'); exit(); }

$total_items = $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();

$low_stmt = $pdo->prepare("
    SELECT * FROM items
    WHERE current_quantity <= reorder_level
    ORDER BY CASE WHEN current_quantity = 0 THEN 1 WHEN current_quantity <= reorder_level/2 THEN 2 ELSE 3 END, current_quantity ASC
");
$low_stmt->execute();
$low_stock = $low_stmt->fetchAll();

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

<!-- Stats -->
<div class="dashboard">
    <div class="card">
        <div class="stat"><?php echo $total_items; ?></div>
        <div class="stat-label">Total Items</div>
    </div>
    <div class="card">
        <div class="stat" style="color:<?php echo count($low_stock) > 0 ? 'var(--danger)' : 'var(--success)'; ?>"><?php echo count($low_stock); ?></div>
        <div class="stat-label">Low Stock</div>
    </div>
    <div class="card">
        <div class="stat" style="color:<?php echo $critical_count > 0 ? 'var(--danger)' : 'inherit'; ?>"><?php echo $critical_count; ?></div>
        <div class="stat-label">Critical Items</div>
    </div>
    <div class="card">
        <div class="stat" style="color:var(--success)"><?php echo $healthy_count; ?></div>
        <div class="stat-label">Healthy Stock</div>
    </div>
    <div class="card">
        <div class="stat"><?php echo $reports_submitted; ?>/<?php echo $total_categories; ?></div>
        <div class="stat-label">Reports This Month</div>
    </div>
</div>

<!-- Quick Actions -->
<div class="action-group">
    <a href="receive_stock.php" class="btn btn-success">Receive Stock</a>
    <a href="issue_stock.php" class="btn btn-primary">Issue Stock</a>
    <a href="procurement.php" class="btn btn-warning">Request Procurement</a>
    <a href="submit_category_report.php" class="btn btn-info">Submit Monthly Report</a>
    <a href="category_reports.php" class="btn">View Reports</a>
</div>

<!-- Low Stock -->
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:8px;">
        <h3 style="margin:0">
            <?php if (count($low_stock) > 0): ?>
                Low Stock Alert &mdash; <?php echo count($low_stock); ?> item<?php echo count($low_stock) != 1 ? 's' : ''; ?>
            <?php else: ?>
                Stock Levels
            <?php endif; ?>
        </h3>
        <?php if (count($low_stock) > 0): ?>
        <a href="procurement.php" class="btn btn-sm btn-warning">Request Multiple</a>
        <?php endif; ?>
    </div>

    <?php if (count($low_stock) > 0): ?>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Current</th>
                    <th>Reorder Level</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($low_stock as $item): ?>
                <tr>
                    <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']); ?></td>
                    <td><span class="cat-pill <?php echo catClass($item['category']??'Other');?>"><?php echo htmlspecialchars($item['category']??'Other');?></span></td>
                    <td>
                        <strong><?php echo $item['current_quantity']; ?></strong>
                        <?php if ($item['current_quantity'] == 0): ?>
                            &nbsp;<span class="status-issuing" style="font-size:.68rem">Out of Stock</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $item['reorder_level']; ?></td>
                    <td>
                        <?php
                        $pct = $item['reorder_level'] > 0 ? ($item['current_quantity'] / $item['reorder_level']) * 100 : 0;
                        if ($item['current_quantity'] == 0): ?><span class="status-issuing">Critical</span>
                        <?php elseif ($pct <= 25): ?><span class="status-issuing">Very Low</span>
                        <?php elseif ($pct <= 50): ?><span class="status-pending">Low</span>
                        <?php else: ?><span class="status-pending">Below Reorder</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap">
                        <a href="receive_stock.php?item=<?php echo $item['id']; ?>" class="btn btn-sm">Receive</a>
                        <a href="procurement.php?item_name=<?php echo urlencode($item['item_name']); ?>&qty=<?php echo $item['reorder_level'] * 2; ?>" class="btn btn-sm">Request</a>
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