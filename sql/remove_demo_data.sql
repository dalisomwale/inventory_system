-- ============================================================
-- Removes the demo accounts and sample items that older versions
-- of setup.sql created. Run AFTER sql/migrate_schema.sql.
--
-- Safe by design:
--   * Demo accounts are locked first (they can never sign in again).
--   * A demo account or sample item is only deleted if nothing else
--     refers to it. Anything with stock history is kept and listed
--     at the end so you can decide what to do with it.
--   * Real users, real items and all transactions are not touched.
--
-- Back up first:
--   mysqldump -u root -p inventory_system > ~/inventory_backup_$(date +%F).sql
-- Then:
--   mysql -u root -p < sql/remove_demo_data.sql
-- ============================================================
USE inventory_system;

-- ── 1. Demo accounts ─────────────────────────────────────────
-- The old setup created these with the password "password123".
CREATE TEMPORARY TABLE demo_users AS
SELECT id FROM users
WHERE (username = 'manager'   AND full_name = 'System Manager')
   OR (username = 'frontdesk' AND full_name = 'Front Desk Staff');

-- Lock them: no email (can't sign in) and a password no login can match
UPDATE users SET email = NULL, password = '!locked-demo-account'
WHERE id IN (SELECT id FROM demo_users);

-- Delete the ones with no history
DELETE FROM users
WHERE id IN (SELECT id FROM demo_users)
  AND id NOT IN (SELECT received_by  FROM stock_receiving)
  AND id NOT IN (SELECT issued_by    FROM stock_issuing)
  AND id NOT IN (SELECT requested_by FROM procurement)
  AND id NOT IN (SELECT approved_by  FROM procurement WHERE approved_by IS NOT NULL)
  AND id NOT IN (SELECT submitted_by FROM category_monthly_reports)
  AND id NOT IN (SELECT closed_by    FROM monthly_closing)
  AND id NOT IN (SELECT opened_by    FROM monthly_opening);

-- ── 2. Sample items ──────────────────────────────────────────
-- From the old setup.sql and the old SQLite sample data.
CREATE TEMPORARY TABLE demo_items AS
SELECT id FROM items WHERE item_name IN (
    'A4 Paper Ream', 'Blue Ballpoint Pens', 'Printer Toner', 'Meal Tray', 'Cutlery Set',
    'Coffee Sachets', 'Sugar Sachets', 'Tea Bags', 'Flight Attendant Uniform', 'Captain''s Hat',
    'Boarding Pass Stock', 'Baggage Tags', 'Disposable Gloves', 'Apron', 'Snack Box',
    'Brake Pads', 'Spark Plug', 'Hydraulic Fluid', 'Oxygen Mask', 'Wrench Set',
    'Multimeter', 'Fire Extinguisher'
);

DELETE FROM items
WHERE id IN (SELECT id FROM demo_items)
  AND id NOT IN (SELECT item_id FROM stock_receiving)
  AND id NOT IN (SELECT item_id FROM stock_issuing);

-- ── 3. Old aviation categories that are now empty ────────────
DELETE c FROM categories c
LEFT JOIN items i ON i.category_id = c.id
WHERE c.name IN ('Caterriage', 'Catering', 'Uniforms', 'Flight Documents', 'Dry Stores',
                 'Aircraft Parts', 'Tools', 'Consumables', 'Safety Equipment')
  AND i.id IS NULL;

-- Make sure the Altus starting categories exist
INSERT IGNORE INTO categories (name, description) VALUES
    ('IT Equipment',        'Laptops, desktops, monitors, peripherals'),
    ('Networking',          'Switches, routers, cables, access points'),
    ('Printer Consumables', 'Toner, ink cartridges, drums'),
    ('Stationery',          'Paper, pens, files, office supplies'),
    ('Furniture',           'Desks, chairs, cabinets'),
    ('Kitchen & Cleaning',  'Tea, coffee, sugar, cleaning supplies'),
    ('Other',               'Miscellaneous items not fitting other categories');

-- ── 4. What's left for you to decide ─────────────────────────
SELECT 'Demo accounts kept (locked, have history)' AS kept, u.id, u.full_name, u.username
FROM users u JOIN demo_users d ON d.id = u.id;

SELECT 'Sample items kept (have stock history)' AS kept, i.id, i.item_name, i.current_quantity
FROM items i JOIN demo_items d ON d.id = i.id;

SELECT 'Accounts with no email (cannot sign in yet)' AS todo, id, full_name, username, role
FROM users WHERE (email IS NULL OR email = '') AND password <> '!locked-demo-account';

DROP TEMPORARY TABLE demo_users;
DROP TEMPORARY TABLE demo_items;
