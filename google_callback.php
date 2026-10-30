<?php
/**
 * google_callback.php
 * ------------------------------------------------------------
 * Google redirects here after the visitor picks their account.
 * We swap the one-time code for an access token, read the
 * verified email/name, then log in the matching TicketHub
 * account — or create a new customer account if none exists.
 * ------------------------------------------------------------
 */
session_start();
require __DIR__ . "/config/database.php";
require __DIR__ . "/config/app.php";
require __DIR__ . "/config/google_config.php";

function google_fail(string $message): void {
    header("Location: login.php?error=" . urlencode($message));
    exit();
}

function google_http(string $url, ?array $postFields = null, array $headers = []): ?array {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    if ($postFields !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge(['Accept: application/json'], $headers));
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("Google OAuth connection error: $curlError");
        return null;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("Google OAuth HTTP $httpCode: $response");
        return null;
    }
    return json_decode($response, true);
}

if (!google_configured()) {
    google_fail("Google sign-in is not set up yet. Please use your email and password.");
}

// User pressed "Cancel" on Google's screen
if (isset($_GET['error'])) {
    google_fail("Google sign-in was cancelled.");
}

$state = $_GET['state'] ?? '';
$expected = $_SESSION['google_oauth_state'] ?? '';
unset($_SESSION['google_oauth_state']);
if ($expected === '' || !hash_equals($expected, $state)) {
    google_fail("Google sign-in expired. Please try again.");
}

$code = $_GET['code'] ?? '';
if ($code === '') {
    google_fail("Google sign-in failed. Please try again.");
}

// ---- Exchange the code for an access token ----
$token = google_http(GOOGLE_TOKEN_URL, [
    'code'          => $code,
    'client_id'     => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri'  => app_url('google_callback.php'),
    'grant_type'    => 'authorization_code',
]);
if (!$token || empty($token['access_token'])) {
    google_fail("Could not connect to Google. Please try again.");
}

// ---- Read the Google profile ----
$profile = google_http(GOOGLE_USERINFO_URL, null, ['Authorization: Bearer ' . $token['access_token']]);
if (!$profile || empty($profile['email'])) {
    google_fail("Could not read your Google account details.");
}
if (empty($profile['email_verified'])) {
    google_fail("Your Google email address is not verified.");
}

$email = strtolower(trim($profile['email']));
$name  = trim($profile['name'] ?? '');
if ($name === '') {
    $name = strstr($email, '@', true);
}
$name = mb_substr($name, 0, 100);

// ---- Find or create the TicketHub account ----
$stmt = $conn->prepare("SELECT id, name, role FROM users WHERE email = ? LIMIT 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    // Google accounts get a random password nobody knows; they can set
    // a real one later through "Forgot password?" if they want.
    $randomPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

    $stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'customer')");
    $stmt->bind_param("sss", $name, $email, $randomPassword);
    $stmt->execute();
    $user = ['id' => $stmt->insert_id, 'name' => $name, 'role' => 'customer'];
    $stmt->close();
}

// Google verified the email, so a guest-checkout account for it becomes a normal account
try {
    $claim = $conn->prepare("UPDATE users SET is_guest = 0 WHERE id = ? AND is_guest = 1");
    $claim->bind_param("i", $user['id']);
    $claim->execute();
    $claim->close();
} catch (mysqli_sql_exception $e) {
    // is_guest column not migrated yet — nothing to claim
}

$returnTo = safe_return_path($_SESSION['login_return'] ?? null);
unset($_SESSION['login_return']);

session_regenerate_id(true);
$_SESSION['user_id']   = $user['id'];
$_SESSION['user_name'] = $user['name'];
$_SESSION['user_role'] = $user['role'];

if ($user['role'] === 'admin' || $user['role'] === 'super_admin') {
    header("Location: " . ($returnTo ?? "admin_dashboard.php"));
} else {
    header("Location: " . ($returnTo ?? "index.php"));
}
exit();
