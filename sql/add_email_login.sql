-- ============================================================
-- Adds an email column to users so people sign in with email.
-- Run once on an existing database.
-- ============================================================
USE inventory_system;

ALTER TABLE users ADD COLUMN email VARCHAR(190) NULL UNIQUE AFTER username;

-- Existing accounts have no email yet. List them so you can set one each:
--   UPDATE users SET email='name@altus.co.zm' WHERE username='theirusername';
SELECT id, full_name, username, email, role FROM users;
