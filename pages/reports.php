<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();
if (!isManager()) { header('Location: dashboard.php'); exit(); }

$start = $_GET['start_date'] ?? date('Y-m-01');
$end   = $_GET['end_date']   ?? date('Y-m-d');

$summary = $pdo->query("
    SELECT COUNT(*) as total_items, SUM(current_quantity) as total_quantity,
           SUM(current_quantity * unit_price) as total_value,
           COUNT(CASE WHEN current_quantity <= reorder_level THEN 1 END) as low_stock_count
    FROM items
")->fetch();

$recv = $pdo->prepare("SELECT COUNT(*) as receive_count, SUM(quantity) as total_received, SUM(quantity * unit_price) as receive_value FROM stock_receiving WHERE DATE(created_at) BETWEEN ? AND ?");
$recv->execute([$start, $end]); $receiving = $recv->fetch();

$iss = $pdo->prepare("SELECT COUNT(*) as issue_count, SUM(quantity) as total_issued FROM stock_issuing WHERE DATE(created_at) BETWEEN ? AND ?");
$iss->execute([$start, $end]); $issuing = $iss->fetch();

$top = $pdo->prepare("
    SELECT i.item_name, i.category, i.current_quantity,
           COALESCE(SUM(si.quantity),0) as total_issued,
           COALESCE(SUM(sr.quantity),0) as total_received
    FROM items i
    LEFT JOIN stock_issuing   si ON i.id=si.item_id AND DATE(si.created_at) BETWEEN ? AND ?
    LEFT JOIN stock_receiving sr ON i.id=sr.item_id AND DATE(sr.created_at) BETWEEN ? AND ?
    GROUP BY i.id ORDER BY total_issued DESC LIMIT 10
");
$top->execute([$start, $end, $start, $end]); $top_items = $top->fetchAll();

$cat_report = $pdo->prepare("
    SELECT i.category,
           COUNT(DISTINCT i.id) as item_count,
           SUM(i.current_quantity * i.unit_price) as stock_value,
           COALESCE(SUM(si.quantity),0) as issued_period,
           COALESCE(SUM(sr.quantity),0) as received_period
    FROM items i
    LEFT JOIN stock_issuing   si ON i.id=si.item_id AND DATE(si.created_at) BETWEEN ? AND ?
    LEFT JOIN stock_receiving sr ON i.id=sr.item_id AND DATE(sr.created_at) BETWEEN ? AND ?
    GROUP BY i.category ORDER BY stock_value DESC
");
$cat_report->execute([$start, $end, $start, $end]); $cat_data = $cat_report->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<h1>Inventory Reports</h1>

<div class="card">
    <form method="GET" class="filter-form">
        <div class="form-group">
            <label>Start Date</label>
            <input type="date" name="start_date" value="<?php echo htmlspecialchars($start);?>" required>
        </div>
        <div class="form-group">
            <label>End Date</label>
            <input type="date" name="end_date" value="<?php echo htmlspecialchars($end);?>" required>
        </div>
        <div class="form-group" style="display:flex;align-items:flex-end">
            <button type="submit" class="btn btn-primary">Generate Report</button>
        </div>
    </form>
</div>

<div class="dashboard">
    <div class="card">
        <h3>Total Items</h3>
        <div class="stat"><?php echo $summary['total_items']??0;?></div>
        <div class="stat-label">Products in inventory</div>
    </div>
    <div class="card">
        <h3>Inventory Value</h3>
        <div class="stat">K<?php echo number_format($summary['total_value']??0,2);?></div>
        <div class="stat-label">Total stock value</div>
    </div>
    <div class="card">
        <h3>Low Stock</h3>
        <div class="stat" style="color:<?php echo ($summary['low_stock_count']??0)>0?'var(--danger)':'var(--success)';?>"><?php echo $summary['low_stock_count']??0;?></div>
        <div class="stat-label">Need reordering</div>
    </div>
</div>

<h2>Activity: <?php echo date('d/m/Y', strtotime($start));?> &mdash; <?php echo date('d/m/Y', strtotime($end));?></h2>
<div class="dashboard">
    <div class="card">
        <h3>Stock Received</h3>
        <div class="stat"><?php echo $receiving['receive_count']??0;?></div>
        <div class="stat-label">Transactions &bull; <?php echo $receiving['total_received']??0;?> units &bull; K<?php echo number_format($receiving['receive_value']??0,2);?></div>
    </div>
    <div class="card">
        <h3>Stock Issued</h3>
        <div class="stat"><?php echo $issuing['issue_count']??0;?></div>
        <div class="stat-label">Transactions &bull; <?php echo $issuing['total_issued']??0;?> units</div>
    </div>
</div>

<!-- CATEGORY BREAKDOWN -->
<div class="card">
    <h3>Category Breakdown</h3>
    <div class="table-container">
        <table>
            <thead><tr><th>Category</th><th>Items</th><th>Stock Value</th><th>Received (period)</th><th>Issued (period)</th></tr></thead>
            <tbody>
                <?php foreach ($cat_data as $c): ?>
                <tr>
                    <td><span class="cat-pill <?php echo catClass($c['category']);?>"><?php echo htmlspecialchars($c['category']);?></span></td>
                    <td><?php echo $c['item_count'];?></td>
                    <td>K<?php echo number_format($c['stock_value'],2);?></td>
                    <td><?php echo $c['received_period'];?></td>
                    <td><?php echo $c['issued_period'];?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3>Top Moving Items</h3>
    <div class="table-container">
        <table>
            <thead><tr><th>Item</th><th>Category</th><th>Current Stock</th><th>Issued</th><th>Received</th><th>Net</th></tr></thead>
            <tbody>
                <?php foreach ($top_items as $item): ?>
                <tr>
                    <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']);?></td>
                    <td><span class="cat-pill <?php echo catClass($item['category']??'General');?>"><?php echo htmlspecialchars($item['category']??'General');?></span></td>
                    <td><?php echo $item['current_quantity'];?></td>
                    <td><?php echo $item['total_issued'];?></td>
                    <td><?php echo $item['total_received'];?></td>
                    <td><?php $n=$item['total_received']-$item['total_issued']; echo ($n>=0?'+':'').$n;?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div style="margin-top:16px;text-align:right">
    <button onclick="window.print()" class="btn btn-success">&#128438; Print Report</button>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
