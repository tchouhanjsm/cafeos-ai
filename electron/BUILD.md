# CaféOS Desktop App — Build Guide
# Phase 3: Electron Desktop Packaging

---

## What You're Building

The Electron wrapper turns CaféOS into a **native desktop app** (.exe for Windows,
.dmg for Mac, .AppImage/.deb for Linux) that:

- Opens in its own window (no browser bar, fullscreen-ready)
- Connects to your local XAMPP PHP server automatically
- Detects server outages and queues requests offline (SQLite)
- Prints thermal receipts directly to network or USB printers via ESC/POS
- Auto-updates from your own update server (optional)
- Appears in the system tray with quick actions

---

## Prerequisites

### Required (All Platforms)
| Software        | Version  | Download |
|-----------------|----------|----------|
| Node.js         | 18+      | https://nodejs.org |
| npm             | 9+       | Included with Node |
| XAMPP           | 8.2+     | https://apachefriends.org |
| PHP             | 8.2+     | Included with XAMPP |
| MySQL           | 8.0+     | Included with XAMPP |

### Windows Extras
- **Windows 10/11** (64-bit)
- **Visual Studio Build Tools** 2019+ — for native modules (SQLite)
  Download: https://visualstudio.microsoft.com/visual-cpp-build-tools/
  → During install, check "Desktop development with C++"

### macOS Extras
- **Xcode Command Line Tools**: `xcode-select --install`
- **macOS 11+** (Big Sur or later)

### Linux Extras
```bash
sudo apt-get install build-essential libusb-dev libudev-dev
```

---

## Step 1 — Project Structure

After extracting the ZIP, your folder should look like:
```
cafeos/
├── backend/              ← PHP API (goes in XAMPP/htdocs/cafeos/)
│   ├── api/
│   ├── config/
│   ├── helpers/
│   ├── middleware/
│   └── database/
├── frontend/             ← HTML/CSS/JS pages
│   ├── pages/
│   ├── js/
│   └── css/
└── electron/             ← Electron desktop app (THIS FOLDER)
    ├── main.js
    ├── preload.js
    ├── printer.js
    ├── offline-queue.js
    ├── logger.js
    ├── package.json
    └── build/            ← Put your app icons here
```

---

## Step 2 — XAMPP Setup (Backend)

1. **Install XAMPP** if not already done
2. Copy the **entire `cafeos/` folder** into XAMPP's htdocs:
   - Windows: `C:\xampp\htdocs\cafeos\`
   - macOS:   `/Applications/XAMPP/htdocs/cafeos/`
   - Linux:   `/opt/lampp/htdocs/cafeos/`

3. **Start XAMPP Control Panel** → Start both **Apache** and **MySQL**

4. **Import database**:
   - Open http://localhost/phpmyadmin
   - Create database: `cafeos` (exactly this name — no underscore)
   - Click Import → upload `cafeos/backend/database/schema.sql`
   - The schema auto-creates the database. Just make sure MySQL is running.

5. **Set JWT secret** in `cafeos/backend/config/settings.php`:
   ```php
   define('JWT_SECRET', 'your-random-64-char-secret-here');
   ```
   Generate one: `openssl rand -hex 32`

6. **Test backend**: Open http://localhost/cafeos/frontend/pages/login.html in browser
   - Default PIN for all accounts: `password` ← CHANGE THIS IMMEDIATELY

---

## Step 3 — Install Electron Dependencies

```bash
# Navigate to the electron folder
cd cafeos/electron

# Install all npm packages
npm install
```

This installs:
- `electron` — The Chromium+Node.js runtime
- `electron-builder` — Packaging tool
- `electron-store` — Persistent config storage
- `better-sqlite3` — Offline queue database
- `node-fetch` — HTTP client for queue flush
- `electron-updater` — Auto-update support

> **Windows note**: If `better-sqlite3` fails to compile, run:
> ```
> npm install --global windows-build-tools
> npm install
> ```

---

## Step 4 — Run in Development

```bash
cd cafeos/electron

# Development mode (opens DevTools automatically)
npm run start:dev

# Standard run
npm start
```

The app will:
1. Open a 1280×800 window
2. Try to connect to `http://localhost/cafeos`
3. Show the login page if server is running
4. Show the "Server Not Running" page if XAMPP is off

---

## Step 5 — Configure App Icons

Before building distributable packages, add your app icons to `electron/build/`:

| File               | Size    | Platform       |
|--------------------|---------|----------------|
| `icon.ico`         | 256×256 | Windows        |
| `icon.icns`        | 512×512 | macOS          |
| `icon.png`         | 512×512 | Linux / All    |
| `tray-icon.png`    | 16×16   | System tray    |

**Free icon tool**: https://icon.kitchen — generate all formats from one PNG

---

## Step 6 — Build Installers

### Windows (.exe installer + portable .exe)
```bash
cd cafeos/electron
npm run build:win
```
Output in `cafeos/dist/`:
- `CaféOS Setup 1.0.0.exe` — Full NSIS installer with shortcuts
- `CaféOS 1.0.0.exe` — Portable single-file executable

### macOS (.dmg)
```bash
npm run build:mac
```
Output:
- `CaféOS-1.0.0.dmg` — Drag-to-Applications installer

> **Code signing note**: For distribution outside your own network, you need
> an Apple Developer certificate. For local cafe use, right-click → Open on
> first launch to bypass Gatekeeper.

### Linux (.AppImage + .deb)
```bash
npm run build:linux
```
Output:
- `CaféOS-1.0.0.AppImage` — Universal, runs on any distro
- `cafeos_1.0.0_amd64.deb` — Debian/Ubuntu package

### All Platforms at Once
```bash
npm run build:all
```
Requires: Docker (for cross-compilation) or run on each platform separately.

---

## Step 7 — Thermal Printer Setup

### Network Printer (Recommended)
Most modern thermal printers (Epson TM-T82, Xprinter, TVS RP-3160) support
network printing over TCP/IP.

1. **Connect printer** to your WiFi/LAN router
2. **Find printer IP**: Print a test page — IP is shown on the receipt
   OR check your router's admin panel → DHCP clients
3. **Open CaféOS** → ⚙️ Settings → 🖨 Printer
4. Set **Connection Type** = Network (IP)
5. Enter **Printer IP** (e.g. `192.168.1.100`)
6. **Port**: Leave as `9100` (standard ESC/POS port)
7. Click **🖨 Print Test Receipt**

**Assigning a static IP to your printer** (recommended for reliability):
- Access router admin (usually http://192.168.1.1)
- Find printer MAC address in DHCP leases
- Reserve that MAC to a fixed IP (e.g. `192.168.1.100`)

### USB Printer
1. Connect printer via USB cable
2. Install manufacturer drivers if needed
3. Settings → 🖨 Printer → Connection Type = USB
4. Click **🔍 Detect** to find printer name
5. Select the printer from detected list
6. Print Test Receipt

### Supported Printer Models
| Brand    | Models                        | Connection |
|----------|-------------------------------|------------|
| Epson    | TM-T20, TM-T82, TM-T88       | USB + LAN  |
| TVS      | RP-3160, RP-3200              | USB + LAN  |
| Xprinter | XP-N160I, XP-Q300, XP-365B   | USB + WiFi |
| Bixolon  | SRP-350, SRP-F310             | USB + LAN  |
| Star     | TSP100, TSP650               | USB + LAN  |
| Generic  | Any ESC/POS 80mm printer      | USB + LAN  |

---

## Offline Mode

When XAMPP is not running or network is down:

1. **Server status bar** appears on login page (red warning)
2. Write operations (create order, add items, record payment) are **queued locally**
   in SQLite at `%APPDATA%\cafeos\data\offline-queue.db`
3. When server comes back online, the app detects it within 8 seconds
4. Go to **Settings → Offline Queue → ⬆ Flush to Server** to replay all queued requests
5. Or it flushes automatically on next page load

**Limitations of offline mode**:
- Cannot READ data (menu, orders) without server — only queuing writes
- Bill generation requires server (tax calculations are server-side)
- Use offline mode for emergencies only; keep XAMPP running normally

---

## Auto-Updates (Optional)

To enable automatic updates when you deploy new versions:

1. Set up a static file server (Nginx, S3, Cloudflare R2)
2. In `electron/package.json`, update the `publish.url`:
   ```json
   "publish": {
     "provider": "generic",
     "url": "https://your-server.com/cafeos-updates/"
   }
   ```
3. After building, upload `latest.yml` + installer to that URL
4. On next app start, Electron checks for updates and prompts user

---

## Troubleshooting

### "Server Not Running" on app launch
→ Start XAMPP → Apache + MySQL must both be green
→ Check http://localhost/cafeos in browser first

### "better-sqlite3" compile error
→ Windows: Install Visual Studio Build Tools
→ Run: `npm install --global windows-build-tools && npm install`

### Printer not responding
→ Check printer IP: it must be on the same network as your computer
→ Try pinging: `ping 192.168.1.100`
→ Check firewall — allow TCP port 9100 inbound
→ Try printing directly: `echo "" | nc 192.168.1.100 9100`

### App window is blank / white
→ XAMPP not running — start Apache + MySQL
→ Check DevTools (Ctrl+Shift+I) for errors
→ Navigate to http://localhost/cafeos/frontend/pages/login.html manually

### "Session expired" after window focus
→ Electron sessions persist via sessionStorage; window sleep may clear it
→ Log in again; future version will add token refresh

### Logs
All application logs are at:
- Windows: `%APPDATA%\cafeos\logs\app.log`
- macOS:   `~/Library/Application Support/cafeos/logs/app.log`
- Linux:   `~/.config/cafeos/logs/app.log`

---

## Updating CaféOS

### Manual update process:
1. Download new version ZIP
2. Replace `cafeos/backend/` and `cafeos/electron/` with new files
3. Run database migrations if provided in `backend/database/migrations/`
4. Run `npm install` in `electron/` folder
5. Rebuild if distributing to other machines

---

## Production Checklist

Before deploying at the cafe:

- [ ] Changed all default PINs (`password` → real PINs)
- [ ] Set a strong `JWT_SECRET` in settings.php
- [ ] Set correct cafe name, address, phone in Settings → Printer
- [ ] Configured printer IP and tested a test receipt
- [ ] XAMPP Apache + MySQL set to auto-start on Windows boot
  (XAMPP Control Panel → Service → Install Service for Apache + MySQL)
- [ ] Tested full order flow: table → order → kitchen → bill → payment → receipt
- [ ] Backed up database (export from phpMyAdmin)

---

## Folder Reference

```
electron/
├── main.js           Window management, IPC handlers, server monitor
├── preload.js        Secure IPC bridge (renderer ↔ main)
├── printer.js        ESC/POS thermal printer (USB + network)
├── offline-queue.js  SQLite queue for offline request storage
├── logger.js         Rolling file logger (5MB × 3 files)
├── package.json      Dependencies + electron-builder config
└── build/
    ├── icon.ico      Windows installer icon
    ├── icon.icns     macOS app icon
    ├── icon.png      Linux + fallback icon
    └── tray-icon.png System tray icon (16×16)
```
