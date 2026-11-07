<?php
// category_reports.php — front desk view
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

$categories           = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$selected_category_id = (int)($_GET['category_id'] ?? 0);
$start_date           = safe_date($_GET['start_date'] ?? null, date('Y-m-01'));
$end_date             = safe_date($_GET['end_date']   ?? null, date('Y-m-d'));
if ($end_date < $start_date) { [$start_date, $end_date] = [$end_date, $start_date]; }
$current_month        = date('Y-m');
$selected_month       = safe_month($_GET['month'] ?? null);
if ($selected_month > $current_month) $selected_month = $current_month;

$category_data     = null;
$items_in_category = [];
$receiving_data    = [];
$issuing_data      = [];
$opening_stock_data = [];
$existing_report   = null;

if ($selected_category_id > 0) {
    $report_check = $pdo->prepare("SELECT * FROM category_monthly_reports WHERE category_id=? AND DATE_FORMAT(report_month,'%Y-%m')=?");
    $report_check->execute([$selected_category_id, $selected_month]);
    $existing_report = $report_check->fetch();

    if ($existing_report) {
        foreach (json_decode($existing_report['items_data'], true) as $item) {
            $opening_stock_data[$item['item_name']] = $item['opening_stock'];
        }
    }

    $cat_stmt = $pdo->prepare("SELECT id, name FROM categories WHERE id=?");
    $cat_stmt->execute([$selected_category_id]);
    $category_data = $cat_stmt->fetch();

    $items_stmt = $pdo->prepare("SELECT id, item_name, current_quantity, reorder_level, unit_price FROM items WHERE category_id=? ORDER BY item_name");
    $items_stmt->execute([$selected_category_id]);
    $items_in_category = $items_stmt->fetchAll();

    $r = $pdo->prepare("SELECT COUNT(*) as transaction_count, SUM(r.quantity) as total_quantity, COUNT(DISTINCT r.supplier) as supplier_count FROM stock_receiving r JOIN items i ON r.item_id=i.id WHERE i.category_id=? AND DATE(r.created_at) BETWEEN ? AND ?");
    $r->execute([$selected_category_id, $start_date, $end_date]);
    $receiving_data = $r->fetch();

    $i = $pdo->prepare("SELECT COUNT(*) as transaction_count, SUM(quantity) as total_quantity, COUNT(DISTINCT issued_to) as recipient_count FROM stock_issuing si JOIN items i ON si.item_id=i.id WHERE i.category_id=? AND DATE(si.created_at) BETWEEN ? AND ?");
    $i->execute([$selected_category_id, $start_date, $end_date]);
    $issuing_data = $i->fetch();

    $t = $pdo->prepare("(SELECT 'Received' as type, r.created_at, i.item_name, r.quantity, r.supplier as counterparty, u.full_name as user_name FROM stock_receiving r JOIN items i ON r.item_id=i.id JOIN users u ON r.received_by=u.id WHERE i.category_id=? AND DATE(r.created_at) BETWEEN ? AND ?) UNION ALL (SELECT 'Issued' as type, si.created_at, i.item_name, si.quantity, si.issued_to as counterparty, u.full_name as user_name FROM stock_issuing si JOIN items i ON si.item_id=i.id JOIN users u ON si.issued_by=u.id WHERE i.category_id=? AND DATE(si.created_at) BETWEEN ? AND ?) ORDER BY created_at DESC LIMIT 100");
    $t->execute([$selected_category_id, $start_date, $end_date, $selected_category_id, $start_date, $end_date]);
    $transactions = $t->fetchAll();
}

$low_stock_count = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report'])) {
    $v            = new Validator($_POST);
    $cat_row      = $v->exists($pdo, 'category_id', 'category', 'categories', 'id, name');
    $report_month = $v->month('report_month', 'Report month');
    $notes        = $v->textarea('notes', 'Notes', 1000);
    $category_id  = $cat_row ? (int)$cat_row['id'] : 0;
    if ($v->ok() && ($category_id !== $selected_category_id || $report_month !== $selected_month)) {
        $v->fail('The page changed while you were working. Refresh and submit again.');
    }
    if ($v->ok() && empty($items_in_category)) {
        $v->fail('There are no items in this category to report on.');
    }
    $openings = [];
    foreach ($items_in_category as $item) {
        $openings[$item['id']] = $v->int('opening_'.$item['id'], 'Opening stock for ' . $item['item_name'], 0, 1000000);
    }
    if (!$v->ok()) {
        $error = $v->message();
    } else {
    $items_data   = [];
    foreach ($items_in_category as $item) {
        $opening      = $openings[$item['id']];
        $closing      = $item['current_quantity'];
        $items_data[] = ['item_name'=>$item['item_name'], 'opening_stock'=>$opening, 'closing_stock'=>$closing, 'reorder_level'=>$item['reorder_level'], 'status'=>($closing<=$item['reorder_level']?'Low Stock':'OK')];
    }
    $items_json   = json_encode($items_data);
    $total_items  = count($items_in_category);
    $total_units  = array_sum(array_column($items_in_category, 'current_quantity'));
    $low_count    = count(array_filter($items_in_category, fn($i)=>$i['current_quantity']<=$i['reorder_level']));
    $total_value  = array_sum(array_map(fn($i)=>$i['current_quantity']*$i['unit_price'], $items_in_category));

    $pdo->beginTransaction();
    try {
        $check = $pdo->prepare("SELECT id FROM category_monthly_reports WHERE category_id=? AND DATE_FORMAT(report_month,'%Y-%m')=?");
        $check->execute([$category_id, $report_month]);
        if ($check->fetch()) {
            $pdo->prepare("UPDATE category_monthly_reports SET items_data=?,total_items=?,total_units=?,total_value=?,low_stock_count=?,notes=?,submitted_by=?,submitted_at=NOW() WHERE category_id=? AND DATE_FORMAT(report_month,'%Y-%m')=?")
                ->execute([$items_json, $total_items, $total_units, $total_value, $low_count, $notes, $_SESSION['user_id'], $category_id, $report_month]);
            $success = "Report updated.";
        } else {
            $pdo->prepare("INSERT INTO category_monthly_reports (category_id,report_month,items_data,total_items,total_units,total_value,low_stock_count,notes,submitted_by) VALUES (?,?,?,?,?,?,?,?,?)")
                ->execute([$category_id, $report_month.'-01', $items_json, $total_items, $total_units, $total_value, $low_count, $notes, $_SESSION['user_id']]);
            $success = "Report submitted.";
        }
        logAudit($pdo, $_SESSION['user_id'], 'SUBMIT_CATEGORY_REPORT', null, null, "Submitted monthly report for: {$category_data['name']} ($report_month)");
        $pdo->commit();
        header("Location: category_reports.php?category_id=$category_id&start_date=$start_date&end_date=$end_date&month=$report_month");
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log($e->getMessage());
        $error = 'Could not save the report. Please try again.';
    }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Category Reports</h1>
    <a href="reports.php" class="btn btn-sm">Back to Monthly Reports</a>
</div>

<div class="info-note">Enter the opening stock for each item (beginning of month). Closing stock is populated automatically from current quantities.</div>

<!-- Month Selector -->
<div class="card" style="margin-bottom:14px; display:flex; align-items:center; gap:14px; flex-wrap:wrap;">
    <label style="font-size:.78rem; font-weight:500; color:var(--text2)">Report Month</label>
    <input type="month" id="monthSelect" value="<?php echo $selected_month; ?>" max="<?php echo $current_month; ?>"
           onchange="window.location.href='?category_id=<?php echo $selected_category_id; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&month='+this.value"
           style="padding:6px 10px; border:1px solid var(--border); border-radius:var(--radius-sm); font-size:.84rem; background:var(--surface);">
</div>

<!-- Category selector -->
<div class="pill-tabs">
    <?php foreach ($categories as $cat): ?>
    <?php
    $rc = $pdo->prepare("SELECT id FROM category_monthly_reports WHERE category_id=? AND DATE_FORMAT(report_month,'%Y-%m')=?");
    $rc->execute([$cat['id'], $selected_month]);
    $has_report = $rc->fetch();
    ?>
    <a href="?category_id=<?php echo $cat['id']; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&month=<?php echo $selected_month; ?>"
       class="pill-tab <?php echo $selected_category_id==$cat['id']?'active':''; ?>">
        <?php echo htmlspecialchars($cat['name']); ?><?php if ($has_report): ?> &check;<?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($selected_category_id > 0 && $category_data): ?>

<?php if ($existing_report): ?>
<div class="info-note" style="background:#f0fdf4; border-color:var(--success);">
    Report for <?php echo date('F Y', strtotime($selected_month.'-01')); ?> already submitted on <?php echo date('d/m/Y H:i', strtotime($existing_report['submitted_at'])); ?>.
</div>
<?php endif; ?>

<?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
<?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<!-- Summary Stats -->
<div class="stat-row">
    <div class="stat-box"><div class="num"><?php echo count($items_in_category); ?></div><div class="lbl">Total Items</div></div>
    <div class="stat-box"><div class="num"><?php echo $receiving_data['total_quantity']??0; ?></div><div class="lbl">Units Received</div></div>
    <div class="stat-box"><div class="num"><?php echo $issuing_data['total_quantity']??0; ?></div><div class="lbl">Units Issued</div></div>
    <div class="stat-box"><div class="num"><?php echo ($receiving_data['total_quantity']??0)-($issuing_data['total_quantity']??0); ?></div><div class="lbl">Net Movement</div></div>
</div>

<!-- Report Form -->
<form method="POST"
      data-confirm-title="<?php echo $existing_report ? 'Update report' : 'Submit report'; ?>"
      data-confirm="<?php echo $existing_report
          ? 'Replace the ' . htmlspecialchars($category_data['name']) . ' report for ' . date('F Y', strtotime($selected_month.'-01')) . ' with these figures? The earlier version will be overwritten.'
          : 'Submit the ' . htmlspecialchars($category_data['name']) . ' report for ' . date('F Y', strtotime($selected_month.'-01')) . '? It will be saved as the month-end stock record.'; ?>"
      data-confirm-ok="<?php echo $existing_report ? 'Update report' : 'Submit report'; ?>">
    <input type="hidden" name="category_id"  value="<?php echo $selected_category_id; ?>">
    <input type="hidden" name="report_month" value="<?php echo $selected_month; ?>">

    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px; flex-wrap:wrap; gap:8px;">
            <h3 style="margin:0"><?php echo htmlspecialchars($category_data['name']); ?> &mdash; <?php echo date('F Y', strtotime($selected_month.'-01')); ?></h3>
        </div>
        <div class="table-container">
            <table>
                <thead>
                    <tr><th>Item</th><th>Opening Stock</th><th>Closing Stock</th><th>Reorder Level</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($items_in_category as $item):
                        $closing   = $item['current_quantity'];
                        $opening   = isset($opening_stock_data[$item['item_name']]) ? $opening_stock_data[$item['item_name']] : 0;
                        $is_low    = $closing <= $item['reorder_level'];
                        if ($is_low) $low_stock_count++;
                    ?>
                    <tr>
                        <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']); ?></td>
                        <td>
                            <input type="number" name="opening_<?php echo $item['id']; ?>"
                                   value="<?php echo isset($error) ? htmlspecialchars($_POST['opening_'.$item['id']] ?? $opening) : $opening; ?>" min="0" max="1000000" step="1" required aria-label="Opening stock for <?php echo htmlspecialchars($item['item_name']); ?>"
                                   style="width:90px; padding:5px 8px; border:1px solid var(--border); border-radius:var(--radius-sm); font-size:.82rem; font-family:var(--mono);">
                        </td>
                        <td><strong><?php echo $closing; ?></strong></td>
                        <td><?php echo $item['reorder_level']; ?></td>
                        <td>
                            <?php if ($closing==0): ?><span class="status-issuing">Out of Stock</span>
                            <?php elseif ($is_low): ?><span class="status-pending">Low Stock</span>
                            <?php else: ?><span class="status-receiving">OK</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!empty($transactions)): ?>
    <div class="card">
        <h3 style="margin-bottom:12px">Activity &mdash; <?php echo date('d/m/Y', strtotime($start_date)); ?> to <?php echo date('d/m/Y', strtotime($end_date)); ?></h3>
        <div class="table-container">
            <table>
                <thead>
                    <tr><th>Date</th><th>Type</th><th>Item</th><th>Qty</th><th>Counterparty</th><th>User</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $t): ?>
                    <tr>
                        <td style="white-space:nowrap; color:var(--muted); font-size:.76rem"><?php echo date('d/m/Y H:i', strtotime($t['created_at'])); ?></td>
                        <td><span class="status-<?php echo strtolower($t['type']); ?>"><?php echo $t['type']; ?></span></td>
                        <td style="font-weight:500"><?php echo htmlspecialchars($t['item_name']); ?></td>
                        <td><?php echo $t['quantity']; ?></td>
                        <td style="color:var(--muted)"><?php echo htmlspecialchars($t['counterparty']??'—'); ?></td>
                        <td style="color:var(--muted)"><?php echo htmlspecialchars($t['user_name']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div class="card">
        <div class="form-group">
            <label>Notes (Optional)</label>
            <textarea name="notes" rows="3" maxlength="1000" placeholder="Any observations about this month's stock..."><?php echo htmlspecialchars($existing_report['notes']??''); ?></textarea>
        </div>
        <div class="form-actions">
            <?php if ($existing_report): ?>
            <button type="submit" name="submit_report" class="btn btn-warning">Update Report</button>
            <?php else: ?>
            <button type="submit" name="submit_report" class="btn btn-success">Submit Report</button>
            <?php endif; ?>
            <a href="dashboard.php" class="btn">Cancel</a>
        </div>
    </div>
</form>

<?php else: ?>
<div class="empty-state" style="background:var(--surface); border:1px solid var(--border); border-radius:var(--radius);">
    <p>Select a category above to view and submit the monthly report.</p>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>