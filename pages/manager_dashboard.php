<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();
if (!isManager()) { header('Location: dashboard.php'); exit(); }

// Get current month for report submission check
$current_month = date('Y-m');

// Get pending procurement requests count
$pending_count = $pdo->query("SELECT COUNT(*) FROM procurement WHERE status='pending'")->fetchColumn();

// Get low stock items count
$low_stock_count = $pdo->query("
    SELECT COUNT(*) FROM items WHERE current_quantity <= reorder_level
")->fetchColumn();

// Get total items count
$total_items = $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();

// Get monthly report submission status
$report_status = $pdo->prepare("
    SELECT 
        c.id, c.name,
        CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END as submitted,
        r.submitted_at,
        r.total_value
    FROM categories c
    LEFT JOIN category_monthly_reports r ON c.id = r.category_id AND DATE_FORMAT(r.report_month, '%Y-%m') = ?
    ORDER BY c.name
");
$report_status->execute([$current_month]);
$reports_data = $report_status->fetchAll();

$total_categories = count($reports_data);
$submitted_count = count(array_filter($reports_data, function($r) { return $r['submitted']; }));

include __DIR__ . '/../includes/header.php';
?>

<style>
.dashboard-container {
    max-width: 1400px;
    margin: 0 auto;
}

/* Stats Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 20px;
    border: 1px solid var(--border);
    text-align: center;
}

.stat-number {
    font-size: 2rem;
    font-weight: 700;
    color: var(--text);
    line-height: 1.2;
    margin-bottom: 5px;
}

.stat-label {
    font-size: 0.8rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-card.low-stock .stat-number {
    color: var(--danger);
}

/* Navigation Cards */
.nav-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 30px;
}

.nav-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 20px;
    text-decoration: none;
    color: var(--text);
    text-align: center;
    transition: all 0.2s;
}

.nav-card:hover {
    border-color: var(--accent);
    transform: translateY(-2px);
    box-shadow: var(--shadow);
}

.nav-icon {
    font-size: 2rem;
    margin-bottom: 10px;
}

.nav-title {
    font-weight: 600;
    font-size: 0.9rem;
}

.nav-desc {
    font-size: 0.7rem;
    color: var(--muted);
    margin-top: 5px;
}

/* Report Progress Section */
.report-section {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 20px;
    border: 1px solid var(--border);
    margin-bottom: 30px;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    flex-wrap: wrap;
    gap: 10px;
}

.section-title {
    font-size: 1rem;
    font-weight: 600;
    margin: 0;
}

.progress-bar-container {
    background: #e5e7eb;
    border-radius: 10px;
    height: 8px;
    overflow: hidden;
    margin: 15px 0;
}

.progress-bar {
    background: var(--success);
    height: 100%;
    border-radius: 10px;
    transition: width 0.3s ease;
}

.report-list {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 15px;
}

.report-item {
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    background: #f3f4f6;
    color: var(--text);
}

.report-item.submitted {
    background: #d4edda;
    color: #155724;
}

.report-item.pending {
    background: #fff3cd;
    color: #856404;
}

/* Action Buttons */
.action-buttons {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 30px;
}

.btn {
    padding: 10px 20px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-size: 0.85rem;
    text-decoration: none;
    display: inline-block;
    font-weight: 500;
}

.btn-primary {
    background: var(--accent);
    color: white;
}

.btn-primary:hover {
    background: #1d4ed8;
}

.btn-outline {
    background: transparent;
    border: 1px solid var(--border);
    color: var(--text);
}

.btn-outline:hover {
    border-color: var(--accent);
    color: var(--accent);
}

/* Pending Requests Table */
.pending-table {
    width: 100%;
    border-collapse: collapse;
}

.pending-table th {
    text-align: left;
    padding: 10px;
    background: #f9fafb;
    border-bottom: 2px solid var(--border);
    font-size: 0.8rem;
    font-weight: 600;
}

.pending-table td {
    padding: 10px;
    border-bottom: 1px solid var(--border);
    font-size: 0.85rem;
}

.empty-state {
    text-align: center;
    padding: 40px;
    color: var(--muted);
}
</style>

<div class="dashboard-container">
    <h1>Manager Dashboard</h1>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-number"><?php echo $total_items; ?></div>
            <div class="stat-label">Total Items</div>
        </div>
        <div class="stat-card <?php echo $low_stock_count > 0 ? 'low-stock' : ''; ?>">
            <div class="stat-number"><?php echo $low_stock_count; ?></div>
            <div class="stat-label">Low Stock Items</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $pending_count; ?></div>
            <div class="stat-label">Pending Requests</div>
        </div>
        <div class="stat-card">
            <div class="stat-number"><?php echo $submitted_count; ?> / <?php echo $total_categories; ?></div>
            <div class="stat-label">Reports Submitted</div>
        </div>
    </div>

    <!-- Navigation Cards -->
    <div class="nav-grid">
        <a href="manage_items.php" class="nav-card">
            <div class="nav-icon">📦</div>
            <div class="nav-title">Manage Items</div>
            <div class="nav-desc">Add, edit, or delete items</div>
        </a>
        <a href="view_transactions.php" class="nav-card">
            <div class="nav-icon">📋</div>
            <div class="nav-title">Transactions</div>
            <div class="nav-desc">View all stock movements</div>
        </a>
        <a href="reports.php" class="nav-card">
            <div class="nav-icon">📊</div>
            <div class="nav-title">Monthly Reports</div>
            <div class="nav-desc">View category reports</div>
        </a>
        <a href="procurement.php" class="nav-card">
            <div class="nav-icon">📝</div>
            <div class="nav-title">Procurement</div>
            <div class="nav-desc">Manage requests</div>
        </a>
    </div>

    <!-- Report Submission Progress -->
    <div class="report-section">
        <div class="section-header">
            <h3 class="section-title">Monthly Reports - <?php echo date('F Y'); ?></h3>
            <a href="reports.php" class="btn btn-outline btn-sm">View All Reports →</a>
        </div>
        <div class="progress-bar-container">
            <div class="progress-bar" style="width: <?php echo $total_categories > 0 ? round(($submitted_count / $total_categories) * 100) : 0; ?>%;"></div>
        </div>
        <div class="report-list">
            <?php foreach ($reports_data as $report): ?>
            <span class="report-item <?php echo $report['submitted'] ? 'submitted' : 'pending'; ?>">
                <?php echo htmlspecialchars($report['name']); ?>
            </span>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Pending Procurement Requests -->
    <?php if ($pending_count > 0): ?>
    <div class="report-section">
        <div class="section-header">
            <h3 class="section-title">Pending Procurement Requests</h3>
            <a href="procurement.php" class="btn btn-outline btn-sm">View All →</a>
        </div>
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
        <table class="pending-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Requested By</th>
                    <th>Date</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pending_requests as $req): ?>
                <tr>
                    <td><?php echo htmlspecialchars($req['item_name']); ?></td>
                    <td><?php echo $req['quantity']; ?></td>
                    <td><?php echo htmlspecialchars($req['requester']); ?></td>
                    <td><?php echo date('d/m/Y', strtotime($req['created_at'])); ?></td>
                    <td>
                        <a href="procurement.php?action=approve&id=<?php echo $req['id']; ?>" class="btn btn-primary btn-sm" style="padding: 4px 10px; font-size: 0.7rem;" onclick="return confirm('Approve this request?')">Approve</a>
                        <a href="procurement.php?action=reject&id=<?php echo $req['id']; ?>" class="btn btn-outline btn-sm" style="padding: 4px 10px; font-size: 0.7rem;" onclick="return confirm('Reject this request?')">Reject</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>