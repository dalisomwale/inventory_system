-- ============================================================
-- Inventory Management System — Full Database Setup
-- ============================================================

-- Create database
CREATE DATABASE IF NOT EXISTS inventory_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE inventory_system;

-- USERS table
CREATE TABLE IF NOT EXISTS users (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    full_name  VARCHAR(150) NOT NULL,
    username   VARCHAR(80)  NOT NULL UNIQUE,
    email      VARCHAR(190) NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    role       ENUM('manager','front_desk','admin','technician') NOT NULL DEFAULT 'front_desk',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- CATEGORIES table
CREATE TABLE IF NOT EXISTS categories (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ITEMS table
CREATE TABLE IF NOT EXISTS items (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    item_name        VARCHAR(200) NOT NULL,
    category         VARCHAR(100) NOT NULL DEFAULT 'Other',
    category_id      INT NULL,
    description      TEXT,
    current_quantity INT NOT NULL DEFAULT 0,
    unit_price       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    reorder_level    INT NOT NULL DEFAULT 10,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- STOCK RECEIVING table
CREATE TABLE IF NOT EXISTS stock_receiving (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    item_id       INT NOT NULL,
    supplier      VARCHAR(200),
    quantity      INT NOT NULL,
    unit_price    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    date_received DATE,
    received_by   INT NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES items(id),
    FOREIGN KEY (received_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- STOCK ISSUING table
CREATE TABLE IF NOT EXISTS stock_issuing (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    item_id     INT NOT NULL,
    quantity    INT NOT NULL,
    issued_to   VARCHAR(200),
    purpose     TEXT,
    date_issued DATE,
    issued_by   INT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id) REFERENCES items(id),
    FOREIGN KEY (issued_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- PROCUREMENT table
CREATE TABLE IF NOT EXISTS procurement (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    item_name    VARCHAR(200) NOT NULL,
    quantity     INT NOT NULL,
    reason       TEXT,
    category_id  INT,
    category     VARCHAR(100),
    requested_by INT NOT NULL,
    approved_by  INT,
    status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (requested_by) REFERENCES users(id),
    FOREIGN KEY (approved_by) REFERENCES users(id),
    FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB;

-- AUDIT TRAIL table
CREATE TABLE IF NOT EXISTS audit_trail (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT,
    action     VARCHAR(100) NOT NULL,
    item_id    INT,
    quantity   INT,
    details    TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

-- CATEGORY MONTHLY REPORTS table
CREATE TABLE IF NOT EXISTS category_monthly_reports (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    category_id    INT NOT NULL,
    report_month   DATE NOT NULL,
    items_data     TEXT,
    total_items    INT DEFAULT 0,
    total_units    INT DEFAULT 0,
    low_stock_count INT DEFAULT 0,
    notes          TEXT,
    submitted_by   INT,
    submitted_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    FOREIGN KEY (submitted_by) REFERENCES users(id),
    UNIQUE KEY unique_category_month (category_id, report_month)
) ENGINE=InnoDB;

-- Insert sample categories
INSERT IGNORE INTO categories (name, description) VALUES
    ('IT Equipment',        'Laptops, desktops, monitors, peripherals'),
    ('Networking',          'Switches, routers, cables, access points'),
    ('Printer Consumables', 'Toner, ink cartridges, drums'),
    ('Stationery',          'Paper, pens, files, office supplies'),
    ('Furniture',           'Desks, chairs, cabinets'),
    ('Kitchen & Cleaning',  'Tea, coffee, sugar, cleaning supplies'),
    ('Other',               'Miscellaneous items not fitting other categories');

-- Insert sample users (password: password123 - bcrypt hash)
INSERT IGNORE INTO users (full_name, username, password, role) VALUES
    ('System Manager', 'manager', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'manager'),
    ('Front Desk Staff', 'frontdesk', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'front_desk');

-- Show completion message
SELECT 'Database created successfully!' AS Status;
