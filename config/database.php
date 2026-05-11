<?php
// MySQL Database Configuration
$host = 'localhost';
$dbname = 'inventory_system';
$username = 'root';
$password = 'king.dee/0306';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Start session if not already started
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

// Helper functions
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'manager');
}

function isManager() {
    return isset($_SESSION['role']) && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'manager');
}

function isFrontDesk() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'front_desk';
}

function isTechnician() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'technician';
}

function redirectIfNotLoggedIn() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}

function logAudit($pdo, $user_id, $action, $item_id, $quantity, $details) {
    try {
        $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, action, item_id, quantity, details) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $action, $item_id, $quantity, $details]);
    } catch (Exception $e) {
        // Silent fail for audit logging
    }
}

function getCategories($pdo) {
    $stmt = $pdo->query("SELECT DISTINCT category FROM items WHERE category IS NOT NULL AND category != ''");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function getLowStockItems($pdo) {
    $stmt = $pdo->query("SELECT * FROM items WHERE current_quantity <= reorder_level AND current_quantity > 0");
    return $stmt->fetchAll();
}

function getOutOfStockItems($pdo) {
    $stmt = $pdo->query("SELECT * FROM items WHERE current_quantity = 0");
    return $stmt->fetchAll();
}

function catClass($category) {
    $classes = ['Aircraft Parts' => 'cat-0', 'Tools' => 'cat-1', 'Consumables' => 'cat-2', 'Safety Equipment' => 'cat-3'];
    return $classes[$category] ?? 'cat-6';
}
?>
