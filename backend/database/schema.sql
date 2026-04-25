-- ============================================================
-- CaféOS — Point of Sale System
-- Database Schema v1.0
-- Engine: MySQL 8.0+ | Charset: utf8mb4
-- ============================================================
-- HOW TO INSTALL:
--   1. Open phpMyAdmin or MySQL CLI
--   2. Create database: CREATE DATABASE cafeos;
--   3. Run this file: mysql -u root -p cafeos < schema.sql
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET NAMES utf8mb4;

-- ============================================================
-- Create & select database
-- ============================================================
CREATE DATABASE IF NOT EXISTS `cafeos`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `cafeos`;

-- Drop tables in safe order (children first)
DROP TABLE IF EXISTS `bills`;
DROP TABLE IF EXISTS `order_items`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `menu_items`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `tables`;
DROP TABLE IF EXISTS `staff`;
DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `activity_log`;

-- ============================================================
-- TABLE: settings
-- Global configuration for the cafe
-- ============================================================
CREATE TABLE `settings` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `key_name`      VARCHAR(100) NOT NULL UNIQUE,
  `value`         TEXT NOT NULL,
  `label`         VARCHAR(150) NOT NULL,
  `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: staff
-- All employees who can log in to the POS
-- ============================================================
CREATE TABLE `staff` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`          VARCHAR(100) NOT NULL,
  `email`         VARCHAR(100) UNIQUE,
  `pin_code`      VARCHAR(255) NOT NULL,        -- bcrypt hashed 4-6 digit PIN
  `role`          ENUM('admin','cashier','waiter') NOT NULL DEFAULT 'waiter',
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_role` (`role`),
  INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: tables
-- Physical tables in the cafe
-- ============================================================
CREATE TABLE `tables` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `table_number`  VARCHAR(10) NOT NULL UNIQUE,  -- e.g. "T1", "T2", "BAR1"
  `capacity`      TINYINT UNSIGNED NOT NULL DEFAULT 4,
  `section`       VARCHAR(50) DEFAULT 'Main',   -- e.g. Main, Outdoor, Bar
  `status`        ENUM('free','occupied','reserved','cleaning') NOT NULL DEFAULT 'free',
  `qr_code`       VARCHAR(255) DEFAULT NULL,    -- URL for QR-based ordering (future)
  `pos_x`         FLOAT DEFAULT 0,              -- X position on floor map (%)
  `pos_y`         FLOAT DEFAULT 0,              -- Y position on floor map (%)
  `shape`         ENUM('square','round','rectangle') DEFAULT 'square',
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: categories
-- Menu groupings (e.g. Coffee, Food, Desserts)
-- ============================================================
CREATE TABLE `categories` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`          VARCHAR(80) NOT NULL,
  `icon`          VARCHAR(10) DEFAULT '🍽',     -- Emoji icon for UI
  `sort_order`    TINYINT UNSIGNED DEFAULT 0,    -- Display order in POS
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  INDEX `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: menu_items
-- Individual items on the menu
-- ============================================================
CREATE TABLE `menu_items` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `category_id`   INT UNSIGNED NOT NULL,
  `name`          VARCHAR(120) NOT NULL,
  `description`   TEXT DEFAULT NULL,
  `price`         DECIMAL(10,2) NOT NULL,
  `image_url`     VARCHAR(255) DEFAULT NULL,
  `is_available`  TINYINT(1) NOT NULL DEFAULT 1,  -- Quick toggle (86'd)
  `is_veg`        TINYINT(1) NOT NULL DEFAULT 1,
  `prep_time_min` TINYINT UNSIGNED DEFAULT 5,     -- Average prep time
  `sort_order`    SMALLINT UNSIGNED DEFAULT 0,
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE RESTRICT,
  INDEX `idx_category` (`category_id`),
  INDEX `idx_available` (`is_available`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: orders
-- Each order session for a table or takeaway
-- ============================================================
CREATE TABLE `orders` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `order_number`    VARCHAR(20) NOT NULL UNIQUE,   -- e.g. ORD-20250228-001
  `table_id`        INT UNSIGNED DEFAULT NULL,      -- NULL for takeaway/delivery
  `staff_id`        INT UNSIGNED NOT NULL,
  `order_type`      ENUM('dine_in','takeaway','delivery') NOT NULL DEFAULT 'dine_in',
  `status`          ENUM('open','sent','ready','served','billed','cancelled') NOT NULL DEFAULT 'open',
  `guest_count`     TINYINT UNSIGNED DEFAULT 1,
  `notes`           TEXT DEFAULT NULL,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`table_id`) REFERENCES `tables`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`staff_id`) REFERENCES `staff`(`id`) ON DELETE RESTRICT,
  INDEX `idx_status`      (`status`),
  INDEX `idx_table`       (`table_id`),
  INDEX `idx_created`     (`created_at`),
  INDEX `idx_order_type`  (`order_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: order_items
-- Individual line items within an order
-- ============================================================
CREATE TABLE `order_items` (
  `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `order_id`      INT UNSIGNED NOT NULL,
  `menu_item_id`  INT UNSIGNED NOT NULL,
  `item_name`     VARCHAR(120) NOT NULL,          -- Snapshot of name at time of order
  `unit_price`    DECIMAL(10,2) NOT NULL,         -- Snapshot of price at time of order
  `quantity`      TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `subtotal`      DECIMAL(10,2) GENERATED ALWAYS AS (`unit_price` * `quantity`) STORED,
  `notes`         VARCHAR(255) DEFAULT NULL,      -- e.g. "No sugar", "Extra shot"
  `status`        ENUM('pending','cooking','ready','served','voided') NOT NULL DEFAULT 'pending',
  `voided_by`     INT UNSIGNED DEFAULT NULL,      -- staff_id who voided
  `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`order_id`)     REFERENCES `orders`(`id`)     ON DELETE CASCADE,
  FOREIGN KEY (`menu_item_id`) REFERENCES `menu_items`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`voided_by`)    REFERENCES `staff`(`id`)      ON DELETE SET NULL,
  INDEX `idx_order`  (`order_id`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: bills
-- Final bill generated for an order (payment record)
-- ============================================================
CREATE TABLE `bills` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bill_number`     VARCHAR(20) NOT NULL UNIQUE,   -- e.g. BILL-20250228-001
  `order_id`        INT UNSIGNED NOT NULL UNIQUE,   -- One bill per order
  `staff_id`        INT UNSIGNED NOT NULL,          -- Cashier who processed
  `subtotal`        DECIMAL(10,2) NOT NULL,
  `discount_type`   ENUM('none','percent','flat') NOT NULL DEFAULT 'none',
  `discount_value`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `tax_rate`        DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `tax_amount`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `grand_total`     DECIMAL(10,2) NOT NULL,
  `amount_tendered` DECIMAL(10,2) DEFAULT NULL,    -- Cash given by customer
  `change_due`      DECIMAL(10,2) DEFAULT NULL,    -- Change to return
  `payment_method`  ENUM('cash','card','upi','split','complimentary') NOT NULL DEFAULT 'cash',
  `payment_status`  ENUM('pending','paid','refunded') NOT NULL DEFAULT 'pending',
  `notes`           TEXT DEFAULT NULL,
  `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  FOREIGN KEY (`order_id`)  REFERENCES `orders`(`id`) ON DELETE RESTRICT,
  FOREIGN KEY (`staff_id`)  REFERENCES `staff`(`id`)  ON DELETE RESTRICT,
  INDEX `idx_payment_status` (`payment_status`),
  INDEX `idx_created`        (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- TABLE: activity_log
-- Audit trail for important actions
-- ============================================================
CREATE TABLE `activity_log` (
  `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `staff_id`    INT UNSIGNED DEFAULT NULL,
  `action`      VARCHAR(80) NOT NULL,       -- e.g. 'login', 'void_item', 'apply_discount'
  `target_type` VARCHAR(40) DEFAULT NULL,   -- e.g. 'order', 'bill', 'menu_item'
  `target_id`   INT UNSIGNED DEFAULT NULL,
  `details`     TEXT DEFAULT NULL,          -- JSON string with extra info
  `ip_address`  VARCHAR(45) DEFAULT NULL,
  `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`staff_id`) REFERENCES `staff`(`id`) ON DELETE SET NULL,
  INDEX `idx_action`  (`action`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ============================================================
-- SEED DATA
-- ============================================================

-- Settings
INSERT INTO `settings` (`key_name`, `value`, `label`) VALUES
('cafe_name',       'My Cafe',          'Cafe Name'),
('cafe_address',    '123 Coffee Lane, City', 'Address'),
('cafe_phone',      '+91 98765 43210',  'Phone Number'),
('cafe_email',      'hello@mycafe.com', 'Email'),
('currency_symbol', '₹',               'Currency Symbol'),
('currency_code',   'INR',             'Currency Code'),
('tax_rate',        '5.00',            'Tax Rate (%)'),
('tax_label',       'GST',             'Tax Label'),
('receipt_footer',  'Thank you for visiting! Come again ☕', 'Receipt Footer Message'),
('timezone',        'Asia/Kolkata',    'Timezone'),
('bill_prefix',     'BILL',            'Bill Number Prefix'),
('order_prefix',    'ORD',             'Order Number Prefix');

-- Staff  (PIN for all starter accounts: 1234)
-- Generated with: password_hash('1234', PASSWORD_BCRYPT)
-- IMPORTANT: Change PINs immediately after first login!
INSERT INTO `staff` (`name`, `email`, `pin_code`, `role`) VALUES
('Admin User',   'admin@cafeos.com',   '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LtIydST/2Gu', 'admin'),
('Sam Cashier',  'cashier@cafeos.com', '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LtIydST/2Gu', 'cashier'),
('Raj Waiter',   'waiter@cafeos.com',  '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LtIydST/2Gu', 'waiter');

-- Tables (12 tables across 3 sections)
INSERT INTO `tables` (`table_number`, `capacity`, `section`, `pos_x`, `pos_y`, `shape`) VALUES
('T1',   2, 'Main',    10, 15, 'square'),
('T2',   2, 'Main',    25, 15, 'square'),
('T3',   4, 'Main',    40, 15, 'square'),
('T4',   4, 'Main',    55, 15, 'square'),
('T5',   4, 'Main',    10, 45, 'round'),
('T6',   4, 'Main',    25, 45, 'round'),
('T7',   6, 'Main',    42, 45, 'rectangle'),
('T8',   6, 'Main',    62, 45, 'rectangle'),
('O1',   2, 'Outdoor', 10, 20, 'round'),
('O2',   2, 'Outdoor', 35, 20, 'round'),
('O3',   4, 'Outdoor', 62, 20, 'round'),
('B1',   3, 'Bar',     20, 50, 'square'),
('B2',   3, 'Bar',     50, 50, 'square');

-- Categories
INSERT INTO `categories` (`name`, `icon`, `sort_order`) VALUES
('Coffee',      '☕', 1),
('Tea',         '🍵', 2),
('Cold Drinks', '🧃', 3),
('Food',        '🍽', 4),
('Snacks',      '🥪', 5),
('Desserts',    '🍰', 6);

-- Menu Items
INSERT INTO `menu_items` (`category_id`, `name`, `description`, `price`, `is_veg`, `prep_time_min`, `sort_order`) VALUES
-- Coffee (cat 1)
(1, 'Espresso',         'Single shot of rich espresso',             90.00,  1, 3,  1),
(1, 'Double Espresso',  'Two shots of espresso',                    130.00, 1, 3,  2),
(1, 'Cappuccino',       'Espresso with steamed milk foam',          150.00, 1, 5,  3),
(1, 'Café Latte',       'Espresso with steamed milk',               160.00, 1, 5,  4),
(1, 'Americano',        'Espresso diluted with hot water',          120.00, 1, 4,  5),
(1, 'Flat White',       'Ristretto with velvety steamed milk',      170.00, 1, 5,  6),
(1, 'Mocha',            'Espresso with chocolate and steamed milk', 180.00, 1, 6,  7),
(1, 'Macchiato',        'Espresso with a dash of milk foam',        140.00, 1, 4,  8),

-- Tea (cat 2)
(2, 'Masala Chai',      'Spiced Indian milk tea',                   80.00,  1, 5,  1),
(2, 'Green Tea',        'Light and refreshing green tea',           90.00,  1, 4,  2),
(2, 'Earl Grey',        'Classic bergamot-infused black tea',       100.00, 1, 5,  3),
(2, 'Chamomile',        'Soothing herbal chamomile tea',            110.00, 1, 5,  4),

-- Cold Drinks (cat 3)
(3, 'Cold Coffee',      'Chilled coffee with ice cream',            170.00, 1, 5,  1),
(3, 'Iced Latte',       'Espresso over ice with cold milk',         180.00, 1, 4,  2),
(3, 'Lemonade',         'Fresh-squeezed lemon with mint',           120.00, 1, 3,  3),
(3, 'Mango Smoothie',   'Fresh mango blended smooth',               160.00, 1, 5,  4),
(3, 'Iced Tea',         'Chilled tea with lemon and mint',          120.00, 1, 3,  5),

-- Food (cat 4)
(4, 'Avocado Toast',    'Sourdough with avocado and seasoning',     250.00, 1, 8,  1),
(4, 'Eggs Benedict',    'Poached eggs on English muffin',           320.00, 0, 12, 2),
(4, 'Club Sandwich',    'Triple-decker with chicken and veggies',   280.00, 0, 10, 3),
(4, 'Veg Sandwich',     'Grilled vegetables in whole wheat bread',  220.00, 1, 8,  4),
(4, 'Pasta Arrabbiata', 'Penne in spicy tomato sauce',              290.00, 1, 15, 5),
(4, 'Caesar Salad',     'Romaine with Caesar dressing',             240.00, 0, 7,  6),

-- Snacks (cat 5)
(5, 'Croissant',        'Buttery flaky croissant',                  130.00, 1, 5,  1),
(5, 'Banana Bread',     'Moist homemade banana bread slice',        120.00, 1, 3,  2),
(5, 'Muffin',           'Daily baked muffin (chef\'s choice)',      110.00, 1, 3,  3),
(5, 'French Fries',     'Crispy salted fries',                      140.00, 1, 8,  4),
(5, 'Garlic Bread',     'Toasted garlic butter bread',              120.00, 1, 6,  5),

-- Desserts (cat 6)
(6, 'Tiramisu',         'Classic Italian coffee dessert',           220.00, 1, 3,  1),
(6, 'Cheesecake',       'New York style baked cheesecake',          200.00, 1, 3,  2),
(6, 'Brownie',          'Warm chocolate brownie with ice cream',    180.00, 1, 5,  3),
(6, 'Waffles',          'Belgian waffles with maple syrup',         240.00, 1, 10, 4);

-- ============================================================
-- VIEWS (convenient query shortcuts)
-- ============================================================

-- Active menu with category info
CREATE OR REPLACE VIEW `v_menu_active` AS
SELECT
  m.id, m.name, m.description, m.price,
  m.is_available, m.is_veg, m.prep_time_min,
  c.id AS category_id, c.name AS category_name, c.icon AS category_icon
FROM `menu_items` m
JOIN `categories` c ON c.id = m.category_id
WHERE m.is_available = 1 AND c.is_active = 1
ORDER BY c.sort_order, m.sort_order;

-- Open orders with table and staff info
CREATE OR REPLACE VIEW `v_open_orders` AS
SELECT
  o.id, o.order_number, o.status, o.order_type, o.guest_count, o.created_at,
  t.table_number, t.section,
  s.name AS staff_name,
  COUNT(oi.id) AS item_count,
  SUM(oi.subtotal) AS total_amount
FROM `orders` o
LEFT JOIN `tables`      t  ON t.id = o.table_id
LEFT JOIN `staff`       s  ON s.id = o.staff_id
LEFT JOIN `order_items` oi ON oi.order_id = o.id AND oi.status != 'voided'
WHERE o.status NOT IN ('billed', 'cancelled')
GROUP BY o.id
ORDER BY o.created_at DESC;

-- Daily sales summary
CREATE OR REPLACE VIEW `v_daily_sales` AS
SELECT
  DATE(b.created_at)          AS sale_date,
  COUNT(DISTINCT b.id)        AS total_bills,
  SUM(b.subtotal)             AS subtotal,
  SUM(b.discount_amount)      AS total_discounts,
  SUM(b.tax_amount)           AS total_tax,
  SUM(b.grand_total)          AS revenue,
  SUM(IF(b.payment_method='cash',  b.grand_total, 0)) AS cash,
  SUM(IF(b.payment_method='card',  b.grand_total, 0)) AS card,
  SUM(IF(b.payment_method='upi',   b.grand_total, 0)) AS upi,
  SUM(IF(b.payment_method='split', b.grand_total, 0)) AS split_payment
FROM `bills` b
WHERE b.payment_status = 'paid'
GROUP BY DATE(b.created_at)
ORDER BY sale_date DESC;

-- ============================================================
-- DONE — Schema installed successfully
-- Default PIN for all starter staff accounts: password
-- IMPORTANT: Change PINs immediately after first login!
-- ============================================================
