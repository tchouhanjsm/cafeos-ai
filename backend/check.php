<?php
// ============================================================
// CaféOS — Diagnostic Check
// File: backend/check.php
// ============================================================
// Open http://localhost/cafeos/backend/check.php in your browser
// to see a full diagnostic of what's working and what isn't.
//
// DELETE THIS FILE before going to production!
// ============================================================

ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
<title>CaféOS Diagnostic</title>
<style>
  body { font-family: -apple-system, monospace; padding: 24px; background: #F8F4EE; max-width: 700px; margin: 0 auto; }
  h1   { color: #1A0E05; }
  .ok  { color: #2D6A4F; font-weight: bold; }
  .err { color: #C0392B; font-weight: bold; }
  .warn{ color: #E67E22; font-weight: bold; }
  .row { padding: 10px 14px; margin: 6px 0; background: white; border-radius: 6px; border-left: 4px solid #E5DDD5; }
  .row.ok   { border-left-color: #2D6A4F; }
  .row.err  { border-left-color: #C0392B; }
  .row.warn { border-left-color: #E67E22; }
  pre  { background: #EDE8DF; padding: 12px; border-radius: 6px; font-size: 12px; overflow-x: auto; }
  .fix { background: #FFF3CD; padding: 10px 14px; border-radius: 6px; margin-top: 6px; font-size: 13px; }
</style>
</head>
<body>
<h1>☕ CaféOS Diagnostic</h1>
<p style="color:#5A5249">Run this to find out why your API is returning errors.</p>

<?php
function row($label, $ok, $detail = '', $fix = '') {
    $cls = $ok === true ? 'ok' : ($ok === false ? 'err' : 'warn');
    $ico = $ok === true ? '✅' : ($ok === false ? '❌' : '⚠️');
    echo "<div class='row $cls'>";
    echo "<b>$ico $label</b>";
    if ($detail) echo "<br><span style='color:#5A5249;font-size:13px'>$detail</span>";
    if ($fix)    echo "<div class='fix'>🔧 Fix: $fix</div>";
    echo "</div>";
}

// ── 1. PHP version ────────────────────────────────────────────
$phpVer = PHP_VERSION;
$phpOk  = version_compare($phpVer, '8.0.0', '>=');
row("PHP Version: $phpVer", $phpOk,
    $phpOk ? 'PHP 8.0+ required — OK' : 'PHP 8.0+ required',
    $phpOk ? '' : 'Upgrade PHP in XAMPP. Download XAMPP 8.2+ from apachefriends.org');

// ── 2. display_errors check ───────────────────────────────────
$de = ini_get('display_errors');
row("display_errors = " . ($de ? 'On' : 'Off'),
    !$de,  // want it OFF for API
    "Current value: '$de'. API needs this OFF to prevent HTML leaking into JSON responses.",
    $de ? "In php.ini: set display_errors = Off. Or add to .htaccess: php_flag display_errors Off" : '');

// ── 3. Required extensions ─────────────────────────────────────
foreach (['pdo', 'pdo_mysql', 'json', 'mbstring', 'openssl'] as $ext) {
    $loaded = extension_loaded($ext);
    row("PHP extension: $ext", $loaded,
        $loaded ? 'Loaded' : 'MISSING',
        $loaded ? '' : "Enable in XAMPP php.ini: uncomment extension=$ext");
}

// ── 4. File paths ──────────────────────────────────────────────
$apiDir    = __DIR__ . '/api';
$configDir = __DIR__ . '/config';
$files = [
    'api/tables.php'      => $apiDir . '/tables.php',
    'api/auth.php'        => $apiDir . '/auth.php',
    'api/orders.php'      => $apiDir . '/orders.php',
    'config/db.php'       => $configDir . '/db.php',
    'config/settings.php' => $configDir . '/settings.php',
    'config/bootstrap.php'=> $configDir . '/bootstrap.php',
];
foreach ($files as $label => $path) {
    $exists = file_exists($path);
    row("File: $label", $exists,
        $exists ? realpath($path) : 'NOT FOUND at ' . $path,
        $exists ? '' : "Copy cafeos/ folder to XAMPP htdocs. Path should be: C:\\xampp\\htdocs\\cafeos\\$label");
}

// ── 5. MySQL connection ────────────────────────────────────────
echo "<h2 style='margin-top:24px'>MySQL Connection</h2>";

// Try to load db config
if (file_exists(__DIR__ . '/config/db.php')) {
    try {
        // Read db.php and extract connection details manually to avoid side effects
        $dbContent = file_get_contents(__DIR__ . '/config/db.php');
        preg_match("/host\s*=\s*'([^']+)'/",     $dbContent, $mHost);
        preg_match("/dbName\s*=\s*'([^']+)'/",   $dbContent, $mDb);
        preg_match("/username\s*=\s*'([^']+)'/", $dbContent, $mUser);
        preg_match("/password\s*=\s*'([^']*)'/", $dbContent, $mPass);

        $host   = $mHost[1]  ?? 'localhost';
        $dbName = $mDb[1]    ?? 'cafeos';
        $user   = $mUser[1]  ?? 'root';
        $pass   = $mPass[1]  ?? '';

        row("MySQL host: $host, user: $user", null, "Attempting connection...");

        $pdo = new PDO(
            "mysql:host=$host;charset=utf8mb4",
            $user, $pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        row("MySQL server connection", true, "Connected to MySQL at $host as '$user'");

        // Check if cafeos database exists
        $dbs = $pdo->query("SHOW DATABASES LIKE '$dbName'")->fetchAll();
        if ($dbs) {
            row("Database '$dbName' exists", true);

            // Count tables
            $pdo->exec("USE `$dbName`");
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            $expected = ['staff','tables','categories','menu_items','orders','order_items','bills','settings'];
            $missing  = array_diff($expected, $tables);

            row("Tables imported (" . count($tables) . " found)", empty($missing),
                "Found: " . implode(', ', $tables),
                empty($missing) ? '' : "Missing tables: " . implode(', ', $missing) . ". Import schema.sql via phpMyAdmin.");

            // Count seed data
            if (in_array('staff', $tables)) {
                $staffCount = $pdo->query("SELECT COUNT(*) FROM `$dbName`.staff")->fetchColumn();
                row("Staff accounts in DB", $staffCount > 0,
                    "$staffCount account(s) found",
                    $staffCount == 0 ? "Re-import schema.sql — seed data is included" : '');
            }
            if (in_array('menu_items', $tables)) {
                $itemCount = $pdo->query("SELECT COUNT(*) FROM `$dbName`.menu_items")->fetchColumn();
                row("Menu items in DB", $itemCount > 0,
                    "$itemCount item(s) found",
                    $itemCount == 0 ? "Re-import schema.sql to get sample menu data" : '');
            }

        } else {
            row("Database '$dbName' exists", false,
                "Database '$dbName' not found in MySQL.",
                "In phpMyAdmin: create a database named exactly 'cafeos' (no underscore), then import schema.sql");

            // Show which databases DO exist
            $allDbs = $pdo->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
            $userDbs = array_filter($allDbs, fn($d) => !in_array($d, ['information_schema','performance_schema','mysql','sys','phpmyadmin','test']));
            echo "<pre>Your databases: " . implode(', ', $userDbs) . "</pre>";
            echo "<div class='fix'>🔧 If you see 'cafeos_db' in the list above, that's the wrong name. Either rename it or change db.php line 17 to: private static string \$dbName = 'cafeos_db';</div>";
        }

    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, 'Access denied')) {
            row("MySQL connection", false, $msg,
                "Wrong username/password. Edit db.php. Default XAMPP: username='root', password=''");
        } elseif (str_contains($msg, 'refused') || str_contains($msg, 'No such')) {
            row("MySQL connection", false, $msg,
                "MySQL is not running. Open XAMPP Control Panel → Start MySQL");
        } else {
            row("MySQL connection", false, $msg);
        }
    }
} else {
    row("config/db.php", false, 'File not found', 'Ensure the backend/ folder is inside htdocs/cafeos/');
}

// ── 6. Quick API test ──────────────────────────────────────────
echo "<h2 style='margin-top:24px'>API Self-Test</h2>";
$apiUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
$apiUrl .= dirname($_SERVER['REQUEST_URI']) . '/api/auth.php';

echo "<p style='font-size:13px;color:#5A5249'>Testing: <code>$apiUrl</code></p>";

$ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 5, 'ignore_errors' => true]]);
$resp = @file_get_contents($apiUrl, false, $ctx);

if ($resp !== false) {
    $json = @json_decode($resp, true);
    if ($json !== null) {
        row("API returns valid JSON", true,
            "Response: " . substr(json_encode($json), 0, 100));
    } else {
        row("API returns valid JSON", false,
            "Got non-JSON response: " . htmlspecialchars(substr($resp, 0, 200)),
            "Check the errors above. The API is returning HTML/XML instead of JSON.");
        echo "<pre>" . htmlspecialchars(substr($resp, 0, 500)) . "</pre>";
    }
} else {
    row("API reachable", false,
        "Could not reach $apiUrl",
        "Ensure Apache is running in XAMPP");
}

?>

<h2 style="margin-top:24px">🔧 Quick Fix Commands</h2>
<p style="font-size:13px;color:#5A5249">Run these in phpMyAdmin SQL tab if needed:</p>
<pre>-- Create the correct database
CREATE DATABASE IF NOT EXISTS `cafeos` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Then import schema.sql via phpMyAdmin Import tab</pre>

<p style="font-size:13px;color:#5A5249;margin-top:16px">To suppress PHP errors in .htaccess (add to cafeos/backend/.htaccess):</p>
<pre>php_flag display_errors Off
php_flag log_errors On</pre>

<p style="color:#C0392B;font-weight:bold;margin-top:24px">⚠️ Delete this file before going to production: <code>cafeos/backend/check.php</code></p>
</body>
</html>
