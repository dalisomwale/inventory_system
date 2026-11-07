<?php
// Manage Stock — every item's stock level in one place, for both roles.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/insights.php';
redirectIfNotLoggedIn();

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

// ── Filters (all from the query string, all sanitised) ──────
$q       = is_string($_GET['q'] ?? null) ? trim(mb_substr($_GET['q'], 0, 100)) : '';
$cat_id  = (int)($_GET['category_id'] ?? 0);
$status  = in_array($_GET['status'] ?? '', ['ok', 'low', 'out', 'soon'], true) ? $_GET['status'] : '';
$sorts   = ['name' => 'Name', 'stock' => 'Stock (lowest first)', 'days' => 'Days left', 'value' => 'Value (highest first)', 'recent' => 'Last movement'];
$sort    = array_key_exists($_GET['sort'] ?? '', $sorts) ? $_GET['sort'] : 'name';

// ── Data ────────────────────────────────────────────────────
$forecast = [];
foreach (ins_item_forecast($pdo, 30) as $r) $forecast[$r['id']] = $r;

$st = $pdo->query("
    SELECT i.id, i.item_name, i.description, i.category_id, COALESCE(c.name, i.category, 'Other') AS category_name,
           i.current_quantity, i.reorder_level, i.unit_price,
           GREATEST(COALESCE((SELECT MAX(created_at) FROM stock_receiving WHERE item_id = i.id), '1970-01-01'),
                    COALESCE((SELECT MAX(created_at) FROM stock_issuing   WHERE item_id = i.id), '1970-01-01')) AS last_move
    FROM items i LEFT JOIN categories c ON c.id = i.category_id");
$all = $st->fetchAll();

$rows = [];
foreach ($all as $r) {
    $f = $forecast[$r['id']] ?? ['days_left' => null, 'per_day' => 0, 'suggest' => 0];
    $r['days_left'] = $f['days_left'];
    $r['per_day']   = $f['per_day'];
    $r['suggest']   = $f['suggest'];
    $r['value']     = $r['current_quantity'] * $r['unit_price'];
    $r['state']     = $r['current_quantity'] <= 0 ? 'out' : ($r['current_quantity'] <= $r['reorder_level'] ? 'low' : 'ok');
    $r['last_move'] = $r['last_move'] === '1970-01-01 00:00:00' || $r['last_move'] === '1970-01-01' ? null : $r['last_move'];
    $rows[] = $r;
}

// Totals across everything (before filtering)
$tot_items = count($rows);
$tot_units = array_sum(array_column($rows, 'current_quantity'));
$tot_value = array_sum(array_column($rows, 'value'));
$tot_low   = count(array_filter($rows, fn($r) => $r['state'] === 'low'));
$tot_out   = count(array_filter($rows, fn($r) => $r['state'] === 'out'));

// Apply filters
$rows = array_values(array_filter($rows, function ($r) use ($q, $cat_id, $status) {
    if ($q !== '' && mb_stripos($r['item_name'] . ' ' . $r['description'], $q) === false) return false;
    if ($cat_id && (int)$r['category_id'] !== $cat_id) return false;
    if ($status === 'soon') return $r['state'] === 'ok' && $r['days_left'] !== null && $r['days_left'] <= 14;
    if ($status && $r['state'] !== $status) return false;
    return true;
}));

usort($rows, function ($a, $b) use ($sort) {
    switch ($sort) {
        case 'stock':  return $a['current_quantity'] <=> $b['current_quantity'] ?: strcasecmp($a['item_name'], $b['item_name']);
        case 'days':   return ($a['current_quantity'] <= 0 ? -1 : ($a['days_left'] ?? PHP_INT_MAX)) <=> ($b['current_quantity'] <= 0 ? -1 : ($b['days_left'] ?? PHP_INT_MAX));
        case 'value':  return $b['value'] <=> $a['value'];
        case 'recent': return strcmp($b['last_move'] ?? '', $a['last_move'] ?? '');
        default:       return strcasecmp($a['item_name'], $b['item_name']);
    }
});

// ── CSV export of what's on screen ──────────────────────────
if (($_GET['export'] ?? '') === 'csv') {
    $name = 'altus-stock-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads the text correctly
    fputcsv($out, ['Item', 'Category', 'In stock', 'Reorder level', 'Status', 'Unit price (K)', 'Value (K)', 'Used per week', 'Days left', 'Suggested order', 'Last movement']);
    foreach ($rows as $r) {
        $cell = fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : $v; // stop formula injection in Excel
        fputcsv($out, [
            $cell($r['item_name']), $cell($r['category_name']), $r['current_quantity'], $r['reorder_level'],
            ['ok' => 'In stock', 'low' => 'Below reorder', 'out' => 'Out of stock'][$r['state']],
            number_format($r['unit_price'], 2, '.', ''), number_format($r['value'], 2, '.', ''),
            $r['per_day'] > 0 ? round($r['per_day'] * 7, 1) : '', $r['current_quantity'] > 0 ? ($r['days_left'] ?? '') : 0,
            $r['suggest'], $r['last_move'] ? date('Y-m-d H:i', strtotime($r['last_move'])) : '',
        ]);
    }
    fclose($out);
    exit();
}

// ── Item history panel ──────────────────────────────────────
$history_item = null; $history = [];
if (!empty($_GET['history'])) {
    $hs = $pdo->prepare("SELECT id, item_name, current_quantity FROM items WHERE id = ?");
    $hs->execute([(int)$_GET['history']]);
    $history_item = $hs->fetch();
    if ($history_item) {
        $h = $pdo->prepare("
            (SELECT 'Received' AS type, r.created_at, r.quantity, r.supplier AS party, r.old_stock, r.new_stock, u.full_name AS by_name
             FROM stock_receiving r LEFT JOIN users u ON u.id = r.received_by WHERE r.item_id = ?)
            UNION ALL
            (SELECT 'Issued', s.created_at, s.quantity, s.issued_to, s.old_stock, s.new_stock, u.full_name
             FROM stock_issuing s LEFT JOIN users u ON u.id = s.issued_by WHERE s.item_id = ?)
            ORDER BY created_at DESC LIMIT 15");
        $h->execute([$history_item['id'], $history_item['id']]);
        $history = $h->fetchAll();
    }
}

// Keep current filters on links
function stock_url(array $change = []): string {
    $keep = array_intersect_key($_GET, array_flip(['q', 'category_id', 'status', 'sort']));
    $params = array_filter(array_merge($keep, $change), fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0');
    return 'stock.php' . ($params ? '?' . http_build_query($params) : '');
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Manage Stock</h1>
    <div style="display:flex; gap:8px; flex-wrap:wrap">
        <a href="<?php echo htmlspecialchars(stock_url(['export' => 'csv'])); ?>" class="btn">Export to Excel (CSV)</a>
        <a href="manage_items.php" class="btn">Add or edit items</a>
    </div>
</div>

<!-- Big actions -->
<div class="action-tiles">
    <?php echo action_tile('receive_stock.php', 'Receive stock', 'Record a delivery', icon('receive')); ?>
    <?php echo action_tile('issue_stock.php', 'Issue stock', 'Give items to a department', icon('issue')); ?>
    <?php echo action_tile('requisitions.php', 'Requisitions', 'What needs buying', icon('request')); ?>
</div>

<!-- Totals; each one is also a quick filter -->
<div class="stock-summary">
    <a href="<?php echo htmlspecialchars(stock_url(['status' => ''])); ?>" class="ss <?php echo $status === '' ? 'active' : ''; ?>">
        <span class="ss-num"><?php echo number_format($tot_items); ?></span><span class="ss-lbl">All items</span></a>
    <a href="<?php echo htmlspecialchars(stock_url(['status' => 'ok'])); ?>" class="ss <?php echo $status === 'ok' ? 'active' : ''; ?>">
        <span class="ss-num"><?php echo number_format($tot_items - $tot_low - $tot_out); ?></span><span class="ss-lbl">In stock</span></a>
    <a href="<?php echo htmlspecialchars(stock_url(['status' => 'soon'])); ?>" class="ss <?php echo $status === 'soon' ? 'active' : ''; ?>">
        <span class="ss-num"><?php echo count(array_filter(array_values($forecast), fn($r) => $r['current_quantity'] > $r['reorder_level'] && $r['days_left'] !== null && $r['days_left'] <= 14)); ?></span><span class="ss-lbl">Running out in 2 weeks</span></a>
    <a href="<?php echo htmlspecialchars(stock_url(['status' => 'low'])); ?>" class="ss warn <?php echo $status === 'low' ? 'active' : ''; ?>">
        <span class="ss-num"><?php echo number_format($tot_low); ?></span><span class="ss-lbl">Below reorder</span></a>
    <a href="<?php echo htmlspecialchars(stock_url(['status' => 'out'])); ?>" class="ss bad <?php echo $status === 'out' ? 'active' : ''; ?>">
        <span class="ss-num"><?php echo number_format($tot_out); ?></span><span class="ss-lbl">Out of stock</span></a>
    <div class="ss static">
        <span class="ss-num"><?php echo kwacha_whole($tot_value); ?></span><span class="ss-lbl"><?php echo number_format($tot_units); ?> units in total</span></div>
</div>

<!-- Search and filters -->
<form method="GET" class="card stock-filters">
    <div class="form-group grow">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Item name or description" maxlength="100">
    </div>
    <div class="form-group">
        <label for="category_id">Category</label>
        <select id="category_id" name="category_id">
            <option value="">All categories</option>
            <?php foreach ($categories as $c): ?>
            <option value="<?php echo $c['id']; ?>" <?php echo $cat_id === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group">
        <label for="status">Status</label>
        <select id="status" name="status">
            <option value="">Any status</option>
            <option value="ok"   <?php echo $status === 'ok'   ? 'selected' : ''; ?>>In stock</option>
            <option value="soon" <?php echo $status === 'soon' ? 'selected' : ''; ?>>Running out in 2 weeks</option>
            <option value="low"  <?php echo $status === 'low'  ? 'selected' : ''; ?>>Below reorder</option>
            <option value="out"  <?php echo $status === 'out'  ? 'selected' : ''; ?>>Out of stock</option>
        </select>
    </div>
    <div class="form-group">
        <label for="sort">Sort by</label>
        <select id="sort" name="sort">
            <?php foreach ($sorts as $k => $label): ?>
            <option value="<?php echo $k; ?>" <?php echo $sort === $k ? 'selected' : ''; ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="form-group filter-buttons">
        <button type="submit" class="btn btn-primary">Apply</button>
        <?php if ($q !== '' || $cat_id || $status || $sort !== 'name'): ?><a href="stock.php" class="btn">Clear</a><?php endif; ?>
    </div>
</form>

<?php if ($history_item): ?>
<!-- Item history -->
<div class="card history-card" id="history">
    <div class="card-head">
        <h3>History &mdash; <?php echo htmlspecialchars($history_item['item_name']); ?> <span class="card-hint">(<?php echo number_format($history_item['current_quantity']); ?> in stock now)</span></h3>
        <a href="<?php echo htmlspecialchars(stock_url()); ?>" class="btn btn-sm">Close</a>
    </div>
    <?php if ($history): ?>
    <table class="compact">
        <thead><tr><th>Date</th><th>Type</th><th class="num">Qty</th><th class="num">Stock before &rarr; after</th><th>From / to</th><th>By</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
            <tr>
                <td class="muted" style="white-space:nowrap"><?php echo date('d M Y, H:i', strtotime($h['created_at'])); ?></td>
                <td><span class="status-<?php echo strtolower($h['type']); ?>"><?php echo $h['type']; ?></span></td>
                <td class="num"><strong><?php echo ($h['type'] === 'Issued' ? '−' : '+') . number_format($h['quantity']); ?></strong></td>
                <td class="num muted"><?php echo $h['old_stock'] !== null ? number_format($h['old_stock']) . ' &rarr; ' . number_format($h['new_stock']) : '&mdash;'; ?></td>
                <td><?php echo htmlspecialchars($h['party'] ?: '—'); ?></td>
                <td class="muted"><?php echo htmlspecialchars($h['by_name'] ?? '—'); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state"><p>No stock has been received or issued for this item yet.</p></div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Stock table -->
<div class="card">
    <div class="card-head">
        <h3>
            <?php echo count($rows) === $tot_items ? 'All items' : 'Showing ' . count($rows) . ' of ' . $tot_items . ' items'; ?>
        </h3>
        <span class="card-hint">Click an item to see its history &middot; Value <?php echo kwacha_whole(array_sum(array_column($rows, 'value'))); ?> &middot; <?php echo number_format(array_sum(array_column($rows, 'current_quantity'))); ?> units</span>
    </div>

    <?php if ($rows): ?>
    <div class="table-container">
        <table class="stock-table">
            <thead>
                <tr>
                    <th>Item</th><th>Category</th><th class="num">In stock</th><th class="col-level">Level</th><th class="num">Reorder at</th>
                    <th>Days left</th><th class="num">Value</th><th class="col-last">Last movement</th><th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r):
                $cap  = max($r['reorder_level'] * 2, $r['current_quantity'], 1);
                $fill = min(100, round($r['current_quantity'] / $cap * 100));
                $mark = min(100, round($r['reorder_level'] / $cap * 100));
                $urgent = $r['state'] === 'out' || ($r['days_left'] !== null && $r['days_left'] < 7);
            ?>
                <tr class="row-<?php echo $r['state']; ?>">
                    <td>
                        <a class="item-link" href="<?php echo htmlspecialchars(stock_url(['history' => $r['id']])); ?>#history" data-tip="Show stock history"><?php echo htmlspecialchars($r['item_name']); ?></a>
                        <?php if ($r['description']): ?><div class="item-desc"><?php echo htmlspecialchars($r['description']); ?></div><?php endif; ?>
                    </td>
                    <td><span class="cat-pill"><?php echo htmlspecialchars($r['category_name']); ?></span></td>
                    <td class="num"><strong class="qty-<?php echo $r['state']; ?>"><?php echo number_format($r['current_quantity']); ?></strong></td>
                    <td class="col-level">
                        <div class="level" data-tip="<?php echo number_format($r['current_quantity']) . ' in stock · reorder at ' . number_format($r['reorder_level']); ?>">
                            <div class="level-fill <?php echo $r['state']; ?>" style="width:<?php echo $fill; ?>%"></div>
                            <div class="level-mark" style="left:<?php echo $mark; ?>%"></div>
                        </div>
                    </td>
                    <td class="num muted"><?php echo number_format($r['reorder_level']); ?></td>
                    <td>
                        <?php if ($r['state'] === 'out'): ?>
                            <span class="status-issuing">&#9888; Out of stock</span>
                        <?php elseif ($r['days_left'] === null): ?>
                            <span class="muted-text">Not used lately</span>
                        <?php else: ?>
                            <span class="<?php echo $urgent ? 'status-issuing' : ($r['days_left'] <= 14 || $r['state'] === 'low' ? 'status-pending' : 'muted-text'); ?>">
                                <?php echo $urgent ? '&#9888; ' : ''; ?><?php echo days_left_label($r['days_left'], (int)$r['current_quantity']); ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="num"><?php echo kwacha_whole($r['value']); ?></td>
                    <td class="muted col-last" style="white-space:nowrap"><?php echo $r['last_move'] ? date('d M Y', strtotime($r['last_move'])) : 'Never'; ?></td>
                    <td><div class="row-actions">
                        <a href="receive_stock.php?category_id=<?php echo (int)$r['category_id']; ?>&item_id=<?php echo $r['id']; ?>" class="btn btn-sm">Receive</a>
                        <?php if ($r['current_quantity'] > 0): ?>
                        <a href="issue_stock.php?category_id=<?php echo (int)$r['category_id']; ?>&item_id=<?php echo $r['id']; ?>" class="btn btn-sm">Issue</a>
                        <?php else: ?>
                        <span class="btn btn-sm" aria-disabled="true" data-tip="Nothing in stock to issue">Issue</span>
                        <?php endif; ?>
                        <?php if ($r['state'] !== 'ok'): ?>
                        <a href="requisitions.php?item_name=<?php echo urlencode($r['item_name']); ?>&qty=<?php echo max(1, $r['suggest']); ?>&category_id=<?php echo (int)$r['category_id']; ?>" class="btn btn-sm" data-tip="Raise a requisition to buy more">Requisition</a>
                        <?php endif; ?>
                    </div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php elseif ($tot_items === 0): ?>
    <div class="empty-state">
        <p>There are no items yet.</p>
        <a href="manage_items.php" class="btn btn-primary">Add your first item</a>
    </div>
    <?php else: ?>
    <div class="empty-state"><p>No items match these filters.</p><a href="stock.php" class="btn">Clear filters</a></div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
