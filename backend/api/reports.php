<?php
require_once __DIR__ . '/../config/bootstrap.php';
// ============================================================
// CaféOS — Reports API
// File: backend/api/reports.php
// ============================================================
// All reports require admin or cashier role.
//
// Endpoints:
//   GET /api/reports.php?type=daily        → Daily sales summary
//   GET /api/reports.php?type=hourly       → Today's sales by hour
//   GET /api/reports.php?type=items        → Top selling items
//   GET /api/reports.php?type=categories   → Sales by category
//   GET /api/reports.php?type=payments     → Payment method breakdown
//   GET /api/reports.php?type=staff        → Staff performance
//   GET /api/reports.php?type=weekly       → Last 7 days trend
//   GET /api/reports.php?type=monthly      → Last 30 days trend
//   GET /api/reports.php?type=dashboard    → KPI cards (today snapshot)
//
// Optional query params: date_from, date_to, limit (for items)
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../middleware/auth.php';

Response::cors();
Auth::requireRole(['admin', 'cashier']);

$type     = $_GET['type']      ?? 'dashboard';
$dateFrom = $_GET['date_from'] ?? date('Y-m-d');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');
$limit    = (int)($_GET['limit'] ?? 10);

match ($type) {
    'dashboard'  => reportDashboard(),
    'daily'      => reportDaily($dateFrom, $dateTo),
    'hourly'     => reportHourly($dateFrom),
    'items'      => reportTopItems($dateFrom, $dateTo, $limit),
    'categories' => reportByCategory($dateFrom, $dateTo),
    'payments'   => reportPayments($dateFrom, $dateTo),
    'staff'      => reportStaff($dateFrom, $dateTo),
    'weekly'     => reportTrend(7),
    'monthly'    => reportTrend(30),
    default      => Response::error("Unknown report type: $type", 400),
};

// ── Dashboard KPIs (today snapshot) ──────────────────────────
function reportDashboard(): void {
    $today = date('Y-m-d');

    // Today's revenue
    $revenue = Database::queryOne(
        "SELECT
            COUNT(*)       AS total_bills,
            COALESCE(SUM(grand_total), 0)     AS revenue,
            COALESCE(AVG(grand_total), 0)     AS avg_bill,
            COALESCE(SUM(discount_amount), 0) AS total_discounts
         FROM bills
         WHERE DATE(created_at) = ? AND payment_status = 'paid'",
        [$today]
    );

    // Open orders right now
    $openOrders = Database::queryOne(
        "SELECT COUNT(*) AS count FROM orders WHERE status NOT IN ('billed','cancelled') AND DATE(created_at) = ?",
        [$today]
    );

    // Occupied tables right now
    $tables = Database::queryOne(
        "SELECT
            COUNT(*) AS total,
            SUM(status = 'occupied') AS occupied,
            SUM(status = 'free') AS free
         FROM tables"
    );

    // Top item today
    $topItem = Database::queryOne(
        "SELECT oi.item_name, SUM(oi.quantity) AS qty_sold
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE DATE(o.created_at) = ? AND oi.status != 'voided' AND o.status = 'billed'
         GROUP BY oi.item_name ORDER BY qty_sold DESC LIMIT 1",
        [$today]
    );

    // Last 7 days sparkline
    $sparkline = Database::query(
        "SELECT DATE(created_at) AS day, COALESCE(SUM(grand_total),0) AS revenue
         FROM bills
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND payment_status = 'paid'
         GROUP BY day ORDER BY day ASC"
    );

    Response::success([
        'date'        => $today,
        'revenue'     => round((float)$revenue['revenue'], 2),
        'total_bills' => (int)$revenue['total_bills'],
        'avg_bill'    => round((float)$revenue['avg_bill'], 2),
        'discounts'   => round((float)$revenue['total_discounts'], 2),
        'open_orders' => (int)$openOrders['count'],
        'tables'      => [
            'total'    => (int)$tables['total'],
            'occupied' => (int)$tables['occupied'],
            'free'     => (int)$tables['free'],
            'rate'     => $tables['total'] > 0 ? round(($tables['occupied'] / $tables['total']) * 100) : 0,
        ],
        'top_item_today' => $topItem ?: null,
        'sparkline'      => array_map(fn($r) => [
            'day'     => $r['day'],
            'revenue' => (float)$r['revenue'],
        ], $sparkline),
    ]);
}

// ── Daily Sales Summary ───────────────────────────────────────
function reportDaily(string $from, string $to): void {
    $rows = Database::query(
        "SELECT
            DATE(b.created_at)           AS date,
            COUNT(DISTINCT b.id)         AS bills,
            COUNT(DISTINCT o.id)         AS orders,
            COALESCE(SUM(b.subtotal), 0) AS subtotal,
            COALESCE(SUM(b.discount_amount), 0) AS discounts,
            COALESCE(SUM(b.tax_amount), 0)      AS tax,
            COALESCE(SUM(b.grand_total), 0)     AS revenue,
            COALESCE(AVG(b.grand_total), 0)     AS avg_bill
         FROM bills b
         JOIN orders o ON o.id = b.order_id
         WHERE DATE(b.created_at) BETWEEN ? AND ?
           AND b.payment_status = 'paid'
         GROUP BY DATE(b.created_at)
         ORDER BY date DESC",
        [$from, $to]
    );

    $totals = [
        'bills'     => 0, 'revenue' => 0,
        'discounts' => 0, 'tax'     => 0,
    ];
    foreach ($rows as &$r) {
        $r['bills']     = (int)$r['bills'];
        $r['revenue']   = round((float)$r['revenue'], 2);
        $r['discounts'] = round((float)$r['discounts'], 2);
        $r['tax']       = round((float)$r['tax'], 2);
        $r['avg_bill']  = round((float)$r['avg_bill'], 2);
        $totals['bills']     += $r['bills'];
        $totals['revenue']   += $r['revenue'];
        $totals['discounts'] += $r['discounts'];
        $totals['tax']       += $r['tax'];
    }

    Response::success(['rows' => $rows, 'totals' => $totals, 'from' => $from, 'to' => $to]);
}

// ── Hourly Breakdown ──────────────────────────────────────────
function reportHourly(string $date): void {
    $rows = Database::query(
        "SELECT
            HOUR(b.created_at) AS hour,
            COUNT(*)           AS bills,
            COALESCE(SUM(grand_total), 0) AS revenue
         FROM bills b
         WHERE DATE(b.created_at) = ? AND payment_status = 'paid'
         GROUP BY HOUR(b.created_at)
         ORDER BY hour ASC",
        [$date]
    );

    // Fill in missing hours with zeros for the chart
    $hourly = [];
    for ($h = 6; $h <= 23; $h++) {
        $found = array_values(array_filter($rows, fn($r) => (int)$r['hour'] === $h));
        $hourly[] = [
            'hour'    => $h,
            'label'   => sprintf('%02d:00', $h),
            'bills'   => $found ? (int)$found[0]['bills']  : 0,
            'revenue' => $found ? (float)$found[0]['revenue'] : 0,
        ];
    }

    Response::success(['date' => $date, 'hourly' => $hourly]);
}

// ── Top Selling Items ─────────────────────────────────────────
function reportTopItems(string $from, string $to, int $limit): void {
    $rows = Database::query(
        "SELECT
            oi.item_name,
            c.name  AS category,
            SUM(oi.quantity)  AS qty_sold,
            SUM(oi.subtotal)  AS revenue,
            COUNT(DISTINCT o.id) AS order_count,
            AVG(oi.unit_price)   AS avg_price
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         LEFT JOIN menu_items m ON m.id = oi.menu_item_id
         LEFT JOIN categories c ON c.id = m.category_id
         WHERE DATE(o.created_at) BETWEEN ? AND ?
           AND oi.status != 'voided'
           AND o.status = 'billed'
         GROUP BY oi.item_name, c.name
         ORDER BY revenue DESC
         LIMIT ?",
        [$from, $to, $limit]
    );

    foreach ($rows as &$r) {
        $r['qty_sold']    = (int)$r['qty_sold'];
        $r['revenue']     = round((float)$r['revenue'], 2);
        $r['order_count'] = (int)$r['order_count'];
        $r['avg_price']   = round((float)$r['avg_price'], 2);
    }

    Response::success(['from' => $from, 'to' => $to, 'items' => $rows]);
}

// ── Sales by Category ─────────────────────────────────────────
function reportByCategory(string $from, string $to): void {
    $rows = Database::query(
        "SELECT
            COALESCE(c.name, 'Unknown') AS category,
            c.icon,
            SUM(oi.quantity)  AS qty_sold,
            SUM(oi.subtotal)  AS revenue
         FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         LEFT JOIN menu_items m ON m.id = oi.menu_item_id
         LEFT JOIN categories c ON c.id = m.category_id
         WHERE DATE(o.created_at) BETWEEN ? AND ?
           AND oi.status != 'voided'
           AND o.status = 'billed'
         GROUP BY c.id, c.name, c.icon
         ORDER BY revenue DESC",
        [$from, $to]
    );

    $totalRevenue = array_sum(array_column($rows, 'revenue'));
    foreach ($rows as &$r) {
        $r['qty_sold'] = (int)$r['qty_sold'];
        $r['revenue']  = round((float)$r['revenue'], 2);
        $r['percent']  = $totalRevenue > 0 ? round(($r['revenue'] / $totalRevenue) * 100, 1) : 0;
    }

    Response::success(['from' => $from, 'to' => $to, 'categories' => $rows, 'total_revenue' => round($totalRevenue, 2)]);
}

// ── Payment Methods Breakdown ────────────────────────────────
function reportPayments(string $from, string $to): void {
    $rows = Database::query(
        "SELECT
            payment_method,
            COUNT(*) AS transactions,
            SUM(grand_total) AS total
         FROM bills
         WHERE DATE(created_at) BETWEEN ? AND ?
           AND payment_status = 'paid'
         GROUP BY payment_method
         ORDER BY total DESC",
        [$from, $to]
    );

    $grandTotal = array_sum(array_column($rows, 'total'));
    foreach ($rows as &$r) {
        $r['transactions'] = (int)$r['transactions'];
        $r['total']        = round((float)$r['total'], 2);
        $r['percent']      = $grandTotal > 0 ? round(($r['total'] / $grandTotal) * 100, 1) : 0;
    }

    Response::success(['from' => $from, 'to' => $to, 'payments' => $rows, 'grand_total' => round($grandTotal, 2)]);
}

// ── Staff Performance ─────────────────────────────────────────
function reportStaff(string $from, string $to): void {
    Auth::requireRole('admin');  // Only admins see staff performance

    $rows = Database::query(
        "SELECT
            s.id, s.name, s.role,
            COUNT(DISTINCT o.id)  AS orders_handled,
            COUNT(DISTINCT b.id)  AS bills_generated,
            COALESCE(SUM(b.grand_total), 0) AS revenue_handled
         FROM staff s
         LEFT JOIN orders o ON o.staff_id = s.id AND DATE(o.created_at) BETWEEN ? AND ?
         LEFT JOIN bills  b ON b.staff_id = s.id AND DATE(b.created_at) BETWEEN ? AND ?
                            AND b.payment_status = 'paid'
         WHERE s.is_active = 1
         GROUP BY s.id, s.name, s.role
         ORDER BY revenue_handled DESC",
        [$from, $to, $from, $to]
    );

    foreach ($rows as &$r) {
        $r['orders_handled']  = (int)$r['orders_handled'];
        $r['bills_generated'] = (int)$r['bills_generated'];
        $r['revenue_handled'] = round((float)$r['revenue_handled'], 2);
    }

    Response::success(['from' => $from, 'to' => $to, 'staff' => $rows]);
}

// ── Trend (weekly / monthly) ──────────────────────────────────
function reportTrend(int $days): void {
    $rows = Database::query(
        "SELECT
            DATE(created_at) AS date,
            COUNT(*)         AS bills,
            SUM(grand_total) AS revenue
         FROM bills
         WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
           AND payment_status = 'paid'
         GROUP BY DATE(created_at)
         ORDER BY date ASC",
        [$days - 1]
    );

    foreach ($rows as &$r) {
        $r['bills']   = (int)$r['bills'];
        $r['revenue'] = round((float)$r['revenue'], 2);
    }

    Response::success(['days' => $days, 'trend' => $rows]);
}
