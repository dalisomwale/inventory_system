<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();

$user_role = $_SESSION['role'];
$is_manager = ($user_role == 'manager');
$is_front_desk = ($user_role == 'front_desk');

// Get filter parameters
$category = $_GET['category'] ?? '';
$status = $_GET['status'] ?? '';
$location = $_GET['location'] ?? '';

$query = "
    SELECT i.*, c.name as category_name 
    FROM items i
    LEFT JOIN categories c ON i.category_id = c.id
    WHERE 1=1
";

$params = [];

if ($category) {
    $query .= " AND i.category_id = ?";
    $params[] = $category;
}

if ($location) {
    $query .= " AND i.location LIKE ?";
    $params[] = "%$location%";
}

if ($status === 'low') {
    $query .= " AND i.current_quantity <= i.reorder_level AND i.current_quantity > 0";
} elseif ($status === 'out') {
    $query .= " AND i.current_quantity = 0";
} elseif ($status === 'in') {
    $query .= " AND i.current_quantity > i.reorder_level";
}

$query .= " ORDER BY i.item_name";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$items = $stmt->fetchAll();

// Get categories for filter
$categories = $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();

include __DIR__ . '/../includes/header.php';
?>

<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
    <h1><?php echo $is_front_desk ? 'View Inventory' : 'Manage Inventory'; ?></h1>
    <?php if ($is_manager): ?>
    <button class="btn" onclick="openAddItemModal()">+ Add New Item</button>
    <?php endif; ?>
</div>

<div class="card" style="margin-bottom: 24px;">
    <form method="GET" style="display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-end;">
        <div class="form-group" style="flex: 1;">
            <label>Category</label>
            <select name="category">
                <option value="">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?php echo $cat['id']; ?>" <?php echo $category == $cat['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($cat['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="form-group" style="flex: 1;">
            <label>Status</label>
            <select name="status">
                <option value="">All Status</option>
                <option value="in" <?php echo $status == 'in' ? 'selected' : ''; ?>>In Stock</option>
                <option value="low" <?php echo $status == 'low' ? 'selected' : ''; ?>>Low Stock</option>
                <option value="out" <?php echo $status == 'out' ? 'selected' : ''; ?>>Out of Stock</option>
            </select>
        </div>
        
        <div class="form-group" style="flex: 1;">
            <label>Location</label>
            <input type="text" name="location" placeholder="Search by location" value="<?php echo htmlspecialchars($location); ?>">
        </div>
        
        <div class="form-group">
            <button type="submit" class="btn">Apply Filters</button>
            <a href="inventory.php" class="btn">Reset</a>
        </div>
    </form>
</div>

<div class="table-container">
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr>
                    <th style="padding: 12px; text-align: left;">Item Name</th>
                    <th style="padding: 12px; text-align: left;">Category</th>
                    <th style="padding: 12px; text-align: left;">Quantity</th>
                    <?php if ($is_manager): ?>
                    <th style="padding: 12px; text-align: left;">Unit Price</th>
                    <th style="padding: 12px; text-align: left;">Total Value</th>
                    <?php endif; ?>
                    <th style="padding: 12px; text-align: left;">Status</th>
                    <?php if ($is_manager): ?>
                    <th style="padding: 12px; text-align: left;">Actions</th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <?php
                $stockStatus = '';
                $statusClass = '';
                if ($item['current_quantity'] == 0) {
                    $stockStatus = 'Out of Stock';
                    $statusClass = 'status-out';
                } elseif ($item['current_quantity'] <= $item['reorder_level']) {
                    $stockStatus = 'Low Stock';
                    $statusClass = 'status-low';
                } else {
                    $stockStatus = 'In Stock';
                    $statusClass = 'status-in-stock';
                }
                ?>
                <tr style="border-bottom: 1px solid #e0e0e0;">
                    <td style="padding: 12px;"><strong><?php echo htmlspecialchars($item['item_name']); ?></strong></td>
                    <td style="padding: 12px;"><?php echo htmlspecialchars($item['category_name']); ?></td>
                    <td style="padding: 12px;"><?php echo $item['current_quantity']; ?></td>
                    <?php if ($is_manager): ?>
                    <td style="padding: 12px;">K<?php echo number_format($item['unit_price'], 2); ?></td>
                    <td style="padding: 12px;">K<?php echo number_format($item['current_quantity'] * $item['unit_price'], 2); ?></td>
                    <?php endif; ?>
                    <td style="padding: 12px;"><span class="status-badge <?php echo $statusClass; ?>"><?php echo $stockStatus; ?></span></td>
                    <?php if ($is_manager): ?>
                    <td class="action-icons" style="padding: 12px;">
                        <i class="fas fa-edit" title="Edit" style="cursor: pointer; margin-right: 10px;" onclick="editItem(<?php echo $item['id']; ?>)"></i>
                        <i class="fas fa-trash" title="Delete" style="cursor: pointer; color: #dc3545;" onclick="deleteItem(<?php echo $item['id']; ?>, '<?php echo addslashes($item['item_name']); ?>')"></i>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
