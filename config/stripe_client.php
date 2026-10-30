<?php
/**
 * stripe_client.php
 * ------------------------------------------------------------
 * Minimal Stripe REST API client using PHP's built-in curl.
 * No Composer / SDK required.
 *
 * Stripe's API accepts form-encoded POST data and returns JSON.
 * Authentication is via HTTP Basic Auth using the secret key as
 * the username (Stripe's own documented convention).
 * ------------------------------------------------------------
 */

require_once __DIR__ . "/stripe_config.php";

/**
 * Send a request to the Stripe API.
 *
 * @param string $method   "GET" or "POST"
 * @param string $endpoint e.g. "/checkout/sessions"
 * @param array  $data     form fields to send (Stripe uses bracket notation for nested data)
 * @return array           ['ok' => bool, 'data' => array, 'error' => string|null]
 */
function stripe_request(string $method, string $endpoint, array $data = [], ?string $idempotencyKey = null): array
{
    $url = STRIPE_API_BASE . $endpoint;
    $ch = curl_init();

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, STRIPE_SECRET_KEY . ":");
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    if ($idempotencyKey !== null) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Idempotency-Key: ' . $idempotencyKey]);
    }

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    } elseif ($method === 'GET' && !empty($data)) {
        curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($data));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'data' => [], 'error' => "Connection error: $curlError"];
    }

    $decoded = json_decode($response, true);

    if ($httpCode >= 400) {
        $errorMsg = $decoded['error']['message'] ?? "Stripe API error (HTTP $httpCode)";
        return ['ok' => false, 'data' => $decoded ?? [], 'error' => $errorMsg];
    }

    return ['ok' => true, 'data' => $decoded ?? [], 'error' => null];
}

/**
 * Create a Stripe Checkout Session for a single line item.
 *
 * @param string $productName   Shown on the Stripe checkout page
 * @param float  $unitPriceLkr  Price per ticket in LKR
 * @param int    $quantity      Number of tickets
 * @param string $successUrl    Where to redirect after successful payment
 * @param string $cancelUrl     Where to redirect if the user cancels
 * @param array  $metadata      Extra data to attach (e.g. event_id, user_id) — retrieved later in payment_success.php
 * @param array  $options       Extra top-level session fields, e.g. customer_email, expires_at, client_reference_id
 */
function stripe_create_checkout_session(
    string $productName,
    float $unitPriceLkr,
    int $quantity,
    string $successUrl,
    string $cancelUrl,
    array $metadata = [],
    array $options = []
): array {
    // Stripe expects amounts in the smallest currency unit.
    // LKR has no sub-unit in practice for Stripe, so we send it as integer "cents"
    // by multiplying by 100 — Stripe will display it divided back down based on currency.
    $unitAmount = (int) round($unitPriceLkr * 100);

    $data = [
        'mode' => 'payment',
        'success_url' => $successUrl . '?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => $cancelUrl,
        'line_items' => [
            [
                'price_data' => [
                    'currency' => 'lkr',
                    'product_data' => [
                        'name' => $productName,
                    ],
                    'unit_amount' => $unitAmount,
                ],
                'quantity' => $quantity,
            ],
        ],
    ];

    // Flatten line_items array into Stripe's expected bracket notation
    $flat = stripe_flatten_checkout_data($data + $options);

    foreach ($metadata as $key => $value) {
        $flat["metadata[$key]"] = $value;
    }

    return stripe_request('POST', '/checkout/sessions', $flat);
}

/**
 * Retrieve a Checkout Session by ID (used after redirect back from Stripe
 * to verify payment actually succeeded before writing a booking to the DB).
 */
function stripe_get_checkout_session(string $sessionId): array
{
    if (!preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId)) {
        return ['ok' => false, 'data' => [], 'error' => 'Invalid checkout session ID.'];
    }
    return stripe_request('GET', "/checkout/sessions/$sessionId");
}

/**
 * Expire an open Checkout Session so it can no longer be paid
 * (used when the customer cancels, to release their held seats).
 */
function stripe_expire_checkout_session(string $sessionId): array
{
    if (!preg_match('/^cs_[A-Za-z0-9_]+$/', $sessionId)) {
        return ['ok' => false, 'data' => [], 'error' => 'Invalid checkout session ID.'];
    }
    return stripe_request('POST', "/checkout/sessions/$sessionId/expire");
}

/**
 * Fully refund a payment (e.g. tickets sold out while the customer was paying).
 * The idempotency key stops a retry from ever refunding twice.
 */
function stripe_refund_payment_intent(string $paymentIntentId, string $reason = 'requested_by_customer'): array
{
    return stripe_request('POST', '/refunds', [
        'payment_intent' => $paymentIntentId,
        'reason'         => $reason,
    ], 'refund-' . $paymentIntentId);
}

/**
 * Refund all or part of a payment (admin-approved refund requests).
 * $idempotencyKey must be unique per refund decision, so a double click or retry refunds once.
 */
function stripe_refund_amount(string $paymentIntentId, float $amountLkr, string $idempotencyKey, array $metadata = []): array
{
    $data = [
        'payment_intent' => $paymentIntentId,
        'amount'         => (int)round($amountLkr * 100),
        'reason'         => 'requested_by_customer',
    ];
    foreach ($metadata as $key => $value) {
        $data["metadata[$key]"] = $value;
    }
    return stripe_request('POST', '/refunds', $data, $idempotencyKey);
}

/**
 * Verify a webhook's Stripe-Signature header (HMAC-SHA256 of "timestamp.payload")
 * and return the decoded event, or null if the signature is invalid or too old.
 */
function stripe_verify_webhook(string $payload, string $signatureHeader, string $secret, int $toleranceSeconds = 300): ?array
{
    if ($secret === '' || $signatureHeader === '') {
        return null;
    }

    $timestamp  = null;
    $signatures = [];
    foreach (explode(',', $signatureHeader) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($key === 't') {
            $timestamp = (int)$value;
        } elseif ($key === 'v1') {
            $signatures[] = $value;
        }
    }

    if (!$timestamp || !$signatures || abs(time() - $timestamp) > $toleranceSeconds) {
        return null;
    }

    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            $event = json_decode($payload, true);
            return is_array($event) ? $event : null;
        }
    }
    return null;
}

/**
 * Stripe's API needs bracket notation for nested arrays in form-encoded data,
 * e.g. line_items[0][price_data][currency] = lkr
 * This walks our nested PHP array and flattens it into that format.
 */
function stripe_flatten_checkout_data(array $data, string $prefix = ''): array
{
    $result = [];
    foreach ($data as $key => $value) {
        $fullKey = $prefix === '' ? $key : "{$prefix}[{$key}]";
        if (is_array($value)) {
            $result += stripe_flatten_checkout_data($value, $fullKey);
        } else {
            $result[$fullKey] = $value;
        }
    }
    return $result;
}
