<?php
// ============================================================
// CaféOS — Auth Middleware
// File: backend/middleware/auth.php
// ============================================================
// Include this at the top of any protected API endpoint:
//   require_once __DIR__ . '/../middleware/auth.php';
//
// It will set $currentUser = ['id', 'name', 'role'] or
// terminate with 401 Unauthorized.
//
// For role-specific checks:
//   Auth::requireRole('admin');
//   Auth::requireRole(['admin','cashier']);
// ============================================================

require_once __DIR__ . '/../helpers/jwt.php';
require_once __DIR__ . '/../helpers/response.php';

class Auth {

    /** @var array|null $currentUser Populated after requireAuth() */
    public static ?array $currentUser = null;

    /**
     * Verify JWT token and populate $currentUser.
     * Terminates with 401 if token is missing or invalid.
     */
    public static function requireAuth(): array {
        $token = JWT::getBearerToken();

        if (!$token) {
            Response::error('Authentication required. Please log in.', 401);
        }

        $result = JWT::decode($token);

        if (!$result['valid']) {
            Response::error('Session expired or invalid. Please log in again.', 401);
        }

        self::$currentUser = $result['payload'];
        return self::$currentUser;
    }

    /**
     * Require a specific role (or one of multiple roles).
     *
     * @param string|array $roles  e.g. 'admin' or ['admin','cashier']
     */
    public static function requireRole(string|array $roles): void {
        $user = self::$currentUser ?? self::requireAuth();
        $allowed = is_array($roles) ? $roles : [$roles];

        if (!in_array($user['role'], $allowed, true)) {
            Response::error(
                sprintf('Access denied. Required role: %s', implode(' or ', $allowed)),
                403
            );
        }
    }

    /**
     * Check if current user has a role (non-terminating).
     */
    public static function hasRole(string $role): bool {
        return (self::$currentUser['role'] ?? '') === $role;
    }

    /**
     * Get current user's ID.
     */
    public static function userId(): int {
        return (int)(self::$currentUser['id'] ?? 0);
    }
}

// Auto-run auth check when this file is included
// (sets $currentUser global for convenience)
$currentUser = Auth::requireAuth();
