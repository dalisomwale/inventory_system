<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();
if (!isManager()) { header('Location: dashboard.php'); exit(); }

// Get current month for report submission check
$current_month = date('Y-m');

// Overall inventory statistics
$stats = $pdo->query("
    SELECT COUNT(*) as total_items, 
           SUM(current_quantity) as total_quantity,
           SUM(current_quantity * unit_price) as total_value,
           COUNT(CASE WHEN current_quantity <= reorder_level THEN 1 END) as low_stock_count,
           COUNT(CASE WHEN current_quantity = 0 THEN 1 END) as out_of_stock_count
    FROM items
")->fetch();

// Get pending procurement requests
$pending_stmt = $pdo->prepare("
    SELECT p.*, u.full_name as requester 
    FROM procurement p 
    JOIN users u ON p.requested_by=u.id 
    WHERE p.status='pending' 
    ORDER BY p.created_at DESC
");
$pending_stmt->execute();
$pending = $pending_stmt->fetchAll();

// Get low stock items
$low_stock = $pdo->query("
    SELECT * FROM items 
    WHERE current_quantity <= reorder_level 
    ORDER BY 
        CASE 
            WHEN current_quantity = 0 THEN 1 
            WHEN current_quantity <= reorder_level/2 THEN 2 
            ELSE 3 
        END,
        current_quantity ASC
")->fetchAll();

// Get recent activity (excluding login/logout)
$recent = $pdo->query("
    SELECT a.*, u.full_name, i.item_name
    FROM audit_trail a 
    LEFT JOIN users u ON a.user_id=u.id 
    LEFT JOIN items i ON a.item_id=i.id
    WHERE a.action NOT IN ('LOGIN', 'LOGOUT')
    ORDER BY a.created_at DESC 
    LIMIT 20
")->fetchAll();

// Get category summary
$cat_summary = $pdo->query("
    SELECT category,
           COUNT(*) as item_count,
           SUM(current_quantity) as total_quantity,
           SUM(current_quantity * unit_price) as cat_value,
           COUNT(CASE WHEN current_quantity <= reorder_level THEN 1 END) as low_stock_in_cat
    FROM items 
    GROUP BY category 
    ORDER BY cat_value DESC
")->fetchAll();

// Get monthly report submission status
$report_status = $pdo->prepare("
    SELECT 
        c.id, c.name,
        CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END as submitted,
        r.submitted_at,
        r.total_value,
        r.total_items,
        r.total_units,
        r.low_stock_count,
        u.full_name as submitted_by
    FROM categories c
    LEFT JOIN category_monthly_reports r ON c.id = r.category_id AND DATE_FORMAT(r.report_month, '%Y-%m') = ?
    LEFT JOIN users u ON r.submitted_by = u.id
    ORDER BY c.name
");
$report_status->execute([$current_month]);
$reports_data = $report_status->fetchAll();

$total_categories = count($reports_data);
$submitted_count = count(array_filter($reports_data, function($r) { return $r['submitted']; }));
$submission_percentage = $total_categories > 0 ? round(($submitted_count / $total_categories) * 100) : 0;

// Get total pending procurement count
$pending_count = count($pending);

// Get today's activity
$today = date('Y-m-d');
$today_receiving = $pdo->prepare("
    SELECT COUNT(*) as count, SUM(quantity) as units 
    FROM stock_receiving 
    WHERE DATE(created_at) = ?
");
$today_receiving->execute([$today]);
$today_recv = $today_receiving->fetch();

$today_issuing = $pdo->prepare("
    SELECT COUNT(*) as count, SUM(quantity) as units 
    FROM stock_issuing 
    WHERE DATE(created_at) = ?
");
$today_issuing->execute([$today]);
$today_issue = $today_issuing->fetch();

include __DIR__ . '/../includes/header.php';
?>

<style>
.dashboard-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 20px;
    box-shadow: var(--shadow);
    border: 1px solid var(--border);
    text-align: center;
    transition: transform 0.2s;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.stat-number {
    font-size: 2rem;
    font-weight: 700;
    color: var(--accent);
    line-height: 1.2;
    margin-bottom: 8px;
}

.stat-label {
    font-size: 0.85rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-sub {
    font-size: 0.7rem;
    color: var(--muted);
    margin-top: 5px;
}

.stat-warning .stat-number {
    color: var(--danger);
}

.stat-success .stat-number {
    color: var(--success);
}

.action-buttons {
    display: flex;
    gap: 15px;
    margin-bottom: 30px;
    flex-wrap: wrap;
}

.action-btn {
    padding: 10px 20px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: 500;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.action-btn-primary {
    background: var(--accent);
    color: white;
}

.action-btn-primary:hover {
    background: #1d4ed8;
    transform: translateY(-2px);
}

.action-btn-success {
    background: var(--success);
    color: white;
}

.action-btn-success:hover {
    background: #15803d;
    transform: translateY(-2px);
}

.action-btn-warning {
    background: var(--warning);
    color: white;
}

.action-btn-warning:hover {
    background: #b45309;
    transform: translateY(-2px);
}

.action-btn-info {
    background: var(--info);
    color: white;
}

.action-btn-info:hover {
    background: #0e7490;
    transform: translateY(-2px);
}

.report-progress {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 30px;
    border: 1px solid var(--border);
}

.progress-bar-container {
    background: #e5e7eb;
    border-radius: 10px;
    height: 20px;
    overflow: hidden;
    margin: 15px 0;
}

.progress-bar {
    background: var(--success);
    height: 100%;
    border-radius: 10px;
    transition: width 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 0.7rem;
    font-weight: 500;
}

.report-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
    margin-top: 15px;
}

.report-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 12px;
    background: #f9fafb;
    border-radius: 8px;
    border: 1px solid var(--border);
}

.report-item-submitted {
    background: #f0fdf4;
    border-color: #bbf7d0;
}

.report-name {
    font-weight: 500;
}

.report-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 500;
}

.badge-submitted {
    background: #dcfce7;
    color: #166534;
}

.badge-pending {
    background: #fef3c7;
    color: #92400e;
}

.category-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.category-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 18px;
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    color: var(--text);
    display: block;
}

.category-card:hover {
    border-color: var(--accent);
    transform: translateY(-2px);
    box-shadow: var(--shadow);
}

.category-name {
    font-weight: 600;
    font-size: 1rem;
    margin-bottom: 10px;
}

.category-stats {
    display: flex;
    gap: 15px;
    margin-top: 10px;
    font-size: 0.8rem;
}

.category-stat {
    display: flex;
    flex-direction: column;
}

.category-stat-value {
    font-weight: 700;
    font-size: 1rem;
    color: var(--accent);
}

.status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 500;
}

.status-critical {
    background: #fee2e2;
    color: #991b1b;
}

.status-low {
    background: #fef3c7;
    color: #92400e;
}

.status-ok {
    background: #dcfce7;
    color: #166534;
}

.today-activity {
    display: flex;
    gap: 20px;
    margin-bottom: 30px;
}

.activity-card {
    flex: 1;
    background: var(--surface);
    border-radius: var(--radius);
    padding: 15px;
    border: 1px solid var(--border);
    text-align: center;
}

.activity-number {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--accent);
}

.activity-label {
    font-size: 0.8rem;
    color: var(--muted);
}
</style>

<h1>Manager Dashboard</h1>

<!-- Statistics Cards -->
<div class="dashboard-stats">
    <div class="stat-card">
        <div class="stat-number"><?php echo number_format($stats['total_items'] ?? 0); ?></div>
        <div class="stat-label">Total Items</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?php echo number_format($stats['total_quantity'] ?? 0); ?></div>
        <div class="stat-label">Total Stock Units</div>
    </div>
    <div class="stat-card stat-success">
        <div class="stat-number">K<?php echo number_format($stats['total_value'] ?? 0, 2); ?></div>
        <div class="stat-label">Total Inventory Value</div>
    </div>
    <div class="stat-card <?php echo ($stats['low_stock_count'] ?? 0) > 0 ? 'stat-warning' : 'stat-success'; ?>">
        <div class="stat-number"><?php echo $stats['low_stock_count'] ?? 0; ?></div>
        <div class="stat-label">Low Stock Items</div>
        <div class="stat-sub"><?php echo $stats['out_of_stock_count'] ?? 0; ?> out of stock</div>
    </div>
    <div class="stat-card <?php echo $pending_count > 0 ? 'stat-warning' : ''; ?>">
        <div class="stat-number"><?php echo $pending_count; ?></div>
        <div class="stat-label">Pending Requests</div>
    </div>
</div>

<!-- Today's Activity -->
<div class="today-activity">
    <div class="activity-card">
        <div class="activity-number"><?php echo $today_recv['count'] ?? 0; ?></div>
        <div class="activity-label">Receiving Transactions Today</div>
        <div style="font-size: 0.75rem; color: var(--muted);"><?php echo $today_recv['units'] ?? 0; ?> units received</div>
    </div>
    <div class="activity-card">
        <div class="activity-number"><?php echo $today_issue['count'] ?? 0; ?></div>
        <div class="activity-label">Issuing Transactions Today</div>
        <div style="font-size: 0.75rem; color: var(--muted);"><?php echo $today_issue['units'] ?? 0; ?> units issued</div>
    </div>
</div>

<!-- Action Buttons -->
<div class="action-buttons">
    <a href="manage_items.php" class="action-btn action-btn-primary">📦 Manage Items</a>
    <a href="view_transactions.php" class="action-btn action-btn-info">📋 View Transactions</a>
    <a href="reports.php" class="action-btn action-btn-success">📊 Full Reports</a>
    <a href="view_category_reports.php" class="action-btn action-btn-info">📋 Category Reports</a>
    <a href="adjust_stock.php" class="action-btn action-btn-warning">⚙️ Adjust Stock</a>
    <a href="procurement.php" class="action-btn">📝 Procurement Requests</a>
    <a href="monthly_closing.php" class="action-btn">📅 Monthly Closing</a>
</div>

<!-- Monthly Report Submission Progress -->
<div class="report-progress">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
        <h3 style="margin: 0;">Monthly Report Submission Status</h3>
        <span><?php echo $submitted_count; ?> / <?php echo $total_categories; ?> categories submitted</span>
    </div>
    <div class="progress-bar-container">
        <div class="progress-bar" style="width: <?php echo $submission_percentage; ?>%;">
            <?php if ($submission_percentage > 15): ?><?php echo $submission_percentage; ?>%<?php endif; ?>
        </div>
    </div>
    <div class="report-list">
        <?php foreach ($reports_data as $report): ?>
        <div class="report-item <?php echo $report['submitted'] ? 'report-item-submitted' : ''; ?>">
            <span class="report-name"><?php echo htmlspecialchars($report['name']); ?></span>
            <?php if ($report['submitted']): ?>
            <span class="report-badge badge-submitted">
                ✓ Submitted <?php echo date('d/m', strtotime($report['submitted_at'])); ?>
            </span>
            <?php else: ?>
            <span class="report-badge badge-pending">Pending</span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <div style="margin-top: 15px; text-align: right;">
        <a href="view_category_reports.php?month=<?php echo $current_month; ?>" class="btn btn-sm btn-primary">View All Reports →</a>
    </div>
</div>

<!-- Pending Procurement Requests -->
<?php if (count($pending) > 0): ?>
<div class="card">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h3 style="margin:0;">Pending Procurement Requests</h3>
        <a href="procurement.php" class="btn btn-sm btn-primary">View All</a>
    </div>
    <div class="table-container">
         <table>
            <thead>
                 <tr>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Reason</th>
                    <th>Requested By</th>
                    <th>Date</th>
                    <th>Actions</th>
                 </tr>
            </thead>
            <tbody>
                <?php foreach ($pending as $r): ?>
                <tr>
                    <td style="font-weight:500"><?php echo htmlspecialchars($r['item_name']);?></td>
                    <td><?php echo $r['quantity'];?></td>
                    <td style="max-width:250px; white-space:normal"><?php echo htmlspecialchars($r['reason']);?></td>
                    <td><?php echo htmlspecialchars($r['requester']);?></td>
                    <td><?php echo date('d/m/Y', strtotime($r['created_at']));?></td>
                    <td style="white-space:nowrap">
                        <a href="procurement.php?action=approve&id=<?php echo $r['id'];?>" class="btn btn-sm btn-success" onclick="return confirm('Approve this request?')">Approve</a>
                        <a href="procurement.php?action=reject&id=<?php echo $r['id'];?>" class="btn btn-sm btn-danger" onclick="return confirm('Reject this request?')">Reject</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
         </table>
    </div>
</div>
<?php endif; ?>

<!-- Low Stock Items -->
<div class="card" style="border-left: 4px solid <?php echo count($low_stock)>0 ? 'var(--warning)' : 'var(--success)'; ?>">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h3 style="margin:0">
            <?php if (count($low_stock)>0): ?>
                ⚠️ Low Stock Items (<?php echo count($low_stock); ?> items need attention)
            <?php else: ?>
                ✓ All Items Stocked OK
            <?php endif; ?>
        </h3>
        <?php if (count($low_stock)>0): ?>
        <div>
            <a href="reports.php?report=low_stock" class="btn btn-sm btn-warning">View Report</a>
            <a href="procurement.php" class="btn btn-sm btn-primary">Request Multiple</a>
        </div>
        <?php endif; ?>
    </div>
    
    <?php if (count($low_stock)>0): ?>
    <div class="table-container">
         <table>
            <thead>
                 <tr>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Current Stock</th>
                    <th>Reorder Level</th>
                    <th>Status</th>
                    <th>Action</th>
                 </tr>
            </thead>
            <tbody>
                <?php foreach ($low_stock as $item): ?>
                <tr>
                    <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']);?></td>
                    <td><span class="cat-pill <?php echo catClass($item['category']??'Other');?>"><?php echo htmlspecialchars($item['category']??'Other');?></span></td>
                    <td>
                        <?php echo $item['current_quantity']; ?>
                        <?php if ($item['current_quantity'] == 0): ?>
                        <span class="status-badge status-critical" style="margin-left:5px;">Out of Stock</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $item['reorder_level']; ?></td>
                    <td>
                        <?php 
                        $percentage = ($item['current_quantity'] / $item['reorder_level']) * 100;
                        if ($item['current_quantity'] == 0): ?>
                            <span class="status-badge status-critical">Critical</span>
                        <?php elseif ($percentage <= 50): ?>
                            <span class="status-badge status-low">Low</span>
                        <?php else: ?>
                            <span class="status-badge status-low">Below Reorder</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="procurement.php?item_name=<?php echo urlencode($item['item_name']); ?>&qty=<?php echo $item['reorder_level']*2; ?>" class="btn btn-sm btn-warning">Request</a>
                        <a href="receive_stock.php?item=<?php echo $item['id']; ?>" class="btn btn-sm btn-success">Receive</a>
                        <a href="manage_items.php?edit=<?php echo $item['id'];?>" class="btn btn-sm">Edit</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
         </table>
    </div>
    <?php else: ?>
    <div style="text-align:center; padding:30px; color:var(--muted); background:#f9fafb; border-radius:8px;">
        <div style="font-size: 2rem; margin-bottom: 10px;">✓</div>
        <p>All items are above their reorder levels. Good job!</p>
    </div>
    <?php endif; ?>
</div>

<!-- Category Summary -->
<?php if (!empty($cat_summary)): ?>
<div class="card">
    <h3>Stock by Category</h3>
    <div class="category-grid">
        <?php foreach ($cat_summary as $c): ?>
        <div class="category-card" onclick="window.location.href='reports.php?category=<?php echo urlencode($c['category']); ?>'">
            <div class="category-name"><?php echo htmlspecialchars($c['category']); ?></div>
            <div class="category-stats">
                <div class="category-stat">
                    <span class="category-stat-value"><?php echo $c['item_count']; ?></span>
                    <span>Items</span>
                </div>
                <div class="category-stat">
                    <span class="category-stat-value"><?php echo number_format($c['total_quantity']); ?></span>
                    <span>Units</span>
                </div>
                <div class="category-stat">
                    <span class="category-stat-value">K<?php echo number_format($c['cat_value'], 0); ?></span>
                    <span>Value</span>
                </div>
            </div>
            <div style="margin-top: 8px; font-size: 0.7rem;">
                <?php if ($c['low_stock_in_cat'] > 0): ?>
                <span style="color: var(--danger);">⚠️ <?php echo $c['low_stock_in_cat']; ?> low stock items</span>
                <?php else: ?>
                <span style="color: var(--success);">✓ All items healthy</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Recent Activity -->
<div class="card">
    <h3>Recent Activity (Stock Movements Only)</h3>
    <div class="table-container">
         <table>
            <thead>
                 <tr>
                    <th>Time</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Details</th>
                 </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $a): ?>
                <tr>
                    <td style="white-space:nowrap"><?php echo date('d/m/Y H:i', strtotime($a['created_at']));?></td>
                    <td><?php echo htmlspecialchars($a['full_name']??'System');?></td>
                    <td>
                        <?php 
                        $action_class = '';
                        if (strpos($a['action'], 'RECEIVE') !== false) $action_class = 'status-ok';
                        elseif (strpos($a['action'], 'ISSUE') !== false) $action_class = 'status-critical';
                        elseif (strpos($a['action'], 'ADJUST') !== false) $action_class = 'status-low';
                        else $action_class = '';
                        ?>
                        <span class="status-badge <?php echo $action_class; ?>"><?php echo htmlspecialchars($a['action']);?></span>
                    </td>
                    <td><?php echo htmlspecialchars($a['item_name']??'—');?></td>
                    <td><?php echo $a['quantity'] ?: '—';?></td>
                    <td style="font-size:.84rem;color:var(--muted); max-width:300px; white-space:normal">
                        <?php echo htmlspecialchars($a['details']??'—');?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($recent)): ?>
                <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:24px">No recent stock movements.</td></tr>
                <?php endif; ?>
            </tbody>
         </table>
    </div>
    <div style="margin-top:15px; text-align:right">
        <a href="view_transactions.php" class="btn btn-sm">View All Transactions →</a>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>