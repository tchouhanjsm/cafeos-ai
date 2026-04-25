<?php
// ============================================================
// CaféOS — Response Helper & CORS
// File: backend/helpers/response.php
// ============================================================
// All API responses go through here for consistency.
// Every response is JSON with a standard envelope:
//   { success: bool, data: any, message: string, code: int }
// ============================================================

require_once __DIR__ . '/../config/settings.php';

class Response {

    /**
     * Set CORS headers. Call at the top of every API file.
     * Handles preflight OPTIONS requests automatically.
     */
    public static function cors(): void {
        header('Access-Control-Allow-Origin: '    . CORS_ORIGIN);
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        header('Content-Type: application/json; charset=utf-8');

        // Respond to preflight and exit
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }

    /**
     * Send a success response.
     *
     * @param mixed  $data    Response data (array, object, or scalar)
     * @param string $message Human-readable success message
     * @param int    $code    HTTP status code (default 200)
     */
    public static function success(mixed $data = null, string $message = 'OK', int $code = 200): void {
        http_response_code($code);
        echo json_encode([
            'success' => true,
            'data'    => $data,
            'message' => $message,
            'code'    => $code,
        ], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
        exit;
    }

    /**
     * Send an error response.
     *
     * @param string $message Human-readable error description
     * @param int    $code    HTTP status code (4xx or 5xx)
     * @param mixed  $errors  Optional detailed error info (validation errors, etc.)
     */
    public static function error(string $message, int $code = 400, mixed $errors = null): void {
        http_response_code($code);
        $body = [
            'success' => false,
            'message' => $message,
            'code'    => $code,
        ];
        if ($errors !== null) $body['errors'] = $errors;

        echo json_encode($body, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Parse and return JSON request body.
     * Returns empty array if body is empty or invalid JSON.
     */
    public static function getBody(): array {
        $raw = file_get_contents('php://input');
        if (empty($raw)) return [];

        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Get a sanitized value from the request body, GET, or POST.
     */
    public static function input(string $key, mixed $default = null): mixed {
        $body = self::getBody();
        if (array_key_exists($key, $body)) return $body[$key];
        if (isset($_GET[$key]))            return $_GET[$key];
        if (isset($_POST[$key]))           return $_POST[$key];
        return $default;
    }

    /**
     * Log to file (only if LOG_ERRORS is true in settings).
     */
    public static function log(string $message, string $level = 'INFO'): void {
        if (!LOG_ERRORS) return;
        $line = sprintf("[%s] [%s] %s\n", date('Y-m-d H:i:s'), $level, $message);
        @file_put_contents(LOG_PATH, $line, FILE_APPEND);
    }
}
