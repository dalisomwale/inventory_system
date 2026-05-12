<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

// If user is manager, redirect to manager dashboard
if (isManager()) {
    header('Location: manager_dashboard.php');
    exit();
}

$user_role = $_SESSION['role'];
$is_front_desk = ($user_role == 'front_desk');

// Get statistics
$stmt = $pdo->query("SELECT COUNT(*) as total_items, SUM(current_quantity * unit_price) as total_value FROM items");
$summary = $stmt->fetch();

// Get low stock items
$stmt = $pdo->prepare("SELECT * FROM items WHERE current_quantity <= reorder_level AND current_quantity > 0 ORDER BY current_quantity ASC LIMIT 10");
$stmt->execute();
$low_stock = $stmt->fetchAll();

// Get out of stock items
$stmt = $pdo->prepare("SELECT * FROM items WHERE current_quantity = 0 ORDER BY item_name LIMIT 10");
$stmt->execute();
$out_of_stock = $stmt->fetchAll();

// Get user's pending requests
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM procurement WHERE requested_by = ? AND status = 'pending'");
$stmt->execute([$_SESSION['user_id']]);
$my_pending_requests = $stmt->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
    <h1>Front Desk Dashboard</h1>
    <div style="color: #666; font-size: 14px;">
        <?php echo date('l, d F Y'); ?>
    </div>
</div>

<!-- Stats Grid -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 30px;">
    <div class="card" style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0;">
        <div style="color: #666; font-size: 14px;">Total Stock Items</div>
        <div style="font-size: 32px; font-weight: bold;"><?php echo $summary['total_items'] ?? 0; ?></div>
    </div>
    <div class="card" style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0;">
        <div style="color: #666; font-size: 14px;">Low Stock Alerts</div>
        <div style="font-size: 32px; font-weight: bold; color: <?php echo count($low_stock) > 0 ? '#dc3545' : '#28a745'; ?>">
            <?php echo count($low_stock); ?>
        </div>
    </div>
    <div class="card" style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0;">
        <div style="color: #666; font-size: 14px;">Out of Stock Items</div>
        <div style="font-size: 32px; font-weight: bold; color: <?php echo count($out_of_stock) > 0 ? '#dc3545' : '#28a745'; ?>">
            <?php echo count($out_of_stock); ?>
        </div>
    </div>
    <div class="card" style="background: white; padding: 20px; border-radius: 8px; border: 1px solid #e0e0e0;">
        <div style="color: #666; font-size: 14px;">Pending My Requests</div>
        <div style="font-size: 32px; font-weight: bold;"><?php echo $my_pending_requests; ?></div>
    </div>
</div>

<!-- Quick Actions (Left) + Welcome Message (Right) - Side by Side -->
<div style="display: flex; gap: 20px; margin-bottom: 30px; flex-wrap: wrap;">
    <!-- Quick Actions - Left Side -->
    <div class="card" style="background: white; border-radius: 8px; border: 1px solid #e0e0e0; flex: 2; min-width: 300px;">
        <div style="padding: 15px 20px; border-bottom: 1px solid #e0e0e0;">
            <h3 style="margin: 0;">Quick Actions</h3>
        </div>
        <div style="padding: 20px; display: flex; gap: 15px; flex-wrap: wrap;">
            <a href="receive_stock.php" class="btn" style="background: #28a745; color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-size: 14px; font-weight: 500;">Receive Stock</a>
            <a href="issue_stock.php" class="btn" style="background: #007bff; color: white; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-size: 14px; font-weight: 500;">Issue Out Items</a>
            <a href="procurement.php" class="btn" style="background: #ffc107; color: #000000; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-size: 14px; font-weight: 500;">Request Procurements</a>
            <a href="inventory.php" class="btn" style="background: #048b26; color: #ffffff; padding: 12px 24px; border-radius: 8px; text-decoration: none; font-size: 14px; font-weight: 500;">View Stock</a>
        </div>
    </div>
    
    <!-- Welcome Message - Right Side (keep original style) -->
    <div class="card" style="background: white; border-radius: 8px; border: 1px solid #e0e0e0; flex: 1; min-width: 250px; display: flex; align-items: left; justify-content: left;">
        <div style="padding: 20px; text-align: left;">
            <div style="color: #555;">Welcome</div>
            <div style="font-size: 18px; font-weight: 600;">Welcome back, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!</div>
            <div style="color: #666; font-size: 12px; margin-top: 5px;">Proflight Zambia Service Center Inventory System</div>
        </div>
    </div>
</div>

<!-- Recent Activity -->
<div style="background: white; border-radius: 8px; border: 1px solid #e0e0e0;">
    <div style="padding: 15px 20px; border-bottom: 1px solid #e0e0e0;">
        <h3 style="margin: 0;">Recent Activity</h3>
    </div>
    <div style="padding: 20px; overflow-x: auto;">
        <?php
        $recent = $pdo->prepare("
            (SELECT 'Received' as type, i.item_name, r.quantity, r.created_at 
             FROM stock_receiving r JOIN items i ON r.item_id = i.id 
             WHERE r.received_by = ? ORDER BY r.created_at DESC LIMIT 10)
            UNION ALL
            (SELECT 'Issued' as type, i.item_name, s.quantity, s.created_at 
             FROM stock_issuing s JOIN items i ON s.item_id = i.id 
             WHERE s.issued_by = ? ORDER BY s.created_at DESC LIMIT 10)
            ORDER BY created_at DESC LIMIT 15
        ");
        $recent->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
        $recent_activity = $recent->fetchAll();
        ?>
        
        <?php if (count($recent_activity) > 0): ?>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #fafafa; border-bottom: 2px solid #e0e0e0;">
                    <th style="padding: 12px; text-align: left;">Type</th>
                    <th style="padding: 12px; text-align: left;">Item</th>
                    <th style="padding: 12px; text-align: left;">Quantity</th>
                    <th style="padding: 12px; text-align: left;">Date/Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_activity as $activity): ?>
                <tr style="border-bottom: 1px solid #e0e0e0;">
                    <td style="padding: 12px;">
                        <?php if ($activity['type'] == 'Received'): ?>
                            <span style="background: #d4edda; color: #155724; padding: 3px 10px; border-radius: 12px; font-size: 11px;">Received</span>
                        <?php else: ?>
                            <span style="background: #f8d7da; color: #721c24; padding: 3px 10px; border-radius: 12px; font-size: 11px;">Issued</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 12px;"><?php echo htmlspecialchars($activity['item_name']); ?></td>
                    <td style="padding: 12px;"><?php echo $activity['quantity']; ?></td>
                    <td style="padding: 12px; color: #666; font-size: 12px;"><?php echo date('d/m/Y H:i', strtotime($activity['created_at'])); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div style="text-align: center; padding: 40px; color: #999;">
            No recent activity found.
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>