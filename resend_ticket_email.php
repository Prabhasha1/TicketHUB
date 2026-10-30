<?php
/**
 * resend_ticket_email.php
 * ------------------------------------------------------------
 * AJAX: re-send a booking's e-ticket.
 *   POST booking_ref, channel = email | sms, csrf_token
 * The customer can only resend their own bookings, once a minute per channel.
 * ------------------------------------------------------------
 */
session_start();
require __DIR__ . "/config/database.php";
require __DIR__ . "/config/booking_service.php";

header('Content-Type: application/json');

function respond(bool $ok, ?string $error = null, array $extra = []): void {
    echo json_encode(['ok' => $ok, 'error' => $error] + $extra);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    respond(false, 'Not logged in.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_valid($_POST['csrf_token'] ?? null)) {
    respond(false, 'Session expired — refresh the page and try again.');
}

$userId     = (int)$_SESSION['user_id'];
$bookingRef = trim($_POST['booking_ref'] ?? '');
$channel    = ($_POST['channel'] ?? 'email') === 'sms' ? 'sms' : 'email';

$stmt = $conn->prepare("SELECT id, phone FROM bookings WHERE booking_ref = ? AND user_id = ? AND status = 'confirmed'");
$stmt->bind_param("si", $bookingRef, $userId);
$stmt->execute();
$booking = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$booking) {
    respond(false, 'Booking not found.');
}

// Optional: SMS to a corrected number
if ($channel === 'sms' && isset($_POST['phone']) && trim($_POST['phone']) !== '') {
    $newPhone = phone_normalize($_POST['phone']);
    if ($newPhone === null) {
        respond(false, 'That mobile number is not valid.');
    }
    $upd = $conn->prepare("UPDATE bookings SET phone = ? WHERE id = ?");
    $upd->bind_param("si", $newPhone, $booking['id']);
    $upd->execute();
    $upd->close();
    $booking['phone'] = $newPhone;
}

$rateKey = "resend_{$channel}_{$booking['id']}";
$wait = 60 - (time() - ($_SESSION[$rateKey] ?? 0));
if ($wait > 0) {
    respond(false, "Please wait {$wait}s before sending again.");
}
$_SESSION[$rateKey] = time();

$result = booking_send_notifications($conn, (int)$booking['id'], [$channel]);
$sent = $result[$channel];

// Provider errors (API keys, IP allow-lists…) are for admins — they're logged and saved in
// bookings.notify_error; customers get a plain message instead.
$customerError = $channel === 'sms'
    ? "We couldn't send the SMS right now. Please try again later, or use \"View e-ticket\"."
    : "We couldn't send the email right now. Please try again later, or use \"View e-ticket\".";

respond((bool)$sent['ok'], $sent['ok'] ? null : $customerError, [
    'sent_to' => $channel === 'sms' ? phone_masked($booking['phone']) : null,
]);
