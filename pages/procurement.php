<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

if (isManager() && isset($_GET['action'], $_GET['id'])) {
    $action = $_GET['action']; 
    $id = (int)$_GET['id'];
    if (in_array($action, ['approve','reject'])) {
        $status = $action === 'approve' ? 'approved' : 'rejected';
        
        // Get procurement details for audit
        $req = $pdo->prepare("SELECT * FROM procurement WHERE id=?");
        $req->execute([$id]);
        $procurement = $req->fetch();
        
        $pdo->prepare("UPDATE procurement SET status=?, approved_by=? WHERE id=?")->execute([$status, $_SESSION['user_id'], $id]);
        logAudit($pdo, $_SESSION['user_id'], 'PROCUREMENT_'.strtoupper($status), null, $procurement['quantity'], 
                 "Request for {$procurement['item_name']} ($status)");
        
        header('Location: procurement.php'); 
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isFrontDesk()) {
    $item_name = trim($_POST['item_name']);
    $quantity = (int)$_POST['quantity'];
    $reason = trim($_POST['reason']);
    
    $pdo->prepare("INSERT INTO procurement (item_name, quantity, reason, requested_by) VALUES (?,?,?,?)")
        ->execute([$item_name, $quantity, $reason, $_SESSION['user_id']]);
    logAudit($pdo, $_SESSION['user_id'], 'PROCUREMENT_REQUEST', null, $quantity, "Requested: $item_name");
    $success = 'Request submitted successfully!';
}

// For front desk - get their own requests
if (isFrontDesk()) {
    $requests = $pdo->prepare("
        SELECT p.*, u1.full_name as requester, u2.full_name as approver
        FROM procurement p 
        LEFT JOIN users u1 ON p.requested_by = u1.id 
        LEFT JOIN users u2 ON p.approved_by = u2.id
        WHERE p.requested_by = ?
        ORDER BY 
            CASE p.status 
                WHEN 'pending' THEN 1 
                WHEN 'approved' THEN 2 
                WHEN 'rejected' THEN 3 
            END,
            p.created_at DESC
    ");
    $requests->execute([$_SESSION['user_id']]);
} else {
    // For manager - get all requests
    $requests = $pdo->prepare("
        SELECT p.*, u1.full_name as requester, u2.full_name as approver
        FROM procurement p 
        LEFT JOIN users u1 ON p.requested_by = u1.id 
        LEFT JOIN users u2 ON p.approved_by = u2.id
        ORDER BY 
            CASE p.status 
                WHEN 'pending' THEN 1 
                WHEN 'approved' THEN 2 
                WHEN 'rejected' THEN 3 
            END,
            p.created_at DESC
    ");
    $requests->execute();
}
$requests = $requests->fetchAll();

// Get pending count for badge (only for manager)
if (isManager()) {
    $pending_count = $pdo->query("SELECT COUNT(*) FROM procurement WHERE status='pending'")->fetchColumn();
}

include __DIR__ . '/../includes/header.php';
?>

<?php if (isFrontDesk()): ?>
    <h1>Request Procurement</h1>
    
    <div class="form-container">
        <?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success;?></div><?php endif;?>
        
        <form method="POST">
            <div class="form-group">
                <label>Item Name</label>
                <input type="text" name="item_name" required placeholder="Item you need" 
                       value="<?php echo htmlspecialchars($_GET['item_name'] ?? ''); ?>">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Quantity Needed</label>
                    <input type="number" name="quantity" min="1" required value="<?php echo htmlspecialchars($_GET['qty'] ?? '1'); ?>">
                </div>
                <div class="form-group">
                    <label>Priority</label>
                    <select name="priority">
                        <option value="normal">Normal</option>
                        <option value="urgent">Urgent</option>
                        <option value="low">Low</option>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Reason</label>
                <textarea name="reason" required rows="3" placeholder="Why is this item needed?"><?php echo htmlspecialchars($_GET['reason'] ?? ''); ?></textarea>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-warning">&#128221; Submit Request</button>
                <a href="dashboard.php" class="btn">Cancel</a>
            </div>
        </form>
    </div>

    <!-- My Procurement Requests Table - Visible to Front Desk -->
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="margin:0">My Procurement Requests</h3>
            <?php 
            $my_pending = array_filter($requests, function($r) { return $r['status'] === 'pending'; });
            if (count($my_pending) > 0): 
            ?>
            <span class="status-pending"><?php echo count($my_pending); ?> pending</span>
            <?php endif; ?>
        </div>
        
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Date Requested</th>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Approved By</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $r): ?>
                    <tr>
                        <td><?php echo date('d/m/Y H:i', strtotime($r['created_at'])); ?></td>
                        <td style="font-weight:500"><?php echo htmlspecialchars($r['item_name']); ?></td>
                        <td><?php echo $r['quantity']; ?></td>
                        <td style="max-width:250px; white-space:normal"><?php echo htmlspecialchars($r['reason']); ?></td>
                        <td>
                            <span class="status-<?php echo $r['status']; ?>">
                                <?php echo ucfirst($r['status']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($r['approver'] ?? '—'); ?></td>
                        <td>
                            <?php if ($r['status'] === 'approved'): ?>
                                <a href="receive_stock.php?item_name=<?php echo urlencode($r['item_name']); ?>" class="btn btn-sm btn-success" title="Receive this item">📥 Receive</a>
                            <?php elseif ($r['status'] === 'pending'): ?>
                                <span class="status-pending" style="font-size:.75rem;">Awaiting approval</span>
                            <?php elseif ($r['status'] === 'rejected'): ?>
                                <span class="status-rejected" style="font-size:.75rem;">Rejected</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($requests)): ?>
                    <tr>
                        <td colspan="7" style="text-align:center; color:var(--muted); padding:30px;">
                            <div style="font-size:2rem; margin-bottom:10px;">📝</div>
                            <p>You haven't made any procurement requests yet.</p>
                            <p style="font-size:.85rem;">Use the form above to request items.</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

<?php else: ?>
    <!-- Manager View -->
    <h1>Procurement Requests Management</h1>
    
    <div class="card">
        <div class="card-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h3 style="margin:0">All Procurement Requests 
                <?php if ($pending_count > 0): ?>
                <span class="status-pending" style="margin-left:10px;"><?php echo $pending_count; ?> pending</span>
                <?php endif; ?>
            </h3>
            <div>
                <a href="?filter=pending" class="btn btn-sm <?php echo ($_GET['filter'] ?? '') === 'pending' ? 'btn-primary' : ''; ?>">Show Pending</a>
                <a href="procurement.php" class="btn btn-sm">Show All</a>
            </div>
        </div>
        
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Date Requested</th>
                        <th>Item</th>
                        <th>Qty</th>
                        <th>Reason</th>
                        <th>Requested By</th>
                        <th>Status</th>
                        <th>Approved By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $filter = $_GET['filter'] ?? '';
                    foreach ($requests as $r): 
                        if ($filter === 'pending' && $r['status'] !== 'pending') continue;
                    ?>
                    <tr>
                        <td><?php echo date('d/m/Y H:i', strtotime($r['created_at'])); ?></td>
                        <td style="font-weight:500"><?php echo htmlspecialchars($r['item_name']); ?></td>
                        <td><?php echo $r['quantity']; ?></td>
                        <td style="max-width:250px; white-space:normal"><?php echo htmlspecialchars($r['reason']); ?></td>
                        <td><?php echo htmlspecialchars($r['requester']); ?></td>
                        <td>
                            <span class="status-<?php echo $r['status']; ?>">
                                <?php echo ucfirst($r['status']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($r['approver'] ?? '—'); ?></td>
                        <td style="white-space:nowrap">
                            <?php if ($r['status'] === 'pending'): ?>
                                <a href="?action=approve&id=<?php echo $r['id']; ?>" class="btn btn-sm btn-success" onclick="return confirm('Approve this request?')">✓ Approve</a>
                                <a href="?action=reject&id=<?php echo $r['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Reject this request?')">✗ Reject</a>
                            <?php elseif ($r['status'] === 'approved'): ?>
                                <a href="receive_stock.php?item_name=<?php echo urlencode($r['item_name']); ?>" class="btn btn-sm btn-success">📥 Receive</a>
                                <span class="status-approved" style="margin-left:5px;">Approved</span>
                            <?php elseif ($r['status'] === 'rejected'): ?>
                                <span class="status-rejected">Rejected</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($requests)): ?>
                    <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:24px">No procurement requests yet.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Summary Stats for Manager -->
    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap:15px; margin-top:20px;">
        <?php
        $stats = $pdo->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status='rejected' THEN 1 ELSE 0 END) as rejected
            FROM procurement
        ")->fetch();
        ?>
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:8px; padding:15px;">
            <div style="font-size:.75rem; color:var(--muted); text-transform:uppercase;">Total Requests</div>
            <div style="font-size:1.8rem; font-weight:700;"><?php echo $stats['total']; ?></div>
        </div>
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:8px; padding:15px;">
            <div style="font-size:.75rem; color:var(--muted); text-transform:uppercase;">Pending</div>
            <div style="font-size:1.8rem; font-weight:700; color:var(--warning);"><?php echo $stats['pending']; ?></div>
        </div>
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:8px; padding:15px;">
            <div style="font-size:.75rem; color:var(--muted); text-transform:uppercase;">Approved</div>
            <div style="font-size:1.8rem; font-weight:700; color:var(--success);"><?php echo $stats['approved']; ?></div>
        </div>
        <div style="background:var(--surface); border:1px solid var(--border); border-radius:8px; padding:15px;">
            <div style="font-size:.75rem; color:var(--muted); text-transform:uppercase;">Rejected</div>
            <div style="font-size:1.8rem; font-weight:700; color:var(--danger);"><?php echo $stats['rejected']; ?></div>
        </div>
    </div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>