<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

// Get all categories
$categories = $pdo->query("SELECT id, name, description FROM categories ORDER BY name")->fetchAll();

$selected_category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

$category_data = null;
$items_in_category = [];
$receiving_data = [];
$issuing_data = [];

if ($selected_category_id > 0) {
    // Get category info
    $cat_stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $cat_stmt->execute([$selected_category_id]);
    $category_data = $cat_stmt->fetch();
    
    // Get items in this category
    $items_stmt = $pdo->prepare("
        SELECT * FROM items 
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
            SUM(r.quantity * r.unit_price) as total_value,
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
        (SELECT 'Received' as type, r.created_at, i.item_name, r.quantity, r.supplier as counterparty, NULL as issued_to, u.full_name as user_name
         FROM stock_receiving r
         JOIN items i ON r.item_id = i.id
         JOIN users u ON r.received_by = u.id
         WHERE i.category_id = ? AND DATE(r.created_at) BETWEEN ? AND ?)
        UNION ALL
        (SELECT 'Issued' as type, si.created_at, i.item_name, si.quantity, NULL as counterparty, si.issued_to, u.full_name as user_name
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

include __DIR__ . '/../includes/header.php';
?>

<h1>Category Reports</h1>

<!-- Category Selection -->
<div style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #ddd;">
    <h3 style="margin-top: 0; margin-bottom: 15px;">📂 Select Category for Report</h3>
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <?php foreach ($categories as $cat): ?>
        <a href="?category_id=<?php echo $cat['id']; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>" 
           style="padding: 8px 16px; background: <?php echo $selected_category_id == $cat['id'] ? '#2563eb' : '#f0f0f0'; ?>; color: <?php echo $selected_category_id == $cat['id'] ? 'white' : '#333'; ?>; text-decoration: none; border-radius: 20px; font-weight: 500;">
            <?php echo htmlspecialchars($cat['name']); ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Date Filter -->
<div class="card">
    <form method="GET" class="filter-form">
        <input type="hidden" name="category_id" value="<?php echo $selected_category_id; ?>">
        <div class="form-group">
            <label>Start Date</label>
            <input type="date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>" required>
        </div>
        <div class="form-group">
            <label>End Date</label>
            <input type="date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>" required>
        </div>
        <div class="form-group" style="display:flex;align-items:flex-end">
            <button type="submit" class="btn btn-primary">Generate Report</button>
        </div>
    </form>
</div>

<?php if ($selected_category_id > 0 && $category_data): ?>

<!-- Category Summary -->
<div class="dashboard">
    <div class="card">
        <h3><?php echo htmlspecialchars($category_data['name']); ?></h3>
        <div class="stat"><?php echo count($items_in_category); ?></div>
        <div class="stat-label">Total Items in Category</div>
        <?php if ($category_data['description']): ?>
        <div style="font-size:0.85rem; color:var(--muted); margin-top:8px;"><?php echo htmlspecialchars($category_data['description']); ?></div>
        <?php endif; ?>
    </div>
    <div class="card">
        <h3>Received</h3>
        <div class="stat"><?php echo $receiving_data['total_quantity'] ?? 0; ?></div>
        <div class="stat-label">Units received (<?php echo date('d/m', strtotime($start_date));?> - <?php echo date('d/m', strtotime($end_date));?>)</div>
    </div>
    <div class="card">
        <h3>Issued</h3>
        <div class="stat"><?php echo $issuing_data['total_quantity'] ?? 0; ?></div>
        <div class="stat-label">Units issued (<?php echo date('d/m', strtotime($start_date));?> - <?php echo date('d/m', strtotime($end_date));?>)</div>
    </div>
    <div class="card">
        <h3>Net Movement</h3>
        <div class="stat" style="color:<?php echo (($receiving_data['total_quantity'] ?? 0) - ($issuing_data['total_quantity'] ?? 0)) >= 0 ? 'var(--success)' : 'var(--danger)'; ?>">
            <?php echo ($receiving_data['total_quantity'] ?? 0) - ($issuing_data['total_quantity'] ?? 0); ?>
        </div>
        <div class="stat-label">Change in stock level</div>
    </div>
</div>

<!-- Items in Category Table -->
<div class="card">
    <h3>Items in <?php echo htmlspecialchars($category_data['name']); ?> Category</h3>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Item Name</th>
                    <th>Current Stock</th>
                    <th>Unit Price (K)</th>
                    <th>Total Value (K)</th>
                    <th>Reorder Level</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $category_total_value = 0;
                foreach ($items_in_category as $item): 
                    $total_value = $item['current_quantity'] * $item['unit_price'];
                    $category_total_value += $total_value;
                ?>
                <tr>
                    <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']); ?></td>
                    <td><?php echo $item['current_quantity']; ?></td>
                    <td>K<?php echo number_format($item['unit_price'], 2); ?></td>
                    <td>K<?php echo number_format($total_value, 2); ?></td>
                    <td><?php echo $item['reorder_level']; ?></td>
                    <td>
                        <?php if ($item['current_quantity'] <= $item['reorder_level']): ?>
                            <span class="status-pending">Low Stock</span>
                        <?php else: ?>
                            <span class="status-receiving">OK</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr style="background: #f8f9fa; font-weight: bold;">
                    <td colspan="3" style="text-align: right;">Category Total Value:</td>
                    <td colspan="3">K<?php echo number_format($category_total_value, 2); ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- Detailed Activity -->
<div class="card">
    <h3>Activity Details (<?php echo date('d/m/Y', strtotime($start_date));?> - <?php echo date('d/m/Y', strtotime($end_date));?>)</h3>
    
    <?php if (!empty($transactions)): ?>
    <div class="table-container">
         <table>
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
                    <td>
                        <span class="status-<?php echo strtolower($t['type']); ?>">
                            <?php echo $t['type']; ?>
                        </span>
                    </td>
                    <td style="font-weight:500"><?php echo htmlspecialchars($t['item_name']); ?></td>
                    <td><strong><?php echo $t['quantity']; ?></strong></td>
                    <td><?php echo htmlspecialchars($t['counterparty'] ?? $t['issued_to'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars($t['user_name']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
         </table>
    </div>
    <?php else: ?>
    <div style="text-align:center; padding:30px; color:var(--muted);">
        <p>No transactions found for this category in the selected date range.</p>
    </div>
    <?php endif; ?>
</div>

<!-- Quick Stats Summary -->
<div class="card">
    <h3>Quick Summary</h3>
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
            $low_stock_count = 0;
            foreach ($items_in_category as $item) {
                if ($item['current_quantity'] <= $item['reorder_level']) $low_stock_count++;
            }
            ?>
            <?php echo $low_stock_count; ?> items low stock<br>
            <?php echo count($items_in_category) - $low_stock_count; ?> items healthy
        </div>
    </div>
</div>

<div style="margin-top:16px; text-align:right">
    <button onclick="window.print()" class="btn btn-success">🖨️ Print Report</button>
    <a href="reports.php" class="btn btn-info">📊 Full Reports</a>
</div>

<?php else: ?>
<div class="card" style="text-align:center; padding:40px;">
    <div style="font-size:3rem; margin-bottom:15px;">📊</div>
    <h3>Select a category to view detailed report</h3>
    <p style="color:var(--muted);">Choose a category from the tabs above to see inventory details, transactions, and summary statistics.</p>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>