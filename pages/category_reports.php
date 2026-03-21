<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

// Get all categories
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

$selected_category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// Get current month for report submission
$current_month = date('Y-m');
$selected_month = isset($_GET['month']) ? $_GET['month'] : $current_month;

$category_data = null;
$items_in_category = [];
$receiving_data = [];
$issuing_data = [];

// Check if report already submitted for this month and category
$existing_report = null;
if ($selected_category_id > 0) {
    $report_check = $pdo->prepare("
        SELECT * FROM category_monthly_reports 
        WHERE category_id = ? AND DATE_FORMAT(report_month, '%Y-%m') = ?
    ");
    $report_check->execute([$selected_category_id, $selected_month]);
    $existing_report = $report_check->fetch();
}

if ($selected_category_id > 0) {
    // Get category info
    $cat_stmt = $pdo->prepare("SELECT id, name FROM categories WHERE id = ?");
    $cat_stmt->execute([$selected_category_id]);
    $category_data = $cat_stmt->fetch();
    
    // Get items in this category
    $items_stmt = $pdo->prepare("
        SELECT id, item_name, current_quantity, reorder_level 
        FROM items 
        WHERE category_id = ? 
        ORDER BY item_name
    ");
    $items_stmt->execute([$selected_category_id]);
    $items_in_category = $items_stmt->fetchAll();
    
    // Get receiving summary for this category within date range
    $recv_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as transaction_count,
            SUM(r.quantity) as total_quantity,
            COUNT(DISTINCT r.supplier) as supplier_count
        FROM stock_receiving r
        JOIN items i ON r.item_id = i.id
        WHERE i.category_id = ? AND DATE(r.created_at) BETWEEN ? AND ?
    ");
    $recv_stmt->execute([$selected_category_id, $start_date, $end_date]);
    $receiving_data = $recv_stmt->fetch();
    
    // Get issuing summary for this category within date range
    $iss_stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as transaction_count,
            SUM(quantity) as total_quantity,
            COUNT(DISTINCT issued_to) as recipient_count
        FROM stock_issuing si
        JOIN items i ON si.item_id = i.id
        WHERE i.category_id = ? AND DATE(si.created_at) BETWEEN ? AND ?
    ");
    $iss_stmt->execute([$selected_category_id, $start_date, $end_date]);
    $issuing_data = $iss_stmt->fetch();
    
    // Get detailed transactions for this category
    $transactions_stmt = $pdo->prepare("
        (SELECT 'Received' as type, r.created_at, i.item_name, r.quantity, r.supplier as counterparty, u.full_name as user_name
         FROM stock_receiving r
         JOIN items i ON r.item_id = i.id
         JOIN users u ON r.received_by = u.id
         WHERE i.category_id = ? AND DATE(r.created_at) BETWEEN ? AND ?)
        UNION ALL
        (SELECT 'Issued' as type, si.created_at, i.item_name, si.quantity, si.issued_to as counterparty, u.full_name as user_name
         FROM stock_issuing si
         JOIN items i ON si.item_id = i.id
         JOIN users u ON si.issued_by = u.id
         WHERE i.category_id = ? AND DATE(si.created_at) BETWEEN ? AND ?)
        ORDER BY created_at DESC
        LIMIT 100
    ");
    $transactions_stmt->execute([$selected_category_id, $start_date, $end_date, $selected_category_id, $start_date, $end_date]);
    $transactions = $transactions_stmt->fetchAll();
}

// Handle report submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report'])) {
    $category_id = (int)$_POST['category_id'];
    $report_month = $_POST['report_month'];
    $notes = trim($_POST['notes']);
    
    // Get items data for this category
    $items_data = [];
    foreach ($items_in_category as $item) {
        $items_data[] = [
            'item_name' => $item['item_name'],
            'opening_stock' => 0,
            'closing_stock' => $item['current_quantity'],
            'reorder_level' => $item['reorder_level'],
            'status' => $item['current_quantity'] <= $item['reorder_level'] ? 'Low Stock' : 'OK'
        ];
    }
    
    $items_json = json_encode($items_data);
    $total_items = count($items_in_category);
    $total_units = array_sum(array_column($items_in_category, 'current_quantity'));
    $low_stock_count = count(array_filter($items_in_category, function($item) {
        return $item['current_quantity'] <= $item['reorder_level'];
    }));
    
    $pdo->beginTransaction();
    try {
        $check = $pdo->prepare("
            SELECT id FROM category_monthly_reports 
            WHERE category_id = ? AND DATE_FORMAT(report_month, '%Y-%m') = ?
        ");
        $check->execute([$category_id, $report_month]);
        
        if ($check->fetch()) {
            $update = $pdo->prepare("
                UPDATE category_monthly_reports 
                SET items_data = ?, total_items = ?, total_units = ?, 
                    low_stock_count = ?, notes = ?, submitted_by = ?, submitted_at = NOW()
                WHERE category_id = ? AND DATE_FORMAT(report_month, '%Y-%m') = ?
            ");
            $update->execute([
                $items_json, $total_items, $total_units,
                $low_stock_count, $notes, $_SESSION['user_id'],
                $category_id, $report_month
            ]);
            $success = "Report updated successfully!";
        } else {
            $insert = $pdo->prepare("
                INSERT INTO category_monthly_reports 
                (category_id, report_month, items_data, total_items, total_units, low_stock_count, notes, submitted_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insert->execute([
                $category_id, $report_month . '-01', $items_json, $total_items, 
                $total_units, $low_stock_count, $notes, $_SESSION['user_id']
            ]);
            $success = "Report submitted successfully!";
        }
        
        logAudit($pdo, $_SESSION['user_id'], 'SUBMIT_CATEGORY_REPORT', null, null, 
                 "Submitted monthly report for category: {$category_data['name']} for month: $report_month");
        
        $pdo->commit();
        
        header("Location: category_reports.php?category_id=$category_id&start_date=$start_date&end_date=$end_date&month=$report_month");
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error submitting report: " . $e->getMessage();
    }
}

include __DIR__ . '/../includes/header.php';
?>

<style>
/* Reports Page Styles */
.filter-card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 30px;
    border: 1px solid var(--border);
}

.stats-grid {
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
    transition: transform 0.2s, box-shadow 0.2s;
    text-align: center;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--text);
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

.stat-card.low-stock .stat-value {
    color: var(--danger);
}

.stat-card.success .stat-value {
    color: var(--success);
}

.section-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--accent);
    display: inline-block;
}

.category-selector {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 25px;
}

.category-btn {
    padding: 8px 16px;
    border: 1.5px solid var(--border);
    border-radius: 20px;
    background: var(--surface);
    cursor: pointer;
    transition: all 0.2s;
    text-decoration: none;
    color: var(--text);
    font-size: 0.85rem;
    position: relative;
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

.category-btn .submitted-badge {
    position: absolute;
    top: -8px;
    right: -8px;
    background: var(--success);
    color: white;
    border-radius: 50%;
    width: 18px;
    height: 18px;
    font-size: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.table-container {
    overflow-x: auto;
    margin-top: 15px;
    margin-bottom: 20px;
}

.items-table {
    width: 100%;
    border-collapse: collapse;
    background: var(--surface);
}

.items-table th {
    text-align: left;
    padding: 12px 15px;
    background: #f9fafb;
    border-bottom: 2px solid var(--border);
    font-weight: 600;
    font-size: 0.85rem;
}

.items-table td {
    padding: 10px 15px;
    border-bottom: 1px solid var(--border);
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

.empty-state {
    text-align: center;
    padding: 60px;
    color: var(--muted);
    background: var(--surface);
    border-radius: var(--radius);
    border: 1px solid var(--border);
}

.empty-state-icon {
    font-size: 3rem;
    margin-bottom: 15px;
}

.info-note {
    background: #e6f7ff;
    border-left: 4px solid var(--accent);
    padding: 12px 15px;
    margin-bottom: 20px;
    border-radius: 5px;
    font-size: 0.85rem;
    color: var(--text2);
}

.report-actions {
    margin-top: 20px;
    display: flex;
    gap: 15px;
    justify-content: flex-end;
    align-items: center;
    flex-wrap: wrap;
}

.btn-submit {
    background: var(--success);
    color: white;
    border: none;
    padding: 12px 30px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 500;
    font-size: 1rem;
}

.btn-submit:hover {
    background: #15803d;
    transform: translateY(-2px);
}

.btn-update {
    background: var(--warning);
    color: white;
}

.btn-update:hover {
    background: #b45309;
}

.submission-status {
    background: #f0fdf4;
    border-left: 4px solid var(--success);
    padding: 10px 15px;
    margin-bottom: 20px;
    border-radius: 5px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
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

.card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 22px;
    box-shadow: var(--shadow);
    margin-bottom: 20px;
    border: 1px solid var(--border);
}
</style>

<h1>Category Reports</h1>

<!-- Info Note -->
<div class="info-note">
    <strong>Monthly Report Submission:</strong> Review the stock levels below and click "Submit Report" to send this month's report to the manager.
</div>

<!-- Month Selector -->
<div class="filter-card">
    <div style="display: flex; gap: 20px; align-items: center; flex-wrap: wrap;">
        <label><strong>Report Month:</strong></label>
        <input type="month" id="monthSelect" value="<?php echo $selected_month; ?>" onchange="window.location.href='?category_id=<?php echo $selected_category_id; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&month='+this.value">
    </div>
</div>

<!-- Category Selection -->
<div class="filter-card">
    <h3 style="margin-top: 0; margin-bottom: 15px;">Select Category</h3>
    <div class="category-selector">
        <?php foreach ($categories as $cat): 
            $report_check = $pdo->prepare("SELECT id FROM category_monthly_reports WHERE category_id = ? AND DATE_FORMAT(report_month, '%Y-%m') = ?");
            $report_check->execute([$cat['id'], $selected_month]);
            $has_report = $report_check->fetch();
        ?>
        <a href="?category_id=<?php echo $cat['id']; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&month=<?php echo $selected_month; ?>" 
           class="category-btn <?php echo $selected_category_id == $cat['id'] ? 'active' : ''; ?>">
            <?php echo htmlspecialchars($cat['name']); ?>
            <?php if ($has_report): ?>
            <span class="submitted-badge">✓</span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($selected_category_id > 0 && $category_data): ?>

<!-- Submission Status -->
<?php if ($existing_report): ?>
<div class="submission-status">
    <span>✓ Report for <?php echo date('F Y', strtotime($selected_month . '-01')); ?> has been submitted.</span>
    <span style="font-size: 0.8rem; color: var(--muted);">Submitted on: <?php echo date('d/m/Y H:i', strtotime($existing_report['submitted_at'])); ?></span>
</div>
<?php endif; ?>

<?php if (isset($success)): ?>
<div class="alert alert-success"><?php echo $success; ?></div>
<?php endif; ?>
<?php if (isset($error)): ?>
<div class="alert alert-danger"><?php echo $error; ?></div>
<?php endif; ?>

<!-- Category Summary -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo count($items_in_category); ?></div>
        <div class="stat-label">Total Items</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $receiving_data['total_quantity'] ?? 0; ?></div>
        <div class="stat-label">Units Received</div>
        <div class="stat-sub"><?php echo date('d/m', strtotime($start_date));?> - <?php echo date('d/m', strtotime($end_date));?></div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo $issuing_data['total_quantity'] ?? 0; ?></div>
        <div class="stat-label">Units Issued</div>
        <div class="stat-sub"><?php echo date('d/m', strtotime($start_date));?> - <?php echo date('d/m', strtotime($end_date));?></div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo ($receiving_data['total_quantity'] ?? 0) - ($issuing_data['total_quantity'] ?? 0); ?></div>
        <div class="stat-label">Net Movement</div>
    </div>
</div>

<!-- Items Table -->
<div class="card">
    <h3 class="section-title">Items in <?php echo htmlspecialchars($category_data['name']); ?></h3>
    <div class="table-container">
        <table class="items-table">
            <thead>
                <tr>
                    <th>Item Name</th>
                    <th>Current Stock</th>
                    <th>Reorder Level</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $low_stock_count = 0;
                foreach ($items_in_category as $item): 
                    $is_low_stock = $item['current_quantity'] <= $item['reorder_level'];
                    if ($is_low_stock) $low_stock_count++;
                ?>
                <tr>
                    <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']); ?></td>
                    <td><?php echo $item['current_quantity']; ?></td>
                    <td><?php echo $item['reorder_level']; ?></td>
                    <td>
                        <?php if ($item['current_quantity'] == 0): ?>
                            <span class="status-badge status-critical">Out of Stock</span>
                        <?php elseif ($is_low_stock): ?>
                            <span class="status-badge status-low">Low Stock</span>
                        <?php else: ?>
                            <span class="status-badge status-ok">OK</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot style="background: #f9fafb; font-weight: bold;">
                <tr>
                    <td colspan="3" style="text-align: right;">Low Stock Items:</td>
                    <td style="color: <?php echo $low_stock_count > 0 ? 'var(--danger)' : 'var(--success)'; ?>">
                        <?php echo $low_stock_count; ?> item<?php echo $low_stock_count != 1 ? 's' : ''; ?>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- Activity Table -->
<div class="card">
    <h3 class="section-title">Recent Activity (<?php echo date('d/m/Y', strtotime($start_date));?> - <?php echo date('d/m/Y', strtotime($end_date));?>)</h3>
    <?php if (!empty($transactions)): ?>
    <div class="table-container">
        <table class="items-table">
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>Type</th>
                    <th>Item</th>
                    <th>Quantity</th>
                    <th>Counterparty</th>
                    <th>User</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $t): ?>
                <tr>
                    <td style="white-space:nowrap"><?php echo date('d/m/Y H:i', strtotime($t['created_at'])); ?></td>
                    <td><span class="status-badge <?php echo $t['type'] == 'Received' ? 'status-ok' : 'status-critical'; ?>"><?php echo $t['type']; ?></span></td>
                    <td style="font-weight:500"><?php echo htmlspecialchars($t['item_name']); ?></td>
                    <td><strong><?php echo $t['quantity']; ?></strong></td>
                    <td><?php echo htmlspecialchars($t['counterparty'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($t['user_name']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <p>No transactions found for this category in the selected date range.</p>
    </div>
    <?php endif; ?>
</div>

<!-- Quick Stats -->
<div class="card">
    <h3 class="section-title">Quick Summary</h3>
    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px;">
        <div>
            <strong>Receiving:</strong><br>
            <?php echo $receiving_data['transaction_count'] ?? 0; ?> transactions<br>
            <?php echo $receiving_data['supplier_count'] ?? 0; ?> different suppliers
        </div>
        <div>
            <strong>Issuing:</strong><br>
            <?php echo $issuing_data['transaction_count'] ?? 0; ?> transactions<br>
            <?php echo $issuing_data['recipient_count'] ?? 0; ?> different recipients
        </div>
        <div>
            <strong>Inventory Health:</strong><br>
            <?php 
            $healthy_count = count($items_in_category) - $low_stock_count;
            ?>
            <span style="color: var(--success);"><?php echo $healthy_count; ?></span> items healthy<br>
            <span style="color: var(--danger);"><?php echo $low_stock_count; ?></span> items low stock
        </div>
    </div>
</div>

<!-- Submit Report Form -->
<form method="POST" class="notes-section">
    <input type="hidden" name="category_id" value="<?php echo $selected_category_id; ?>">
    <input type="hidden" name="report_month" value="<?php echo $selected_month; ?>">
    <label><strong>Notes for Manager (Optional):</strong></label>
    <textarea name="notes" placeholder="Add any notes about this month's stock, issues, or observations..."><?php echo htmlspecialchars($existing_report['notes'] ?? ''); ?></textarea>
    
    <div class="report-actions">
        <?php if ($existing_report): ?>
        <button type="submit" name="submit_report" class="btn-submit btn-update">Update Report</button>
        <?php else: ?>
        <button type="submit" name="submit_report" class="btn-submit">Submit Report to Manager</button>
        <?php endif; ?>
        <a href="dashboard.php" class="btn">Cancel</a>
    </div>
</form>

<div style="margin-top: 16px; font-size: 0.8rem; color: var(--muted); text-align: center;">
    <p>Once submitted, this report will be available for the manager to review.</p>
</div>

<?php else: ?>
<div class="empty-state">
    <div class="empty-state-icon">📊</div>
    <h3>Select a category to view and submit report</h3>
    <p>Choose a category above to review stock levels and submit the monthly report.</p>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>