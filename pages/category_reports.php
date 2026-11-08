<?php
// Category activity: deliveries and issues for one category over a date range, to view, print or export to Excel.
require_once __DIR__ . '/../includes/auth.php';
requirePermission('reports.view');
// Department-limited users see their department's report instead of the organisation-wide ones
if (my_scope_department() !== null) { header('Location: reports.php'); exit(); }
require_once __DIR__ . '/../includes/insights.php';

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$cat_id     = (int)($_GET['category_id'] ?? 0);
$start_date = safe_date($_GET['start_date'] ?? null, date('Y-m-01'));
$end_date   = safe_date($_GET['end_date']   ?? null, date('Y-m-d'));
if ($end_date < $start_date) [$start_date, $end_date] = [$end_date, $start_date];

$category = null;
foreach ($categories as $c) if ((int)$c['id'] === $cat_id) $category = $c;
$cat_id = $category ? (int)$category['id'] : 0;

$items = $moves = [];
$recv = $iss = ['n' => 0, 'q' => 0];
if ($category) {
    $st = $pdo->prepare("SELECT id, item_name, current_quantity, reorder_level, unit_price FROM items WHERE category_id = ? ORDER BY item_name");
    $st->execute([$cat_id]);
    $items = $st->fetchAll();

    $st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(r.quantity), 0) AS q FROM stock_receiving r JOIN items i ON i.id = r.item_id WHERE i.category_id = ? AND DATE(r.created_at) BETWEEN ? AND ?");
    $st->execute([$cat_id, $start_date, $end_date]);
    $recv = $st->fetch();
    $st = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(s.quantity), 0) AS q FROM stock_issuing s JOIN items i ON i.id = s.item_id WHERE i.category_id = ? AND DATE(s.created_at) BETWEEN ? AND ?");
    $st->execute([$cat_id, $start_date, $end_date]);
    $iss = $st->fetch();

    $st = $pdo->prepare("
        (SELECT 'Received' AS type, r.created_at, i.item_name, r.quantity, r.supplier AS party, NULL AS purpose, u.full_name AS user_name, b.name AS branch_name
         FROM stock_receiving r JOIN items i ON i.id = r.item_id LEFT JOIN users u ON u.id = r.received_by LEFT JOIN branches b ON b.id = r.branch_id
         WHERE i.category_id = ? AND DATE(r.created_at) BETWEEN ? AND ?)
        UNION ALL
        (SELECT 'Issued', s.created_at, i.item_name, s.quantity, s.issued_to, s.purpose, u.full_name, b.name
         FROM stock_issuing s JOIN items i ON i.id = s.item_id LEFT JOIN users u ON u.id = s.issued_by LEFT JOIN branches b ON b.id = s.branch_id
         WHERE i.category_id = ? AND DATE(s.created_at) BETWEEN ? AND ?)
        ORDER BY created_at DESC LIMIT 1000");
    $st->execute([$cat_id, $start_date, $end_date, $cat_id, $start_date, $end_date]);
    $moves = $st->fetchAll();
}
$state = fn($i) => $i['current_quantity'] <= 0 ? 'Out of stock' : ($i['current_quantity'] <= $i['reorder_level'] ? 'Below reorder' : 'OK');
$period = date('d M Y', strtotime($start_date)) . ' to ' . date('d M Y', strtotime($end_date));

if (wants_xlsx() && $category) {
    $title = "{$category['name']} - category activity";
    xlsx_download("{$category['name']} activity $start_date to $end_date", [
        ['name' => 'Summary', 'title' => $title, 'meta' => ["Period: $period", 'Exported: ' . date('d M Y, H:i')],
         'columns' => ['Measure', ['Value', 'int']],
         'rows' => [['Items in category', count($items)], ['Units received', (int)$recv['q']], ['Units issued', (int)$iss['q']],
                    ['Net movement', (int)$recv['q'] - (int)$iss['q']], ['Deliveries', (int)$recv['n']], ['Issues', (int)$iss['n']]]],
        ['name' => 'Stock now', 'title' => "{$category['name']} - stock on " . date('d M Y'),
         'columns' => ['Item', ['In stock', 'int'], ['Reorder at', 'int'], 'Status', ['Unit price (K)', 'money'], ['Value (K)', 'money']],
         'rows' => array_map(fn($i) => [$i['item_name'], $i['current_quantity'], $i['reorder_level'], $state($i), $i['unit_price'], $i['current_quantity'] * $i['unit_price']], $items),
         'totals' => ['Total', array_sum(array_column($items, 'current_quantity')), '', '', '', array_sum(array_map(fn($i) => $i['current_quantity'] * $i['unit_price'], $items))]],
        ['name' => 'Activity', 'title' => "$title - $period",
         'columns' => ['Date', 'Type', 'Item', 'Branch', ['Qty', 'int'], 'Supplier / issued to', 'Purpose', 'User'],
         'rows' => array_map(fn($m) => [date('d M Y, H:i', strtotime($m['created_at'])), $m['type'], $m['item_name'], $m['branch_name'] ?? '', $m['quantity'], $m['party'] ?? '', $m['purpose'] ?? '', $m['user_name'] ?? ''], $moves)],
    ]);
}

$page_url = fn(array $p) => 'category_reports.php?' . http_build_query(array_filter($p + ['category_id' => $cat_id, 'start_date' => $start_date, 'end_date' => $end_date]));
include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Category activity<?php echo $category ? ' &mdash; ' . htmlspecialchars($category['name']) : ''; ?></h1>
    <div style="display:flex; gap:8px; flex-wrap:wrap">
        <?php if ($category): ?>
        <?php echo xlsx_button(); ?>
        <button type="button" onclick="window.print()" class="btn btn-sm">Print</button>
        <?php endif; ?>
        <a href="reports.php" class="btn btn-sm">Monthly Reports</a>
    </div>
</div>

<form method="GET" class="card filter-form" style="margin-bottom:14px">
    <?php if ($cat_id): ?><input type="hidden" name="category_id" value="<?php echo $cat_id; ?>"><?php endif; ?>
    <div class="form-group"><label for="start_date">From</label><input type="date" id="start_date" name="start_date" value="<?php echo $start_date; ?>" max="<?php echo date('Y-m-d'); ?>"></div>
    <div class="form-group"><label for="end_date">To</label><input type="date" id="end_date" name="end_date" value="<?php echo $end_date; ?>" max="<?php echo date('Y-m-d'); ?>"></div>
    <div class="form-group" style="display:flex;align-items:flex-end"><button type="submit" class="btn btn-primary">Show</button></div>
</form>

<div class="pill-tabs pill-tabs-inline">
    <?php foreach ($categories as $c): ?>
    <a href="<?php echo htmlspecialchars($page_url(['category_id' => (int)$c['id']])); ?>" class="pill-tab <?php echo $cat_id === (int)$c['id'] ? 'active' : ''; ?>"><?php echo htmlspecialchars($c['name']); ?></a>
    <?php endforeach; ?>
</div>

<?php if (!$category): ?>
<div class="empty-state card"><p>Choose a category above to see its deliveries and issues.</p></div>
<?php else: ?>

<div class="kpi-row">
    <div class="kpi"><div class="kpi-label">Items in category</div><div class="kpi-value"><?php echo count($items); ?></div></div>
    <div class="kpi"><div class="kpi-label">Received</div><div class="kpi-value"><?php echo number_format($recv['q']); ?> <span class="kpi-unit">units</span></div></div>
    <div class="kpi"><div class="kpi-label">Issued</div><div class="kpi-value"><?php echo number_format($iss['q']); ?> <span class="kpi-unit">units</span></div></div>
    <div class="kpi"><div class="kpi-label">Net movement</div><div class="kpi-value"><?php $net = $recv['q'] - $iss['q']; echo ($net > 0 ? '+' : '') . number_format($net); ?></div></div>
</div>

<div class="card">
    <div class="card-head"><h3>Stock now</h3><span class="card-hint"><?php echo date('d M Y'); ?></span></div>
    <?php if ($items): ?>
    <div class="table-container">
        <table>
            <thead><tr><th>Item</th><th class="num">In stock</th><th class="num">Reorder at</th><th>Status</th><th class="num">Unit price</th><th class="num">Value</th></tr></thead>
            <tbody>
            <?php foreach ($items as $i): $s = $state($i); ?>
                <tr>
                    <td><a class="item-link" href="stock_item.php?id=<?php echo $i['id']; ?>"><?php echo htmlspecialchars($i['item_name']); ?></a></td>
                    <td class="num"><strong><?php echo number_format($i['current_quantity']); ?></strong></td>
                    <td class="num muted"><?php echo number_format($i['reorder_level']); ?></td>
                    <td><span class="<?php echo $s === 'OK' ? 'status-receiving' : ($s === 'Out of stock' ? 'status-issuing' : 'status-pending'); ?>"><?php echo $s; ?></span></td>
                    <td class="num muted"><?php echo kwacha((float)$i['unit_price']); ?></td>
                    <td class="num"><?php echo kwacha($i['current_quantity'] * $i['unit_price']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><p>There are no items in this category.</p></div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head"><h3>Activity &mdash; <?php echo $period; ?></h3><span class="card-hint"><?php echo count($moves) === 1000 ? 'Latest 1,000 movements' : count($moves) . ' movement' . (count($moves) === 1 ? '' : 's'); ?></span></div>
    <?php if ($moves): ?>
    <div class="table-container">
        <table class="compact">
            <thead><tr><th>Date</th><th>Type</th><th>Item</th><th>Branch</th><th class="num">Qty</th><th>Supplier / issued to</th><th>Purpose</th><th>User</th></tr></thead>
            <tbody>
            <?php foreach ($moves as $m): ?>
                <tr>
                    <td class="muted" style="white-space:nowrap"><?php echo date('d M Y, H:i', strtotime($m['created_at'])); ?></td>
                    <td><span class="status-<?php echo $m['type'] === 'Received' ? 'receiving' : 'issuing'; ?>"><?php echo $m['type']; ?></span></td>
                    <td style="font-weight:500"><?php echo htmlspecialchars($m['item_name']); ?></td>
                    <td class="muted"><?php echo htmlspecialchars($m['branch_name'] ?? '—'); ?></td>
                    <td class="num"><?php echo number_format($m['quantity']); ?></td>
                    <td><?php echo htmlspecialchars($m['party'] ?: '—'); ?></td>
                    <td class="muted"><?php echo htmlspecialchars($m['purpose'] ?: '—'); ?></td>
                    <td class="muted"><?php echo htmlspecialchars($m['user_name'] ?? '—'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><p>Nothing was received or issued in this category between <?php echo $period; ?>.</p></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
