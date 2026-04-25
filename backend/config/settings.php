<?php
// ============================================================
// CaféOS — App Settings
// File: backend/config/settings.php
// ============================================================
// ⚠️  IMPORTANT: Change JWT_SECRET before going to production!
//     Run this in terminal to generate a strong secret:
//     php -r "echo bin2hex(random_bytes(32));"
// ============================================================

// ── Environment ─────────────────────────────────────────────
define('APP_ENV',       'development');   // 'development' | 'production'
define('APP_VERSION',   '1.0.0');

// ── JWT Authentication ───────────────────────────────────────
define('JWT_SECRET',    'CHANGE_THIS_TO_A_RANDOM_64_CHAR_STRING_IN_PRODUCTION');
define('JWT_EXPIRY',    28800);   // 8 hours in seconds (one shift)
define('JWT_ALGORITHM', 'HS256');

// ── CORS ────────────────────────────────────────────────────
// In development allow all origins. In production set your domain.
define('CORS_ORIGIN', APP_ENV === 'production' ? 'https://yourapp.com' : '*');

// ── Paths ────────────────────────────────────────────────────
define('BASE_PATH',     dirname(__DIR__));
define('UPLOAD_PATH',   BASE_PATH . '/uploads/');
define('UPLOAD_URL',    '/backend/uploads/');

// ── Pagination defaults ──────────────────────────────────────
define('DEFAULT_PAGE_SIZE', 50);

// ── Business Rules ───────────────────────────────────────────
// These can also be loaded from the `settings` table at runtime.
define('DEFAULT_TAX_RATE',    5.00);    // % — override from DB settings
define('MAX_DISCOUNT_PERCENT', 50);     // Max % discount a cashier can apply
define('REQUIRE_MANAGER_APPROVAL_ABOVE', 20); // % — needs admin to approve above this

// ── Logging ─────────────────────────────────────────────────
define('LOG_ERRORS', true);
define('LOG_PATH',   BASE_PATH . '/logs/app.log');
