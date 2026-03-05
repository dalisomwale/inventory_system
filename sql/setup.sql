-- ============================================================
-- Inventory Management System — Full Database Setup
-- Run this file ONCE to set up or upgrade your database.
-- Safe to run on both fresh installs and existing databases.
-- ============================================================

CREATE DATABASE IF NOT EXISTS inventory_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE inventory_system;

-- ── USERS ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    full_name  VARCHAR(150) NOT NULL,
    username   VARCHAR(80)  NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    role       ENUM('manager','front_desk') NOT NULL DEFAULT 'front_desk',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ── CATEGORIES ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS categories (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert your categories
INSERT IGNORE INTO categories (name, description) VALUES
  ('Caterriage',      'Catering supplies and equipment - trays, cutlery, serving items'),
  ('Stationery',      'Paper, pens, office supplies, printer consumables'),
  ('Dry Stores',      'Non-perishable food items - coffee, sugar, tea, snacks'),
  ('Catering',        'Food service and kitchen supplies - preparation equipment'),
  ('Uniforms',        'Staff uniforms, hats, aprons, clothing items'),
  ('Flight Documents','Boarding passes, tickets, baggage tags, forms'),
  ('Other',           'Miscellaneous items not fitting other categories');

-- ── ITEMS ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS items (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    item_name        VARCHAR(200) NOT NULL,
    category         VARCHAR(100) NOT NULL DEFAULT 'Other',
    description      TEXT,
    current_quantity INT          NOT NULL DEFAULT 0,
    unit_price       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    reorder_level    INT          NOT NULL DEFAULT 10,
    category_id      INT NULL,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- MIGRATION: add category column if it doesn't exist yet (safe on existing DB)
SET @col_exists = (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'items'
      AND COLUMN_NAME  = 'category'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE items ADD COLUMN category VARCHAR(100) NOT NULL DEFAULT \'Other\' AFTER item_name',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- MIGRATION: add index if missing
SET @idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'items'
      AND INDEX_NAME   = 'idx_category'
);
SET @sql2 = IF(@idx_exists = 0,
    'ALTER TABLE items ADD INDEX idx_category (category)',
    'SELECT 1'
);
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- ── STOCK RECEIVING ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS stock_receiving (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    item_id       INT            NOT NULL,
    supplier      VARCHAR(200),
    quantity      INT            NOT NULL,
    unit_price    DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
    date_received DATE,
    received_by   INT            NOT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id)     REFERENCES items(id),
    FOREIGN KEY (received_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ── STOCK ISSUING ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS stock_issuing (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    item_id     INT           NOT NULL,
    quantity    INT           NOT NULL,
    issued_to   VARCHAR(200),
    purpose     TEXT,
    date_issued DATE,
    issued_by   INT           NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id)   REFERENCES items(id),
    FOREIGN KEY (issued_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ── PROCUREMENT ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS procurement (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    item_name    VARCHAR(200) NOT NULL,
    quantity     INT          NOT NULL,
    reason       TEXT,
    status       ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    requested_by INT          NOT NULL,
    approved_by  INT,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (requested_by) REFERENCES users(id),
    FOREIGN KEY (approved_by)  REFERENCES users(id)
) ENGINE=InnoDB;

-- ── AUDIT TRAIL ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS audit_trail (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT,
    action     VARCHAR(100) NOT NULL,
    item_id    INT,
    quantity   DECIMAL(12,2),
    details    TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user   (user_id),
    INDEX idx_action (action)
) ENGINE=InnoDB;

-- ── DEMO USERS ─────────────────────────────────────────────
-- Passwords are bcrypt hashes of 'password123'
INSERT IGNORE INTO users (full_name, username, password, role) VALUES
    ('System Manager',    'manager',   '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'manager'),
    ('Front Desk Staff',  'frontdesk', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'front_desk');

-- ── SAMPLE ITEMS (using your categories) ───────────────────
INSERT IGNORE INTO items (id, item_name, category, description, current_quantity, unit_price, reorder_level) VALUES
    (1,  'A4 Paper Ream',        'Stationery',      '500 sheets per ream',              50,  45.00,  20),
    (2,  'Blue Ballpoint Pens',  'Stationery',      'Box of 50 pens',                   30,  12.50,  10),
    (3,  'Printer Toner',        'Stationery',      'Compatible with HP printers',      8,   320.00, 3),
    (4,  'Meal Tray',            'Caterriage',      'Airline meal trays',               100, 15.00,  25),
    (5,  'Cutlery Set',          'Caterriage',      'Fork, knife, spoon set',           200, 5.00,   50),
    (6,  'Coffee Sachets',       'Dry Stores',      'Instant coffee packets',           500, 2.50,   100),
    (7,  'Sugar Sachets',        'Dry Stores',      '5g sugar packets',                 1000, 1.00,  200),
    (8,  'Tea Bags',             'Dry Stores',      'Box of 100 tea bags',              30,  25.00,  10),
    (9,  'Flight Attendant Uniform','Uniforms',     'Women''s size M',                  15,  450.00, 5),
    (10, 'Captain''s Hat',        'Uniforms',       'Official captain hat',             8,   120.00, 3),
    (11, 'Boarding Pass Stock',   'Flight Documents','Thermal paper rolls',             50,  35.00,  15),
    (12, 'Baggage Tags',          'Flight Documents','Self-adhesive tags',              200, 0.50,   50),
    (13, 'Disposable Gloves',     'Catering',       'Box of 100 pairs',                 40,  28.00,  15),
    (14, 'Apron',                 'Uniforms',       'Waist apron for servers',          25,  85.00,  10),
    (15, 'Snack Box',             'Dry Stores',     'Assorted snacks for passengers',   200, 12.00,  50);