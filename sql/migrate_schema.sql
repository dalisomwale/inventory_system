-- ============================================================
-- Brings an existing inventory_system database up to date with
-- what the pages expect. Adds only what is missing, keeps data.
-- Safe to run more than once.
--
--   mysql -u root -p < sql/migrate_schema.sql
-- ============================================================
USE inventory_system;

DROP PROCEDURE IF EXISTS add_column_if_missing;
DELIMITER //
CREATE PROCEDURE add_column_if_missing(IN tbl VARCHAR(64), IN col VARCHAR(64), IN definition VARCHAR(255))
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = col
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', tbl, '` ADD COLUMN `', col, '` ', definition);
        PREPARE stmt FROM @sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

-- Users sign in with email; username is kept only for old records
CALL add_column_if_missing('users', 'email', 'VARCHAR(190) NULL AFTER full_name');
ALTER TABLE users MODIFY username VARCHAR(80) NULL;

-- Stock movements record the before/after stock level
CALL add_column_if_missing('stock_receiving', 'old_stock', 'INT NULL AFTER received_by');
CALL add_column_if_missing('stock_receiving', 'new_stock', 'INT NULL AFTER old_stock');
CALL add_column_if_missing('stock_issuing',   'old_stock', 'INT NULL AFTER issued_by');
CALL add_column_if_missing('stock_issuing',   'new_stock', 'INT NULL AFTER old_stock');

-- Procurement requests belong to a category
CALL add_column_if_missing('procurement', 'category_id', 'INT NULL AFTER reason');
CALL add_column_if_missing('procurement', 'category',    'VARCHAR(100) NULL AFTER category_id');

-- Procurement becomes a purchase list: needed -> ordered -> received (or cancelled)
CALL add_column_if_missing('procurement', 'supplier',    'VARCHAR(200) NULL AFTER category');
CALL add_column_if_missing('procurement', 'ordered_at',  'DATETIME NULL');
CALL add_column_if_missing('procurement', 'received_at', 'DATETIME NULL');
CALL add_column_if_missing('procurement', 'item_id',     'INT NULL AFTER item_name');
ALTER TABLE procurement MODIFY status ENUM('pending','approved','rejected','needed','ordered','received','cancelled') NOT NULL DEFAULT 'needed';
UPDATE procurement SET status = 'needed'    WHERE status = 'pending';
UPDATE procurement SET status = 'ordered', ordered_at = COALESCE(ordered_at, created_at) WHERE status = 'approved';
UPDATE procurement SET status = 'cancelled' WHERE status = 'rejected';
ALTER TABLE procurement MODIFY status ENUM('needed','ordered','received','cancelled') NOT NULL DEFAULT 'needed';

-- Monthly reports store the stock value
CALL add_column_if_missing('category_monthly_reports', 'total_value', 'DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER total_units');
CALL add_column_if_missing('category_monthly_reports', 'updated_at',  'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

DROP PROCEDURE IF EXISTS add_column_if_missing;

-- One account per email (case-insensitive collation)
DROP PROCEDURE IF EXISTS add_email_index;
DELIMITER //
CREATE PROCEDURE add_email_index()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email' AND NON_UNIQUE = 0
    ) THEN
        ALTER TABLE users ADD UNIQUE KEY uq_users_email (email);
    END IF;
END //
DELIMITER ;
CALL add_email_index();
DROP PROCEDURE IF EXISTS add_email_index;

-- Give total_value a default so older code paths can't fail on insert
ALTER TABLE category_monthly_reports MODIFY total_value DECIMAL(15,2) NOT NULL DEFAULT 0.00;

SELECT 'Schema is up to date.' AS result;

-- Accounts that can't sign in until an email is set:
SELECT id, full_name, username, role FROM users WHERE email IS NULL OR email = '';
