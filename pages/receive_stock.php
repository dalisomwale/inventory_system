<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_id       = (int)$_POST['item_id'];
    $supplier      = trim($_POST['supplier']);
    $quantity      = (int)$_POST['quantity'];
    $unit_price    = (float)$_POST['unit_price'];
    $date_received = $_POST['date_received'];
    
    $pdo->beginTransaction();
    try {
        // Get current item details for audit
        $item = $pdo->prepare("SELECT item_name, current_quantity FROM items WHERE id=?");
        $item->execute([$item_id]);
        $item_data = $item->fetch();
        
        $pdo->prepare("INSERT INTO stock_receiving (item_id, supplier, quantity, unit_price, date_received, received_by) VALUES (?,?,?,?,?,?)")
            ->execute([$item_id, $supplier, $quantity, $unit_price, $date_received, $_SESSION['user_id']]);
        $pdo->prepare("UPDATE items SET current_quantity = current_quantity + ?, unit_price = ? WHERE id = ?")
            ->execute([$quantity, $unit_price, $item_id]);
        logAudit($pdo, $_SESSION['user_id'], 'RECEIVE_STOCK', $item_id, $quantity, "Received $quantity of {$item_data['item_name']} from: $supplier");
        $pdo->commit();
        $success = 'Stock received successfully!';
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Error receiving stock: ' . $e->getMessage();
    }
}

// Group items by category for grouped select
$all_items = $pdo->query("SELECT * FROM items ORDER BY category, item_name")->fetchAll();
$grouped   = [];
foreach ($all_items as $it) {
    $grouped[$it['category'] ?: 'Other'][] = $it;
}

// Get recent receiving transactions
$recent_receiving = $pdo->prepare("
    SELECT r.*, i.item_name, i.category, u.full_name as received_by_name
    FROM stock_receiving r
    JOIN items i ON r.item_id = i.id
    JOIN users u ON r.received_by = u.id
    ORDER BY r.created_at DESC
    LIMIT 20
");
$recent_receiving->execute();
$recent_receiving = $recent_receiving->fetchAll();

include __DIR__ . '/../includes/header.php';
?>
<h1>Receive Stock</h1>

<div class="form-container">
    <?php if (isset($success)): ?><div class="alert alert-success"><?php echo $success;?></div><?php endif; ?>
    <?php if (isset($error)):   ?><div class="alert alert-danger"><?php echo $error;?></div><?php endif; ?>
    
    <form method="POST">
        <div class="form-group">
            <label>Select Item</label>
            <select name="item_id" required>
                <option value="">— Choose item —</option>
                <?php foreach ($grouped as $cat => $items): ?>
                <optgroup label="<?php echo htmlspecialchars($cat); ?>">
                    <?php foreach ($items as $item): ?>
                    <option value="<?php echo $item['id']; ?>" <?php echo (isset($_GET['item']) && $_GET['item'] == $item['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($item['item_name']); ?> (Stock: <?php echo $item['current_quantity']; ?>)
                    </option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Supplier</label>
                <input type="text" name="supplier" required placeholder="Supplier name">
            </div>
            <div class="form-group">
                <label>Quantity</label>
                <input type="number" name="quantity" min="1" required placeholder="Qty received">
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Unit Price (K)</label>
                <input type="number" name="unit_price" min="0" step="0.01" required placeholder="0.00">
            </div>
            <div class="form-group">
                <label>Date Received</label>
                <input type="date" name="date_received" value="<?php echo date('Y-m-d');?>" required>
            </div>
        </div>
        <div class="form-actions">
            <button type="submit" class="btn btn-success">&#8681; Receive Stock</button>
            <a href="dashboard.php" class="btn">Cancel</a>
        </div>
    </form>
</div>

<!-- Recent Receiving Transactions Table -->
<div class="card">
    <h3>Recent Receiving Transactions</h3>
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Date & Time</th>
                    <th>Item</th>
                    <th>Category</th>
                    <th>Quantity</th>
                    <th>Supplier</th>
                    <th>Unit Price</th>
                    <th>Total Value</th>
                    <th>Received By</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_receiving as $trans): ?>
                <tr>
                    <td><?php echo date('d/m/Y H:i', strtotime($trans['created_at'])); ?></td>
                    <td style="font-weight:500"><?php echo htmlspecialchars($trans['item_name']); ?></td>
                    <td><span class="cat-pill <?php echo catClass($trans['category']); ?>"><?php echo htmlspecialchars($trans['category']); ?></span></td>
                    <td><?php echo $trans['quantity']; ?></td>
                    <td><?php echo htmlspecialchars($trans['supplier'] ?: '—'); ?></td>
                    <td>K<?php echo number_format($trans['unit_price'], 2); ?></td>
                    <td>K<?php echo number_format($trans['quantity'] * $trans['unit_price'], 2); ?></td>
                    <td><?php echo htmlspecialchars($trans['received_by_name']); ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($recent_receiving)): ?>
                <tr><td colspan="8" style="text-align:center;color:var(--muted);padding:24px">No receiving transactions yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div style="margin-top:15px; text-align:right">
        <a href="view_transactions.php?type=receiving" class="btn btn-sm">View All Receiving Transactions →</a>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>