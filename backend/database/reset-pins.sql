-- ============================================================
-- CaféOS — PIN Reset Script
-- Run this in phpMyAdmin → SQL tab
-- This sets ALL staff PINs to: 1234
-- ============================================================

USE `cafeos`;

-- The bcrypt hash below is for PIN: 1234
-- Generated with: password_hash('1234', PASSWORD_BCRYPT)
UPDATE `staff` SET `pin_code` = '$2y$10$N9qo8uLOickgx2ZMRZoMyeIjZAgcfl7p92ldGxad68LtIydST/2Gu' WHERE 1;

-- Verify the update worked
SELECT id, name, email, role FROM staff;
