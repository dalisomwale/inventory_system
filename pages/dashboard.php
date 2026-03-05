<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();
if (isManager()) { header('Location: manager_dashboard.php'); exit(); }

// Get total items count only - NO inventory value for front desk
$total_items = $pdo->query("SELECT COUNT(*) as total_items FROM items")->fetchColumn();

// Get ALL items for reference
$all_items_count = $pdo->query("SELECT COUNT(*) FROM items")->fetchColumn();

// Low stock items - always fetch them
$low_stmt = $pdo->prepare("
    SELECT * FROM items 
    WHERE current_quantity <= reorder_level 
    ORDER BY 
        CASE 
            WHEN current_quantity = 0 THEN 1 
            WHEN current_quantity <= reorder_level/2 THEN 2 
            ELSE 3 
        END,
        current_quantity ASC
");
$low_stmt->execute();
$low_stock = $low_stmt->fetchAll();

// Also get items that are above reorder level for comparison
$healthy_count = $pdo->query("
    SELECT COUNT(*) FROM items 
    WHERE current_quantity > reorder_level
")->fetchColumn();

// Get critical items (out of stock or very low)
$critical_count = $pdo->query("
    SELECT COUNT(*) FROM items 
    WHERE current_quantity = 0 OR current_quantity <= reorder_level/2
")->fetchColumn();

// Recent transactions - EXCLUDING login/logout
$stmt = $pdo->prepare("
    (SELECT 'Received' as type, i.item_name, i.category, s.quantity, s.created_at, s.supplier
     FROM stock_receiving s JOIN items i ON s.item_id=i.id WHERE s.received_by=? ORDER BY s.created_at DESC LIMIT 5)
    UNION ALL
    (SELECT 'Issued' as type, i.item_name, i.category, s.quantity, s.created_at, s.issued_to as supplier
     FROM stock_issuing s JOIN items i ON s.item_id=i.id WHERE s.issued_by=? ORDER BY s.created_at DESC LIMIT 5)
    ORDER BY created_at DESC LIMIT 10
");
$stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
$recent = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<h1>Front Desk Dashboard</h1>

<div class="dashboard">
    <div class="card">
        <h3>Total Items</h3>
        <div class="stat"><?php echo $total_items ?: 0; ?></div>
        <div class="stat-label">Products in inventory</div>
    </div>
    <div class="card">
        <h3>Low Stock</h3>
        <div class="stat" style="color:<?php echo count($low_stock)>0?'var(--danger)':'var(--success)';?>"><?php echo count($low_stock); ?></div>
        <div class="stat-label">Items below reorder level</div>
    </div>
    <div class="card">
        <h3>Critical Items</h3>
        <div class="stat" style="color:<?php echo $critical_count>0?'var(--danger)':'var(--success)';?>"><?php echo $critical_count; ?></div>
        <div class="stat-label">Out of stock or very low</div>
    </div>
    <div class="card">
        <h3>Healthy Stock</h3>
        <div class="stat" style="color:var(--success);"><?php echo $healthy_count; ?></div>
        <div class="stat-label">Items above reorder level</div>
    </div>
</div>

<div class="action-group">
    <a href="receive_stock.php"  class="btn btn-success">&#8681; Receive Stock</a>
    <a href="issue_stock.php"    class="btn btn-primary">&#8682; Issue Stock</a>
    <a href="procurement.php"    class="btn btn-warning">&#128722; Request Procurement</a>
</div>

<!-- LOW STOCK SECTION - ALWAYS VISIBLE -->
<div class="card" style="border-left: 4px solid <?php echo count($low_stock)>0 ? 'var(--warning)' : 'var(--success)'; ?>">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h3 style="margin:0">
            <?php if (count($low_stock) > 0): ?>
                &#9888; Low Stock Alert (<?php echo count($low_stock); ?> item<?php echo count($low_stock) != 1 ? 's' : ''; ?> need attention)
            <?php else: ?>
                ✓ All Stock Levels Healthy
            <?php endif; ?>
        </h3>
        <?php if (count($low_stock) > 0): ?>
        <div>
            <a href="procurement.php" class="btn btn-sm btn-warning">Request Multiple Items</a>
        </div>
        <?php endif; ?>
    </div>
    
    <?php if (count($low_stock) > 0): ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Category</th>
                        <th>Current Stock</th>
                        <th>Reorder Level</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($low_stock as $item): ?>
                    <tr>
                        <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']); ?></td>
                        <td><span class="cat-pill <?php echo catClass($item['category']??'Other');?>"><?php echo htmlspecialchars($item['category']??'Other');?></span></td>
                        <td>
                            <strong><?php echo $item['current_quantity']; ?></strong>
                            <?php if ($item['current_quantity'] == 0): ?>
                                <span class="status-rejected" style="margin-left:8px;">OUT OF STOCK</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $item['reorder_level']; ?></td>
                        <td>
                            <?php 
                            $percentage = $item['reorder_level'] > 0 ? ($item['current_quantity'] / $item['reorder_level']) * 100 : 0;
                            if ($item['current_quantity'] == 0): ?>
                                <span class="status-rejected">Critical</span>
                            <?php elseif ($percentage <= 25): ?>
                                <span class="status-rejected">Very Low</span>
                            <?php elseif ($percentage <= 50): ?>
                                <span class="status-pending">Low</span>
                            <?php elseif ($percentage <= 75): ?>
                                <span class="status-warning">Getting Low</span>
                            <?php else: ?>
                                <span class="status-warning">Below Reorder</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap">
                            <a href="receive_stock.php?item=<?php echo $item['id']; ?>" class="btn btn-sm btn-success" title="Receive Stock">📥 Receive</a>
                            <a href="issue_stock.php?item=<?php echo $item['id']; ?>" class="btn btn-sm btn-primary" title="Issue Stock">📤 Issue</a>
                            <a href="procurement.php?item_name=<?php echo urlencode($item['item_name']); ?>&qty=<?php echo $item['reorder_level'] * 2; ?>" class="btn btn-sm btn-warning" title="Request Procurement">📝 Request</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <!-- This shows when NO low stock items exist -->
        <div style="text-align:center; padding:30px 20px; background:#f9fafb; border-radius:8px;">
            <div style="font-size:3rem; color:var(--success); margin-bottom:10px;">✓</div>
            <h3 style="color:var(--success); margin-bottom:10px;">All Stock Levels are Healthy</h3>
            <p style="color:var(--text2); margin-bottom:15px; max-width:400px; margin-left:auto; margin-right:auto;">
                Great job! All <?php echo $healthy_count; ?> items in your inventory are above their reorder levels.
            </p>
            <div style="display:flex; gap:10px; justify-content:center; flex-wrap:wrap;">
                <a href="receive_stock.php" class="btn btn-success">📥 Receive New Stock</a>
                <a href="issue_stock.php" class="btn btn-primary">📤 Issue Stock</a>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Summary stats at the bottom of the card -->
    <div style="margin-top:15px; display:flex; gap:15px; flex-wrap:wrap; border-top:1px solid var(--border); padding-top:15px;">
        <div style="font-size:.85rem;">
            <span style="color:var(--muted);">Total Items:</span> 
            <strong><?php echo $all_items_count; ?></strong>
        </div>
        <div style="font-size:.85rem;">
            <span style="color:var(--muted);">Healthy Stock:</span> 
            <strong style="color:var(--success);"><?php echo $healthy_count; ?></strong>
        </div>
        <?php if (count($low_stock) > 0): ?>
        <div style="font-size:.85rem;">
            <span style="color:var(--muted);">Need Attention:</span> 
            <strong style="color:var(--danger);"><?php echo count($low_stock); ?></strong>
        </div>
        <?php endif; ?>
        <?php if ($critical_count > 0): ?>
        <div style="font-size:.85rem;">
            <span style="color:var(--muted);">Critical:</span> 
            <strong style="color:var(--danger);"><?php echo $critical_count; ?></strong>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Transactions -->
<div class="card">
    <h3>Your Recent Transactions</h3>
    <?php if (!empty($recent)): ?>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Qty</th>
                    <th>Details</th>
                    <th>Date & Time</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $t): ?>
                <tr>
                    <td><span class="status-<?php echo strtolower($t['type']);?>"><?php echo $t['type'];?></span></td>
                    <td style="font-weight:500"><?php echo htmlspecialchars($t['item_name']);?></td>
                    <td><span class="cat-pill <?php echo catClass($t['category']??'Other');?>"><?php echo htmlspecialchars($t['category']??'Other');?></span></td>
                    <td><strong><?php echo $t['quantity'];?></strong></td>
                    <td style="max-width:200px; white-space:normal; color:var(--muted); font-size:.84rem;">
                        <?php echo $t['type'] === 'Received' ? 'From: ' . htmlspecialchars($t['supplier']??'') : 'To: ' . htmlspecialchars($t['supplier']??''); ?>
                    </td>
                    <td style="white-space:nowrap"><?php echo date('d/m/Y H:i', strtotime($t['created_at']));?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div style="text-align:center; padding:30px; color:var(--muted);">
        <p>No transactions yet. Start by receiving or issuing stock.</p>
    </div>
    <?php endif; ?>
    <div style="margin-top:15px; text-align:right">
        <a href="view_transactions.php" class="btn btn-sm">View All Transactions →</a>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>