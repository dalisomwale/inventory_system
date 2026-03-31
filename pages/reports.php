<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();
if (!isManager()) { header('Location: dashboard.php'); exit(); }

$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
$selected_category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

// Get all categories
$categories = $pdo->query("SELECT id, name, description FROM categories ORDER BY name")->fetchAll();

// Get all submitted reports for the selected month
$reports = $pdo->prepare("
    SELECT r.*, c.name as category_name, u.full_name as submitted_by_name
    FROM category_monthly_reports r
    JOIN categories c ON r.category_id = c.id
    JOIN users u ON r.submitted_by = u.id
    WHERE DATE_FORMAT(r.report_month, '%Y-%m') = ?
    ORDER BY c.name
");
$reports->execute([$selected_month]);
$all_reports = $reports->fetchAll();

// Get specific report if category selected
$selected_report = null;
$items_data = array();
if ($selected_category_id > 0) {
    $report_stmt = $pdo->prepare("
        SELECT r.*, c.name as category_name, c.description as category_description, u.full_name as submitted_by_name
        FROM category_monthly_reports r
        JOIN categories c ON r.category_id = c.id
        JOIN users u ON r.submitted_by = u.id
        WHERE r.category_id = ? AND DATE_FORMAT(r.report_month, '%Y-%m') = ?
    ");
    $report_stmt->execute([$selected_category_id, $selected_month]);
    $selected_report = $report_stmt->fetch();
    
    if ($selected_report) {
        $items_data = json_decode($selected_report['items_data'], true);
        
        // Get actual item data with prices for manager view
        foreach ($items_data as $key => $item) {
            $item_stmt = $pdo->prepare("SELECT unit_price, current_quantity FROM items WHERE item_name = ? AND category_id = ?");
            $item_stmt->execute([$item['item_name'], $selected_category_id]);
            $db_item = $item_stmt->fetch();
            if ($db_item) {
                $items_data[$key]['unit_price'] = $db_item['unit_price'];
                $items_data[$key]['total_value'] = $item['closing_stock'] * $db_item['unit_price'];
                $items_data[$key]['current_quantity'] = $db_item['current_quantity'];
            } else {
                $items_data[$key]['unit_price'] = 0;
                $items_data[$key]['total_value'] = 0;
                $items_data[$key]['current_quantity'] = 0;
            }
        }
        
        // Calculate totals with values
        $total_value = 0;
        $total_units = 0;
        foreach ($items_data as $item) {
            $total_value += $item['total_value'];
            $total_units += $item['closing_stock'];
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<style>
.reports-container {
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

.category-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.report-card {
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

.report-card:hover {
    border-color: var(--accent);
    transform: translateY(-2px);
    box-shadow: var(--shadow);
}

.report-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.report-category-name {
    font-weight: 600;
    font-size: 1.1rem;
    color: var(--text);
}

.report-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.7rem;
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

.report-stats {
    display: flex;
    gap: 15px;
    margin-top: 12px;
    font-size: 0.8rem;
    color: var(--muted);
}

.report-stat {
    display: flex;
    flex-direction: column;
}

.report-stat-value {
    font-weight: 700;
    font-size: 1rem;
    color: var(--accent);
}

.report-date {
    font-size: 0.7rem;
    color: var(--muted);
    margin-top: 10px;
    padding-top: 10px;
    border-top: 1px solid var(--border);
}

.detail-view {
    background: var(--surface);
    border-radius: var(--radius);
    border: 1px solid var(--border);
    padding: 25px;
    margin-top: 20px;
}

.detail-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid var(--accent);
    flex-wrap: wrap;
    gap: 15px;
}

.back-button {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-bottom: 20px;
    color: var(--accent);
    text-decoration: none;
}

.back-button:hover {
    text-decoration: underline;
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
    border-left: 4px solid var(--accent);
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

.btn-print {
    background: var(--success);
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 500;
    margin-top: 20px;
}

.btn-print:hover {
    background: #15803d;
}

.section-title {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--accent);
    display: inline-block;
}

.card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 20px;
    border: 1px solid var(--border);
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
</style>

<div class="reports-container">
    <h1>Category Monthly Reports</h1>
    
    <!-- Month Selector -->
    <div class="month-selector">
        <label><strong>Select Month:</strong></label>
        <input type="month" id="monthSelect" value="<?php echo $selected_month; ?>" onchange="window.location.href='?month='+this.value<?php if($selected_category_id > 0) echo '&category_id='.$selected_category_id; ?>">
    </div>
    
    <?php if ($selected_category_id > 0 && $selected_report): ?>
    <!-- Detailed View for Selected Category -->
    <div>
        <a href="reports.php?month=<?php echo $selected_month; ?>" class="back-button">← Back to All Categories</a>
        
        <div class="detail-view">
            <div class="detail-header">
                <h2 style="margin: 0;"><?php echo htmlspecialchars($selected_report['category_name']); ?></h2>
                <span class="report-badge badge-submitted">Submitted on: <?php echo date('d/m/Y H:i', strtotime($selected_report['submitted_at'])); ?></span>
            </div>
            
            <p><strong>Submitted by:</strong> <?php echo htmlspecialchars($selected_report['submitted_by_name']); ?></p>
            <p><strong>Report Month:</strong> <?php echo date('F Y', strtotime($selected_report['report_month'])); ?></p>
            <?php if ($selected_report['category_description']): ?>
            <p><strong>Category Description:</strong> <?php echo htmlspecialchars($selected_report['category_description']); ?></p>
            <?php endif; ?>
            
            <!-- Statistics with Values -->
            <div class="stats-row">
                <div class="stat-box">
                    <div class="stat-number"><?php echo $selected_report['total_items']; ?></div>
                    <div class="stat-label">Total Items</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number"><?php echo number_format($selected_report['total_units']); ?></div>
                    <div class="stat-label">Total Stock Units</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number">K<?php echo number_format($total_value, 2); ?></div>
                    <div class="stat-label">Total Stock Value</div>
                </div>
                <div class="stat-box">
                    <div class="stat-number" style="color: <?php echo $selected_report['low_stock_count'] > 0 ? '#dc2626' : '#16a34a'; ?>">
                        <?php echo $selected_report['low_stock_count']; ?>
                    </div>
                    <div class="stat-label">Low Stock Items</div>
                </div>
            </div>
            
            <!-- Items Table with Values -->
            <div class="items-table">
                <h4>Stock Details with Values</h4>
                <table>
                    <thead>
                        <tr>
                            <th>Item Name</th>
                            <th>Opening Stock</th>
                            <th>Closing Stock</th>
                            <th>Unit Price</th>
                            <th>Total Value</th>
                            <th>Reorder Level</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items_data as $item): ?>
                        <tr>
                            <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']); ?></td>
                            <td><?php echo $item['opening_stock']; ?></td>
                            <td><strong><?php echo $item['closing_stock']; ?></strong></td>
                            <td>K<?php echo number_format(isset($item['unit_price']) ? $item['unit_price'] : 0, 2); ?></td>
                            <td>K<?php echo number_format(isset($item['total_value']) ? $item['total_value'] : 0, 2); ?></td>
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
                    <tfoot style="background: #f9fafb; font-weight: bold;">
                        <tr>
                            <td colspan="4" style="text-align: right;">Total Value:</td>
                            <td colspan="3">K<?php echo number_format($total_value, 2); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            
            <!-- Notes -->
            <?php if ($selected_report['notes']): ?>
            <div class="notes-section">
                <strong>📝 Notes from Reception:</strong><br>
                <?php echo nl2br(htmlspecialchars($selected_report['notes'])); ?>
            </div>
            <?php endif; ?>
            
            <!-- Print Button -->
            <div style="margin-top: 25px; display: flex; gap: 15px; justify-content: flex-end;">
                <button onclick="window.print()" class="btn-print">Print Report</button>
            </div>
        </div>
    </div>
    
    <?php else: ?>
    <!-- Grid View of All Categories -->
    <div class="card">
        <h3 class="section-title">Categories Overview</h3>
        <p style="color: var(--muted); margin-bottom: 20px;">Click on any category to view the detailed monthly report submitted by reception.</p>
        
        <div class="category-grid">
            <?php 
            $submitted_categories = array();
            foreach ($all_reports as $r) {
                $submitted_categories[] = $r['category_id'];
            }
            foreach ($categories as $cat):
                $report = null;
                foreach ($all_reports as $r) {
                    if ($r['category_id'] == $cat['id']) {
                        $report = $r;
                        break;
                    }
                }
            ?>
            <a href="?category_id=<?php echo $cat['id']; ?>&month=<?php echo $selected_month; ?>" class="report-card">
                <div class="report-card-header">
                    <span class="report-category-name"><?php echo htmlspecialchars($cat['name']); ?></span>
                    <?php if ($report): ?>
                    <span class="report-badge badge-submitted">✓ Submitted</span>
                    <?php else: ?>
                    <span class="report-badge badge-pending">Pending</span>
                    <?php endif; ?>
                </div>
                <?php if ($report): ?>
                <div class="report-stats">
                    <div class="report-stat">
                        <span class="report-stat-value"><?php echo $report['total_items']; ?></span>
                        <span>Items</span>
                    </div>
                    <div class="report-stat">
                        <span class="report-stat-value"><?php echo number_format($report['total_units']); ?></span>
                        <span>Units</span>
                    </div>
                </div>
                <div class="report-date">
                    Submitted: <?php echo date('d/m/Y', strtotime($report['submitted_at'])); ?>
                    <?php if ($report['low_stock_count'] > 0): ?>
                    <span style="color: var(--danger);"> • ⚠️ <?php echo $report['low_stock_count']; ?> low stock</span>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="report-stats">
                    <div class="report-stat">
                        <span class="report-stat-value">—</span>
                        <span>No report</span>
                    </div>
                </div>
                <div class="report-date">
                    Not yet submitted for <?php echo date('F Y', strtotime($selected_month . '-01')); ?>
                </div>
                <?php endif; ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Summary Table of All Reports -->
    <?php if (count($all_reports) > 0): ?>
    <div class="card">
        <h3 class="section-title">Monthly Summary - <?php echo date('F Y', strtotime($selected_month . '-01')); ?></h3>
        <div class="table-container">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr>
                        <th style="text-align: left; padding: 12px; background: #f9fafb; border-bottom: 2px solid var(--border);">Category</th>
                        <th style="text-align: left; padding: 12px; background: #f9fafb; border-bottom: 2px solid var(--border);">Total Items</th>
                        <th style="text-align: left; padding: 12px; background: #f9fafb; border-bottom: 2px solid var(--border);">Total Units</th>
                        <th style="text-align: left; padding: 12px; background: #f9fafb; border-bottom: 2px solid var(--border);">Low Stock</th>
                        <th style="text-align: left; padding: 12px; background: #f9fafb; border-bottom: 2px solid var(--border);">Submitted By</th>
                        <th style="text-align: left; padding: 12px; background: #f9fafb; border-bottom: 2px solid var(--border);">Submitted On</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_reports as $report): ?>
                    <tr onclick="window.location.href='?category_id=<?php echo $report['category_id']; ?>&month=<?php echo $selected_month; ?>'" style="cursor: pointer;">
                        <td style="padding: 12px; border-bottom: 1px solid var(--border); font-weight:500"><?php echo htmlspecialchars($report['category_name']); ?></td>
                        <td style="padding: 12px; border-bottom: 1px solid var(--border);"><?php echo $report['total_items']; ?></td>
                        <td style="padding: 12px; border-bottom: 1px solid var(--border);"><?php echo number_format($report['total_units']); ?></td>
                        <td style="padding: 12px; border-bottom: 1px solid var(--border);">
                            <?php if ($report['low_stock_count'] > 0): ?>
                            <span style="color: var(--danger);"><?php echo $report['low_stock_count']; ?> items</span>
                            <?php else: ?>
                            <span style="color: var(--success);">None</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px; border-bottom: 1px solid var(--border);"><?php echo htmlspecialchars($report['submitted_by_name']); ?></td>
                        <td style="padding: 12px; border-bottom: 1px solid var(--border);"><?php echo date('d/m/Y H:i', strtotime($report['submitted_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot style="background: #f9fafb; font-weight: bold;">
                    <tr>
                        <td style="padding: 12px;">Total</td>
                        <td style="padding: 12px;"><?php 
                            $total_items_sum = 0;
                            foreach ($all_reports as $report) {
                                $total_items_sum += $report['total_items'];
                            }
                            echo $total_items_sum;
                        ?></td>
                        <td style="padding: 12px;"><?php 
                            $total_units_sum = 0;
                            foreach ($all_reports as $report) {
                                $total_units_sum += $report['total_units'];
                            }
                            echo number_format($total_units_sum);
                        ?></td>
                        <td colspan="3" style="padding: 12px;"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (count($all_reports) == 0): ?>
    <div class="empty-state">
        <div class="empty-state-icon">📊</div>
        <h3>No Reports Submitted Yet</h3>
        <p>No category reports have been submitted for <?php echo date('F Y', strtotime($selected_month . '-01')); ?>.</p>
        <p style="font-size: 0.85rem;">Reception staff need to submit monthly reports for each category.</p>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<script>
// Auto-hide alerts
setTimeout(function() {
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        alert.style.opacity = '0';
        setTimeout(function() { alert.remove(); }, 400);
    });
}, 5000);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>