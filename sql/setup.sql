-- ============================================================
-- Altus Inventory System — database setup
-- Creates an empty database: no users, no items, no demo data.
-- After running it, open the app and use "Create one" on the
-- sign-in page to make the first manager account.
--
-- For an EXISTING database run sql/migrate_schema.sql instead.
-- ============================================================

CREATE DATABASE IF NOT EXISTS inventory_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE inventory_system;

-- ── ORGANISATION (managed by the System Admin) ─────────────
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

-- Starting company and branch (rename them on the Organisation page)
INSERT IGNORE INTO companies (id, name) VALUES (1, 'Altus Financial Services Limited');
INSERT IGNORE INTO branches (id, company_id, name) VALUES (1, 1, 'Head Office');

-- ── ROLES AND ACCESS RIGHTS (managed on Admin › Roles) ─────
CREATE TABLE IF NOT EXISTS roles (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255) NULL,
    is_system   TINYINT(1)   NOT NULL DEFAULT 0,   -- System Admin: all rights, can't be changed
    is_default  TINYINT(1)   NOT NULL DEFAULT 0,   -- given to people who sign up themselves
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS role_permissions (
    role_id    INT         NOT NULL,
    permission VARCHAR(50) NOT NULL,
    PRIMARY KEY (role_id, permission),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;
INSERT IGNORE INTO roles (id, name, description, is_system, is_default) VALUES
    (1, 'System Admin', 'Full access, including users, roles and the organisation', 1, 0),
    (2, 'Staff', 'All stock work: receive, issue, requisitions, items and reports', 0, 1),
    -- The people in the requisition workflow (edit them on Admin > Roles)
    (3, 'Department Manager',    'Raises requisitions for their department and attaches quotations', 0, 0),
    (4, 'IT Officer',            'Gets quotations for IT-related requisitions', 0, 0),
    (5, 'Director',              'Approves or rejects requisitions', 0, 0),
    (6, 'Accountant',            'Prepares payments and sends the proof of payment', 0, 0),
    (7, 'Accounts Manager',      'Checks payments before the CFO', 0, 0),
    (8, 'Chief Finance Officer', 'Gives final approval for payments', 0, 0);
INSERT IGNORE INTO role_permissions (role_id, permission)
    SELECT 2, p FROM (SELECT 'stock.view' p UNION SELECT 'stock.receive' UNION SELECT 'stock.issue' UNION SELECT 'items.manage'
                      UNION SELECT 'categories.manage' UNION SELECT 'requisitions.raise'
                      UNION SELECT 'transactions.view' UNION SELECT 'reports.view' UNION SELECT 'reports.submit') x;
INSERT IGNORE INTO role_permissions (role_id, permission) VALUES
    (3, 'requisitions.raise'), (3, 'stock.view'),
    (4, 'requisitions.it_quotes'), (4, 'stock.view'),
    (5, 'requisitions.approve'), (5, 'requisitions.view_all'), (5, 'stock.view'), (5, 'reports.view'),
    (6, 'requisitions.accounts'),
    (7, 'requisitions.accounts_review'),
    (8, 'requisitions.cfo'), (8, 'reports.view');

-- ── USERS ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS users (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    full_name  VARCHAR(150) NOT NULL,
    email      VARCHAR(190) NOT NULL UNIQUE,
    username   VARCHAR(80)  NULL UNIQUE,     -- legacy, no longer used to sign in
    password   VARCHAR(255) NOT NULL,
    role       ENUM('admin','staff') NOT NULL DEFAULT 'staff',   -- legacy; access comes from role_id
    role_id    INT          NULL,
    active     TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at DATETIME  NULL,
    company_id    INT       NULL,
    branch_id     INT       NULL,
    department_id INT       NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id)       REFERENCES roles(id),
    FOREIGN KEY (company_id)    REFERENCES companies(id),
    FOREIGN KEY (branch_id)     REFERENCES branches(id),
    FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB;

-- ── CATEGORIES ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS categories (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Starting categories (edit them in Manage Items > Manage Categories)
INSERT IGNORE INTO categories (name, description) VALUES
    ('IT Equipment',        'Laptops, desktops, monitors, peripherals'),
    ('Networking',          'Switches, routers, cables, access points'),
    ('Printer Consumables', 'Toner, ink cartridges, drums'),
    ('Stationery',          'Paper, pens, files, office supplies'),
    ('Furniture',           'Desks, chairs, cabinets'),
    ('Kitchen & Cleaning',  'Tea, coffee, sugar, cleaning supplies'),
    ('Other',               'Miscellaneous items not fitting other categories');

-- ── ITEMS ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS items (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    item_name        VARCHAR(200) NOT NULL,
    category         VARCHAR(100) NOT NULL DEFAULT 'Other',
    category_id      INT NULL,
    description      TEXT,
    current_quantity INT          NOT NULL DEFAULT 0,
    unit_price       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    reorder_level    INT          NOT NULL DEFAULT 10,
    created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ── STOCK PER BRANCH ───────────────────────────────────────
-- items.current_quantity is the total across all branches.
CREATE TABLE IF NOT EXISTS branch_stock (
    item_id    INT NOT NULL,
    branch_id  INT NOT NULL,
    quantity   INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (item_id, branch_id),
    FOREIGN KEY (item_id)   REFERENCES items(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id)
) ENGINE=InnoDB;

-- ── STOCK RECEIVING ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS stock_receiving (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    item_id       INT            NOT NULL,
    supplier      VARCHAR(200),
    quantity      INT            NOT NULL,
    unit_price    DECIMAL(12,2)  NOT NULL DEFAULT 0.00,
    date_received DATE,
    received_by   INT            NOT NULL,
    branch_id     INT            NULL,
    old_stock     INT            NULL,
    new_stock     INT            NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id)     REFERENCES items(id),
    FOREIGN KEY (received_by) REFERENCES users(id),
    FOREIGN KEY (branch_id)   REFERENCES branches(id)
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
    branch_id     INT         NULL,
    department_id INT         NULL,
    recipient   VARCHAR(150)  NULL,
    old_stock   INT           NULL,
    new_stock   INT           NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (item_id)   REFERENCES items(id),
    FOREIGN KEY (issued_by) REFERENCES users(id),
    FOREIGN KEY (branch_id) REFERENCES branches(id),
    FOREIGN KEY (department_id) REFERENCES departments(id)
) ENGINE=InnoDB;

-- ── PROCUREMENT ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS procurement (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    item_name    VARCHAR(200) NOT NULL,
    quantity     INT          NOT NULL,
    reason       TEXT,
    item_id      INT          NULL,
    category_id  INT          NULL,
    category     VARCHAR(100) NULL,
    supplier     VARCHAR(200) NULL,
    branch_id     INT         NULL,
    department_id INT         NULL,
    is_it        TINYINT(1)   NOT NULL DEFAULT 0,   -- IT gets the quotations
    quote_id     INT          NULL,                 -- quotation the Director approved
    last_note    TEXT         NULL,                 -- latest rejection reason or note
    stage_at     DATETIME     NULL,                 -- when it reached its current stage
    director_at  DATETIME     NULL,
    paid_at      DATETIME     NULL,
    status       VARCHAR(20)  NOT NULL DEFAULT 'quotes_dept',   -- stage, see includes/requisition_flow.php
    ordered_at   DATETIME     NULL,
    received_at  DATETIME     NULL,
    requested_by INT          NOT NULL,
    approved_by  INT,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (requested_by) REFERENCES users(id),
    FOREIGN KEY (approved_by)  REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS requisition_files (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    requisition_id INT          NOT NULL,
    kind           VARCHAR(10)  NOT NULL,           -- quote or pop
    supplier       VARCHAR(200) NULL,
    amount         DECIMAL(15,2) NULL,
    original_name  VARCHAR(255) NOT NULL,
    stored_name    VARCHAR(80)  NOT NULL,
    mime           VARCHAR(100) NOT NULL,
    size           INT          NOT NULL,
    uploaded_by    INT          NOT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (requisition_id) REFERENCES procurement(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by)    REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS requisition_log (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    requisition_id INT          NOT NULL,
    user_id        INT          NOT NULL,
    action         VARCHAR(40)  NOT NULL,
    from_status    VARCHAR(20)  NULL,
    to_status      VARCHAR(20)  NULL,
    note           TEXT         NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (requisition_id) REFERENCES procurement(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)        REFERENCES users(id)
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

-- ── MONTHLY CLOSING ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS monthly_closing (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    closing_date DATETIME NOT NULL,
    items_data  TEXT NOT NULL,
    total_value DECIMAL(15,2) NOT NULL,
    closed_by   INT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (closed_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ── MONTHLY OPENING ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS monthly_opening (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    opening_date DATETIME NOT NULL,
    items_data  TEXT NOT NULL,
    total_value DECIMAL(15,2) NOT NULL,
    opened_by   INT NOT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (opened_by) REFERENCES users(id)
) ENGINE=InnoDB;

-- ── CATEGORY MONTHLY REPORTS ────────────────────────────────
CREATE TABLE IF NOT EXISTS category_monthly_reports (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    category_id    INT NOT NULL,
    report_month   DATE NOT NULL,
    items_data     TEXT NOT NULL,
    total_items    INT NOT NULL,
    total_units    INT NOT NULL,
    total_value    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    low_stock_count INT NOT NULL,
    notes          TEXT,
    submitted_by   INT NOT NULL,
    submitted_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE,
    FOREIGN KEY (submitted_by) REFERENCES users(id),
    UNIQUE KEY unique_category_month (category_id, report_month)
) ENGINE=InnoDB;
