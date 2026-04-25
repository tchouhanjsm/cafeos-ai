# CaféOS — Point of Sale System
**Version 1.0.0 — Final Build (All phases + bugfixes)**

---

## Quick Start (5 minutes)

### 1. Copy to XAMPP
Copy the entire `cafeos/` folder into your XAMPP htdocs:
- **Windows:** `C:\xampp\htdocs\cafeos\`
- **macOS:**   `/Applications/XAMPP/htdocs/cafeos/`
- **Linux:**   `/opt/lampp/htdocs/cafeos/`

### 2. Start XAMPP
Open XAMPP Control Panel → Start **Apache** + **MySQL**

### 3. Import database
Open http://localhost/phpmyadmin → run in the SQL tab:
```sql
CREATE DATABASE IF NOT EXISTS `cafeos` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```
Then: click **cafeos** → **Import** → choose `backend/database/schema.sql` → Go

### 4. Open the app
http://localhost/cafeos/frontend/pages/login.html

**Default PIN for all accounts: `password`** ← change immediately!

---

## Diagnostic Tool
If you see any error, open this first:
```
http://localhost/cafeos/backend/check.php
```
It checks every component and tells you exactly what to fix.

---

## Desktop App (Electron)
```bash
cd cafeos/electron
npm install --registry https://registry.npmjs.org
npm start
```
See `electron/BUILD.md` for packaging to .exe/.dmg/.AppImage

---

## What's Included

```
cafeos/
├── backend/               PHP 8.2 REST API (14 endpoints)
│   ├── api/               auth, tables, menu, orders, billing, reports
│   ├── config/            db.php, settings.php, bootstrap.php (error trap)
│   ├── helpers/           jwt.php, response.php
│   ├── middleware/        auth.php (JWT + RBAC)
│   ├── database/          schema.sql (8 tables + seed data)
│   ├── .htaccess          Security + display_errors Off
│   └── check.php          Diagnostic tool — DELETE before production
│
├── frontend/              Vanilla HTML/CSS/JS
│   ├── pages/             login, tables, pos, billing, orders,
│   │                      reports, menu-admin, settings
│   └── js/api.js          API client (Electron-aware, offline queue)
│
├── electron/              Desktop app wrapper
│   ├── main.js            Window, server monitor, IPC, tray
│   ├── preload.js         Secure IPC bridge
│   ├── printer.js         ESC/POS thermal receipt printing
│   ├── offline-queue.js   SQLite queue for offline operation
│   ├── logger.js          Rolling file logger
│   └── BUILD.md           Full packaging guide
│
├── TROUBLESHOOTING.html   Visual fix guide (open in browser)
└── README.md              This file
```

## Default Accounts
| Role     | Email                | PIN        |
|----------|----------------------|------------|
| Admin    | admin@cafeos.com     | `password` |
| Cashier  | cashier@cafeos.com   | `password` |
| Waiter   | waiter@cafeos.com    | `password` |

## Bugs Fixed in This Build
- `display_errors` PHP leak — caused "Unexpected token '<'" JSON error
- Wrong database name in docs (`cafeos_db` → `cafeos`)
- Global error/exception handler added (all errors return JSON, never HTML)
- `db.php` clears output buffer before returning error JSON
- All 6 API files now load `bootstrap.php` as very first line
- `api.js` Electron-aware: persists JWT for offline queue, queues failed writes
- `billing.html` uses native thermal printer in Electron, fallback in browser
- npm auth token error documented with fix commands
