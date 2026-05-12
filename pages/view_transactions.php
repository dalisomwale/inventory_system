<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();
if (!isManager()) { header('Location: dashboard.php'); exit(); }

$filter_type = $_GET['type'] ?? 'all';
$filter_date = $_GET['date'] ?? date('Y-m-d');
$filter_item = (int)($_GET['item_id'] ?? 0);
$filter_cat = $_GET['cat'] ?? '';

$all_items = $pdo->query("SELECT * FROM items ORDER BY category, item_name")->fetchAll();
$grouped = [];
foreach ($all_items as $it) { $grouped[$it['category'] ?: 'General'][] = $it; }
$all_cats = getCategories($pdo);

$base_r = "SELECT 'Receiving' as type, r.id, r.item_id, r.quantity, r.supplier, NULL as issued_to, NULL as purpose, r.created_at, i.item_name, i.category, u.full_name as user_name
           FROM stock_receiving r JOIN items i ON r.item_id=i.id JOIN users u ON r.received_by=u.id
           WHERE DATE(r.created_at) = ?";
$base_i = "SELECT 'Issuing' as type, si.id, si.item_id, si.quantity, NULL as supplier, si.issued_to, si.purpose, si.created_at, i.item_name, i.category, u.full_name as user_name
           FROM stock_issuing si JOIN items i ON si.item_id=i.id JOIN users u ON si.issued_by=u.id
           WHERE DATE(si.created_at) = ?";
$cond_item = $filter_item ? " AND {tbl}.item_id = ?" : "";
$cond_cat = $filter_cat ? " AND i.category = ?" : "";

if ($filter_type === 'receiving') {
    $q = $base_r . str_replace('{tbl}', 'r', $cond_item) . $cond_cat . " ORDER BY r.created_at DESC";
    $p = array_merge([$filter_date], $filter_item ? [$filter_item] : [], $filter_cat ? [$filter_cat] : []);
} elseif ($filter_type === 'issuing') {
    $q = $base_i . str_replace('{tbl}', 'si', $cond_item) . $cond_cat . " ORDER BY si.created_at DESC";
    $p = array_merge([$filter_date], $filter_item ? [$filter_item] : [], $filter_cat ? [$filter_cat] : []);
} else {
    $q = "(" . $base_r . str_replace('{tbl}', 'r', $cond_item) . $cond_cat . ")";
    $q .= " UNION ALL ";
    $q .= "(" . $base_i . str_replace('{tbl}', 'si', $cond_item) . $cond_cat . ")";
    $q .= " ORDER BY created_at DESC";
    $extra = array_merge($filter_item ? [$filter_item] : [], $filter_cat ? [$filter_cat] : []);
    $p = array_merge([$filter_date], $extra, [$filter_date], $extra);
}

$stmt = $pdo->prepare($q);
$stmt->execute($p);
$transactions = $stmt->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
    <h1>Transaction History</h1>
    <a href="manager_dashboard.php" class="btn">← Back to Dashboard</a>
</div>

<!-- Filters -->
<div style="background: white; border-radius: 8px; border: 1px solid #e0e0e0; margin-bottom: 20px;">
    <div style="padding: 20px;">
        <form method="GET">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px;">
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Type</label>
                    <select name="type" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px;">
                        <option value="all" <?php echo $filter_type === 'all' ? 'selected' : ''; ?>>All</option>
                        <option value="receiving" <?php echo $filter_type === 'receiving' ? 'selected' : ''; ?>>Receiving</option>
                        <option value="issuing" <?php echo $filter_type === 'issuing' ? 'selected' : ''; ?>>Issuing</option>
                    </select>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Date</label>
                    <input type="date" name="date" value="<?php echo htmlspecialchars($filter_date); ?>" required style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px;">
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Category</label>
                    <select name="cat" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px;">
                        <option value="">All Categories</option>
                        <?php foreach ($all_cats as $c): ?>
                        <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $filter_cat === $c ? 'selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label style="display: block; margin-bottom: 5px; font-weight: 500;">Item</label>
                    <select name="item_id" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 5px;">
                        <option value="0">All Items</option>
                        <?php foreach ($grouped as $cat => $items): ?>
                        <optgroup label="<?php echo htmlspecialchars($cat); ?>">
                            <?php foreach ($items as $it): ?>
                            <option value="<?php echo $it['id']; ?>" <?php echo $filter_item == $it['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($it['item_name']); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display: flex; align-items: flex-end;">
                    <button type="submit" class="btn" style="background: #007bff;">Apply Filter</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Results -->
<div style="background: white; border-radius: 8px; overflow: hidden; border: 1px solid #e0e0e0;">
    <div style="padding: 16px 20px; border-bottom: 1px solid #e0e0e0; background: #fafafa; display: flex; justify-content: space-between; align-items: center;">
        <h3 style="margin: 0;"><?php echo date('d/m/Y', strtotime($filter_date)); ?></h3>
        <span style="color: #666; font-size: 12px;"><?php echo count($transactions); ?> result<?php echo count($transactions) !== 1 ? 's' : ''; ?></span>
    </div>
    <div style="padding: 20px; overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #fafafa; border-bottom: 2px solid #e0e0e0;">
                    <th style="padding: 12px; text-align: left;">Type</th>
                    <th style="padding: 12px; text-align: left;">Item</th>
                    <th style="padding: 12px; text-align: left;">Category</th>
                    <th style="padding: 12px; text-align: left;">Qty</th>
                    <th style="padding: 12px; text-align: left;">User</th>
                    <th style="padding: 12px; text-align: left;">Time</th>
                    <th style="padding: 12px; text-align: left;">Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $t): ?>
                <tr style="border-bottom: 1px solid #e0e0e0;">
                    <td style="padding: 12px;">
                        <?php if ($t['type'] == 'Receiving'): ?>
                            <span style="background: #d4edda; color: #155724; padding: 3px 10px; border-radius: 12px; font-size: 11px;">Received</span>
                        <?php else: ?>
                            <span style="background: #f8d7da; color: #721c24; padding: 3px 10px; border-radius: 12px; font-size: 11px;">Issued</span>
                        <?php endif; ?>
                    </td>
                    <td style="padding: 12px; font-weight: 500;"><?php echo htmlspecialchars($t['item_name']); ?></td>
                    <td style="padding: 12px;">
                        <span style="padding: 3px 8px; border-radius: 12px; font-size: 11px; background: #f1f5f9;"><?php echo htmlspecialchars($t['category'] ?? 'General'); ?></span>
                    </td>
                    <td style="padding: 12px;"><?php echo $t['quantity']; ?></td>
                    <td style="padding: 12px; color: #666;"><?php echo htmlspecialchars($t['user_name']); ?></td>
                    <td style="padding: 12px; color: #666; font-size: 12px; white-space: nowrap;"><?php echo date('H:i', strtotime($t['created_at'])); ?></td>
                    <td style="padding: 12px; color: #666; font-size: 12px;">
                        <?php if ($t['type'] == 'Receiving'): ?>
                            Supplier: <?php echo htmlspecialchars($t['supplier'] ?? ''); ?>
                        <?php else: ?>
                            To: <?php echo htmlspecialchars($t['issued_to'] ?? ''); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($transactions)): ?>
                <tr>
                    <td colspan="7" style="padding: 40px; text-align: center; color: #999;">No transactions found for this date.</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
