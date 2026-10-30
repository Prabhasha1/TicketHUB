<?php
/**
 * brevo_client.php
 * ------------------------------------------------------------
 * Minimal Brevo transactional email client using PHP's curl.
 * No SDK / Composer required.
 * ------------------------------------------------------------
 */

require_once __DIR__ . "/brevo_config.php";

/**
 * Send a transactional email via Brevo, optionally with one attachment.
 *
 * @param string $toEmail
 * @param string $toName
 * @param string $subject
 * @param string $htmlContent
 * @param string|null $attachmentPath  Absolute path to a file to attach (e.g. QR code PNG)
 * @param string|null $attachmentName  Filename shown to the recipient (must end in a valid extension)
 * @param string|null $attachmentBytes Raw file contents to attach instead of reading $attachmentPath
 * @return array ['ok' => bool, 'error' => string|null]
 */
function brevo_send_email(
    string $toEmail,
    string $toName,
    string $subject,
    string $htmlContent,
    ?string $attachmentPath = null,
    ?string $attachmentName = null,
    ?string $attachmentBytes = null
): array {
    $payload = [
        'sender' => [
            'name' => BREVO_SENDER_NAME,
            'email' => BREVO_SENDER_EMAIL,
        ],
        'to' => [
            ['email' => $toEmail, 'name' => $toName],
        ],
        'subject' => $subject,
        'htmlContent' => $htmlContent,
    ];

    if ($attachmentBytes !== null && $attachmentBytes !== '') {
        $payload['attachment'] = [
            [
                'name' => $attachmentName ?? 'attachment',
                'content' => base64_encode($attachmentBytes),
            ],
        ];
    } elseif ($attachmentPath !== null && file_exists($attachmentPath)) {
        $payload['attachment'] = [
            [
                'name' => $attachmentName ?? basename($attachmentPath),
                'content' => base64_encode(file_get_contents($attachmentPath)),
            ],
        ];
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, BREVO_API_BASE . '/smtp/email');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'api-key: ' . BREVO_API_KEY,
        'Content-Type: application/json',
        'Accept: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => "Connection error: $curlError"];
    }

    $decoded = json_decode($response, true);

    // Brevo returns HTTP 201 Created on success
    if ($httpCode >= 200 && $httpCode < 300) {
        return ['ok' => true, 'error' => null];
    }

    $errorMsg = $decoded['message'] ?? "Brevo API error (HTTP $httpCode)";
    return ['ok' => false, 'error' => $errorMsg];
}


/**
 * Build the HTML body for a booking confirmation email, matching
 * the TicketHub brand (violet/rose theme).
 *
 * $extra (all optional):
 *   ticket_url  — link to the online e-ticket (view_ticket.php)
 *   qr_url      — public URL of the QR PNG (shown inline when the site is public)
 *   holder_name — ticket holder's name
 *   phone       — masked phone the SMS went to
 */
function build_ticket_email_html(array $booking, array $event, array $extra = []): string
{
    $eventDate  = date("l, M d, Y · h:i A", strtotime($event['event_date']));
    $title      = htmlspecialchars($event['title']);
    $location   = htmlspecialchars($event['location']);
    $bookingRef = htmlspecialchars($booking['booking_ref']);
    $quantity   = (int)$booking['quantity'];
    $totalPrice = number_format((float)$booking['total_price'], 2);
    $discount   = (float)($booking['discount_amount'] ?? 0);
    $fullPrice  = (float)$booking['total_price'] + $discount;
    $unitPrice  = $quantity > 0 ? number_format($fullPrice / $quantity, 2) : $totalPrice;
    $discountRow = $discount > 0
        ? "<tr><td style=\"padding: 6px 0; color:#6FCF97;\">Loyalty discount (" . (int)($booking['points_redeemed'] ?? 0) . " pts)</td><td style=\"padding: 6px 0; text-align:right; color:#6FCF97;\">− LKR " . number_format($discount, 2) . "</td></tr>"
        : '';
    $holder     = htmlspecialchars($extra['holder_name'] ?? '');
    $ticketUrl  = htmlspecialchars($extra['ticket_url'] ?? '');
    $qrUrl      = htmlspecialchars($extra['qr_url'] ?? '');
    $phone      = htmlspecialchars($extra['phone'] ?? '');
    $mapsUrl    = htmlspecialchars('https://www.google.com/maps/search/?api=1&query=' . urlencode($event['location']));

    $holderRow = $holder !== ''
        ? "<tr><td style=\"padding: 6px 0; color:#A9A3B8;\">Ticket holder</td><td style=\"padding: 6px 0; text-align:right;\">{$holder}</td></tr>"
        : '';
    $qrImg = $qrUrl !== ''
        ? "<img src=\"{$qrUrl}\" width=\"180\" height=\"180\" alt=\"QR code {$bookingRef}\" style=\"display:block; margin: 0 auto 8px; background:#fff; border-radius:8px;\">"
        : '';
    $button = $ticketUrl !== ''
        ? "<a href=\"{$ticketUrl}\" style=\"display:inline-block; background:#9D7CF9; color:#ffffff; text-decoration:none; font-weight:bold; font-size:14px; padding: 12px 26px; border-radius: 100px;\">View your e-ticket</a>"
        : '';
    $smsNote = $phone !== ''
        ? "<p style=\"color:#A9A3B8; font-size: 12px; margin: 12px 0 0;\">We've also sent your ticket link by SMS to {$phone}.</p>"
        : '';

    return <<<HTML
    <div style="font-family: Arial, sans-serif; background:#14121A; padding: 30px 12px; color:#F1EEF7;">
        <div style="max-width: 480px; margin: 0 auto; background:#1B1822; border-radius: 16px; overflow:hidden; border:1px solid #2a2733;">
            <div style="padding: 28px 30px 10px;">
                <div style="font-size: 20px; font-weight: bold; color:#F1EEF7;">Ticket<span style="color:#9D7CF9;">Hub</span></div>
            </div>
            <div style="padding: 10px 30px 0;">
                <h2 style="color:#9D7CF9; margin: 10px 0 4px; font-size: 20px;">Payment received — you're going! 🎉</h2>
                <p style="color:#A9A3B8; font-size: 14px; margin: 0 0 20px;">Your tickets for <strong style="color:#F1EEF7;">{$title}</strong> are confirmed.</p>
            </div>
            <div style="padding: 0 30px;">
                <table style="width:100%; font-size: 14px; color:#F1EEF7; border-collapse: collapse;">
                    <tr><td style="padding: 6px 0; color:#A9A3B8;">Event</td><td style="padding: 6px 0; text-align:right;">{$title}</td></tr>
                    <tr><td style="padding: 6px 0; color:#A9A3B8;">Date</td><td style="padding: 6px 0; text-align:right;">{$eventDate}</td></tr>
                    <tr><td style="padding: 6px 0; color:#A9A3B8;">Venue</td><td style="padding: 6px 0; text-align:right;"><a href="{$mapsUrl}" style="color:#F1EEF7;">{$location}</a></td></tr>
                    {$holderRow}
                    <tr><td style="padding: 6px 0; color:#A9A3B8;">Tickets</td><td style="padding: 6px 0; text-align:right;">{$quantity} × LKR {$unitPrice}</td></tr>
                    {$discountRow}
                    <tr><td style="padding: 10px 0 6px; color:#A9A3B8; border-top:1px solid #2a2733;">Total paid</td><td style="padding: 10px 0 6px; text-align:right; font-weight:bold; border-top:1px solid #2a2733;">LKR {$totalPrice}</td></tr>
                </table>
            </div>
            <div style="padding: 20px 30px 4px;">
                <div style="background:#0f0e14; border:1px solid #2a2733; border-radius: 12px; padding: 18px; text-align:center;">
                    {$qrImg}
                    <p style="color:#A9A3B8; font-size: 12px; margin: 0;">Show this QR code at the entrance — it's also attached to this email.</p>
                    <p style="font-family: monospace; color:#9D7CF9; font-size: 15px; letter-spacing: 1px; margin: 10px 0 0;">{$bookingRef}</p>
                </div>
            </div>
            <div style="padding: 18px 30px 0; text-align:center;">
                {$button}
                {$smsNote}
            </div>
            <div style="padding: 22px 30px 28px; text-align:center; color:#6e6a7d; font-size: 12px;">
                One QR code admits all {$quantity} ticket(s) in this booking.<br>
                © 2026 TicketHub. All rights reserved.
            </div>
        </div>
    </div>
    HTML;
}
