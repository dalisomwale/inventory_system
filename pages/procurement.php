<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

// Get all categories for filter
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

// Get filter parameters
$filter_category = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$filter_month = isset($_GET['month']) ? $_GET['month'] : '';

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
        
        // Build redirect URL with filters
        $redirect = 'procurement.php';
        $params = array();
        if ($filter_category) $params[] = 'category_id='.$filter_category;
        if ($filter_month) $params[] = 'month='.$filter_month;
        if (!empty($params)) $redirect .= '?' . implode('&', $params);
        
        header('Location: ' . $redirect);
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isFrontDesk()) {
    $item_name = trim($_POST['item_name']);
    $quantity = (int)$_POST['quantity'];
    $reason = trim($_POST['reason']);
    $category_id = (int)$_POST['category_id'];
    
    // Get category name
    $cat = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
    $cat->execute([$category_id]);
    $cat_name = $cat->fetchColumn();
    
    $pdo->prepare("INSERT INTO procurement (item_name, quantity, reason, requested_by, category_id, category) VALUES (?,?,?,?,?,?)")
        ->execute([$item_name, $quantity, $reason, $_SESSION['user_id'], $category_id, $cat_name]);
    logAudit($pdo, $_SESSION['user_id'], 'PROCUREMENT_REQUEST', null, $quantity, "Requested: $item_name in category: $cat_name");
    $success = 'Request submitted successfully!';
}

// Build query with filters
$query = "
    SELECT p.*, u1.full_name as requester, u2.full_name as approver, c.name as category_name
    FROM procurement p 
    LEFT JOIN users u1 ON p.requested_by = u1.id 
    LEFT JOIN users u2 ON p.approved_by = u2.id
    LEFT JOIN categories c ON p.category_id = c.id
    WHERE 1=1
";
$params = array();

if (isFrontDesk()) {
    $query .= " AND p.requested_by = ?";
    $params[] = $_SESSION['user_id'];
}

if ($filter_category > 0) {
    $query .= " AND p.category_id = ?";
    $params[] = $filter_category;
}

if ($filter_month != '') {
    $query .= " AND DATE_FORMAT(p.created_at, '%Y-%m') = ?";
    $params[] = $filter_month;
}

$query .= " ORDER BY 
    CASE p.status 
        WHEN 'pending' THEN 1 
        WHEN 'approved' THEN 2 
        WHEN 'rejected' THEN 3 
    END,
    p.created_at DESC";

$requests = $pdo->prepare($query);
$requests->execute($params);
$requests = $requests->fetchAll();

// Get pending count for badge (only for manager)
$pending_count = 0;
if (isManager()) {
    $pending_count = $pdo->query("SELECT COUNT(*) FROM procurement WHERE status='pending'")->fetchColumn();
}

// Get available months for filter
$available_months = $pdo->query("
    SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m') as month 
    FROM procurement 
    ORDER BY month DESC
")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../includes/header.php';
?>

<style>
.procurement-container {
    max-width: 1400px;
    margin: 0 auto;
}

.filter-card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 25px;
    border: 1px solid var(--border);
}

.filter-section {
    margin-bottom: 15px;
}

.filter-label {
    font-weight: 600;
    font-size: 0.8rem;
    margin-bottom: 8px;
    display: block;
    color: var(--muted);
}

.category-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 10px;
}

.category-btn {
    padding: 6px 14px;
    border: 1px solid var(--border);
    border-radius: 20px;
    background: var(--surface);
    text-decoration: none;
    color: var(--text);
    font-size: 0.8rem;
    transition: all 0.2s;
}

.category-btn:hover {
    border-color: var(--accent);
    background: var(--accent-lt);
}

.category-btn.active {
    background: var(--accent);
    color: white;
    border-color: var(--accent);
}

.month-select {
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 0.85rem;
    background: var(--surface);
    cursor: pointer;
    min-width: 150px;
}

.clear-filters {
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid var(--border);
    text-align: right;
}

.form-card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 25px;
    margin-bottom: 25px;
    border: 1px solid var(--border);
}

.form-title {
    font-size: 1.2rem;
    font-weight: 600;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--accent);
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    font-size: 0.85rem;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 0.9rem;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.btn {
    padding: 10px 20px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-size: 0.9rem;
    text-decoration: none;
    display: inline-block;
}

.btn-primary {
    background: var(--accent);
    color: white;
}

.btn-primary:hover {
    background: #1d4ed8;
}

.btn-success {
    background: var(--success);
    color: white;
}

.btn-success:hover {
    background: #15803d;
}

.btn-danger {
    background: var(--danger);
    color: white;
}

.btn-danger:hover {
    background: #b91c1c;
}

.btn-sm {
    padding: 4px 10px;
    font-size: 0.75rem;
}

.btn-outline {
    background: transparent;
    border: 1px solid var(--border);
    color: var(--text);
}

.btn-outline:hover {
    border-color: var(--accent);
    color: var(--accent);
}

.table-container {
    overflow-x: auto;
}

.requests-table {
    width: 100%;
    border-collapse: collapse;
}

.requests-table th {
    text-align: left;
    padding: 12px;
    background: #f9fafb;
    border-bottom: 2px solid var(--border);
    font-weight: 600;
    font-size: 0.85rem;
}

.requests-table td {
    padding: 12px;
    border-bottom: 1px solid var(--border);
    font-size: 0.85rem;
}

.status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: 500;
}

.status-pending {
    background: #fef3c7;
    color: #92400e;
}

.status-approved {
    background: #dcfce7;
    color: #166534;
}

.status-rejected {
    background: #fee2e2;
    color: #991b1b;
}

.alert {
    padding: 12px 15px;
    border-radius: 6px;
    margin-bottom: 20px;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-danger {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.cat-pill {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.7rem;
    background: var(--accent-lt);
    color: var(--accent);
}

.empty-state {
    text-align: center;
    padding: 60px;
    color: var(--muted);
}
</style>

<div class="procurement-container">
    <h1>Procurement Requests</h1>

    <!-- Filter Section -->
    <div class="filter-card">
        <!-- Category Filter -->
        <div class="filter-section">
            <div class="filter-label">Category</div>
            <div class="category-buttons">
                <a href="?<?php 
                    $params = array();
                    if ($filter_month) $params[] = 'month='.$filter_month;
                    echo implode('&', $params);
                ?>" class="category-btn <?php echo $filter_category == 0 ? 'active' : ''; ?>">All Categories</a>
                <?php foreach ($categories as $cat): ?>
                <a href="?category_id=<?php echo $cat['id']; ?><?php 
                    if ($filter_month) echo '&month='.$filter_month;
                ?>" class="category-btn <?php echo $filter_category == $cat['id'] ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($cat['name']); ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Month Filter -->
        <div class="filter-section">
            <div class="filter-label">Month</div>
            <div>
                <select class="month-select" onchange="window.location.href=this.value">
                    <option value="?<?php 
                        $params = array();
                        if ($filter_category) $params[] = 'category_id='.$filter_category;
                        echo implode('&', $params);
                    ?>">All Months</option>
                    <?php 
                    $months = array();
                    for ($i = 0; $i <= 12; $i++) {
                        $month_date = date('Y-m', strtotime("-$i months"));
                        $months[] = $month_date;
                    }
                    $months = array_unique(array_merge($months, $available_months));
                    rsort($months);
                    foreach ($months as $month):
                    ?>
                    <option value="?month=<?php echo $month; ?><?php 
                        if ($filter_category) echo '&category_id='.$filter_category;
                    ?>" <?php echo $filter_month == $month ? 'selected' : ''; ?>>
                        <?php echo date('F Y', strtotime($month . '-01')); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <!-- Clear Filters -->
        <?php if ($filter_category > 0 || $filter_month != ''): ?>
        <div class="clear-filters">
            <a href="procurement.php" class="btn btn-outline btn-sm">Clear Filters</a>
        </div>
        <?php endif; ?>
    </div>

    <?php if (isFrontDesk()): ?>
    <!-- Request Form for Front Desk -->
    <div class="form-card">
        <div class="form-title">Request Procurement</div>
        
        <?php if (isset($success)): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>Item Name</label>
                    <input type="text" name="item_name" required placeholder="Item you need">
                </div>
                <div class="form-group">
                    <label>Category</label>
                    <select name="category_id" required>
                        <option value="">— Select Category —</option>
                        <?php foreach ($categories as $cat): ?>
                        <option value="<?php echo $cat['id']; ?>" <?php echo ($filter_category == $cat['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($cat['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Quantity Needed</label>
                    <input type="number" name="quantity" min="1" required value="1">
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
                <textarea name="reason" required rows="3" placeholder="Why is this item needed?"></textarea>
            </div>
            <div class="form-actions">
                <button type="submit" class="btn btn-primary">Submit Request</button>
                <a href="dashboard.php" class="btn" style="background: #6c757d; color: white;">Cancel</a>
            </div>
        </form>
    </div>
    <?php endif; ?>

    <!-- Requests Table -->
    <div class="form-card">
        <div class="form-title">
            Procurement Requests
            <?php if (isManager() && $pending_count > 0): ?>
            <span class="status-badge status-pending" style="margin-left: 10px;"><?php echo $pending_count; ?> pending</span>
            <?php endif; ?>
        </div>
        
        <?php if (count($requests) > 0): ?>
        <div class="table-container">
            <table class="requests-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Item</th>
                        <th>Category</th>
                        <th>Qty</th>
                        <th>Reason</th>
                        <th>Requested By</th>
                        <th>Status</th>
                        <?php if (isManager()): ?>
                        <th>Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requests as $r): ?>
                    <tr>
                        <td style="white-space:nowrap"><?php echo date('d/m/Y H:i', strtotime($r['created_at'])); ?></td>
                        <td style="font-weight:500"><?php echo htmlspecialchars($r['item_name']); ?></td>
                        <td><span class="cat-pill"><?php echo htmlspecialchars($r['category_name'] ?? 'Uncategorized'); ?></span></td>
                        <td><?php echo $r['quantity']; ?></td>
                        <td style="max-width:250px; white-space:normal"><?php echo htmlspecialchars($r['reason']); ?></td>
                        <td><?php echo htmlspecialchars($r['requester']); ?></td>
                        <td>
                            <?php if ($r['status'] == 'pending'): ?>
                                <span class="status-badge status-pending">Pending</span>
                            <?php elseif ($r['status'] == 'approved'): ?>
                                <span class="status-badge status-approved">Approved</span>
                            <?php else: ?>
                                <span class="status-badge status-rejected">Rejected</span>
                            <?php endif; ?>
                        </td>
                        <?php if (isManager() && $r['status'] == 'pending'): ?>
                        <td style="white-space:nowrap">
                            <a href="?action=approve&id=<?php echo $r['id']; ?><?php 
                                if ($filter_category) echo '&category_id='.$filter_category;
                                if ($filter_month) echo '&month='.$filter_month;
                            ?>" class="btn btn-success btn-sm" onclick="return confirm('Approve this request?')">Approve</a>
                            <a href="?action=reject&id=<?php echo $r['id']; ?><?php 
                                if ($filter_category) echo '&category_id='.$filter_category;
                                if ($filter_month) echo '&month='.$filter_month;
                            ?>" class="btn btn-danger btn-sm" onclick="return confirm('Reject this request?')">Reject</a>
                        </td>
                        <?php elseif (isManager()): ?>
                        <td>—</td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <p>No procurement requests found.</p>
            <?php if ($filter_category > 0 || $filter_month != ''): ?>
            <p style="font-size: 0.8rem;">Try changing the filters to see more results.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
// Auto-hide alerts
setTimeout(function() {
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        alert.style.opacity = '0';
        setTimeout(function() { alert.remove(); }, 400);
    });
}, 5000);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>