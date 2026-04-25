<?php
require_once __DIR__ . '/../config/bootstrap.php';
// ============================================================
// CaféOS — Orders API
// File: backend/api/orders.php
// ============================================================
// Endpoints:
//   GET    /api/orders.php              → List open orders
//   GET    /api/orders.php?id=1         → Get full order with items
//   POST   /api/orders.php              → Create new order
//   PUT    /api/orders.php?id=1         → Update order (status, notes, guest count)
//   POST   /api/orders.php?id=1&action=add_item    → Add item to order
//   PUT    /api/orders.php?id=1&action=update_item  → Change item qty
//   PUT    /api/orders.php?id=1&action=void_item    → Void an item
//   PUT    /api/orders.php?id=1&action=send_kitchen → Send to kitchen
//   DELETE /api/orders.php?id=1         → Cancel order (admin)
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../middleware/auth.php';

Response::cors();

$method   = $_SERVER['REQUEST_METHOD'];
$orderId  = isset($_GET['id'])     ? (int)$_GET['id']     : null;
$action   = $_GET['action']        ?? null;

match (true) {
    $method === 'GET'    && !$orderId             => listOrders(),
    $method === 'GET'    && $orderId              => getOrder($orderId),
    $method === 'POST'   && !$orderId             => createOrder(),
    $method === 'POST'   && $orderId && $action   => handleItemAction($orderId, $action),
    $method === 'PUT'    && $orderId && !$action  => updateOrder($orderId),
    $method === 'PUT'    && $orderId && $action   => handleItemAction($orderId, $action),
    $method === 'DELETE' && $orderId              => cancelOrder($orderId),
    default => Response::error('Invalid request', 400),
};

// ── GET list of orders ───────────────────────────────────────
function listOrders(): void {
    $status     = $_GET['status']   ?? null;
    $type       = $_GET['type']     ?? null;
    $date       = $_GET['date']     ?? date('Y-m-d');  // default: today

    $sql = "
        SELECT
            o.id, o.order_number, o.status, o.order_type, o.guest_count,
            o.notes, o.created_at, o.updated_at,
            t.table_number, t.section,
            s.name AS staff_name,
            COUNT(CASE WHEN oi.status != 'voided' THEN 1 END) AS item_count,
            COALESCE(SUM(CASE WHEN oi.status != 'voided' THEN oi.subtotal ELSE 0 END), 0) AS running_total
        FROM orders o
        LEFT JOIN tables      t  ON t.id = o.table_id
        LEFT JOIN staff       s  ON s.id = o.staff_id
        LEFT JOIN order_items oi ON oi.order_id = o.id
        WHERE DATE(o.created_at) = ?
    ";
    $params = [$date];

    if ($status) { $sql .= " AND o.status = ?";     $params[] = $status; }
    if ($type)   { $sql .= " AND o.order_type = ?"; $params[] = $type; }

    $sql .= " GROUP BY o.id ORDER BY o.created_at DESC";

    $orders = Database::query($sql, $params);
    foreach ($orders as &$o) {
        $o['item_count']    = (int)$o['item_count'];
        $o['running_total'] = (float)$o['running_total'];
        $o['guest_count']   = (int)$o['guest_count'];
    }
    Response::success(['orders' => $orders, 'count' => count($orders), 'date' => $date]);
}

// ── GET single order with all items ──────────────────────────
function getOrder(int $id): void {
    $order = Database::queryOne(
        "SELECT o.*, t.table_number, t.section, s.name AS staff_name
         FROM orders o
         LEFT JOIN tables t ON t.id = o.table_id
         LEFT JOIN staff  s ON s.id = o.staff_id
         WHERE o.id = ?",
        [$id]
    );
    if (!$order) Response::error('Order not found', 404);

    $items = Database::query(
        "SELECT oi.*, m.category_id, c.name AS category_name
         FROM order_items oi
         LEFT JOIN menu_items m ON m.id = oi.menu_item_id
         LEFT JOIN categories c ON c.id = m.category_id
         WHERE oi.order_id = ?
         ORDER BY oi.id",
        [$id]
    );

    foreach ($items as &$i) {
        $i['unit_price'] = (float)$i['unit_price'];
        $i['subtotal']   = (float)$i['subtotal'];
        $i['quantity']   = (int)$i['quantity'];
    }

    $activeItems = array_filter($items, fn($i) => $i['status'] !== 'voided');
    $subtotal    = array_sum(array_column(array_values($activeItems), 'subtotal'));

    $order['items']       = $items;
    $order['subtotal']    = round($subtotal, 2);
    $order['item_count']  = count($activeItems);
    $order['guest_count'] = (int)$order['guest_count'];

    Response::success($order);
}

// ── POST create order ────────────────────────────────────────
function createOrder(): void {
    $body    = Response::getBody();
    $type    = $body['order_type'] ?? 'dine_in';
    $tableId = isset($body['table_id']) ? (int)$body['table_id'] : null;

    // Validate table for dine-in
    if ($type === 'dine_in') {
        if (!$tableId) Response::error('table_id required for dine-in orders', 422);

        $table = Database::queryOne('SELECT * FROM tables WHERE id = ?', [$tableId]);
        if (!$table) Response::error('Table not found', 404);
        if ($table['status'] === 'occupied') {
            // Check if there's already an open order — if so, return that
            $existing = Database::queryOne(
                "SELECT id FROM orders WHERE table_id = ? AND status NOT IN ('billed','cancelled')",
                [$tableId]
            );
            if ($existing) Response::error(
                "Table already has an open order #{$existing['id']}. Use that order or close it first.",
                409
            );
        }
    }

    // Generate unique order number: ORD-YYYYMMDD-XXXX
    $orderNumber = generateOrderNumber();

    Database::beginTransaction();
    try {
        Database::execute(
            "INSERT INTO orders (order_number, table_id, staff_id, order_type, guest_count, notes)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $orderNumber,
                $tableId,
                Auth::userId(),
                $type,
                (int)($body['guest_count'] ?? 1),
                $body['notes'] ?? null,
            ]
        );
        $orderId = (int)Database::lastInsertId();

        // Mark table as occupied
        if ($tableId) {
            Database::execute(
                "UPDATE tables SET status = 'occupied' WHERE id = ?",
                [$tableId]
            );
        }

        // Add initial items if provided
        if (!empty($body['items']) && is_array($body['items'])) {
            foreach ($body['items'] as $item) {
                addItemToOrder($orderId, $item);
            }
        }

        Database::commit();
    } catch (Exception $e) {
        Database::rollback();
        Response::log('Create order failed: ' . $e->getMessage(), 'ERROR');
        Response::error('Failed to create order. Please try again.', 500);
    }

    $order = getOrderData($orderId);
    Response::success($order, "Order $orderNumber created", 201);
}

// ── PUT update order (status, notes, guest_count) ────────────
function updateOrder(int $id): void {
    $order = Database::queryOne('SELECT * FROM orders WHERE id = ?', [$id]);
    if (!$order) Response::error('Order not found', 404);

    if (in_array($order['status'], ['billed', 'cancelled'])) {
        Response::error("Cannot modify a {$order['status']} order", 409);
    }

    $body   = Response::getBody();
    $sets   = [];
    $params = [];

    $allowed = ['status', 'guest_count', 'notes'];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $body)) {
            $sets[]   = "`$f` = ?";
            $params[] = $body[$f];
        }
    }

    if (empty($sets)) Response::error('Nothing to update', 422);

    $params[] = $id;
    Database::execute("UPDATE orders SET " . implode(', ', $sets) . " WHERE id = ?", $params);

    Response::success(getOrderData($id), 'Order updated');
}

// ── Item actions dispatcher ───────────────────────────────────
function handleItemAction(int $orderId, string $action): void {
    $order = Database::queryOne('SELECT * FROM orders WHERE id = ?', [$orderId]);
    if (!$order) Response::error('Order not found', 404);

    match ($action) {
        'add_item'    => addItemAction($orderId, $order),
        'update_item' => updateItemAction($orderId),
        'void_item'   => voidItemAction($orderId),
        'send_kitchen'=> sendToKitchen($orderId, $order),
        default       => Response::error("Unknown action: $action", 400),
    };
}

function addItemAction(int $orderId, array $order): void {
    if (in_array($order['status'], ['billed', 'cancelled'])) {
        Response::error("Cannot add items to a {$order['status']} order", 409);
    }

    $body = Response::getBody();
    if (empty($body['menu_item_id'])) Response::error('menu_item_id required', 422);

    addItemToOrder($orderId, $body);
    Response::success(getOrderData($orderId), 'Item added');
}

function updateItemAction(int $orderId): void {
    $body   = Response::getBody();
    $itemId = $body['item_id'] ?? null;
    $qty    = (int)($body['quantity'] ?? 0);

    if (!$itemId) Response::error('item_id required', 422);
    if ($qty < 1) Response::error('quantity must be at least 1 (use void_item to remove)', 422);

    $item = Database::queryOne(
        'SELECT * FROM order_items WHERE id = ? AND order_id = ?',
        [$itemId, $orderId]
    );
    if (!$item) Response::error('Order item not found', 404);
    if ($item['status'] === 'voided') Response::error('Cannot update a voided item', 409);

    Database::execute(
        'UPDATE order_items SET quantity = ? WHERE id = ?',
        [$qty, $itemId]
    );
    Response::success(getOrderData($orderId), 'Item quantity updated');
}

function voidItemAction(int $orderId): void {
    $body   = Response::getBody();
    $itemId = $body['item_id'] ?? null;

    if (!$itemId) Response::error('item_id required', 422);

    $item = Database::queryOne(
        'SELECT * FROM order_items WHERE id = ? AND order_id = ?',
        [$itemId, $orderId]
    );
    if (!$item) Response::error('Order item not found', 404);
    if ($item['status'] === 'voided') Response::error('Item already voided', 409);

    // Cashiers/admins can void. Waiters cannot void once sent.
    if ($item['status'] !== 'pending' && !Auth::hasRole('admin') && !Auth::hasRole('cashier')) {
        Response::error('Only admin or cashier can void sent items', 403);
    }

    Database::execute(
        "UPDATE order_items SET status = 'voided', voided_by = ? WHERE id = ?",
        [Auth::userId(), $itemId]
    );

    // Log the void
    Database::execute(
        "INSERT INTO activity_log (staff_id, action, target_type, target_id, details) VALUES (?,?,?,?,?)",
        [
            Auth::userId(), 'void_item', 'order_item', $itemId,
            json_encode(['order_id' => $orderId, 'item_name' => $item['item_name']])
        ]
    );

    Response::success(getOrderData($orderId), "Item '{$item['item_name']}' voided");
}

function sendToKitchen(int $orderId, array $order): void {
    // Mark all pending items as 'cooking'
    $updated = Database::execute(
        "UPDATE order_items SET status = 'cooking' WHERE order_id = ? AND status = 'pending'",
        [$orderId]
    );

    if ($updated === 0) {
        Response::error('No pending items to send to kitchen', 409);
    }

    Database::execute(
        "UPDATE orders SET status = 'sent' WHERE id = ?",
        [$orderId]
    );

    Response::success(getOrderData($orderId), "$updated item(s) sent to kitchen");
}

// ── DELETE cancel order ──────────────────────────────────────
function cancelOrder(int $id): void {
    Auth::requireRole(['admin', 'cashier']);

    $order = Database::queryOne('SELECT * FROM orders WHERE id = ?', [$id]);
    if (!$order) Response::error('Order not found', 404);

    if ($order['status'] === 'billed') Response::error('Cannot cancel a billed order', 409);

    Database::beginTransaction();
    try {
        Database::execute("UPDATE orders SET status = 'cancelled' WHERE id = ?", [$id]);

        // Free the table
        if ($order['table_id']) {
            Database::execute("UPDATE tables SET status = 'free' WHERE id = ?", [$order['table_id']]);
        }

        Database::execute(
            "INSERT INTO activity_log (staff_id, action, target_type, target_id) VALUES (?,?,?,?)",
            [Auth::userId(), 'cancel_order', 'order', $id]
        );
        Database::commit();
    } catch (Exception $e) {
        Database::rollback();
        Response::error('Failed to cancel order', 500);
    }

    Response::success(null, "Order {$order['order_number']} cancelled");
}

// ── Helpers ──────────────────────────────────────────────────

function addItemToOrder(int $orderId, array $itemData): void {
    $menuItemId = (int)$itemData['menu_item_id'];
    $qty        = (int)($itemData['quantity'] ?? 1);

    $menuItem = Database::queryOne(
        'SELECT * FROM menu_items WHERE id = ? AND is_available = 1',
        [$menuItemId]
    );
    if (!$menuItem) throw new Exception("Menu item $menuItemId not found or unavailable");

    // Check if same item (with same notes) already in order — if so, increment qty
    $existing = Database::queryOne(
        "SELECT id, quantity FROM order_items
         WHERE order_id = ? AND menu_item_id = ? AND status = 'pending'
           AND COALESCE(notes,'') = COALESCE(?,'')
         LIMIT 1",
        [$orderId, $menuItemId, $itemData['notes'] ?? null]
    );

    if ($existing) {
        Database::execute(
            'UPDATE order_items SET quantity = quantity + ? WHERE id = ?',
            [$qty, $existing['id']]
        );
    } else {
        Database::execute(
            "INSERT INTO order_items (order_id, menu_item_id, item_name, unit_price, quantity, notes)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $orderId,
                $menuItemId,
                $menuItem['name'],
                $menuItem['price'],
                $qty,
                $itemData['notes'] ?? null,
            ]
        );
    }
}

function generateOrderNumber(): string {
    $date   = date('Ymd');
    $prefix = "ORD-$date-";

    // Get today's count for sequential numbering
    $count = Database::queryOne(
        "SELECT COUNT(*) AS cnt FROM orders WHERE order_number LIKE ?",
        ["$prefix%"]
    );
    $seq = str_pad((int)$count['cnt'] + 1, 4, '0', STR_PAD_LEFT);
    return "$prefix$seq";
}

function getOrderData(int $id): array {
    ob_start();
    getOrder($id);
    $json = ob_get_clean();
    $data = json_decode($json, true);
    return $data['data'] ?? [];
}
