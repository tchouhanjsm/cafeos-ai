<?php
// ============================================================
// CaféOS — JWT Helper (No external library needed)
// File: backend/helpers/jwt.php
// ============================================================
// Pure PHP JWT implementation using HMAC-SHA256.
// Handles token creation, validation, and expiry checks.
// ============================================================

require_once __DIR__ . '/../config/settings.php';

class JWT {

    /**
     * Create a signed JWT token.
     *
     * @param array $payload  Data to embed (staff_id, role, name, etc.)
     * @return string         Signed JWT token string
     */
    public static function encode(array $payload): string {
        // Standard header
        $header = self::base64url(json_encode([
            'alg' => JWT_ALGORITHM,
            'typ' => 'JWT'
        ]));

        // Add standard time claims
        $payload['iat'] = time();               // Issued at
        $payload['exp'] = time() + JWT_EXPIRY;  // Expiry

        $encodedPayload = self::base64url(json_encode($payload));

        // Sign: HMAC-SHA256 over "header.payload"
        $signature = self::base64url(
            hash_hmac('sha256', "$header.$encodedPayload", JWT_SECRET, true)
        );

        return "$header.$encodedPayload.$signature";
    }

    /**
     * Decode and verify a JWT token.
     *
     * @param string $token  The JWT string
     * @return array         ['valid' => bool, 'payload' => array|null, 'error' => string|null]
     */
    public static function decode(string $token): array {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return ['valid' => false, 'payload' => null, 'error' => 'Malformed token'];
        }

        [$header, $payload, $signature] = $parts;

        // Re-compute expected signature
        $expectedSig = self::base64url(
            hash_hmac('sha256', "$header.$payload", JWT_SECRET, true)
        );

        // Constant-time comparison to prevent timing attacks
        if (!hash_equals($expectedSig, $signature)) {
            return ['valid' => false, 'payload' => null, 'error' => 'Invalid signature'];
        }

        $decodedPayload = json_decode(self::base64urlDecode($payload), true);

        if (!$decodedPayload) {
            return ['valid' => false, 'payload' => null, 'error' => 'Invalid payload'];
        }

        // Check expiry
        if (isset($decodedPayload['exp']) && $decodedPayload['exp'] < time()) {
            return ['valid' => false, 'payload' => null, 'error' => 'Token expired'];
        }

        return ['valid' => true, 'payload' => $decodedPayload, 'error' => null];
    }

    /**
     * Extract token from "Authorization: Bearer <token>" header.
     */
    public static function getBearerToken(): ?string {
        $headers = apache_request_headers();

        // Try Authorization header
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

        if ($authHeader && preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        // Fallback: check query string (for EventSource / WebSocket connections)
        return $_GET['token'] ?? null;
    }

    // ── Private helpers ──────────────────────────────────────

    private static function base64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64urlDecode(string $data): string {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
