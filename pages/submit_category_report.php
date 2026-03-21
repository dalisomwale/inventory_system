<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();
if (isManager()) { header('Location: manager_dashboard.php'); exit(); }

// Get current month
$current_month = date('Y-m');
$selected_month = isset($_GET['month']) ? $_GET['month'] : $current_month;
$selected_category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

// Get all categories
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

// Get previous month's closing stock for opening balance
$prev_month = date('Y-m', strtotime($selected_month . '-01 -1 month'));
$prev_closing = $pdo->prepare("
    SELECT items_data FROM category_monthly_reports 
    WHERE category_id = ? AND DATE_FORMAT(report_month, '%Y-%m') = ?
");
$prev_closing->execute([$selected_category_id, $prev_month]);
$prev_report = $prev_closing->fetch();

// Check if report already submitted for this month and category
$report_check = $pdo->prepare("
    SELECT * FROM category_monthly_reports 
    WHERE category_id = ? AND DATE_FORMAT(report_month, '%Y-%m') = ?
");
$report_check->execute([$selected_category_id, $selected_month]);
$existing_report = $report_check->fetch();

// Get items for selected category
$items = [];
$category_name = '';
if ($selected_category_id > 0) {
    $cat_stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
    $cat_stmt->execute([$selected_category_id]);
    $category_name = $cat_stmt->fetchColumn();
    
    $items_stmt = $pdo->prepare("
        SELECT * FROM items 
        WHERE category_id = ? 
        ORDER BY item_name
    ");
    $items_stmt->execute([$selected_category_id]);
    $items = $items_stmt->fetchAll();
}

// Prepare previous stock data for opening balance
$prev_stock_data = [];
if ($prev_report) {
    $prev_items = json_decode($prev_report['items_data'], true);
    foreach ($prev_items as $item) {
        $prev_stock_data[$item['item_name']] = $item['closing_stock'];
    }
}

// Prepare stock data for report
$stock_data = [];
$total_items_count = 0;
$total_units = 0;
$low_stock_count = 0;

foreach ($items as $item) {
    $opening_stock = $prev_stock_data[$item['item_name']] ?? 0;
    $closing_stock = $item['current_quantity'];
    $status = $closing_stock <= $item['reorder_level'] ? 'Low Stock' : 'OK';
    
    $stock_data[] = [
        'item_name' => $item['item_name'],
        'opening_stock' => $opening_stock,
        'closing_stock' => $closing_stock,
        'reorder_level' => $item['reorder_level'],
        'status' => $status
    ];
    
    $total_items_count++;
    $total_units += $closing_stock;
    if ($closing_stock <= $item['reorder_level']) $low_stock_count++;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report'])) {
    $category_id = (int)$_POST['category_id'];
    $report_month = $_POST['report_month'];
    $notes = trim($_POST['notes']);
    
    $items_json = json_encode($stock_data);
    
    $pdo->beginTransaction();
    try {
        // Check if report already exists
        $check = $pdo->prepare("
            SELECT id FROM category_monthly_reports 
            WHERE category_id = ? AND DATE_FORMAT(report_month, '%Y-%m') = ?
        ");
        $check->execute([$category_id, $report_month]);
        
        if ($check->fetch()) {
            // Update existing report
            $update = $pdo->prepare("
                UPDATE category_monthly_reports 
                SET items_data = ?, total_items = ?, total_units = ?, 
                    low_stock_count = ?, notes = ?, submitted_by = ?, submitted_at = NOW()
                WHERE category_id = ? AND DATE_FORMAT(report_month, '%Y-%m') = ?
            ");
            $update->execute([
                $items_json, $total_items_count, $total_units,
                $low_stock_count, $notes, $_SESSION['user_id'],
                $category_id, $report_month
            ]);
            $message = "Report updated successfully!";
        } else {
            // Insert new report
            $insert = $pdo->prepare("
                INSERT INTO category_monthly_reports 
                (category_id, report_month, items_data, total_items, total_units, low_stock_count, notes, submitted_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insert->execute([
                $category_id, $report_month . '-01', $items_json, $total_items_count, 
                $total_units, $low_stock_count, $notes, $_SESSION['user_id']
            ]);
            $message = "Report submitted successfully!";
        }
        
        logAudit($pdo, $_SESSION['user_id'], 'SUBMIT_CATEGORY_REPORT', null, null, 
                 "Submitted monthly report for category: $category_name for month: $report_month");
        
        $pdo->commit();
        $success = $message;
        
        // Refresh
        header("Location: submit_category_report.php?category_id=$category_id&month=$report_month");
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error submitting report: " . $e->getMessage();
    }
}

include __DIR__ . '/../includes/header.php';
?>

<style>
.report-container {
    max-width: 1400px;
    margin: 0 auto;
}

.month-selector {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 25px;
    border: 1px solid var(--border);
    display: flex;
    gap: 20px;
    align-items: center;
    flex-wrap: wrap;
}

.category-selector {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 25px;
}

.category-btn {
    padding: 10px 20px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: var(--surface);
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    color: var(--text);
}

.category-btn:hover {
    border-color: var(--accent);
    background: var(--accent-lt);
}

.category-btn.active {
    background: var(--accent);
    color: white;
    border-color: var(--accent);
}

.report-card {
    background: var(--surface);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    padding: 20px;
    margin-bottom: 25px;
}

.report-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid var(--accent);
}

.report-title {
    font-size: 1.3rem;
    font-weight: 600;
    color: var(--text);
}

.report-badge {
    display: inline-block;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: 500;
}

.badge-submitted {
    background: #d4edda;
    color: #155724;
}

.badge-pending {
    background: #fff3cd;
    color: #856404;
}

.stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 25px;
}

.stat-box {
    background: #f9fafb;
    border-radius: 8px;
    padding: 15px;
    text-align: center;
    border: 1px solid var(--border);
}

.stat-number {
    font-size: 1.8rem;
    font-weight: 700;
    color: var(--accent);
    margin-bottom: 5px;
}

.stat-label {
    font-size: 0.85rem;
    color: var(--muted);
}

.items-table {
    width: 100%;
    overflow-x: auto;
    margin-top: 20px;
}

.items-table table {
    width: 100%;
    border-collapse: collapse;
}

.items-table th {
    text-align: left;
    padding: 12px;
    background: #f9fafb;
    border-bottom: 2px solid var(--border);
    font-weight: 600;
}

.items-table td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--border);
}

.notes-section {
    margin-top: 25px;
    padding: 20px;
    background: #f9fafb;
    border-radius: 8px;
}

.notes-section textarea {
    width: 100%;
    padding: 12px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-family: var(--font);
    resize: vertical;
    min-height: 80px;
}

.form-actions {
    margin-top: 25px;
    display: flex;
    gap: 15px;
    justify-content: flex-end;
}

.btn-submit {
    background: var(--success);
    color: white;
    padding: 12px 30px;
    font-size: 1rem;
}

.btn-submit:hover {
    background: #15803d;
}

.info-note {
    background: #e6f7ff;
    border-left: 4px solid var(--accent);
    padding: 12px 15px;
    margin-bottom: 20px;
    border-radius: 5px;
    font-size: 0.85rem;
}

.status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 500;
}

.status-low {
    background: #fef3c7;
    color: #92400e;
}

.status-ok {
    background: #dcfce7;
    color: #166534;
}
</style>

<div class="report-container">
    <h1>Submit Monthly Stock Report</h1>
    
    <div class="month-selector">
        <label><strong>Select Month:</strong></label>
        <input type="month" id="monthSelect" value="<?php echo $selected_month; ?>" onchange="window.location.href='?month='+this.value + '&category_id=<?php echo $selected_category_id; ?>'">
        <span style="color: var(--muted); font-size: 0.85rem;">Reports show opening stock, closing stock, and stock status</span>
    </div>
    
    <div class="category-selector">
        <?php foreach ($categories as $cat): ?>
        <?php 
        $report_status = $pdo->prepare("SELECT submitted_at FROM category_monthly_reports WHERE category_id = ? AND DATE_FORMAT(report_month, '%Y-%m') = ?");
        $report_status->execute([$cat['id'], $selected_month]);
        $submitted = $report_status->fetch();
        ?>
        <a href="?category_id=<?php echo $cat['id']; ?>&month=<?php echo $selected_month; ?>" 
           class="category-btn <?php echo $selected_category_id == $cat['id'] ? 'active' : ''; ?>">
            <?php echo htmlspecialchars($cat['name']); ?>
            <?php if ($submitted): ?> ✓ <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
    
    <?php if ($selected_category_id > 0 && $category_name): ?>
    
    <div class="info-note">
        <strong>📋 Report for: <?php echo htmlspecialchars($category_name); ?></strong> - 
        Month: <?php echo date('F Y', strtotime($selected_month . '-01')); ?>
        <?php if ($existing_report): ?>
        <span style="float: right;">✓ Submitted on: <?php echo date('d/m/Y H:i', strtotime($existing_report['submitted_at'])); ?></span>
        <?php endif; ?>
    </div>
    
    <div class="report-card">
        <div class="report-header">
            <div class="report-title">Stock Report</div>
            <?php if ($existing_report): ?>
            <span class="report-badge badge-submitted">Report Submitted</span>
            <?php else: ?>
            <span class="report-badge badge-pending">Pending Submission</span>
            <?php endif; ?>
        </div>
        
        <div class="stats-row">
            <div class="stat-box">
                <div class="stat-number"><?php echo $total_items_count; ?></div>
                <div class="stat-label">Total Items</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo number_format($total_units); ?></div>
                <div class="stat-label">Total Stock Units</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo $low_stock_count; ?></div>
                <div class="stat-label">Low Stock Items</div>
            </div>
        </div>
        
        <div class="items-table">
            <h4>Stock Details</h4>
            <table>
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Opening Stock</th>
                        <th>Closing Stock</th>
                        <th>Reorder Level</th>
                        <th>Status</th>
                    </thead>
                <tbody>
                    <?php foreach ($stock_data as $item): ?>
                    <tr>
                        <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']); ?></td>
                        <td><?php echo $item['opening_stock']; ?></td>
                        <td><strong><?php echo $item['closing_stock']; ?></strong></td>
                        <td><?php echo $item['reorder_level']; ?></td>
                        <td>
                            <?php if ($item['status'] == 'Low Stock'): ?>
                                <span class="status-badge status-low">⚠️ Low Stock</span>
                            <?php else: ?>
                                <span class="status-badge status-ok">✓ OK</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <form method="POST" class="notes-section">
            <input type="hidden" name="category_id" value="<?php echo $selected_category_id; ?>">
            <input type="hidden" name="report_month" value="<?php echo $selected_month; ?>">
            <label><strong>Notes (Optional):</strong></label>
            <textarea name="notes" placeholder="Add any notes about this month's stock..."><?php echo htmlspecialchars($existing_report['notes'] ?? ''); ?></textarea>
            
            <div class="form-actions">
                <?php if ($existing_report): ?>
                <button type="submit" name="submit_report" class="btn btn-warning">Update Report</button>
                <?php else: ?>
                <button type="submit" name="submit_report" class="btn btn-success btn-submit">✓ Submit Report</button>
                <?php endif; ?>
                <a href="dashboard.php" class="btn">Cancel</a>
            </div>
        </form>
    </div>
    
    <?php elseif ($selected_category_id == 0): ?>
    <div class="report-card" style="text-align: center; padding: 60px;">
        <div style="font-size: 3rem; margin-bottom: 15px;">📊</div>
        <h3>Select a Category to Submit Report</h3>
        <p style="color: var(--muted);">Choose a category from the buttons above to view and submit the monthly stock report.</p>
    </div>
    <?php endif; ?>
</div>

<script>
setTimeout(function() {
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        alert.style.opacity = '0';
        setTimeout(function() { alert.remove(); }, 400);
    });
}, 5000);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>