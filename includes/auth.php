<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/validation.php';
require_once __DIR__ . '/org.php';
require_once __DIR__ . '/permissions.php';

// Access comes from the user's role (Admin › Roles). See includes/permissions.php.
function isLoggedIn(): bool { return isset($_SESSION['user_id']); }
function isAdmin(): bool    { return !empty($_SESSION['is_system_admin']); }

function redirectIfNotLoggedIn(): void {
    global $pdo;
    if (!isLoggedIn()) { header('Location: login.php'); exit(); }
    // Re-read the account on every page so role changes and disabled accounts take effect at once
    $st = $pdo->prepare("SELECT u.id, u.full_name, u.email, u.role, u.active, u.role_id, r.name AS role_name, r.is_system
                         FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?");
    $st->execute([$_SESSION['user_id']]);
    $u = $st->fetch();
    if (!$u || !(int)$u['active']) {
        $_SESSION = [];
        session_destroy();
        session_start();
        $_SESSION['login_notice'] = 'Your account has been disabled. Contact the IT department.';
        header('Location: login.php');
        exit();
    }
    $_SESSION['role']      = $u['role'];
    $_SESSION['role_name'] = $u['role_name'] ?? '';
    $_SESSION['is_system_admin'] = (int)($u['is_system'] ?? 0) === 1;
    $_SESSION['perms']     = $u['role_id'] ? role_permissions($pdo, (int)$u['role_id']) : [];
    $_SESSION['full_name'] = $u['full_name'];
    $_SESSION['email']     = $u['email'];
}

function requireAdmin(): void { requirePermission('admin.users'); }

function logAudit(PDO $pdo, int $user_id, string $action, ?int $item_id = null, ?float $quantity = null, ?string $details = null): void {
    try {
        $pdo->prepare("INSERT INTO audit_trail (user_id, action, item_id, quantity, details) VALUES (?,?,?,?,?)")
            ->execute([$user_id, $action, $item_id, $quantity, $details]);
    } catch (Exception $e) { error_log('Audit log failed: ' . $e->getMessage()); }
}

/**
 * Return a CSS class name deterministically based on a category string.
 * This ensures each category gets a consistent color.
 */
function catClass(?string $cat): string {
    // Map your categories to specific colors for better visual consistency
    $color_map = [
        'IT Equipment'        => 0,
        'Stationery'          => 1,
        'Kitchen & Cleaning'  => 2,
        'Printer Consumables' => 3,
        'Networking'          => 4,
        'Furniture'           => 5,
        'Other'               => 6
    ];
    
    if ($cat && isset($color_map[$cat])) {
        return 'cat-' . $color_map[$cat];
    }
    
    // Fallback to hash for any new categories
    return 'cat-' . (crc32($cat ?? 'Other') % 7);
}

/**
 * Fetch sorted unique category list from items table.
 */
function getCategories(PDO $pdo): array {
    return $pdo->query("SELECT DISTINCT category FROM items WHERE category != '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Get all categories from categories table
 */
function getAllCategories(PDO $pdo): array {
    return $pdo->query("SELECT id, name FROM categories ORDER BY name")->fetchAll();
}