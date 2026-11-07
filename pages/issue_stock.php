<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

$categories       = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
$selected_cat_id  = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
$selected_item_id = isset($_GET['item_id'])     ? (int)$_GET['item_id']     : 0;

$items         = [];
$selected_item = null;

if ($selected_cat_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM items WHERE category_id = ? AND current_quantity > 0 ORDER BY item_name");
    $stmt->execute([$selected_cat_id]);
    $items = $stmt->fetchAll();
}
if ($selected_item_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
    $stmt->execute([$selected_item_id]);
    $selected_item = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v           = new Validator($_POST);
    $item        = $v->exists($pdo, 'item_id', 'item', 'items', 'id, item_name, current_quantity');
    $quantity    = $v->int('quantity', 'Quantity to issue', 1, 1000000);
    $issued_to   = $v->text('issued_to', 'Issued to', 150, true, 2);
    $purpose     = $v->textarea('purpose', 'Purpose', 255);
    $date_issued = $v->date('date_issued', 'Date issued');
    $item_id     = $item ? (int)$item['id'] : 0;

    if ($v->ok() && $quantity > $item['current_quantity']) {
        $v->fail('Only ' . $item['current_quantity'] . ' in stock. You cannot issue ' . $quantity . '.');
    }

    if (!$v->ok()) {
        $error = $v->message();
    } else {
        $pdo->beginTransaction();
        try {
            // Lock the row so two people can't issue the same units at once
            $lock = $pdo->prepare("SELECT current_quantity FROM items WHERE id=? FOR UPDATE");
            $lock->execute([$item_id]);
            $old_stock = (int)$lock->fetchColumn();
            if ($quantity > $old_stock) {
                throw new RuntimeException("Stock changed while you were working. Only $old_stock now available.");
            }
            $new_stock = $old_stock - $quantity;
            $pdo->prepare("INSERT INTO stock_issuing (item_id, quantity, issued_to, purpose, date_issued, issued_by, old_stock, new_stock) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$item_id, $quantity, $issued_to, $purpose, $date_issued, $_SESSION['user_id'], $old_stock, $new_stock]);
            $pdo->prepare("UPDATE items SET current_quantity = current_quantity - ? WHERE id=?")->execute([$quantity, $item_id]);
            logAudit($pdo, $_SESSION['user_id'], 'ISSUE_STOCK', $item_id, $quantity,
                     "Issued $quantity of {$item['item_name']} to: $issued_to. Old: $old_stock, New: $new_stock.");
            $pdo->commit();
            $success = "Issued successfully. Previous stock: $old_stock &rarr; New stock: $new_stock";

            $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
            $stmt->execute([$item_id]);
            $selected_item = $stmt->fetch();

            if ($selected_cat_id > 0) {
                $stmt = $pdo->prepare("SELECT * FROM items WHERE category_id = ? AND current_quantity > 0 ORDER BY item_name");
                $stmt->execute([$selected_cat_id]);
                $items = $stmt->fetchAll();
            }
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            $error = htmlspecialchars($e->getMessage());
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log($e->getMessage());
            $error = 'Could not issue stock. Please try again.';
        }
    }
}

$recent = $pdo->prepare("
    SELECT i.*, it.item_name, c.name as category_name, u.full_name as issued_by_name
    FROM stock_issuing i
    JOIN items it ON i.item_id = it.id
    LEFT JOIN categories c ON it.category_id = c.id
    JOIN users u ON i.issued_by = u.id
    ORDER BY i.created_at DESC LIMIT 20
");
$recent->execute();
$recent = $recent->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Issue Stock</h1>
    <a href="dashboard.php" class="btn btn-sm">Back to Dashboard</a>
</div>

<!-- Category Selector -->
<div class="pill-tabs">
    <a href="issue_stock.php" class="pill-tab <?php echo $selected_cat_id == 0 ? 'active' : ''; ?>">All</a>
    <?php foreach ($categories as $cat): ?>
    <a href="?category_id=<?php echo $cat['id']; ?>" class="pill-tab <?php echo $selected_cat_id == $cat['id'] ? 'active' : ''; ?>">
        <?php echo htmlspecialchars($cat['name']); ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success; ?></div><?php endif; ?>
<?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error; ?></div><?php endif; ?>

<!-- Item Selection -->
<div class="form-container">
    <div class="form-title">Select Item</div>
    <form method="GET">
        <input type="hidden" name="category_id" value="<?php echo $selected_cat_id; ?>">
        <div class="form-group">
            <label>Choose Item</label>
            <select name="item_id" onchange="this.form.submit()">
                <option value="">— Select an item —</option>
                <?php foreach ($items as $item): ?>
                <option value="<?php echo $item['id']; ?>" <?php echo ($selected_item_id == $item['id']) ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($item['item_name']); ?> (Available: <?php echo $item['current_quantity']; ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <?php if (empty($items) && $selected_cat_id > 0): ?>
            <small style="color:var(--muted)">No items with available stock in this category.</small>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($selected_item): ?>
<div class="form-container">
    <div class="form-title"><?php echo htmlspecialchars($selected_item['item_name']); ?></div>

    <div class="stock-info-box">
        <div class="stock-info-item">
            <div class="label">Available Stock</div>
            <div class="value"><?php echo $selected_item['current_quantity']; ?></div>
        </div>
        <div class="stock-info-item">
            <div class="label">Category</div>
            <div class="value" style="font-size:.88rem"><?php echo htmlspecialchars($selected_item['category']); ?></div>
        </div>
        <div class="stock-info-item">
            <div class="label">Reorder Level</div>
            <div class="value"><?php echo $selected_item['reorder_level']; ?></div>
        </div>
    </div>

    <form method="POST"
          data-confirm-title="Issue stock"
          data-confirm="Issue {quantity} &times; <?php echo htmlspecialchars($selected_item['item_name']); ?> to {issued_to}? This will be deducted from stock."
          data-confirm-ok="Issue stock">
        <input type="hidden" name="item_id" value="<?php echo $selected_item['id']; ?>">
        <div class="form-group">
            <label>Quantity to Issue *</label>
            <input type="number" name="quantity" id="qty" min="1" max="<?php echo $selected_item['current_quantity']; ?>" step="1" required
                   placeholder="0" oninput="updateNewStock()">
            <small id="newStockMsg"></small>
        </div>
        <div class="form-group">
            <label>Issued To *</label>
            <input type="text" name="issued_to" required minlength="2" maxlength="150" placeholder="Department, person or customer name"
                   value="<?php echo isset($error) ? htmlspecialchars($_POST['issued_to'] ?? '') : ''; ?>">
        </div>
        <div class="form-group">
            <label>Purpose</label>
            <textarea name="purpose" rows="2" maxlength="255" placeholder="Reason for issue (optional)"><?php echo isset($error) ? htmlspecialchars($_POST['purpose'] ?? '') : ''; ?></textarea>
        </div>
        <div class="form-group">
            <label>Date Issued *</label>
            <input type="date" name="date_issued" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-danger">Issue Stock</button>
            <a href="issue_stock.php?category_id=<?php echo $selected_cat_id; ?>" class="btn">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- Recent Transactions -->
<div class="card">
    <h3 style="margin-bottom:12px">Recent Issuing</h3>
    <?php if (count($recent) > 0): ?>
    <div class="table-container">
        <table>
            <thead>
                <tr><th>Date</th><th>Item</th><th>Category</th><th>Qty</th><th>Prev Stock</th><th>New Stock</th><th>Issued To</th><th>Purpose</th><th>By</th></tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $t): ?>
                <tr>
                    <td style="white-space:nowrap; color:var(--muted); font-size:.76rem"><?php echo date('d/m/Y H:i', strtotime($t['created_at'])); ?></td>
                    <td style="font-weight:500"><?php echo htmlspecialchars($t['item_name']); ?></td>
                    <td><span class="cat-pill"><?php echo htmlspecialchars($t['category_name'] ?? 'Other'); ?></span></td>
                    <td><strong><?php echo $t['quantity']; ?></strong></td>
                    <td style="color:var(--muted)"><?php echo $t['old_stock'] ?? '—'; ?></td>
                    <td style="color:var(--danger); font-weight:500"><?php echo $t['new_stock'] ?? '—'; ?></td>
                    <td><?php echo htmlspecialchars($t['issued_to'] ?: '—'); ?></td>
                    <td style="color:var(--muted); font-size:.76rem; max-width:160px; white-space:normal"><?php echo htmlspecialchars($t['purpose'] ?: '—'); ?></td>
                    <td style="color:var(--muted)"><?php echo htmlspecialchars($t['issued_by_name']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="empty-state"><p>No issuing transactions yet.</p></div>
    <?php endif; ?>
</div>

<script>
function updateNewStock() {
    var current = <?php echo $selected_item['current_quantity'] ?? 0; ?>;
    var qty = parseInt(document.getElementById('qty').value) || 0;
    var msg = document.getElementById('newStockMsg');
    if (qty > current) {
        msg.innerHTML = 'Cannot issue more than available stock.';
        msg.style.color = 'var(--danger)';
    } else if (qty > 0) {
        msg.innerHTML = 'After issue, new stock: <strong>' + (current - qty) + '</strong>';
        msg.style.color = 'var(--muted)';
    } else {
        msg.innerHTML = '';
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>