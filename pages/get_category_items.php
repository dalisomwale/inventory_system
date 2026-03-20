<?php
require_once __DIR__ . '/../config/database.php';

$category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

if ($category_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM items WHERE category_id = ? ORDER BY item_name");
    $stmt->execute([$category_id]);
    $items = $stmt->fetchAll();
    
    // Get category name
    $cat_stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
    $cat_stmt->execute([$category_id]);
    $category_name = $cat_stmt->fetchColumn();
    
    if (count($items) > 0) {
        ?>
        <div style="overflow-x: auto;">
            <table style="width:100%; border-collapse:collapse;">
                <thead>
                    <tr style="background: #f9fafb;">
                        <th style="text-align:left; padding:12px; border-bottom:2px solid #e5e7eb;">Item Name</th>
                        <th style="text-align:left; padding:12px; border-bottom:2px solid #e5e7eb;">Stock</th>
                        <th style="text-align:left; padding:12px; border-bottom:2px solid #e5e7eb;">Unit Price</th>
                        <th style="text-align:left; padding:12px; border-bottom:2px solid #e5e7eb;">Total Value</th>
                        <th style="text-align:left; padding:12px; border-bottom:2px solid #e5e7eb;">Reorder</th>
                        <th style="text-align:left; padding:12px; border-bottom:2px solid #e5e7eb;">Status</th>
                        <th style="text-align:left; padding:12px; border-bottom:2px solid #e5e7eb;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td style="padding:12px; border-bottom:1px solid #e5e7eb; font-weight:500;"><?php echo htmlspecialchars($item['item_name']); ?></td>
                        <td style="padding:12px; border-bottom:1px solid #e5e7eb;"><?php echo $item['current_quantity']; ?></td>
                        <td style="padding:12px; border-bottom:1px solid #e5e7eb;">K<?php echo number_format($item['unit_price'], 2); ?></td>
                        <td style="padding:12px; border-bottom:1px solid #e5e7eb;">K<?php echo number_format($item['current_quantity'] * $item['unit_price'], 2); ?></td>
                        <td style="padding:12px; border-bottom:1px solid #e5e7eb;"><?php echo $item['reorder_level']; ?></td>
                        <td style="padding:12px; border-bottom:1px solid #e5e7eb;">
                            <?php if ($item['current_quantity'] <= $item['reorder_level']): ?>
                                <span class="status-pending">Low Stock</span>
                            <?php else: ?>
                                <span class="status-receiving">OK</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:12px; border-bottom:1px solid #e5e7eb; white-space:nowrap;">
                            <button onclick="editItem(<?php echo $item['id']; ?>)" class="btn btn-sm" style="margin-right:5px;">Edit</button>
                            <button onclick="deleteItem(<?php echo $item['id']; ?>, '<?php echo addslashes($item['item_name']); ?>')" class="btn btn-sm btn-danger">Delete</button>
                            <a href="receive_stock.php?item=<?php echo $item['id']; ?>" class="btn btn-sm btn-success" style="margin-left:5px;">Receive</a>
                            <a href="issue_stock.php?item=<?php echo $item['id']; ?>" class="btn btn-sm btn-primary">Issue</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    } else {
        echo '<div style="text-align:center; padding:60px; color:var(--muted);">
                <div style="font-size:3rem; margin-bottom:15px;">📦</div>
                <p>No items in ' . htmlspecialchars($category_name) . ' yet.</p>
                <p>Click "Add Item" above to add your first item.</p>
              </div>';
    }
} else {
    echo '<div style="text-align:center; padding:60px; color:var(--muted);">
            <p>Invalid category selected.</p>
          </div>';
}
?>