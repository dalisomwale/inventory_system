<?php
require_once __DIR__ . '/../includes/auth.php';
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

// Build query
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

$available_months = $pdo->query("SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m') as month FROM procurement ORDER BY month DESC")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Procurement Requests</h1>
    <?php if (isManager() && $pending_count > 0): ?>
    <span class="status-pending"><?php echo $pending_count; ?> pending</span>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:16px;">
    <div style="display:flex; gap:16px; align-items:flex-end; flex-wrap:wrap;">
        <div>
            <div style="font-size:.72rem; font-weight:500; color:var(--muted); margin-bottom:6px; text-transform:uppercase; letter-spacing:.06em;">Category</div>
            <div class="cat-tabs" style="margin:0">
                <a href="?<?php $p=[]; if($filter_month) $p[]='month='.$filter_month; echo implode('&',$p); ?>" class="cat-tab <?php echo $filter_cat==0?'active':''; ?>">All</a>
                <?php foreach ($categories as $cat): ?>
                <a href="?category_id=<?php echo $cat['id']; ?><?php if($filter_month) echo '&month='.$filter_month; ?>" class="cat-tab <?php echo $filter_cat==$cat['id']?'active':''; ?>">
                    <?php echo htmlspecialchars($cat['name']); ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div>
            <div style="font-size:.72rem; font-weight:500; color:var(--muted); margin-bottom:6px; text-transform:uppercase; letter-spacing:.06em;">Month</div>
            <select onchange="window.location.href=this.value" style="padding:6px 10px; border:1px solid var(--border); border-radius:var(--radius-sm); font-size:.82rem; background:var(--surface); cursor:pointer; min-width:140px;">
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
        <a href="procurement.php" class="btn btn-sm" style="margin-bottom:2px">Clear Filters</a>
        <?php endif; ?>
    </div>
</div>

<?php if (isFrontDesk()): ?>
<!-- Request Form -->
<div class="form-container">
    <div class="form-title">Submit Procurement Request</div>
    <?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
    <form method="POST">
        <div class="form-row">
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
        <div class="form-row">
            <div class="form-group">
                <label>Quantity *</label>
                <input type="number" name="quantity" min="1" required value="1">
            </div>
        </div>
        <div class="form-group">
            <label>Reason *</label>
            <textarea name="reason" required rows="2" placeholder="Why is this item needed?"></textarea>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-primary">Submit Request</button>
            <a href="dashboard.php" class="btn">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- Requests Table -->
<div class="card">
    <h3 style="margin-bottom:12px">
        Requests
        <?php if ($filter_month): ?>&mdash; <?php echo date('F Y', strtotime($filter_month.'-01')); ?><?php endif; ?>
    </h3>
    <?php if (count($requests) > 0): ?>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Date</th><th>Item</th><th>Category</th><th>Qty</th><th>Reason</th><th>Requested By</th><th>Status</th>
                    <?php if (isManager()): ?><th></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($requests as $r): ?>
                <tr>
                    <td style="white-space:nowrap; color:var(--muted); font-size:.76rem"><?php echo date('d/m/Y', strtotime($r['created_at'])); ?></td>
                    <td style="font-weight:500"><?php echo htmlspecialchars($r['item_name']); ?></td>
                    <td><span class="cat-pill"><?php echo htmlspecialchars($r['category_name'] ?? 'Uncategorized'); ?></span></td>
                    <td><?php echo $r['quantity']; ?></td>
                    <td style="color:var(--muted); font-size:.76rem; max-width:200px; white-space:normal"><?php echo htmlspecialchars($r['reason']); ?></td>
                    <td style="color:var(--muted)"><?php echo htmlspecialchars($r['requester']); ?></td>
                    <td>
                        <?php if ($r['status'] == 'pending'):  ?><span class="status-pending">Pending</span>
                        <?php elseif ($r['status'] == 'approved'): ?><span class="status-receiving">Approved</span>
                        <?php else: ?><span class="status-issuing">Rejected</span>
                        <?php endif; ?>
                    </td>
                    <?php if (isManager()): ?>
                    <td style="white-space:nowrap">
                        <?php if ($r['status'] == 'pending'): ?>
                        <a href="?action=approve&id=<?php echo $r['id']; ?><?php if($filter_cat) echo '&category_id='.$filter_cat; if($filter_month) echo '&month='.$filter_month; ?>" class="btn btn-sm btn-success" onclick="return confirm('Approve?')">Approve</a>
                        <a href="?action=reject&id=<?php echo $r['id']; ?><?php if($filter_cat) echo '&category_id='.$filter_cat; if($filter_month) echo '&month='.$filter_month; ?>" class="btn btn-sm" onclick="return confirm('Reject?')">Reject</a>
                        <?php else: ?><span style="color:var(--muted); font-size:.76rem">—</span><?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state">
        <p>No procurement requests found<?php if($filter_cat||$filter_month): ?> for the selected filters<?php endif; ?>.</p>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>