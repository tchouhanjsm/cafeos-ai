// ============================================================
// CaféOS — Electron Main Process
// File: electron/main.js
// ============================================================
// This is the entry point for the desktop application.
// It:
//   1. Creates the main BrowserWindow
//   2. Detects if the PHP/XAMPP server is running
//   3. Exposes IPC handlers for printer, offline queue, config
//   4. Sets up auto-updater
//   5. Manages app lifecycle (tray, window state)
// ============================================================

const {
  app, BrowserWindow, ipcMain, Menu, Tray,
  shell, dialog, nativeImage, session,
} = require('electron');

const path    = require('path');
const http    = require('http');
const https   = require('https');
const fs      = require('fs');

// Local modules (loaded after app ready to allow native modules)
let store, logger, printer, offlineQueue, autoUpdater;

const isDev  = process.env.NODE_ENV === 'development';
const isMac  = process.platform === 'darwin';
const isWin  = process.platform === 'win32';

// ── App constants ─────────────────────────────────────────────
const APP_CONFIG = {
  SERVER_URL:   'http://localhost',
  SERVER_PORT:  80,
  PHP_PATH:     isWin ? 'C:\\xampp\\htdocs\\cafeos' : '/Applications/XAMPP/htdocs/cafeos',
  FRONTEND_DIR: isDev
    ? path.join(__dirname, '../frontend')
    : path.join(process.resourcesPath, 'frontend'),
  LOGIN_PAGE:   'pages/login.html',
  MIN_WIDTH:    1100,
  MIN_HEIGHT:   700,
  WINDOW_TITLE: 'CaféOS — Point of Sale',
};

let mainWindow = null;
let tray       = null;
let serverCheckInterval = null;

// ── App lifecycle ─────────────────────────────────────────────
app.whenReady().then(async () => {
  // Load modules that need native bindings after app is ready
  store        = new (require('electron-store'))({
    name:      'cafeos-config',
    defaults:  {
      serverUrl:        APP_CONFIG.SERVER_URL,
      serverPort:       APP_CONFIG.SERVER_PORT,
      printerType:      'network',   // 'usb' | 'network'
      printerIp:        '192.168.1.100',
      printerPort:      9100,
      receiptWidth:     48,          // chars per line (48 for 80mm, 32 for 57mm)
      cafeName:         'My Cafe',
      cafeAddress:      '123 Coffee Lane, Your City',
      cafePhone:        '+91 98765 43210',
      receiptFooter:    'Thank you! Come again ☕',
      windowBounds:     { width: 1280, height: 800 },
      windowMaximized:  false,
      offlineMode:      false,
      autoUpdate:       true,
    }
  });

  // Init logger
  logger = require('./logger');
  logger.info('CaféOS starting up', { version: app.getVersion(), platform: process.platform });

  // Create offline queue DB
  offlineQueue = require('./offline-queue');
  await offlineQueue.init();

  // Init printer
  printer = require('./printer');

  // Create window
  createMainWindow();

  // Setup tray icon
  setupTray();

  // Start server health check
  startServerMonitor();

  // Setup auto-updater (production only)
  if (!isDev && store.get('autoUpdate')) {
    setupAutoUpdater();
  }

  app.on('activate', () => {
    if (BrowserWindow.getAllWindows().length === 0) createMainWindow();
  });
});

app.on('window-all-closed', () => {
  if (!isMac) app.quit();
});

app.on('before-quit', () => {
  if (serverCheckInterval) clearInterval(serverCheckInterval);
  logger.info('CaféOS shutting down');
});

// ── Window creation ───────────────────────────────────────────
function createMainWindow() {
  const savedBounds = store.get('windowBounds');

  mainWindow = new BrowserWindow({
    title:          APP_CONFIG.WINDOW_TITLE,
    width:          savedBounds.width  || 1280,
    height:         savedBounds.height || 800,
    minWidth:       APP_CONFIG.MIN_WIDTH,
    minHeight:      APP_CONFIG.MIN_HEIGHT,
    center:         true,
    show:           false,   // shown after ready-to-show
    backgroundColor: '#F8F4EE',

    webPreferences: {
      preload:            path.join(__dirname, 'preload.js'),
      contextIsolation:   true,    // SECURITY: isolate renderer context
      nodeIntegration:    false,   // SECURITY: no direct Node in renderer
      webSecurity:        true,
      allowRunningInsecureContent: false,
    },

    // macOS specific
    titleBarStyle: isMac ? 'hiddenInset' : 'default',
    trafficLightPosition: { x: 16, y: 16 },
  });

  // Restore maximized state
  if (store.get('windowMaximized')) mainWindow.maximize();

  // ── Load the app ─────────────────────────────────────────────
  // Try to load from local PHP server first, fall back to offline HTML
  loadApp();

  // Show window once content is ready (prevents white flash)
  mainWindow.once('ready-to-show', () => {
    mainWindow.show();
    if (isDev) mainWindow.webContents.openDevTools({ mode: 'detach' });
    logger.info('Main window shown');
  });

  // Save window state on resize/move
  mainWindow.on('resize', saveWindowState);
  mainWindow.on('move',   saveWindowState);
  mainWindow.on('maximize',   () => store.set('windowMaximized', true));
  mainWindow.on('unmaximize', () => store.set('windowMaximized', false));

  // Handle external links — open in default browser not in-app
  mainWindow.webContents.setWindowOpenHandler(({ url }) => {
    if (url.startsWith('http')) shell.openExternal(url);
    return { action: 'deny' };
  });

  // Block navigation to non-local URLs for security
  mainWindow.webContents.on('will-navigate', (event, url) => {
    const serverUrl = store.get('serverUrl');
    const frontendDir = APP_CONFIG.FRONTEND_DIR;
    if (!url.startsWith(serverUrl) && !url.startsWith('file://') && !url.startsWith('http://localhost')) {
      event.preventDefault();
      logger.warn('Blocked navigation to external URL', { url });
    }
  });

  mainWindow.on('closed', () => { mainWindow = null; });

  // Build application menu
  buildAppMenu();

  logger.info('Main window created');
}

function loadApp() {
  const serverUrl = store.get('serverUrl');
  const port      = store.get('serverPort');
  const loginUrl  = `${serverUrl}:${port}/cafeos/frontend/pages/login.html`;

  // Check if server is available
  checkServer(serverUrl, port).then(available => {
    if (available) {
      logger.info('Server available, loading web app', { url: loginUrl });
      mainWindow.loadURL(loginUrl);
    } else {
      logger.warn('Server not available, loading offline mode');
      loadOfflineMode();
    }
  });
}

function loadOfflineMode() {
  // Load from embedded frontend files
  const offlinePage = path.join(APP_CONFIG.FRONTEND_DIR, APP_CONFIG.LOGIN_PAGE);

  if (fs.existsSync(offlinePage)) {
    mainWindow.loadFile(offlinePage);
    // Notify renderer that we're offline
    mainWindow.webContents.once('did-finish-load', () => {
      mainWindow.webContents.send('server-status', { online: false });
    });
  } else {
    // Fallback: show server-not-running page
    mainWindow.loadURL(`data:text/html,${encodeURIComponent(getServerNotRunningPage())}`);
  }
}

// ── Server monitoring ─────────────────────────────────────────
function startServerMonitor() {
  let lastStatus = null;

  serverCheckInterval = setInterval(async () => {
    const serverUrl = store.get('serverUrl');
    const port      = store.get('serverPort');
    const online    = await checkServer(serverUrl, port);

    if (online !== lastStatus) {
      lastStatus = online;
      logger.info('Server status changed', { online });

      if (mainWindow) {
        mainWindow.webContents.send('server-status', { online });
      }

      // If server came back online and we were in offline mode, reload
      if (online && mainWindow) {
        const currentUrl = mainWindow.webContents.getURL();
        if (currentUrl.startsWith('file://') || currentUrl.startsWith('data:')) {
          loadApp();
        }
      }
    }
  }, 8000); // Check every 8 seconds
}

function checkServer(serverUrl, port) {
  return new Promise(resolve => {
    const url = `${serverUrl}:${port}/cafeos/backend/api/auth.php`;
    const mod = serverUrl.startsWith('https') ? https : http;

    const req = mod.get(url, { timeout: 3000 }, (res) => {
      resolve(res.statusCode < 500);
    });
    req.on('error',   () => resolve(false));
    req.on('timeout', () => { req.destroy(); resolve(false); });
  });
}

// ── Tray ──────────────────────────────────────────────────────
function setupTray() {
  // Use a simple inline PNG as fallback (real apps use build/icon.png)
  try {
    const iconPath = path.join(__dirname, 'build', 'tray-icon.png');
    if (fs.existsSync(iconPath)) {
      tray = new Tray(iconPath);
    } else {
      // Create a minimal 16x16 placeholder icon
      const img = nativeImage.createEmpty();
      tray = new Tray(img);
    }
  } catch (e) {
    logger.warn('Could not create tray icon', { error: e.message });
    return;
  }

  tray.setToolTip('CaféOS — Point of Sale');

  const contextMenu = Menu.buildFromTemplate([
    { label: 'Open CaféOS',  click: () => { if (mainWindow) mainWindow.show(); else createMainWindow(); } },
    { type: 'separator' },
    { label: 'Check Server', click: () => { if (mainWindow) mainWindow.webContents.send('check-server'); } },
    { label: 'Reload App',   click: () => { if (mainWindow) loadApp(); } },
    { type: 'separator' },
    { label: 'Quit CaféOS',  role: 'quit' },
  ]);

  tray.setContextMenu(contextMenu);
  tray.on('double-click', () => { if (mainWindow) mainWindow.show(); });
}

// ── Application menu ──────────────────────────────────────────
function buildAppMenu() {
  const template = [
    ...(isMac ? [{ label: app.name, submenu: [{ role: 'about' }, { type: 'separator' }, { role: 'quit' }] }] : []),
    {
      label: 'App',
      submenu: [
        { label: 'Reload',         accelerator: 'CmdOrCtrl+R',         click: () => mainWindow?.webContents.reload() },
        { label: 'Hard Reload',    accelerator: 'CmdOrCtrl+Shift+R',   click: () => mainWindow?.webContents.reloadIgnoringCache() },
        { type: 'separator' },
        { label: 'Tables',         accelerator: 'CmdOrCtrl+1',         click: () => navigate('tables.html') },
        { label: 'POS Order',      accelerator: 'CmdOrCtrl+2',         click: () => navigate('pos.html') },
        { label: 'Billing',        accelerator: 'CmdOrCtrl+3',         click: () => navigate('billing.html') },
        { label: 'Reports',        accelerator: 'CmdOrCtrl+4',         click: () => navigate('reports.html') },
        { type: 'separator' },
        { label: 'Toggle Fullscreen', accelerator: 'F11',              click: () => mainWindow?.setFullScreen(!mainWindow?.isFullScreen()) },
        ...(!isMac ? [{ type: 'separator' }, { role: 'quit' }] : []),
      ],
    },
    {
      label: 'Settings',
      submenu: [
        { label: 'Printer Setup…',    click: () => mainWindow?.webContents.send('open-settings', 'printer') },
        { label: 'Server Settings…',  click: () => mainWindow?.webContents.send('open-settings', 'server') },
        { label: 'Offline Queue',     click: () => mainWindow?.webContents.send('open-settings', 'queue') },
        { type: 'separator' },
        { label: 'Check for Updates', click: () => autoUpdater?.checkForUpdatesAndNotify?.() },
        isDev ? { label: 'DevTools', accelerator: 'CmdOrCtrl+Shift+I', click: () => mainWindow?.webContents.toggleDevTools() } : null,
      ].filter(Boolean),
    },
  ];

  Menu.setApplicationMenu(Menu.buildFromTemplate(template));
}

function navigate(page) {
  const serverUrl = store.get('serverUrl');
  const port      = store.get('serverPort');
  mainWindow?.loadURL(`${serverUrl}:${port}/cafeos/frontend/${page}`);
}

// ── Window state ──────────────────────────────────────────────
function saveWindowState() {
  if (!mainWindow || mainWindow.isMaximized()) return;
  store.set('windowBounds', mainWindow.getBounds());
}

// ── IPC Handlers ─────────────────────────────────────────────
// Printer
ipcMain.handle('printer:print-receipt', async (_, receiptData) => {
  try {
    const result = await printer.printReceipt(receiptData, store.get());
    logger.info('Receipt printed', { billNum: receiptData.billNumber });
    return { success: true, result };
  } catch (err) {
    logger.error('Print failed', { error: err.message });
    return { success: false, error: err.message };
  }
});

ipcMain.handle('printer:test', async () => {
  try {
    await printer.printTest(store.get());
    return { success: true };
  } catch (err) {
    return { success: false, error: err.message };
  }
});

ipcMain.handle('printer:get-printers', async () => {
  try {
    const list = await printer.listPrinters();
    return { success: true, printers: list };
  } catch (err) {
    return { success: false, printers: [] };
  }
});

// Config store
ipcMain.handle('config:get', (_, key) => store.get(key));
ipcMain.handle('config:set', (_, key, value) => { store.set(key, value); return true; });
ipcMain.handle('config:getAll', () => store.store);

// Offline queue
ipcMain.handle('queue:add',     async (_, item)  => offlineQueue.add(item));
ipcMain.handle('queue:list',    async ()          => offlineQueue.list());
ipcMain.handle('queue:delete',  async (_, id)     => offlineQueue.remove(id));
ipcMain.handle('queue:flush',   async ()          => {
  const serverUrl = store.get('serverUrl');
  const port      = store.get('serverPort');
  return offlineQueue.flush(`${serverUrl}:${port}`);
});
ipcMain.handle('queue:count',   async ()          => offlineQueue.count());

// Server status
ipcMain.handle('server:check', async () => {
  const serverUrl = store.get('serverUrl');
  const port      = store.get('serverPort');
  const online    = await checkServer(serverUrl, port);
  return { online };
});

// App info
ipcMain.handle('app:version',  () => app.getVersion());
ipcMain.handle('app:platform', () => process.platform);

// File system — allow saving receipts as PDF
ipcMain.handle('app:save-dialog', async (_, options) => {
  const result = await dialog.showSaveDialog(mainWindow, options);
  return result;
});

// Open external
ipcMain.on('app:open-external', (_, url) => shell.openExternal(url));

// Reload
ipcMain.on('app:reload', () => loadApp());

// ── Auto-updater ──────────────────────────────────────────────
function setupAutoUpdater() {
  try {
    const { autoUpdater: au } = require('electron-updater');
    autoUpdater = au;

    autoUpdater.autoDownload    = false;
    autoUpdater.autoInstallOnAppQuit = true;
    autoUpdater.logger          = logger;

    autoUpdater.on('update-available', (info) => {
      logger.info('Update available', { version: info.version });
      if (mainWindow) {
        mainWindow.webContents.send('update-available', info);
      }
    });

    autoUpdater.on('update-downloaded', (info) => {
      logger.info('Update downloaded', { version: info.version });
      if (mainWindow) {
        mainWindow.webContents.send('update-downloaded', info);
      }
    });

    autoUpdater.on('error', (err) => {
      logger.error('Auto-updater error', { error: err.message });
    });

    autoUpdater.checkForUpdatesAndNotify();
    logger.info('Auto-updater initialized');
  } catch (err) {
    logger.warn('Auto-updater not available', { error: err.message });
  }
}

// Allow renderer to trigger update install
ipcMain.on('app:install-update', () => {
  autoUpdater?.quitAndInstall?.();
});

// ── Server not running page ───────────────────────────────────
function getServerNotRunningPage() {
  return `<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>CaféOS — Server Not Running</title>
<style>
  body { font-family: -apple-system, sans-serif; background: #F8F4EE; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
  .box { text-align: center; max-width: 480px; padding: 40px; }
  h1 { font-size: 48px; margin-bottom: 8px; }
  h2 { font-size: 22px; color: #1A0E05; margin-bottom: 12px; }
  p { color: #5A5249; font-size: 14px; line-height: 1.6; margin-bottom: 8px; }
  ol { text-align: left; color: #5A5249; font-size: 14px; line-height: 2; margin: 20px 0; }
  button { background: #1A0E05; color: white; border: none; padding: 12px 28px; border-radius: 8px; font-size: 14px; cursor: pointer; margin-top: 16px; }
  button:hover { background: #3D1F0A; }
  .code { background: #EDE8DF; padding: 4px 10px; border-radius: 4px; font-family: monospace; font-size: 13px; }
</style>
</head>
<body>
<div class="box">
  <div h1>☕</div>
  <h2>XAMPP Server Not Running</h2>
  <p>CaféOS needs a local PHP server to work.</p>
  <ol>
    <li>Open <strong>XAMPP Control Panel</strong></li>
    <li>Click <strong>Start</strong> next to <strong>Apache</strong></li>
    <li>Click <strong>Start</strong> next to <strong>MySQL</strong></li>
    <li>Wait for both to turn green</li>
  </ol>
  <p>Then click Retry below:</p>
  <button onclick="window.electronAPI && window.electronAPI.reload()">↻ Retry Connection</button>
</div>
</body>
</html>`;
}
