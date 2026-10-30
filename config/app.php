<?php
/**
 * app.php
 * ------------------------------------------------------------
 * Small app-wide helpers: the public base URL of the site and
 * phone number handling.
 * ------------------------------------------------------------
 */

/**
 * Public base URL of TicketHUB, e.g. "http://localhost/TicketHUB"
 * or "https://tickethub.example.com". Worked out from the request so
 * the same code runs on localhost and the live host without edits.
 * Stripe redirects back here and SMS/email links point here.
 */
function app_base_url(): string
{
    if (defined('APP_BASE_URL_OVERRIDE') && APP_BASE_URL_OVERRIDE !== '') {
        return rtrim(APP_BASE_URL_OVERRIDE, '/');
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $https ? 'https' : 'http';

    // Project root is the parent of /config; map it onto the document root
    $projectRoot = str_replace('\\', '/', realpath(__DIR__ . '/..'));
    $docRoot     = str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'] ?? '') ?: '');
    $path = '';
    if ($docRoot !== '' && stripos($projectRoot, $docRoot) === 0) {
        $path = substr($projectRoot, strlen($docRoot));
    } else {
        // Fallback: directory of the running script (all pages live in the project root)
        $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    }

    $path = trim($path, '/');
    return $scheme . '://' . $host . ($path !== '' ? '/' . $path : '');
}

function app_url(string $relative): string
{
    return rtrim(app_base_url(), '/') . '/' . ltrim($relative, '/');
}

/**
 * Normalize a phone number to international digits without "+",
 * e.g. "077 123 4567" -> "94771234567". Sri Lankan numbers are
 * accepted in any common format; other countries need a "+" prefix.
 * Returns null if the number is not valid.
 */
function phone_normalize(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    $hasPlus = str_starts_with($raw, '+') || str_starts_with($raw, '00');
    $digits  = preg_replace('/\D+/', '', $raw);
    if (str_starts_with($raw, '00')) {
        $digits = substr($digits, 2);
    }

    // Sri Lanka: 07XXXXXXXX, 7XXXXXXXX, 947XXXXXXXX (+ landlines 0XX... are rejected for SMS)
    if (preg_match('/^0(7\d{8})$/', $digits, $m))  return '94' . $m[1];
    if (preg_match('/^(7\d{8})$/', $digits, $m))   return '94' . $m[1];
    if (preg_match('/^94(7\d{8})$/', $digits, $m)) return '94' . $m[1];

    // Any other country, only when written with a + / 00 prefix (E.164: 8–15 digits)
    if ($hasPlus && !str_starts_with($digits, '94') && preg_match('/^[1-9]\d{7,14}$/', $digits)) {
        return $digits;
    }
    return null;
}

/**
 * "94771234567" -> "+94 77 123 4567" for display.
 */
function phone_display(?string $phone): string
{
    if (!$phone) {
        return '';
    }
    if (preg_match('/^94(\d{2})(\d{3})(\d{4})$/', $phone, $m)) {
        return "+94 {$m[1]} {$m[2]} {$m[3]}";
    }
    return '+' . $phone;
}

/**
 * Masked for logs / confirmations: "+94 77 *** 4567".
 */
function phone_masked(?string $phone): string
{
    if (!$phone) {
        return '';
    }
    if (preg_match('/^94(\d{2})\d{3}(\d{4})$/', $phone, $m)) {
        return "+94 {$m[1]} *** {$m[2]}";
    }
    return '+' . substr($phone, 0, 3) . str_repeat('*', max(0, strlen($phone) - 7)) . substr($phone, -4);
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_valid(?string $submitted): bool
{
    return !empty($_SESSION['csrf_token']) && is_string($submitted) && hash_equals($_SESSION['csrf_token'], $submitted);
}

/**
 * Where to send someone after they log in, e.g. back to the ticket page they came from.
 * Only plain local pages like "tickets.php?event_id=5" are accepted, so the
 * ?return= parameter can never be abused to bounce people to another site.
 */
function safe_return_path(?string $path): ?string
{
    $path = trim((string)$path);
    return preg_match('/^[a-z_]+\.php(\?[A-Za-z0-9_=&%.-]*)?$/', $path) ? $path : null;
}
