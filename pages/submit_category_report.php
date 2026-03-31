<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();
if (isManager()) { header('Location: manager_dashboard.php'); exit(); }

$current_month        = date('Y-m');
$selected_month       = $_GET['month']       ?? $current_month;
$selected_category_id = (int)($_GET['category_id'] ?? 0);

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

$prev_month  = date('Y-m', strtotime($selected_month.'-01 -1 month'));
$prev_report = $pdo->prepare("SELECT items_data FROM category_monthly_reports WHERE category_id=? AND DATE_FORMAT(report_month, '%Y-%m')=?");
$prev_report->execute([$selected_category_id, $prev_month]);
$prev_report = $prev_report->fetch();

$report_check = $pdo->prepare("SELECT * FROM category_monthly_reports WHERE category_id=? AND DATE_FORMAT(report_month, '%Y-%m')=?");
$report_check->execute([$selected_category_id, $selected_month]);
$existing_report = $report_check->fetch();

$items         = [];
$category_name = '';
if ($selected_category_id > 0) {
    $cat_stmt = $pdo->prepare("SELECT name FROM categories WHERE id=?");
    $cat_stmt->execute([$selected_category_id]);
    $category_name = $cat_stmt->fetchColumn();
    $items_stmt    = $pdo->prepare("SELECT * FROM items WHERE category_id=? ORDER BY item_name");
    $items_stmt->execute([$selected_category_id]);
    $items = $items_stmt->fetchAll();
}

$prev_stock_data = [];
if ($prev_report) {
    foreach (json_decode($prev_report['items_data'], true) as $item) {
        $prev_stock_data[$item['item_name']] = $item['closing_stock'];
    }
}

$stock_data       = [];
$total_items_count = 0;
$total_units       = 0;
$low_stock_count   = 0;
foreach ($items as $item) {
    $opening = $prev_stock_data[$item['item_name']] ?? 0;
    $closing = $item['current_quantity'];
    $status  = $closing <= $item['reorder_level'] ? 'Low Stock' : 'OK';
    $stock_data[] = ['item_name'=>$item['item_name'], 'opening_stock'=>$opening, 'closing_stock'=>$closing, 'reorder_level'=>$item['reorder_level'], 'status'=>$status];
    $total_items_count++;
    $total_units += $closing;
    if ($closing <= $item['reorder_level']) $low_stock_count++;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report'])) {
    $category_id  = (int)$_POST['category_id'];
    $report_month = $_POST['report_month'];
    $notes        = trim($_POST['notes']);
    $items_json   = json_encode($stock_data);

    $pdo->beginTransaction();
    try {
        $check = $pdo->prepare("SELECT id FROM category_monthly_reports WHERE category_id=? AND DATE_FORMAT(report_month,'%Y-%m')=?");
        $check->execute([$category_id, $report_month]);
        if ($check->fetch()) {
            $pdo->prepare("UPDATE category_monthly_reports SET items_data=?,total_items=?,total_units=?,low_stock_count=?,notes=?,submitted_by=?,submitted_at=NOW() WHERE category_id=? AND DATE_FORMAT(report_month,'%Y-%m')=?")
                ->execute([$items_json, $total_items_count, $total_units, $low_stock_count, $notes, $_SESSION['user_id'], $category_id, $report_month]);
            $message = "Report updated.";
        } else {
            $pdo->prepare("INSERT INTO category_monthly_reports (category_id,report_month,items_data,total_items,total_units,low_stock_count,notes,submitted_by) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$category_id, $report_month.'-01', $items_json, $total_items_count, $total_units, $low_stock_count, $notes, $_SESSION['user_id']]);
            $message = "Report submitted.";
        }
        logAudit($pdo, $_SESSION['user_id'], 'SUBMIT_CATEGORY_REPORT', null, null, "Submitted monthly report for: $category_name ($report_month)");
        $pdo->commit();
        header("Location: submit_category_report.php?category_id=$category_id&month=$report_month");
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Submit Monthly Report</h1>
    <a href="dashboard.php" class="btn btn-sm">Back to Dashboard</a>
</div>

<!-- Month Selector -->
<div class="card" style="margin-bottom:14px;">
    <div style="display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
        <label style="font-size:.78rem; font-weight:500; color:var(--text2)">Report Month</label>
        <input type="month" id="monthSelect" value="<?php echo $selected_month; ?>"
               onchange="window.location.href='?month='+this.value+'&category_id=<?php echo $selected_category_id; ?>'"
               style="padding:6px 10px; border:1px solid var(--border); border-radius:var(--radius-sm); font-size:.84rem; background:var(--surface);">
    </div>
</div>

<!-- Category Selector -->
<div class="pill-tabs">
    <?php foreach ($categories as $cat): ?>
    <?php
    $r = $pdo->prepare("SELECT submitted_at FROM category_monthly_reports WHERE category_id=? AND DATE_FORMAT(report_month,'%Y-%m')=?");
    $r->execute([$cat['id'], $selected_month]);
    $submitted = $r->fetch();
    ?>
    <a href="?category_id=<?php echo $cat['id']; ?>&month=<?php echo $selected_month; ?>"
       class="pill-tab <?php echo $selected_category_id==$cat['id']?'active':''; ?>">
        <?php echo htmlspecialchars($cat['name']); ?><?php if ($submitted): ?> &check;<?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($selected_category_id > 0 && $category_name): ?>

<?php if ($existing_report): ?>
<div class="info-note" style="background:#f0fdf4; border-color:var(--success);">
    <strong><?php echo htmlspecialchars($category_name); ?></strong> — report for <?php echo date('F Y', strtotime($selected_month.'-01')); ?> was submitted on <?php echo date('d/m/Y H:i', strtotime($existing_report['submitted_at'])); ?>.
</div>
<?php else: ?>
<div class="info-note">
    <strong><?php echo htmlspecialchars($category_name); ?></strong> — <?php echo date('F Y', strtotime($selected_month.'-01')); ?>. Review and submit below.
</div>
<?php endif; ?>

<?php if (isset($error)): ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<!-- Stats -->
<div class="stat-row">
    <div class="stat-box"><div class="num"><?php echo $total_items_count; ?></div><div class="lbl">Total Items</div></div>
    <div class="stat-box"><div class="num"><?php echo number_format($total_units); ?></div><div class="lbl">Total Units</div></div>
    <div class="stat-box"><div class="num" style="color:<?php echo $low_stock_count>0?'var(--danger)':'var(--success)'; ?>"><?php echo $low_stock_count; ?></div><div class="lbl">Low Stock Items</div></div>
</div>

<!-- Report Form -->
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
        <h3 style="margin:0">Stock Details</h3>
        <span style="font-size:.72rem; color:var(--muted)"><?php echo htmlspecialchars($category_name); ?> &mdash; <?php echo date('F Y', strtotime($selected_month.'-01')); ?></span>
    </div>
    <div class="table-container">
        <table>
            <thead>
                <tr><th>Item</th><th>Opening Stock</th><th>Closing Stock</th><th>Reorder Level</th><th>Status</th></tr>
            </thead>
            <tbody>
                <?php foreach ($stock_data as $item): ?>
                <tr>
                    <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']); ?></td>
                    <td style="color:var(--muted)"><?php echo $item['opening_stock']; ?></td>
                    <td><strong><?php echo $item['closing_stock']; ?></strong></td>
                    <td><?php echo $item['reorder_level']; ?></td>
                    <td>
                        <?php if ($item['status']=='Low Stock'): ?>
                            <span class="status-pending">Low Stock</span>
                        <?php else: ?>
                            <span class="status-receiving">OK</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <form method="POST" style="margin-top:18px;">
        <input type="hidden" name="category_id"  value="<?php echo $selected_category_id; ?>">
        <input type="hidden" name="report_month" value="<?php echo $selected_month; ?>">
        <div class="form-group">
            <label>Notes (Optional)</label>
            <textarea name="notes" rows="3" placeholder="Any notes about this month's stock..."><?php echo htmlspecialchars($existing_report['notes']??''); ?></textarea>
        </div>
        <div class="form-actions">
            <?php if ($existing_report): ?>
            <button type="submit" name="submit_report" class="btn btn-warning">Update Report</button>
            <?php else: ?>
            <button type="submit" name="submit_report" class="btn btn-success">Submit Report</button>
            <?php endif; ?>
            <a href="dashboard.php" class="btn">Cancel</a>
        </div>
    </form>
</div>

<?php else: ?>
<div class="empty-state" style="background:var(--surface); border:1px solid var(--border); border-radius:var(--radius);">
    <p>Select a category above to view and submit the monthly stock report.</p>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>