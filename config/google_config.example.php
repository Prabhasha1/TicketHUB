<?php
/**
 * google_config.php
 * ------------------------------------------------------------
 * "Continue with Google" (OAuth 2.0) configuration.
 *
 * 1. Go to https://console.cloud.google.com/apis/credentials
 * 2. Create a project (or pick one), then set up the
 *    "OAuth consent screen" (External, app name TicketHub,
 *    scopes: email, profile, openid).
 * 3. Create credentials → OAuth client ID → "Web application".
 * 4. Under "Authorized redirect URIs" add BOTH of these:
 *      http://localhost/TicketHUB/google_callback.php
 *      https://<your-live-site>/google_callback.php
 * 5. Paste the Client ID and Client secret below.
 * ------------------------------------------------------------
 */

define('GOOGLE_CLIENT_ID', 'YOUR_GOOGLE_CLIENT_ID');
define('GOOGLE_CLIENT_SECRET', 'YOUR_GOOGLE_CLIENT_SECRET');

define('GOOGLE_AUTH_URL', 'https://accounts.google.com/o/oauth2/v2/auth');
define('GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token');
define('GOOGLE_USERINFO_URL', 'https://openidconnect.googleapis.com/v1/userinfo');

function google_configured(): bool
{
    return GOOGLE_CLIENT_ID !== '' && GOOGLE_CLIENT_SECRET !== '';
}
