<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();
if (!isManager()) { header('Location: dashboard.php'); exit(); }

$selected_month = $_GET['month'] ?? date('Y-m');
$selected_category_id = (int)($_GET['category_id'] ?? 0);

$categories = $pdo->query("SELECT id, name, description FROM categories ORDER BY name")->fetchAll();

$reports = $pdo->prepare("SELECT r.*,c.name as category_name,u.full_name as submitted_by_name FROM category_monthly_reports r JOIN categories c ON r.category_id=c.id JOIN users u ON r.submitted_by=u.id WHERE DATE_FORMAT(r.report_month,'%Y-%m')=? ORDER BY c.name");
$reports->execute([$selected_month]);
$all_reports = $reports->fetchAll();

$selected_report = null;
$items_data = [];
$total_value = 0;

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
            $item['unit_price'] = $db ? $db['unit_price'] : 0;
            $item['total_value'] = $item['closing_stock'] * $item['unit_price'];
        }
        $total_value = array_sum(array_column($items_data, 'total_value'));
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
    <h1>Category Reports</h1>
    <div style="display: flex; gap: 12px;">
        <a href="reports.php" class="btn btn-info" style="background: #17a2b8;">Full Reports</a>
        <a href="manager_dashboard.php" class="btn">Dashboard</a>
    </div>
</div>

<!-- Month Selector -->
<div style="background: white; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #e0e0e0;">
    <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
        <label style="font-weight: 500;">Select Month:</label>
        <input type="month" id="monthSelect" value="<?php echo $selected_month; ?>"
               onchange="window.location.href='?month='+this.value<?php if($selected_category_id>0) echo '&category_id='.$selected_category_id; ?>"
               style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px;">
    </div>
</div>

<?php if ($selected_category_id > 0 && $selected_report): ?>
<div style="margin-bottom: 15px;">
    <a href="view_category_reports.php?month=<?php echo $selected_month; ?>" style="color: #007bff; text-decoration: none;">← Back to All Categories</a>
</div>

<div style="background: white; border-radius: 8px; overflow: hidden; border: 1px solid #e0e0e0;">
    <div style="padding: 20px; border-bottom: 1px solid #e0e0e0; background: #fafafa;">
        <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 10px;">
            <div>
                <h2 style="margin: 0 0 4px;"><?php echo htmlspecialchars($selected_report['category_name']); ?></h2>
                <div style="color: #666; font-size: 14px;">
                    <?php echo date('F Y', strtotime($selected_report['report_month'])); ?>
                    — Submitted by <?php echo htmlspecialchars($selected_report['submitted_by_name']); ?>
                    on <?php echo date('d/m/Y H:i', strtotime($selected_report['submitted_at'])); ?>
                </div>
            </div>
            <div style="display: flex; gap: 8px;">
                <button onclick="window.print()" class="btn btn-sm" style="background: #17a2b8;">Print</button>
                <a href="reports.php?category_id=<?php echo $selected_category_id; ?>&start_date=<?php echo $selected_month.'-01'; ?>&end_date=<?php echo date('Y-m-t', strtotime($selected_month.'-01')); ?>" class="btn btn-sm btn-primary" style="background: #007bff;">Detailed Activity</a>
            </div>
        </div>
    </div>
    <div style="padding: 20px;">
        <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 25px;">
            <div style="background: #f5f5f5; padding: 15px; text-align: center; border-radius: 8px;">
                <div style="font-size: 28px; font-weight: bold;"><?php echo $selected_report['total_items']; ?></div>
                <div style="color: #666;">Items</div>
            </div>
            <div style="background: #f5f5f5; padding: 15px; text-align: center; border-radius: 8px;">
                <div style="font-size: 28px; font-weight: bold;"><?php echo number_format($selected_report['total_units']); ?></div>
                <div style="color: #666;">Total Units</div>
            </div>
            <div style="background: #f5f5f5; padding: 15px; text-align: center; border-radius: 8px;">
                <div style="font-size: 28px; font-weight: bold;">K<?php echo number_format($total_value, 2); ?></div>
                <div style="color: #666;">Total Value</div>
            </div>
            <div style="background: #f5f5f5; padding: 15px; text-align: center; border-radius: 8px;">
                <div style="font-size: 28px; font-weight: bold; color: <?php echo $selected_report['low_stock_count'] > 0 ? '#dc3545' : '#28a745'; ?>">
                    <?php echo $selected_report['low_stock_count']; ?>
                </div>
                <div style="color: #666;">Low Stock</div>
            </div>
        </div>

        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead>
                    <tr style="background: #fafafa; border-bottom: 2px solid #e0e0e0;">
                        <th style="padding: 12px; text-align: left;">Item</th>
                        <th style="padding: 12px; text-align: left;">Opening</th>
                        <th style="padding: 12px; text-align: left;">Closing</th>
                        <th style="padding: 12px; text-align: left;">Unit Price</th>
                        <th style="padding: 12px; text-align: left;">Total Value</th>
                        <th style="padding: 12px; text-align: left;">Reorder</th>
                        <th style="padding: 12px; text-align: left;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items_data as $item): ?>
                    <tr style="border-bottom: 1px solid #e0e0e0;">
                        <td style="padding: 12px; font-weight: 500;"><?php echo htmlspecialchars($item['item_name']); ?></td>
                        <td style="padding: 12px; color: #666;"><?php echo $item['opening_stock']; ?><td>
                        <td style="padding: 12px;"><strong><?php echo $item['closing_stock']; ?></strong></td>
                        <td style="padding: 12px; font-family: monospace;">K<?php echo number_format($item['unit_price'], 2); ?></td>
                        <td style="padding: 12px; font-family: monospace;">K<?php echo number_format($item['total_value'], 2); ?></td>
                        <td style="padding: 12px;"><?php echo $item['reorder_level']; ?></td>
                        <td style="padding: 12px;">
                            <?php if ($item['status'] == 'Low Stock'): ?>
                                <span style="background: #fef3c7; color: #856404; padding: 3px 10px; border-radius: 12px; font-size: 11px;">Low Stock</span>
                            <?php else: ?>
                                <span style="background: #d4edda; color: #155724; padding: 3px 10px; border-radius: 12px; font-size: 11px;">OK</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background: #fafafa; font-weight: bold; border-top: 2px solid #e0e0e0;">
                        <td colspan="4" style="padding: 12px; text-align: right;">Total Value</td>
                        <td style="padding: 12px; font-family: monospace;">K<?php echo number_format($total_value, 2); ?></td>
                        <td colspan="2" style="padding: 12px;"></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <?php if ($selected_report['notes']): ?>
        <div style="margin-top: 20px; padding: 15px; background: #e7f3ff; border-radius: 8px;">
            <strong>Notes from Reception:</strong><br>
            <?php echo nl2br(htmlspecialchars($selected_report['notes'])); ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- Grid View -->
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
                        <span style="color: #dc3545;"><?php echo $report['low_stock_count']; ?> low</span>
                    <?php endif; ?>
                </div>
                <div style="font-size: 11px; color: #999; padding-top: 10px; border-top: 1px solid #e0e0e0;">
                    <?php echo date('d/m/Y', strtotime($report['submitted_at'])); ?>
                </div>
            <?php else: ?>
                <div style="color: #999; margin: 15px 0;">Not yet submitted</div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php if (count($all_reports) > 0): ?>
<div style="background: white; border-radius: 8px; overflow: hidden; border: 1px solid #e0e0e0; margin-top: 20px;">
    <div style="padding: 16px 20px; border-bottom: 1px solid #e0e0e0; background: #fafafa;">
        <h3 style="margin: 0;">Summary — <?php echo date('F Y', strtotime($selected_month.'-01')); ?></h3>
    </div>
    <div style="padding: 20px; overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #fafafa; border-bottom: 2px solid #e0e0e0;">
                    <th style="padding: 12px; text-align: left;">Category</th>
                    <th style="padding: 12px; text-align: left;">Items</th>
                    <th style="padding: 12px; text-align: left;">Units</th>
                    <th style="padding: 12px; text-align: left;">Low Stock</th>
                    <th style="padding: 12px; text-align: left;">Submitted By</th>
                    <th style="padding: 12px; text-align: left;">Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_reports as $r): ?>
                <tr style="cursor: pointer; border-bottom: 1px solid #e0e0e0;" onclick="window.location.href='?category_id=<?php echo $r['category_id']; ?>&month=<?php echo $selected_month; ?>'">
                    <td style="padding: 12px; font-weight: 500;"><?php echo htmlspecialchars($r['category_name']); ?></td>
                    <td style="padding: 12px;"><?php echo $r['total_items']; ?></td>
                    <td style="padding: 12px;"><?php echo number_format($r['total_units']); ?></td>
                    <td style="padding: 12px;">
                        <?php if ($r['low_stock_count'] > 0): ?>
                            <span style="color: #dc3545;"><?php echo $r['low_stock_count']; ?> items</span>
                        <?php else: ?>
                            <span style="color: #28a745;">None</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 12px; color: #666;"><?php echo htmlspecialchars($r['submitted_by_name']); ?></td>
                    <td style="padding: 12px; color: #666; font-size: 12px;"><?php echo date('d/m/Y H:i', strtotime($r['submitted_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background: #fafafa; font-weight: bold;">
                    <td style="padding: 12px;">Total</td>
                    <td style="padding: 12px;"><?php echo array_sum(array_column($all_reports, 'total_items')); ?></td>
                    <td style="padding: 12px;"><?php echo number_format(array_sum(array_column($all_reports, 'total_units'))); ?></td>
                    <td colspan="3" style="padding: 12px;"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (count($all_reports) == 0): ?>
<div style="background: white; padding: 60px; text-align: center; color: #999; border-radius: 8px; border: 1px solid #e0e0e0; margin-top: 20px;">
    <p>No reports submitted for <?php echo date('F Y', strtotime($selected_month.'-01')); ?> yet.</p>
</div>
<?php endif; ?>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
