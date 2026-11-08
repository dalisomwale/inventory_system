<?php
require_once __DIR__ . '/../includes/auth.php';
requirePermission('stock.view');

// Get unique suppliers from receiving records
$suppliers = $pdo->query("SELECT DISTINCT supplier FROM stock_receiving WHERE supplier IS NOT NULL AND supplier != '' ORDER BY supplier")->fetchAll(PDO::FETCH_COLUMN);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Suppliers</h1>
</div>

<div class="card">
    <div class="card-header">
        <h3>Supplier List</h3>
    </div>
    <div class="table-container">
        <table style="width:100%">
            <thead>
                <tr><th>Supplier Name</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php if (count($suppliers) > 0): ?>
                    <?php foreach ($suppliers as $supplier): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($supplier); ?></td>
                        <td><a href="receive_stock.php?supplier=<?php echo urlencode($supplier); ?>" class="btn btn-sm">View Transactions</a></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="2" style="text-align:center; padding:40px;">No suppliers recorded yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
