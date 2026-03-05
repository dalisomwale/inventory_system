<?php
$host     = 'localhost';
$dbname   = 'inventory_system';
$username = 'inventory_admin';
$password = 'king.dee/0306';

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Auto-migration: add 'category' column to items if it doesn't exist yet.
    // This runs once and is a no-op after that (safe on every request).
    static $migrated = false;
    if (!$migrated) {
        $migrated = true;
        $col = $pdo->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'items'
              AND COLUMN_NAME  = 'category'
        ")->fetchColumn();

        if (!$col) {
            $pdo->exec("ALTER TABLE items ADD COLUMN category VARCHAR(100) NOT NULL DEFAULT 'General' AFTER item_name");
            $pdo->exec("ALTER TABLE items ADD INDEX idx_category (category)");
        }
    }

} catch (PDOException $e) {
    error_log('DB Connection failed: ' . $e->getMessage());
    die('<div style="font-family:sans-serif;text-align:center;margin-top:80px;color:#c0392b">
         <h2>Database connection failed.</h2>
         <p>Please check your database credentials and that MySQL is running.</p>
         <p style="font-size:.85rem;color:#888">Error: ' . htmlspecialchars($e->getMessage()) . '</p>
         </div>');
}
