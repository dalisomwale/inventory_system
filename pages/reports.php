<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();
if (!isManager()) { header('Location: dashboard.php'); exit(); }

// Get selected category from URL
$selected_category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$selected_category = null;

if ($selected_category_id > 0) {
    $cat_stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $cat_stmt->execute([$selected_category_id]);
    $selected_category = $cat_stmt->fetch();
}

$start = $_GET['start_date'] ?? date('Y-m-01');
$end   = $_GET['end_date']   ?? date('Y-m-d');

// Get all categories
$categories = $pdo->query("SELECT id, name, description FROM categories ORDER BY name")->fetchAll();

// ============================================
// REALISTIC DATA - BASED ON RECEPTION ACTIVITIES
// ============================================

// 1. OVERALL INVENTORY STATUS (Current state based on all reception transactions)
$inventory_status = $pdo->query("
    SELECT 
        COUNT(*) as total_items,
        SUM(current_quantity) as total_stock_units,
        SUM(current_quantity * unit_price) as total_inventory_value,
        COUNT(CASE WHEN current_quantity <= reorder_level THEN 1 END) as low_stock_items,
        COUNT(CASE WHEN current_quantity = 0 THEN 1 END) as out_of_stock_items
    FROM items
")->fetch();

// 2. RECEPTION ACTIVITY SUMMARY (Based on actual reception staff transactions)
$reception_activity = $pdo->prepare("
    SELECT 
        (SELECT COUNT(DISTINCT received_by) FROM stock_receiving WHERE DATE(created_at) BETWEEN ? AND ?) as active_receiving_staff,
        (SELECT COUNT(DISTINCT issued_by) FROM stock_issuing WHERE DATE(created_at) BETWEEN ? AND ?) as active_issuing_staff,
        (SELECT COUNT(*) FROM stock_receiving WHERE DATE(created_at) BETWEEN ? AND ?) as total_receive_transactions,
        (SELECT COUNT(*) FROM stock_issuing WHERE DATE(created_at) BETWEEN ? AND ?) as total_issue_transactions,
        (SELECT SUM(quantity) FROM stock_receiving WHERE DATE(created_at) BETWEEN ? AND ?) as total_units_received,
        (SELECT SUM(quantity) FROM stock_issuing WHERE DATE(created_at) BETWEEN ? AND ?) as total_units_issued
");
$reception_activity->execute([$start, $end, $start, $end, $start, $end, $start, $end, $start, $end, $start, $end]);
$activity = $reception_activity->fetch();

// 3. CATEGORY PERFORMANCE (Based on reception transactions)
$category_performance = $pdo->prepare("
    SELECT 
        i.category,
        COUNT(DISTINCT i.id) as total_items,
        SUM(i.current_quantity) as current_stock,
        SUM(i.current_quantity * i.unit_price) as stock_value,
        COUNT(CASE WHEN i.current_quantity <= i.reorder_level THEN 1 END) as low_stock_count,
        COALESCE(SUM(sr.quantity), 0) as units_received_period,
        COALESCE(SUM(si.quantity), 0) as units_issued_period,
        COUNT(DISTINCT sr.id) as receive_transactions,
        COUNT(DISTINCT si.id) as issue_transactions
    FROM items i
    LEFT JOIN stock_receiving sr ON i.id = sr.item_id AND DATE(sr.created_at) BETWEEN ? AND ?
    LEFT JOIN stock_issuing si ON i.id = si.item_id AND DATE(si.created_at) BETWEEN ? AND ?
    GROUP BY i.category
    ORDER BY stock_value DESC
");
$category_performance->execute([$start, $end, $start, $end]);
$categories_data = $category_performance->fetchAll();

// 4. TOP RECEIVED ITEMS (Based on reception receiving activities)
$top_received = $pdo->prepare("
    SELECT 
        i.item_name,
        i.category,
        SUM(sr.quantity) as total_received,
        COUNT(sr.id) as receive_count,
        GROUP_CONCAT(DISTINCT sr.supplier ORDER BY sr.supplier SEPARATOR ', ') as suppliers
    FROM stock_receiving sr
    JOIN items i ON sr.item_id = i.id
    WHERE DATE(sr.created_at) BETWEEN ? AND ?
    GROUP BY i.id, i.item_name, i.category
    ORDER BY total_received DESC
    LIMIT 10
");
$top_received->execute([$start, $end]);
$top_received_items = $top_received->fetchAll();

// 5. TOP ISSUED ITEMS (Based on reception issuing activities)
$top_issued = $pdo->prepare("
    SELECT 
        i.item_name,
        i.category,
        SUM(si.quantity) as total_issued,
        COUNT(si.id) as issue_count,
        COUNT(DISTINCT si.issued_to) as unique_recipients
    FROM stock_issuing si
    JOIN items i ON si.item_id = i.id
    WHERE DATE(si.created_at) BETWEEN ? AND ?
    GROUP BY i.id, i.item_name, i.category
    ORDER BY total_issued DESC
    LIMIT 10
");
$top_issued->execute([$start, $end]);
$top_issued_items = $top_issued->fetchAll();

// 6. RECEPTION STAFF PERFORMANCE - FIXED
$staff_performance = $pdo->prepare("
    SELECT 
        u.full_name,
        u.role,
        COUNT(DISTINCT sr.id) as receive_transactions,
        COALESCE(SUM(sr.quantity), 0) as units_received,
        COUNT(DISTINCT si.id) as issue_transactions,
        COALESCE(SUM(si.quantity), 0) as units_issued
    FROM users u
    LEFT JOIN stock_receiving sr ON u.id = sr.received_by AND DATE(sr.created_at) BETWEEN ? AND ?
    LEFT JOIN stock_issuing si ON u.id = si.issued_by AND DATE(si.created_at) BETWEEN ? AND ?
    WHERE u.role = 'front_desk'
    GROUP BY u.id, u.full_name, u.role
");
$staff_performance->execute([$start, $end, $start, $end]);
$staff_data = $staff_performance->fetchAll();

// Sort in PHP instead of SQL to avoid alias issues
usort($staff_data, function($a, $b) {
    $total_a = $a['receive_transactions'] + $a['issue_transactions'];
    $total_b = $b['receive_transactions'] + $b['issue_transactions'];
    return $total_b - $total_a;
});

// 7. DAILY ACTIVITY TREND (Based on reception daily transactions) - FIXED
$daily_trend = $pdo->prepare("
    SELECT 
        DATE(created_at) as date,
        SUM(CASE WHEN type = 'receive' THEN 1 ELSE 0 END) as receive_count,
        SUM(CASE WHEN type = 'receive' THEN quantity ELSE 0 END) as units_received,
        SUM(CASE WHEN type = 'issue' THEN 1 ELSE 0 END) as issue_count,
        SUM(CASE WHEN type = 'issue' THEN quantity ELSE 0 END) as units_issued
    FROM (
        SELECT created_at, 'receive' as type, quantity FROM stock_receiving WHERE DATE(created_at) BETWEEN ? AND ?
        UNION ALL
        SELECT created_at, 'issue' as type, quantity FROM stock_issuing WHERE DATE(created_at) BETWEEN ? AND ?
    ) combined
    GROUP BY DATE(created_at)
    ORDER BY date DESC
    LIMIT 30
");
$daily_trend->execute([$start, $end, $start, $end]);
$daily_activity = $daily_trend->fetchAll();

// 8. SUPPLIER ACTIVITY (Based on reception receiving from suppliers)
$supplier_activity = $pdo->prepare("
    SELECT 
        supplier,
        COUNT(*) as receive_count,
        SUM(quantity) as total_units,
        COUNT(DISTINCT item_id) as unique_items
    FROM stock_receiving
    WHERE DATE(created_at) BETWEEN ? AND ? AND supplier IS NOT NULL AND supplier != ''
    GROUP BY supplier
    ORDER BY total_units DESC
    LIMIT 10
");
$supplier_activity->execute([$start, $end]);
$top_suppliers = $supplier_activity->fetchAll();

// 9. RECENT TRANSACTIONS (Actual reception transactions)
$recent_transactions = $pdo->prepare("
    (SELECT 
        'Receive' as type,
        r.created_at,
        i.item_name,
        i.category,
        r.quantity,
        r.supplier as counterparty,
        u.full_name as staff_name
    FROM stock_receiving r
    JOIN items i ON r.item_id = i.id
    JOIN users u ON r.received_by = u.id
    WHERE DATE(r.created_at) BETWEEN ? AND ?
    ORDER BY r.created_at DESC LIMIT 20)
    UNION ALL
    (SELECT 
        'Issue' as type,
        s.created_at,
        i.item_name,
        i.category,
        s.quantity,
        s.issued_to as counterparty,
        u.full_name as staff_name
    FROM stock_issuing s
    JOIN items i ON s.item_id = i.id
    JOIN users u ON s.issued_by = u.id
    WHERE DATE(s.created_at) BETWEEN ? AND ?
    ORDER BY s.created_at DESC LIMIT 20)
    ORDER BY created_at DESC
    LIMIT 50
");
$recent_transactions->execute([$start, $end, $start, $end]);
$transactions = $recent_transactions->fetchAll();

// 10. LOW STOCK ITEMS
$low_stock_items = $pdo->query("
    SELECT i.*, c.name as category_name 
    FROM items i 
    LEFT JOIN categories c ON i.category_id = c.id
    WHERE i.current_quantity <= i.reorder_level 
    ORDER BY 
        CASE 
            WHEN i.current_quantity = 0 THEN 1 
            WHEN i.current_quantity <= i.reorder_level/2 THEN 2 
            ELSE 3 
        END,
        i.current_quantity ASC
")->fetchAll();

// 11. If specific category selected, get category-specific reception activity
if ($selected_category_id > 0 && $selected_category) {
    // Items in this category
    $category_items = $pdo->prepare("
        SELECT * FROM items WHERE category_id = ? ORDER BY item_name
    ");
    $category_items->execute([$selected_category_id]);
    $items_in_category = $category_items->fetchAll();
    
    // Category reception activity
    $category_activity = $pdo->prepare("
        SELECT 
            COALESCE(SUM(sr.quantity), 0) as received_units,
            COALESCE(SUM(si.quantity), 0) as issued_units,
            COUNT(DISTINCT sr.id) as receive_transactions,
            COUNT(DISTINCT si.id) as issue_transactions,
            COUNT(DISTINCT sr.supplier) as unique_suppliers,
            COUNT(DISTINCT si.issued_to) as unique_recipients
        FROM items i
        LEFT JOIN stock_receiving sr ON i.id = sr.item_id AND DATE(sr.created_at) BETWEEN ? AND ?
        LEFT JOIN stock_issuing si ON i.id = si.item_id AND DATE(si.created_at) BETWEEN ? AND ?
        WHERE i.category_id = ?
    ");
    $category_activity->execute([$start, $end, $start, $end, $selected_category_id]);
    $category_stats = $category_activity->fetch();
    
    // Top items in this category
    $category_top_items = $pdo->prepare("
        SELECT 
            i.item_name,
            i.current_quantity,
            i.reorder_level,
            i.unit_price,
            COALESCE(SUM(sr.quantity), 0) as received_period,
            COALESCE(SUM(si.quantity), 0) as issued_period
        FROM items i
        LEFT JOIN stock_receiving sr ON i.id = sr.item_id AND DATE(sr.created_at) BETWEEN ? AND ?
        LEFT JOIN stock_issuing si ON i.id = si.item_id AND DATE(si.created_at) BETWEEN ? AND ?
        WHERE i.category_id = ?
        GROUP BY i.id, i.item_name, i.current_quantity, i.reorder_level, i.unit_price
        ORDER BY (received_period + issued_period) DESC
        LIMIT 10
    ");
    $category_top_items->execute([$start, $end, $start, $end, $selected_category_id]);
    $top_items_category = $category_top_items->fetchAll();
    
    // Recent transactions in this category
    $category_transactions = $pdo->prepare("
        (SELECT 
            'Receive' as type,
            r.created_at,
            i.item_name,
            r.quantity,
            r.supplier as counterparty,
            u.full_name as staff_name
        FROM stock_receiving r
        JOIN items i ON r.item_id = i.id
        JOIN users u ON r.received_by = u.id
        WHERE i.category_id = ? AND DATE(r.created_at) BETWEEN ? AND ?
        ORDER BY r.created_at DESC LIMIT 10)
        UNION ALL
        (SELECT 
            'Issue' as type,
            s.created_at,
            i.item_name,
            s.quantity,
            s.issued_to as counterparty,
            u.full_name as staff_name
        FROM stock_issuing s
        JOIN items i ON s.item_id = i.id
        JOIN users u ON s.issued_by = u.id
        WHERE i.category_id = ? AND DATE(s.created_at) BETWEEN ? AND ?
        ORDER BY s.created_at DESC LIMIT 10)
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $category_transactions->execute([$selected_category_id, $start, $end, $selected_category_id, $start, $end]);
    $transactions_category = $category_transactions->fetchAll();
}

include __DIR__ . '/../includes/header.php';
?>

<style>
/* Reports Page Styles */
.reports-header {
    margin-bottom: 30px;
}

.filter-card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 30px;
    border: 1px solid var(--border);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
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
    font-size: 0.75rem;
    color: var(--muted);
    margin-top: 5px;
}

.stat-card.low-stock .stat-value {
    color: var(--danger);
}

.stat-card.high-value .stat-value {
    color: var(--success);
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

.category-pill {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.75rem;
    font-weight: 500;
    background: #e6f7ff;
    color: #0050b3;
    cursor: pointer;
    transition: all 0.2s;
}

.category-pill:hover {
    transform: scale(1.05);
    background: var(--accent);
    color: white;
}

.category-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.category-report-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 18px;
    cursor: pointer;
    transition: all 0.2s;
}

.category-report-card:hover {
    border-color: var(--accent);
    transform: translateY(-2px);
    box-shadow: var(--shadow);
}

.category-report-name {
    font-weight: 600;
    font-size: 1rem;
    color: var(--text);
    margin-bottom: 8px;
}

.category-report-stats {
    display: flex;
    gap: 15px;
    margin-top: 10px;
    font-size: 0.8rem;
    color: var(--muted);
}

.category-report-stat {
    display: flex;
    flex-direction: column;
}

.category-report-stat-value {
    font-weight: 700;
    font-size: 1.1rem;
    color: var(--accent);
}

.back-button {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-bottom: 20px;
    color: var(--accent);
    text-decoration: none;
    font-weight: 500;
}

.back-button:hover {
    text-decoration: underline;
}

.table-container {
    overflow-x: auto;
    margin-top: 15px;
}

table {
    width: 100%;
    border-collapse: collapse;
}

th {
    text-align: left;
    padding: 12px;
    background: #f9fafb;
    border-bottom: 2px solid var(--border);
    font-weight: 600;
    font-size: 0.85rem;
}

td {
    padding: 12px;
    border-bottom: 1px solid var(--border);
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

.info-note {
    background: #e6f7ff;
    border-left: 4px solid var(--accent);
    padding: 12px 15px;
    margin-bottom: 20px;
    border-radius: 5px;
    font-size: 0.85rem;
    color: var(--text2);
}
</style>

<h1>Manager Reports</h1>

<!-- Info Note -->
<div class="info-note">
    <strong>Report Source:</strong> All data in this report is based on actual transactions performed by reception staff. This includes stock receiving, stock issuing, and procurement requests.
</div>

<!-- Date Filter Card -->
<div class="filter-card">
    <form method="GET" class="filter-form">
        <input type="hidden" name="category_id" value="<?php echo $selected_category_id; ?>">
        <div style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 15px; align-items: end;">
            <div class="form-group" style="margin-bottom: 0;">
                <label>Start Date</label>
                <input type="date" name="start_date" value="<?php echo htmlspecialchars($start); ?>" required style="width: 100%; padding: 10px; border: 1.5px solid var(--border); border-radius: 6px;">
            </div>
            <div class="form-group" style="margin-bottom: 0;">
                <label>End Date</label>
                <input type="date" name="end_date" value="<?php echo htmlspecialchars($end); ?>" required style="width: 100%; padding: 10px; border: 1.5px solid var(--border); border-radius: 6px;">
            </div>
            <div>
                <button type="submit" class="btn btn-primary">Generate Report</button>
            </div>
        </div>
    </form>
</div>

<?php if ($selected_category_id > 0 && $selected_category): ?>
<!-- ============================================ -->
<!-- CATEGORY-SPECIFIC REPORT (Based on Reception Activity) -->
<!-- ============================================ -->
<div>
    <a href="reports.php?start_date=<?php echo $start; ?>&end_date=<?php echo $end; ?>" class="back-button">← Back to All Categories</a>
    
    <h2 style="margin-bottom: 10px;"><?php echo htmlspecialchars($selected_category['name']); ?> - Activity Report</h2>
    <?php if ($selected_category['description']): ?>
    <p style="color: var(--muted); margin-bottom: 20px;"><?php echo htmlspecialchars($selected_category['description']); ?></p>
    <?php endif; ?>
    
    <!-- Category Activity Summary -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo count($items_in_category); ?></div>
            <div class="stat-label">Total Items</div>
            <div class="stat-sub">In this category</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $category_stats['receive_transactions'] ?? 0; ?></div>
            <div class="stat-label">Receiving Transactions</div>
            <div class="stat-sub"><?php echo $category_stats['received_units'] ?? 0; ?> units received</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $category_stats['issue_transactions'] ?? 0; ?></div>
            <div class="stat-label">Issuing Transactions</div>
            <div class="stat-sub"><?php echo $category_stats['issued_units'] ?? 0; ?> units issued</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo ($category_stats['received_units'] ?? 0) - ($category_stats['issued_units'] ?? 0); ?></div>
            <div class="stat-label">Net Movement</div>
            <div class="stat-sub"><?php echo date('d/m/Y', strtotime($start));?> - <?php echo date('d/m/Y', strtotime($end));?></div>
        </div>
    </div>
    
    <!-- Category Items Table -->
    <div class="card">
        <h3 class="section-title">Items in <?php echo htmlspecialchars($selected_category['name']); ?></h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Current Stock</th>
                        <th>Unit Price</th>
                        <th>Total Value</th>
                        <th>Reorder Level</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items_in_category as $item): ?>
                    <tr>
                        <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']); ?></td>
                        <td><?php echo $item['current_quantity']; ?></td>
                        <td>K<?php echo number_format($item['unit_price'], 2); ?></td>
                        <td>K<?php echo number_format($item['current_quantity'] * $item['unit_price'], 2); ?></td>
                        <td><?php echo $item['reorder_level']; ?></td>
                        <td>
                            <?php if ($item['current_quantity'] == 0): ?>
                                <span class="status-badge status-critical">Out of Stock</span>
                            <?php elseif ($item['current_quantity'] <= $item['reorder_level']): ?>
                                <span class="status-badge status-low">Low Stock</span>
                            <?php else: ?>
                                <span class="status-badge status-ok">OK</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- Top Moving Items in Category -->
    <?php if (count($top_items_category) > 0): ?>
    <div class="card">
        <h3 class="section-title">Top Moving Items in <?php echo htmlspecialchars($selected_category['name']); ?> (Based on Reception Activity)</h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Current Stock</th>
                        <th>Unit Price</th>
                        <th>Received (Period)</th>
                        <th>Issued (Period)</th>
                        <th>Total Movement</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_items_category as $item): ?>
                    <tr>
                        <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']); ?></td>
                        <td><?php echo $item['current_quantity']; ?></td>
                        <td>K<?php echo number_format($item['unit_price'], 2); ?></td>
                        <td><?php echo $item['received_period']; ?> units</td>
                        <td><?php echo $item['issued_period']; ?> units</td>
                        <td style="color: <?php echo ($item['received_period'] - $item['issued_period']) >= 0 ? 'var(--success)' : 'var(--danger)'; ?>">
                            <?php echo ($item['received_period'] - $item['issued_period']) >= 0 ? '+' : ''; ?><?php echo $item['received_period'] - $item['issued_period']; ?> units
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Recent Transactions in Category -->
    <?php if (isset($transactions_category) && count($transactions_category) > 0): ?>
    <div class="card">
        <h3 class="section-title">Recent Transactions in <?php echo htmlspecialchars($selected_category['name']); ?> (Performed by Reception)</h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Type</th>
                        <th>Item</th>
                        <th>Quantity</th>
                        <th>Counterparty</th>
                        <th>Performed By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions_category as $t): ?>
                    <tr>
                        <td style="white-space:nowrap"><?php echo date('d/m/Y H:i', strtotime($t['created_at'])); ?></td>
                        <td>
                            <span class="status-badge <?php echo $t['type'] == 'Receive' ? 'status-ok' : 'status-critical'; ?>">
                                <?php echo $t['type']; ?>
                            </span>
                        </td>
                        <td style="font-weight:500"><?php echo htmlspecialchars($t['item_name']); ?></td>
                        <td><strong><?php echo $t['quantity']; ?></strong></td>
                        <td><?php echo htmlspecialchars($t['counterparty'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($t['staff_name']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- ============================================ -->
<!-- MAIN REPORTS VIEW - Based on Reception Activity -->
<!-- ============================================ -->
<div>
    <!-- 1. CURRENT INVENTORY STATUS -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($inventory_status['total_items'] ?? 0); ?></div>
            <div class="stat-label">Total Items</div>
            <div class="stat-sub">In inventory</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo number_format($inventory_status['total_stock_units'] ?? 0); ?></div>
            <div class="stat-label">Total Stock Units</div>
            <div class="stat-sub">Across all items</div>
        </div>
        <div class="stat-card high-value">
            <div class="stat-value">K<?php echo number_format($inventory_status['total_inventory_value'] ?? 0, 2); ?></div>
            <div class="stat-label">Total Inventory Value</div>
            <div class="stat-sub">Current value</div>
        </div>
        <div class="stat-card low-stock">
            <div class="stat-value"><?php echo $inventory_status['low_stock_items'] ?? 0; ?></div>
            <div class="stat-label">Low Stock Items</div>
            <div class="stat-sub"><?php echo $inventory_status['out_of_stock_items'] ?? 0; ?> out of stock</div>
        </div>
    </div>
    
    <!-- 2. RECEPTION ACTIVITY SUMMARY -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo $activity['total_receive_transactions'] ?? 0; ?></div>
            <div class="stat-label">Receiving Transactions</div>
            <div class="stat-sub"><?php echo $activity['total_units_received'] ?? 0; ?> units received</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $activity['total_issue_transactions'] ?? 0; ?></div>
            <div class="stat-label">Issuing Transactions</div>
            <div class="stat-sub"><?php echo $activity['total_units_issued'] ?? 0; ?> units issued</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo ($activity['total_units_received'] ?? 0) - ($activity['total_units_issued'] ?? 0); ?></div>
            <div class="stat-label">Net Stock Movement</div>
            <div class="stat-sub"><?php echo date('d/m/Y', strtotime($start));?> - <?php echo date('d/m/Y', strtotime($end));?></div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo ($activity['active_receiving_staff'] ?? 0) + ($activity['active_issuing_staff'] ?? 0); ?></div>
            <div class="stat-label">Active Reception Staff</div>
            <div class="stat-sub">Performed transactions</div>
        </div>
    </div>
    
    <!-- 3. CATEGORY PERFORMANCE CARDS -->
    <div class="card">
        <h3 class="section-title">Category Performance Overview</h3>
        <p style="color: var(--muted); margin-bottom: 20px;">Click on any category to view detailed transaction report</p>
        <div class="category-grid">
            <?php foreach ($categories_data as $cat): ?>
            <?php 
            $cat_id = 0;
            foreach ($categories as $c) {
                if ($c['name'] == $cat['category']) {
                    $cat_id = $c['id'];
                    break;
                }
            }
            ?>
            <div class="category-report-card" onclick="window.location.href='reports.php?category_id=<?php echo $cat_id; ?>&start_date=<?php echo $start; ?>&end_date=<?php echo $end; ?>'">
                <div class="category-report-name"><?php echo htmlspecialchars($cat['category']); ?></div>
                <div class="category-report-stats">
                    <div class="category-report-stat">
                        <span class="category-report-stat-value"><?php echo $cat['total_items']; ?></span>
                        <span>Items</span>
                    </div>
                    <div class="category-report-stat">
                        <span class="category-report-stat-value"><?php echo number_format($cat['current_stock']); ?></span>
                        <span>Units</span>
                    </div>
                    <div class="category-report-stat">
                        <span class="category-report-stat-value">K<?php echo number_format($cat['stock_value'], 0); ?></span>
                        <span>Value</span>
                    </div>
                </div>
                <div style="margin-top: 10px; font-size: 0.75rem;">
                    <span>Received: <?php echo $cat['units_received_period']; ?> units</span><br>
                    <span>Issued: <?php echo $cat['units_issued_period']; ?> units</span>
                </div>
                <div style="margin-top: 10px;">
                    <?php if ($cat['low_stock_count'] > 0): ?>
                        <span style="color: var(--danger);">⚠️ <?php echo $cat['low_stock_count']; ?> low stock items</span>
                    <?php else: ?>
                        <span style="color: var(--success);">✓ All items healthy</span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- 4. TOP RECEIVED ITEMS -->
    <?php if (count($top_received_items) > 0): ?>
    <div class="card">
        <h3 class="section-title">Most Received Items (Reception Activity)</h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Category</th>
                        <th>Total Received</th>
                        <th>Receive Count</th>
                        <th>Suppliers</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_received_items as $item): ?>
                    <tr>
                        <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']); ?></td>
                        <td><span class="cat-pill <?php echo catClass($item['category']); ?>"><?php echo htmlspecialchars($item['category']); ?></span></td>
                        <td><strong><?php echo $item['total_received']; ?></strong> units</td>
                        <td><?php echo $item['receive_count']; ?> transactions</td>
                        <td style="max-width: 200px; white-space: normal;"><?php echo htmlspecialchars($item['suppliers']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- 5. TOP ISSUED ITEMS -->
    <?php if (count($top_issued_items) > 0): ?>
    <div class="card">
        <h3 class="section-title">Most Issued Items (Reception Activity)</h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Category</th>
                        <th>Total Issued</th>
                        <th>Issue Count</th>
                        <th>Unique Recipients</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_issued_items as $item): ?>
                    <tr>
                        <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']); ?></td>
                        <td><span class="cat-pill <?php echo catClass($item['category']); ?>"><?php echo htmlspecialchars($item['category']); ?></span></td>
                        <td><strong><?php echo $item['total_issued']; ?></strong> units</td>
                        <td><?php echo $item['issue_count']; ?> transactions</td>
                        <td><?php echo $item['unique_recipients']; ?> recipients</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- 6. RECEPTION STAFF PERFORMANCE -->
    <?php if (count($staff_data) > 0): ?>
    <div class="card">
        <h3 class="section-title">Reception Staff Performance</h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Staff Name</th>
                        <th>Receiving Transactions</th>
                        <th>Units Received</th>
                        <th>Issuing Transactions</th>
                        <th>Units Issued</th>
                        <th>Total Activity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staff_data as $staff): ?>
                    <tr>
                        <td style="font-weight:500"><?php echo htmlspecialchars($staff['full_name']); ?></td>
                        <td><?php echo $staff['receive_transactions']; ?></td>
                        <td><?php echo $staff['units_received']; ?> units</td>
                        <td><?php echo $staff['issue_transactions']; ?></td>
                        <td><?php echo $staff['units_issued']; ?> units</td>
                        <td><strong><?php echo $staff['receive_transactions'] + $staff['issue_transactions']; ?></strong> transactions</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- 7. TOP SUPPLIERS -->
    <?php if (count($top_suppliers) > 0): ?>
    <div class="card">
        <h3 class="section-title">Top Suppliers (Reception Activity)</h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Supplier</th>
                        <th>Receiving Transactions</th>
                        <th>Total Units</th>
                        <th>Unique Items</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($top_suppliers as $supplier): ?>
                    <tr>
                        <td style="font-weight:500"><?php echo htmlspecialchars($supplier['supplier']); ?></td>
                        <td><?php echo $supplier['receive_count']; ?></td>
                        <td><strong><?php echo $supplier['total_units']; ?></strong> units</td>
                        <td><?php echo $supplier['unique_items']; ?> items</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- 8. DAILY ACTIVITY TREND -->
    <?php if (count($daily_activity) > 0): ?>
    <div class="card">
        <h3 class="section-title">Daily Activity Trend (Last 30 Days)</h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Receiving Transactions</th>
                        <th>Units Received</th>
                        <th>Issuing Transactions</th>
                        <th>Units Issued</th>
                        <th>Total Activity</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($daily_activity as $day): ?>
                    <tr>
                        <td><?php echo date('d/m/Y', strtotime($day['date'])); ?></td>
                        <td><?php echo $day['receive_count']; ?></td>
                        <td><?php echo $day['units_received']; ?> units</td>
                        <td><?php echo $day['issue_count']; ?></td>
                        <td><?php echo $day['units_issued']; ?> units</td>
                        <td><strong><?php echo $day['receive_count'] + $day['issue_count']; ?></strong> transactions</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- 9. RECENT TRANSACTIONS -->
    <?php if (count($transactions) > 0): ?>
    <div class="card">
        <h3 class="section-title">Recent Transactions (Performed by Reception Staff)</h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Type</th>
                        <th>Item</th>
                        <th>Category</th>
                        <th>Quantity</th>
                        <th>Counterparty</th>
                        <th>Performed By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $t): ?>
                    <tr>
                        <td style="white-space:nowrap"><?php echo date('d/m/Y H:i', strtotime($t['created_at'])); ?></td>
                        <td>
                            <span class="status-badge <?php echo $t['type'] == 'Receive' ? 'status-ok' : 'status-critical'; ?>">
                                <?php echo $t['type']; ?>
                            </span>
                        </td>
                        <td style="font-weight:500"><?php echo htmlspecialchars($t['item_name']); ?></td>
                        <td><span class="cat-pill <?php echo catClass($t['category']); ?>"><?php echo htmlspecialchars($t['category']); ?></span></td>
                        <td><strong><?php echo $t['quantity']; ?></strong></td>
                        <td><?php echo htmlspecialchars($t['counterparty'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($t['staff_name']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- 10. LOW STOCK ITEMS -->
    <div class="card">
        <h3 class="section-title">Items Requiring Attention (Low Stock)</h3>
        
        <?php if (count($low_stock_items) > 0): ?>
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
                    <?php foreach ($low_stock_items as $item): ?>
                    <tr>
                        <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']); ?></td>
                        <td><span class="cat-pill <?php echo catClass($item['category']??'Other'); ?>"><?php echo htmlspecialchars($item['category']??'Other'); ?></span></td>
                        <td><?php echo $item['current_quantity']; ?></td>
                        <td><?php echo $item['reorder_level']; ?></td>
                        <td>
                            <?php if ($item['current_quantity'] == 0): ?>
                                <span class="status-badge status-critical">Out of Stock</span>
                            <?php elseif ($item['current_quantity'] <= $item['reorder_level']/2): ?>
                                <span class="status-badge status-low">Very Low</span>
                            <?php else: ?>
                                <span class="status-badge status-low">Low Stock</span>
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
        <div style="text-align:center; padding:40px; color:var(--muted);">
            <div style="font-size: 2rem; margin-bottom: 10px;">✓</div>
            <p>All items are above their reorder levels. Good job, reception team!</p>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Print Button -->
<div style="margin-top: 20px; text-align: right;">
    <button onclick="window.print()" class="btn-print">Print Report</button>
</div>

<script>
// Auto-hide alerts after 5 seconds
setTimeout(function() {
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        alert.style.opacity = '0';
        setTimeout(function() { alert.remove(); }, 400);
    });
}, 5000);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>