<?php
// ============================================================
// CaféOS — Database Configuration
// File: backend/config/db.php
// ============================================================
// Uses PDO with prepared statements for security.
// Implements Singleton pattern — one connection per request.
// ============================================================

class Database {
    // ── Connection settings ──────────────────────────────────
    private static string $host     = 'localhost';
    private static string $dbName   = 'cafeos';
    private static string $username = 'root';
    private static string $password = '';          // Change in production!
    private static string $charset  = 'utf8mb4';

    private static ?PDO $instance = null;

    // Prevent direct instantiation
    private function __construct() {}
    private function __clone()     {}

    /**
     * Get the single PDO instance.
     * Creates it on first call, reuses it on subsequent calls.
     */
    public static function getInstance(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                self::$host,
                self::$dbName,
                self::$charset
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // Throw on errors
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // Return arrays
                PDO::ATTR_EMULATE_PREPARES   => false,                    // Real prepared statements
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+05:30'", // IST timezone
            ];

            try {
                self::$instance = new PDO($dsn, self::$username, self::$password, $options);
            } catch (PDOException $e) {
                // Discard any output that might have leaked before this point
                if (ob_get_level()) ob_clean();

                $isDev = (defined('APP_ENV') && APP_ENV === 'development');
                $hint  = $isDev ? ' (' . $e->getMessage() . ')' : '';

                error_log('[CafeOS] DB Connection Failed: ' . $e->getMessage());
                http_response_code(500);

                if (!headers_sent()) {
                    header('Content-Type: application/json; charset=utf-8');
                }

                echo json_encode([
                    'success' => false,
                    'message' => 'Database connection failed.' . $hint .
                                 ' Ensure MySQL is running and the "cafeos" database exists.',
                    'data'    => null,
                    'code'    => 500,
                ]);
                exit;
            }
        }

        return self::$instance;
    }

    /**
     * Convenience wrapper: run a SELECT query and return all rows.
     */
    public static function query(string $sql, array $params = []): array {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Convenience wrapper: run a SELECT query and return ONE row.
     */
    public static function queryOne(string $sql, array $params = []): array|false {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }

    /**
     * Convenience wrapper: run INSERT/UPDATE/DELETE.
     * Returns affected row count.
     */
    public static function execute(string $sql, array $params = []): int {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * Returns the last inserted auto-increment ID.
     */
    public static function lastInsertId(): string {
        return self::getInstance()->lastInsertId();
    }

    /**
     * Begin a transaction.
     */
    public static function beginTransaction(): void {
        self::getInstance()->beginTransaction();
    }

    /**
     * Commit a transaction.
     */
    public static function commit(): void {
        self::getInstance()->commit();
    }

    /**
     * Roll back a transaction.
     */
    public static function rollback(): void {
        self::getInstance()->rollBack();
    }
}
