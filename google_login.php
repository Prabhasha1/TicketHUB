<?php
/**
 * google_login.php
 * ------------------------------------------------------------
 * Starts "Continue with Google": sends the visitor to Google's
 * sign-in page. Google then returns them to google_callback.php.
 * ------------------------------------------------------------
 */
session_start();
require __DIR__ . "/config/app.php";
require __DIR__ . "/config/google_config.php";

if (!google_configured()) {
    header("Location: login.php?error=" . urlencode("Google sign-in is not set up yet. Please use your email and password."));
    exit();
}

// Page to come back to after sign-in (e.g. the ticket page), checked again in the callback
$_SESSION['login_return'] = safe_return_path($_GET['return'] ?? null);

// Random state protects the callback against forged requests (CSRF)
$state = bin2hex(random_bytes(16));
$_SESSION['google_oauth_state'] = $state;

$params = [
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => app_url('google_callback.php'),
    'response_type' => 'code',
    'scope'         => 'openid email profile',
    'state'         => $state,
    'prompt'        => 'select_account',
];

header("Location: " . GOOGLE_AUTH_URL . "?" . http_build_query($params));
exit();
