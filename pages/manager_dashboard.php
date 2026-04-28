<?php
require_once __DIR__ . '/../config/database.php';
redirectIfNotLoggedIn();
if (!isManager()) { header('Location: dashboard.php'); exit(); }

$current_month   = date('Y-m');
$pending_count   = $pdo->query("SELECT COUNT(*) FROM procurement WHERE status='pending'")->fetchColumn();
$low_stock_count = $pdo->query("SELECT COUNT(*) FROM items WHERE current_quantity <= reorder_level")->fetchColumn();
$total_items     = $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();

// SQLite compatible query - use strftime instead of DATE_FORMAT
$report_status = $pdo->prepare("
    SELECT c.id, c.name,
           CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END as submitted,
           r.submitted_at
    FROM categories c
    LEFT JOIN category_monthly_reports r ON c.id = r.category_id AND strftime('%Y-%m', r.report_month) = ?
    ORDER BY c.name
");
$report_status->execute([$current_month]);
$reports_data    = $report_status->fetchAll();
$total_cats      = count($reports_data);
$submitted_count = 0;
foreach ($reports_data as $r) {
    if ($r['submitted']) $submitted_count++;
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Inventory Management System - Manager Dashboard</h1>
    <div style="font-size:.78rem; color:var(--muted)"><?php echo date('l, d F Y'); ?></div>
</div>

<!-- Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo $total_items; ?></div>
        <div class="stat-label">Total Items</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" style="color:<?php echo $low_stock_count > 0 ? '#f59e0b' : 'inherit'; ?>"><?php echo $low_stock_count; ?></div>
        <div class="stat-label">Low Stock Items</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" style="color:<?php echo $pending_count > 0 ? '#f59e0b' : 'inherit'; ?>"><?php echo $pending_count; ?></div>
        <div class="stat-label">Pending Requests</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $submitted_count; ?>/<?php echo $total_cats; ?></div>
        <div class="stat-label">Reports Submitted</div>
    </div>
</div>

<!-- Navigation Cards -->
<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 24px;">
    <div class="card">
        <div class="card-header">
            <h3>Quick Actions</h3>
        </div>
        <div class="card-body">
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <a href="manage_items.php" style="display: inline-block; padding: 10px 16px; background: #2563eb; color: white; text-decoration: none; border-radius: 8px; text-align: center;">Manage Items</a>
                <a href="procurement.php" style="display: inline-block; padding: 10px 16px; background: #10b981; color: white; text-decoration: none; border-radius: 8px; text-align: center;">View Procurement Requests</a>
                <a href="reports.php" style="display: inline-block; padding: 10px 16px; background: #0891b2; color: white; text-decoration: none; border-radius: 8px; text-align: center;">View Monthly Reports</a>
                <a href="adjust_stock.php" style="display: inline-block; padding: 10px 16px; background: #f59e0b; color: white; text-decoration: none; border-radius: 8px; text-align: center;">Adjust Stock</a>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h3>Report Submission Progress</h3>
        </div>
        <div class="card-body">
            <div style="height: 8px; background: #e2e8f0; border-radius: 4px; overflow: hidden;">
                <div style="width: <?php echo $total_cats > 0 ? round(($submitted_count / $total_cats) * 100) : 0; ?>%; height: 100%; background: #10b981;"></div>
            </div>
            <div style="font-size:.74rem; color:#64748b; margin: 12px 0"><?php echo $submitted_count; ?> of <?php echo $total_cats; ?> categories submitted</div>
            <div style="display:flex; flex-wrap:wrap; gap:6px;">
                <?php foreach ($reports_data as $r): ?>
                <span style="padding:3px 10px; border-radius:999px; font-size:.72rem; font-weight:500; background:<?php echo $r['submitted'] ? '#d1fae5' : '#f1f5f9'; ?>; color:<?php echo $r['submitted'] ? '#065f46' : '#64748b'; ?>;">
                    <?php echo htmlspecialchars($r['name']); ?>
                </span>
                <?php endforeach; ?>
            </div>
        </div>
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
    <div class="card-header">
        <h3>Pending Procurement Requests (<?php echo $pending_count; ?>)</h3>
        <a href="procurement.php" style="padding: 4px 12px; background: #f1f5f9; border-radius: 6px; text-decoration: none; color: #1e293b; font-size: 12px;">View All</a>
    </div>
    <div style="overflow-x: auto;">
        <table style="width:100%; border-collapse: collapse;">
            <thead>
                <tr><th style="padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0;">Item</th><th style="padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0;">Qty</th><th style="padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0;">Requested By</th><th style="padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0;">Date</th><th style="padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0;"></th></tr>
            </thead>
            <tbody>
                <?php foreach ($pending_requests as $req): ?>
                <tr>
                    <td style="padding: 12px; border-bottom: 1px solid #e2e8f0; font-weight:500"><?php echo htmlspecialchars($req['item_name']); ?></td>
                    <td style="padding: 12px; border-bottom: 1px solid #e2e8f0;"><?php echo $req['quantity']; ?></td>
                    <td style="padding: 12px; border-bottom: 1px solid #e2e8f0; color:#64748b"><?php echo htmlspecialchars($req['requester']); ?></td>
                    <td style="padding: 12px; border-bottom: 1px solid #e2e8f0; color:#64748b; font-size:.76rem"><?php echo date('d/m/Y', strtotime($req['created_at'])); ?></td>
                    <td style="padding: 12px; border-bottom: 1px solid #e2e8f0; white-space:nowrap">
                        <a href="procurement.php?action=approve&id=<?php echo $req['id']; ?>" style="padding: 4px 10px; background: #10b981; color: white; text-decoration: none; border-radius: 4px; font-size: 12px; margin-right: 4px;" onclick="return confirm('Approve this request?')">Approve</a>
                        <a href="procurement.php?action=reject&id=<?php echo $req['id']; ?>" style="padding: 4px 10px; background: #ef4444; color: white; text-decoration: none; border-radius: 4px; font-size: 12px;" onclick="return confirm('Reject this request?')">Reject</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
