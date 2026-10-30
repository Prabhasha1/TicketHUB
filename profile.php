<?php
/**
 * profile.php
 * ------------------------------------------------------------
 * "My profile" for any logged-in user (built for customers):
 *   - account summary (tickets, events attended, spend, loyalty points)
 *   - edit name / mobile / email (email change needs the current password)
 *   - change password
 *   - favourite categories + recent loyalty point history
 * ------------------------------------------------------------
 */
session_start();
require __DIR__ . "/config/database.php";
require __DIR__ . "/config/app.php";
require_once __DIR__ . "/config/loyalty.php";

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?error=" . urlencode("Please log in to see your profile."));
    exit;
}

$userId    = (int)$_SESSION['user_id'];
$csrfToken = csrf_token();

function load_user(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare("SELECT id, name, email, phone, password, role, loyalty_points, created_at FROM users WHERE id = ?");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $user ?: null;
}

function back_to_profile(string $key, string $message, string $anchor = ''): void
{
    header("Location: profile.php?" . http_build_query([$key => $message]) . ($anchor ? "#$anchor" : ''));
    exit;
}

$user = load_user($conn, $userId);
if (!$user) {
    session_destroy();
    header("Location: login.php");
    exit;
}

// ---- Save changes ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valid($_POST['csrf_token'] ?? null)) {
        back_to_profile('error', "Your session expired. Please try again.");
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'update_details') {
        $name     = trim($_POST['name'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $phoneRaw = trim($_POST['phone'] ?? '');
        $phone    = $phoneRaw === '' ? null : phone_normalize($phoneRaw);

        if ($name === '' || mb_strlen($name) > 100) {
            back_to_profile('error', "Please enter your name (up to 100 characters).", 'details');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150) {
            back_to_profile('error', "Please enter a valid email address.", 'details');
        }
        if ($phoneRaw !== '' && $phone === null) {
            back_to_profile('error', "Please enter a valid mobile number (e.g. 077 123 4567), or leave it blank.", 'details');
        }

        $emailChanged = strcasecmp($email, $user['email']) !== 0;
        if ($emailChanged) {
            // Changing the login email is sensitive — confirm it's really the account owner
            if (!password_verify($_POST['current_password'] ?? '', $user['password'])) {
                back_to_profile('error', "To change your email, enter your current password. Signed up with Google? Set a password first with \"Forgot password\".", 'details');
            }
            $check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
            $check->bind_param("si", $email, $userId);
            $check->execute();
            $taken = $check->get_result()->num_rows > 0;
            $check->close();
            if ($taken) {
                back_to_profile('error', "Another account already uses that email address.", 'details');
            }
        }

        if ($name === $user['name'] && !$emailChanged && $phone === $user['phone']) {
            back_to_profile('msg', "Nothing to update — your details are already saved.", 'details');
        }

        $upd = $conn->prepare("UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?");
        $upd->bind_param("sssi", $name, $email, $phone, $userId);
        $upd->execute();
        $upd->close();
        $_SESSION['user_name'] = $name;

        back_to_profile('msg', "Your details were saved." . ($emailChanged ? " Use $email to log in from now on." : ''), 'details');
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $user['password'])) {
            back_to_profile('error', "Your current password is not correct. Signed up with Google? Use \"Forgot password\" to set one.", 'password');
        }
        if (strlen($new) < 8) {
            back_to_profile('error', "Your new password must be at least 8 characters.", 'password');
        }
        if ($new !== $confirm) {
            back_to_profile('error', "The two new passwords don't match.", 'password');
        }
        if (password_verify($new, $user['password'])) {
            back_to_profile('error', "Your new password must be different from the current one.", 'password');
        }

        $hash = password_hash($new, PASSWORD_DEFAULT);
        $upd = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $upd->bind_param("si", $hash, $userId);
        $upd->execute();
        $upd->close();
        session_regenerate_id(true);

        back_to_profile('msg', "Password changed. Use your new password next time you log in.", 'password');
    }

    back_to_profile('error', "Unknown action.");
}

$successMsg = trim($_GET['msg'] ?? '');
$errorMsg   = trim($_GET['error'] ?? '');

// ---- Summary ----
$summary = ['bookings' => 0, 'tickets' => 0, 'spent' => 0.0, 'attended' => 0, 'upcoming' => 0];
$nextEvent = null;
$favourites = [];
$pointsHistory = [];
try {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS bookings, COALESCE(SUM(b.quantity), 0) AS tickets, COALESCE(SUM(b.total_price), 0) AS spent,
                COALESCE(SUM(b.checked_in_at IS NOT NULL), 0) AS attended,
                COALESCE(SUM(e.event_date >= NOW()), 0) AS upcoming
         FROM bookings b JOIN events e ON e.id = b.event_id
         WHERE b.user_id = ? AND b.status = 'confirmed'"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $summary = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare(
        "SELECT e.id, e.title, e.event_date, e.location, b.quantity FROM bookings b JOIN events e ON e.id = b.event_id
         WHERE b.user_id = ? AND b.status = 'confirmed' AND e.event_date >= NOW()
         ORDER BY e.event_date ASC LIMIT 1"
    );
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $nextEvent = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("SELECT category, score FROM user_category_affinity WHERE user_id = ? AND score > 0 ORDER BY score DESC LIMIT 5");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $favourites = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt = $conn->prepare("SELECT points, reason, created_at FROM loyalty_transactions WHERE user_id = ? ORDER BY created_at DESC, id DESC LIMIT 8");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $pointsHistory = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
} catch (mysqli_sql_exception $e) {
    error_log("profile summary failed: " . $e->getMessage());
}

$initials = '';
foreach (array_slice(preg_split('/\s+/', trim($user['name'])), 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}
$roleLabel = ['super_admin' => '👑 Super Admin', 'admin' => 'Admin', 'customer' => 'Customer'][$user['role']] ?? $user['role'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . "/pwa_head.php"; ?>
<title>My Profile - TicketHub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #14121A; --surface: rgba(255,255,255,0.04); --border: rgba(255,255,255,0.08);
    --text: #F1EEF7; --text-dim: #A9A3B8; --text-muted: #7D7790;
    --violet: #9D7CF9; --rose: #F0729C; --blue: #6CA0F2; --red: #EF6B6B; --amber: #F2C572; --green: #6FCF97;
    --font-display: 'Sora', 'Segoe UI', sans-serif; --font-body: 'Inter', 'Segoe UI', sans-serif;
}
* { box-sizing: border-box; }
body { margin: 0; font-family: var(--font-body); background: var(--bg); color: var(--text); }
.container { max-width: 980px; margin: 0 auto; padding: 40px 48px 100px; }

.flash { padding: 12px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; }
.flash.ok  { background: rgba(111,207,151,0.12); border: 1px solid rgba(111,207,151,0.35); color: var(--green); }
.flash.err { background: rgba(239,107,107,0.14); border: 1px solid rgba(239,107,107,0.35); color: #FFA5A5; }

.hero {
    display: flex; align-items: center; gap: 22px; flex-wrap: wrap;
    padding: 24px; border-radius: 20px; margin-bottom: 22px;
    background: linear-gradient(135deg, rgba(157,124,249,0.16), rgba(240,114,156,0.08)); border: 1px solid rgba(157,124,249,0.3);
    animation: rise 0.5s ease-out both;
}
.avatar {
    width: 78px; height: 78px; border-radius: 50%; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
    font-family: var(--font-display); font-size: 28px; font-weight: 700; color: #fff;
    background: linear-gradient(135deg, var(--violet), var(--rose)); box-shadow: 0 10px 30px -10px rgba(157,124,249,0.7);
}
.hero h1 { font-family: var(--font-display); font-size: 26px; margin: 0 0 4px; }
.hero .sub { color: var(--text-dim); font-size: 14px; display: flex; gap: 6px 14px; flex-wrap: wrap; }
.hero .points {
    margin-left: auto; text-align: center; padding: 12px 20px; border-radius: 16px;
    background: rgba(20,18,26,0.5); border: 1px solid rgba(157,124,249,0.35);
}
.hero .points b { display: block; font-family: var(--font-display); font-size: 26px; color: var(--violet); }
.hero .points span { font-size: 12px; color: var(--text-dim); }

.stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 22px; animation: rise 0.5s 0.05s ease-out both; }
.stat { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 16px 18px; }
.stat .label { font-size: 12px; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; color: var(--text-dim); margin-bottom: 6px; }
.stat .value { font-family: var(--font-display); font-size: 22px; font-weight: 700; }
.stat .value small { font-size: 12px; color: var(--text-dim); margin-right: 3px; }

.next {
    display: flex; justify-content: space-between; align-items: center; gap: 14px; flex-wrap: wrap;
    padding: 16px 20px; border-radius: 16px; margin-bottom: 22px; font-size: 14px;
    background: rgba(108,160,242,0.08); border: 1px solid rgba(108,160,242,0.3);
}
.next b { font-family: var(--font-display); }
.next span { color: var(--text-dim); font-size: 13px; }
.next a { color: var(--blue); font-weight: 600; text-decoration: none; white-space: nowrap; }

.grid { display: grid; grid-template-columns: 1.2fr 1fr; gap: 22px; align-items: start; }
.panel { background: var(--surface); border: 1px solid var(--border); border-radius: 18px; padding: 22px; margin-bottom: 22px; animation: rise 0.5s 0.1s ease-out both; }
.panel h2 { font-family: var(--font-display); font-size: 17px; margin: 0 0 4px; }
.panel .lead { color: var(--text-dim); font-size: 13px; margin: 0 0 18px; }

.field { margin-bottom: 14px; }
.field label { display: block; font-size: 12.5px; font-weight: 600; color: var(--text-dim); margin-bottom: 6px; }
.field input {
    width: 100%; background: rgba(255,255,255,0.05); border: 1px solid var(--border); border-radius: 10px; color: var(--text);
    font-family: var(--font-body); font-size: 14px; padding: 11px 13px; outline: none; transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.field input:focus { border-color: var(--violet); box-shadow: 0 0 0 3px rgba(157,124,249,0.15); }
.field .help { font-size: 12px; color: var(--text-muted); margin-top: 5px; }
.field.confirm-email { display: none; padding: 12px; border-radius: 12px; background: rgba(242,197,114,0.07); border: 1px solid rgba(242,197,114,0.3); }
.field.confirm-email.show { display: block; }
.field.confirm-email label { color: var(--amber); }
.pw-wrap { position: relative; }
.pw-wrap input { padding-right: 64px; }
.pw-wrap button {
    position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
    background: none; border: none; color: var(--text-dim); font-family: var(--font-body); font-size: 12px; font-weight: 600; cursor: pointer;
}
.strength { height: 5px; border-radius: 100px; background: rgba(255,255,255,0.07); margin-top: 8px; overflow: hidden; }
.strength span { display: block; height: 100%; width: 0; border-radius: 100px; transition: width 0.25s ease, background 0.25s ease; }

.btn {
    padding: 11px 22px; border-radius: 100px; border: none; cursor: pointer; font-family: var(--font-body);
    font-weight: 700; font-size: 14px; background: var(--text); color: var(--bg); transition: background 0.2s ease, color 0.2s ease, transform 0.2s ease;
}
.btn:hover:not(:disabled) { background: var(--violet); color: #fff; transform: translateY(-1px); }
.btn:disabled { opacity: 0.4; cursor: not-allowed; }
.forgot { font-size: 12.5px; color: var(--text-dim); margin-left: 12px; }
.forgot a { color: var(--violet); }

.chips { display: flex; gap: 8px; flex-wrap: wrap; }
.chip { padding: 6px 12px; border-radius: 100px; font-size: 13px; font-weight: 600; color: #CBB8FC; background: rgba(157,124,249,0.12); border: 1px solid rgba(157,124,249,0.3); }
.rows .row { display: flex; justify-content: space-between; gap: 10px; padding: 9px 0; border-bottom: 1px solid rgba(255,255,255,0.05); font-size: 13px; }
.rows .row:last-child { border-bottom: none; }
.rows .row span { color: var(--text-dim); }
.rows .row small { display: block; color: var(--text-muted); font-size: 11.5px; }
.plus { color: var(--green); font-weight: 700; white-space: nowrap; }
.minus { color: var(--rose); font-weight: 700; white-space: nowrap; }
.empty { color: var(--text-dim); font-size: 13px; }

.footer { text-align: center; padding: 28px; border-top: 1px solid var(--border); color: var(--text-dim); font-size: 13px; }
@keyframes rise { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
@media (prefers-reduced-motion: reduce) { * { animation: none !important; transition: none !important; } }
@media (max-width: 860px) { .grid { grid-template-columns: 1fr; } .stats { grid-template-columns: repeat(2, 1fr); } .hero .points { margin-left: 0; } }
@media (max-width: 640px) { .container { padding: 24px 16px 70px; } }
</style>
</head>
<body>
<?php require __DIR__ . "/nav.php"; ?>

<div class="container">
    <?php if ($successMsg): ?><div class="flash ok">✅ <?php echo htmlspecialchars($successMsg); ?></div><?php endif; ?>
    <?php if ($errorMsg): ?><div class="flash err">⚠ <?php echo htmlspecialchars($errorMsg); ?></div><?php endif; ?>

    <section class="hero">
        <div class="avatar" aria-hidden="true"><?php echo htmlspecialchars($initials ?: '🙂'); ?></div>
        <div>
            <h1><?php echo htmlspecialchars($user['name']); ?></h1>
            <div class="sub">
                <span>✉ <?php echo htmlspecialchars($user['email']); ?></span>
                <?php if ($user['phone']): ?><span>📱 <?php echo htmlspecialchars(phone_display($user['phone'])); ?></span><?php endif; ?>
                <span>🗓 Member since <?php echo date('M Y', strtotime($user['created_at'])); ?></span>
                <?php if ($user['role'] !== 'customer'): ?><span><?php echo $roleLabel; ?></span><?php endif; ?>
            </div>
        </div>
        <div class="points">
            <b><?php echo number_format((int)$user['loyalty_points']); ?></b>
            <span>🏆 loyalty points</span>
        </div>
    </section>

    <section class="stats">
        <div class="stat"><div class="label">🎟 Tickets</div><div class="value"><?php echo number_format((int)$summary['tickets']); ?></div></div>
        <div class="stat"><div class="label">📅 Upcoming</div><div class="value"><?php echo number_format((int)$summary['upcoming']); ?></div></div>
        <div class="stat"><div class="label">✅ Attended</div><div class="value"><?php echo number_format((int)$summary['attended']); ?></div></div>
        <div class="stat"><div class="label">💳 Spent</div><div class="value"><small>LKR</small><?php echo number_format((float)$summary['spent'], 2); ?></div></div>
    </section>

    <?php if ($nextEvent): ?>
        <div class="next">
            <div>🎉 Next up: <b><?php echo htmlspecialchars($nextEvent['title']); ?></b><br>
                <span><?php echo date('l, M d · h:i A', strtotime($nextEvent['event_date'])); ?> · <?php echo htmlspecialchars($nextEvent['location']); ?> · <?php echo (int)$nextEvent['quantity']; ?> ticket(s)</span></div>
            <a href="my_tickets.php">View my tickets →</a>
        </div>
    <?php endif; ?>

    <div class="grid">
        <div>
            <section class="panel" id="details">
                <h2>👤 Personal details</h2>
                <p class="lead">Your e-tickets are sent to this email and mobile number.</p>
                <form method="POST" id="detailsForm" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="update_details">
                    <div class="field">
                        <label for="name">Full name</label>
                        <input type="text" id="name" name="name" maxlength="100" required autocomplete="name" value="<?php echo htmlspecialchars($user['name']); ?>">
                    </div>
                    <div class="field">
                        <label for="phone">Mobile number</label>
                        <input type="tel" id="phone" name="phone" maxlength="20" autocomplete="tel" placeholder="077 123 4567" value="<?php echo htmlspecialchars($user['phone'] ? phone_display($user['phone']) : ''); ?>">
                        <div class="help">Sri Lankan numbers in any format, or +country code. Used for SMS tickets.</div>
                    </div>
                    <div class="field">
                        <label for="email">Email address</label>
                        <input type="email" id="email" name="email" maxlength="150" required autocomplete="email" value="<?php echo htmlspecialchars($user['email']); ?>" data-original="<?php echo htmlspecialchars($user['email']); ?>">
                        <div class="help">This is also your login. If you sign in with Google, keep it the same as your Google email.</div>
                    </div>
                    <div class="field confirm-email" id="confirmEmail">
                        <label for="email_password">🔒 Confirm with your current password to change your email</label>
                        <div class="pw-wrap">
                            <input type="password" id="email_password" name="current_password" autocomplete="current-password">
                            <button type="button" class="toggle">Show</button>
                        </div>
                    </div>
                    <button type="submit" class="btn" id="detailsBtn" disabled>Save changes</button>
                </form>
            </section>

            <section class="panel" id="password">
                <h2>🔑 Change password</h2>
                <p class="lead">Use at least 8 characters. A mix of letters, numbers and symbols is strongest.</p>
                <form method="POST" id="passwordForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="change_password">
                    <div class="field">
                        <label for="current_password">Current password</label>
                        <div class="pw-wrap"><input type="password" id="current_password" name="current_password" required autocomplete="current-password"><button type="button" class="toggle">Show</button></div>
                    </div>
                    <div class="field">
                        <label for="new_password">New password</label>
                        <div class="pw-wrap"><input type="password" id="new_password" name="new_password" minlength="8" required autocomplete="new-password"><button type="button" class="toggle">Show</button></div>
                        <div class="strength"><span id="strengthBar"></span></div>
                        <div class="help" id="strengthText">At least 8 characters.</div>
                    </div>
                    <div class="field">
                        <label for="confirm_password">Confirm new password</label>
                        <div class="pw-wrap"><input type="password" id="confirm_password" name="confirm_password" minlength="8" required autocomplete="new-password"><button type="button" class="toggle">Show</button></div>
                        <div class="help" id="matchText"></div>
                    </div>
                    <button type="submit" class="btn">Update password</button>
                    <span class="forgot">Signed up with Google? <a href="forgot_password.php">Set a password</a></span>
                </form>
            </section>
        </div>

        <div>
            <section class="panel">
                <h2>❤️ What you like</h2>
                <p class="lead">Based on the events you book — we use it to recommend events.</p>
                <?php if ($favourites): ?>
                    <div class="chips">
                        <?php foreach ($favourites as $f): ?><span class="chip"><?php echo htmlspecialchars($f['category']); ?></span><?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="empty">Book your first event and we'll learn what you enjoy. <a href="events.php" style="color: var(--violet);">Browse events →</a></p>
                <?php endif; ?>
            </section>

            <section class="panel">
                <h2>🏆 Loyalty points</h2>
                <p class="lead">Earn 1 point for every LKR <?php echo LOYALTY_LKR_PER_POINT; ?> you spend. At checkout, each point = <?php echo LOYALTY_PERCENT_PER_POINT; ?>% off (up to <?php echo LOYALTY_MAX_DISCOUNT_PERCENT; ?>% per order).</p>
                <?php if ($pointsHistory): ?>
                    <div class="rows">
                        <?php foreach ($pointsHistory as $p): ?>
                            <div class="row">
                                <span><?php echo htmlspecialchars($p['reason']); ?><small><?php echo date('M d, Y', strtotime($p['created_at'])); ?></small></span>
                                <b class="<?php echo (int)$p['points'] >= 0 ? 'plus' : 'minus'; ?>"><?php echo ((int)$p['points'] >= 0 ? '+' : '') . (int)$p['points']; ?></b>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <p class="empty">No points yet.</p>
                <?php endif; ?>
            </section>
        </div>
    </div>
</div>

<?php require __DIR__ . "/footer.php"; ?>

<script>
// Show/hide password fields
document.querySelectorAll('.pw-wrap .toggle').forEach(btn => btn.addEventListener('click', () => {
    const input = btn.previousElementSibling;
    input.type = input.type === 'password' ? 'text' : 'password';
    btn.textContent = input.type === 'password' ? 'Show' : 'Hide';
}));

// Details: enable Save only when something changed; ask for the password when the email changes
(function () {
    const form = document.getElementById('detailsForm');
    const btn = document.getElementById('detailsBtn');
    const email = document.getElementById('email');
    const confirmBox = document.getElementById('confirmEmail');
    const fields = [...form.querySelectorAll('#name, #phone, #email')];
    const initial = fields.map(f => f.value.trim());
    function sync() {
        const changed = fields.some((f, i) => f.value.trim() !== initial[i]);
        const emailChanged = email.value.trim().toLowerCase() !== email.dataset.original.toLowerCase();
        confirmBox.classList.toggle('show', emailChanged);
        document.getElementById('email_password').required = emailChanged;
        btn.disabled = !changed;
    }
    fields.forEach(f => f.addEventListener('input', sync));
    form.addEventListener('submit', e => {
        if (!form.checkValidity()) { e.preventDefault(); form.reportValidity(); }
    });
})();

// Password strength + match
(function () {
    const pw = document.getElementById('new_password'), confirmPw = document.getElementById('confirm_password');
    const bar = document.getElementById('strengthBar'), text = document.getElementById('strengthText'), match = document.getElementById('matchText');
    const levels = [
        ['Too short', '#EF6B6B', 15], ['Weak', '#EF6B6B', 35], ['Okay', '#F2C572', 60], ['Good', '#6CA0F2', 80], ['Strong', '#6FCF97', 100]
    ];
    function score(v) {
        if (v.length < 8) return 0;
        let s = 1;
        if (v.length >= 12) s++;
        if (/[a-z]/.test(v) && /[A-Z]/.test(v)) s++;
        if (/\d/.test(v) && /[^A-Za-z0-9]/.test(v)) s++;
        return Math.min(s, 4);
    }
    function sync() {
        const v = pw.value;
        if (!v) { bar.style.width = '0'; text.textContent = 'At least 8 characters.'; }
        else { const [label, color, width] = levels[score(v)]; bar.style.width = width + '%'; bar.style.background = color; text.textContent = label; }
        match.textContent = confirmPw.value ? (confirmPw.value === v ? '✓ Passwords match' : '✕ Passwords don\'t match') : '';
        match.style.color = confirmPw.value === v ? '#6FCF97' : '#FFA5A5';
        confirmPw.setCustomValidity(confirmPw.value && confirmPw.value !== v ? "Passwords don't match" : '');
    }
    pw.addEventListener('input', sync);
    confirmPw.addEventListener('input', sync);
})();
</script>
</body>
</html>
