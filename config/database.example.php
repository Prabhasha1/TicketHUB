<?php
/**
 * database.php
 * ------------------------------------------------------------
 * Single shared database connection for the whole app.
 * Every other file does: require __DIR__ . "/database.php";
 * and then uses the $conn object.
 * ------------------------------------------------------------
 */

$hostHeader = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = in_array($hostHeader, ['localhost', '127.0.0.1', '::1'])
    || str_starts_with($hostHeader, 'localhost:')
    || str_starts_with($hostHeader, '127.0.0.1:')
    || php_sapi_name() === 'cli';

if ($isLocal) {
    $host     = "localhost";
    $user     = "root";
    $password = "";
    $database = "YOUR_LIVE_DB_DATABASE";
} else {
    $host     = "YOUR_LIVE_DB_HOST";
    $user     = "YOUR_LIVE_DB_USER";
    $password = "YOUR_LIVE_DB_PASSWORD";
    $database = "YOUR_LIVE_DB_DATABASE";
}

// TicketHUB runs on Sri Lanka time (no DST). PHP and MySQL must agree, or seat holds,
// "upcoming event" checks and booking times drift apart (php.ini here says Europe/Berlin).
date_default_timezone_set('Asia/Colombo');

// Make mysqli throw exceptions on errors instead of silently failing,
// so we never accidentally run code against a query that didn't work.
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = new mysqli($host, $user, $password, $database);
    $conn->set_charset("utf8mb4");
    $conn->query("SET time_zone = '+05:30'");
} catch (mysqli_sql_exception $e) {
    // If local connection failed with YOUR_LIVE_DB_DATABASE, try fallback to 'tickethub'
    if ($isLocal && $database !== 'tickethub') {
        try {
            $conn = new mysqli($host, $user, $password, 'tickethub');
            $conn->set_charset("utf8mb4");
            $conn->query("SET time_zone = '+05:30'");
            return;
        } catch (mysqli_sql_exception $e2) {
            // continue to error handler below
        }
    }

    // Don't leak raw DB error details to the browser.
    // Full detail still goes to the PHP error log for you to check.
    error_log("Database connection failed: " . $e->getMessage());
    http_response_code(500);
    die("We're having trouble connecting right now. Please try again shortly.");
}
?>