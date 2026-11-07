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

-- Stock movements record the before/after stock level
CALL add_column_if_missing('stock_receiving', 'old_stock', 'INT NULL AFTER received_by');
CALL add_column_if_missing('stock_receiving', 'new_stock', 'INT NULL AFTER old_stock');
CALL add_column_if_missing('stock_issuing',   'old_stock', 'INT NULL AFTER issued_by');
CALL add_column_if_missing('stock_issuing',   'new_stock', 'INT NULL AFTER old_stock');

-- Procurement requests belong to a category
CALL add_column_if_missing('procurement', 'category_id', 'INT NULL AFTER reason');
CALL add_column_if_missing('procurement', 'category',    'VARCHAR(100) NULL AFTER category_id');

-- Monthly reports store the stock value
CALL add_column_if_missing('category_monthly_reports', 'total_value', 'DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER total_units');
CALL add_column_if_missing('category_monthly_reports', 'updated_at',  'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

DROP PROCEDURE IF EXISTS add_column_if_missing;

-- Give total_value a default so older code paths can't fail on insert
ALTER TABLE category_monthly_reports MODIFY total_value DECIMAL(15,2) NOT NULL DEFAULT 0.00;

SELECT 'Schema is up to date.' AS result;
