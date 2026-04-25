<?php
require_once __DIR__ . '/../config/bootstrap.php';
// ============================================================
// CaféOS — Billing API
// File: backend/api/billing.php
// ============================================================
// Endpoints:
//   GET  /api/billing.php?order_id=1  → Preview bill for order
//   POST /api/billing.php             → Generate & finalize bill
//   PUT  /api/billing.php?id=1        → Record payment (mark as paid)
//   GET  /api/billing.php?id=1        → Get bill details
//   GET  /api/billing.php?date=today  → List bills for date
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/response.php';
require_once __DIR__ . '/../middleware/auth.php';

Response::cors();

$method   = $_SERVER['REQUEST_METHOD'];
$billId   = isset($_GET['id'])       ? (int)$_GET['id']       : null;
$orderId  = isset($_GET['order_id']) ? (int)$_GET['order_id'] : null;
$date     = $_GET['date']            ?? null;

match (true) {
    $method === 'GET'  && $billId               => getBill($billId),
    $method === 'GET'  && $orderId              => previewBill($orderId),
    $method === 'GET'  && !$billId && !$orderId => listBills($date),
    $method === 'POST'                          => generateBill(),
    $method === 'PUT'  && $billId               => recordPayment($billId),
    default => Response::error('Invalid request', 400),
};

// ── GET preview bill (no DB write) ───────────────────────────
function previewBill(int $orderId): void {
    $order = getActiveOrder($orderId);
    $calc  = calculateBill($order['items'], $_GET['discount_type'] ?? 'none', (float)($_GET['discount_value'] ?? 0));
    Response::success(array_merge($order, $calc));
}

// ── GET single bill ──────────────────────────────────────────
function getBill(int $id): void {
    $bill = Database::queryOne(
        "SELECT b.*, o.order_number, o.order_type,
                t.table_number, s.name AS cashier_name
         FROM bills b
         JOIN orders o ON o.id = b.order_id
         LEFT JOIN tables t ON t.id = o.table_id
         LEFT JOIN staff  s ON s.id = b.staff_id
         WHERE b.id = ?",
        [$id]
    );
    if (!$bill) Response::error('Bill not found', 404);

    // Fetch order items
    $items = Database::query(
        "SELECT item_name, unit_price, quantity, subtotal, notes, status
         FROM order_items WHERE order_id = ? AND status != 'voided'",
        [$bill['order_id']]
    );
    $bill['items'] = $items;
    $bill = castBillNumbers($bill);

    Response::success($bill);
}

// ── GET list bills ───────────────────────────────────────────
function listBills(?string $date): void {
    Auth::requireRole(['admin', 'cashier']);

    $targetDate = ($date === null || $date === 'today') ? date('Y-m-d') : $date;

    $bills = Database::query(
        "SELECT b.id, b.bill_number, b.grand_total, b.payment_method, b.payment_status,
                b.created_at, o.order_number, o.order_type, t.table_number, s.name AS cashier
         FROM bills b
         JOIN orders o ON o.id = b.order_id
         LEFT JOIN tables t ON t.id = o.table_id
         LEFT JOIN staff  s ON s.id = b.staff_id
         WHERE DATE(b.created_at) = ?
         ORDER BY b.created_at DESC",
        [$targetDate]
    );

    $totals = [
        'bills'    => count($bills),
        'revenue'  => 0,
        'cash'     => 0,
        'card'     => 0,
        'upi'      => 0,
        'split'    => 0,
    ];
    foreach ($bills as &$b) {
        $b['grand_total'] = (float)$b['grand_total'];
        if ($b['payment_status'] === 'paid') {
            $totals['revenue'] += $b['grand_total'];
            $totals[$b['payment_method']] = ($totals[$b['payment_method']] ?? 0) + $b['grand_total'];
        }
    }

    Response::success(['bills' => $bills, 'date' => $targetDate, 'totals' => $totals]);
}

// ── POST generate bill ────────────────────────────────────────
function generateBill(): void {
    $body    = Response::getBody();
    $orderId = (int)($body['order_id'] ?? 0);

    if (!$orderId) Response::error('order_id required', 422);

    // Check no existing bill
    $existing = Database::queryOne('SELECT id FROM bills WHERE order_id = ?', [$orderId]);
    if ($existing) Response::error("Bill already exists for this order (Bill #{$existing['id']})", 409);

    $order  = getActiveOrder($orderId);
    $items  = $order['items'];

    if (empty($items)) Response::error('Cannot bill an order with no items', 422);

    $discountType  = $body['discount_type']  ?? 'none';
    $discountValue = (float)($body['discount_value'] ?? 0);

    // Enforce max discount rule (non-admins capped)
    if ($discountType === 'percent' && !Auth::hasRole('admin')) {
        if ($discountValue > MAX_DISCOUNT_PERCENT) {
            Response::error("Discount above " . MAX_DISCOUNT_PERCENT . "% requires admin approval", 403);
        }
    }

    $calc = calculateBill($items, $discountType, $discountValue);

    // Generate bill number
    $billNumber = generateBillNumber();

    Database::beginTransaction();
    try {
        Database::execute(
            "INSERT INTO bills
                (bill_number, order_id, staff_id, subtotal, discount_type, discount_value,
                 discount_amount, tax_rate, tax_amount, grand_total, payment_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')",
            [
                $billNumber,
                $orderId,
                Auth::userId(),
                $calc['subtotal'],
                $discountType,
                $discountValue,
                $calc['discount_amount'],
                $calc['tax_rate'],
                $calc['tax_amount'],
                $calc['grand_total'],
            ]
        );
        $billId = (int)Database::lastInsertId();

        // Mark order as billed
        Database::execute("UPDATE orders SET status = 'billed' WHERE id = ?", [$orderId]);

        Database::commit();
    } catch (Exception $e) {
        Database::rollback();
        Response::log('Generate bill failed: ' . $e->getMessage(), 'ERROR');
        Response::error('Failed to generate bill. Please try again.', 500);
    }

    $bill = getBillData($billId);
    Response::success($bill, "Bill $billNumber generated", 201);
}

// ── PUT record payment ────────────────────────────────────────
function recordPayment(int $billId): void {
    $bill = Database::queryOne('SELECT * FROM bills WHERE id = ?', [$billId]);
    if (!$bill) Response::error('Bill not found', 404);
    if ($bill['payment_status'] === 'paid') Response::error('Bill already paid', 409);

    $body           = Response::getBody();
    $paymentMethod  = $body['payment_method'] ?? 'cash';
    $amountTendered = isset($body['amount_tendered']) ? (float)$body['amount_tendered'] : null;

    $validMethods = ['cash','card','upi','split','complimentary'];
    if (!in_array($paymentMethod, $validMethods)) {
        Response::error('Invalid payment method. Use: ' . implode(', ', $validMethods), 422);
    }

    $grandTotal = (float)$bill['grand_total'];

    // For cash: validate tendered amount
    if ($paymentMethod === 'cash') {
        if ($amountTendered === null) Response::error('amount_tendered required for cash payments', 422);
        if ($amountTendered < $grandTotal) {
            Response::error(sprintf('Tendered amount ₹%.2f is less than total ₹%.2f', $amountTendered, $grandTotal), 422);
        }
    }

    $changeDue = ($paymentMethod === 'cash' && $amountTendered !== null)
        ? round($amountTendered - $grandTotal, 2)
        : null;

    Database::beginTransaction();
    try {
        Database::execute(
            "UPDATE bills SET
                payment_method  = ?,
                payment_status  = 'paid',
                amount_tendered = ?,
                change_due      = ?,
                notes           = ?,
                updated_at      = NOW()
             WHERE id = ?",
            [
                $paymentMethod,
                $amountTendered,
                $changeDue,
                $body['notes'] ?? null,
                $billId,
            ]
        );

        // Free up the table
        $order = Database::queryOne('SELECT table_id FROM orders WHERE id = ?', [$bill['order_id']]);
        if ($order && $order['table_id']) {
            Database::execute(
                "UPDATE tables SET status = 'free' WHERE id = ?",
                [$order['table_id']]
            );
        }

        Database::commit();
    } catch (Exception $e) {
        Database::rollback();
        Response::error('Failed to record payment', 500);
    }

    $updated = getBillData($billId);
    Response::success(array_merge($updated, ['change_due' => $changeDue]), 'Payment recorded — table is now free');
}

// ── Helpers ──────────────────────────────────────────────────

function calculateBill(array $items, string $discountType, float $discountValue): array {
    $subtotal = array_sum(array_column($items, 'subtotal'));

    // Apply discount
    $discountAmount = match($discountType) {
        'percent' => round($subtotal * ($discountValue / 100), 2),
        'flat'    => min($discountValue, $subtotal),   // can't discount more than subtotal
        default   => 0.00,
    };

    $afterDiscount = $subtotal - $discountAmount;

    // Load tax rate from DB settings
    $setting = Database::queryOne("SELECT value FROM settings WHERE key_name = 'tax_rate'");
    $taxRate = $setting ? (float)$setting['value'] : DEFAULT_TAX_RATE;

    $taxAmount  = round($afterDiscount * ($taxRate / 100), 2);
    $grandTotal = round($afterDiscount + $taxAmount, 2);

    return [
        'subtotal'        => round($subtotal, 2),
        'discount_type'   => $discountType,
        'discount_value'  => $discountValue,
        'discount_amount' => $discountAmount,
        'tax_rate'        => $taxRate,
        'tax_amount'      => $taxAmount,
        'grand_total'     => $grandTotal,
    ];
}

function getActiveOrder(int $orderId): array {
    $order = Database::queryOne(
        "SELECT o.*, t.table_number, t.section, s.name AS staff_name
         FROM orders o
         LEFT JOIN tables t ON t.id = o.table_id
         LEFT JOIN staff  s ON s.id = o.staff_id
         WHERE o.id = ?",
        [$orderId]
    );
    if (!$order) Response::error('Order not found', 404);
    if (in_array($order['status'], ['cancelled'])) {
        Response::error("Order is {$order['status']}", 409);
    }

    $items = Database::query(
        "SELECT id, item_name, unit_price, quantity, subtotal, notes, status, menu_item_id
         FROM order_items WHERE order_id = ? AND status != 'voided'",
        [$orderId]
    );
    foreach ($items as &$i) {
        $i['unit_price'] = (float)$i['unit_price'];
        $i['subtotal']   = (float)$i['subtotal'];
        $i['quantity']   = (int)$i['quantity'];
    }

    $order['items'] = $items;
    return $order;
}

function getBillData(int $id): array {
    ob_start();
    getBill($id);
    $json = ob_get_clean();
    $data = json_decode($json, true);
    return $data['data'] ?? [];
}

function generateBillNumber(): string {
    $date   = date('Ymd');
    $prefix = "BILL-$date-";
    $count  = Database::queryOne(
        "SELECT COUNT(*) AS cnt FROM bills WHERE bill_number LIKE ?",
        ["$prefix%"]
    );
    return $prefix . str_pad((int)$count['cnt'] + 1, 4, '0', STR_PAD_LEFT);
}

function castBillNumbers(array $bill): array {
    $floats = ['subtotal','discount_value','discount_amount','tax_rate','tax_amount','grand_total','amount_tendered','change_due'];
    foreach ($floats as $f) {
        if (isset($bill[$f])) $bill[$f] = (float)$bill[$f];
    }
    return $bill;
}
