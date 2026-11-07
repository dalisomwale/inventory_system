<?php
require_once __DIR__ . '/../config/database.php';
redirectIfNotLoggedIn();

$categories    = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$filter_cat    = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$filter_month  = $_GET['month'] ?? '';

// Manager approve/reject
if (isManager() && isset($_GET['action'], $_GET['id'])) {
    $action = $_GET['action'];
    $id     = (int)$_GET['id'];
    if (in_array($action, ['approve', 'reject'])) {
        $status = $action === 'approve' ? 'approved' : 'rejected';
        $req    = $pdo->prepare("SELECT * FROM procurement WHERE id=?");
        $req->execute([$id]);
        $procurement = $req->fetch();
        $pdo->prepare("UPDATE procurement SET status=?, approved_by=? WHERE id=?")->execute([$status, $_SESSION['user_id'], $id]);
        logAudit($pdo, $_SESSION['user_id'], 'PROCUREMENT_'.strtoupper($status), null, $procurement['quantity'],
                 "Request for {$procurement['item_name']} ($status)");
        $parts = [];
        if ($filter_cat)   $parts[] = 'category_id='.$filter_cat;
        if ($filter_month) $parts[] = 'month='.$filter_month;
        header('Location: procurement.php' . ($parts ? '?'.implode('&', $parts) : ''));
        exit();
    }
}

// Front desk submit request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isFrontDesk()) {
    $item_name   = trim($_POST['item_name']);
    $quantity    = (int)$_POST['quantity'];
    $reason      = trim($_POST['reason']);
    $category_id = (int)$_POST['category_id'];
    $cat         = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
    $cat->execute([$category_id]);
    $cat_name    = $cat->fetchColumn();
    $pdo->prepare("INSERT INTO procurement (item_name, quantity, reason, requested_by, category_id, category) VALUES (?,?,?,?,?,?)")
        ->execute([$item_name, $quantity, $reason, $_SESSION['user_id'], $category_id, $cat_name]);
    logAudit($pdo, $_SESSION['user_id'], 'PROCUREMENT_REQUEST', null, $quantity, "Requested: $item_name in category: $cat_name");
    $success = 'Request submitted.';
}

// Build query for MySQL - use DATE_FORMAT instead of strftime
$query  = "SELECT p.*, u1.full_name as requester, u2.full_name as approver, c.name as category_name
           FROM procurement p
           LEFT JOIN users u1 ON p.requested_by = u1.id
           LEFT JOIN users u2 ON p.approved_by  = u2.id
           LEFT JOIN categories c ON p.category_id = c.id
           WHERE 1=1";
$params = [];
if (isFrontDesk()) { $query .= " AND p.requested_by = ?"; $params[] = $_SESSION['user_id']; }
if ($filter_cat)   { $query .= " AND p.category_id = ?"; $params[] = $filter_cat; }
if ($filter_month) { $query .= " AND DATE_FORMAT(p.created_at, '%Y-%m') = ?"; $params[] = $filter_month; }
$query .= " ORDER BY CASE p.status WHEN 'pending' THEN 1 WHEN 'approved' THEN 2 WHEN 'rejected' THEN 3 END, p.created_at DESC";

$requests      = $pdo->prepare($query);
$requests->execute($params);
$requests      = $requests->fetchAll();
$pending_count = isManager() ? $pdo->query("SELECT COUNT(*) FROM procurement WHERE status='pending'")->fetchColumn() : 0;

// Get available months for filter - use DATE_FORMAT for MySQL
$available_months = $pdo->query("SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m') as month FROM procurement ORDER BY month DESC")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Procurement Requests</h1>
</div>


<!-- Filters -->
<div class="card" style="margin-bottom:16px;">
    <div class="card-body">
        <div style="display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap;">
            <div>
                <div style="font-size:.72rem; font-weight:500; color:var(--muted); margin-bottom:6px;">Category</div>
                <div style="display:flex; flex-wrap:wrap; gap:6px;">
                    <a href="?<?php $p=[]; if($filter_month) $p[]='month='.$filter_month; echo implode('&',$p); ?>" style="padding:5px 12px; border-radius:20px; font-size:.78rem; text-decoration:none; background:<?php echo $filter_cat==0?'#263A8E':'#f1f5f9'; ?>; color:<?php echo $filter_cat==0?'white':'#475569'; ?>;">All</a>
                    <?php foreach ($categories as $cat): ?>
                    <a href="?category_id=<?php echo $cat['id']; ?><?php if($filter_month) echo '&month='.$filter_month; ?>" style="padding:5px 12px; border-radius:20px; font-size:.78rem; text-decoration:none; background:<?php echo $filter_cat==$cat['id']?'#263A8E':'#f1f5f9'; ?>; color:<?php echo $filter_cat==$cat['id']?'white':'#475569'; ?>;">
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <div>
                <div style="font-size:.72rem; font-weight:500; color:var(--muted); margin-bottom:6px;">Month</div>
                <select onchange="window.location.href=this.value" style="padding:6px 10px; border:1px solid #e2e8f0; border-radius:8px;">
                    <option value="?<?php $p=[]; if($filter_cat) $p[]='category_id='.$filter_cat; echo implode('&',$p); ?>">All Months</option>
                    <?php
                    $months = [];
                    for ($i=0; $i<=12; $i++) { $months[] = date('Y-m', strtotime("-$i months")); }
                    $months = array_unique(array_merge($months, $available_months));
                    rsort($months);
                    foreach ($months as $m):
                    ?>
                    <option value="?month=<?php echo $m; ?><?php if($filter_cat) echo '&category_id='.$filter_cat; ?>" <?php echo $filter_month==$m?'selected':''; ?>>
                        <?php echo date('F Y', strtotime($m.'-01')); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($filter_cat || $filter_month): ?>
            <a href="procurement.php" class="btn btn-sm">Clear Filters</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (isFrontDesk()): ?>
<!-- Request Form -->
<div class="card" style="margin-bottom:20px;">
    <div class="card-header">
        <h3>Submit Procurement Request</h3>
    </div>
    <div class="card-body">
        <?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
        <form method="POST">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
                <div class="form-group">
                    <label>Item Name *</label>
                    <input type="text" name="item_name" required placeholder="Item you need">
                </div>
                <div class="form-group">
                    <label>Category *</label>
                    <select name="category_id" required>
                        <option value="">— Select —</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo ($filter_cat==$cat['id'])?'selected':''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label>Quantity *</label>
                <input type="number" name="quantity" min="1" required value="1">
            </div>
            <div class="form-group">
                <label>Reason *</label>
                <textarea name="reason" required rows="2" placeholder="Why is this item needed?"></textarea>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Submit Request</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Requests Table -->
<div class="card">
    <div class="card-header">
        <h3>Requests <?php if ($filter_month): ?>— <?php echo date('F Y', strtotime($filter_month.'-01')); ?><?php endif; ?></h3>
    </div>
    <div class="table-container">
        <?php if (count($requests) > 0): ?>
        <table style="width:100%">
            <thead>
                <tr>
                    <th>Date</th><th>Item</th><th>Category</th><th>Qty</th><th>Reason</th><th>Requested By</th><th>Status</th>
                    <?php if (isManager()): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $r): ?>
                <tr>
                    <td style="white-space:nowrap; color:#64748b; font-size:.76rem"><?php echo date('d/m/Y', strtotime($r['created_at'])); ?></td>
                    <td style="font-weight:500"><?php echo htmlspecialchars($r['item_name']); ?></td>
                    <td><span style="padding:2px 8px; border-radius:20px; font-size:.72rem; background:#f1f5f9;"><?php echo htmlspecialchars($r['category_name'] ?? 'Uncategorized'); ?></span></td>
                    <td><?php echo $r['quantity']; ?></td>
                    <td style="color:#64748b; font-size:.76rem; max-width:200px;"><?php echo htmlspecialchars($r['reason']); ?></td>
                    <td style="color:#64748b"><?php echo htmlspecialchars($r['requester']); ?></td>
                    <td>
                        <?php if ($r['status'] == 'pending'):  ?><span style="background:#fef3c7; color:#d97706; padding:2px 8px; border-radius:20px; font-size:11px;">Pending</span>
                        <?php elseif ($r['status'] == 'approved'): ?><span style="background:#d1fae5; color:#059669; padding:2px 8px; border-radius:20px; font-size:11px;">Approved</span>
                        <?php else: ?><span style="background:#fee2e2; color:#dc2626; padding:2px 8px; border-radius:20px; font-size:11px;">Rejected</span>
                        <?php endif; ?>
                    </td>
                    <?php if (isManager()): ?>
                    <td style="white-space:nowrap">
                        <?php if ($r['status'] == 'pending'): ?>
                        <a href="?action=approve&id=<?php echo $r['id']; ?><?php if($filter_cat) echo '&category_id='.$filter_cat; if($filter_month) echo '&month='.$filter_month; ?>" style="padding:4px 10px; background:#10b981; color:white; text-decoration:none; border-radius:4px; font-size:11px; margin-right:4px;" onclick="return confirm('Approve?')">Approve</a>
                        <a href="?action=reject&id=<?php echo $r['id']; ?><?php if($filter_cat) echo '&category_id='.$filter_cat; if($filter_month) echo '&month='.$filter_month; ?>" style="padding:4px 10px; background:#ef4444; color:white; text-decoration:none; border-radius:4px; font-size:11px;" onclick="return confirm('Reject?')">Reject</a>
                        <?php else: ?><span style="color:#94a3b8; font-size:.76rem">—</span><?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
        <div style="padding:40px; text-align:center; color:#64748b;">
            No procurement requests found<?php if($filter_cat||$filter_month): ?> for the selected filters<?php endif; ?>.
        </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>