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

if (!empty($_SESSION['flash']))       { $success = htmlspecialchars($_SESSION['flash']);       unset($_SESSION['flash']); }
if (!empty($_SESSION['flash_error'])) { $error   = htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); }

$f = null;
foreach (ins_item_forecast($pdo, 30) as $r) { if ((int)$r['id'] === (int)$item['id']) { $f = $r; break; } }
$state = $item['current_quantity'] <= 0 ? 'out' : ($item['current_quantity'] <= $item['reorder_level'] ? 'low' : 'ok');

$h = $pdo->prepare("
    (SELECT 'Received' AS type, r.created_at, r.quantity, r.supplier AS party, r.old_stock, r.new_stock, u.full_name AS by_name, NULL AS purpose, b.name AS branch_name
     FROM stock_receiving r LEFT JOIN users u ON u.id = r.received_by LEFT JOIN branches b ON b.id = r.branch_id WHERE r.item_id = ?)
    UNION ALL
    (SELECT 'Issued', s.created_at, s.quantity, CONCAT(s.issued_to, IF(s.recipient IS NULL OR s.recipient = '', '', CONCAT(' (', s.recipient, ')'))), s.old_stock, s.new_stock, u.full_name, s.purpose, b.name
     FROM stock_issuing s LEFT JOIN users u ON u.id = s.issued_by LEFT JOIN branches b ON b.id = s.branch_id WHERE s.item_id = ?)
    ORDER BY created_at DESC LIMIT 100");
$h->execute([$item['id'], $item['id']]);
$history     = $h->fetchAll();
$has_history = count($history) > 0;
$received    = array_sum(array_map(fn($r) => $r['type'] === 'Received' ? $r['quantity'] : 0, $history));
$issued      = array_sum(array_map(fn($r) => $r['type'] === 'Issued'   ? $r['quantity'] : 0, $history));

$by_branch = $pdo->prepare("
    SELECT b.id, b.name, c.name AS company_name, COALESCE(bs.quantity, 0) AS quantity
    FROM branches b JOIN companies c ON c.id = b.company_id
    LEFT JOIN branch_stock bs ON bs.branch_id = b.id AND bs.item_id = ?
    WHERE (b.active = 1 AND c.active = 1) OR COALESCE(bs.quantity, 0) <> 0
    ORDER BY c.name, b.name");
$by_branch->execute([$item['id']]);
$by_branch = $by_branch->fetchAll();

$open_req = $pdo->prepare("SELECT * FROM procurement WHERE (item_id = ? OR LOWER(item_name) = LOWER(?)) AND status IN ('needed','ordered') ORDER BY created_at DESC LIMIT 1");
$open_req->execute([$item['id'], $item['item_name']]);
$open_req = $open_req->fetch();

$breadcrumbs = [['Manage Stock', 'stock.php'], [$item['item_name'], null]];
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1 style="margin-bottom:4px"><?php echo htmlspecialchars($item['item_name']); ?></h1>
        <span class="cat-pill"><?php echo htmlspecialchars($item['category_name']); ?></span>
        <?php if ($state === 'out'): ?><span class="status-issuing">&#9888; Out of stock</span>
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

<!-- Actions -->
<div class="action-tiles">
    <?php if (can('stock.receive')): ?><?php echo action_tile('receive_stock.php?category_id=' . (int)$item['category_id'] . '&item_id=' . $item['id'], 'Receive', 'Add stock that arrived', icon('receive')); ?><?php endif; ?>
    <?php if ($item['current_quantity'] > 0 && can('stock.issue')): ?>
        <?php echo action_tile('issue_stock.php?category_id=' . (int)$item['category_id'] . '&item_id=' . $item['id'], 'Issue', 'Give out to a department', icon('issue')); ?>
    <?php endif; ?>
    <?php if ($open_req && can('requisitions.view')): ?>
        <?php echo action_tile('requisitions.php', 'Requisition open', $open_req['quantity'] . ' ' . ($open_req['status'] === 'ordered' ? 'ordered' : 'to order'), icon('request')); ?>
    <?php elseif (!$open_req && can('requisitions.manage')): ?>
        <?php echo action_tile('requisitions.php?item_name=' . urlencode($item['item_name']) . '&qty=' . max(1, (int)($f['suggest'] ?? 1)) . '&category_id=' . (int)$item['category_id'], 'Raise requisition', 'Order more of this item', icon('request')); ?>
    <?php endif; ?>
</div>

<!-- Details -->
<div class="grid-2">
    <div class="card">
        <div class="card-head"><h3>Stock</h3></div>
        <dl class="detail-list">
            <div><dt>In stock</dt><dd class="big qty-<?php echo $state; ?>"><?php echo number_format($item['current_quantity']); ?></dd></div>
            <div><dt>Reorder level</dt><dd><?php echo number_format($item['reorder_level']); ?></dd></div>
            <div><dt>Days left</dt><dd><?php echo days_left_label($f['days_left'] ?? null, (int)$item['current_quantity']); ?></dd></div>
            <div><dt>Used per week</dt><dd><?php echo !empty($f['per_day']) ? number_format($f['per_day'] * 7, 1) : '—'; ?></dd></div>
            <div><dt>Total received</dt><dd><?php echo number_format($received); ?></dd></div>
            <div><dt>Total issued</dt><dd><?php echo number_format($issued); ?></dd></div>
        </dl>
    </div>
    <div class="card">
        <div class="card-head"><h3>Details</h3></div>
        <dl class="detail-list">
            <div><dt>Unit price</dt><dd><?php echo kwacha((float)$item['unit_price']); ?></dd></div>
            <div><dt>Stock value</dt><dd><?php echo kwacha($item['current_quantity'] * $item['unit_price']); ?></dd></div>
            <div><dt>Date added</dt><dd><?php echo date('d M Y', strtotime($item['created_at'])); ?></dd></div>
            <div><dt>Last updated</dt><dd><?php echo $item['updated_at'] ? date('d M Y, H:i', strtotime($item['updated_at'])) : '—'; ?></dd></div>
            <div class="wide"><dt>Description</dt><dd><?php echo $item['description'] ? nl2br(htmlspecialchars($item['description'])) : '—'; ?></dd></div>
        </dl>
    </div>
</div>

<?php if (count($by_branch) > 1): ?>
<!-- Stock by branch -->
<div class="card">
    <div class="card-head"><h3>Stock by branch</h3><span class="card-hint">Total <?php echo number_format($item['current_quantity']); ?></span></div>
    <table class="compact">
        <thead><tr><th>Branch</th><th>Company</th><th class="num">In stock</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($by_branch as $bb): ?>
            <tr>
                <td style="font-weight:600"><?php echo htmlspecialchars($bb['name']); ?></td>
                <td class="muted"><?php echo htmlspecialchars($bb['company_name']); ?></td>
                <td class="num"><strong class="qty-<?php echo $bb['quantity'] <= 0 ? 'out' : ($bb['quantity'] <= $item['reorder_level'] ? 'low' : 'ok'); ?>"><?php echo number_format($bb['quantity']); ?></strong></td>
                <td><div class="row-actions">
                    <?php if (can('stock.receive')): ?><a href="receive_stock.php?category_id=<?php echo (int)$item['category_id']; ?>&item_id=<?php echo $item['id']; ?>&branch_id=<?php echo $bb['id']; ?>" class="btn btn-sm">Receive</a><?php endif; ?>
                    <?php if ($bb['quantity'] > 0 && can('stock.issue')): ?><a href="issue_stock.php?category_id=<?php echo (int)$item['category_id']; ?>&item_id=<?php echo $item['id']; ?>&branch_id=<?php echo $bb['id']; ?>" class="btn btn-sm">Issue</a><?php endif; ?>
                </div></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- History -->
<div class="card">
    <div class="card-head"><h3>Stock history</h3><?php if (count($history) === 100): ?><span class="card-hint">Latest 100 movements</span><?php endif; ?></div>
    <?php if ($history): ?>
    <div class="table-container">
        <table class="compact">
            <thead><tr><th>Date</th><th>Type</th><th>Branch</th><th class="num">Qty</th><th class="num">Branch stock before &rarr; after</th><th>From / to</th><th>Purpose</th><th>By</th></tr></thead>
            <tbody>
            <?php foreach ($history as $r): ?>
                <tr>
                    <td class="muted" style="white-space:nowrap"><?php echo date('d M Y, H:i', strtotime($r['created_at'])); ?></td>
                    <td><span class="status-<?php echo strtolower($r['type']); ?>"><?php echo $r['type']; ?></span></td>
                    <td class="muted"><?php echo htmlspecialchars($r['branch_name'] ?? '—'); ?></td>
                    <td class="num"><strong><?php echo ($r['type'] === 'Issued' ? '−' : '+') . number_format($r['quantity']); ?></strong></td>
                    <td class="num muted"><?php echo $r['old_stock'] !== null ? number_format($r['old_stock']) . ' &rarr; ' . number_format($r['new_stock']) : '—'; ?></td>
                    <td><?php echo htmlspecialchars($r['party'] ?: '—'); ?></td>
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
