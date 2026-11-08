<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('transactions.view');

$filter_type = $_GET['type']    ?? 'all';
$filter_date = $_GET['date']    ?? date('Y-m-d');
$filter_item = (int)($_GET['item_id'] ?? 0);
$filter_cat  = $_GET['cat']     ?? '';
// Department-limited users only see what was issued to their department
$scope_dept  = my_scope_department();
if ($scope_dept !== null) $filter_type = 'issuing';
$cond_dept   = $scope_dept !== null ? " AND si.department_id = " . (int)$scope_dept : "";

$all_items = $pdo->query("SELECT * FROM items" . ($scope_dept !== null ? " WHERE id IN (SELECT item_id FROM stock_issuing WHERE department_id = " . (int)$scope_dept . ")" : "") . " ORDER BY category, item_name")->fetchAll();
$grouped   = [];
foreach ($all_items as $it) { $grouped[$it['category']?:'General'][] = $it; }
$all_cats  = getCategories($pdo);

$base_r = "SELECT 'Receiving' as type, r.id, r.item_id, r.quantity, r.supplier, NULL as issued_to, NULL as purpose, r.created_at, i.item_name, i.category, u.full_name as user_name, b.name as branch_name
           FROM stock_receiving r JOIN items i ON r.item_id=i.id LEFT JOIN users u ON r.received_by=u.id LEFT JOIN branches b ON b.id=r.branch_id
           WHERE DATE(r.created_at) = ?";
$base_i = "SELECT 'Issuing' as type, si.id, si.item_id, si.quantity, NULL as supplier, si.issued_to, si.purpose, si.created_at, i.item_name, i.category, u.full_name as user_name, b.name as branch_name
           FROM stock_issuing si JOIN items i ON si.item_id=i.id LEFT JOIN users u ON si.issued_by=u.id LEFT JOIN branches b ON b.id=si.branch_id
           WHERE DATE(si.created_at) = ?" . $cond_dept;
$cond_item = $filter_item ? " AND {tbl}.item_id = ?" : "";
$cond_cat  = $filter_cat  ? " AND i.category = ?"   : "";

if ($filter_type === 'receiving') {
    $q = $base_r . str_replace('{tbl}','r',$cond_item) . $cond_cat . " ORDER BY r.created_at DESC";
    $p = array_merge([$filter_date], $filter_item ? [$filter_item] : [], $filter_cat ? [$filter_cat] : []);
} elseif ($filter_type === 'issuing') {
    $q = $base_i . str_replace('{tbl}','si',$cond_item) . $cond_cat . " ORDER BY si.created_at DESC";
    $p = array_merge([$filter_date], $filter_item ? [$filter_item] : [], $filter_cat ? [$filter_cat] : []);
} else {
    $q  = "(" . $base_r . str_replace('{tbl}','r',$cond_item) . $cond_cat . ")";
    $q .= " UNION ALL ";
    $q .= "(" . $base_i . str_replace('{tbl}','si',$cond_item) . $cond_cat . ")";
    $q .= " ORDER BY created_at DESC";
    $extra = array_merge($filter_item ? [$filter_item] : [], $filter_cat ? [$filter_cat] : []);
    $p     = array_merge([$filter_date], $extra, [$filter_date], $extra);
}

$stmt = $pdo->prepare($q);
$stmt->execute($p);
$transactions = $stmt->fetchAll();

if (wants_xlsx()) {
    $dept_label = $scope_dept !== null ? (department_name($scope_dept) ?: 'Your department') : '';
    $item_label = '';
    foreach ($all_items as $it) if ((int)$it['id'] === $filter_item) $item_label = $it['item_name'];
    xlsx_download('Transactions ' . $filter_date . ($dept_label ? " $dept_label" : ''), [[
        'name'    => 'Transactions',
        'title'   => 'Transaction History' . ($dept_label ? " - $dept_label" : ''),
        'meta'    => array_filter(['Date: ' . date('d M Y', strtotime($filter_date)),
                                   $scope_dept === null ? 'Type: ' . ucfirst($filter_type) : '',
                                   $filter_cat ? "Category: $filter_cat" : '', $item_label ? "Item: $item_label" : '']),
        'columns' => ['Type', 'Time', 'Item', 'Category', 'Branch', ['Qty', 'int'], 'User', 'Supplier / issued to', 'Purpose'],
        'rows'    => array_map(fn($t) => [$t['type'], date('H:i', strtotime($t['created_at'])), $t['item_name'], $t['category'] ?? 'General', $t['branch_name'] ?? '',
                                          $t['quantity'], $t['user_name'] ?? '', $t['type'] === 'Receiving' ? ($t['supplier'] ?? '') : ($t['issued_to'] ?? ''), $t['purpose'] ?? ''], $transactions),
        // Receipts and issues don't add up together, so only total one type
        'totals'  => $filter_type !== 'all' ? ['Total', '', '', '', '', array_sum(array_column($transactions, 'quantity'))] : null,
    ]]);
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Transaction History<?php if ($scope_dept !== null): ?> &mdash; <?php echo htmlspecialchars(department_name($scope_dept) ?: 'your department'); ?><?php endif; ?></h1>
    <div style="display:flex; gap:8px; flex-wrap:wrap">
        <a href="dashboard.php" class="btn btn-sm">Back to Dashboard</a>
    </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:16px;">
    <form method="GET" class="filter-form">
        <?php if ($scope_dept === null): ?>
        <div class="form-group">
            <label>Type</label>
            <select name="type">
                <option value="all"       <?php echo $filter_type==='all'       ?'selected':'';?>>All</option>
                <option value="receiving" <?php echo $filter_type==='receiving' ?'selected':'';?>>Receiving</option>
                <option value="issuing"   <?php echo $filter_type==='issuing'   ?'selected':'';?>>Issuing</option>
            </select>
        </div>
        <?php endif; ?>
        <div class="form-group">
            <label>Date</label>
            <input type="date" name="date" value="<?php echo htmlspecialchars($filter_date); ?>" required>
        </div>
        <div class="form-group">
            <label>Category</label>
            <select name="cat">
                <option value="">All Categories</option>
                <?php foreach ($all_cats as $c): ?>
                <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $filter_cat===$c?'selected':''; ?>><?php echo htmlspecialchars($c); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label>Item</label>
            <select name="item_id">
                <option value="0">All Items</option>
                <?php foreach ($grouped as $cat => $items): ?>
                <optgroup label="<?php echo htmlspecialchars($cat); ?>">
                    <?php foreach ($items as $it): ?>
                    <option value="<?php echo $it['id']; ?>" <?php echo $filter_item==$it['id']?'selected':''; ?>><?php echo htmlspecialchars($it['item_name']); ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="display:flex;align-items:flex-end">
            <button type="submit" class="btn btn-primary">Apply Filter</button>
        </div>
    </form>
</div>

<!-- Results -->
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; flex-wrap:wrap; gap:8px;">
        <h3 style="margin:0"><?php echo date('d/m/Y', strtotime($filter_date)); ?></h3>
        <div style="display:flex; align-items:center; gap:12px">
            <span style="font-size:.76rem; color:var(--muted)"><?php echo count($transactions); ?> result<?php echo count($transactions)!==1?'s':''; ?></span>
            <?php echo xlsx_button(); ?>
        </div>
    </div>
    <div class="table-container">
        <table>
            <thead>
                <tr><th>Type</th><th>Item</th><th>Category</th><th>Branch</th><th>Qty</th><th>User</th><th>Time</th><th>Details</th></tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $t): ?>
                <tr>
                    <td><span class="status-<?php echo strtolower($t['type']); ?>"><?php echo $t['type']; ?></span></td>
                    <td style="font-weight:500"><?php echo htmlspecialchars($t['item_name']); ?></td>
                    <td><span class="cat-pill <?php echo catClass($t['category']??'General'); ?>"><?php echo htmlspecialchars($t['category']??'General'); ?></span></td>
                    <td style="color:var(--muted)"><?php echo htmlspecialchars($t['branch_name'] ?? '—'); ?></td>
                    <td><?php echo $t['quantity']; ?></td>
                    <td style="color:var(--muted)"><?php echo htmlspecialchars($t['user_name'] ?? '—'); ?></td>
                    <td style="white-space:nowrap; color:var(--muted); font-size:.76rem"><?php echo date('H:i', strtotime($t['created_at'])); ?></td>
                    <td style="font-size:.76rem; color:var(--muted)">
                        <?php if ($t['type']==='Receiving'): ?>Supplier: <?php echo htmlspecialchars($t['supplier']??''); ?>
                        <?php else: ?>To: <?php echo htmlspecialchars($t['issued_to']??''); ?><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($transactions)): ?>
                <tr> <td colspan="8" style="text-align:center; color:var(--muted); padding:32px">No transactions found for this date.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>