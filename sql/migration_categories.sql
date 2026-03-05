-- Run this SQL once to add category support to your database
-- -------------------------------------------------------

-- 1. Create categories table
CREATE TABLE IF NOT EXISTS categories (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL UNIQUE,
    description VARCHAR(255),
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. Your specific categories
INSERT IGNORE INTO categories (name, description) VALUES
  ('Caterriage',      'Catering supplies and equipment - trays, cutlery, serving items'),
  ('Stationery',      'Paper, pens, office supplies, printer consumables'),
  ('Dry Stores',      'Non-perishable food items - coffee, sugar, tea, snacks'),
  ('Catering',        'Food service and kitchen supplies - preparation equipment'),
  ('Uniforms',        'Staff uniforms, hats, aprons, clothing items'),
  ('Flight Documents','Boarding passes, tickets, baggage tags, forms'),
  ('Other',           'Miscellaneous items not fitting other categories');

-- 3. Add category_id column to items (safe to run repeatedly)
ALTER TABLE items
  ADD COLUMN IF NOT EXISTS category_id INT NULL,
  ADD CONSTRAINT fk_items_category
      FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL;