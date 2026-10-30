<?php
/**
 * stripe_webhook.php
 * ------------------------------------------------------------
 * Stripe calls this server-to-server when a checkout changes state,
 * so bookings are confirmed (and tickets sent) even if the customer
 * closes the tab right after paying.
 *
 * Setup: see STRIPE_WEBHOOK_SECRET in config/stripe_config.php.
 * ------------------------------------------------------------
 */

require __DIR__ . "/config/database.php";
require __DIR__ . "/config/booking_service.php";

header('Content-Type: application/json');

$payload = file_get_contents('php://input');
$event = stripe_verify_webhook($payload, $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '', STRIPE_WEBHOOK_SECRET);

if ($event === null) {
    http_response_code(400);
    echo json_encode(['error' => STRIPE_WEBHOOK_SECRET === '' ? 'Webhook secret not configured' : 'Invalid signature']);
    exit;
}

$session = $event['data']['object'] ?? [];
$sessionId = $session['id'] ?? '';

try {
    switch ($event['type'] ?? '') {
        case 'checkout.session.completed':
        case 'checkout.session.async_payment_succeeded':
            // Re-fetch from Stripe rather than trusting the payload body alone
            $result = payment_finalize_session($conn, $sessionId);
            if ($result['status'] === 'confirmed') {
                booking_send_notifications($conn, (int)$result['booking']['id']);
            }
            if ($result['status'] === 'error') {
                // 500 makes Stripe retry later (it retries for up to 3 days)
                http_response_code(500);
            }
            echo json_encode(['received' => true, 'status' => $result['status']]);
            break;

        case 'checkout.session.async_payment_failed':
        case 'checkout.session.expired':
            $newStatus = $event['type'] === 'checkout.session.expired' ? 'expired' : 'failed';
            $stmt = $conn->prepare("UPDATE payments SET status = ?, failure_reason = ? WHERE stripe_session_id = ? AND status = 'pending'");
            $reason = $newStatus === 'expired' ? 'Checkout session expired.' : 'Payment failed at the bank.';
            $stmt->bind_param("sss", $newStatus, $reason, $sessionId);
            $stmt->execute();
            $stmt->close();
            echo json_encode(['received' => true, 'status' => $newStatus]);
            break;

        default:
            echo json_encode(['received' => true, 'ignored' => $event['type'] ?? '']);
    }
} catch (Throwable $e) {
    error_log("stripe_webhook failed for {$sessionId}: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal error']);
}
