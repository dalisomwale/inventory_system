-- ============================================================
-- Altus rebrand: replace the old aviation categories with
-- office/IT categories on an EXISTING database.
-- Safe to run more than once.
-- ============================================================
USE inventory_system;

-- Add the new categories (skips any that already exist)
INSERT IGNORE INTO categories (name, description) VALUES
    ('IT Equipment',        'Laptops, desktops, monitors, peripherals'),
    ('Networking',          'Switches, routers, cables, access points'),
    ('Printer Consumables', 'Toner, ink cartridges, drums'),
    ('Stationery',          'Paper, pens, files, office supplies'),
    ('Furniture',           'Desks, chairs, cabinets'),
    ('Kitchen & Cleaning',  'Tea, coffee, sugar, cleaning supplies'),
    ('Other',               'Miscellaneous items not fitting other categories');

-- Remove the old categories, but only if no items use them
DELETE c FROM categories c
LEFT JOIN items i ON i.category_id = c.id
WHERE c.name IN ('Aircraft Parts', 'Tools', 'Consumables', 'Safety Equipment',
                 'Dry Stores', 'Uniforms', 'Caterriage', 'Catering', 'Flight Documents')
  AND i.id IS NULL;

SELECT name FROM categories ORDER BY name;
