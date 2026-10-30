<?php
/**
 * qr_generator.php
 * ------------------------------------------------------------
 * Generates booking QR codes as PNG images.
 *
 * phpqrcode's own PNG output needs the GD extension, which is off
 * on many hosts (including default XAMPP). So we only ask phpqrcode
 * for the QR matrix and write the PNG ourselves with zlib — works
 * everywhere, no GD required.
 *
 * The QR encodes "TH1.<booking ref>.<ticket token>". The secret
 * token means a ticket can't be forged just by knowing the booking
 * reference printed on it; scan_tickets.php checks both.
 * ------------------------------------------------------------
 */

require_once __DIR__ . "/../lib/phpqrcode.php";

/**
 * Render a QR code for $text as PNG bytes, or false on failure.
 *
 * @param int $scale  pixels per QR module
 * @param int $margin quiet-zone width in modules (4 is the spec; 2 is fine on screens)
 */
function qr_png_bytes(string $text, int $scale = 8, int $margin = 2)
{
    try {
        // Error correction H: ~30% of the code can be damaged and still scan (phone screens, glare)
        $matrix = QRcode::text($text, false, QR_ECLEVEL_H, 1, 0);
    } catch (Throwable $e) {
        error_log("QR matrix generation failed: " . $e->getMessage());
        return false;
    }
    if (!is_array($matrix) || !$matrix) {
        return false;
    }

    $modules = count($matrix);
    $size    = ($modules + 2 * $margin) * $scale;

    // 8-bit grayscale scanlines, each prefixed with filter type 0
    $white    = str_repeat("\xFF", $size);
    $quietRow = "\x00" . $white;
    $raw = str_repeat($quietRow, $margin * $scale);
    foreach ($matrix as $row) {
        $line = str_repeat("\xFF", $margin * $scale);
        for ($x = 0; $x < $modules; $x++) {
            $line .= str_repeat($row[$x] === '1' ? "\x00" : "\xFF", $scale);
        }
        $line .= str_repeat("\xFF", $margin * $scale);
        $raw .= str_repeat("\x00" . $line, $scale);
    }
    $raw .= str_repeat($quietRow, $margin * $scale);

    $chunk = static function (string $type, string $data): string {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    };

    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNCCCCC', $size, $size, 8, 0, 0, 0, 0))
        . $chunk('IDAT', gzcompress($raw, 9))
        . $chunk('IEND', '');
}

/**
 * Text inside a booking QR. Without a token (very old callers) it falls
 * back to the bare reference, which the scanner still accepts.
 */
function booking_qr_payload(string $bookingRef, ?string $ticketToken = null): string
{
    return $ticketToken ? "TH1.$bookingRef.$ticketToken" : $bookingRef;
}

/**
 * QR PNG bytes for a booking.
 */
function booking_qr_png(string $bookingRef, ?string $ticketToken = null)
{
    return qr_png_bytes(booking_qr_payload($bookingRef, $ticketToken));
}

/**
 * data: URI for embedding the QR straight into a page.
 */
function booking_qr_data_uri(string $bookingRef, ?string $ticketToken = null): string
{
    $png = booking_qr_png($bookingRef, $ticketToken);
    return $png ? 'data:image/png;base64,' . base64_encode($png) : '';
}

/**
 * Kept for older callers: saves the QR to /ticket_qrcodes/ and returns the path.
 *
 * @return string|false
 */
function generate_booking_qr(string $bookingRef)
{
    $png = booking_qr_png($bookingRef);
    if (!$png) {
        return false;
    }
    $qrDir = __DIR__ . "/../ticket_qrcodes/";
    if (!is_dir($qrDir)) {
        mkdir($qrDir, 0755, true);
    }
    $filename = $qrDir . preg_replace('/[^A-Za-z0-9_-]/', '', $bookingRef) . '.png';
    return file_put_contents($filename, $png) !== false ? $filename : false;
}
