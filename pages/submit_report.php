<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

$user_role = $_SESSION['role'];
if ($user_role != 'front_desk') {
    header('Location: dashboard.php');
    exit();
}

$message = '';
$error = '';
$selected_category = (int)($_GET['category'] ?? 0);
$selected_month = safe_month($_GET['month'] ?? null);

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = new Validator($_POST);
    $cat_row = $v->exists($pdo, 'category_id', 'category', 'categories', 'id');
    $report_month = $v->month('report_month', 'Month');
    $notes = $v->textarea('notes', 'Notes', 1000);
    $category_id = $cat_row ? (int)$cat_row['id'] : 0;

    // Check if report already exists
    if (!$v->ok()) {
        $error = $v->message();
    } else {
    $check = $pdo->prepare("SELECT id FROM category_monthly_reports WHERE category_id = ? AND DATE_FORMAT(report_month, '%Y-%m') = ?");
    $check->execute([$category_id, $report_month]);
    if ($check->fetch()) {
        $error = "A report for this category and month already exists!";
    } else {
        // Get all items in this category
        $items = $pdo->prepare("SELECT * FROM items WHERE category_id = ? ORDER BY item_name");
        $items->execute([$category_id]);
        $items_list = $items->fetchAll();
        
        if (empty($items_list)) {
            $error = "No items found in this category.";
        } else {
            $items_data = [];
            $total_units = 0;
            $total_value = 0;
            $low_stock_count = 0;
            
            foreach ($items_list as $item) {
                $closing_stock = $item['current_quantity'];
                $status = ($closing_stock <= $item['reorder_level']) ? 'Low Stock' : 'OK';
                if ($status == 'Low Stock') $low_stock_count++;
                
                $items_data[] = [
                    'item_name' => $item['item_name'],
                    'closing_stock' => $closing_stock,
                    'reorder_level' => $item['reorder_level'],
                    'status' => $status
                ];
                $total_units += $closing_stock;
                $total_value += $closing_stock * $item['unit_price'];
            }
            
            $stmt = $pdo->prepare("INSERT INTO category_monthly_reports (category_id, report_month, items_data, total_items, total_units, total_value, low_stock_count, notes, submitted_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $result = $stmt->execute([$category_id, $report_month . '-01', json_encode($items_data), count($items_data), $total_units, $total_value, $low_stock_count, $notes, $_SESSION['user_id']]);
            
            if ($result) {
                $message = "Monthly report submitted successfully!";
                header('Refresh: 2; url=reports.php?month=' . $report_month);
            } else {
                $error = "Failed to submit report.";
            }
        }
    }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1>Submit Monthly Report</h1>
    <a href="reports.php" class="btn">Back to Reports</a>
</div>

<?php if ($message): ?>
    <div style="background: #d4edda; color: #155724; padding: 12px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #c3e6cb;"><?php echo $message; ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div style="background: #f8d7da; color: #721c24; padding: 12px; border-radius: 5px; margin-bottom: 20px; border: 1px solid #f5c6cb;"><?php echo $error; ?></div>
<?php endif; ?>

<div style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0;">
    <form method="POST"
          data-confirm-title="Submit report"
          data-confirm="Submit the {category_id} report for {report_month}? It will be sent to the manager."
          data-confirm-ok="Submit report">
        <div class="form-group" style="margin-bottom: 15px;">
            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Category:</label>
            <select name="category_id" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                <option value="">-- Select Category --</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?php echo $cat['id']; ?>" <?php echo $selected_category == $cat['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div style="margin-bottom: 15px;">
            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Month:</label>
            <input type="month" name="report_month" value="<?php echo $selected_month; ?>" max="<?php echo date('Y-m'); ?>" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
        </div>
        
        <div style="margin-bottom: 15px;">
            <label style="display: block; margin-bottom: 5px; font-weight: 500;">Notes / Observations:</label>
            <textarea name="notes" rows="4" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" placeholder="Add any observations about stock levels..."></textarea>
        </div>
        
        <button type="submit" name="submit_report" class="btn">Submit Report</button>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
