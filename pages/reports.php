<?php
require_once __DIR__ . '/../config/database.php';
redirectIfNotLoggedIn();
if (!isManager()) { header('Location: dashboard.php'); exit(); }

$selected_month       = $_GET['month']       ?? date('Y-m');
$selected_category_id = (int)($_GET['category_id'] ?? 0);

$categories = $pdo->query("SELECT id, name, description FROM categories ORDER BY name")->fetchAll();

$reports = $pdo->prepare("
    SELECT r.*, c.name as category_name, u.full_name as submitted_by_name
    FROM category_monthly_reports r
    JOIN categories c ON r.category_id=c.id
    JOIN users u ON r.submitted_by=u.id
    WHERE strftime('%Y-%m', r.report_month) = ?
    ORDER BY c.name
");
$reports->execute([$selected_month]);
$all_reports = $reports->fetchAll();

$selected_report = null;
$items_data      = [];
$total_value     = 0;
if ($selected_category_id > 0) {
    $r = $pdo->prepare("SELECT r.*,c.name as category_name,c.description as category_description,u.full_name as submitted_by_name FROM category_monthly_reports r JOIN categories c ON r.category_id=c.id JOIN users u ON r.submitted_by=u.id WHERE r.category_id=? AND strftime('%Y-%m', r.report_month)=?");
    $r->execute([$selected_category_id, $selected_month]);
    $selected_report = $r->fetch();

    if ($selected_report) {
        $items_data = json_decode($selected_report['items_data'], true);
        foreach ($items_data as $key => $item) {
            $i = $pdo->prepare("SELECT unit_price, current_quantity FROM items WHERE item_name=? AND category_id=?");
            $i->execute([$item['item_name'], $selected_category_id]);
            $db_item = $i->fetch();
            $items_data[$key]['unit_price']  = $db_item ? $db_item['unit_price'] : 0;
            $items_data[$key]['total_value'] = $items_data[$key]['closing_stock'] * ($db_item ? $db_item['unit_price'] : 0);
        }
        $total_value = array_sum(array_column($items_data, 'total_value'));
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Monthly Reports</h1>
    <a href="manager_dashboard.php" class="btn btn-sm">Back to Dashboard</a>
</div>

<!-- Month Selector -->
<div class="card" style="margin-bottom:14px;">
    <div class="card-body">
        <div style="display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
            <label style="font-size:.78rem; font-weight:500;">Select Month</label>
            <input type="month" id="monthSelect" value="<?php echo $selected_month; ?>"
                   onchange="window.location.href='?month='+this.value<?php if($selected_category_id>0) echo '&category_id='.$selected_category_id; ?>"
                   style="padding:6px 10px; border:1px solid #e2e8f0; border-radius:8px;">
        </div>
    </div>
</div>

<?php if ($selected_category_id > 0 && $selected_report): ?>
<!-- Detail View -->
<a href="reports.php?month=<?php echo $selected_month; ?>" style="display:inline-flex; align-items:center; gap:4px; margin-bottom:16px; color:#64748b; text-decoration:none;">&larr; All Categories</a>

<div class="card">
    <div class="card-header">
        <div>
            <h2 style="margin:0 0 4px"><?php echo htmlspecialchars($selected_report['category_name']); ?></h2>
            <div style="font-size:.76rem; color:#64748b">
                <?php echo date('F Y', strtotime($selected_report['report_month'])); ?>
                &mdash; Submitted by <?php echo htmlspecialchars($selected_report['submitted_by_name']); ?>
                on <?php echo date('d/m/Y H:i', strtotime($selected_report['submitted_at'])); ?>
            </div>
        </div>
        <button onclick="window.print()" class="btn btn-sm btn-info">Print</button>
    </div>
    <div class="card-body">
        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:20px;">
            <div style="background:#f8fafc; padding:16px; border-radius:8px;"><div style="font-size:24px; font-weight:700;"><?php echo $selected_report['total_items']; ?></div><div style="font-size:12px; color:#64748b;">Items</div></div>
            <div style="background:#f8fafc; padding:16px; border-radius:8px;"><div style="font-size:24px; font-weight:700;"><?php echo number_format($selected_report['total_units']); ?></div><div style="font-size:12px; color:#64748b;">Total Units</div></div>
            <div style="background:#f8fafc; padding:16px; border-radius:8px;"><div style="font-size:24px; font-weight:700;">K<?php echo number_format($total_value,2); ?></div><div style="font-size:12px; color:#64748b;">Total Value</div></div>
            <div style="background:#f8fafc; padding:16px; border-radius:8px;"><div style="font-size:24px; font-weight:700; color:<?php echo $selected_report['low_stock_count']>0?'#dc2626':'#10b981'; ?>"><?php echo $selected_report['low_stock_count']; ?></div><div style="font-size:12px; color:#64748b;">Low Stock</div></div>
        </div>
        
        <div class="table-container">
            <table style="width:100%">
                <thead>
                    <tr><th>Item</th><th>Opening</th><th>Closing</th><th>Unit Price</th><th>Total Value</th><th>Reorder</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($items_data as $item): ?>
                    <tr>
                        <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']); ?></td>
                        <td style="color:#64748b"><?php echo $item['opening_stock']; ?></td>
                        <td><strong><?php echo $item['closing_stock']; ?></strong></td>
                        <td>K<?php echo number_format($item['unit_price'],2); ?></td>
                        <td>K<?php echo number_format($item['total_value'],2); ?></td>
                        <td><?php echo $item['reorder_level']; ?></td>
                        <td><?php if ($item['status']=='Low Stock'): ?><span style="background:#fef3c7; color:#d97706; padding:2px 8px; border-radius:20px; font-size:11px;">Low Stock</span><?php else: ?><span style="background:#d1fae5; color:#059669; padding:2px 8px; border-radius:20px; font-size:11px;">OK</span><?php endif; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <?php if ($selected_report['notes']): ?>
        <div style="margin-top:16px; padding:12px; background:#eff6ff; border-radius:8px;">
            <strong>Notes from Reception:</strong><br>
            <?php echo nl2br(htmlspecialchars($selected_report['notes'])); ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- Grid Overview -->
<div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(260px,1fr)); gap:14px; margin-bottom:22px;">
    <?php
    $submitted_ids = array_column($all_reports, 'category_id');
    foreach ($categories as $cat):
        $report = null;
        foreach ($all_reports as $r) { if ($r['category_id']==$cat['id']) { $report=$r; break; } }
    ?>
    <a href="?category_id=<?php echo $cat['id']; ?>&month=<?php echo $selected_month; ?>" style="background:white; border:1px solid #e2e8f0; border-radius:12px; padding:16px; text-decoration:none; color:inherit; transition:all 0.2s;">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
            <span style="font-weight:600; font-size:.88rem"><?php echo htmlspecialchars($cat['name']); ?></span>
            <?php if ($report): ?>
            <span style="background:#d1fae5; color:#059669; padding:2px 8px; border-radius:20px; font-size:11px;">Submitted</span>
            <?php else: ?>
            <span style="background:#fef3c7; color:#d97706; padding:2px 8px; border-radius:20px; font-size:11px;">Pending</span>
            <?php endif; ?>
        </div>
        <?php if ($report): ?>
        <div style="display:flex; gap:16px; font-size:.76rem; color:#64748b; margin-bottom:8px;">
            <span><?php echo $report['total_items']; ?> items</span>
            <span><?php echo number_format($report['total_units']); ?> units</span>
            <?php if ($report['low_stock_count']>0): ?>
            <span style="color:#dc2626"><?php echo $report['low_stock_count']; ?> low</span>
            <?php endif; ?>
        </div>
        <div style="font-size:.72rem; color:#64748b; padding-top:8px; border-top:1px solid #e2e8f0">
            <?php echo date('d/m/Y', strtotime($report['submitted_at'])); ?>
        </div>
        <?php else: ?>
        <div style="font-size:.76rem; color:#64748b">Not yet submitted</div>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if (count($all_reports) > 0): ?>
<div class="card">
    <div class="card-header">
        <h3>Summary &mdash; <?php echo date('F Y', strtotime($selected_month.'-01')); ?></h3>
    </div>
    <div class="table-container">
        <table style="width:100%">
            <thead>
                <tr><th>Category</th><th>Items</th><th>Units</th><th>Low Stock</th><th>Submitted By</th><th>Submitted On</th></tr>
            </thead>
            <tbody>
                <?php foreach ($all_reports as $r): ?>
                <tr style="cursor:pointer" onclick="window.location.href='?category_id=<?php echo $r['category_id']; ?>&month=<?php echo $selected_month; ?>'">
                    <td style="font-weight:500"><?php echo htmlspecialchars($r['category_name']); ?></td>
                    <td><?php echo $r['total_items']; ?></td>
                    <td><?php echo number_format($r['total_units']); ?></td>
                    <td><?php echo $r['low_stock_count']>0 ? '<span style="color:#dc2626">'.$r['low_stock_count'].' items</span>' : '<span style="color:#10b981">None</span>'; ?></td>
                    <td style="color:#64748b"><?php echo htmlspecialchars($r['submitted_by_name']); ?></td>
                    <td style="color:#64748b; font-size:.76rem"><?php echo date('d/m/Y H:i', strtotime($r['submitted_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr><td style="font-weight:600">Total</td><td><?php echo array_sum(array_column($all_reports,'total_items')); ?></td><td><?php echo number_format(array_sum(array_column($all_reports,'total_units'))); ?></td><td colspan="3"></td></tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (count($all_reports)==0): ?>
<div class="card">
    <div style="padding:48px; text-align:center; color:#64748b;">
        No reports submitted for <?php echo date('F Y', strtotime($selected_month.'-01')); ?> yet.
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
