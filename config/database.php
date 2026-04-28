<?php
// SQLite Database Configuration
$db_file = __DIR__ . '/../inventory.db';

try {
    $pdo = new PDO("sqlite:$db_file");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Enable foreign keys
    $pdo->exec("PRAGMA foreign_keys = ON");
    
    // Create all tables
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            role TEXT DEFAULT 'front_desk',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_name TEXT NOT NULL,
            category_id INTEGER,
            category TEXT,
            description TEXT,
            current_quantity INTEGER DEFAULT 0,
            unit_price REAL DEFAULT 0,
            reorder_level INTEGER DEFAULT 10,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (category_id) REFERENCES categories(id)
        );
        
        CREATE TABLE IF NOT EXISTS stock_receiving (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER NOT NULL,
            supplier TEXT,
            quantity INTEGER NOT NULL,
            unit_price REAL DEFAULT 0,
            date_received DATE,
            received_by INTEGER,
            old_stock INTEGER,
            new_stock INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (item_id) REFERENCES items(id),
            FOREIGN KEY (received_by) REFERENCES users(id)
        );
        
        CREATE TABLE IF NOT EXISTS stock_issuing (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL,
            issued_to TEXT,
            purpose TEXT,
            date_issued DATE,
            issued_by INTEGER,
            old_stock INTEGER,
            new_stock INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (item_id) REFERENCES items(id),
            FOREIGN KEY (issued_by) REFERENCES users(id)
        );
        
        CREATE TABLE IF NOT EXISTS procurement (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_name TEXT NOT NULL,
            quantity INTEGER NOT NULL,
            reason TEXT,
            category_id INTEGER,
            category TEXT,
            requested_by INTEGER,
            approved_by INTEGER,
            status TEXT DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (requested_by) REFERENCES users(id),
            FOREIGN KEY (approved_by) REFERENCES users(id),
            FOREIGN KEY (category_id) REFERENCES categories(id)
        );
        
        CREATE TABLE IF NOT EXISTS category_monthly_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_id INTEGER NOT NULL,
            report_month DATE NOT NULL,
            items_data TEXT,
            total_items INTEGER DEFAULT 0,
            total_units INTEGER DEFAULT 0,
            low_stock_count INTEGER DEFAULT 0,
            notes TEXT,
            submitted_by INTEGER,
            submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (category_id) REFERENCES categories(id),
            UNIQUE(category_id, report_month)
        );
        
        CREATE TABLE IF NOT EXISTS audit_trail (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT,
            item_id INTEGER,
            quantity_changed INTEGER,
            details TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );
    ");
    
    // Insert sample data if empty
    $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    if ($userCount == 0) {
        // Insert categories
        $pdo->exec("INSERT INTO categories (name, description) VALUES 
            ('Aircraft Parts', 'Essential components for aircraft maintenance'),
            ('Tools', 'Maintenance and repair tools'),
            ('Consumables', 'Items that are used up during service'),
            ('Safety Equipment', 'Safety gear and emergency equipment')");
        
        // Insert users (password: password123)
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (full_name, username, password, role) VALUES (?, ?, ?, ?)");
        $stmt->execute(['John Smith', 'manager', $hash, 'manager']);
        $stmt->execute(['Sarah Johnson', 'frontdesk', $hash, 'front_desk']);
        
        // Insert sample items
        $pdo->exec("INSERT INTO items (item_name, category_id, category, current_quantity, unit_price, reorder_level) VALUES
            ('Brake Pads', 1, 'Aircraft Parts', 8, 150.00, 10),
            ('Oil Filter', 1, 'Aircraft Parts', 0, 45.00, 5),
            ('Spark Plug', 1, 'Aircraft Parts', 20, 12.50, 10),
            ('Hydraulic Fluid', 3, 'Consumables', 4, 85.00, 10),
            ('Oxygen Mask', 4, 'Safety Equipment', 0, 200.00, 5),
            ('Wrench Set', 2, 'Tools', 15, 75.00, 5),
            ('Multimeter', 2, 'Tools', 6, 120.00, 3),
            ('Fire Extinguisher', 4, 'Safety Equipment', 10, 350.00, 3)");
    }
    
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
} catch (PDOException $e) {
    die("Database Error: " . $e->getMessage());
}

// Helper functions
function isManager() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'manager';
}

function isFrontDesk() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'front_desk';
}

function redirectIfNotLoggedIn() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit();
    }
}

function logAudit($pdo, $user_id, $action, $item_id, $quantity, $details) {
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, item_id, quantity_changed, details) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $action, $item_id, $quantity, $details]);
    } catch (Exception $e) {}
}

function getCategories($pdo) {
    $stmt = $pdo->query("SELECT DISTINCT category FROM items WHERE category IS NOT NULL");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function catClass($category) {
    $classes = ['Aircraft Parts' => 'cat-0', 'Tools' => 'cat-1', 'Consumables' => 'cat-2', 'Safety Equipment' => 'cat-3'];
    return $classes[$category] ?? 'cat-6';
}
?>
