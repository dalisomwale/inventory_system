<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();
if (!isManager()) { header('Location: dashboard.php'); exit(); }

$selected_month       = $_GET['month']       ?? date('Y-m');
$selected_category_id = (int)($_GET['category_id'] ?? 0);

$categories = $pdo->query("SELECT id, name, description FROM categories ORDER BY name")->fetchAll();

$reports = $pdo->prepare("SELECT r.*,c.name as category_name,u.full_name as submitted_by_name FROM category_monthly_reports r JOIN categories c ON r.category_id=c.id JOIN users u ON r.submitted_by=u.id WHERE DATE_FORMAT(r.report_month,'%Y-%m')=? ORDER BY c.name");
$reports->execute([$selected_month]);
$all_reports = $reports->fetchAll();

$selected_report = null;
$items_data      = [];
$total_value     = 0;

if ($selected_category_id > 0) {
    $r = $pdo->prepare("SELECT r.*,c.name as category_name,c.description as category_description,u.full_name as submitted_by_name FROM category_monthly_reports r JOIN categories c ON r.category_id=c.id JOIN users u ON r.submitted_by=u.id WHERE r.category_id=? AND DATE_FORMAT(r.report_month,'%Y-%m')=?");
    $r->execute([$selected_category_id, $selected_month]);
    $selected_report = $r->fetch();

    if ($selected_report) {
        $items_data = json_decode($selected_report['items_data'], true);
        foreach ($items_data as &$item) {
            $i = $pdo->prepare("SELECT unit_price FROM items WHERE item_name=? AND category_id=?");
            $i->execute([$item['item_name'], $selected_category_id]);
            $db = $i->fetch();
            $item['unit_price']  = $db ? $db['unit_price'] : 0;
            $item['total_value'] = $item['closing_stock'] * $item['unit_price'];
        }
        $total_value = array_sum(array_column($items_data, 'total_value'));
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Category Reports</h1>
    <div style="display:flex; gap:8px;">
        <a href="reports.php" class="btn btn-sm btn-info">Full Reports</a>
        <a href="manager_dashboard.php" class="btn btn-sm">Dashboard</a>
    </div>
</div>

<!-- Month Selector -->
<div class="card" style="margin-bottom:14px; display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
    <label style="font-size:.78rem; font-weight:500; color:var(--text2)">Select Month</label>
    <input type="month" id="monthSelect" value="<?php echo $selected_month; ?>"
           onchange="window.location.href='?month='+this.value<?php if($selected_category_id>0) echo '&category_id='.$selected_category_id; ?>"
           style="padding:6px 10px; border:1px solid var(--border); border-radius:var(--radius-sm); font-size:.84rem; background:var(--surface);">
</div>

<?php if ($selected_category_id > 0 && $selected_report): ?>
<a href="view_category_reports.php?month=<?php echo $selected_month; ?>" class="back-link">&#8592; All Categories</a>

<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:16px; flex-wrap:wrap; gap:10px;">
        <div>
            <h2 style="margin:0 0 4px"><?php echo htmlspecialchars($selected_report['category_name']); ?></h2>
            <div style="font-size:.76rem; color:var(--muted)">
                <?php echo date('F Y', strtotime($selected_report['report_month'])); ?>
                &mdash; Submitted by <?php echo htmlspecialchars($selected_report['submitted_by_name']); ?>
                on <?php echo date('d/m/Y H:i', strtotime($selected_report['submitted_at'])); ?>
            </div>
        </div>
        <div style="display:flex; gap:8px;">
            <button onclick="window.print()" class="btn btn-sm btn-info">Print</button>
            <a href="reports.php?category_id=<?php echo $selected_category_id; ?>&start_date=<?php echo $selected_month.'-01'; ?>&end_date=<?php echo date('Y-m-t', strtotime($selected_month.'-01')); ?>" class="btn btn-sm btn-primary">Detailed Activity</a>
        </div>
    </div>

    <div class="stat-row">
        <div class="stat-box"><div class="num"><?php echo $selected_report['total_items']; ?></div><div class="lbl">Items</div></div>
        <div class="stat-box"><div class="num"><?php echo number_format($selected_report['total_units']); ?></div><div class="lbl">Total Units</div></div>
        <div class="stat-box"><div class="num" style="font-size:1.2rem">K<?php echo number_format($total_value,2); ?></div><div class="lbl">Total Value</div></div>
        <div class="stat-box"><div class="num" style="color:<?php echo $selected_report['low_stock_count']>0?'var(--danger)':'var(--success)'; ?>"><?php echo $selected_report['low_stock_count']; ?></div><div class="lbl">Low Stock</div></div>
    </div>

    <div class="table-container">
        <table>
            <thead>
                <tr><th>Item</th><th>Opening</th><th>Closing</th><th>Unit Price</th><th>Total Value</th><th>Reorder</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($items_data as $item): ?>
                <tr>
                    <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']); ?></td>
                    <td style="color:var(--muted)"><?php echo $item['opening_stock']; ?></td>
                    <td><strong><?php echo $item['closing_stock']; ?></strong></td>
                    <td style="font-family:var(--mono); font-size:.76rem">K<?php echo number_format($item['unit_price'],2); ?></td>
                    <td style="font-family:var(--mono); font-size:.76rem">K<?php echo number_format($item['total_value'],2); ?></td>
                    <td><?php echo $item['reorder_level']; ?></td>
                    <td>
                        <?php if ($item['status']=='Low Stock'): ?><span class="status-pending">Low Stock</span>
                        <?php else: ?><span class="status-receiving">OK</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="4" style="text-align:right; font-size:.76rem; color:var(--muted)">Total Value</td>
                    <td style="font-family:var(--mono)">K<?php echo number_format($total_value,2); ?></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <?php if ($selected_report['notes']): ?>
    <div class="info-note" style="margin-top:16px">
        <strong>Notes from Reception:</strong><br>
        <?php echo nl2br(htmlspecialchars($selected_report['notes'])); ?>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- Grid -->
<div class="report-grid">
    <?php foreach ($categories as $cat):
        $report = null;
        foreach ($all_reports as $r) { if ($r['category_id']==$cat['id']) { $report=$r; break; } }
    ?>
    <a href="?category_id=<?php echo $cat['id']; ?>&month=<?php echo $selected_month; ?>"
       class="report-card-item <?php echo $report?'submitted':'pending'; ?>">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
            <span style="font-weight:600; font-size:.88rem"><?php echo htmlspecialchars($cat['name']); ?></span>
            <?php if ($report): ?>
            <span class="status-receiving" style="font-size:.68rem">Submitted</span>
            <?php else: ?>
            <span class="status-pending" style="font-size:.68rem">Pending</span>
            <?php endif; ?>
        </div>
        <?php if ($report): ?>
        <div style="display:flex; gap:16px; font-size:.76rem; color:var(--muted); margin-bottom:8px;">
            <span><?php echo $report['total_items']; ?> items</span>
            <span><?php echo number_format($report['total_units']); ?> units</span>
            <?php if ($report['low_stock_count']>0): ?>
            <span style="color:var(--danger)"><?php echo $report['low_stock_count']; ?> low</span>
            <?php endif; ?>
        </div>
        <div style="font-size:.72rem; color:var(--muted); padding-top:8px; border-top:1px solid var(--border)">
            <?php echo date('d/m/Y', strtotime($report['submitted_at'])); ?>
        </div>
        <?php else: ?>
        <div style="font-size:.76rem; color:var(--muted)">Not yet submitted</div>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if (count($all_reports) > 0): ?>
<div class="card">
    <h3 style="margin-bottom:12px">Summary &mdash; <?php echo date('F Y', strtotime($selected_month.'-01')); ?></h3>
    <div class="table-container">
        <table>
            <thead>
                <tr><th>Category</th><th>Items</th><th>Units</th><th>Low Stock</th><th>Submitted By</th><th>Date</th></tr>
            </thead>
            <tbody>
                <?php foreach ($all_reports as $r): ?>
                <tr style="cursor:pointer" onclick="window.location.href='?category_id=<?php echo $r['category_id']; ?>&month=<?php echo $selected_month; ?>'">
                    <td style="font-weight:500"><?php echo htmlspecialchars($r['category_name']); ?></td>
                    <td><?php echo $r['total_items']; ?></td>
                    <td><?php echo number_format($r['total_units']); ?></td>
                    <td><?php echo $r['low_stock_count']>0 ? '<span style="color:var(--danger)">'.$r['low_stock_count'].' items</span>' : '<span style="color:var(--success)">None</span>'; ?></td>
                    <td style="color:var(--muted)"><?php echo htmlspecialchars($r['submitted_by_name']); ?></td>
                    <td style="color:var(--muted); font-size:.76rem"><?php echo date('d/m/Y H:i', strtotime($r['submitted_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td>Total</td>
                    <td><?php echo array_sum(array_column($all_reports,'total_items')); ?></td>
                    <td><?php echo number_format(array_sum(array_column($all_reports,'total_units'))); ?></td>
                    <td colspan="3"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (count($all_reports)==0): ?>
<div class="empty-state" style="background:var(--surface); border:1px solid var(--border); border-radius:var(--radius);">
    <p>No reports submitted for <?php echo date('F Y', strtotime($selected_month.'-01')); ?> yet.</p>
</div>
<?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>