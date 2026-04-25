// ============================================================
// CaféOS — Preload Script (IPC Bridge)
// File: electron/preload.js
// ============================================================
// This file runs in a privileged context with access to Node.js
// but exposes only specific, safe APIs to the renderer process.
//
// The renderer (HTML pages) accesses these via:
//   window.electronAPI.printer.printReceipt(data)
//   window.electronAPI.config.get('printerIp')
//   etc.
//
// contextIsolation: true ensures the renderer cannot access
// Node.js APIs directly — NEVER set this to false.
// ============================================================

const { contextBridge, ipcRenderer } = require('electron');

// ── Expose safe API to renderer ───────────────────────────────
contextBridge.exposeInMainWorld('electronAPI', {

  // ── Is running in Electron? ─────────────────────────────────
  isElectron: true,

  // ── App info ─────────────────────────────────────────────────
  app: {
    getVersion:   () => ipcRenderer.invoke('app:version'),
    getPlatform:  () => ipcRenderer.invoke('app:platform'),
    reload:       () => ipcRenderer.send('app:reload'),
    openExternal: (url) => ipcRenderer.send('app:open-external', url),
    saveDialog:   (opts) => ipcRenderer.invoke('app:save-dialog', opts),
    installUpdate:() => ipcRenderer.send('app:install-update'),
  },

  // ── Configuration store ──────────────────────────────────────
  config: {
    get:    (key)        => ipcRenderer.invoke('config:get', key),
    set:    (key, value) => ipcRenderer.invoke('config:set', key, value),
    getAll: ()           => ipcRenderer.invoke('config:getAll'),
  },

  // ── Printer ──────────────────────────────────────────────────
  printer: {
    printReceipt: (data)  => ipcRenderer.invoke('printer:print-receipt', data),
    test:         ()      => ipcRenderer.invoke('printer:test'),
    listPrinters: ()      => ipcRenderer.invoke('printer:get-printers'),
  },

  // ── Offline queue ────────────────────────────────────────────
  queue: {
    add:    (item) => ipcRenderer.invoke('queue:add', item),
    list:   ()     => ipcRenderer.invoke('queue:list'),
    remove: (id)   => ipcRenderer.invoke('queue:delete', id),
    flush:  ()     => ipcRenderer.invoke('queue:flush'),
    count:  ()     => ipcRenderer.invoke('queue:count'),
  },

  // ── Server status ────────────────────────────────────────────
  server: {
    check: () => ipcRenderer.invoke('server:check'),
  },

  // ── Event listeners (main → renderer) ────────────────────────
  on: {
    serverStatus: (cb)   => ipcRenderer.on('server-status',   (_, data) => cb(data)),
    updateAvailable:(cb) => ipcRenderer.on('update-available',(_, data) => cb(data)),
    updateDownloaded:(cb)=> ipcRenderer.on('update-downloaded',(_, data) => cb(data)),
    openSettings: (cb)   => ipcRenderer.on('open-settings',   (_, page) => cb(page)),
    checkServer:  (cb)   => ipcRenderer.on('check-server',    () => cb()),
  },

  // ── Remove listeners ────────────────────────────────────────
  off: {
    serverStatus:    () => ipcRenderer.removeAllListeners('server-status'),
    updateAvailable: () => ipcRenderer.removeAllListeners('update-available'),
  },
});

// ── Notify main that preload is ready ─────────────────────────
// (Useful for debugging)
window.addEventListener('DOMContentLoaded', () => {
  // Signal to any page-level code that Electron APIs are available
  document.dispatchEvent(new CustomEvent('electron-ready'));
});
