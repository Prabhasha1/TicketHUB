<?php
session_start();
require __DIR__ . "/config/database.php";
require __DIR__ . "/config/app.php";

// Page to come back to after logging in (e.g. the ticket page the visitor was on)
$returnTo = safe_return_path($_POST['return'] ?? null);

function fail(string $message): void {
    global $returnTo;
    header("Location: login.php?error=" . urlencode($message) . ($returnTo ? "&return=" . urlencode($returnTo) : ""));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: login.php");
    exit();
}

$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

if ($email === '' || $password === '') {
    fail("Please enter your email and password.");
}

// Prepared statement — no SQL injection possible
// SELECT * so this keeps working before the is_guest column is migrated
$stmt = $conn->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    fail("No account found with that email address.");
}

if (!empty($user['is_guest'])) {
    fail("You bought tickets as a guest with this email, so there's no password yet. Use \"Forgot password?\" to set one — your tickets will be waiting.");
}

if (!password_verify($password, $user['password'])) {
    fail("Incorrect password. Please try again.");
}

// Set session
$_SESSION['user_id']   = $user['id'];
$_SESSION['user_name'] = $user['name'];
$_SESSION['user_role'] = $user['role'];

// Redirect based on role (or back to where they were)
if ($user['role'] === 'admin' || $user['role'] === 'super_admin') {
    header("Location: " . ($returnTo ?? "admin_dashboard.php"));
} else {
    header("Location: " . ($returnTo ?? "index.php"));
}
exit();
