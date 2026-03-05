<?php
require_once __DIR__ . '/../config/database.php';

function isLoggedIn(): bool { return isset($_SESSION['user_id']); }
function isManager(): bool  { return isset($_SESSION['role']) && $_SESSION['role'] === 'manager'; }
function isFrontDesk(): bool{ return isset($_SESSION['role']) && $_SESSION['role'] === 'front_desk'; }

function redirectIfNotLoggedIn(): void {
    if (!isLoggedIn()) { header('Location: login.php'); exit(); }
}

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
function catClass(string $cat): string {
    // Map your categories to specific colors for better visual consistency
    $color_map = [
        'Caterriage' => 0,
        'Stationery' => 1,
        'Dry Stores' => 2,
        'Catering' => 3,
        'Uniforms' => 4,
        'Flight Documents' => 5,
        'Other' => 6
    ];
    
    if (isset($color_map[$cat])) {
        return 'cat-' . $color_map[$cat];
    }
    
    // Fallback to hash for any new categories
    return 'cat-' . (crc32($cat) % 7);
}

/**
 * Fetch sorted unique category list from items table.
 */
function getCategories(PDO $pdo): array {
    return $pdo->query("SELECT DISTINCT category FROM items WHERE category != '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);
}