// ============================================================
// CaféOS — Logger
// File: electron/logger.js
// ============================================================
// Writes logs to:
//   - Console (always)
//   - ~/.cafeos/logs/app.log (rolling, max 5MB × 3 files)
//
// Usage:
//   const logger = require('./logger');
//   logger.info('Order created', { orderId: 42 });
//   logger.error('Print failed', { error: err.message });
// ============================================================

const path    = require('path');
const fs      = require('fs');
const { app } = require('electron');

// ── Log directory ─────────────────────────────────────────────
// userData is platform-specific:
//   Windows: %APPDATA%\cafeos
//   macOS:   ~/Library/Application Support/cafeos
//   Linux:   ~/.config/cafeos
const logDir = app
  ? path.join(app.getPath('userData'), 'logs')
  : path.join(require('os').homedir(), '.cafeos', 'logs');

if (!fs.existsSync(logDir)) {
  fs.mkdirSync(logDir, { recursive: true });
}

const logFile = path.join(logDir, 'app.log');

// ── Simple rolling logger (no external dep needed) ────────────
const MAX_LOG_BYTES = 5 * 1024 * 1024; // 5MB per file
const MAX_FILES     = 3;

function rotateLogs() {
  try {
    const stats = fs.statSync(logFile);
    if (stats.size < MAX_LOG_BYTES) return;

    // Rotate: app.2.log → delete, app.1.log → app.2.log, app.log → app.1.log
    for (let i = MAX_FILES - 1; i >= 1; i--) {
      const src  = i === 1 ? logFile          : `${logFile}.${i - 1}`;
      const dest = `${logFile}.${i}`;
      if (fs.existsSync(src)) {
        if (i === MAX_FILES - 1 && fs.existsSync(dest)) fs.unlinkSync(dest);
        fs.renameSync(src, dest);
      }
    }
  } catch (_) {}
}

function writeLog(level, message, meta = {}) {
  rotateLogs();

  const timestamp = new Date().toISOString();
  const metaStr   = Object.keys(meta).length ? ' ' + JSON.stringify(meta) : '';
  const line      = `[${timestamp}] [${level.toUpperCase().padEnd(5)}] ${message}${metaStr}\n`;

  // Write to file
  try {
    fs.appendFileSync(logFile, line, 'utf8');
  } catch (_) {}

  // Write to console
  const consoleMethod = level === 'error' ? 'error' : level === 'warn' ? 'warn' : 'log';
  console[consoleMethod](`[CaféOS] [${level.toUpperCase()}] ${message}`, Object.keys(meta).length ? meta : '');
}

const logger = {
  info:  (msg, meta) => writeLog('info',  msg, meta),
  warn:  (msg, meta) => writeLog('warn',  msg, meta),
  error: (msg, meta) => writeLog('error', msg, meta),
  debug: (msg, meta) => {
    if (process.env.NODE_ENV === 'development') writeLog('debug', msg, meta);
  },
  getLogPath: () => logFile,
  getLogDir:  () => logDir,
};

module.exports = logger;
