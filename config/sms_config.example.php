<?php
/**
 * sms_config.php
 * ------------------------------------------------------------
 * SMS delivery for booking confirmations.
 *
 * Pick ONE provider in SMS_PROVIDER:
 *
 *   'log'     — Development mode. Nothing is sent; each SMS is
 *               written to logs/sms.log so you can see it.
 *
 *   'notifylk' — Notify.lk (Sri Lanka) https://app.notify.lk
 *               Dashboard → Settings → API Keys: copy User ID + API Key.
 *               Sender ID "NotifyDEMO" works for testing; request your
 *               own (e.g. "TicketHub") for production.
 *
 *   'textlk'  — Text.lk (Sri Lanka) https://app.text.lk
 *               Developers → API Tokens: create a token.
 *
 *   'brevo'   — Brevo transactional SMS (uses the BREVO_API_KEY from
 *               brevo_config.php). Needs SMS credits in your Brevo account.
 * ------------------------------------------------------------
 */

define('SMS_PROVIDER', 'notifylk');

// Sender name shown on the phone (max 11 letters/numbers; must be approved by the provider)
define('SMS_SENDER_ID', 'TicketHub');

// Notify.lk
define('NOTIFYLK_USER_ID', 'YOUR_NOTIFYLK_USER_ID');
define('NOTIFYLK_API_KEY', 'YOUR_NOTIFYLK_API_KEY');
define('NOTIFYLK_SENDER_ID', 'NotifyDEMO');

// Text.lk
define('TEXTLK_API_TOKEN', '');
