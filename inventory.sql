PRAGMA foreign_keys=OFF;
BEGIN TRANSACTION;
CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            role TEXT DEFAULT 'front_desk',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
INSERT INTO users VALUES(1,'John Smith','manager','$2y$12$.gvbnRsIfvDF7QgWXGnTneK63d02FfR4Gh85.GRPLPj4M5JyCdQ56','manager','2026-04-28 07:20:41');
INSERT INTO users VALUES(2,'Sarah Johnson','frontdesk','$2y$12$.gvbnRsIfvDF7QgWXGnTneK63d02FfR4Gh85.GRPLPj4M5JyCdQ56','front_desk','2026-04-28 07:20:41');
INSERT INTO users VALUES(3,'Daliso Mwale','dalisomwale','$2y$12$PA2TKfiNUzmVbLKReCAkbu1nb6rga/uzn6/yROdFIHYLuHTKeGqDe','manager','2026-04-28 08:12:56');
INSERT INTO users VALUES(4,'Dee','dee','$2y$12$.wo5VlS2yc6FCXMIoJZXPOsotpuHGMv1qXdiq7H3GC3ggAdth6X/q','front_desk','2026-04-28 08:24:54');
INSERT INTO users VALUES(5,'Daliso Mwale','daliso','$2y$12$xxx4TZMsTLS0rhr1duD2ZedtujBoFG/pv5e/OVkhC3Y75gpqkSBCy','front_desk','2026-05-06 10:22:27');
INSERT INTO users VALUES(6,'Sokoni Mwango','Sokoni','$2y$12$IvQ4idK4cfjEX.5ZD66C8uq7W.8U/6e4nhS7wifM9LJx/ixiETAhO','front_desk','2026-05-06 10:28:36');
INSERT INTO users VALUES(7,'david chiluwe','david','$2y$12$TOCeZfGXi.qxHq0YLJon7.aeuN./Iyanz3hld7CWUCAbjDBp2NhXG','front_desk','2026-05-06 10:29:05');
INSERT INTO users VALUES(8,'Sokoni Mwango','SokoniM','$2y$12$tuhKr9jABaNzxmGeWtCwYe3rzgA6iaIz.b60yj6dhanRmIpLhspTO','manager','2026-05-06 10:29:23');
INSERT INTO users VALUES(9,'Esau Ngoma','esau','$2y$12$Xq1aJHP/VE01nou4yw1LS.KpS0TDBssqVHgOQCRU5V.aXMNKnL5BS','front_desk','2026-05-08 14:00:34');
CREATE TABLE categories (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
INSERT INTO categories VALUES(1,'Dry Stores','','2026-04-28 07:20:40');
INSERT INTO categories VALUES(2,'Stationary','','2026-04-28 07:20:40');
INSERT INTO categories VALUES(3,'Consumables','Items that are used up during service','2026-04-28 07:20:40');
INSERT INTO categories VALUES(4,'Safety Equipment','Safety gear and emergency equipment','2026-04-28 07:20:40');
INSERT INTO categories VALUES(5,'Marketing','','2026-05-08 13:57:38');
INSERT INTO categories VALUES(6,'IT','','2026-05-08 13:58:18');
CREATE TABLE items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_name TEXT NOT NULL,
            category_id INTEGER,
            category TEXT,
            description TEXT,
            current_quantity INTEGER DEFAULT 0,
            unit_price REAL DEFAULT 0,
            reorder_level INTEGER DEFAULT 10,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (category_id) REFERENCES categories(id)
        );
INSERT INTO items VALUES(1,'Brake Pads',1,'Dry Stores',NULL,8,150.0,10,'2026-04-28 07:20:41');
INSERT INTO items VALUES(3,'Spark Plug',1,'Dry Stores',NULL,20,12.5,10,'2026-04-28 07:20:41');
INSERT INTO items VALUES(4,'Hydraulic Fluid',3,'Consumables',NULL,4,85.0,10,'2026-04-28 07:20:41');
INSERT INTO items VALUES(5,'Oxygen Mask',4,'Safety Equipment',NULL,0,200.0,5,'2026-04-28 07:20:41');
INSERT INTO items VALUES(6,'Wrench Set',2,'Stationary',NULL,15,75.0,5,'2026-04-28 07:20:41');
INSERT INTO items VALUES(7,'Multimeter',2,'Stationary',NULL,6,120.0,3,'2026-04-28 07:20:41');
INSERT INTO items VALUES(8,'Fire Extinguisher',4,'Safety Equipment',NULL,10,350.0,3,'2026-04-28 07:20:41');
INSERT INTO items VALUES(9,'A4 Paper Ream',1,'Dry Stores','',55,0.0,5,'2026-04-28 11:08:23');
INSERT INTO items VALUES(10,'Paper',3,'Consumables','coffee',20,0.0,10,'2026-05-06 10:30:25');
CREATE TABLE stock_receiving (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER NOT NULL,
            supplier TEXT,
            quantity INTEGER NOT NULL,
            unit_price REAL DEFAULT 0,
            date_received DATE,
            received_by INTEGER,
            old_stock INTEGER,
            new_stock INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (item_id) REFERENCES items(id),
            FOREIGN KEY (received_by) REFERENCES users(id)
        );
INSERT INTO stock_receiving VALUES(1,9,'Book World',50,0.0,'2026-04-28',4,0,50,'2026-04-28 11:08:23');
INSERT INTO stock_receiving VALUES(2,9,'Book World',5,0.0,'2026-04-28',4,50,55,'2026-04-28 11:09:01');
INSERT INTO stock_receiving VALUES(3,10,'test',20,0.0,'2026-05-06',7,0,20,'2026-05-06 10:30:25');
CREATE TABLE stock_issuing (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_id INTEGER NOT NULL,
            quantity INTEGER NOT NULL,
            issued_to TEXT,
            purpose TEXT,
            date_issued DATE,
            issued_by INTEGER,
            old_stock INTEGER,
            new_stock INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (item_id) REFERENCES items(id),
            FOREIGN KEY (issued_by) REFERENCES users(id)
        );
CREATE TABLE procurement (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            item_name TEXT NOT NULL,
            quantity INTEGER NOT NULL,
            reason TEXT,
            category_id INTEGER,
            category TEXT,
            requested_by INTEGER,
            approved_by INTEGER,
            status TEXT DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (requested_by) REFERENCES users(id),
            FOREIGN KEY (approved_by) REFERENCES users(id),
            FOREIGN KEY (category_id) REFERENCES categories(id)
        );
CREATE TABLE category_monthly_reports (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            category_id INTEGER NOT NULL,
            report_month DATE NOT NULL,
            items_data TEXT,
            total_items INTEGER DEFAULT 0,
            total_units INTEGER DEFAULT 0,
            low_stock_count INTEGER DEFAULT 0,
            notes TEXT,
            submitted_by INTEGER,
            submitted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (category_id) REFERENCES categories(id),
            UNIQUE(category_id, report_month)
        );
CREATE TABLE audit_trail (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            action TEXT,
            item_id INTEGER,
            quantity_changed INTEGER,
            details TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );
INSERT INTO audit_trail VALUES(1,1,'LOGIN',NULL,NULL,'User logged in','2026-04-28 07:22:06');
INSERT INTO audit_trail VALUES(2,1,'LOGOUT',NULL,NULL,'User logged out','2026-04-28 08:08:12');
INSERT INTO audit_trail VALUES(3,3,'EDIT_CATEGORY',NULL,NULL,'Updated category: Dry Stores','2026-04-28 11:01:25');
INSERT INTO audit_trail VALUES(4,4,'ADD_NEW_ITEM_RECEIVE',9,50,'Added new item: A4 Paper Ream in category: Dry Stores. Initial stock: 50 from: Book World','2026-04-28 11:08:23');
INSERT INTO audit_trail VALUES(5,4,'RECEIVE_STOCK',9,5,'Received 5 of A4 Paper Ream. Old: 50, New: 55. Supplier: Book World','2026-04-28 11:09:01');
INSERT INTO audit_trail VALUES(6,7,'ADD_NEW_ITEM_RECEIVE',10,20,'Added new item: Paper in category: Consumables. Initial stock: 20 from: test','2026-05-06 10:30:25');
INSERT INTO audit_trail VALUES(7,6,'LOGOUT',NULL,NULL,'User logged out','2026-05-06 10:32:45');
INSERT INTO audit_trail VALUES(8,8,'LOGOUT',NULL,NULL,'User logged out','2026-05-06 10:34:50');
INSERT INTO audit_trail VALUES(9,3,'ADD_CATEGORY',NULL,NULL,'Added category: Marketing','2026-05-08 13:57:38');
INSERT INTO audit_trail VALUES(10,3,'ADD_CATEGORY',NULL,NULL,'Added category: IT','2026-05-08 13:58:18');
INSERT INTO audit_trail VALUES(11,3,'EDIT_CATEGORY',NULL,NULL,'Updated category: Stationary','2026-05-08 13:58:57');
DELETE FROM sqlite_sequence;
INSERT INTO sqlite_sequence VALUES('categories',6);
INSERT INTO sqlite_sequence VALUES('users',9);
INSERT INTO sqlite_sequence VALUES('items',10);
INSERT INTO sqlite_sequence VALUES('audit_trail',11);
INSERT INTO sqlite_sequence VALUES('stock_receiving',3);
COMMIT;
