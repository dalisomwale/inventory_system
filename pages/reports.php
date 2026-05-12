<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

$user_role = $_SESSION['role'];
$is_front_desk = ($user_role == 'front_desk');
$is_manager = ($user_role == 'manager');

$selected_month = $_GET['month'] ?? date('Y-m');
$selected_category_id = (int)($_GET['category_id'] ?? 0);

// Get start and end dates for the selected month
$start_date = $selected_month . '-01';
$end_date = date('Y-m-t', strtotime($start_date));

// Get all categories
$stmt = $pdo->query("SELECT id, name, description FROM categories ORDER BY name");
$categories = $stmt->fetchAll();

// Get existing reports for the selected month only
$stmt = $pdo->prepare("
    SELECT r.*, c.name as category_name, u.full_name as submitted_by_name
    FROM category_monthly_reports r
    JOIN categories c ON r.category_id = c.id
    JOIN users u ON r.submitted_by = u.id
    WHERE DATE_FORMAT(r.report_month, '%Y-%m') = ?
    ORDER BY c.name
");
$stmt->execute([$selected_month]);
$all_reports = $stmt->fetchAll();

$items_data = [];
$total_received = 0;
$total_issued = 0;
$category_name = '';
$has_report = false;

if ($selected_category_id > 0) {
    // Get the category name
    $cat_stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
    $cat_stmt->execute([$selected_category_id]);
    $category = $cat_stmt->fetch();
    $category_name = $category ? $category['name'] : 'Unknown';

    // Check if report exists for this category and selected month
    $check_report = $pdo->prepare("SELECT id FROM category_monthly_reports WHERE category_id = ? AND DATE_FORMAT(report_month, '%Y-%m') = ?");
    $check_report->execute([$selected_category_id, $selected_month]);
    $has_report = $check_report->fetch() ? true : false;

    // Get all items in this category
    $items = $pdo->prepare("SELECT * FROM items WHERE category_id = ? ORDER BY item_name");
    $items->execute([$selected_category_id]);
    $items_list = $items->fetchAll();
    
    foreach ($items_list as $item) {
        // Get stock movement for this item during the selected month only
        $movement = $pdo->prepare("
            SELECT 
                COALESCE(SUM(CASE WHEN movement_type = 'received' THEN quantity ELSE 0 END), 0) as total_received,
                COALESCE(SUM(CASE WHEN movement_type = 'issued' THEN quantity ELSE 0 END), 0) as total_issued
            FROM stock_movement 
            WHERE item_id = ? AND DATE(created_at) BETWEEN ? AND ?
        ");
        $movement->execute([$item['id'], $start_date, $end_date]);
        $movement_data = $movement->fetch();
        
        $received_qty = $movement_data['total_received'];
        $issued_qty = $movement_data['total_issued'];
        $closing_stock = $item['current_quantity'];
        $opening_stock = $closing_stock - $received_qty + $issued_qty;
        $status = ($closing_stock <= $item['reorder_level']) ? 'Low Stock' : 'OK';
        
        $items_data[] = [
            'item_name' => $item['item_name'],
            'opening_stock' => max(0, $opening_stock),
            'received' => $received_qty,
            'issued' => $issued_qty,
            'closing_stock' => $closing_stock,
            'status' => $status
        ];
        
        $total_received += $received_qty;
        $total_issued += $issued_qty;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1>Monthly Reports</h1>
</div>

<?php if ($selected_category_id > 0 && !empty($items_data)): ?>
<!-- Detail View for a specific category - with month selector on the right -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
    <a href="reports.php?month=<?php echo $selected_month; ?>" style="color: #007bff; text-decoration: none;">← Back to All Categories</a>
    
    <!-- Month Selector on the right -->
    <div style="display: flex; align-items: center; gap: 10px;">
        <label style="font-weight: 500; color: #555;">Select Month:</label>
        <input type="month" id="monthSelect" value="<?php echo $selected_month; ?>"
               onchange="window.location.href='?month='+this.value<?php if($selected_category_id>0) echo '&category_id='.$selected_category_id; ?>"
               style="padding: 6px 10px; border: 1px solid #ddd; border-radius: 5px;">
    </div>
</div>

<div style="background: white; border-radius: 8px; overflow: hidden; border: 1px solid #e0e0e0;">
    <div style="padding: 20px; border-bottom: 1px solid #e0e0e0; background: #fafafa;">
        <h2 style="margin: 0 0 5px;"><?php echo htmlspecialchars($category_name); ?></h2>
        <div style="color: #666; font-size: 14px;">
            <?php echo date('F Y', strtotime($selected_month . '-01')); ?> |
            Stock Movement Report
            <?php if ($has_report): ?>
                <span style="background: #d4edda; color: #155724; padding: 3px 10px; border-radius: 12px; font-size: 11px; margin-left: 10px;">Report Submitted</span>
            <?php else: ?>
                <span style="background: #f5f5f5; color: #666; padding: 3px 10px; border-radius: 12px; font-size: 11px; margin-left: 10px;">Pending Submission</span>
            <?php endif; ?>
        </div>
    </div>
    <div style="padding: 20px;">
        <!-- Summary Stats -->
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 25px;">
            <div style="background: #f5f5f5; padding: 15px; text-align: center; border-radius: 8px;">
                <div style="font-size: 28px; font-weight: bold;"><?php echo count($items_data); ?></div>
                <div style="color: #666;">Total Items</div>
            </div>
            <div style="background: #f5f5f5; padding: 15px; text-align: center; border-radius: 8px;">
                <div style="font-size: 28px; font-weight: bold; color: #28a745;"><?php echo number_format($total_received); ?></div>
                <div style="color: #666;">Received (<?php echo date('M', strtotime($selected_month)); ?>)</div>
            </div>
            <div style="background: #f5f5f5; padding: 15px; text-align: center; border-radius: 8px;">
                <div style="font-size: 28px; font-weight: bold; color: #dc3545;"><?php echo number_format($total_issued); ?></div>
                <div style="color: #666;">Issued (<?php echo date('M', strtotime($selected_month)); ?>)</div>
            </div>
        </div>
        
        <!-- Items Table -->
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #fafafa; border-bottom: 2px solid #e0e0e0;">
                        <th style="padding: 12px; text-align: left;">Item Name</th>
                        <th style="padding: 12px; text-align: left;">Opening Stock</th>
                        <th style="padding: 12px; text-align: left;">Received</th>
                        <th style="padding: 12px; text-align: left;">Issued</th>
                        <th style="padding: 12px; text-align: left;">Closing Stock</th>
                        <th style="padding: 12px; text-align: left;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items_data as $item): ?>
                    <tr style="border-bottom: 1px solid #e0e0e0;">
                        <td style="padding: 12px; font-weight: 500;"><?php echo htmlspecialchars($item['item_name']); ?></td>
                        <td style="padding: 12px;"><?php echo $item['opening_stock']; ?></td>
                        <td style="padding: 12px;"><?php echo $item['received']; ?></td>
                        <td style="padding: 12px;"><?php echo $item['issued']; ?></td>
                        <td style="padding: 12px;"><strong><?php echo $item['closing_stock']; ?></strong></td>
                        <td style="padding: 12px;">
                            <?php if ($item['status'] == 'Low Stock'): ?>
                                <span style="background: #fef3c7; color: #856404; padding: 3px 8px; border-radius: 12px; font-size: 11px;">Low Stock</span>
                            <?php else: ?>
                                <span style="background: #d4edda; color: #155724; padding: 3px 8px; border-radius: 12px; font-size: 11px;">OK</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: #fafafa; font-weight: bold; border-top: 2px solid #e0e0e0;">
                        <td style="padding: 12px;">TOTAL</td>
                        <td style="padding: 12px;"></td>
                        <td style="padding: 12px; color: #28a745;"><?php echo number_format($total_received); ?></td>
                        <td style="padding: 12px; color: #dc3545;"><?php echo number_format($total_issued); ?></td>
                        <td style="padding: 12px;"></td>
                        <td style="padding: 12px;"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        
        <!-- Submit Report Button -->
        <?php if ($is_front_desk && !$has_report): ?>
        <div style="margin-top: 25px; text-align: center; padding-top: 20px; border-top: 1px solid #e0e0e0;">
            <a href="submit_report.php?category=<?php echo $selected_category_id; ?>&month=<?php echo $selected_month; ?>" 
               style="display: inline-block; background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%); color: white; padding: 12px 32px; border-radius: 8px; text-decoration: none; font-size: 15px; font-weight: 500; transition: all 0.3s; box-shadow: 0 2px 4px rgba(0,0,0,0.1);"
               onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(0,0,0,0.2)';"
               onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 2px 4px rgba(0,0,0,0.1)';">
                Submit Monthly Report
            </a>
        </div>
        <?php elseif ($is_front_desk && $has_report): ?>
        <div style="margin-top: 25px; text-align: center; padding-top: 20px; border-top: 1px solid #e0e0e0;">
            <span style="color: #28a745; background: #d4edda; padding: 10px 24px; border-radius: 8px; display: inline-block;">✓ Report already submitted for <?php echo date('F Y', strtotime($selected_month)); ?></span>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($selected_category_id > 0 && empty($items_data)): ?>
<div style="background: white; padding: 40px; text-align: center; color: #999; border-radius: 8px; border: 1px solid #e0e0e0;">
    <p>No items found in this category for <?php echo date('F Y', strtotime($selected_month)); ?>.</p>
    <a href="reports.php?month=<?php echo $selected_month; ?>" class="btn">Back to Categories</a>
</div>

<?php else: ?>
<!-- Overview - Categories Grid -->
<div style="margin-bottom: 15px;">
    <h3 style="margin-bottom: 10px;">Reports for <?php echo date('F Y', strtotime($selected_month . '-01')); ?></h3>
    <p style="color: #666; font-size: 14px;">Click on any category below to review stock movement for <?php echo date('F Y', strtotime($selected_month)); ?></p>
</div>

<div style="display: flex; justify-content: flex-end; margin-bottom: 20px;">
    <div style="display: flex; align-items: center; gap: 10px;">
        <label style="font-weight: 500; color: #555;">Select Month:</label>
        <input type="month" id="monthSelect" value="<?php echo $selected_month; ?>"
               onchange="window.location.href='?month='+this.value<?php if($selected_category_id>0) echo '&category_id='.$selected_category_id; ?>"
               style="padding: 6px 10px; border: 1px solid #ddd; border-radius: 5px;">
    </div>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">
    <?php foreach ($categories as $cat):
        $report = null;
        foreach ($all_reports as $r) {
            if ($r['category_id'] == $cat['id']) {
                $report = $r;
                break;
            }
        }
    ?>
    <div style="background: white; border-radius: 8px; cursor: pointer; border: 1px solid #e0e0e0; transition: all 0.3s;" 
         onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 8px rgba(0,0,0,0.1)';"
         onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='none';"
         onclick="window.location.href='?category_id=<?php echo $cat['id']; ?>&month=<?php echo $selected_month; ?>'">
        <div style="padding: 20px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                <h3 style="margin: 0;"><?php echo htmlspecialchars($cat['name']); ?></h3>
                <?php if ($report): ?>
                    <span style="background: #d4edda; color: #155724; padding: 3px 10px; border-radius: 12px; font-size: 11px;">Submitted</span>
                <?php else: ?>
                    <span style="background: #f5f5f5; color: #666; padding: 3px 10px; border-radius: 12px; font-size: 11px;">Pending</span>
                <?php endif; ?>
            </div>
            
            <?php if ($report): ?>
                <div style="display: flex; gap: 15px; font-size: 13px; color: #666; margin: 10px 0;">
                    <span><?php echo $report['total_items']; ?> items</span>
                    <span><?php echo number_format($report['total_units']); ?> units</span>
                    <?php if ($report['low_stock_count'] > 0): ?>
                        <span><?php echo $report['low_stock_count']; ?> low</span>
                    <?php endif; ?>
                </div>
                <div style="font-size: 11px; color: #999; padding-top: 10px; border-top: 1px solid #e0e0e0;">
                    By: <?php echo htmlspecialchars($report['submitted_by_name']); ?><br>
                    On: <?php echo date('d/m/Y', strtotime($report['submitted_at'])); ?>
                </div>
            <?php else: ?>
                <div style="color: #999; margin: 15px 0;">Click to view stock movement for <?php echo date('F', strtotime($selected_month)); ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if (empty($all_reports) && !$is_front_desk): ?>
<div style="background: white; padding: 40px; text-align: center; color: #999; border-radius: 8px; border: 1px solid #e0e0e0; margin-top: 20px;">
    <p>No reports available for <?php echo date('F Y', strtotime($selected_month . '-01')); ?>.</p>
    <p>Front desk staff need to submit monthly reports.</p>
</div>
<?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>