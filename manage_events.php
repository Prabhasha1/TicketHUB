<?php
session_start();
require __DIR__ . "/config/database.php";
require __DIR__ . "/config/commission.php";
require __DIR__ . "/config/refund_service.php";

// Admin check
if (!isset($_SESSION['user_role']) || !in_array($_SESSION['user_role'], ['admin', 'super_admin'], true)) {
    header("Location: login.php");
    exit;
}

$currentUserId = (int)$_SESSION['user_id'];
$isSuperAdmin  = ($_SESSION['user_role'] === 'super_admin');
$successMsg    = trim($_GET['msg'] ?? '');
$errorMsg      = trim($_GET['error'] ?? '');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

function back_to_events(string $key, string $message): void {
    header("Location: manage_events.php?$key=" . urlencode($message));
    exit;
}

// ---- Event status (postpone / cancel / reschedule…): Admins on their own events, Super Admins on all ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'set_event_status') {
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        back_to_events('error', "Security validation failed (invalid CSRF token). Please try again.");
    }
    $eventId = (int)($_POST['event_id'] ?? 0);
    $own = $conn->prepare("SELECT created_by FROM events WHERE id = ?");
    $own->bind_param("i", $eventId);
    $own->execute();
    $ownerRow = $own->get_result()->fetch_assoc();
    $own->close();
    if (!$ownerRow) {
        back_to_events('error', "Event not found.");
    }
    if (!$isSuperAdmin && (int)$ownerRow['created_by'] !== $currentUserId) {
        back_to_events('error', "Access denied: You can only change the status of events that you created.");
    }
    $result = event_status_change($conn, $eventId, (string)($_POST['event_status'] ?? ''), (string)($_POST['status_note'] ?? ''),
                                  trim((string)($_POST['new_date'] ?? '')), $currentUserId);
    back_to_events($result['ok'] ? 'msg' : 'error', $result['message']);
}

// ---- Commission changes: Super Admins only ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isSuperAdmin) {
        back_to_events('error', "Only Super Admins can change TicketHUB commission.");
    }
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) {
        back_to_events('error', "Security validation failed (invalid CSRF token). Please try again.");
    }

    $action = $_POST['action'] ?? '';
    $type   = trim($_POST['commission_type'] ?? '');
    $value  = trim($_POST['commission_value'] ?? '');

    if ($validationError = commission_validate($type, $value)) {
        back_to_events('error', $validationError);
    }
    $value = round((float)$value, 2);
    $label = commission_label($type, $value);

    if ($action === 'set_event_commission') {
        $eventId = (int)($_POST['event_id'] ?? 0);
        $upd = $conn->prepare("UPDATE events SET commission_type = ?, commission_value = ? WHERE id = ?");
        $upd->bind_param("sdi", $type, $value, $eventId);
        $upd->execute();
        $changed = $upd->affected_rows;
        $upd->close();

        $titleStmt = $conn->prepare("SELECT title FROM events WHERE id = ?");
        $titleStmt->bind_param("i", $eventId);
        $titleStmt->execute();
        $eventRow = $titleStmt->get_result()->fetch_assoc();
        $titleStmt->close();

        if (!$eventRow) {
            back_to_events('error', "Event not found.");
        }
        back_to_events('msg', $changed
            ? "Commission for '{$eventRow['title']}' set to $label. Applies to new bookings from now on."
            : "Commission for '{$eventRow['title']}' is already $label.");
    }

    if ($action === 'set_default_commission') {
        if (!commission_set_default($conn, $type, $value)) {
            back_to_events('error', "Failed to save the default commission.");
        }
        $message = "Default commission set to $label for new events.";
        if (!empty($_POST['apply_to_all'])) {
            $upd = $conn->prepare("UPDATE events SET commission_type = ?, commission_value = ?");
            $upd->bind_param("sd", $type, $value);
            $upd->execute();
            $message .= " Also applied to all {$upd->affected_rows} existing event(s) that differed.";
            $upd->close();
        }
        back_to_events('msg', $message);
    }

    back_to_events('error', "Unknown action.");
}

$defaultCommission = commission_get_default($conn);
$pendingRefunds    = refund_pending_count($conn, $currentUserId, $isSuperAdmin);
// Cancelled events that still owe customers money → "Refund everyone" shortcut on the card
$cancelledOwing = [];
foreach (refund_cancelled_events_pending($conn, $currentUserId, $isSuperAdmin) as $ev) {
    $cancelledOwing[(int)$ev['id']] = $ev['outstanding'];
}

// Pending refund requests per event, for the badge on each card
$refundsByEvent = [];
try {
    $res = $conn->query("SELECT event_id, COUNT(*) AS n FROM refund_requests WHERE status = 'pending' GROUP BY event_id");
    while ($r = $res->fetch_assoc()) {
        $refundsByEvent[(int)$r['event_id']] = (int)$r['n'];
    }
} catch (mysqli_sql_exception $e) {
    error_log("manage_events refund counts failed: " . $e->getMessage());
}

// Fetch events: admins can manage their own added events only; super admins can view/manage all events.
// Each event carries its confirmed sales so we can show the TicketHUB / organizer split.
$events = [];
$totals = ['tickets' => 0, 'gross' => 0.0, 'commission' => 0.0, 'payout' => 0.0];
try {
    $sql = "SELECT e.*, u.name AS creator_name, u.role AS creator_role,
                   COALESCE(b.tickets, 0)    AS sold_confirmed,
                   COALESCE(b.gross, 0)      AS gross_sales,
                   COALESCE(b.commission, 0) AS commission_earned
            FROM events e
            LEFT JOIN users u ON e.created_by = u.id
            LEFT JOIN (
                SELECT event_id, SUM(quantity) AS tickets, SUM(total_price) AS gross, SUM(commission_amount) AS commission
                FROM bookings WHERE status = 'confirmed' GROUP BY event_id
            ) b ON b.event_id = e.id";
    if ($isSuperAdmin) {
        $stmt = $conn->prepare("$sql ORDER BY e.event_date ASC");
    } else {
        $stmt = $conn->prepare("$sql WHERE e.created_by = ? ORDER BY e.event_date ASC");
        $stmt->bind_param("i", $currentUserId);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['commission_per_ticket'] = commission_per_ticket((float)$row['ticket_price'], $row['commission_type'], (float)$row['commission_value']);
        $row['payout'] = (float)$row['gross_sales'] - (float)$row['commission_earned'];

        $totals['tickets']    += (int)$row['sold_confirmed'];
        $totals['gross']      += (float)$row['gross_sales'];
        $totals['commission'] += (float)$row['commission_earned'];
        $totals['payout']     += $row['payout'];

        $events[] = $row;
    }
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    error_log("manage_events fetch failed (run files/setup_platform.php?): " . $e->getMessage());
    $errorMsg = $errorMsg ?: "Could not load events. If you just updated TicketHUB, run files/setup_platform.php once.";
    $events = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . "/pwa_head.php"; ?>
<title>Manage Events - TicketHub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #14121A;
    --surface: rgba(255,255,255,0.04);
    --surface-hover: rgba(255,255,255,0.07);
    --border: rgba(255,255,255,0.08);
    --text: #F1EEF7;
    --text-dim: #A9A3B8;
    --violet: #9D7CF9;
    --violet-soft: rgba(157,124,249,0.18);
    --rose: #F0729C;
    --blue: #6CA0F2;
    --red: #EF6B6B;
    --font-display: 'Sora', 'Segoe UI', sans-serif;
    --font-body: 'Inter', 'Segoe UI', sans-serif;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: var(--font-body);
    background: var(--bg);
    color: var(--text);
}

header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 22px 48px; background: rgba(20,18,26,0.75);
    backdrop-filter: blur(14px); border-bottom: 1px solid var(--border);
    position: sticky; top: 0; z-index: 100;
}

.logo { font-family: var(--font-display); font-size: 22px; font-weight: 700; letter-spacing: -0.01em; }
.logo span { color: var(--violet); }

nav { display: flex; align-items: center; gap: 4px; }

nav a {
    padding: 8px 14px; border-radius: 8px; text-decoration: none;
    color: var(--text-dim); font-size: 14px; font-weight: 500;
    transition: color 0.25s ease, background 0.25s ease;
}

nav a:hover { color: var(--text); background: var(--surface); }
nav a.cta { color: var(--bg); background: var(--text); margin-left: 6px; }
nav a.cta:hover { background: var(--violet); color: #fff; }

nav .nav-divider {
    display: inline-block;
    width: 1px;
    height: 18px;
    background: var(--border);
    margin: 0 4px;
    padding: 0;
}

nav a.admin-link {
    color: #CBB8FC;
    background: rgba(157, 124, 249, 0.12);
    border: 1px solid rgba(157, 124, 249, 0.25);
}

nav a.admin-link:hover {
    background: rgba(157, 124, 249, 0.22);
    color: #fff;
    border-color: rgba(157, 124, 249, 0.45);
}

nav span.welcome {
    color: var(--violet);
    font-weight: 600;
}

nav span.admin-badge {
    background: rgba(157, 124, 249, 0.18);
    border: 1px solid rgba(157, 124, 249, 0.35);
    color: var(--violet);
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    padding: 2px 7px;
    border-radius: 6px;
    margin-left: 6px;
    display: inline-block;
}

.container { max-width: 1180px; margin: 0 auto; padding: 50px 48px 100px; }

.page-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 16px;
    margin-bottom: 36px;
    opacity: 0;
    animation: rise 0.6s ease-out forwards;
}

h2 { font-family: var(--font-display); font-size: 28px; font-weight: 700; margin: 0; }

a.btn {
    padding: 11px 22px;
    text-decoration: none;
    border-radius: 100px;
    font-weight: 600;
    font-size: 14px;
    color: var(--bg);
    background: var(--text);
    transition: transform 0.25s ease, background 0.25s ease, color 0.25s ease, box-shadow 0.25s ease;
}

a.btn:hover {
    background: var(--violet);
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 12px 30px -8px rgba(157,124,249,0.55);
}

.cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 22px;
}

.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    overflow: hidden;
    transition: transform 0.3s ease, border-color 0.3s ease, background 0.3s ease;
    opacity: 0;
    transform: translateY(16px);
    animation: rise 0.55s ease-out forwards;
}

.card:hover {
    transform: translateY(-5px);
    border-color: rgba(157,124,249,0.4);
    background: var(--surface-hover);
}

.card .thumb { width: 100%; height: 170px; object-fit: cover; display: block; border-bottom: 1px solid var(--border); }

.card .thumb-fallback {
    width: 100%; height: 170px;
    display: flex; align-items: center; justify-content: center;
    font-size: 36px;
    background: linear-gradient(135deg, rgba(157,124,249,0.15), rgba(240,114,156,0.15));
    border-bottom: 1px solid var(--border);
}

.card-body { padding: 18px 20px; text-align: left; }

.card-body h3 { margin: 0 0 10px; font-family: var(--font-display); font-size: 17px; font-weight: 600; }

.card-body p { margin: 4px 0; color: var(--text-dim); font-size: 13px; }
.card-body p strong { color: var(--text); font-weight: 500; }

.actions { margin-top: 16px; display: flex; gap: 10px; }

.actions a {
    flex: 1;
    text-align: center;
    padding: 9px 0;
    border-radius: 100px;
    font-weight: 600;
    font-size: 13px;
    text-decoration: none;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}

.actions a.edit { background: rgba(108,160,242,0.15); color: var(--blue); border: 1px solid rgba(108,160,242,0.3); }
.actions a.edit:hover { background: var(--blue); color: #0c2240; transform: translateY(-1px); }

.actions a.details { background: rgba(157,124,249,0.15); color: #CBB8FC; border: 1px solid rgba(157,124,249,0.3); }
.actions a.details:hover { background: var(--violet); color: #fff; transform: translateY(-1px); }

.actions a.delete { background: rgba(239,107,107,0.15); color: var(--red); border: 1px solid rgba(239,107,107,0.3); }
.actions a.delete:hover { background: var(--red); color: #2e0a0a; transform: translateY(-1px); }

.empty-state { text-align: center; padding: 60px 20px; color: var(--text-dim); }
.empty-state .icon { font-size: 40px; margin-bottom: 12px; display: block; }

.footer { text-align: center; padding: 28px; border-top: 1px solid var(--border); color: var(--text-dim); font-size: 13px; }

/* ---- Commission & earnings ---- */
:root { --amber: #F2C572; --green: #6FCF97; }

.earnings {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 16px;
    margin-bottom: 22px;
    opacity: 0;
    animation: rise 0.55s 0.08s ease-out forwards;
}

.earn-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 16px;
    padding: 18px 20px;
}

.earn-card .label {
    font-size: 12px; font-weight: 600; letter-spacing: 0.5px;
    text-transform: uppercase; color: var(--text-dim); margin-bottom: 8px;
}

.earn-card .value { font-family: var(--font-display); font-size: 22px; font-weight: 700; }
.earn-card .value small { font-size: 12px; font-weight: 600; color: var(--text-dim); margin-right: 4px; }
.earn-card .hint { font-size: 12px; color: var(--text-dim); margin-top: 6px; }

.earn-card.gold { background: linear-gradient(135deg, rgba(242,197,114,0.13), rgba(20,18,26,0.4)); border-color: rgba(242,197,114,0.32); }
.earn-card.gold .label, .earn-card.gold .value { color: var(--amber); }
.earn-card.green { background: linear-gradient(135deg, rgba(111,207,151,0.12), rgba(20,18,26,0.4)); border-color: rgba(111,207,151,0.3); }
.earn-card.green .label, .earn-card.green .value { color: var(--green); }

.split-bar {
    display: flex; height: 6px; border-radius: 100px; overflow: hidden;
    background: rgba(255,255,255,0.06); margin-top: 10px;
}
.split-bar .org { background: var(--green); }
.split-bar .th  { background: var(--amber); }

.default-panel {
    display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px;
    background: linear-gradient(135deg, rgba(242,197,114,0.08), rgba(157,124,249,0.06));
    border: 1px solid rgba(242,197,114,0.28);
    border-radius: 16px; padding: 18px 22px; margin-bottom: 36px;
    opacity: 0; animation: rise 0.55s 0.14s ease-out forwards;
}
.default-panel h4 { margin: 0 0 4px; font-family: var(--font-display); font-size: 15px; color: var(--amber); }
.default-panel p { margin: 0; font-size: 13px; color: var(--text-dim); }
.default-panel form { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.default-panel .check { font-size: 13px; color: var(--text-dim); display: flex; align-items: center; gap: 6px; cursor: pointer; }

.commission-input {
    display: flex; border: 1px solid var(--border); border-radius: 10px; overflow: hidden;
    background: rgba(255,255,255,0.05);
}
.commission-input select, .commission-input input {
    background: transparent; border: none; color: var(--text); font-family: var(--font-body);
    font-size: 14px; padding: 10px 12px; outline: none;
}
.commission-input select { border-right: 1px solid var(--border); cursor: pointer; }
.commission-input select option { background: #1A1722; }
.commission-input input { width: 110px; }
.commission-input:focus-within { border-color: var(--amber); box-shadow: 0 0 0 3px rgba(242,197,114,0.15); }

.btn-gold {
    padding: 10px 18px; border: none; border-radius: 10px; cursor: pointer;
    background: var(--amber); color: #1A1305; font-family: var(--font-body); font-weight: 700; font-size: 13px;
    transition: transform 0.2s ease, box-shadow 0.2s ease;
}
.btn-gold:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(242,197,114,0.35); }

.commission-box {
    margin-top: 14px; padding: 12px 14px; border-radius: 12px;
    background: rgba(242,197,114,0.06); border: 1px dashed rgba(242,197,114,0.3);
}
.commission-box .row { display: flex; justify-content: space-between; align-items: center; font-size: 12.5px; color: var(--text-dim); margin: 3px 0; }
.commission-box .row b { color: var(--text); font-weight: 600; }
.commission-box .row b.gold { color: var(--amber); }
.commission-box .row b.green { color: var(--green); }
.commission-box .head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
.commission-box .pill {
    font-size: 11px; font-weight: 700; letter-spacing: 0.4px; text-transform: uppercase;
    padding: 3px 9px; border-radius: 100px; color: var(--amber);
    background: rgba(242,197,114,0.15); border: 1px solid rgba(242,197,114,0.4);
}
.commission-box .lock { font-size: 11px; color: var(--text-dim); }
.commission-box button.set {
    font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 100px; cursor: pointer;
    background: transparent; color: var(--amber); border: 1px solid rgba(242,197,114,0.45);
    transition: background 0.2s ease, color 0.2s ease;
}
.commission-box button.set:hover { background: var(--amber); color: #1A1305; }
.commission-box .divider { border-top: 1px solid rgba(255,255,255,0.06); margin: 8px 0 6px; }

/* Modal */
.modal-overlay {
    position: fixed; inset: 0; background: rgba(10,8,14,0.75);
    backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px);
    z-index: 2000; display: none; align-items: center; justify-content: center; padding: 16px;
}
.modal-overlay.active { display: flex; }
.modal-card {
    background: #181521; border: 1px solid rgba(255,255,255,0.12); border-radius: 20px;
    width: 100%; max-width: 440px; padding: 28px; box-shadow: 0 20px 50px rgba(0,0,0,0.6);
    animation: rise 0.25s ease-out;
}
.modal-card h3 { margin: 0 0 4px; font-family: var(--font-display); font-size: 19px; }
.modal-card .sub { margin: 0 0 20px; color: var(--text-dim); font-size: 13px; }
.modal-card .commission-input { width: 100%; }
.modal-card .commission-input input { flex: 1; width: auto; }
.preview {
    margin-top: 16px; padding: 14px 16px; border-radius: 12px;
    background: rgba(255,255,255,0.03); border: 1px solid var(--border); font-size: 13px;
}
.preview .row { display: flex; justify-content: space-between; margin: 4px 0; color: var(--text-dim); }
.preview .row b { color: var(--text); }
.preview .row b.gold { color: var(--amber); }
.preview .row b.green { color: var(--green); }
.modal-actions { display: flex; gap: 10px; margin-top: 20px; }
.modal-actions .btn-gold { flex: 1; padding: 12px; }
.btn-cancel {
    padding: 12px 18px; background: transparent; border: 1px solid var(--border); color: var(--text-dim);
    border-radius: 10px; cursor: pointer; font-family: var(--font-body);
}
.btn-cancel:hover { color: var(--text); border-color: rgba(255,255,255,0.25); }

/* ---- Refund notification ---- */
.refund-bell {
    display: inline-flex; align-items: center; gap: 8px; padding: 7px 8px 7px 14px; border-radius: 100px;
    font-size: 13px; font-weight: 600; text-decoration: none; color: var(--text-dim);
    background: rgba(255,255,255,0.05); border: 1px solid var(--border);
    transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease;
}
.refund-bell .count {
    min-width: 22px; height: 22px; padding: 0 7px; border-radius: 100px; display: inline-flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 700; background: rgba(255,255,255,0.08); color: var(--text-dim);
}
.refund-bell:hover { color: var(--text); border-color: rgba(255,255,255,0.2); }
.refund-bell.has { color: #FFC2D4; background: rgba(240,114,156,0.12); border-color: rgba(240,114,156,0.4); }
.refund-bell.has .count { background: var(--rose); color: #2A0A16; animation: pulse 2s ease-in-out infinite; }
.refund-bell.has:hover { background: rgba(240,114,156,0.22); color: #fff; }
@keyframes pulse { 0%, 100% { box-shadow: 0 0 0 0 rgba(240,114,156,0.5); } 50% { box-shadow: 0 0 0 6px rgba(240,114,156,0); } }

/* ---- Event status box ---- */
.status-box {
    margin-top: 12px; padding: 12px 14px; border-radius: 12px;
    background: rgba(255,255,255,0.03); border: 1px solid var(--border);
}
.status-box .head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.status-box .label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-dim); }
.status-box .note { font-size: 12px; color: var(--text-dim); font-style: italic; margin: -2px 0 8px; }
.st { font-size: 11.5px; font-weight: 700; padding: 3px 9px; border-radius: 100px; border: 1px solid transparent; white-space: nowrap; }
.st-green { color: var(--green); background: rgba(111,207,151,0.12); border-color: rgba(111,207,151,0.35); }
.st-amber { color: var(--amber); background: rgba(242,197,114,0.12); border-color: rgba(242,197,114,0.35); }
.st-blue  { color: var(--blue);  background: rgba(108,160,242,0.12); border-color: rgba(108,160,242,0.35); }
.st-red   { color: #FFA5A5;      background: rgba(239,107,107,0.12); border-color: rgba(239,107,107,0.35); }
.status-row { display: flex; gap: 8px; }
.status-box select, .status-box input {
    background: rgba(255,255,255,0.05); border: 1px solid var(--border); border-radius: 10px; color: var(--text);
    font-family: var(--font-body); font-size: 13px; padding: 8px 10px; outline: none; min-width: 0;
}
.status-box select { flex: 1; cursor: pointer; }
.status-box select option { background: #1A1722; }
.status-box select:focus, .status-box input:focus { border-color: var(--violet); box-shadow: 0 0 0 3px rgba(157,124,249,0.15); }
.status-box input[type="datetime-local"] { color-scheme: dark; }
.status-box .apply {
    padding: 8px 16px; border-radius: 10px; border: none; cursor: pointer; font-family: var(--font-body);
    font-weight: 700; font-size: 13px; background: var(--text); color: var(--bg);
    transition: background 0.2s ease, color 0.2s ease, opacity 0.2s ease;
}
.status-box .apply:hover:not(:disabled) { background: var(--violet); color: #fff; }
.status-box .apply:disabled { opacity: 0.35; cursor: not-allowed; }
.status-box .apply.danger { background: var(--red); color: #2e0a0a; }
.status-box .extra { display: none; flex-direction: column; gap: 8px; margin-top: 8px; }
.status-box.changing .extra { display: flex; }
.status-box .extra .hint { margin: 0; font-size: 12px; color: var(--text-dim); line-height: 1.45; }
.status-box .date-input { display: none; }
.status-box.rescheduling .date-input { display: block; }
.refund-chip {
    display: block; margin: 0 0 8px; font-size: 12px; font-weight: 600; color: #FFC2D4; text-decoration: none;
    padding: 6px 10px; border-radius: 8px; background: rgba(240,114,156,0.1); border: 1px solid rgba(240,114,156,0.3);
}
.refund-chip:hover { background: rgba(240,114,156,0.2); }

@media (max-width: 900px) { .earnings { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 480px) { .earnings { grid-template-columns: 1fr; } }

@keyframes rise { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }

@media (prefers-reduced-motion: reduce) {
    *, .page-head, .card { animation: none !important; opacity: 1 !important; transform: none !important; }
}

@media (max-width: 640px) {
    header { padding: 18px 20px; }
    .container { padding: 36px 20px 70px; }
}
</style>
</head>
<body>
<?php require __DIR__ . "/nav.php"; ?>

<div class="container">
    <?php if ($successMsg): ?>
        <div style="background: rgba(111,207,151,0.12); border: 1px solid rgba(111,207,151,0.35); color: #6FCF97; padding: 12px 18px; border-radius: 12px; margin-bottom: 24px; font-size: 14px;">
            ✅ <?php echo htmlspecialchars($successMsg); ?>
        </div>
    <?php endif; ?>
    <?php if ($errorMsg): ?>
        <div style="background: rgba(239,107,107,0.14); border: 1px solid rgba(239,107,107,0.35); color: #FFA5A5; padding: 12px 18px; border-radius: 12px; margin-bottom: 24px; font-size: 14px;">
            ⚠ <?php echo htmlspecialchars($errorMsg); ?>
        </div>
    <?php endif; ?>

    <div class="page-head">
        <div>
            <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
                <h2><?php echo $isSuperAdmin ? 'Manage All Events' : 'Manage Your Events'; ?></h2>
                <a href="refund_requests.php" class="refund-bell <?php echo $pendingRefunds ? 'has' : ''; ?>"
                   title="<?php echo $pendingRefunds ? "$pendingRefunds refund request(s) waiting for review" : 'No refund requests waiting'; ?>">
                    🔔 Refund requests
                    <span class="count"><?php echo $pendingRefunds; ?></span>
                </a>
            </div>
            <p style="color: var(--text-dim); font-size: 14px; margin: 4px 0 0;">
                <?php echo $isSuperAdmin ? 'Full platform events directory across all administrators.' : 'You can only view and manage events that you have created.'; ?>
            </p>
        </div>
        <a href="add_event.php" class="btn">+ Add new event</a>
    </div>

    <?php $commissionShare = $totals['gross'] > 0 ? $totals['commission'] / $totals['gross'] * 100 : 0; ?>
    <div class="earnings">
        <div class="earn-card">
            <div class="label">🎟️ Tickets sold</div>
            <div class="value"><?php echo number_format($totals['tickets']); ?></div>
            <div class="hint">Across <?php echo count($events); ?> event(s)</div>
        </div>
        <div class="earn-card">
            <div class="label">💳 Gross sales</div>
            <div class="value"><small>LKR</small><?php echo number_format($totals['gross'], 2); ?></div>
            <div class="split-bar" title="Organizer vs TicketHUB share">
                <span class="org" style="width: <?php echo 100 - $commissionShare; ?>%;"></span>
                <span class="th" style="width: <?php echo $commissionShare; ?>%;"></span>
            </div>
        </div>
        <div class="earn-card gold">
            <div class="label">👑 TicketHUB commission</div>
            <div class="value"><small>LKR</small><?php echo number_format($totals['commission'], 2); ?></div>
            <div class="hint"><?php echo number_format($commissionShare, 1); ?>% of gross sales</div>
        </div>
        <div class="earn-card green">
            <div class="label">🏢 <?php echo $isSuperAdmin ? 'Organizer payouts' : 'Your earnings'; ?></div>
            <div class="value"><small>LKR</small><?php echo number_format($totals['payout'], 2); ?></div>
            <div class="hint">Gross sales minus commission</div>
        </div>
    </div>

    <?php if ($isSuperAdmin): ?>
    <div class="default-panel">
        <div>
            <h4>👑 Default TicketHUB commission: <?php echo htmlspecialchars(commission_label($defaultCommission['type'], $defaultCommission['value'])); ?></h4>
            <p>Applied automatically to every new event an admin creates. You can override it per event below.</p>
        </div>
        <form method="POST" action="manage_events.php" onsubmit="return !this.apply_to_all.checked || confirm('Overwrite the commission on ALL existing events? Past bookings keep the commission they were charged.');">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="set_default_commission">
            <div class="commission-input">
                <select name="commission_type" aria-label="Commission type">
                    <option value="percentage" <?php echo $defaultCommission['type'] === 'percentage' ? 'selected' : ''; ?>>% of ticket</option>
                    <option value="fixed" <?php echo $defaultCommission['type'] === 'fixed' ? 'selected' : ''; ?>>LKR per ticket</option>
                </select>
                <input type="number" name="commission_value" min="0" step="0.01" required aria-label="Commission value"
                       value="<?php echo htmlspecialchars((string)$defaultCommission['value']); ?>">
            </div>
            <label class="check"><input type="checkbox" name="apply_to_all" value="1"> Apply to all events</label>
            <button type="submit" class="btn-gold">Save default</button>
        </form>
    </div>
    <?php endif; ?>

    <div class="cards">
    <?php if (count($events) === 0): ?>
        <div class="empty-state" style="grid-column: 1 / -1;">
            <span class="icon">🎫</span>
            <p><?php echo $isSuperAdmin ? 'No events found in the platform.' : 'You haven\'t added any events yet. Click "+ Add new event" to create one.'; ?></p>
        </div>
    <?php else: ?>
        <?php foreach ($events as $index => $event):
            $delay = min($index * 0.06, 0.6);
        ?>
        <div class="card" style="animation-delay: <?php echo $delay; ?>s;">
            <?php if (!empty($event['photo']) && file_exists(__DIR__ . "/eventphotos/" . $event['photo'])): ?>
                <img class="thumb" src="eventphotos/<?php echo htmlspecialchars($event['photo']); ?>" alt="<?php echo htmlspecialchars($event['title']); ?>">
            <?php else: ?>
                <div class="thumb-fallback">🎫</div>
            <?php endif; ?>
            <div class="card-body">
                <h3><a href="event_details.php?id=<?php echo (int)$event['id']; ?>" style="color: inherit; text-decoration: none;"><?php echo htmlspecialchars($event['title']); ?></a></h3>
                <p><strong>Location:</strong> <?php echo htmlspecialchars($event['location']); ?></p>
                <p><strong>Date:</strong> <?php echo date("M d, Y H:i", strtotime($event['event_date'])); ?></p>
                <p><strong>Price:</strong> LKR <?php echo number_format($event['ticket_price'], 2); ?></p>
                <?php if ($isSuperAdmin): ?>
                    <p><strong>Created by:</strong> <?php echo htmlspecialchars($event['creator_name'] ?? 'Unknown'); ?> (<?php echo ($event['creator_role'] === 'super_admin') ? 'Super Admin' : 'Admin'; ?>)</p>
                <?php endif; ?>

                <div class="commission-box">
                    <div class="head">
                        <span class="pill">TicketHUB <?php echo htmlspecialchars(commission_label($event['commission_type'], (float)$event['commission_value'])); ?></span>
                        <?php if ($isSuperAdmin): ?>
                            <button type="button" class="set" onclick="openCommissionModal(<?php echo htmlspecialchars(json_encode([
                                'id'    => (int)$event['id'],
                                'title' => $event['title'],
                                'price' => (float)$event['ticket_price'],
                                'type'  => $event['commission_type'],
                                'value' => (float)$event['commission_value'],
                            ])); ?>)">✎ Set commission</button>
                        <?php else: ?>
                            <span class="lock" title="Commission is set by TicketHUB Super Admins">🔒 Set by TicketHUB</span>
                        <?php endif; ?>
                    </div>
                    <div class="row"><span>Commission / ticket</span><b class="gold">LKR <?php echo number_format($event['commission_per_ticket'], 2); ?></b></div>
                    <div class="row"><span>Organizer gets / ticket</span><b class="green">LKR <?php echo number_format((float)$event['ticket_price'] - $event['commission_per_ticket'], 2); ?></b></div>
                    <div class="divider"></div>
                    <div class="row"><span>Sold (confirmed)</span><b><?php echo (int)$event['sold_confirmed']; ?> / <?php echo (int)$event['total_tickets']; ?></b></div>
                    <div class="row"><span>Commission earned</span><b class="gold">LKR <?php echo number_format((float)$event['commission_earned'], 2); ?></b></div>
                    <div class="row"><span>Organizer payout</span><b class="green">LKR <?php echo number_format($event['payout'], 2); ?></b></div>
                </div>

                <?php
                    $statusKey  = event_status_of($event);
                    $statusMeta = EVENT_STATUSES[$statusKey];
                    $eventRefunds = $refundsByEvent[(int)$event['id']] ?? 0;
                ?>
                <form class="status-box" method="POST" action="manage_events.php" data-title="<?php echo htmlspecialchars($event['title']); ?>" data-current="<?php echo $statusKey; ?>" data-sold="<?php echo (int)$event['sold_confirmed']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="set_event_status">
                    <input type="hidden" name="event_id" value="<?php echo (int)$event['id']; ?>">
                    <div class="head">
                        <span class="label">Event status</span>
                        <span class="st st-<?php echo $statusMeta['tone']; ?>"><?php echo $statusMeta['icon'] . ' ' . $statusMeta['label']; ?></span>
                    </div>
                    <?php if (!empty($event['status_note'])): ?>
                        <div class="note">“<?php echo htmlspecialchars($event['status_note']); ?>”</div>
                    <?php endif; ?>
                    <?php if (isset($cancelledOwing[(int)$event['id']])): $owing = $cancelledOwing[(int)$event['id']]; ?>
                        <a class="refund-chip" href="refund_requests.php?status=all&event_id=<?php echo (int)$event['id']; ?>">↩ Refund everyone · <?php echo $owing['bookings']; ?> booking(s), LKR <?php echo number_format($owing['amount'], 2); ?> →</a>
                    <?php endif; ?>
                    <?php if ($eventRefunds): ?>
                        <a class="refund-chip" href="refund_requests.php?event_id=<?php echo (int)$event['id']; ?>">🔔 <?php echo $eventRefunds; ?> refund request<?php echo $eventRefunds === 1 ? '' : 's'; ?> waiting →</a>
                    <?php endif; ?>
                    <div class="status-row">
                        <select name="event_status" aria-label="New status for <?php echo htmlspecialchars($event['title']); ?>">
                            <?php foreach (EVENT_STATUSES as $key => $meta): ?>
                                <option value="<?php echo $key; ?>" <?php echo $key === $statusKey ? 'selected' : ''; ?> title="<?php echo htmlspecialchars($meta['hint']); ?>"><?php echo $meta['icon'] . ' ' . $meta['label']; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="apply">Apply</button>
                    </div>
                    <div class="extra">
                        <p class="hint"></p>
                        <input type="datetime-local" name="new_date" class="date-input" aria-label="New date and time"
                               min="<?php echo date('Y-m-d\TH:i'); ?>" value="<?php echo date('Y-m-d\TH:i', strtotime($event['event_date'])); ?>">
                        <input type="text" name="status_note" maxlength="255" class="note-input" placeholder="Reason shown to customers">
                    </div>
                </form>

                <div class="actions">
                    <a href="event_details.php?id=<?php echo (int)$event['id']; ?>" class="details">Details</a>
                    <a href="edit_event.php?id=<?php echo (int)$event['id']; ?>" class="edit">Edit</a>
                    <a href="delete_event.php?id=<?php echo (int)$event['id']; ?>" class="delete" onclick="return confirm('Delete this event? This cannot be undone.');">Delete</a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
    </div>
</div>

<?php if ($isSuperAdmin): ?>
<div class="modal-overlay" id="commissionModal" onclick="if (event.target === this) closeCommissionModal();">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="cm_title">
        <h3 id="cm_title">👑 Set TicketHUB commission</h3>
        <p class="sub" id="cm_event"></p>
        <form method="POST" action="manage_events.php">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
            <input type="hidden" name="action" value="set_event_commission">
            <input type="hidden" name="event_id" id="cm_id">
            <div class="commission-input">
                <select name="commission_type" id="cm_type" aria-label="Commission type">
                    <option value="percentage">% of ticket</option>
                    <option value="fixed">LKR per ticket</option>
                </select>
                <input type="number" name="commission_value" id="cm_value" min="0" step="0.01" required aria-label="Commission value">
            </div>
            <div class="preview">
                <div class="row"><span>Ticket price</span><b id="pv_price"></b></div>
                <div class="row"><span>TicketHUB takes</span><b class="gold" id="pv_comm"></b></div>
                <div class="row"><span>Organizer receives</span><b class="green" id="pv_org"></b></div>
            </div>
            <p class="sub" style="margin: 12px 0 0;">Applies to new bookings. Past bookings keep the commission they were charged.</p>
            <div class="modal-actions">
                <button type="submit" class="btn-gold">Save commission</button>
                <button type="button" class="btn-cancel" onclick="closeCommissionModal();">Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
let cmPrice = 0;
const lkr = n => 'LKR ' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function updateCommissionPreview() {
    const type  = document.getElementById('cm_type').value;
    const value = Math.max(0, parseFloat(document.getElementById('cm_value').value) || 0);
    // Mirrors commission_per_ticket() in config/commission.php
    const comm = cmPrice <= 0 ? 0 : Math.min(type === 'fixed' ? value : cmPrice * value / 100, cmPrice);
    document.getElementById('pv_price').textContent = lkr(cmPrice);
    document.getElementById('pv_comm').textContent  = lkr(comm);
    document.getElementById('pv_org').textContent   = lkr(cmPrice - comm);
}

function openCommissionModal(ev) {
    cmPrice = ev.price;
    document.getElementById('cm_id').value = ev.id;
    document.getElementById('cm_event').textContent = ev.title;
    document.getElementById('cm_type').value = ev.type;
    document.getElementById('cm_value').value = ev.value;
    updateCommissionPreview();
    document.getElementById('commissionModal').classList.add('active');
    document.getElementById('cm_value').focus();
}

function closeCommissionModal() {
    document.getElementById('commissionModal').classList.remove('active');
}

document.getElementById('cm_type').addEventListener('change', updateCommissionPreview);
document.getElementById('cm_value').addEventListener('input', updateCommissionPreview);
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeCommissionModal(); });
</script>
<?php endif; ?>

<script>
// ---- Event status dropdown: explain the choice, ask for what it needs, confirm risky ones ----
const STATUS_INFO = <?php echo json_encode(array_map(fn($m) => ['label' => $m['label'], 'hint' => $m['hint']], EVENT_STATUSES)); ?>;
document.querySelectorAll('.status-box').forEach(form => {
    const select = form.querySelector('select');
    const apply  = form.querySelector('.apply');
    const hint   = form.querySelector('.hint');
    const note   = form.querySelector('.note-input');
    const date   = form.querySelector('.date-input');
    const current = form.dataset.current;

    function sync() {
        const value = select.value;
        // Re-rescheduling = keep "Rescheduled" selected and just pick another date
        const changing = value !== current || (value === 'rescheduled' && date.value !== date.defaultValue);
        form.classList.toggle('changing', changing || value === 'rescheduled');
        form.classList.toggle('rescheduling', value === 'rescheduled');
        apply.disabled = !changing;
        apply.classList.toggle('danger', value === 'cancelled' && value !== current);
        hint.textContent = STATUS_INFO[value].hint + (value === 'rescheduled' ? ' Pick the new date and time:' : '');
        note.required = value === 'postponed' || value === 'cancelled';
        note.placeholder = note.required ? 'Reason shown to customers (required)' : 'Message for customers (optional)';
        date.required = value === 'rescheduled';
    }
    select.addEventListener('change', sync);
    date.addEventListener('input', sync);
    sync();

    form.addEventListener('submit', e => {
        const value = select.value, sold = parseInt(form.dataset.sold, 10) || 0;
        const who = sold ? `\n\n${sold} ticket(s) have been sold — those customers will be able to request refunds.` : '';
        let question = null;
        if (value === 'cancelled') {
            question = `Cancel "${form.dataset.title}"?\n\nTicket sales stop immediately.${who}`;
        } else if (value === 'postponed') {
            question = `Postpone "${form.dataset.title}"?\n\nTicket sales stop until you reschedule it.${who}`;
        } else if (value === 'rescheduled') {
            question = `Move "${form.dataset.title}" to ${new Date(date.value).toLocaleString()}?${who}`;
        } else if (current === 'cancelled') {
            question = `"${form.dataset.title}" is cancelled. Set it to "${STATUS_INFO[value].label}" again?`;
        }
        if (question && !confirm(question)) {
            e.preventDefault();
            return;
        }
        apply.disabled = true;
        apply.textContent = 'Saving…';
    });
});
</script>

<?php require __DIR__ . "/footer.php"; ?>
</body>
</html>