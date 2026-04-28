<?php
require_once __DIR__ . '/../config/database.php';
redirectIfNotLoggedIn();

$total_items = $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();
$low_stock = $pdo->query("SELECT COUNT(*) FROM items WHERE current_quantity <= reorder_level")->fetchColumn();
$out_of_stock = $pdo->query("SELECT COUNT(*) FROM items WHERE current_quantity = 0")->fetchColumn();
$pending = $pdo->query("SELECT COUNT(*) FROM procurement WHERE status = 'pending' AND requested_by = " . ($_SESSION['user_id'] ?? 0))->fetchColumn();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Inventory Management System - Dashboard</h1>
    <div class="date-badge"><?php echo date('l, d F Y'); ?></div>
</div>

<div class="stats-grid">
    <div class="stat-card">       
        <div class="stat-value"><?php echo $total_items; ?></div>
        <div class="stat-label">Total Stock Items</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" style="color: #f59e0b"><?php echo $low_stock; ?></div>
        <div class="stat-label">Low Stock Alerts</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" style="color: #dc2626"><?php echo $out_of_stock; ?></div>
        <div class="stat-label">Out of Stock Items</div>
    </div>
    <div class="stat-card">
        <div class="stat-value" style="color: #f59e0b"><?php echo $pending; ?></div>
        <div class="stat-label">Pending My Requests</div>
    </div>
</div>

<div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 24px;">
    <div class="card">
        <div class="card-header">
            <h3>Quick Actions</h3>
        </div>
        <div class="card-body">
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <a href="receive_stock.php" style="display: inline-block; padding: 10px 16px; background: #10b981; color: white; text-decoration: none; border-radius: 8px; text-align: center;">Receive Stock</a>
                <a href="issue_stock.php" style="display: inline-block; padding: 10px 16px; background: #2563eb; color: white; text-decoration: none; border-radius: 8px; text-align: center;">Issue Stock</a>
                <a href="procurement.php" style="display: inline-block; padding: 10px 16px; background: #f59e0b; color: white; text-decoration: none; border-radius: 8px; text-align: center;">Request Procurement</a>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-header">
            <h3>Welcome</h3>
        </div>
        <div class="card-body">
            <p>Welcome back, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?>!</p>
            <p style="font-size: 13px; color: #64748b; margin-top: 12px;">Proflight Zambia Service Center Inventory System</p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
