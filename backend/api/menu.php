<?php
require_once __DIR__ . '/../config/bootstrap.php';
// ============================================================
// CaféOS — Menu API
// File: backend/api/menu.php
// ============================================================
// Endpoints:
//   GET    /api/menu.php                    → Full menu (all active items)
//   GET    /api/menu.php?category_id=1      → Items in category
//   GET    /api/menu.php?id=1               → Single item
//   POST   /api/menu.php                    → Create item (admin)
//   PUT    /api/menu.php?id=1               → Update item (admin)
//   DELETE /api/menu.php?id=1               → Delete item (admin)
//
//   GET    /api/menu.php?resource=categories → All categories
//   POST   /api/menu.php?resource=categories → Create category (admin)
//   PUT    /api/menu.php?resource=categories&id=1 → Update category (admin)
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../middleware/auth.php';

Response::cors();

$method     = $_SERVER['REQUEST_METHOD'];
$itemId     = isset($_GET['id'])          ? (int)$_GET['id']          : null;
$resource   = $_GET['resource']           ?? 'items';
$categoryId = isset($_GET['category_id']) ? (int)$_GET['category_id'] : null;

// Route to categories or items sub-resource
if ($resource === 'categories') {
    match($method) {
        'GET'  => listCategories(),
        'POST' => createCategory(),
        'PUT'  => updateCategory($itemId),
        default=> Response::error('Method not allowed', 405),
    };
} else {
    match($method) {
        'GET'    => $itemId ? getMenuItem($itemId) : listMenuItems($categoryId),
        'POST'   => createMenuItem(),
        'PUT'    => updateMenuItem($itemId),
        'DELETE' => deleteMenuItem($itemId),
        default  => Response::error('Method not allowed', 405),
    };
}

// ── GET all categories ───────────────────────────────────────
function listCategories(): void {
    $includeInactive = isset($_GET['all']) && Auth::hasRole('admin');

    $sql = "SELECT c.*, COUNT(m.id) AS item_count
            FROM categories c
            LEFT JOIN menu_items m ON m.category_id = c.id AND m.is_available = 1
            " . ($includeInactive ? '' : 'WHERE c.is_active = 1') . "
            GROUP BY c.id ORDER BY c.sort_order";

    $cats = Database::query($sql);
    foreach ($cats as &$c) $c['item_count'] = (int)$c['item_count'];
    Response::success($cats);
}

// ── POST create category ─────────────────────────────────────
function createCategory(): void {
    Auth::requireRole('admin');
    $body = Response::getBody();
    if (empty($body['name'])) Response::error('Category name required', 422);

    Database::execute(
        "INSERT INTO categories (name, icon, sort_order) VALUES (?, ?, ?)",
        [trim($body['name']), $body['icon'] ?? '🍽', (int)($body['sort_order'] ?? 99)]
    );
    $id  = Database::lastInsertId();
    $cat = Database::queryOne('SELECT * FROM categories WHERE id = ?', [$id]);
    Response::success($cat, 'Category created', 201);
}

// ── PUT update category ──────────────────────────────────────
function updateCategory(?int $id): void {
    Auth::requireRole('admin');
    if (!$id) Response::error('Category ID required', 422);

    $body   = Response::getBody();
    $sets   = [];
    $params = [];

    foreach (['name','icon','sort_order','is_active'] as $f) {
        if (array_key_exists($f, $body)) {
            $sets[]   = "`$f` = ?";
            $params[] = $body[$f];
        }
    }
    if (empty($sets)) Response::error('Nothing to update', 422);

    $params[] = $id;
    Database::execute("UPDATE categories SET " . implode(', ', $sets) . " WHERE id = ?", $params);
    Response::success(Database::queryOne('SELECT * FROM categories WHERE id = ?', [$id]), 'Category updated');
}

// ── GET full menu / filtered by category ────────────────────
function listMenuItems(?int $categoryId): void {
    $showAll = isset($_GET['all']) && Auth::hasRole('admin');

    $sql = "
        SELECT
            m.id, m.name, m.description, m.price, m.image_url,
            m.is_available, m.is_veg, m.prep_time_min, m.sort_order,
            c.id AS category_id, c.name AS category_name, c.icon AS category_icon
        FROM menu_items m
        JOIN categories c ON c.id = m.category_id
        WHERE c.is_active = 1
    ";
    $params = [];

    if (!$showAll) {
        $sql .= " AND m.is_available = 1";
    }
    if ($categoryId) {
        $sql .= " AND m.category_id = ?";
        $params[] = $categoryId;
    }
    $sql .= " ORDER BY c.sort_order, m.sort_order, m.name";

    $items = Database::query($sql, $params);
    foreach ($items as &$item) {
        $item['price']       = (float)$item['price'];
        $item['is_available']= (bool)$item['is_available'];
        $item['is_veg']      = (bool)$item['is_veg'];
        $item['prep_time_min'] = (int)$item['prep_time_min'];
    }

    // Structure menu by category for the POS screen
    $byCategory = [];
    foreach ($items as $item) {
        $catId = $item['category_id'];
        if (!isset($byCategory[$catId])) {
            $byCategory[$catId] = [
                'id'    => $catId,
                'name'  => $item['category_name'],
                'icon'  => $item['category_icon'],
                'items' => [],
            ];
        }
        unset($item['category_id'], $item['category_name'], $item['category_icon']);
        $byCategory[$catId]['items'][] = $item;
    }

    Response::success([
        'items'      => $items,
        'categories' => array_values($byCategory),
        'total'      => count($items),
    ]);
}

// ── GET single menu item ─────────────────────────────────────
function getMenuItem(int $id): void {
    $item = Database::queryOne(
        "SELECT m.*, c.name AS category_name FROM menu_items m
         JOIN categories c ON c.id = m.category_id WHERE m.id = ?",
        [$id]
    );
    if (!$item) Response::error('Menu item not found', 404);
    $item['price'] = (float)$item['price'];
    Response::success($item);
}

// ── POST create menu item ────────────────────────────────────
function createMenuItem(): void {
    Auth::requireRole('admin');
    $body = Response::getBody();

    // Validate required fields
    $errors = [];
    if (empty($body['name']))        $errors[] = 'name is required';
    if (empty($body['category_id'])) $errors[] = 'category_id is required';
    if (!isset($body['price']) || $body['price'] <= 0) $errors[] = 'price must be > 0';
    if ($errors) Response::error('Validation failed', 422, $errors);

    // Check category exists
    $cat = Database::queryOne('SELECT id FROM categories WHERE id = ?', [$body['category_id']]);
    if (!$cat) Response::error('Category not found', 404);

    Database::execute(
        "INSERT INTO menu_items
            (category_id, name, description, price, image_url, is_available, is_veg, prep_time_min, sort_order)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            (int)$body['category_id'],
            trim($body['name']),
            $body['description'] ?? null,
            round((float)$body['price'], 2),
            $body['image_url'] ?? null,
            isset($body['is_available']) ? (int)$body['is_available'] : 1,
            isset($body['is_veg'])       ? (int)$body['is_veg']       : 1,
            (int)($body['prep_time_min'] ?? 5),
            (int)($body['sort_order']    ?? 99),
        ]
    );

    $id   = Database::lastInsertId();
    $item = Database::queryOne('SELECT * FROM menu_items WHERE id = ?', [$id]);
    Response::success($item, 'Menu item created', 201);
}

// ── PUT update menu item ─────────────────────────────────────
function updateMenuItem(?int $id): void {
    if (!$id) Response::error('Item ID required', 422);

    $item = Database::queryOne('SELECT id FROM menu_items WHERE id = ?', [$id]);
    if (!$item) Response::error('Menu item not found', 404);

    // Waiters/cashiers can ONLY toggle availability (86'd items)
    if (!Auth::hasRole('admin')) {
        if (!array_key_exists('is_available', Response::getBody())) {
            Response::error('Only admins can edit menu items', 403);
        }
        $body = Response::getBody();
        Database::execute(
            'UPDATE menu_items SET is_available = ? WHERE id = ?',
            [(int)$body['is_available'], $id]
        );
        Response::success(['is_available' => (bool)$body['is_available']], 'Availability updated');
    }

    // Admin can update any field
    $body   = Response::getBody();
    $sets   = [];
    $params = [];
    $allowed = ['category_id','name','description','price','image_url','is_available','is_veg','prep_time_min','sort_order'];

    foreach ($allowed as $f) {
        if (array_key_exists($f, $body)) {
            $sets[]   = "`$f` = ?";
            $params[] = $body[$f];
        }
    }
    if (empty($sets)) Response::error('Nothing to update', 422);

    $params[] = $id;
    Database::execute("UPDATE menu_items SET " . implode(', ', $sets) . " WHERE id = ?", $params);
    Response::success(Database::queryOne('SELECT * FROM menu_items WHERE id = ?', [$id]), 'Item updated');
}

// ── DELETE menu item ─────────────────────────────────────────
function deleteMenuItem(?int $id): void {
    Auth::requireRole('admin');
    if (!$id) Response::error('Item ID required', 422);

    $item = Database::queryOne('SELECT * FROM menu_items WHERE id = ?', [$id]);
    if (!$item) Response::error('Item not found', 404);

    // Soft-check: if it's ever been ordered, just deactivate
    $used = Database::queryOne(
        'SELECT id FROM order_items WHERE menu_item_id = ? LIMIT 1',
        [$id]
    );
    if ($used) {
        Database::execute('UPDATE menu_items SET is_available = 0 WHERE id = ?', [$id]);
        Response::success(null, "Item deactivated (it has order history and cannot be fully deleted)");
    }

    Database::execute('DELETE FROM menu_items WHERE id = ?', [$id]);
    Response::success(null, "Menu item '{$item['name']}' deleted");
}
