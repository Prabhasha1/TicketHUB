<?php
/**
 * stripe_config.php
 * ------------------------------------------------------------
 * Stripe TEST MODE configuration.
 *
 * 1. Go to https://dashboard.stripe.com/test/apikeys
 * 2. Copy your "Publishable key" (starts with pk_test_...)
 * 3. Copy your "Secret key" (starts with sk_test_...)
 * 4. Paste them below, replacing the placeholder strings.
 *
 * These are TEST keys only — they cannot move real money.
 * Never commit real LIVE keys (sk_live_...) to any public repo.
 * ------------------------------------------------------------
 */

define('STRIPE_PUBLISHABLE_KEY', 'YOUR_STRIPE_PUBLISHABLE_KEY');
define('STRIPE_SECRET_KEY', 'YOUR_STRIPE_SECRET_KEY');

define('STRIPE_API_BASE', 'https://api.stripe.com/v1');

// Webhook signing secret (starts with whsec_...). Optional but recommended:
// it lets Stripe confirm bookings even if the customer closes the tab after paying.
//   Live host: Stripe Dashboard → Developers → Webhooks → Add endpoint
//              URL: https://<your-site>/stripe_webhook.php
//              Events: checkout.session.completed, checkout.session.async_payment_succeeded,
//                      checkout.session.async_payment_failed, checkout.session.expired
//   Localhost: stripe listen --forward-to localhost/TicketHUB/stripe_webhook.php
define('STRIPE_WEBHOOK_SECRET', '');

// Where Stripe sends the customer back after checkout. Leave '' to detect it
// automatically from the current request (works on localhost and the live host).
// Only set this if auto-detection is wrong, e.g. 'https://tickethub.example.com'.
define('APP_BASE_URL_OVERRIDE', '');
