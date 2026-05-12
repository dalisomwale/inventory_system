<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

// Redirect if not manager
if (!isManager()) {
    header('Location: dashboard.php');
    exit();
}

$stmt = $pdo->query("SELECT COUNT(*) as total FROM items");
$total_items = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) as total FROM items WHERE current_quantity <= reorder_level AND current_quantity > 0");
$low_stock = $stmt->fetchColumn();

$stmt = $pdo->query("SELECT COUNT(*) as total FROM procurement WHERE status = 'pending'");
$pending_requests = $stmt->fetchColumn();

// Get categories count
$stmt = $pdo->query("SELECT COUNT(*) as total FROM categories");
$total_categories = $stmt->fetchColumn();

// Get submitted reports count for current month
$current_month = date('Y-m');
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM category_monthly_reports WHERE DATE_FORMAT(report_month, '%Y-%m') = ?");
$stmt->execute([$current_month]);
$submitted_reports = $stmt->fetchColumn();

$progress_percentage = ($submitted_reports / $total_categories) * 100;

// Get pending procurement requests
$stmt = $pdo->prepare("
    SELECT p.*, u.full_name as requester_name 
    FROM procurement p 
    JOIN users u ON p.requested_by = u.id 
    WHERE p.status = 'pending' 
    ORDER BY p.created_at DESC 
    LIMIT 5
");
$stmt->execute();
$pending_procurements = $stmt->fetchAll();

// Get categories for progress
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1>Service Center Inventory Management Dashboard</h1>
    <div style="color: #666; font-size: 14px;">
        <?php echo date('l, d F Y'); ?>
    </div>
</div>

<!-- Stats Grid -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
    <div class="card" style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0;">
        <div style="color: #666; font-size: 14px; margin-bottom: 5px;">Total Items</div>
        <div style="font-size: 32px; font-weight: bold;"><?php echo $total_items; ?></div>
    </div>
    <div class="card" style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0;">
        <div style="color: #666; font-size: 14px; margin-bottom: 5px;">Low Stock Items</div>
        <div style="font-size: 32px; font-weight: bold; color: <?php echo $low_stock > 0 ? '#dc3545' : '#28a745'; ?>"><?php echo $low_stock; ?></div>
    </div>
    <div class="card" style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0;">
        <div style="color: #666; font-size: 14px; margin-bottom: 5px;">Pending Requests</div>
        <div style="font-size: 32px; font-weight: bold;"><?php echo $pending_requests; ?></div>
    </div>
    <div class="card" style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0;">
        <div style="color: #666; font-size: 14px; margin-bottom: 5px;">Reports Submitted</div>
        <div style="font-size: 32px; font-weight: bold;"><?php echo $submitted_reports; ?>/<?php echo $total_categories; ?></div>
    </div>
</div>

<!-- Quick Actions (Left) + Report Submission Progress (Right) - Side by Side -->
<div style="display: flex; gap: 20px; margin-bottom: 30px; flex-wrap: wrap;">
    <!-- Quick Actions - Left Side -->
    <div class="card" style="background: white; border-radius: 8px; border: 1px solid #e0e0e0; flex: 2; min-width: 300px;">
        <div style="padding: 15px 20px; border-bottom: 1px solid #e0e0e0;">
            <h3 style="margin: 0;">Quick Actions</h3>
        </div>
        <div style="padding: 20px; display: flex; gap: 15px; flex-wrap: wrap;">
            <a href="manage_items.php" class="btn" style="background: #007bff; color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-size: 14px; font-weight: 500;">Manage Inventory</a>
            <a href="procurement.php" class="btn" style="background: #ffc107; color: #333; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-size: 14px; font-weight: 500;">Manage Procurements</a>
            <a href="reports.php" class="btn" style="background: #17a2b8; color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-size: 14px; font-weight: 500;">Monthly Reports</a>
        </div>
    </div>
    
    <!-- Report Submission Progress - Right Side with Progress Bar -->
    <div class="card" style="background: white; border-radius: 8px; border: 1px solid #e0e0e0; flex: 1; min-width: 280px;">
        <div style="padding: 12px 16px; border-bottom: 1px solid #e0e0e0;">
            <h3 style="margin: 0; font-size: 14px;">Report Submission Progress</h3>
        </div>
        <div style="padding: 12px 16px;">
            <!-- Progress Bar -->
            <div style="margin-bottom: 12px;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px;">
                    <span style="font-size: 11px; color: #666;">Progress</span>
                    <span style="font-size: 12px; font-weight: 500; color: #28a745;"><?php echo round($progress_percentage); ?>%</span>
                </div>
                <div style="background: #e9ecef; border-radius: 4px; height: 4px; overflow: hidden;">
                    <div style="background: #28a745; width: <?php echo $progress_percentage; ?>%; height: 100%;"></div>
                </div>
                <div style="margin-top: 6px;">
                    <span style="font-size: 11px; color: #666;"><?php echo $submitted_reports; ?> of <?php echo $total_categories; ?> categories submitted</span>
                </div>
            </div>
            <!-- Category Status -->
            <div style="display: flex; flex-wrap: wrap; gap: 10px; padding-top: 8px; border-top: 1px solid #e0e0e0;">
                <?php foreach ($categories as $cat):
                    $stmt = $pdo->prepare("SELECT id FROM category_monthly_reports WHERE category_id = ? AND DATE_FORMAT(report_month, '%Y-%m') = ?");
                    $stmt->execute([$cat['id'], $current_month]);
                    $submitted = $stmt->fetch();
                ?>
                <div style="display: inline-flex; align-items: center; gap: 5px;">
                    <?php if ($submitted): ?>
                        <span style="color: #28a745; font-size: 11px;">✓</span>
                    <?php else: ?>
                        <span style="color: #dc3545; font-size: 11px;">○</span>
                    <?php endif; ?>
                    <span style="color: #555; font-size: 11px;"><?php echo htmlspecialchars($cat['name']); ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Pending Procurement Requests -->
<?php if (count($pending_procurements) > 0): ?>
<div class="card" style="background: white; border-radius: 8px; border: 1px solid #e0e0e0;">
    <div style="padding: 15px 20px; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center;">
        <h3 style="margin: 0;">Pending Procurement Requests (<?php echo count($pending_procurements); ?>)</h3>
        <a href="procurement.php" class="btn btn-sm" style="font-size: 12px; background: #6c757d; color: white; padding: 5px 10px; border-radius: 3px; text-decoration: none;">View All</a>
    </div>
    <div style="padding: 20px; overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f8f9fa; border-bottom: 2px solid #dee2e6;">
                    <th style="padding: 12px; text-align: left;">Item</th>
                    <th style="padding: 12px; text-align: left;">Qty</th>
                    <th style="padding: 12px; text-align: left;">Requested By</th>
                    <th style="padding: 12px; text-align: left;">Date</th>
                    <th style="padding: 12px; text-align: left;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pending_procurements as $req): ?>
                <tr style="border-bottom: 1px solid #dee2e6;">
                    <td style="padding: 12px;"><?php echo htmlspecialchars($req['item_name']); ?></td>
                    <td style="padding: 12px;"><?php echo $req['quantity']; ?></td>
                    <td style="padding: 12px;"><?php echo htmlspecialchars($req['requester_name']); ?></td>
                    <td style="padding: 12px;"><?php echo date('d/m/Y', strtotime($req['created_at'])); ?></td>
                    <td style="padding: 12px;">
                        <a href="procurement.php?action=approve&id=<?php echo $req['id']; ?>" class="btn btn-sm" style="background: #28a745; color: white; padding: 5px 10px; border-radius: 3px; text-decoration: none; font-size: 11px; margin-right: 5px;" onclick="return confirm('Approve this request?')">Approve</a>
                        <a href="procurement.php?action=reject&id=<?php echo $req['id']; ?>" class="btn btn-sm" style="background: #dc3545; color: white; padding: 5px 10px; border-radius: 3px; text-decoration: none; font-size: 11px;" onclick="return confirm('Reject this request?')">Reject</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>