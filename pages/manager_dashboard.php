<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();
if (!isManager()) { header('Location: dashboard.php'); exit(); }

$stats = $pdo->query("
    SELECT COUNT(*) as total_items, SUM(current_quantity) as total_quantity,
           SUM(current_quantity * unit_price) as total_value,
           COUNT(CASE WHEN current_quantity <= reorder_level THEN 1 END) as low_stock_count
    FROM items
")->fetch();

$pending_stmt = $pdo->prepare("SELECT p.*, u.full_name as requester FROM procurement p JOIN users u ON p.requested_by=u.id WHERE p.status='pending' ORDER BY p.created_at DESC");
$pending_stmt->execute();
$pending = $pending_stmt->fetchAll();

// Low stock items - always show all low stock items
$low_stock = $pdo->query("
    SELECT * FROM items 
    WHERE current_quantity <= reorder_level 
    ORDER BY 
        CASE 
            WHEN current_quantity = 0 THEN 1 
            WHEN current_quantity <= reorder_level/2 THEN 2 
            ELSE 3 
        END,
        current_quantity ASC
")->fetchAll();

// Recent activity - EXCLUDING login/logout actions
$recent = $pdo->query("
    SELECT a.*, u.full_name, i.item_name
    FROM audit_trail a 
    LEFT JOIN users u ON a.user_id=u.id 
    LEFT JOIN items i ON a.item_id=i.id
    WHERE a.action NOT IN ('LOGIN', 'LOGOUT')
    ORDER BY a.created_at DESC 
    LIMIT 20
")->fetchAll();

// Category summary
$cat_summary = $pdo->query("
    SELECT category,
           COUNT(*) as item_count,
           SUM(current_quantity) as total_quantity,
           SUM(current_quantity * unit_price) as cat_value,
           COUNT(CASE WHEN current_quantity <= reorder_level THEN 1 END) as low_stock_in_cat
    FROM items 
    GROUP BY category 
    ORDER BY cat_value DESC
")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<h1>Manager Dashboard</h1>

<div class="dashboard">
    <div class="card">
        <h3>Total Items</h3>
        <div class="stat"><?php echo $stats['total_items']??0;?></div>
        <div class="stat-label">Products in inventory</div>
    </div>
    <div class="card">
        <h3>Stock Value</h3>
        <div class="stat">K<?php echo number_format($stats['total_value']??0,2);?></div>
        <div class="stat-label">Current inventory value</div>
    </div>
    <div class="card">
        <h3>Low Stock</h3>
        <div class="stat" style="color:<?php echo ($stats['low_stock_count']??0)>0?'var(--danger)':'var(--success)';?>"><?php echo $stats['low_stock_count']??0;?></div>
        <div class="stat-label">Need reordering</div>
    </div>
    <div class="card">
        <h3>Pending Requests</h3>
        <div class="stat"><?php echo count($pending);?></div>
        <div class="stat-label">Awaiting approval</div>
    </div>
</div>

<div class="action-group">
    <a href="manage_items.php"      class="btn btn-primary">&#128230; Manage Items</a>
    <a href="view_transactions.php" class="btn btn-info">&#128202; Transactions</a>
    <a href="reports.php"           class="btn btn-success">&#128200; Reports</a>
    <a href="adjust_stock.php"      class="btn btn-warning">&#9878; Adjust Stock</a>
</div>

<!-- CATEGORY SUMMARY -->
<?php if (!empty($cat_summary)): ?>
<div class="card">
    <h3>Stock by Category</h3>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Items</th>
                    <th>Total Qty</th>
                    <th>Total Value</th>
                    <th>Low Stock</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cat_summary as $c): ?>
                <tr>
                    <td><span class="cat-pill <?php echo catClass($c['category']);?>"><?php echo htmlspecialchars($c['category']);?></span></td>
                    <td><?php echo $c['item_count'];?></td>
                    <td><?php echo $c['total_quantity'];?></td>
                    <td>K<?php echo number_format($c['cat_value'],2);?></td>
                    <td>
                        <?php if ($c['low_stock_in_cat'] > 0): ?>
                        <span class="status-pending"><?php echo $c['low_stock_in_cat']; ?> items</span>
                        <?php else: ?>
                        <span class="status-receiving">OK</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (count($pending)>0): ?>
<div class="card">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h3 style="margin:0">Pending Procurement Requests</h3>
        <a href="procurement.php" class="btn btn-sm btn-primary">View All Requests</a>
    </div>
    <div class="table-container">
        <table>
            <thead><tr><th>Item</th><th>Qty</th><th>Reason</th><th>Requested By</th><th>Date</th><th>Actions</th></tr></thead>
            <tbody>
                <?php foreach ($pending as $r): ?>
                <tr>
                    <td style="font-weight:500"><?php echo htmlspecialchars($r['item_name']);?></td>
                    <td><?php echo $r['quantity'];?></td>
                    <td style="max-width:250px; white-space:normal"><?php echo htmlspecialchars($r['reason']);?></td>
                    <td><?php echo htmlspecialchars($r['requester']);?></td>
                    <td><?php echo date('d/m/Y', strtotime($r['created_at']));?></td>
                    <td style="white-space:nowrap">
                        <a href="procurement.php?action=approve&id=<?php echo $r['id'];?>" class="btn btn-sm btn-success" onclick="return confirm('Approve this request?')">Approve</a>
                        <a href="procurement.php?action=reject&id=<?php echo $r['id'];?>"  class="btn btn-sm btn-danger"  onclick="return confirm('Reject this request?')">Reject</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- LOW STOCK SECTION - Always visible -->
<div class="card" style="border-left: 4px solid <?php echo count($low_stock)>0 ? 'var(--warning)' : 'var(--success)'; ?>">
    <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
        <h3 style="margin:0">
            <?php if (count($low_stock)>0): ?>
            &#9888; Low Stock Items (<?php echo count($low_stock); ?> items need attention)
            <?php else: ?>
            ✓ All Items Stocked OK
            <?php endif; ?>
        </h3>
        <?php if (count($low_stock)>0): ?>
        <div>
            <a href="reports.php?report=low_stock" class="btn btn-sm btn-warning">View Report</a>
            <a href="procurement.php" class="btn btn-sm btn-primary">Request Multiple</a>
        </div>
        <?php endif; ?>
    </div>
    
    <?php if (count($low_stock)>0): ?>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Current Stock</th>
                    <th>Reorder Level</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($low_stock as $item): ?>
                <tr>
                    <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']);?></td>
                    <td><span class="cat-pill <?php echo catClass($item['category']??'Other');?>"><?php echo htmlspecialchars($item['category']??'Other');?></span></td>
                    <td>
                        <?php echo $item['current_quantity']; ?>
                        <?php if ($item['current_quantity'] == 0): ?>
                        <span class="status-rejected" style="margin-left:5px;">Out of Stock</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $item['reorder_level']; ?></td>
                    <td>
                        <?php 
                        $percentage = ($item['current_quantity'] / $item['reorder_level']) * 100;
                        if ($item['current_quantity'] == 0): ?>
                            <span class="status-rejected">Critical</span>
                        <?php elseif ($percentage <= 50): ?>
                            <span class="status-pending">Low</span>
                        <?php else: ?>
                            <span class="status-warning">Below Reorder</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="procurement.php?item_name=<?php echo urlencode($item['item_name']); ?>&qty=<?php echo $item['reorder_level']*2; ?>" class="btn btn-sm btn-warning">Request</a>
                        <a href="receive_stock.php?item=<?php echo $item['id']; ?>" class="btn btn-sm btn-success">Receive</a>
                        <a href="manage_items.php?edit=<?php echo $item['id'];?>" class="btn btn-sm">Edit</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div style="text-align:center; padding:30px; color:var(--muted); background:#f9fafb; border-radius:8px;">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" style="margin-bottom:10px; opacity:0.5">
            <circle cx="12" cy="12" r="10"/><path d="M8 12h8"/><line x1="12" y1="8" x2="12" y2="16"/>
        </svg>
        <p>All items are above their reorder levels. Good job!</p>
    </div>
    <?php endif; ?>
</div>

<!-- RECENT ACTIVITY - Excluding login/logout -->
<div class="card">
    <h3>Recent Activity (Stock Movements Only)</h3>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Time</th>
                    <th>User</th>
                    <th>Action</th>
                    <th>Item</th>
                    <th>Qty</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $a): ?>
                <tr>
                    <td style="white-space:nowrap"><?php echo date('d/m/Y H:i', strtotime($a['created_at']));?></td>
                    <td><?php echo htmlspecialchars($a['full_name']??'System');?></td>
                    <td>
                        <?php 
                        $action_class = '';
                        if (strpos($a['action'], 'RECEIVE') !== false) $action_class = 'status-receiving';
                        elseif (strpos($a['action'], 'ISSUE') !== false) $action_class = 'status-issuing';
                        elseif (strpos($a['action'], 'ADJUST') !== false) $action_class = 'status-warning';
                        elseif (strpos($a['action'], 'ADD') !== false) $action_class = 'status-success';
                        else $action_class = 'status-pending';
                        ?>
                        <span class="<?php echo $action_class; ?>" style="padding:2px 8px;"><?php echo htmlspecialchars($a['action']);?></span>
                    </td>
                    <td><?php echo htmlspecialchars($a['item_name']??'—');?></td>
                    <td><?php echo $a['quantity'] ?: '—';?></td>
                    <td style="font-size:.84rem;color:var(--muted); max-width:300px; white-space:normal">
                        <?php echo htmlspecialchars($a['details']??'—');?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($recent)): ?>
                <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:24px">No recent stock movements.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div style="margin-top:15px; text-align:right">
        <a href="view_transactions.php" class="btn btn-sm">View All Transactions →</a>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>