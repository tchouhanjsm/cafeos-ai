<?php
// ============================================================
// CaféOS — API Bootstrap
// File: backend/config/bootstrap.php
// ============================================================
// Load this at the very top of every API file BEFORE anything
// else. It:
//   1. Kills PHP's display_errors (common XAMPP default)
//   2. Sets up a global error handler that returns JSON
//   3. Sets up an exception handler that returns JSON
//   4. Sets Content-Type header early
//
// Without this, any PHP notice/warning/error outputs HTML
// before the JSON, producing: "Unexpected token '<'..."
// ============================================================

// ── Kill PHP's native error display immediately ───────────────
// In XAMPP, php.ini has display_errors = On by default.
// Any error (even a Notice) printed before header() calls
// will corrupt the JSON response.
@ini_set('display_errors',  '0');
@ini_set('display_startup_errors', '0');
@error_reporting(E_ALL);   // Still log errors, just don't display them

// ── Log errors to a file instead ─────────────────────────────
$logDir = dirname(__DIR__) . '/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
@ini_set('log_errors',      '1');
@ini_set('error_log',       $logDir . '/php-errors.log');

// ── Set JSON Content-Type early ──────────────────────────────
// This must happen before ANY output. If something does slip
// through, the browser at least knows it's trying to be JSON.
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

// ── Global exception handler ──────────────────────────────────
// Catches any uncaught exception and returns JSON 500
set_exception_handler(function (Throwable $e) {
    // Clear any partial output that might have been sent
    if (ob_get_level()) ob_clean();

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    $isDev = (defined('APP_ENV') && APP_ENV === 'development');

    echo json_encode([
        'success' => false,
        'message' => $isDev
            ? $e->getMessage() . ' in ' . basename($e->getFile()) . ':' . $e->getLine()
            : 'Internal server error. Check logs.',
        'data'    => null,
        'code'    => 500,
    ]);

    error_log('[CaféOS] Uncaught exception: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    exit;
});

// ── Global error handler ──────────────────────────────────────
// Converts PHP errors (Warnings, Notices, etc.) to exceptions
// so they're caught above instead of leaking as HTML
set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline): bool {
    if (!(error_reporting() & $errno)) return false; // Respect @ operator

    // Only throw on serious errors; log warnings/notices
    if ($errno === E_ERROR || $errno === E_PARSE || $errno === E_CORE_ERROR) {
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

    // For warnings and notices: log them but continue
    error_log(sprintf('[CaféOS] PHP %s: %s in %s:%d',
        match ($errno) {
            E_WARNING  => 'Warning',
            E_NOTICE   => 'Notice',
            default    => "Error($errno)",
        },
        $errstr, basename($errfile), $errline
    ));

    return true; // Don't run PHP's default error handler
});

// ── Output buffering ──────────────────────────────────────────
// Catch any accidental echo/print before JSON output.
// If anything is buffered at response time, it gets discarded.
ob_start();
