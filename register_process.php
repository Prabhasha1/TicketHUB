<?php
session_start();
require __DIR__ . "/config/database.php";
require __DIR__ . "/config/app.php";

function back_with_error($message) {
    header("Location: register.php?error=" . urlencode($message));
    exit();
}

// Only accept POST submissions
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: register.php");
    exit();
}

$name     = trim($_POST['name'] ?? '');
$email    = trim($_POST['email'] ?? '');
$password = $_POST['password'] ?? '';

// ---- Validation ----
if ($name === '' || $email === '' || $password === '') {
    back_with_error("All fields are required.");
}

if (mb_strlen($name) > 100) {
    back_with_error("Name is too long.");
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    back_with_error("Please enter a valid email address.");
}

if (strlen($password) < 8) {
    back_with_error("Password must be at least 8 characters.");
}

$phoneRaw = trim($_POST['phone'] ?? '');
$phone = $phoneRaw === '' ? null : phone_normalize($phoneRaw);
if ($phoneRaw !== '' && $phone === null) {
    back_with_error("Please enter a valid mobile number (e.g. 077 123 4567), or leave it blank.");
}

// ---- Check for existing account ----
// SELECT * so this keeps working before the is_guest column is migrated
$check = $conn->prepare("SELECT * FROM users WHERE email = ?");
$check->bind_param("s", $email);
$check->execute();
$existing = $check->get_result()->fetch_assoc();
$check->close();

if ($existing) {
    // Guest accounts are claimed through the emailed reset link (proves they own the
    // email), never by registering — otherwise anyone could take over their tickets.
    back_with_error(!empty($existing['is_guest'])
        ? "You've bought tickets as a guest with this email. Use \"Forgot password?\" on the login page to set a password — your tickets will be waiting."
        : "An account with that email already exists.");
}

// ---- Create account ----
$hashedPassword = password_hash($password, PASSWORD_DEFAULT);

$stmt = $conn->prepare("INSERT INTO users (name, email, phone, password, role) VALUES (?, ?, ?, ?, 'customer')");
$stmt->bind_param("ssss", $name, $email, $phone, $hashedPassword);

if ($stmt->execute()) {
    // Log the user in immediately after registering
    $_SESSION['user_id']   = $stmt->insert_id;
    $_SESSION['user_name'] = $name;
    $_SESSION['user_role'] = 'customer';

    $stmt->close();
    $conn->close();

    header("Location: index.php");
    exit();
} else {
    $stmt->close();
    $conn->close();
    back_with_error("Something went wrong. Please try again.");
}