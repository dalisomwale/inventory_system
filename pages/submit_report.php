<?php
require_once __DIR__ . '/../config/database.php';
redirectIfNotLoggedIn();

// Only admin/reception can submit reports
if (!isAdmin()) {
    header('Location: dashboard.php');
    exit();
}

$selected_month = $_GET['month'] ?? date('Y-m');
$selected_category_id = (int)($_GET['category'] ?? 0);
$message = '';
$error = '';

// Get all categories
$categories = $pdo->query("SELECT id, name, description FROM categories ORDER BY name")->fetchAll();

// Check if report already exists for this month and category
if ($selected_category_id > 0) {
    $check = $pdo->prepare("SELECT id FROM category_monthly_reports WHERE category_id = ? AND strftime('%Y-%m', report_month) = ?");
    $check->execute([$selected_category_id, $selected_month]);
    if ($check->fetch()) {
        $error = "A report for this category and month already exists. You can edit it from the reports page.";
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_report'])) {
    $category_id = $_POST['category_id'];
    $report_month = $_POST['report_month'];
    $notes = $_POST['notes'] ?? '';
    
    // Get all items for this category
    $items = $pdo->prepare("SELECT * FROM items WHERE category_id = ? ORDER BY item_name");
    $items->execute([$category_id]);
    $items_list = $items->fetchAll();
    
    $items_data = [];
    $total_units = 0;
    $low_stock_count = 0;
    
    foreach ($items_list as $item) {
        // For demo, opening stock is current quantity (in real system, you'd track this separately)
        $opening_stock = $item['current_quantity'];
        $closing_stock = $item['current_quantity'];
        $status = ($closing_stock <= $item['reorder_level']) ? 'Low Stock' : 'OK';
        
        if ($status == 'Low Stock') {
            $low_stock_count++;
        }
        
        $items_data[] = [
            'item_name' => $item['item_name'],
            'opening_stock' => $opening_stock,
            'closing_stock' => $closing_stock,
            'reorder_level' => $item['reorder_level'],
            'status' => $status
        ];
        
        $total_units += $closing_stock;
    }
    
    // Insert report
    $stmt = $pdo->prepare("
        INSERT INTO category_monthly_reports (category_id, report_month, items_data, total_items, total_units, low_stock_count, notes, submitted_by) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $result = $stmt->execute([
        $category_id,
        $report_month,
        json_encode($items_data),
        count($items_data),
        $total_units,
        $low_stock_count,
        $notes,
        $_SESSION['user_id']
    ]);
    
    if ($result) {
        $message = "Monthly report submitted successfully!";
        header("refresh:2;url=reports.php?month=" . date('Y-m', strtotime($report_month)));
    } else {
        $error = "Failed to submit report. Please try again.";
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
    <h1>Submit Monthly Report</h1>
    <a href="reports.php" class="btn btn-sm">Back to Reports</a>
</div>

<?php if ($message): ?>
    <div class="alert alert-success" style="background: #d1fae5; color: #065f46; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;">
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger" style="background: #fee2e2; color: #991b1b; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px;">
        <?php echo $error; ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body" style="padding: 24px;">
        <form method="POST">
            <div class="form-group">
                <label>Select Category</label>
                <select name="category_id" required onchange="window.location.href='?category='+this.value+'&month=<?php echo $selected_month; ?>'">
                    <option value="">-- Select Category --</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo $selected_category_id == $cat['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Report Month</label>
                <input type="month" name="report_month" value="<?php echo $selected_month; ?>" required>
            </div>
            
            <?php if ($selected_category_id > 0 && !$error): ?>
            <div class="form-group">
                <label>Notes (Optional)</label>
                <textarea name="notes" rows="4" placeholder="Add any notes about stock issues, observations, or recommendations..."></textarea>
            </div>
            
            <div style="margin-top: 24px;">
                <button type="submit" name="submit_report" class="btn btn-primary">Submit Report</button>
                <a href="reports.php" class="btn">Cancel</a>
            </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php if ($selected_category_id > 0 && !$error): ?>
<div class="card" style="margin-top: 24px;">
    <div class="card-header" style="padding: 16px 20px; border-bottom: 1px solid #e2e8f0;">
        <h3>Preview: Items that will be included</h3>
    </div>
    <div class="table-container">
        <table style="width: 100%">
            <thead>
                <tr>
                    <th>Item Name</th>
                    <th>Current Stock</th>
                    <th>Reorder Level</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $preview_items = $pdo->prepare("SELECT * FROM items WHERE category_id = ? ORDER BY item_name");
                $preview_items->execute([$selected_category_id]);
                $items = $preview_items->fetchAll();
                foreach ($items as $item):
                    $status = ($item['current_quantity'] <= $item['reorder_level']) ? 'Low Stock' : 'OK';
                ?>
                <tr>
                    <td><?php echo htmlspecialchars($item['item_name']); ?></td>
                    <td><?php echo $item['current_quantity']; ?></td>
                    <td><?php echo $item['reorder_level']; ?></td>
                    <td>
                        <?php if ($status == 'Low Stock'): ?>
                            <span style="background: #fef3c7; color: #d97706; padding: 2px 8px; border-radius: 20px; font-size: 11px;">Low Stock</span>
                        <?php else: ?>
                            <span style="background: #d1fae5; color: #059669; padding: 2px 8px; border-radius: 20px; font-size: 11px;">OK</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>