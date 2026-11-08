<?php
// Requisitions — what needs buying: To order -> Ordered -> Received (or Cancelled).
require_once __DIR__ . '/../includes/auth.php';
requirePermission('requisitions.view');

$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$departments = org_departments($pdo);
$statuses   = ['needed' => 'To order', 'ordered' => 'Ordered', 'received' => 'Received', 'cancelled' => 'Cancelled'];
$tab        = array_key_exists($_GET['tab'] ?? '', $statuses) || ($_GET['tab'] ?? '') === 'all' ? $_GET['tab'] : 'open';

if (!empty($_SESSION['flash']))       { $success = htmlspecialchars($_SESSION['flash']);       unset($_SESSION['flash']); }
if (!empty($_SESSION['flash_error'])) { $error   = htmlspecialchars($_SESSION['flash_error']); unset($_SESSION['flash_error']); }

function back_to_list(string $tab): void {
    header('Location: requisitions.php' . ($tab !== 'open' ? '?tab=' . urlencode($tab) : ''));
    exit();
}

// Raising, ordering and cancelling need the "manage" right; viewing doesn't
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !can('requisitions.manage')) {
    $_SESSION['flash_error'] = "You can view requisitions but not change them.";
    header('Location: requisitions.php'); exit();
}

// ── Add to the list ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_purchase'])) {
    $v         = new Validator($_POST);
    $item_name = $v->text('item_name', 'Item', 200, true, 2);
    $category  = $v->exists($pdo, 'category_id', 'category', 'categories', 'id, name');
    $quantity  = $v->int('quantity', 'Quantity', 1, 100000);
    $supplier  = $v->text('supplier', 'Supplier', 200, true, 2);
    $notes     = $v->textarea('reason', 'Reason', 500, true, 3);
    $dept = null;
    if ($departments) {
        foreach ($departments as $d) if ((int)$d['id'] === (int)($_POST['department_id'] ?? 0)) $dept = $d;
        if (!$dept) $v->fail('Please choose the department that needs this.');
    }
    $req_branch = $dept ? (int)$dept['branch_id'] : (user_branch_id() ?: null);

    if ($v->ok()) {
        $dup = $pdo->prepare("SELECT COUNT(*) FROM procurement WHERE LOWER(item_name)=LOWER(?) AND status IN ('needed','ordered')");
        $dup->execute([$item_name]);
        if ($dup->fetchColumn() > 0) $v->fail("There is already an open requisition for \"$item_name\".");
    }
    if (!$v->ok()) {
        $error = $v->message();
    } else {
        // Link to an existing item with the same name, if there is one
        $match = $pdo->prepare("SELECT id FROM items WHERE LOWER(item_name)=LOWER(?) AND category_id=? LIMIT 1");
        $match->execute([$item_name, $category['id']]);
        $item_id = $match->fetchColumn() ?: null;

        $pdo->prepare("INSERT INTO procurement (item_name, item_id, quantity, reason, supplier, requested_by, category_id, category, branch_id, department_id, status)
                       VALUES (?,?,?,?,?,?,?,?,?,?,'needed')")
            ->execute([$item_name, $item_id, $quantity, $notes, $supplier ?: null, $_SESSION['user_id'], $category['id'], $category['name'], $req_branch, $dept['id'] ?? null]);
        logAudit($pdo, $_SESSION['user_id'], 'REQUISITION_RAISED', $item_id ? (int)$item_id : null, $quantity, "Requisition raised: $item_name");
        $_SESSION['flash'] = "Requisition raised for \"$item_name\".";
        back_to_list('open');
    }
}

// ── Mark ordered / cancel ───────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['purchase_action'])) {
    $v      = new Validator($_POST);
    $action = $v->choice('purchase_action', 'action', ['ordered', 'cancel']);
    $p      = $v->exists($pdo, 'id', 'requisition', 'procurement');
    $from   = $action === 'ordered' ? ['needed'] : ['needed', 'ordered'];
    if ($v->ok() && !in_array($p['status'], $from, true)) {
        $v->fail('This requisition is already ' . strtolower($statuses[$p['status']] ?? $p['status']) . '.');
    }
    if ($v->ok()) {
        if ($action === 'ordered') {
            $supplier = trim((string)($_POST['supplier'] ?? '')) ?: $p['supplier'];
            $pdo->prepare("UPDATE procurement SET status='ordered', ordered_at=NOW(), supplier=?, approved_by=? WHERE id=? AND status='needed'")
                ->execute([mb_substr((string)$supplier, 0, 200) ?: null, $_SESSION['user_id'], $p['id']]);
            logAudit($pdo, $_SESSION['user_id'], 'REQUISITION_ORDERED', $p['item_id'] ? (int)$p['item_id'] : null, $p['quantity'], "Ordered: {$p['item_name']}");
            $_SESSION['flash'] = "\"{$p['item_name']}\" marked as ordered.";
        } else {
            $pdo->prepare("UPDATE procurement SET status='cancelled', approved_by=? WHERE id=? AND status IN ('needed','ordered')")
                ->execute([$_SESSION['user_id'], $p['id']]);
            logAudit($pdo, $_SESSION['user_id'], 'REQUISITION_CANCELLED', $p['item_id'] ? (int)$p['item_id'] : null, $p['quantity'], "Cancelled: {$p['item_name']}");
            $_SESSION['flash'] = "Requisition for \"{$p['item_name']}\" cancelled.";
        }
    } else {
        $_SESSION['flash_error'] = implode(' ', $v->errors());
    }
    $back = $_POST['return'] ?? 'requisitions.php';
    if (!in_array(parse_url($back, PHP_URL_PATH), ['requisitions.php', 'dashboard.php'], true)) $back = 'requisitions.php';
    header('Location: ' . $back);
    exit();
}

// ── List ────────────────────────────────────────────────────
$counts = array_fill_keys(array_keys($statuses), 0);
foreach ($pdo->query("SELECT status, COUNT(*) n FROM procurement GROUP BY status")->fetchAll() as $c) {
    if (isset($counts[$c['status']])) $counts[$c['status']] = (int)$c['n'];
}

$where = match ($tab) {
    'open'  => "p.status IN ('needed','ordered')",
    'all'   => '1=1',
    default => 'p.status = ' . $pdo->quote($tab),
};
$list = $pdo->query("
    SELECT p.*, u.full_name AS added_by, u2.full_name AS updated_by, c.name AS category_name,
           d.name AS dept_name, b.name AS branch_name
    FROM procurement p
    LEFT JOIN users u  ON u.id  = p.requested_by
    LEFT JOIN users u2 ON u2.id = p.approved_by
    LEFT JOIN categories c ON c.id = p.category_id
    LEFT JOIN departments d ON d.id = p.department_id
    LEFT JOIN branches b ON b.id = p.branch_id
    WHERE $where
    ORDER BY FIELD(p.status,'needed','ordered','received','cancelled'), p.created_at DESC")->fetchAll();

$items_for_list = $pdo->query("SELECT item_name FROM items ORDER BY item_name")->fetchAll(PDO::FETCH_COLUMN);
$suppliers      = $pdo->query("SELECT DISTINCT supplier FROM stock_receiving WHERE supplier IS NOT NULL AND supplier <> '' AND supplier <> 'Initial Stock'
                               UNION SELECT DISTINCT supplier FROM procurement WHERE supplier IS NOT NULL AND supplier <> '' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);

$pre_name = is_string($_GET['item_name'] ?? null) ? mb_substr($_GET['item_name'], 0, 200) : '';
$pre_cat  = (int)($_GET['category_id'] ?? 0);
$pre_qty  = max(1, min(100000, (int)($_GET['qty'] ?? 1)));

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Requisitions</h1>
    <span class="card-hint">Raise a requisition for what needs buying, mark it ordered, then receive it into stock.</span>
</div>

<?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
<?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<?php if (can('requisitions.manage')): ?>
<!-- Add -->
<div class="form-container wide">
    <div class="form-title">New requisition</div>
    <form method="POST"
          data-confirm-title="Raise requisition"
          data-confirm="Raise a requisition for {quantity} &times; {item_name} ({category_id})<?php echo $departments ? ' for {department_id}' : ''; ?>?"
          data-confirm-ok="Raise requisition">
        <input type="hidden" name="add_purchase" value="1">
        <div class="form-row">
            <div class="form-group">
                <label for="item_name">Item *</label>
                <input type="text" id="item_name" name="item_name" list="item-names" required minlength="2" maxlength="200" placeholder="Start typing an item"
                       value="<?php echo htmlspecialchars(isset($error) ? ($_POST['item_name'] ?? '') : $pre_name); ?>">
                <datalist id="item-names"><?php foreach ($items_for_list as $n): ?><option value="<?php echo htmlspecialchars($n); ?>"><?php endforeach; ?></datalist>
            </div>
            <div class="form-group">
                <label for="category_id">Category *</label>
                <select id="category_id" name="category_id" required>
                    <option value="">— Select —</option>
                    <?php $sel = isset($error) ? (int)($_POST['category_id'] ?? 0) : $pre_cat; ?>
                    <?php foreach ($categories as $c): ?>
                    <option value="<?php echo $c['id']; ?>" <?php echo $sel === (int)$c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="quantity">Quantity *</label>
                <input type="number" id="quantity" name="quantity" min="1" max="100000" step="1" required
                       value="<?php echo isset($error) ? (int)($_POST['quantity'] ?? 1) : $pre_qty; ?>">
            </div>
            <div class="form-group">
                <label for="supplier">Supplier *</label>
                <input type="text" id="supplier" name="supplier" list="supplier-names" required minlength="2" maxlength="200" placeholder="Who will supply it"
                       value="<?php echo isset($error) ? htmlspecialchars($_POST['supplier'] ?? '') : ''; ?>">
                <datalist id="supplier-names"><?php foreach ($suppliers as $n): ?><option value="<?php echo htmlspecialchars($n); ?>"><?php endforeach; ?></datalist>
            </div>
        </div>
        <?php if ($departments): ?>
        <div class="form-group" style="max-width:50%">
            <label for="department_id">For department *</label>
            <select id="department_id" name="department_id" required>
                <option value="">— Select department —</option>
                <?php $sel = (int)($_POST['department_id'] ?? 0); $groups = [];
                foreach ($departments as $d) $groups[$d['company_name'] . ' › ' . $d['branch_name']][] = $d;
                foreach ($groups as $g => $ds): ?>
                <optgroup label="<?php echo htmlspecialchars($g); ?>">
                    <?php foreach ($ds as $d): ?><option value="<?php echo $d['id']; ?>" <?php echo $sel === (int)$d['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars(dept_label($d)); ?></option><?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
            </select>
            <small>The stock will be received at this department's branch.</small>
        </div>
        <?php endif; ?>
        <div class="form-group">
            <label for="reason">Reason *</label>
            <textarea id="reason" name="reason" rows="2" required minlength="3" maxlength="500" placeholder="Why it is needed, e.g. toner for the Credit department printer"><?php echo isset($error) ? htmlspecialchars($_POST['reason'] ?? '') : ''; ?></textarea>
        </div>
        <div class="form-actions"><button type="submit" class="btn btn-primary">Raise requisition</button></div>
    </form>
</div>
<?php endif; ?>

<!-- Tabs -->
<div class="pill-tabs">
    <a href="requisitions.php" class="pill-tab <?php echo $tab === 'open' ? 'active' : ''; ?>">Open (<?php echo $counts['needed'] + $counts['ordered']; ?>)</a>
    <?php foreach ($statuses as $k => $label): ?>
    <a href="?tab=<?php echo $k; ?>" class="pill-tab <?php echo $tab === $k ? 'active' : ''; ?>"><?php echo $label; ?> (<?php echo $counts[$k]; ?>)</a>
    <?php endforeach; ?>
    <a href="?tab=all" class="pill-tab <?php echo $tab === 'all' ? 'active' : ''; ?>">All</a>
</div>

<div class="card">
    <?php if ($list): ?>
    <div class="table-container">
        <table>
            <thead><tr><th>Date</th><th>Raised by</th><th>Item</th><th>For</th><th class="num">Qty</th><th>Supplier</th><th>Reason</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($list as $p): ?>
                <tr>
                    <td class="muted" style="white-space:nowrap"><?php echo date('d M Y', strtotime($p['created_at'])); ?></td>
                    <td><?php echo htmlspecialchars($p['added_by'] ?? '—'); ?></td>
                    <td style="font-weight:600"><?php echo htmlspecialchars($p['item_name']); ?></td>
                    <td><?php echo $p['dept_name'] ? htmlspecialchars($p['dept_name']) : '<span class="muted-text">—</span>'; ?><div class="item-desc"><?php echo htmlspecialchars($p['branch_name'] ?? ''); ?></div></td>
                    <td class="num"><?php echo number_format($p['quantity']); ?></td>
                    <td><?php echo htmlspecialchars($p['supplier'] ?: '—'); ?></td>
                    <td class="muted" style="max-width:220px; white-space:normal; font-size:.82rem"><?php echo htmlspecialchars($p['reason'] ?: '—'); ?></td>
                    <td style="white-space:nowrap">
                        <?php if ($p['status'] === 'needed'): ?><span class="status-pending">To order</span>
                        <?php elseif ($p['status'] === 'ordered'): ?><span class="status-ordered">Ordered</span><br><span class="muted-text" style="font-size:.76rem"><?php echo $p['ordered_at'] ? date('d M', strtotime($p['ordered_at'])) : ''; ?></span>
                        <?php elseif ($p['status'] === 'received'): ?><span class="status-receiving">Received</span><br><span class="muted-text" style="font-size:.76rem"><?php echo $p['received_at'] ? date('d M', strtotime($p['received_at'])) : ''; ?></span>
                        <?php else: ?><span class="muted-text">Cancelled</span><?php endif; ?>
                    </td>
                    <td>
                        <div class="row-actions">
                        <?php if ($p['status'] === 'needed' && can('requisitions.manage')): ?>
                            <form method="POST" class="inline-form"
                                  data-confirm-title="Mark as ordered"
                                  data-confirm="Mark <?php echo htmlspecialchars($p['quantity'] . ' × ' . $p['item_name']); ?> as ordered<?php echo $p['supplier'] ? ' from ' . htmlspecialchars($p['supplier']) : ''; ?>?"
                                  data-confirm-ok="Mark ordered">
                                <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                <input type="hidden" name="return" value="<?php echo htmlspecialchars('requisitions.php' . (($_SERVER['QUERY_STRING'] ?? '') ? '?' . ($_SERVER['QUERY_STRING'] ?? '') : '')); ?>">
                                <button type="submit" name="purchase_action" value="ordered" class="btn btn-sm btn-primary">Mark ordered</button>
                            </form>
                        <?php endif; ?>
                        <?php if (in_array($p['status'], ['needed', 'ordered'], true)): ?>
                            <?php if (can('stock.receive')): ?><a href="receive_stock.php?purchase_id=<?php echo $p['id']; ?>" class="btn btn-sm btn-success">Receive</a><?php endif; ?>
                            <?php if (can('requisitions.manage')): ?>
                            <form method="POST" class="inline-form"
                                  data-confirm-title="Cancel requisition"
                                  data-confirm="Cancel the requisition for <?php echo htmlspecialchars($p['quantity'] . ' × ' . $p['item_name']); ?>?"
                                  data-confirm-ok="Cancel requisition" data-confirm-danger="1">
                                <input type="hidden" name="id" value="<?php echo $p['id']; ?>">
                                <input type="hidden" name="return" value="<?php echo htmlspecialchars('requisitions.php' . (($_SERVER['QUERY_STRING'] ?? '') ? '?' . ($_SERVER['QUERY_STRING'] ?? '') : '')); ?>">
                                <button type="submit" name="purchase_action" value="cancel" class="btn btn-sm">Cancel</button>
                            </form>
                            <?php endif; ?>
                        <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><p><?php echo $tab === 'open' ? 'No open requisitions.' : 'No requisitions here.'; ?></p></div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
