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
$sorts   = ['name' => 'Name', 'stock' => 'Stock (lowest first)', 'days' => 'Days left', 'value' => 'Value (highest first)', 'added' => 'Date added (newest first)'];
$sort    = array_key_exists($_GET['sort'] ?? '', $sorts) ? $_GET['sort'] : 'name';
$branches = org_branches($pdo);
$branch_id = (int)($_GET['branch_id'] ?? 0);
if (!in_array($branch_id, array_map(fn($b) => (int)$b['id'], $branches), true)) $branch_id = 0;
$branch_name = '';
foreach ($branches as $b) if ((int)$b['id'] === $branch_id) $branch_name = $b['name'];

// ── Data ────────────────────────────────────────────────────
$forecast = [];
foreach (ins_item_forecast($pdo, 30, $branch_id) as $r) $forecast[$r['id']] = $r;

$st = $pdo->query("
    SELECT i.id, i.item_name, i.description, i.category_id, COALESCE(c.name, i.category, 'Other') AS category_name,
           i.current_quantity, i.reorder_level, i.unit_price, i.created_at,
           (SELECT COUNT(*) FROM stock_receiving WHERE item_id = i.id) + (SELECT COUNT(*) FROM stock_issuing WHERE item_id = i.id) AS moves,
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
    if ($branch_id) $r['current_quantity'] = (int)($f['current_quantity'] ?? 0); // this branch's stock
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
        case 'added':  return strcmp($b['created_at'], $a['created_at']);
        default:       return strcasecmp($a['item_name'], $b['item_name']);
    }
});

if (!empty($_SESSION['flash']))       { $success = htmlspecialchars($_SESSION['flash']);       unset($_SESSION['flash']); }
if (!empty($_SESSION['flash_error'])) { $error   = htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); }

// Keep current filters on links
function stock_url(array $change = []): string {
    $keep = array_intersect_key($_GET, array_flip(['q', 'category_id', 'status', 'sort', 'branch_id']));
    $params = array_filter(array_merge($keep, $change), fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0');
    return 'stock.php' . ($params ? '?' . http_build_query($params) : '');
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Manage Stock</h1>
</div>

<?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
<?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<!-- Big actions -->
<div class="action-tiles">
    <?php echo action_tile('receive_stock.php', 'Receive stock', 'Record a delivery', icon('receive')); ?>
    <?php echo action_tile('issue_stock.php', 'Issue stock', 'Give items to a department', icon('issue')); ?>
    <?php echo action_tile('requisitions.php', 'Requisitions', 'What needs buying', icon('request')); ?>
    <?php echo action_tile('categories.php?add=1#add', 'Add Categories', 'Create a new category', icon('box')); ?>
</div>

<!-- Search and filters -->
<form method="GET" class="card stock-filters">
    <div class="form-group grow">
        <label for="q">Search</label>
        <input type="search" id="q" name="q" value="<?php echo htmlspecialchars($q); ?>" placeholder="Item name or description" maxlength="100">
    </div>
    <?php if (count($branches) > 1): ?>
    <div class="form-group">
        <label for="branch_id">Branch</label>
        <select id="branch_id" name="branch_id">
            <option value="">All branches</option>
            <?php foreach ($branches as $b): ?>
            <option value="<?php echo $b['id']; ?>" <?php echo $branch_id === (int)$b['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($b['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
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
        <?php if ($q !== '' || $cat_id || $status || $sort !== 'name' || $branch_id): ?><a href="stock.php" class="btn">Clear</a><?php endif; ?>
    </div>
</form>

<!-- Stock table -->
<div class="card">
    <div class="card-head">
        <h3>
            <?php echo count($rows) === $tot_items ? 'All items' : 'Showing ' . count($rows) . ' of ' . $tot_items . ' items'; ?><?php echo $branch_id ? ' at ' . htmlspecialchars($branch_name) : ''; ?>
        </h3>
        <span class="card-hint">Value <?php echo kwacha_whole(array_sum(array_column($rows, 'value'))); ?> &middot; <?php echo number_format(array_sum(array_column($rows, 'current_quantity'))); ?> units</span>
    </div>

    <?php if ($rows): ?>
    <div class="table-container">
        <table class="stock-table">
            <thead>
                <tr>
                    <th>Item</th><th>Category</th><th class="num">In stock</th><th class="col-level">Level</th><th class="num">Reorder at</th>
                    <th>Days left</th><th class="num">Value</th><th>Date added</th><th></th>
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
                        <a class="item-link" href="stock_item.php?id=<?php echo $r['id']; ?>"><?php echo htmlspecialchars($r['item_name']); ?></a>
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
                    <td class="muted" style="white-space:nowrap"><?php echo date('d M Y', strtotime($r['created_at'])); ?></td>
                    <td><div class="row-actions">
                        <a href="stock_item.php?id=<?php echo $r['id']; ?>" class="btn btn-icon" aria-label="View <?php echo htmlspecialchars($r['item_name']); ?>" data-tip="View"><?php echo row_icon('view'); ?></a>
                        <a href="stock_edit.php?id=<?php echo $r['id']; ?>" class="btn btn-icon" aria-label="Edit <?php echo htmlspecialchars($r['item_name']); ?>" data-tip="Edit"><?php echo row_icon('edit'); ?></a>
                        <?php if ((int)$r['moves'] === 0): ?>
                        <form method="POST" action="stock_edit.php" class="inline-form"
                              data-confirm-title="Delete item"
                              data-confirm="Delete <?php echo htmlspecialchars($r['item_name']); ?>? This cannot be undone."
                              data-confirm-ok="Delete" data-confirm-danger="1">
                            <input type="hidden" name="id" value="<?php echo $r['id']; ?>">
                            <input type="hidden" name="return" value="<?php echo htmlspecialchars(stock_url()); ?>">
                            <button type="submit" name="delete_item" value="1" class="btn btn-icon btn-icon-danger" aria-label="Delete <?php echo htmlspecialchars($r['item_name']); ?>" data-tip="Delete"><?php echo row_icon('delete'); ?></button>
                        </form>
                        <?php else: ?>
                        <span class="btn btn-icon" aria-disabled="true" role="img" aria-label="Can't delete: has stock history" data-tip="Can't delete: has stock history"><?php echo row_icon('delete'); ?></span>
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
        <a href="stock_edit.php" class="btn btn-primary">Add your first item</a>
    </div>
    <?php else: ?>
    <div class="empty-state"><p>No items match these filters.</p><a href="stock.php" class="btn">Clear filters</a></div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
