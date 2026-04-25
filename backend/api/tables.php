<?php
require_once __DIR__ . '/../config/bootstrap.php';
// ============================================================
// CaféOS — Tables API
// File: backend/api/tables.php
// ============================================================
// Endpoints:
//   GET    /api/tables.php          → List all tables (with active order info)
//   GET    /api/tables.php?id=1     → Get single table
//   POST   /api/tables.php          → Create table (admin only)
//   PUT    /api/tables.php?id=1     → Update table status / details
//   DELETE /api/tables.php?id=1     → Delete table (admin only, no active orders)
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../middleware/auth.php';

Response::cors();

$method  = $_SERVER['REQUEST_METHOD'];
$tableId = isset($_GET['id']) ? (int)$_GET['id'] : null;

match ($method) {
    'GET'    => $tableId ? getTable($tableId)  : listTables(),
    'POST'   => createTable(),
    'PUT'    => updateTable($tableId),
    'DELETE' => deleteTable($tableId),
    default  => Response::error('Method not allowed', 405),
};

// ── GET all tables ───────────────────────────────────────────
function listTables(): void {
    $section = $_GET['section'] ?? null;

    // Join with open orders to show what's active on each table
    $sql = "
        SELECT
            t.id, t.table_number, t.capacity, t.section,
            t.status, t.pos_x, t.pos_y, t.shape,
            t.updated_at,
            o.id          AS order_id,
            o.order_number,
            o.created_at  AS order_started,
            o.guest_count,
            TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) AS occupied_minutes,
            COUNT(oi.id)  AS item_count,
            COALESCE(SUM(CASE WHEN oi.status != 'voided' THEN oi.subtotal ELSE 0 END), 0) AS running_total
        FROM tables t
        LEFT JOIN orders o ON o.table_id = t.id
            AND o.status NOT IN ('billed', 'cancelled')
        LEFT JOIN order_items oi ON oi.order_id = o.id
    ";

    $params = [];
    if ($section) {
        $sql .= " WHERE t.section = ?";
        $params[] = $section;
    }

    $sql .= " GROUP BY t.id, o.id ORDER BY t.section, t.table_number";

    $tables = Database::query($sql, $params);

    // Cast numeric fields
    foreach ($tables as &$t) {
        $t['capacity']         = (int)$t['capacity'];
        $t['pos_x']            = (float)$t['pos_x'];
        $t['pos_y']            = (float)$t['pos_y'];
        $t['order_id']         = $t['order_id'] ? (int)$t['order_id'] : null;
        $t['item_count']       = (int)$t['item_count'];
        $t['running_total']    = (float)$t['running_total'];
        $t['occupied_minutes'] = $t['occupied_minutes'] ? (int)$t['occupied_minutes'] : null;
        $t['guest_count']      = $t['guest_count'] ? (int)$t['guest_count'] : null;
    }

    // Group by section for easier frontend rendering
    $grouped = [];
    foreach ($tables as $t) {
        $grouped[$t['section']][] = $t;
    }

    Response::success([
        'tables'   => $tables,
        'grouped'  => $grouped,
        'sections' => array_keys($grouped),
        'summary'  => [
            'total'    => count($tables),
            'occupied' => count(array_filter($tables, fn($t) => $t['status'] === 'occupied')),
            'free'     => count(array_filter($tables, fn($t) => $t['status'] === 'free')),
            'reserved' => count(array_filter($tables, fn($t) => $t['status'] === 'reserved')),
        ],
    ]);
}

// ── GET single table ─────────────────────────────────────────
function getTable(int $id): void {
    $table = Database::queryOne('SELECT * FROM tables WHERE id = ?', [$id]);

    if (!$table) Response::error("Table not found", 404);

    // Include the active order if any
    $order = Database::queryOne(
        "SELECT o.*, s.name AS staff_name
         FROM orders o
         LEFT JOIN staff s ON s.id = o.staff_id
         WHERE o.table_id = ? AND o.status NOT IN ('billed','cancelled')
         LIMIT 1",
        [$id]
    );

    $table['active_order'] = $order ?: null;
    Response::success($table);
}

// ── POST — Create table ──────────────────────────────────────
function createTable(): void {
    Auth::requireRole('admin');

    $body = Response::getBody();
    $num  = trim($body['table_number'] ?? '');

    if (empty($num)) Response::error('table_number is required', 422);

    // Check uniqueness
    $exists = Database::queryOne('SELECT id FROM tables WHERE table_number = ?', [$num]);
    if ($exists) Response::error("Table number '$num' already exists", 409);

    Database::execute(
        "INSERT INTO tables (table_number, capacity, section, pos_x, pos_y, shape)
         VALUES (?, ?, ?, ?, ?, ?)",
        [
            $num,
            (int)($body['capacity'] ?? 4),
            $body['section'] ?? 'Main',
            (float)($body['pos_x'] ?? 0),
            (float)($body['pos_y'] ?? 0),
            $body['shape'] ?? 'square',
        ]
    );

    $newId = Database::lastInsertId();
    $table = Database::queryOne('SELECT * FROM tables WHERE id = ?', [$newId]);

    Response::success($table, 'Table created', 201);
}

// ── PUT — Update table ───────────────────────────────────────
function updateTable(?int $id): void {
    if (!$id) Response::error('Table ID required', 422);

    $table = Database::queryOne('SELECT * FROM tables WHERE id = ?', [$id]);
    if (!$table) Response::error('Table not found', 404);

    $body = Response::getBody();
    $sets = [];
    $params = [];

    // Which fields are allowed to be updated
    $allowed = ['table_number', 'capacity', 'section', 'status', 'pos_x', 'pos_y', 'shape', 'qr_code'];

    foreach ($allowed as $field) {
        if (array_key_exists($field, $body)) {
            $sets[]   = "`$field` = ?";
            $params[] = $body[$field];
        }
    }

    // Protect: can't set to 'free' if there's an open order
    if (($body['status'] ?? '') === 'free') {
        $openOrder = Database::queryOne(
            "SELECT id FROM orders WHERE table_id = ? AND status NOT IN ('billed','cancelled')",
            [$id]
        );
        if ($openOrder) {
            Response::error('Cannot mark table as free — it has an open order.', 409);
        }
    }

    // Non-admins can only update status
    if (!Auth::hasRole('admin')) {
        $sets   = [];
        $params = [];
        if (isset($body['status'])) {
            $sets[]   = "`status` = ?";
            $params[] = $body['status'];
        }
    }

    if (empty($sets)) Response::error('No valid fields to update', 422);

    $params[] = $id;
    Database::execute("UPDATE tables SET " . implode(', ', $sets) . " WHERE id = ?", $params);

    $updated = Database::queryOne('SELECT * FROM tables WHERE id = ?', [$id]);
    Response::success($updated, 'Table updated');
}

// ── DELETE — Remove table ────────────────────────────────────
function deleteTable(?int $id): void {
    Auth::requireRole('admin');
    if (!$id) Response::error('Table ID required', 422);

    $table = Database::queryOne('SELECT * FROM tables WHERE id = ?', [$id]);
    if (!$table) Response::error('Table not found', 404);

    // Block deletion if there are any orders (historical record)
    $orderCount = Database::queryOne(
        'SELECT COUNT(*) AS cnt FROM orders WHERE table_id = ?',
        [$id]
    );
    if ($orderCount['cnt'] > 0) {
        Response::error('Cannot delete — table has order history. Deactivate instead.', 409);
    }

    Database::execute('DELETE FROM tables WHERE id = ?', [$id]);
    Response::success(null, "Table {$table['table_number']} deleted");
}
