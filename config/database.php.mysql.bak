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
    static $migrated = false;
    if (!$migrated) {
        $migrated = true;
        
        // Check if categories table exists, create if not
        $tableCheck = $pdo->query("SHOW TABLES LIKE 'categories'")->rowCount();
        if (!$tableCheck) {
            $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL UNIQUE,
                description VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            
            // Insert default categories
            $pdo->exec("INSERT IGNORE INTO categories (name, description) VALUES
                ('Caterriage', 'Catering supplies and equipment - trays, cutlery, serving items'),
                ('Stationery', 'Paper, pens, office supplies, printer consumables'),
                ('Dry Stores', 'Non-perishable food items - coffee, sugar, tea, snacks'),
                ('Catering', 'Food service and kitchen supplies - preparation equipment'),
                ('Uniforms', 'Staff uniforms, hats, aprons, clothing items'),
                ('Flight Documents', 'Boarding passes, tickets, baggage tags, forms'),
                ('Other', 'Miscellaneous items not fitting other categories')");
        }
        
        // Add category column if it doesn't exist
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
        
        // Add category_id column if it doesn't exist
        $col2 = $pdo->query("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'items'
              AND COLUMN_NAME  = 'category_id'
        ")->fetchColumn();
        
        if (!$col2) {
            $pdo->exec("ALTER TABLE items ADD COLUMN category_id INT NULL AFTER category");
            $pdo->exec("ALTER TABLE items ADD FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL");
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