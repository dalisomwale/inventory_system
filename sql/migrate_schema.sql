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

-- Organisation: companies > branches > departments (managed by the System Admin)
CREATE TABLE IF NOT EXISTS companies (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(150) NOT NULL UNIQUE,
    active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS branches (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    company_id INT          NOT NULL,
    name       VARCHAR(150) NOT NULL,
    location   VARCHAR(200) NULL,
    active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_branch (company_id, name),
    FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS departments (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    branch_id  INT          NOT NULL,
    name       VARCHAR(150) NOT NULL,
    active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_department (branch_id, name),
    FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB;
-- A starting company and branch so existing stock has a home (rename on the Organisation page)
INSERT INTO companies (name) SELECT 'Altus Financial Services Limited' FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM companies);
INSERT INTO branches (company_id, name) SELECT MIN(id), 'Head Office' FROM companies HAVING NOT EXISTS (SELECT 1 FROM branches);
SET @home_branch  = (SELECT MIN(id) FROM branches);
SET @home_company = (SELECT company_id FROM branches WHERE id = @home_branch);

CALL add_column_if_missing('users', 'company_id',    'INT NULL');
CALL add_column_if_missing('users', 'branch_id',     'INT NULL');
CALL add_column_if_missing('users', 'department_id', 'INT NULL');
CALL add_column_if_missing('stock_receiving', 'branch_id',     'INT NULL AFTER received_by');
CALL add_column_if_missing('stock_issuing',   'branch_id',     'INT NULL AFTER issued_by');
CALL add_column_if_missing('stock_issuing',   'department_id', 'INT NULL AFTER branch_id');
CALL add_column_if_missing('stock_issuing',   'recipient',     'VARCHAR(150) NULL AFTER department_id');
CALL add_column_if_missing('procurement',     'branch_id',     'INT NULL');
CALL add_column_if_missing('procurement',     'department_id', 'INT NULL');

-- Stock held at each branch; items.current_quantity stays the total across branches
CREATE TABLE IF NOT EXISTS branch_stock (
    item_id    INT NOT NULL,
    branch_id  INT NOT NULL,
    quantity   INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (item_id, branch_id),
    FOREIGN KEY (item_id)   REFERENCES items(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB;
-- Existing stock and history belong to the starting branch (only rows not yet assigned)
INSERT INTO branch_stock (item_id, branch_id, quantity)
    SELECT i.id, @home_branch, i.current_quantity FROM items i
    WHERE NOT EXISTS (SELECT 1 FROM branch_stock b WHERE b.item_id = i.id);
UPDATE stock_receiving SET branch_id = @home_branch WHERE branch_id IS NULL;
UPDATE stock_issuing   SET branch_id = @home_branch WHERE branch_id IS NULL;
UPDATE procurement     SET branch_id = @home_branch WHERE branch_id IS NULL;
UPDATE users SET company_id = @home_company, branch_id = @home_branch WHERE branch_id IS NULL;

-- Roles: 'admin' (System Admin, manages users) and 'staff' (everything else)
CALL add_column_if_missing('users', 'active',        'TINYINT(1) NOT NULL DEFAULT 1 AFTER role');
CALL add_column_if_missing('users', 'last_login_at', 'DATETIME NULL AFTER active');
ALTER TABLE users MODIFY role ENUM('manager','front_desk','admin','staff') NOT NULL DEFAULT 'staff';
UPDATE users SET role = 'staff' WHERE role IN ('manager','front_desk');
ALTER TABLE users MODIFY role ENUM('admin','staff') NOT NULL DEFAULT 'staff';

-- Roles and access rights (managed on Admin › Roles)
CREATE TABLE IF NOT EXISTS roles (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    is_system   TINYINT(1)   NOT NULL DEFAULT 0,
    is_default  TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS role_permissions (
    role_id    INT         NOT NULL,
    permission VARCHAR(50) NOT NULL,
    PRIMARY KEY (role_id, permission),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;
INSERT INTO roles (name, description, is_system, is_default)
    SELECT 'System Admin', 'Full access, including users, roles and the organisation', 1, 0 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM roles WHERE is_system = 1);
INSERT INTO roles (name, description, is_system, is_default)
    SELECT 'Staff', 'All stock work: receive, issue, requisitions, items and reports', 0, 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM roles WHERE name = 'Staff');
SET @admin_role = (SELECT MIN(id) FROM roles WHERE is_system = 1);
SET @staff_role = (SELECT id FROM roles WHERE name = 'Staff');
INSERT IGNORE INTO role_permissions (role_id, permission)
    SELECT @staff_role, p FROM (SELECT 'stock.view' p UNION SELECT 'stock.receive' UNION SELECT 'stock.issue' UNION SELECT 'items.manage'
                      UNION SELECT 'categories.manage' UNION SELECT 'requisitions.view' UNION SELECT 'requisitions.manage'
                      UNION SELECT 'transactions.view' UNION SELECT 'reports.view' UNION SELECT 'reports.submit') x
    WHERE NOT EXISTS (SELECT 1 FROM role_permissions WHERE role_id = @staff_role);
CALL add_column_if_missing('users', 'role_id', 'INT NULL AFTER role');
UPDATE users SET role_id = @admin_role WHERE role_id IS NULL AND role = 'admin';
UPDATE users SET role_id = @staff_role WHERE role_id IS NULL;

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

-- Foreign keys for the organisation links (added once)
DROP PROCEDURE IF EXISTS add_fk_if_missing;
DELIMITER //
CREATE PROCEDURE add_fk_if_missing(IN tbl VARCHAR(64), IN col VARCHAR(64), IN ref VARCHAR(64))
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = tbl AND COLUMN_NAME = col AND REFERENCED_TABLE_NAME = ref
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', tbl, '` ADD FOREIGN KEY (`', col, '`) REFERENCES `', ref, '`(id)');
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;
CALL add_fk_if_missing('users', 'role_id', 'roles');
CALL add_fk_if_missing('users', 'company_id', 'companies');
CALL add_fk_if_missing('users', 'branch_id', 'branches');
CALL add_fk_if_missing('users', 'department_id', 'departments');
CALL add_fk_if_missing('stock_receiving', 'branch_id', 'branches');
CALL add_fk_if_missing('stock_issuing', 'branch_id', 'branches');
CALL add_fk_if_missing('stock_issuing', 'department_id', 'departments');
CALL add_fk_if_missing('procurement', 'branch_id', 'branches');
CALL add_fk_if_missing('procurement', 'department_id', 'departments');
DROP PROCEDURE IF EXISTS add_fk_if_missing;

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
