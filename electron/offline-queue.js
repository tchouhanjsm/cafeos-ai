// ============================================================
// CaféOS — Offline Queue
// File: electron/offline-queue.js
// ============================================================
// When the PHP/XAMPP server is unreachable, POS operations are
// stored in a local SQLite database. When connectivity is
// restored, flush() replays all queued requests against the
// server in the order they were made.
//
// Queue entry structure:
//   { id, method, endpoint, body, created_at, retries, status }
//
// The renderer stores the JWT token in sessionStorage —
// the flush function reads it from the config store so it can
// include Authorization headers when replaying requests.
// ============================================================

const path    = require('path');
const fs      = require('fs');
const { app } = require('electron');
const logger  = require('./logger');

let db = null; // better-sqlite3 instance

// ── DB file path ──────────────────────────────────────────────
function getDbPath() {
  const dir = app
    ? path.join(app.getPath('userData'), 'data')
    : path.join(require('os').homedir(), '.cafeos', 'data');

  if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
  return path.join(dir, 'offline-queue.db');
}

// ── Init ──────────────────────────────────────────────────────
async function init() {
  try {
    const Database = require('better-sqlite3');
    db = new Database(getDbPath());

    // Enable WAL mode for better concurrent access
    db.pragma('journal_mode = WAL');
    db.pragma('foreign_keys = ON');

    // Create queue table
    db.exec(`
      CREATE TABLE IF NOT EXISTS queue (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        method      TEXT    NOT NULL,
        endpoint    TEXT    NOT NULL,
        body        TEXT,
        created_at  TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
        retries     INTEGER NOT NULL DEFAULT 0,
        last_error  TEXT,
        status      TEXT    NOT NULL DEFAULT 'pending'
      );

      CREATE INDEX IF NOT EXISTS idx_status ON queue(status);
    `);

    logger.info('Offline queue DB initialized', { path: getDbPath() });
  } catch (err) {
    logger.error('Failed to initialize offline queue', { error: err.message });
    // Graceful degradation: queue just won't persist
    db = null;
  }
}

// ── Add item to queue ─────────────────────────────────────────
function add(item) {
  if (!db) return { success: false, error: 'Queue not available' };

  try {
    const stmt = db.prepare(`
      INSERT INTO queue (method, endpoint, body, status)
      VALUES (?, ?, ?, 'pending')
    `);
    const result = stmt.run(
      item.method   || 'POST',
      item.endpoint || '',
      item.body ? JSON.stringify(item.body) : null
    );
    logger.info('Item added to offline queue', { id: result.lastInsertRowid, endpoint: item.endpoint });
    return { success: true, id: result.lastInsertRowid };
  } catch (err) {
    logger.error('Queue add failed', { error: err.message });
    return { success: false, error: err.message };
  }
}

// ── List queued items ─────────────────────────────────────────
function list(status = null) {
  if (!db) return [];

  try {
    if (status) {
      return db.prepare('SELECT * FROM queue WHERE status = ? ORDER BY id ASC').all(status);
    }
    return db.prepare('SELECT * FROM queue ORDER BY id ASC').all();
  } catch (err) {
    logger.error('Queue list failed', { error: err.message });
    return [];
  }
}

// ── Count pending ─────────────────────────────────────────────
function count() {
  if (!db) return 0;
  try {
    const row = db.prepare("SELECT COUNT(*) as cnt FROM queue WHERE status = 'pending'").get();
    return row ? row.cnt : 0;
  } catch (_) { return 0; }
}

// ── Remove item ────────────────────────────────────────────────
function remove(id) {
  if (!db) return false;
  try {
    db.prepare('DELETE FROM queue WHERE id = ?').run(id);
    return true;
  } catch (_) { return false; }
}

// ── Clear all ─────────────────────────────────────────────────
function clear() {
  if (!db) return;
  try { db.exec("DELETE FROM queue"); } catch (_) {}
}

// ── Flush queue to server ─────────────────────────────────────
async function flush(serverBaseUrl) {
  if (!db) return { flushed: 0, failed: 0, errors: [] };

  const pending = list('pending');
  if (!pending.length) {
    logger.info('Offline queue is empty, nothing to flush');
    return { flushed: 0, failed: 0, errors: [] };
  }

  logger.info(`Flushing ${pending.length} queued request(s)`, { serverBaseUrl });

  // Load token from electron-store (set when user logs in)
  let token = null;
  try {
    const Store = require('electron-store');
    const store = new Store({ name: 'cafeos-config' });
    token = store.get('authToken') || null;
  } catch (_) {}

  const fetch   = require('node-fetch');
  let flushed   = 0;
  let failed    = 0;
  const errors  = [];

  for (const item of pending) {
    try {
      const url     = `${serverBaseUrl}/cafeos/backend/api/${item.endpoint}`;
      const headers = { 'Content-Type': 'application/json' };
      if (token) headers['Authorization'] = `Bearer ${token}`;

      const opts = {
        method:  item.method,
        headers,
        timeout: 8000,
      };
      if (item.body && item.method !== 'GET') {
        opts.body = item.body; // already JSON string
      }

      const res  = await fetch(url, opts);
      const json = await res.json();

      if (json.success) {
        // Mark as done
        db.prepare("UPDATE queue SET status = 'done' WHERE id = ?").run(item.id);
        flushed++;
        logger.info('Queue item flushed', { id: item.id, endpoint: item.endpoint });
      } else {
        // Server returned an error — increment retries
        db.prepare("UPDATE queue SET retries = retries + 1, last_error = ?, status = CASE WHEN retries >= 4 THEN 'failed' ELSE 'pending' END WHERE id = ?")
          .run(json.message || 'Server error', item.id);
        failed++;
        errors.push({ id: item.id, error: json.message });
        logger.warn('Queue item failed (server error)', { id: item.id, msg: json.message });
      }
    } catch (err) {
      // Network error — keep as pending, increment retries
      db.prepare("UPDATE queue SET retries = retries + 1, last_error = ? WHERE id = ?")
        .run(err.message, item.id);
      failed++;
      errors.push({ id: item.id, error: err.message });
      logger.error('Queue item flush error', { id: item.id, error: err.message });
    }
  }

  // Clean up done items older than 24 hours
  db.exec("DELETE FROM queue WHERE status = 'done'");

  logger.info('Queue flush complete', { flushed, failed });
  return { flushed, failed, errors };
}

module.exports = { init, add, list, count, remove, clear, flush };
