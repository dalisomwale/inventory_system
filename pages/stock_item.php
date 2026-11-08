<?php
// View one stock item: details, actions and full history.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/insights.php';
requirePermission('stock.view');

$st = $pdo->prepare("SELECT i.*, COALESCE(c.name, i.category, 'Other') AS category_name
                     FROM items i LEFT JOIN categories c ON c.id = i.category_id WHERE i.id = ?");
$st->execute([(int)($_GET['id'] ?? 0)]);
$item = $st->fetch();
if (!$item) { $_SESSION['flash_error'] = 'That item no longer exists.'; header('Location: stock.php'); exit(); }
// Department-limited users only see items issued to their department, and only those issues
$scope_dept = my_scope_department();
$scoped     = $scope_dept !== null;
if ($scoped) {
    $chk = $pdo->prepare("SELECT COUNT(*) FROM stock_issuing WHERE item_id = ? AND department_id = ?");
    $chk->execute([$item['id'], $scope_dept]);
    if (!$chk->fetchColumn()) { $_SESSION['flash_error'] = "That item hasn't been issued to your department."; header('Location: stock.php'); exit(); }
}

if (!empty($_SESSION['flash']))       { $success = htmlspecialchars($_SESSION['flash']);       unset($_SESSION['flash']); }
if (!empty($_SESSION['flash_error'])) { $error   = htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); }

$f = null;
foreach (ins_item_forecast($pdo, 30) as $r) { if ((int)$r['id'] === (int)$item['id']) { $f = $r; break; } }
$state = $item['current_quantity'] <= 0 ? 'out' : ($item['current_quantity'] <= $item['reorder_level'] ? 'low' : 'ok');

$h = $pdo->prepare($scoped ? "
    SELECT 'Issued' AS type, s.created_at, s.quantity, s.issued_to AS party, s.old_stock, s.new_stock, u.full_name AS by_name, s.purpose, b.name AS branch_name
    FROM stock_issuing s LEFT JOIN users u ON u.id = s.issued_by LEFT JOIN branches b ON b.id = s.branch_id
    WHERE s.item_id = ? AND s.department_id = " . (int)$scope_dept . " ORDER BY s.created_at DESC LIMIT 100" : "
    (SELECT 'Received' AS type, r.created_at, r.quantity, r.supplier AS party, r.old_stock, r.new_stock, u.full_name AS by_name, NULL AS purpose, b.name AS branch_name
     FROM stock_receiving r LEFT JOIN users u ON u.id = r.received_by LEFT JOIN branches b ON b.id = r.branch_id WHERE r.item_id = ?)
    UNION ALL
    (SELECT 'Issued', s.created_at, s.quantity, s.issued_to, s.old_stock, s.new_stock, u.full_name, s.purpose, b.name
     FROM stock_issuing s LEFT JOIN users u ON u.id = s.issued_by LEFT JOIN branches b ON b.id = s.branch_id WHERE s.item_id = ?)
    ORDER BY created_at DESC LIMIT 100");
$h->execute($scoped ? [$item['id']] : [$item['id'], $item['id']]);
$history     = $h->fetchAll();
$has_history = count($history) > 0;
$received    = array_sum(array_map(fn($r) => $r['type'] === 'Received' ? $r['quantity'] : 0, $history));
$issued      = array_sum(array_map(fn($r) => $r['type'] === 'Issued'   ? $r['quantity'] : 0, $history));



$breadcrumbs = [[$scoped ? 'Stock' : 'Manage Stock', 'stock.php'], [$item['item_name'], null]];
if (wants_xlsx()) {
    xlsx_download("Stock history {$item['item_name']} " . date('Y-m-d'), [[
        'name'    => 'Stock history',
        'title'   => "Stock history - {$item['item_name']}",
        'meta'    => array_filter(['Category: ' . $item['category_name'], $scoped ? 'Department: ' . department_name($scope_dept) : '',
                                   count($history) === 100 ? 'Latest 100 movements' : '', 'Exported: ' . date('d M Y, H:i')]),
        'columns' => ['Date', 'Item', 'Type', ['Qty', 'int'], 'From', 'To', 'Purpose', 'By'],
        'rows'    => array_map(fn($r) => [date('d M Y, H:i', strtotime($r['created_at'])), $item['item_name'], $r['type'], ($r['type'] === 'Issued' ? -1 : 1) * (int)$r['quantity'],
                                          $r['type'] === 'Issued' ? ($r['branch_name'] ?? '') : ($r['party'] ?? ''),
                                          $r['type'] === 'Issued' ? ($r['party'] ?? '') : ($r['branch_name'] ?? ''),
                                          $r['purpose'] ?? '', $r['by_name'] ?? ''], $history),
    ]]);
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 style="margin-bottom:4px"><?php echo htmlspecialchars($item['item_name']); ?></h1>
        <span class="cat-pill"><?php echo htmlspecialchars($item['category_name']); ?></span>
        <?php if ($scoped): ?>
        <?php elseif ($state === 'out'): ?><span class="status-issuing">&#9888; Out of stock</span>
        <?php elseif ($state === 'low'): ?><span class="status-pending">&#9888; Below reorder level</span>
        <?php else: ?><span class="status-receiving">&#10003; In stock</span><?php endif; ?>
    </div>
    <?php if (can('items.manage')): ?>
    <div style="display:flex; gap:8px; flex-wrap:wrap">
        <a href="stock_edit.php?id=<?php echo $item['id']; ?>" class="btn">Edit</a>
        <?php if (!$has_history): ?>
        <form method="POST" action="stock_edit.php" class="inline-form"
              data-confirm-title="Delete item"
              data-confirm="Delete <?php echo htmlspecialchars($item['item_name']); ?>? This cannot be undone."
              data-confirm-ok="Delete" data-confirm-danger="1">
            <input type="hidden" name="id" value="<?php echo $item['id']; ?>">
            <button type="submit" name="delete_item" value="1" class="btn btn-danger-outline">Delete</button>
        </form>
        <?php else: ?>
        <span class="btn" aria-disabled="true" data-tip="Items with stock history can't be deleted">Delete</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
<?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<!-- Details -->
<div class="card item-summary">
    <section>
        <h3 class="item-summary-title"><?php echo $scoped ? 'Issued to ' . htmlspecialchars(department_name($scope_dept)) : 'Stock'; ?></h3>
        <?php if ($scoped): ?>
        <dl class="detail-list">
            <div><dt>Total issued</dt><dd class="big"><?php echo number_format($issued); ?></dd></div>
            <div><dt>Times issued</dt><dd><?php echo number_format(count($history)); ?></dd></div>
            <div><dt>Last issued</dt><dd><?php echo $history ? date('d M Y', strtotime($history[0]['created_at'])) : '—'; ?></dd></div>
            <div><dt>Value issued</dt><dd><?php echo kwacha($issued * (float)$item['unit_price']); ?></dd></div>
        </dl>
        <?php else: ?>
        <dl class="detail-list">
            <div><dt>In stock</dt><dd class="big qty-<?php echo $state; ?>"><?php echo number_format($item['current_quantity']); ?></dd></div>
            <div><dt>Reorder level</dt><dd><?php echo number_format($item['reorder_level']); ?></dd></div>
            <div><dt>Days left</dt><dd><?php echo days_left_label($f['days_left'] ?? null, (int)$item['current_quantity']); ?></dd></div>
            <div><dt>Used per week</dt><dd><?php echo !empty($f['per_day']) ? number_format($f['per_day'] * 7, 1) : '—'; ?></dd></div>
            <div><dt>Total received</dt><dd><?php echo number_format($received); ?></dd></div>
            <div><dt>Total issued</dt><dd><?php echo number_format($issued); ?></dd></div>
        </dl>
        <?php endif; ?>
    </section>
    <section>
        <h3 class="item-summary-title">Details</h3>
        <dl class="detail-list">
            <div><dt>Unit price</dt><dd><?php echo kwacha((float)$item['unit_price']); ?></dd></div>
            <?php if (!$scoped): ?><div><dt>Stock value</dt><dd><?php echo kwacha($item['current_quantity'] * $item['unit_price']); ?></dd></div><?php endif; ?>
            <div><dt>Date added</dt><dd><?php echo date('d M Y', strtotime($item['created_at'])); ?></dd></div>
            <div><dt>Last updated</dt><dd><?php echo $item['updated_at'] ? date('d M Y, H:i', strtotime($item['updated_at'])) : '—'; ?></dd></div>
            <div class="wide"><dt>Description</dt><dd><?php echo $item['description'] ? nl2br(htmlspecialchars($item['description'])) : '—'; ?></dd></div>
        </dl>
    </section>
</div>

<!-- History -->
<div class="card">
    <div class="card-head"><h3>Stock history &mdash; <?php echo htmlspecialchars($item['item_name']); ?></h3><div style="display:flex; align-items:center; gap:12px"><?php if (count($history) === 100): ?><span class="card-hint">Latest 100 movements</span><?php endif; ?><?php if ($history) echo xlsx_button(); ?></div></div>
    <?php if ($history): ?>
    <div class="table-container">
        <table class="compact">
            <thead><tr><th>Date</th><th>Item</th><th>Type</th><th class="num">Qty</th><th>From</th><th>To</th><th>Purpose</th><th>By</th></tr></thead>
            <tbody>
            <?php foreach ($history as $r): ?>
                <tr>
                    <td class="muted" style="white-space:nowrap"><?php echo date('d M Y, H:i', strtotime($r['created_at'])); ?></td>
                    <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']); ?></td>
                    <td><span class="status-<?php echo strtolower($r['type']); ?>"><?php echo $r['type']; ?></span></td>
                    <td class="num"><strong><?php echo ($r['type'] === 'Issued' ? '−' : '+') . number_format($r['quantity']); ?></strong></td>
                    <?php // Deliveries come from a supplier into a branch; issues go from a branch to a department
                    $branch_cell = htmlspecialchars($r['branch_name'] ?? '—');
                    $party_cell  = htmlspecialchars($r['party'] ?: '—'); ?>
                    <td><?php echo $r['type'] === 'Issued' ? $branch_cell : $party_cell; ?></td>
                    <td><?php echo $r['type'] === 'Issued' ? $party_cell : $branch_cell; ?></td>
                    <td class="muted"><?php echo htmlspecialchars($r['purpose'] ?: '—'); ?></td>
                    <td class="muted"><?php echo htmlspecialchars($r['by_name'] ?? '—'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><p>No stock has been received or issued for this item yet.</p></div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
