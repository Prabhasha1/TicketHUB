<?php
/**
 * subscribe.php
 * ------------------------------------------------------------
 * Footer newsletter signup. POST email (+ csrf_token).
 * Answers JSON for the footer's fetch(); without JavaScript it
 * redirects back to the page the visitor came from.
 * ------------------------------------------------------------
 */
session_start();
require __DIR__ . "/config/database.php";
require __DIR__ . "/config/app.php";

$wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

function subscribe_respond(bool $ok, string $message): void
{
    global $wantsJson;
    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => $ok, 'message' => $message]);
        exit;
    }
    $back = $_SERVER['HTTP_REFERER'] ?? 'index.php';
    // Only bounce back to our own pages
    if (parse_url($back, PHP_URL_HOST) && parse_url($back, PHP_URL_HOST) !== ($_SERVER['HTTP_HOST'] ?? '')) {
        $back = 'index.php';
    }
    $_SESSION['subscribe_flash'] = $message;
    header("Location: $back");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    subscribe_respond(false, "Please use the subscribe form.");
}
if (!csrf_valid($_POST['csrf_token'] ?? null)) {
    subscribe_respond(false, "Your session expired — refresh the page and try again.");
}

// Light rate limit: 5 attempts per 10 minutes per browser session
$_SESSION['subscribe_hits'] = array_values(array_filter($_SESSION['subscribe_hits'] ?? [], fn($t) => $t > time() - 600));
if (count($_SESSION['subscribe_hits']) >= 5) {
    subscribe_respond(false, "Too many attempts. Please try again in a few minutes.");
}
$_SESSION['subscribe_hits'][] = time();

$email = strtolower(trim($_POST['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
    subscribe_respond(false, "Please enter a valid email address.");
}

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
try {
    $stmt = $conn->prepare("SELECT id, unsubscribed_at FROM newsletter_subscribers WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($existing && $existing['unsubscribed_at'] === null) {
        subscribe_respond(true, "You're already subscribed — we'll keep you posted! 🎉");
    }
    if ($existing) {
        $stmt = $conn->prepare("UPDATE newsletter_subscribers SET unsubscribed_at = NULL, subscribed_at = NOW(), user_id = COALESCE(user_id, ?) WHERE id = ?");
        $stmt->bind_param("ii", $userId, $existing['id']);
    } else {
        $stmt = $conn->prepare("INSERT INTO newsletter_subscribers (email, user_id, subscribed_at) VALUES (?, ?, NOW())");
        $stmt->bind_param("si", $email, $userId);
    }
    $stmt->execute();
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    error_log("subscribe failed (run files/setup_platform.php?): " . $e->getMessage());
    subscribe_respond(false, "Could not subscribe right now. Please try again later.");
}

subscribe_respond(true, "Thanks! You'll be the first to hear about new events. 🎉");
