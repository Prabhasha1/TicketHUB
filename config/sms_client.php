<?php
/**
 * sms_client.php
 * ------------------------------------------------------------
 * Sends SMS through the provider chosen in sms_config.php.
 * Every provider returns the same shape:
 *   ['ok' => bool, 'error' => string|null, 'provider' => string]
 * ------------------------------------------------------------
 */

require_once __DIR__ . "/sms_config.php";
require_once __DIR__ . "/brevo_config.php";

/**
 * @param string $phone   international digits without "+", e.g. 94771234567 (see phone_normalize())
 * @param string $message plain text; keep under ~300 chars (2 SMS parts)
 */
function sms_send(string $phone, string $message): array
{
    $provider = SMS_PROVIDER;

    if (!preg_match('/^[1-9]\d{7,14}$/', $phone)) {
        return ['ok' => false, 'error' => 'Invalid phone number.', 'provider' => $provider];
    }

    switch ($provider) {
        case 'notifylk':
            if (NOTIFYLK_USER_ID === '' || NOTIFYLK_API_KEY === '') {
                return ['ok' => false, 'error' => 'Notify.lk is not configured (config/sms_config.php).', 'provider' => $provider];
            }
            $res = sms_http('POST', 'https://app.notify.lk/api/v1/send', [
                'user_id'   => NOTIFYLK_USER_ID,
                'api_key'   => NOTIFYLK_API_KEY,
                'sender_id' => NOTIFYLK_SENDER_ID,
                'to'        => $phone,
                'message'   => $message,
            ], [], false);
            $ok = $res['ok'] && (($res['json']['status'] ?? '') === 'success');
            return ['ok' => $ok, 'error' => $ok ? null : ($res['json']['errors'][0] ?? $res['json']['message'] ?? $res['error'] ?? 'Notify.lk rejected the SMS.'), 'provider' => $provider];

        case 'textlk':
            if (TEXTLK_API_TOKEN === '') {
                return ['ok' => false, 'error' => 'Text.lk is not configured (config/sms_config.php).', 'provider' => $provider];
            }
            $res = sms_http('POST', 'https://app.text.lk/api/v3/sms/send', [
                'recipient' => $phone,
                'sender_id' => SMS_SENDER_ID,
                'type'      => 'plain',
                'message'   => $message,
            ], ['Authorization: Bearer ' . TEXTLK_API_TOKEN], true);
            $ok = $res['ok'] && (($res['json']['status'] ?? '') === 'success');
            return ['ok' => $ok, 'error' => $ok ? null : ($res['json']['message'] ?? $res['error'] ?? 'Text.lk rejected the SMS.'), 'provider' => $provider];

        case 'brevo':
            $res = sms_http('POST', BREVO_API_BASE . '/transactionalSMS/sms', [
                'sender'    => substr(preg_replace('/[^A-Za-z0-9]/', '', SMS_SENDER_ID), 0, 11),
                'recipient' => $phone,
                'content'   => $message,
                'type'      => 'transactional',
            ], ['api-key: ' . BREVO_API_KEY], true);
            return ['ok' => $res['ok'], 'error' => $res['ok'] ? null : ($res['json']['message'] ?? $res['error']), 'provider' => $provider];

        case 'log':
        default:
            $logDir = __DIR__ . '/../logs';
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
                // Keep logs (they contain phone numbers) out of the public web root
                file_put_contents($logDir . '/.htaccess', "Require all denied\nDeny from all\n");
            }
            $line = '[' . date('Y-m-d H:i:s') . "] TO +$phone\n$message\n" . str_repeat('-', 60) . "\n";
            $written = file_put_contents($logDir . '/sms.log', $line, FILE_APPEND | LOCK_EX) !== false;
            return ['ok' => $written, 'error' => $written ? null : 'Could not write logs/sms.log', 'provider' => 'log'];
    }
}

/**
 * Tiny curl helper shared by the providers.
 */
function sms_http(string $method, string $url, array $data, array $headers, bool $json): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $json ? json_encode($data) : http_build_query($data));
    $headers[] = 'Accept: application/json';
    if ($json) {
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'json' => [], 'error' => "Connection error: $curlErr"];
    }
    $decoded = json_decode($body, true) ?: [];
    return [
        'ok'    => $httpCode >= 200 && $httpCode < 300,
        'json'  => $decoded,
        'error' => $httpCode >= 300 ? "HTTP $httpCode" : null,
    ];
}
