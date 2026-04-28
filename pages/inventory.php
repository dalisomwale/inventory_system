<?php
require_once __DIR__ . '/../config/database.php';
redirectIfNotLoggedIn();

// Get all items
$items = $pdo->query("SELECT * FROM items ORDER BY category, item_name")->fetchAll();
$categories = $pdo->query("SELECT DISTINCT category FROM items WHERE category IS NOT NULL ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Inventory Management</h1>
    <a href="manage_items.php" class="btn btn-primary">Manage Items</a>
</div>

<div class="card">
    <div class="card-header">
        <h3>All Inventory Items</h3>
    </div>
    <div class="table-container">
        <table style="width:100%">
            <thead>
                <tr>
                    <th>Item Name</th>
                    <th>Category</th>
                    <th>Quantity</th>
                    <th>Unit Price</th>
                    <th>Reorder Level</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']); ?></td>
                    <td><?php echo htmlspecialchars($item['category'] ?? 'Uncategorized'); ?></td>
                    <td><?php echo $item['current_quantity']; ?></td>
                    <td>K<?php echo number_format($item['unit_price'], 2); ?></td>
                    <td><?php echo $item['reorder_level']; ?></td>
                    <td>
                        <?php if ($item['current_quantity'] == 0): ?>
                            <span style="background:#fee2e2; color:#dc2626; padding:2px 8px; border-radius:20px; font-size:11px;">Out of Stock</span>
                        <?php elseif ($item['current_quantity'] <= $item['reorder_level']): ?>
                            <span style="background:#fef3c7; color:#d97706; padding:2px 8px; border-radius:20px; font-size:11px;">Low Stock</span>
                        <?php else: ?>
                            <span style="background:#d1fae5; color:#059669; padding:2px 8px; border-radius:20px; font-size:11px;">In Stock</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="receive_stock.php?item=<?php echo $item['id']; ?>" style="padding:4px 8px; background:#10b981; color:white; text-decoration:none; border-radius:4px; font-size:11px;">Receive</a>
                        <a href="issue_stock.php?item=<?php echo $item['id']; ?>" style="padding:4px 8px; background:#2563eb; color:white; text-decoration:none; border-radius:4px; font-size:11px;">Issue</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
