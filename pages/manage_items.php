<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();
if (!isManager()) { header('Location: dashboard.php'); exit(); }

// Get all categories for filter
$categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();

// Get filter parameters
$filter_category = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

// Handle Add New Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    $cat_name = trim($_POST['category_name']);
    $cat_desc = trim($_POST['category_desc']);
    
    if (!empty($cat_name)) {
        $check = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
        $check->execute([$cat_name]);
        if (!$check->fetch()) {
            $pdo->prepare("INSERT INTO categories (name, description) VALUES (?, ?)")->execute([$cat_name, $cat_desc]);
            $success = "Category added successfully!";
            logAudit($pdo, $_SESSION['user_id'], 'ADD_CATEGORY', null, null, "Added category: $cat_name");
            // Refresh categories
            $categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
        } else {
            $error = "Category already exists!";
        }
    }
}

// Handle Edit Category
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_category'])) {
    $cat_id = (int)$_POST['category_id'];
    $cat_name = trim($_POST['category_name']);
    $cat_desc = trim($_POST['category_desc']);
    
    $pdo->prepare("UPDATE categories SET name = ?, description = ? WHERE id = ?")->execute([$cat_name, $cat_desc, $cat_id]);
    $pdo->prepare("UPDATE items SET category = ? WHERE category_id = ?")->execute([$cat_name, $cat_id]);
    $success = "Category updated successfully!";
    logAudit($pdo, $_SESSION['user_id'], 'EDIT_CATEGORY', null, null, "Updated category: $cat_name");
    // Refresh categories
    $categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
}

// Handle Delete Category
if (isset($_GET['delete_category'])) {
    $cat_id = (int)$_GET['delete_category'];
    $check = $pdo->prepare("SELECT COUNT(*) FROM items WHERE category_id = ?");
    $check->execute([$cat_id]);
    if ($check->fetchColumn() == 0) {
        $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$cat_id]);
        $success = "Category deleted successfully!";
        logAudit($pdo, $_SESSION['user_id'], 'DELETE_CATEGORY', null, null, "Deleted category ID: $cat_id");
        // Refresh categories
        $categories = $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
    } else {
        $error = "Cannot delete category with existing items.";
    }
}

// Handle Add New Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_item'])) {
    $item_name = trim($_POST['item_name']);
    $category_id = (int)$_POST['category_id'];
    $description = trim($_POST['description']);
    $unit_price = (float)$_POST['unit_price'];
    $reorder_level = (int)$_POST['reorder_level'];
    $initial_quantity = (int)$_POST['initial_quantity'];
    
    $cat = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
    $cat->execute([$category_id]);
    $cat_name = $cat->fetchColumn();
    
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO items (item_name, category_id, category, description, current_quantity, unit_price, reorder_level) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$item_name, $category_id, $cat_name, $description, $initial_quantity, $unit_price, $reorder_level]);
        $item_id = $pdo->lastInsertId();
        
        if ($initial_quantity > 0) {
            $pdo->prepare("INSERT INTO stock_receiving (item_id, supplier, quantity, unit_price, date_received, received_by) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$item_id, 'Initial Stock', $initial_quantity, $unit_price, date('Y-m-d'), $_SESSION['user_id']]);
        }
        
        logAudit($pdo, $_SESSION['user_id'], 'ADD_ITEM', $item_id, $initial_quantity, "Added item: $item_name in category: $cat_name");
        $pdo->commit();
        $success = "Item added successfully!";
    } catch (Exception $e) {
        $pdo->rollBack();
        $error = 'Error adding item: ' . $e->getMessage();
    }
}

// Handle Edit Item
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_item'])) {
    $item_id = (int)$_POST['item_id'];
    $item_name = trim($_POST['item_name']);
    $category_id = (int)$_POST['category_id'];
    $description = trim($_POST['description']);
    $unit_price = (float)$_POST['unit_price'];
    $reorder_level = (int)$_POST['reorder_level'];
    
    $cat = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
    $cat->execute([$category_id]);
    $cat_name = $cat->fetchColumn();
    
    $pdo->prepare("UPDATE items SET item_name=?, category_id=?, category=?, description=?, unit_price=?, reorder_level=? WHERE id=?")
        ->execute([$item_name, $category_id, $cat_name, $description, $unit_price, $reorder_level, $item_id]);
    logAudit($pdo, $_SESSION['user_id'], 'EDIT_ITEM', $item_id, null, "Updated item: $item_name");
    $success = 'Item updated successfully!';
}

// Handle Delete Item
if (isset($_GET['delete_item'])) {
    $id = (int)$_GET['delete_item'];
    
    $rc = $pdo->prepare("SELECT COUNT(*) as c FROM stock_receiving WHERE item_id=?"); 
    $rc->execute([$id]);
    $ic = $pdo->prepare("SELECT COUNT(*) as c FROM stock_issuing WHERE item_id=?"); 
    $ic->execute([$id]);
    
    if ($rc->fetch()['c'] > 0 || $ic->fetch()['c'] > 0) {
        $error = 'Cannot delete an item that has transaction history.';
    } else {
        $pdo->prepare("DELETE FROM items WHERE id=?")->execute([$id]);
        $success = 'Item deleted successfully!';
    }
}

// Build query with filters
$query = "
    SELECT i.*, c.name as category_name
    FROM items i
    LEFT JOIN categories c ON i.category_id = c.id
    WHERE 1=1
";
$params = array();

if ($filter_category > 0) {
    $query .= " AND i.category_id = ?";
    $params[] = $filter_category;
}

$query .= " ORDER BY i.category, i.item_name";

$items_stmt = $pdo->prepare($query);
$items_stmt->execute($params);
$items = $items_stmt->fetchAll();

// Get item being edited
$edit_item = null;
if (isset($_GET['edit_item'])) {
    $stmt = $pdo->prepare("SELECT * FROM items WHERE id = ?");
    $stmt->execute([$_GET['edit_item']]);
    $edit_item = $stmt->fetch();
}

// Get category being edited
$edit_category = null;
if (isset($_GET['edit_category'])) {
    $stmt = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
    $stmt->execute([$_GET['edit_category']]);
    $edit_category = $stmt->fetch();
}

include __DIR__ . '/../includes/header.php';
?>

<style>
.items-container {
    max-width: 1400px;
    margin: 0 auto;
}

.filter-card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 25px;
    border: 1px solid var(--border);
}

.filter-section {
    margin-bottom: 15px;
}

.filter-label {
    font-weight: 600;
    font-size: 0.8rem;
    margin-bottom: 8px;
    display: block;
    color: var(--muted);
}

.category-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.category-btn {
    padding: 6px 14px;
    border: 1px solid var(--border);
    border-radius: 20px;
    background: var(--surface);
    text-decoration: none;
    color: var(--text);
    font-size: 0.8rem;
    transition: all 0.2s;
}

.category-btn:hover {
    border-color: var(--accent);
    background: var(--accent-lt);
}

.category-btn.active {
    background: var(--accent);
    color: white;
    border-color: var(--accent);
}

.action-buttons {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    justify-content: flex-end;
}

.form-card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 25px;
    border: 1px solid var(--border);
}

.form-title {
    font-size: 1rem;
    font-weight: 600;
    margin-bottom: 15px;
    padding-bottom: 8px;
    border-bottom: 2px solid var(--accent);
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    font-size: 0.8rem;
}

.form-group input,
.form-group select,
.form-group textarea {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--border);
    border-radius: 6px;
    font-size: 0.85rem;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 15px;
}

.btn {
    padding: 8px 16px;
    border-radius: 6px;
    border: none;
    cursor: pointer;
    font-size: 0.85rem;
    text-decoration: none;
    display: inline-block;
}

.btn-primary {
    background: var(--accent);
    color: white;
}

.btn-primary:hover {
    background: #1d4ed8;
}

.btn-success {
    background: var(--success);
    color: white;
}

.btn-success:hover {
    background: #15803d;
}

.btn-danger {
    background: var(--danger);
    color: white;
}

.btn-danger:hover {
    background: #b91c1c;
}

.btn-warning {
    background: var(--warning);
    color: white;
}

.btn-warning:hover {
    background: #b45309;
}

.btn-sm {
    padding: 4px 10px;
    font-size: 0.75rem;
}

.btn-outline {
    background: transparent;
    border: 1px solid var(--border);
    color: var(--text);
}

.btn-outline:hover {
    border-color: var(--accent);
    color: var(--accent);
}

.table-container {
    overflow-x: auto;
}

.items-table {
    width: 100%;
    border-collapse: collapse;
}

.items-table th {
    text-align: left;
    padding: 10px 12px;
    background: #f9fafb;
    border-bottom: 2px solid var(--border);
    font-weight: 600;
    font-size: 0.8rem;
}

.items-table td {
    padding: 10px 12px;
    border-bottom: 1px solid var(--border);
    font-size: 0.85rem;
}

.cat-pill {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.7rem;
    background: var(--accent-lt);
    color: var(--accent);
}

.status-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.7rem;
    font-weight: 500;
}

.status-low {
    background: #fef3c7;
    color: #92400e;
}

.status-ok {
    background: #dcfce7;
    color: #166534;
}

.alert {
    padding: 10px 15px;
    border-radius: 6px;
    margin-bottom: 20px;
    font-size: 0.85rem;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-danger {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.empty-state {
    text-align: center;
    padding: 40px;
    color: var(--muted);
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    justify-content: center;
    align-items: center;
}

.modal-content {
    background: var(--surface);
    border-radius: var(--radius);
    width: 90%;
    max-width: 500px;
    padding: 20px;
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 1px solid var(--border);
}

.modal-close {
    background: none;
    border: none;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--muted);
}
</style>

<div class="items-container">
    <h1>Manage Items</h1>

    <!-- Filter Section -->
    <div class="filter-card">
        <div class="filter-section">
            <div class="filter-label">Category</div>
            <div class="category-buttons">
                <a href="manage_items.php" class="category-btn <?php echo $filter_category == 0 ? 'active' : ''; ?>">All Categories</a>
                <?php foreach ($categories as $cat): ?>
                <a href="?category_id=<?php echo $cat['id']; ?>" class="category-btn <?php echo $filter_category == $cat['id'] ? 'active' : ''; ?>">
                    <?php echo htmlspecialchars($cat['name']); ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="action-buttons">
        <button onclick="openCategoryModal()" class="btn btn-outline btn-sm">Manage Categories</button>
        <button onclick="openAddItemModal()" class="btn btn-primary btn-sm">+ Add Item</button>
    </div>

    <!-- Success/Error Messages -->
    <?php if (isset($success)): ?>
    <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if (isset($error)): ?>
    <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>

    <!-- Items Table -->
    <div class="form-card">
        <div class="form-title">
            Items
            <?php if ($filter_category > 0): ?>
            in <?php 
                $cat_name = '';
                foreach ($categories as $cat) {
                    if ($cat['id'] == $filter_category) {
                        $cat_name = $cat['name'];
                        break;
                    }
                }
                echo htmlspecialchars($cat_name);
            ?>
            <?php endif; ?>
            <span style="font-size: 0.75rem; font-weight: normal;">(<?php echo count($items); ?> items)</span>
        </div>
        
        <?php if (count($items) > 0): ?>
        <div class="table-container">
            <table class="items-table">
                <thead>
                    <tr>
                        <th>Item Name</th>
                        <th>Category</th>
                        <th>Stock</th>
                        <th>Price</th>
                        <th>Value</th>
                        <th>Reorder</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                    <tr>
                        <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']); ?></td>
                        <td><span class="cat-pill"><?php echo htmlspecialchars($item['category']); ?></span></td>
                        <td><?php echo $item['current_quantity']; ?></td>
                        <td>K<?php echo number_format($item['unit_price'], 2); ?></td>
                        <td>K<?php echo number_format($item['current_quantity'] * $item['unit_price'], 2); ?></td>
                        <td><?php echo $item['reorder_level']; ?></td>
                        <td>
                            <?php if ($item['current_quantity'] <= $item['reorder_level']): ?>
                                <span class="status-badge status-low">Low Stock</span>
                            <?php else: ?>
                                <span class="status-badge status-ok">OK</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap">
                            <a href="?edit_item=<?php echo $item['id']; ?><?php echo $filter_category ? '&category_id='.$filter_category : ''; ?>" class="btn btn-outline btn-sm">Edit</a>
                            <a href="?delete_item=<?php echo $item['id']; ?>" class="btn btn-outline btn-sm" onclick="return confirm('Delete this item?')">Delete</a>
                            <a href="receive_stock.php?item=<?php echo $item['id']; ?>" class="btn btn-outline btn-sm">Receive</a>
                            <a href="issue_stock.php?item=<?php echo $item['id']; ?>" class="btn btn-outline btn-sm">Issue</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <p>No items found.</p>
            <?php if ($filter_category > 0): ?>
            <p style="font-size: 0.8rem;">Try selecting a different category or add items to this category.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Manage Categories Modal -->
<div id="categoryModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Manage Categories</h3>
            <button class="modal-close" onclick="closeCategoryModal()">&times;</button>
        </div>
        
        <!-- Add Category Form -->
        <form method="POST">
            <div class="form-row">
                <div class="form-group">
                    <label>Category Name</label>
                    <input type="text" name="category_name" required placeholder="New category name">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" name="category_desc" placeholder="Optional description">
                </div>
            </div>
            <button type="submit" name="add_category" class="btn btn-primary btn-sm">Add Category</button>
        </form>
        
        <hr style="margin: 20px 0;">
        
        <!-- Categories List -->
        <h4>Existing Categories</h4>
        <div style="display: flex; flex-direction: column; gap: 10px; margin-top: 10px;">
            <?php foreach ($categories as $cat): ?>
            <div style="display: flex; justify-content: space-between; align-items: center; padding: 8px 0; border-bottom: 1px solid var(--border);">
                <div>
                    <strong><?php echo htmlspecialchars($cat['name']); ?></strong>
                    <?php 
                    $item_count = $pdo->prepare("SELECT COUNT(*) FROM items WHERE category_id = ?");
                    $item_count->execute([$cat['id']]);
                    $count = $item_count->fetchColumn();
                    ?>
                    <span style="font-size: 0.7rem; color: var(--muted);">(<?php echo $count; ?> items)</span>
                </div>
                <div style="display: flex; gap: 8px;">
                    <a href="?edit_category=<?php echo $cat['id']; ?>" class="btn btn-outline btn-sm">Edit</a>
                    <?php if ($count == 0 && $cat['name'] != 'Other'): ?>
                    <a href="?delete_category=<?php echo $cat['id']; ?>" class="btn btn-outline btn-sm" onclick="return confirm('Delete this category?')">Delete</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Edit Category Form (if editing) -->
        <?php if ($edit_category): ?>
        <hr style="margin: 20px 0;">
        <form method="POST">
            <input type="hidden" name="category_id" value="<?php echo $edit_category['id']; ?>">
            <h4>Edit Category</h4>
            <div class="form-row">
                <div class="form-group">
                    <label>Category Name</label>
                    <input type="text" name="category_name" value="<?php echo htmlspecialchars($edit_category['name']); ?>" required>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <input type="text" name="category_desc" value="<?php echo htmlspecialchars($edit_category['description'] ?? ''); ?>">
                </div>
            </div>
            <button type="submit" name="edit_category" class="btn btn-warning btn-sm">Update Category</button>
            <a href="manage_items.php" class="btn btn-outline btn-sm">Cancel Edit</a>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- Add Item Modal -->
<div id="addItemModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Add New Item</h3>
            <button class="modal-close" onclick="closeAddItemModal()">&times;</button>
        </div>
        
        <form method="POST">
            <div class="form-group">
                <label>Item Name</label>
                <input type="text" name="item_name" required placeholder="e.g., A4 Paper Ream">
            </div>
            
            <div class="form-group">
                <label>Category</label>
                <select name="category_id" required>
                    <option value="">— Select Category —</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo ($filter_category == $cat['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Unit Price (K)</label>
                    <input type="number" name="unit_price" step="0.01" min="0" required placeholder="0.00">
                </div>
                <div class="form-group">
                    <label>Reorder Level</label>
                    <input type="number" name="reorder_level" min="0" required value="10">
                </div>
            </div>
            
            <div class="form-group">
                <label>Initial Quantity</label>
                <input type="number" name="initial_quantity" min="0" value="0">
                <small style="font-size: 0.7rem;">Initial stock if adding with existing stock</small>
            </div>
            
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" rows="2" placeholder="Brief description (optional)"></textarea>
            </div>
            
            <button type="submit" name="add_item" class="btn btn-success">Add Item</button>
            <button type="button" onclick="closeAddItemModal()" class="btn btn-outline">Cancel</button>
        </form>
    </div>
</div>

<!-- Edit Item Form (inline, shown when editing) -->
<?php if ($edit_item): ?>
<div class="form-card" style="background: #fef9e6;">
    <div class="form-title">Edit Item: <?php echo htmlspecialchars($edit_item['item_name']); ?></div>
    <form method="POST">
        <input type="hidden" name="item_id" value="<?php echo $edit_item['id']; ?>">
        <div class="form-row">
            <div class="form-group">
                <label>Item Name</label>
                <input type="text" name="item_name" value="<?php echo htmlspecialchars($edit_item['item_name']); ?>" required>
            </div>
            <div class="form-group">
                <label>Category</label>
                <select name="category_id" required>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo $cat['id']; ?>" <?php echo ($edit_item['category_id'] == $cat['id']) ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($cat['name']); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label>Unit Price (K)</label>
                <input type="number" name="unit_price" step="0.01" min="0" value="<?php echo $edit_item['unit_price']; ?>" required>
            </div>
            <div class="form-group">
                <label>Reorder Level</label>
                <input type="number" name="reorder_level" min="0" value="<?php echo $edit_item['reorder_level']; ?>" required>
            </div>
        </div>
        <div class="form-group">
            <label>Description</label>
            <textarea name="description" rows="2"><?php echo htmlspecialchars($edit_item['description'] ?? ''); ?></textarea>
        </div>
        <div class="form-actions">
            <button type="submit" name="edit_item" class="btn btn-warning">Update Item</button>
            <a href="manage_items.php<?php echo $filter_category ? '?category_id='.$filter_category : ''; ?>" class="btn btn-outline">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<script>
function openCategoryModal() {
    document.getElementById('categoryModal').style.display = 'flex';
}

function closeCategoryModal() {
    document.getElementById('categoryModal').style.display = 'none';
}

function openAddItemModal() {
    document.getElementById('addItemModal').style.display = 'flex';
}

function closeAddItemModal() {
    document.getElementById('addItemModal').style.display = 'none';
}

// Close modals when clicking outside
window.onclick = function(event) {
    var categoryModal = document.getElementById('categoryModal');
    var addItemModal = document.getElementById('addItemModal');
    if (event.target == categoryModal) {
        categoryModal.style.display = 'none';
    }
    if (event.target == addItemModal) {
        addItemModal.style.display = 'none';
    }
}

// Auto-hide alerts
setTimeout(function() {
    var alerts = document.querySelectorAll('.alert');
    alerts.forEach(function(alert) {
        alert.style.opacity = '0';
        setTimeout(function() { alert.remove(); }, 400);
    });
}, 5000);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>