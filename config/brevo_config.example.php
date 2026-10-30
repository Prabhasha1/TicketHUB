<?php
/**
 * brevo_config.php
 * ------------------------------------------------------------
 * Brevo (formerly Sendinblue) transactional email configuration.
 *
 * 1. Sign up free at https://app.brevo.com
 * 2. Go to Settings (profile icon) → SMTP & API → API Keys tab
 * 3. Click "Generate a new API key", copy it (starts with xkeysib-...)
 * 4. Paste it below
 * 5. Go to Senders & IP → Senders, and verify the email address
 *    you want to send FROM (Brevo requires this on the free tier)
 * ------------------------------------------------------------
 */

define('BREVO_API_KEY', 'YOUR_BREVO_API_KEY');
define('BREVO_API_BASE', 'https://api.brevo.com/v3');

// Must be a verified sender in your Brevo account (Senders & IP → Senders)
define('BREVO_SENDER_EMAIL', 'tickethubdesk@gmail.com');
define('BREVO_SENDER_NAME', 'TicketHub');
