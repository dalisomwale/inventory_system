<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$selected_category_id = (int)($_GET['category_id'] ?? 0);
$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$current_month = date('Y-m');
$selected_month = $_GET['month'] ?? $current_month;

$category_data = null;
$items_in_category = [];
$receiving_data = [];
$issuing_data = [];
$opening_stock_data = [];
$existing_report = null;

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

    $items_stmt = $pdo->prepare("SELECT id, item_name, current_quantity, reorder_level FROM items WHERE category_id=? ORDER BY item_name");
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
    $category_id = (int)$_POST['category_id'];
    $report_month = $_POST['report_month'];
    $notes = trim($_POST['notes']);
    $items_data = [];
    foreach ($items_in_category as $item) {
        $opening = isset($_POST['opening_'.$item['id']]) ? (int)$_POST['opening_'.$item['id']] : 0;
        $closing = $item['current_quantity'];
        $items_data[] = ['item_name'=>$item['item_name'], 'opening_stock'=>$opening, 'closing_stock'=>$closing, 'reorder_level'=>$item['reorder_level'], 'status'=>($closing<=$item['reorder_level']?'Low Stock':'OK')];
    }
    $items_json = json_encode($items_data);
    $total_items = count($items_in_category);
    $total_units = array_sum(array_column($items_in_category, 'current_quantity'));
    $low_count = count(array_filter($items_in_category, fn($i)=>$i['current_quantity']<=$i['reorder_level']));

    $pdo->beginTransaction();
    try {
        $check = $pdo->prepare("SELECT id FROM category_monthly_reports WHERE category_id=? AND DATE_FORMAT(report_month,'%Y-%m')=?");
        $check->execute([$category_id, $report_month]);
        if ($check->fetch()) {
            $pdo->prepare("UPDATE category_monthly_reports SET items_data=?,total_items=?,total_units=?,low_stock_count=?,notes=?,submitted_by=?,submitted_at=NOW() WHERE category_id=? AND DATE_FORMAT(report_month,'%Y-%m')=?")
                ->execute([$items_json, $total_items, $total_units, $low_count, $notes, $_SESSION['user_id'], $category_id, $report_month]);
            $success = "Report updated.";
        } else {
            $pdo->prepare("INSERT INTO category_monthly_reports (category_id,report_month,items_data,total_items,total_units,low_stock_count,notes,submitted_by) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$category_id, $report_month.'-01', $items_json, $total_items, $total_units, $low_count, $notes, $_SESSION['user_id']]);
            $success = "Report submitted.";
        }
        logAudit($pdo, $_SESSION['user_id'], 'SUBMIT_CATEGORY_REPORT', null, null, "Submitted monthly report for: {$category_data['name']} ($report_month)");
        $pdo->commit();
        header("Location: category_reports.php?category_id=$category_id&start_date=$start_date&end_date=$end_date&month=$report_month");
        exit();
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = "Error: " . $e->getMessage();
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
    <h1>Category Reports</h1>
    <a href="dashboard.php" class="btn">Back to Dashboard</a>
</div>


<div style="background: #e7f3ff; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #007bff; font-size: 13px;">
    Enter the opening stock for each item (beginning of month). Closing stock is populated automatically from current quantities.
</div>

<!-- Month & Category Selectors -->
<div style="background: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #e0e0e0;">
    <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
        <label style="font-weight: 500;">Report Month:</label>
        <input type="month" id="monthSelect" value="<?php echo $selected_month; ?>"
               onchange="window.location.href='?category_id=<?php echo $selected_category_id; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&month='+this.value"
               style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px;">
    </div>
</div>

<!-- Category Pills -->
<div style="display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 24px;">
    <?php foreach ($categories as $cat):
        $rc = $pdo->prepare("SELECT id FROM category_monthly_reports WHERE category_id=? AND DATE_FORMAT(report_month,'%Y-%m')=?");
        $rc->execute([$cat['id'], $selected_month]);
        $has_report = $rc->fetch();
    ?>
    <a href="?category_id=<?php echo $cat['id']; ?>&start_date=<?php echo $start_date; ?>&end_date=<?php echo $end_date; ?>&month=<?php echo $selected_month; ?>"
       class="pill-tab <?php echo $selected_category_id == $cat['id'] ? 'active' : ''; ?>"
       style="padding: 8px 20px; background: <?php echo $selected_category_id == $cat['id'] ? '#2563eb' : '#f1f5f9'; ?>; color: <?php echo $selected_category_id == $cat['id'] ? 'white' : '#475569'; ?>; border-radius: 40px; text-decoration: none; font-size: 13px; font-weight: 500; transition: all 0.2s;">
        <?php echo htmlspecialchars($cat['name']); ?><?php if ($has_report): ?> ✓<?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($selected_category_id > 0 && $category_data): ?>

<?php if ($existing_report): ?>
<div style="background: #d4edda; color: #155724; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;">
    Report for <?php echo date('F Y', strtotime($selected_month.'-01')); ?> already submitted on <?php echo date('d/m/Y H:i', strtotime($existing_report['submitted_at'])); ?>.
</div>
<?php endif; ?>

<?php if (isset($success)): ?>
<div style="background: #d4edda; color: #155724; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;"><?php echo $success; ?></div>
<?php endif; ?>
<?php if (isset($error)): ?>
<div style="background: #f8d7da; color: #721c24; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;"><?php echo $error; ?></div>
<?php endif; ?>

<!-- Summary Stats -->
<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 25px;">
    <div style="background: #f5f5f5; padding: 15px; text-align: center; border-radius: 8px;">
        <div style="font-size: 28px; font-weight: bold;"><?php echo count($items_in_category); ?></div>
        <div style="color: #666;">Total Items</div>
    </div>
    <div style="background: #f5f5f5; padding: 15px; text-align: center; border-radius: 8px;">
        <div style="font-size: 28px; font-weight: bold; color: #28a745;">+<?php echo $receiving_data['total_quantity'] ?? 0; ?></div>
        <div style="color: #666;">Units Received</div>
    </div>
    <div style="background: #f5f5f5; padding: 15px; text-align: center; border-radius: 8px;">
        <div style="font-size: 28px; font-weight: bold; color: #dc3545;">-<?php echo $issuing_data['total_quantity'] ?? 0; ?></div>
        <div style="color: #666;">Units Issued</div>
    </div>
    <div style="background: #f5f5f5; padding: 15px; text-align: center; border-radius: 8px;">
        <div style="font-size: 28px; font-weight: bold;"><?php echo ($receiving_data['total_quantity'] ?? 0) - ($issuing_data['total_quantity'] ?? 0); ?></div>
        <div style="color: #666;">Net Movement</div>
    </div>
</div>

<!-- Report Form -->
<form method="POST">
    <input type="hidden" name="category_id" value="<?php echo $selected_category_id; ?>">
    <input type="hidden" name="report_month" value="<?php echo $selected_month; ?>">

    <div style="background: white; border-radius: 8px; overflow: hidden; border: 1px solid #e0e0e0; margin-bottom: 20px;">
        <div style="padding: 16px 20px; border-bottom: 1px solid #e0e0e0; background: #fafafa;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3 style="margin: 0;"><?php echo htmlspecialchars($category_data['name']); ?> — <?php echo date('F Y', strtotime($selected_month.'-01')); ?></h3>
            </div>
        </div>
        <div style="padding: 20px; overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #fafafa; border-bottom: 2px solid #e0e0e0;">
                        <th style="padding: 12px; text-align: left;">Item</th>
                        <th style="padding: 12px; text-align: left;">Opening Stock</th>
                        <th style="padding: 12px; text-align: left;">Closing Stock</th>
                        <th style="padding: 12px; text-align: left;">Reorder Level</th>
                        <th style="padding: 12px; text-align: left;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items_in_category as $item):
                        $closing = $item['current_quantity'];
                        $opening = isset($opening_stock_data[$item['item_name']]) ? $opening_stock_data[$item['item_name']] : 0;
                        $is_low = $closing <= $item['reorder_level'];
                        if ($is_low) $low_stock_count++;
                    ?>
                    <tr style="border-bottom: 1px solid #e0e0e0;">
                        <td style="padding: 12px; font-weight: 500;"><?php echo htmlspecialchars($item['item_name']); ?></td>
                        <td style="padding: 12px;">
                            <input type="number" name="opening_<?php echo $item['id']; ?>"
                                   value="<?php echo $opening; ?>" min="0" required
                                   style="width: 100px; padding: 6px 10px; border: 1px solid #ddd; border-radius: 5px;">
                        </td>
                        <td style="padding: 12px;"><strong><?php echo $closing; ?></strong></td>
                        <td style="padding: 12px;"><?php echo $item['reorder_level']; ?></td>
                        <td style="padding: 12px;">
                            <?php if ($closing == 0): ?>
                                <span style="background: #f8d7da; color: #721c24; padding: 3px 10px; border-radius: 12px; font-size: 11px;">Out of Stock</span>
                            <?php elseif ($is_low): ?>
                                <span style="background: #fef3c7; color: #856404; padding: 3px 10px; border-radius: 12px; font-size: 11px;">Low Stock</span>
                            <?php else: ?>
                                <span style="background: #d4edda; color: #155724; padding: 3px 10px; border-radius: 12px; font-size: 11px;">OK</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if (!empty($transactions)): ?>
    <div style="background: white; border-radius: 8px; overflow: hidden; border: 1px solid #e0e0e0; margin-bottom: 20px;">
        <div style="padding: 16px 20px; border-bottom: 1px solid #e0e0e0; background: #fafafa;">
            <h3 style="margin: 0;">Activity — <?php echo date('d/m/Y', strtotime($start_date)); ?> to <?php echo date('d/m/Y', strtotime($end_date)); ?></h3>
        </div>
        <div style="padding: 20px; overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #fafafa; border-bottom: 2px solid #e0e0e0;">
                        <th style="padding: 12px; text-align: left;">Date</th>
                        <th style="padding: 12px; text-align: left;">Type</th>
                        <th style="padding: 12px; text-align: left;">Item</th>
                        <th style="padding: 12px; text-align: left;">Qty</th>
                        <th style="padding: 12px; text-align: left;">Counterparty</th>
                        <th style="padding: 12px; text-align: left;">User</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions as $t): ?>
                    <tr style="border-bottom: 1px solid #e0e0e0;">
                        <td style="padding: 12px; white-space: nowrap; color: #666; font-size: 12px;"><?php echo date('d/m/Y H:i', strtotime($t['created_at'])); ?></td>
                        <td style="padding: 12px;">
                            <?php if ($t['type'] == 'Received'): ?>
                                <span style="background: #d4edda; color: #155724; padding: 3px 10px; border-radius: 12px; font-size: 11px;">Received</span>
                            <?php else: ?>
                                <span style="background: #f8d7da; color: #721c24; padding: 3px 10px; border-radius: 12px; font-size: 11px;">Issued</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px; font-weight: 500;"><?php echo htmlspecialchars($t['item_name']); ?></td>
                        <td style="padding: 12px;"><?php echo $t['quantity']; ?></td>
                        <td style="padding: 12px; color: #666;"><?php echo htmlspecialchars($t['counterparty'] ?? '—'); ?></td>
                        <td style="padding: 12px; color: #666;"><?php echo htmlspecialchars($t['user_name']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <div style="background: white; border-radius: 8px; overflow: hidden; border: 1px solid #e0e0e0;">
        <div style="padding: 16px 20px; border-bottom: 1px solid #e0e0e0; background: #fafafa;">
            <h3 style="margin: 0;">Notes for Manager</h3>
        </div>
        <div style="padding: 20px;">
            <div style="margin-bottom: 20px;">
                <textarea name="notes" rows="4" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;" placeholder="Any observations about this month's stock..."><?php echo htmlspecialchars($existing_report['notes'] ?? ''); ?></textarea>
            </div>
            <div style="display: flex; gap: 12px;">
                <?php if ($existing_report): ?>
                <button type="submit" name="submit_report" class="btn" style="background: #f59e0b;">Update Report</button>
                <?php else: ?>
                <button type="submit" name="submit_report" class="btn" style="background: #28a745;">Submit Report</button>
                <?php endif; ?>
                <a href="dashboard.php" class="btn">Cancel</a>
            </div>
        </div>
    </div>
</form>

<?php else: ?>
<div style="background: white; padding: 60px; text-align: center; color: #999; border-radius: 8px; border: 1px solid #e0e0e0;">
    <p>Select a category above to view and submit the monthly report.</p>
</div>
<?php endif; ?>

<style>
.pill-tab.active {
    background: #2563eb !important;
    color: white !important;
}
.pill-tab:not(.active):hover {
    background: #e2e8f0 !important;
}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
