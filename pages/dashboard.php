<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();
if (isManager()) { header('Location: manager_dashboard.php'); exit(); }

// Get total items count only - NO inventory value for front desk
$total_items = $pdo->query("SELECT COUNT(*) as total_items FROM items")->fetchColumn();

// Get ALL items for reference
$all_items_count = $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();

// Low stock items - always fetch them
$low_stmt = $pdo->prepare("
    SELECT * FROM items 
    WHERE current_quantity <= reorder_level 
    ORDER BY 
        CASE 
            WHEN current_quantity = 0 THEN 1 
            WHEN current_quantity <= reorder_level/2 THEN 2 
            ELSE 3 
        END,
        current_quantity ASC
");
$low_stmt->execute();
$low_stock = $low_stmt->fetchAll();

// Also get items that are above reorder level for comparison
$healthy_count = $pdo->query("
    SELECT COUNT(*) FROM items 
    WHERE current_quantity > reorder_level
")->fetchColumn();

// Get critical items (out of stock or very low)
$critical_count = $pdo->query("
    SELECT COUNT(*) FROM items 
    WHERE current_quantity = 0 OR current_quantity <= reorder_level/2
")->fetchColumn();

// Get pending procurement requests count
$pending_procurement = $pdo->query("
    SELECT COUNT(*) FROM procurement WHERE status = 'pending'
")->fetchColumn();

// Get submitted reports for current month
$current_month = date('Y-m');
$submitted_reports = $pdo->prepare("
    SELECT COUNT(*) as count FROM category_monthly_reports 
    WHERE DATE_FORMAT(report_month, '%Y-%m') = ? AND submitted_by = ?
");
$submitted_reports->execute([$current_month, $_SESSION['user_id']]);
$reports_submitted = $submitted_reports->fetchColumn();

// Get total categories count
$total_categories = $pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();

// Recent transactions - EXCLUDING login/logout
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
    padding: 12px 24px;
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

.low-stock-section {
    margin-bottom: 30px;
}

.low-stock-card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 20px;
    border: 1px solid var(--border);
    border-left: 4px solid var(--warning);
}

.low-stock-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 10px;
}

.low-stock-title {
    font-size: 1rem;
    font-weight: 600;
    margin: 0;
}

.empty-state {
    text-align: center;
    padding: 40px;
    background: #f9fafb;
    border-radius: 8px;
    color: var(--muted);
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

.report-progress {
    background: #f0fdf4;
    border-left: 4px solid var(--success);
}

.report-progress .stat-number {
    color: var(--success);
}
</style>

<h1>Front Desk Dashboard</h1>

<!-- Statistics Cards -->
<div class="dashboard-stats">
    <div class="stat-card">
        <div class="stat-number"><?php echo $total_items ?: 0; ?></div>
        <div class="stat-label">Total Items</div>
    </div>
    <div class="stat-card <?php echo count($low_stock) > 0 ? 'stat-warning' : 'stat-success'; ?>">
        <div class="stat-number"><?php echo count($low_stock); ?></div>
        <div class="stat-label">Low Stock Items</div>
    </div>
    <div class="stat-card <?php echo $critical_count > 0 ? 'stat-warning' : ''; ?>">
        <div class="stat-number"><?php echo $critical_count; ?></div>
        <div class="stat-label">Critical Items</div>
    </div>
    <div class="stat-card stat-success">
        <div class="stat-number"><?php echo $healthy_count; ?></div>
        <div class="stat-label">Healthy Stock</div>
    </div>
    <div class="stat-card report-progress">
        <div class="stat-number"><?php echo $reports_submitted; ?>/<?php echo $total_categories; ?></div>
        <div class="stat-label">Reports Submitted</div>
    </div>
</div>

<!-- Action Buttons -->
<div class="action-buttons">
    <a href="receive_stock.php" class="action-btn action-btn-success">📥 Receive Stock</a>
    <a href="issue_stock.php" class="action-btn action-btn-primary">📤 Issue Stock</a>
    <a href="procurement.php" class="action-btn action-btn-warning">📝 Request Procurement</a>
    <a href="submit_category_report.php" class="action-btn action-btn-info">📊 Submit Monthly Report</a>
    <a href="category_reports.php" class="action-btn">📈 View Reports</a>
</div>

<!-- Low Stock Section -->
<div class="low-stock-section">
    <div class="low-stock-card">
        <div class="low-stock-header">
            <h3 class="low-stock-title">
                <?php if (count($low_stock) > 0): ?>
                    ⚠️ Low Stock Alert (<?php echo count($low_stock); ?> item<?php echo count($low_stock) != 1 ? 's' : ''; ?> need attention)
                <?php else: ?>
                    ✓ All Stock Levels Healthy
                <?php endif; ?>
            </h3>
            <?php if (count($low_stock) > 0): ?>
            <a href="procurement.php" class="btn btn-sm btn-warning">Request Multiple Items</a>
            <?php endif; ?>
        </div>
        
        <?php if (count($low_stock) > 0): ?>
        <div class="table-container">
             <table>
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Category</th>
                        <th>Current Stock</th>
                        <th>Reorder Level</th>
                        <th>Status</th>
                        <th>Actions</th>
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
                                <span class="status-badge status-critical" style="margin-left:8px;">OUT OF STOCK</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $item['reorder_level']; ?></td>
                        <td>
                            <?php 
                            $percentage = $item['reorder_level'] > 0 ? ($item['current_quantity'] / $item['reorder_level']) * 100 : 0;
                            if ($item['current_quantity'] == 0): ?>
                                <span class="status-badge status-critical">Critical</span>
                            <?php elseif ($percentage <= 25): ?>
                                <span class="status-badge status-critical">Very Low</span>
                            <?php elseif ($percentage <= 50): ?>
                                <span class="status-badge status-low">Low</span>
                            <?php elseif ($percentage <= 75): ?>
                                <span class="status-badge status-low">Getting Low</span>
                            <?php else: ?>
                                <span class="status-badge status-low">Below Reorder</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap">
                            <a href="receive_stock.php?item=<?php echo $item['id']; ?>" class="btn btn-sm">Receive</a>
                            <a href="issue_stock.php?item=<?php echo $item['id']; ?>" class="btn btn-sm">Issue</a>
                            <a href="procurement.php?item_name=<?php echo urlencode($item['item_name']); ?>&qty=<?php echo $item['reorder_level'] * 2; ?>" class="btn btn-sm">Request</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <div style="font-size: 3rem; margin-bottom: 10px;">✓</div>
            <h3 style="color: var(--success); margin-bottom: 10px;">All Stock Levels are Healthy</h3>
            <p style="color: var(--text2); margin-bottom: 15px;">
                Great job! All <?php echo $healthy_count; ?> items in your inventory are above their reorder levels.
            </p>
            <div style="display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;">
                <a href="receive_stock.php" class="btn btn-success">Receive New Stock</a>
                <a href="issue_stock.php" class="btn btn-primary">Issue Stock</a>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Summary Stats -->
        <div style="margin-top: 15px; display: flex; gap: 15px; flex-wrap: wrap; border-top: 1px solid var(--border); padding-top: 15px;">
            <div style="font-size: .85rem;">
                <span style="color: var(--muted);">Total Items:</span> 
                <strong><?php echo $all_items_count; ?></strong>
            </div>
            <div style="font-size: .85rem;">
                <span style="color: var(--muted);">Healthy Stock:</span> 
                <strong style="color: var(--success);"><?php echo $healthy_count; ?></strong>
            </div>
            <?php if (count($low_stock) > 0): ?>
            <div style="font-size: .85rem;">
                <span style="color: var(--muted);">Need Attention:</span> 
                <strong style="color: var(--danger);"><?php echo count($low_stock); ?></strong>
            </div>
            <?php endif; ?>
            <?php if ($critical_count > 0): ?>
            <div style="font-size: .85rem;">
                <span style="color: var(--muted);">Critical:</span> 
                <strong style="color: var(--danger);"><?php echo $critical_count; ?></strong>
            </div>
            <?php endif; ?>
            <?php if ($pending_procurement > 0): ?>
            <div style="font-size: .85rem;">
                <span style="color: var(--muted);">Pending Requests:</span> 
                <strong style="color: var(--warning);"><?php echo $pending_procurement; ?></strong>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Recent Transactions -->
<div class="card">
    <h3>Your Recent Transactions</h3>
    <?php if (!empty($recent)): ?>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Qty</th>
                    <th>Details</th>
                    <th>Date & Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $t): ?>
                <tr>
                    <td><span class="status-<?php echo strtolower($t['type']);?>"><?php echo $t['type'];?></span></td>
                    <td style="font-weight:500"><?php echo htmlspecialchars($t['item_name']);?></td>
                    <td><span class="cat-pill <?php echo catClass($t['category']??'Other');?>"><?php echo htmlspecialchars($t['category']??'Other');?></span></td>
                    <td><strong><?php echo $t['quantity'];?></strong></td>
                    <td style="max-width:200px; white-space:normal; color:var(--muted); font-size:.84rem;">
                        <?php echo $t['type'] === 'Received' ? 'From: ' . htmlspecialchars($t['supplier']??'') : 'To: ' . htmlspecialchars($t['supplier']??''); ?>
                    </td>
                    <td style="white-space:nowrap"><?php echo date('d/m/Y H:i', strtotime($t['created_at']));?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <p>No transactions yet. Start by receiving or issuing stock.</p>
    </div>
    <?php endif; ?>
    <div style="margin-top:15px; text-align:right">
        <a href="view_transactions.php" class="btn btn-sm">View All Transactions →</a>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>