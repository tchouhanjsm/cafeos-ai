<?php
require_once __DIR__ . '/../config/bootstrap.php';
// ============================================================
// CaféOS — Auth API
// File: backend/api/auth.php
// ============================================================
// Endpoints:
//   POST /api/auth.php          → Login (returns JWT)
//   DELETE /api/auth.php        → Logout (client-side token drop)
//   GET  /api/auth.php          → Get current user info (requires auth)
//   PUT  /api/auth.php          → Change PIN (requires auth)
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../helpers/jwt.php';
require_once __DIR__ . '/../helpers/response.php';

Response::cors();

$method = $_SERVER['REQUEST_METHOD'];

// ── Route to handler ─────────────────────────────────────────
match ($method) {
    'POST'   => handleLogin(),
    'GET'    => handleMe(),
    'PUT'    => handleChangePin(),
    'DELETE' => handleLogout(),
    default  => Response::error('Method not allowed', 405),
};

// ── POST /api/auth.php — Login ───────────────────────────────
function handleLogin(): void {
    $body = Response::getBody();

    $pin   = trim($body['pin']   ?? '');
    $email = trim($body['email'] ?? '');

    // Need at least one identifier
    if (empty($pin)) {
        Response::error('PIN is required', 422);
    }

    // Look up staff by email or fetch all actives (for PIN-only login)
    if (!empty($email)) {
        $staff = Database::queryOne(
            'SELECT * FROM staff WHERE email = ? AND is_active = 1',
            [$email]
        );
    } else {
        // PIN-only quick login: try matching against all active staff
        // This is the fast cashier/waiter mode (touch the PIN pad)
        $allStaff = Database::query(
            'SELECT * FROM staff WHERE is_active = 1'
        );
        $staff = null;
        foreach ($allStaff as $s) {
            if (password_verify($pin, $s['pin_code'])) {
                $staff = $s;
                break;
            }
        }
    }

    if (!$staff) {
        // Generic message — don't leak which field was wrong
        Response::error('Invalid credentials. Please try again.', 401);
    }

    // Verify PIN
    if (!empty($email) && !password_verify($pin, $staff['pin_code'])) {
        Response::error('Invalid credentials. Please try again.', 401);
    }

    // Build token payload
    $payload = [
        'id'    => $staff['id'],
        'name'  => $staff['name'],
        'email' => $staff['email'],
        'role'  => $staff['role'],
    ];

    $token = JWT::encode($payload);

    // Log the login
    Database::execute(
        'INSERT INTO activity_log (staff_id, action, details, ip_address) VALUES (?, ?, ?, ?)',
        [
            $staff['id'],
            'login',
            json_encode(['role' => $staff['role']]),
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]
    );

    Response::success([
        'token'      => $token,
        'expires_in' => JWT_EXPIRY,
        'staff'      => [
            'id'    => $staff['id'],
            'name'  => $staff['name'],
            'email' => $staff['email'],
            'role'  => $staff['role'],
        ],
    ], 'Login successful');
}

// ── GET /api/auth.php — Get current user ────────────────────
function handleMe(): void {
    // Manually verify (not using middleware file to keep this self-contained)
    $token = JWT::getBearerToken();
    if (!$token) Response::error('Not authenticated', 401);

    $result = JWT::decode($token);
    if (!$result['valid']) Response::error($result['error'], 401);

    $user = $result['payload'];

    // Fetch fresh data from DB
    $staff = Database::queryOne(
        'SELECT id, name, email, role, is_active FROM staff WHERE id = ?',
        [$user['id']]
    );

    if (!$staff || !$staff['is_active']) {
        Response::error('Account not found or deactivated', 401);
    }

    Response::success($staff);
}

// ── PUT /api/auth.php — Change PIN ──────────────────────────
function handleChangePin(): void {
    $token = JWT::getBearerToken();
    if (!$token) Response::error('Not authenticated', 401);

    $result = JWT::decode($token);
    if (!$result['valid']) Response::error($result['error'], 401);

    $staffId = $result['payload']['id'];
    $body    = Response::getBody();

    $oldPin = $body['old_pin'] ?? '';
    $newPin = $body['new_pin'] ?? '';

    // Validate new PIN: 4–6 digits
    if (!preg_match('/^\d{4,6}$/', $newPin)) {
        Response::error('New PIN must be 4–6 digits', 422);
    }

    $staff = Database::queryOne(
        'SELECT * FROM staff WHERE id = ?',
        [$staffId]
    );

    if (!$staff || !password_verify($oldPin, $staff['pin_code'])) {
        Response::error('Current PIN is incorrect', 401);
    }

    $newHash = password_hash($newPin, PASSWORD_BCRYPT);
    Database::execute(
        'UPDATE staff SET pin_code = ? WHERE id = ?',
        [$newHash, $staffId]
    );

    Response::success(null, 'PIN changed successfully');
}

// ── DELETE /api/auth.php — Logout ───────────────────────────
function handleLogout(): void {
    // JWT is stateless — actual invalidation happens on client side.
    // We just log the event here.
    $token = JWT::getBearerToken();
    if ($token) {
        $result = JWT::decode($token);
        if ($result['valid']) {
            Database::execute(
                'INSERT INTO activity_log (staff_id, action, ip_address) VALUES (?, ?, ?)',
                [
                    $result['payload']['id'],
                    'logout',
                    $_SERVER['REMOTE_ADDR'] ?? null,
                ]
            );
        }
    }

    Response::success(null, 'Logged out successfully');
}
