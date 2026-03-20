<?php
require_once __DIR__ . '/../includes/auth.php';
redirectIfNotLoggedIn();
if (!isManager()) { header('Location: dashboard.php'); exit(); }

// Handle Category Operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Add new category
    if (isset($_POST['add_category'])) {
        $cat_name = trim($_POST['category_name']);
        $cat_desc = trim($_POST['category_desc']);
        
        if (!empty($cat_name)) {
            $check = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
            $check->execute([$cat_name]);
            if (!$check->fetch()) {
                $pdo->prepare("INSERT INTO categories (name, description) VALUES (?, ?)")->execute([$cat_name, $cat_desc]);
                $success_cat = "Category '$cat_name' added successfully!";
                logAudit($pdo, $_SESSION['user_id'], 'ADD_CATEGORY', null, null, "Added category: $cat_name");
            } else {
                $error_cat = "Category already exists!";
            }
        }
    }
    
    // Edit category
    if (isset($_POST['edit_category'])) {
        $cat_id = (int)$_POST['category_id'];
        $cat_name = trim($_POST['category_name']);
        $cat_desc = trim($_POST['category_desc']);
        
        if (!empty($cat_name)) {
            $check = $pdo->prepare("SELECT id FROM categories WHERE name = ? AND id != ?");
            $check->execute([$cat_name, $cat_id]);
            if (!$check->fetch()) {
                $pdo->prepare("UPDATE categories SET name = ?, description = ? WHERE id = ?")->execute([$cat_name, $cat_desc, $cat_id]);
                $pdo->prepare("UPDATE items SET category = ? WHERE category_id = ?")->execute([$cat_name, $cat_id]);
                $success_cat = "Category updated successfully!";
                logAudit($pdo, $_SESSION['user_id'], 'EDIT_CATEGORY', null, null, "Updated category: $cat_name");
            } else {
                $error_cat = "Category name already exists!";
            }
        }
    }
    
    // Delete category
    if (isset($_POST['delete_category'])) {
        $cat_id = (int)$_POST['category_id'];
        $cat_name = trim($_POST['category_name']);
        
        $check = $pdo->prepare("SELECT COUNT(*) FROM items WHERE category_id = ?");
        $check->execute([$cat_id]);
        $item_count = $check->fetchColumn();
        
        if ($item_count == 0) {
            $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$cat_id]);
            $success_cat = "Category '$cat_name' deleted successfully!";
            logAudit($pdo, $_SESSION['user_id'], 'DELETE_CATEGORY', null, null, "Deleted category: $cat_name");
        } else {
            $error_cat = "Cannot delete category with $item_count items. Move or delete items first.";
        }
    }
    
    // Add new item to category
    if (isset($_POST['add_item'])) {
        $item_name = trim($_POST['item_name']);
        $category_id = (int)$_POST['category_id'];
        $description = trim($_POST['description']);
        $unit_price = (float)$_POST['unit_price'];
        $reorder_level = (int)$_POST['reorder_level'];
        $initial_quantity = (int)$_POST['initial_quantity'];
        
        $cat_stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
        $cat_stmt->execute([$category_id]);
        $cat_name = $cat_stmt->fetchColumn();
        
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
            $success = "Item '$item_name' added successfully!";
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error adding item: ' . $e->getMessage();
        }
    }
    
    // Edit item
    if (isset($_POST['edit_item'])) {
        $item_id = (int)$_POST['item_id'];
        $item_name = trim($_POST['item_name']);
        $category_id = (int)$_POST['category_id'];
        $description = trim($_POST['description']);
        $unit_price = (float)$_POST['unit_price'];
        $reorder_level = (int)$_POST['reorder_level'];
        
        $cat_stmt = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
        $cat_stmt->execute([$category_id]);
        $cat_name = $cat_stmt->fetchColumn();
        
        $pdo->prepare("UPDATE items SET item_name=?, category_id=?, category=?, description=?, unit_price=?, reorder_level=? WHERE id=?")
            ->execute([$item_name, $category_id, $cat_name, $description, $unit_price, $reorder_level, $item_id]);
        logAudit($pdo, $_SESSION['user_id'], 'EDIT_ITEM', $item_id, null, "Updated item: $item_name");
        $success = 'Item updated successfully!';
    }
}

// Handle Item Deletion
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $item_name = $_GET['item_name'] ?? '';
    $category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;
    
    $rc = $pdo->prepare("SELECT COUNT(*) as c FROM stock_receiving WHERE item_id=?"); 
    $rc->execute([$id]);
    $ic = $pdo->prepare("SELECT COUNT(*) as c FROM stock_issuing WHERE item_id=?"); 
    $ic->execute([$id]);
    
    if ($rc->fetch()['c'] > 0 || $ic->fetch()['c'] > 0) {
        $error = 'Cannot delete an item that has transaction history.';
    } else {
        $pdo->prepare("DELETE FROM items WHERE id=?")->execute([$id]);
        logAudit($pdo, $_SESSION['user_id'], 'DELETE_ITEM', $id, null, "Deleted item: $item_name");
        $success = 'Item deleted successfully!';
    }
}

// Get filter parameters
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$selected_category_id = isset($_GET['category_id']) ? (int)$_GET['category_id'] : 0;

// Build items query with filters
$items_query = "SELECT i.*, c.name as category_name FROM items i LEFT JOIN categories c ON i.category_id = c.id WHERE 1=1";
$params = [];

if ($selected_category_id > 0) {
    $items_query .= " AND i.category_id = ?";
    $params[] = $selected_category_id;
}

if ($search_query != '') {
    $items_query .= " AND i.item_name LIKE ?";
    $params[] = "%$search_query%";
}

$items_query .= " ORDER BY i.category, i.item_name";
$stmt = $pdo->prepare($items_query);
$stmt->execute($params);
$items = $stmt->fetchAll();

// Get dashboard statistics
$stats = $pdo->query("
    SELECT 
        COUNT(*) as total_items,
        SUM(current_quantity) as total_stock,
        SUM(current_quantity * unit_price) as total_value,
        COUNT(CASE WHEN current_quantity <= reorder_level THEN 1 END) as low_stock_count
    FROM items
")->fetch();

// Get all categories
$categories = $pdo->query("SELECT id, name, description FROM categories ORDER BY name")->fetchAll();
$category_counts = $pdo->query("
    SELECT c.id, c.name, c.description, COUNT(i.id) as item_count,
           SUM(i.current_quantity * i.unit_price) as category_value
    FROM categories c 
    LEFT JOIN items i ON c.id = i.category_id 
    GROUP BY c.id 
    ORDER BY c.name
")->fetchAll();

// Item being edited
$edit_item = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare("SELECT * FROM items WHERE id=?");
    $s->execute([$_GET['edit']]);
    $edit_item = $s->fetch();
}

// Category being edited
$edit_category = null;
if (isset($_GET['edit_category'])) {
    $s = $pdo->prepare("SELECT * FROM categories WHERE id=?");
    $s->execute([$_GET['edit_category']]);
    $edit_category = $s->fetch();
}

include __DIR__ . '/../includes/header.php';
?>

<style>
/* Dashboard Stats Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 20px;
    box-shadow: var(--shadow);
    border: 1px solid var(--border);
    transition: transform 0.2s, box-shadow 0.2s;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: var(--shadow-lg);
}

.stat-value {
    font-size: 2rem;
    font-weight: 700;
    color: var(--text);
    line-height: 1.2;
    margin-bottom: 8px;
}

.stat-label {
    font-size: 0.85rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.stat-card.low-stock .stat-value {
    color: var(--danger);
}

.stat-card.high-value .stat-value {
    color: var(--success);
}

/* Action Buttons Bar */
.action-bar {
    display: flex;
    gap: 12px;
    margin-bottom: 25px;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
}

.action-buttons {
    display: flex;
    gap: 12px;
}

.search-box {
    flex: 1;
    min-width: 250px;
    position: relative;
}

.search-box form {
    display: flex;
    gap: 10px;
}

.search-box input {
    flex: 1;
    padding: 10px 15px;
    border: 1.5px solid var(--border);
    border-radius: 8px;
    font-size: 0.9rem;
    font-family: var(--font);
    transition: all 0.2s;
}

.search-box input:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(37,99,235,0.1);
}

.category-filter select {
    padding: 10px 15px;
    border: 1.5px solid var(--border);
    border-radius: 8px;
    font-size: 0.9rem;
    font-family: var(--font);
    background: var(--surface);
    cursor: pointer;
    min-width: 180px;
}

.category-filter select:focus {
    outline: none;
    border-color: var(--accent);
}

/* Full Screen Modal */
.modal-fullscreen {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: var(--surface);
    z-index: 1000;
    overflow-y: auto;
    animation: slideIn 0.3s ease;
}

@keyframes slideIn {
    from {
        transform: translateX(100%);
    }
    to {
        transform: translateX(0);
    }
}

.modal-fullscreen-content {
    padding: 30px;
    min-height: 100vh;
}

.modal-fullscreen-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 1px solid var(--border);
}

.modal-fullscreen-header h2 {
    margin: 0;
    font-size: 1.5rem;
}

.modal-fullscreen-close {
    background: none;
    border: none;
    font-size: 2rem;
    cursor: pointer;
    color: var(--muted);
    transition: color 0.2s;
    padding: 0 10px;
}

.modal-fullscreen-close:hover {
    color: var(--danger);
}

/* Categories Section in Modal */
.modal-categories-section {
    display: block;
}

/* Categories Grid */
.modal-categories-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.modal-category-card {
    background: #f9fafb;
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 20px;
    cursor: pointer;
    transition: all 0.2s;
}

.modal-category-card:hover {
    border-color: var(--accent);
    transform: translateY(-2px);
    box-shadow: var(--shadow);
}

.modal-category-name {
    font-weight: 600;
    font-size: 1.1rem;
    color: var(--text);
    margin-bottom: 8px;
}

.modal-category-desc {
    font-size: 0.85rem;
    color: var(--muted);
    margin-bottom: 10px;
}

.modal-category-stats {
    font-size: 0.8rem;
    color: var(--accent);
    margin-top: 8px;
}

/* Category Items Section - Takes full modal */
.category-items-full {
    display: block;
}

.category-items-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
    gap: 15px;
}

.category-items-header h3 {
    margin: 0;
    font-size: 1.2rem;
}

/* Add Item Form in Modal */
.add-item-form-modal {
    background: #f9fafb;
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 25px;
}

.add-item-form-modal h4 {
    margin-top: 0;
    margin-bottom: 15px;
}

/* Items Table in Modal */
.modal-items-table {
    width: 100%;
    overflow-x: auto;
    margin-top: 20px;
}

.modal-items-table table {
    width: 100%;
    border-collapse: collapse;
}

.modal-items-table th {
    text-align: left;
    padding: 12px;
    background: #f9fafb;
    border-bottom: 2px solid var(--border);
    font-weight: 600;
}

.modal-items-table td {
    padding: 12px;
    border-bottom: 1px solid var(--border);
}

/* Back button */
.back-to-categories {
    display: inline-block;
    margin-bottom: 25px;
    color: var(--accent);
    text-decoration: none;
    font-size: 0.9rem;
}

.back-to-categories:hover {
    text-decoration: underline;
}

/* Edit Item Form */
.edit-item-form {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 20px;
    margin-bottom: 30px;
    border: 1px solid var(--border);
}

.edit-item-form.editing {
    background: #fef9e6;
    border-left: 4px solid #f59e0b;
}

/* Items Table Wrapper */
.items-table-wrapper {
    background: var(--surface);
    border-radius: var(--radius);
    padding: 20px;
    border: 1px solid var(--border);
    overflow-x: auto;
}

.btn-link {
    background: none;
    border: none;
    color: var(--muted);
    font-size: 0.75rem;
    cursor: pointer;
    padding: 0;
    text-decoration: none;
}

.btn-link:hover {
    color: var(--accent);
}

.btn-link-danger:hover {
    color: var(--danger);
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.section-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--text);
    margin: 0;
}

/* Add Category Form */
.add-category-form {
    background: #f9fafb;
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 20px;
    margin-bottom: 30px;
}
</style>

<h1>Manage Items</h1>

<?php if (isset($success)): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>
<?php if (isset($error)): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if (isset($success_cat)): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($success_cat); ?></div>
<?php endif; ?>
<?php if (isset($error_cat)): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error_cat); ?></div>
<?php endif; ?>

<!-- Dashboard Stats -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['total_items'] ?? 0); ?></div>
        <div class="stat-label">Total Items</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?php echo number_format($stats['total_stock'] ?? 0); ?></div>
        <div class="stat-label">Total Stock Units</div>
    </div>
    <div class="stat-card high-value">
        <div class="stat-value">K<?php echo number_format($stats['total_value'] ?? 0, 2); ?></div>
        <div class="stat-label">Total Inventory Value</div>
    </div>
    <div class="stat-card low-stock">
        <div class="stat-value"><?php echo number_format($stats['low_stock_count'] ?? 0); ?></div>
        <div class="stat-label">Low Stock Items</div>
    </div>
</div>

<!-- Action Bar -->
<div class="action-bar">
    <div class="action-buttons">
        <button onclick="openCategoryModal()" class="btn btn-primary">Manage Categories</button>
    </div>
    <div class="search-box">
        <form method="GET">
            <input type="text" name="search" placeholder="Search items by name..." value="<?php echo htmlspecialchars($search_query); ?>">
            <button type="submit" class="btn btn-primary">Search</button>
            <?php if ($search_query): ?>
            <a href="manage_items.php<?php echo $selected_category_id ? '?category_id='.$selected_category_id : ''; ?>" class="btn">Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <div class="category-filter">
        <form method="GET">
            <select name="category_id" onchange="this.form.submit()">
                <option value="0">All Categories</option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?php echo $cat['id']; ?>" <?php echo $selected_category_id == $cat['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($cat['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
            <?php if ($selected_category_id > 0): ?>
            <a href="manage_items.php<?php echo $search_query ? '?search='.urlencode($search_query) : ''; ?>" class="btn btn-sm" style="margin-left: 8px;">Clear Filter</a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Edit Item Form (on main page) -->
<?php if ($edit_item): ?>
<div class="edit-item-form editing">
    <div class="section-header">
        <h3 class="section-title">Edit Item: <?php echo htmlspecialchars($edit_item['item_name']); ?></h3>
    </div>
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
                <input type="number" name="unit_price" min="0" step="0.01" value="<?php echo $edit_item['unit_price']; ?>" required>
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
            <a href="manage_items.php<?php echo $selected_category_id ? '?category_id='.$selected_category_id : ''; ?><?php echo $search_query ? '&search='.urlencode($search_query) : ''; ?>" class="btn">Cancel</a>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- Items Table -->
<div class="items-table-wrapper">
    <h3 class="section-title" style="margin-bottom: 20px;">
        <?php if ($selected_category_id > 0): ?>
            Items in <?php echo htmlspecialchars($categories[array_search($selected_category_id, array_column($categories, 'id'))]['name'] ?? 'Selected Category'); ?>
        <?php else: ?>
            All Items
        <?php endif; ?>
        <span style="font-weight: 400; font-size: 0.85rem; color: var(--muted);">
            (<?php echo count($items); ?> items)
        </span>
    </h3>
    
    <?php if (count($items) > 0): ?>
    <div class="table-container">
        <table>
            <thead>
                32
                    <th>Item Name</th>
                    <th>Category</th>
                    <th>Description</th>
                    <th>Stock</th>
                    <th>Unit Price</th>
                    <th>Total Value</th>
                    <th>Reorder</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td style="font-weight:500"><?php echo htmlspecialchars($item['item_name']); ?></td>
                    <td><span class="cat-pill <?php echo catClass($item['category']); ?>"><?php echo htmlspecialchars($item['category']); ?></span></td>
                    <td style="color:var(--muted);font-size:.84rem"><?php echo htmlspecialchars($item['description'] ?? ''); ?></td>
                    <td><?php echo $item['current_quantity']; ?></td>
                    <td>K<?php echo number_format($item['unit_price'], 2); ?></td>
                    <td>K<?php echo number_format($item['current_quantity'] * $item['unit_price'], 2); ?></td>
                    <td><?php echo $item['reorder_level']; ?></td>
                    <td>
                        <?php if ($item['current_quantity'] <= $item['reorder_level']): ?>
                            <span class="status-pending">Low Stock</span>
                        <?php else: ?>
                            <span class="status-receiving">OK</span>
                        <?php endif; ?>
                    </td>
                    <td style="white-space:nowrap">
                        <a href="?edit=<?php echo $item['id']; ?><?php echo $selected_category_id ? '&category_id='.$selected_category_id : ''; ?><?php echo $search_query ? '&search='.urlencode($search_query) : ''; ?>" class="btn btn-sm">Edit</a>
                        <a href="?delete=<?php echo $item['id']; ?>&item_name=<?php echo urlencode($item['item_name']); ?>" class="btn btn-sm" onclick="return confirm('Delete this item permanently?')">Delete</a>
                        <a href="receive_stock.php?item=<?php echo $item['id']; ?>" class="btn btn-sm">Receive</a>
                        <a href="issue_stock.php?item=<?php echo $item['id']; ?>" class="btn btn-sm">Issue</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div style="text-align: center; padding: 60px; color: var(--muted);">
        <div style="font-size: 3rem; margin-bottom: 15px;">📦</div>
        <p>No items found.</p>
        <?php if ($search_query): ?>
        <p>Try a different search term or clear the search filter.</p>
        <a href="manage_items.php<?php echo $selected_category_id ? '?category_id='.$selected_category_id : ''; ?>" class="btn btn-primary">Clear Search</a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Full Screen Category Modal -->
<div id="categoryModal" class="modal-fullscreen">
    <div class="modal-fullscreen-content">
        <div class="modal-fullscreen-header">
            <h2 id="modalHeaderTitle">Manage Categories</h2>
            <button class="modal-fullscreen-close" onclick="closeCategoryModal()">&times;</button>
        </div>
        
        <!-- Categories View (Default) -->
        <div id="categoriesView">
            <!-- Add Category Form -->
            <div class="add-category-form">
                <h3 style="margin-top: 0; margin-bottom: 15px;">Add New Category</h3>
                <form method="POST">
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                        <div>
                            <label style="display: block; margin-bottom: 5px;">Category Name</label>
                            <input type="text" name="category_name" placeholder="e.g., Electronics" required style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 5px;">
                        </div>
                        <div>
                            <label style="display: block; margin-bottom: 5px;">Description</label>
                            <input type="text" name="category_desc" placeholder="Brief description" style="width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 5px;">
                        </div>
                    </div>
                    <div style="margin-top: 15px;">
                        <button type="submit" name="add_category" class="btn btn-primary">Add Category</button>
                    </div>
                </form>
            </div>
            
            <!-- Categories Grid -->
            <h3>All Categories</h3>
            <div id="categoriesGrid" class="modal-categories-grid">
                <?php foreach ($category_counts as $cat): ?>
                <div class="modal-category-card" onclick="viewCategoryItems(<?php echo $cat['id']; ?>, '<?php echo addslashes($cat['name']); ?>')">
                    <div class="modal-category-name"><?php echo htmlspecialchars($cat['name']); ?></div>
                    <?php if ($cat['description']): ?>
                    <div class="modal-category-desc"><?php echo htmlspecialchars($cat['description']); ?></div>
                    <?php endif; ?>
                    <div class="modal-category-stats"><?php echo $cat['item_count']; ?> items | K<?php echo number_format($cat['category_value'] ?? 0, 2); ?></div>
                    <div style="margin-top: 12px; display: flex; gap: 10px;" onclick="event.stopPropagation()">
                        <a href="?edit_category=<?php echo $cat['id']; ?>" class="btn-link">Edit</a>
                        <?php if ($cat['name'] !== 'Other' && $cat['item_count'] == 0): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="category_id" value="<?php echo $cat['id']; ?>">
                            <input type="hidden" name="category_name" value="<?php echo htmlspecialchars($cat['name']); ?>">
                            <button type="submit" name="delete_category" class="btn-link btn-link-danger" onclick="return confirm('Delete category \'<?php echo addslashes($cat['name']); ?>\'?')">Delete</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Category Items View (Hidden by default) -->
        <div id="categoryItemsView" style="display: none;">
            <a href="javascript:void(0)" class="back-to-categories" onclick="showCategoriesView()">← Back to Categories</a>
            
            <div class="category-items-full">
                <div class="category-items-header">
                    <h3 id="selectedCategoryName">Category Items</h3>
                    <button onclick="showAddItemForm()" class="btn btn-success">+ Add Item</button>
                </div>
                
                <!-- Add Item Form (Hidden by default) -->
                <div id="addItemFormModal" style="display: none;" class="add-item-form-modal">
                    <h4>Add New Item</h4>
                    <form method="POST" id="addItemForm" onsubmit="return submitAddItemForm(event)">
                        <input type="hidden" name="category_id" id="modalCategoryId">
                        <div class="form-row">
                            <div class="form-group">
                                <label>Item Name</label>
                                <input type="text" name="item_name" id="item_name" required placeholder="e.g., A4 Paper Ream">
                            </div>
                            <div class="form-group">
                                <label>Unit Price (K)</label>
                                <input type="number" name="unit_price" id="unit_price" min="0" step="0.01" required placeholder="0.00">
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Reorder Level</label>
                                <input type="number" name="reorder_level" id="reorder_level" min="0" required value="10">
                            </div>
                            <div class="form-group">
                                <label>Initial Quantity</label>
                                <input type="number" name="initial_quantity" id="initial_quantity" min="0" value="0">
                                <small>Initial stock if adding with existing stock</small>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Description</label>
                            <textarea name="description" id="description" rows="2" placeholder="Brief description"></textarea>
                        </div>
                        <div class="form-actions">
                            <button type="submit" name="add_item" class="btn btn-success">Add Item</button>
                            <button type="button" onclick="hideAddItemForm()" class="btn">Cancel</button>
                        </div>
                    </form>
                </div>
                
                <!-- Items Table -->
                <div id="categoryItemsTable" class="modal-items-table">
                    <!-- Items will be loaded here dynamically -->
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let currentCategoryId = 0;
let currentCategoryName = '';

function openCategoryModal() {
    document.getElementById('categoryModal').style.display = 'block';
    showCategoriesView();
}

function closeCategoryModal() {
    document.getElementById('categoryModal').style.display = 'none';
}

function showCategoriesView() {
    document.getElementById('categoriesView').style.display = 'block';
    document.getElementById('categoryItemsView').style.display = 'none';
    document.getElementById('modalHeaderTitle').innerHTML = 'Manage Categories';
    hideAddItemForm();
}

function showCategoryItemsView() {
    document.getElementById('categoriesView').style.display = 'none';
    document.getElementById('categoryItemsView').style.display = 'block';
}

function viewCategoryItems(categoryId, categoryName) {
    currentCategoryId = categoryId;
    currentCategoryName = categoryName;
    
    document.getElementById('modalHeaderTitle').innerHTML = categoryName;
    document.getElementById('selectedCategoryName').innerHTML = categoryName;
    document.getElementById('modalCategoryId').value = categoryId;
    
    showCategoryItemsView();
    hideAddItemForm();
    loadCategoryItems(categoryId);
}

function loadCategoryItems(categoryId) {
    fetch(`get_category_items.php?category_id=${categoryId}&t=${Date.now()}`)
        .then(response => response.text())
        .then(data => {
            document.getElementById('categoryItemsTable').innerHTML = data;
        })
        .catch(error => {
            console.error('Error loading items:', error);
            document.getElementById('categoryItemsTable').innerHTML = '<p style="text-align:center;padding:40px;">Error loading items. Please refresh and try again.</p>';
        });
}

function showAddItemForm() {
    document.getElementById('addItemFormModal').style.display = 'block';
    document.getElementById('item_name').value = '';
    document.getElementById('unit_price').value = '';
    document.getElementById('reorder_level').value = '10';
    document.getElementById('initial_quantity').value = '0';
    document.getElementById('description').value = '';
}

function hideAddItemForm() {
    document.getElementById('addItemFormModal').style.display = 'none';
}

function submitAddItemForm(event) {
    event.preventDefault();
    
    const formData = new FormData(document.getElementById('addItemForm'));
    formData.append('add_item', '1');
    
    fetch('manage_items.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.text())
    .then(() => {
        loadCategoryItems(currentCategoryId);
        hideAddItemForm();
    })
    .catch(error => {
        console.error('Error adding item:', error);
        alert('Error adding item. Please try again.');
    });
    
    return false;
}

function deleteItem(itemId, itemName) {
    if (confirm(`Delete item "${itemName}" permanently?`)) {
        fetch(`manage_items.php?delete=${itemId}&item_name=${encodeURIComponent(itemName)}&category_id=${currentCategoryId}`, {
            method: 'GET'
        })
        .then(() => {
            loadCategoryItems(currentCategoryId);
        })
        .catch(error => {
            console.error('Error deleting item:', error);
            alert('Error deleting item. Please try again.');
        });
    }
}

function editItem(itemId) {
    window.location.href = `manage_items.php?edit=${itemId}&category_id=${currentCategoryId}`;
}

// Close modal when pressing Escape
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const modal = document.getElementById('categoryModal');
        if (modal.style.display === 'block') {
            closeCategoryModal();
        }
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>