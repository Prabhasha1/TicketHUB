<?php
session_start();
require __DIR__ . "/config/database.php";
require __DIR__ . "/config/brevo_client.php";

$hostHeader = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = in_array($hostHeader, ['localhost', '127.0.0.1', '::1'])
    || str_starts_with($hostHeader, 'localhost:')
    || str_starts_with($hostHeader, '127.0.0.1:')
    || php_sapi_name() === 'cli';

$message = '';
$messageType = '';
$devResetUrl = '';
$devEmailError = '';

// Clean up expired tokens
$conn->query("DELETE FROM password_resets WHERE expires_at < NOW()");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
        $messageType = 'error';
    } else {
        // Check if account exists
        $stmt = $conn->prepare("SELECT id, name FROM users WHERE LOWER(email) = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $userRow = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($userRow) {
            $userName = !empty($userRow['name']) ? $userRow['name'] : 'there';

            // Rate-limiting: reuse token if created within last 60 seconds
            $rateStmt = $conn->prepare("SELECT token FROM password_resets WHERE email = ? AND created_at > (NOW() - INTERVAL 60 SECOND) LIMIT 1");
            $rateStmt->bind_param("s", $email);
            $rateStmt->execute();
            $recent = $rateStmt->get_result()->fetch_assoc();
            $rateStmt->close();

            if ($recent) {
                $token = $recent['token'];
            } else {
                // Delete previous tokens for this email
                $del = $conn->prepare("DELETE FROM password_resets WHERE email = ?");
                $del->bind_param("s", $email);
                $del->execute();
                $del->close();

                // Generate cryptographically secure token (64 hex characters)
                $token = bin2hex(random_bytes(32));
                $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour validity

                $ins = $conn->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
                $ins->bind_param("sss", $email, $token, $expires);
                $ins->execute();
                $ins->close();
            }

            // Build full reset link
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
            $resetUrl = "$scheme://$host$dir/reset_password.php?token=$token";

            $emailHtml = '
            <div style="font-family: Arial, sans-serif; background:#14121A; padding:32px 16px; color:#F1EEF7;">
                <div style="max-width:480px; margin:0 auto; background:#1B1822; border-radius:16px; overflow:hidden; border:1px solid #2a2733;">
                    <div style="padding:28px 30px 10px;">
                        <div style="font-size:22px; font-weight:bold; color:#F1EEF7;">Ticket<span style="color:#9D7CF9;">Hub</span></div>
                    </div>
                    <div style="padding:10px 30px 30px;">
                        <h2 style="color:#9D7CF9; margin:10px 0 10px; font-size:20px;">Reset your password</h2>
                        <p style="color:#A9A3B8; font-size:14px; margin:0 0 18px; line-height:1.5;">Hi ' . htmlspecialchars($userName) . ',</p>
                        <p style="color:#A9A3B8; font-size:14px; margin:0 0 24px; line-height:1.5;">We received a request to reset the password for your TicketHub account. Click the button below to choose a new password. This link will expire in <strong style="color:#F1EEF7;">1 hour</strong>.</p>
                        <div style="text-align:center; margin:28px 0;">
                            <a href="' . htmlspecialchars($resetUrl) . '"
                               style="background:#9D7CF9; color:#ffffff; padding:14px 32px; border-radius:100px; text-decoration:none; font-weight:600; font-size:15px; display:inline-block;">
                                Reset Password
                            </a>
                        </div>
                        <p style="color:#6e6a7d; font-size:12px; margin:20px 0 0; line-height:1.5;">If you didn\'t request this, you can safely ignore this email. Your password will remain unchanged.</p>
                        <p style="color:#6e6a7d; font-size:11px; margin:14px 0 0; word-break:break-all;">Or copy this link: ' . htmlspecialchars($resetUrl) . '</p>
                    </div>
                    <div style="padding:16px 30px; text-align:center; color:#6e6a7d; font-size:12px; border-top:1px solid #2a2733;">
                        © 2026 TicketHub. All rights reserved.
                    </div>
                </div>
            </div>';

            $sendResult = brevo_send_email(
                $email,
                $userName,
                "Reset your TicketHub password",
                $emailHtml
            );

            // In local environment, provide the reset link directly for seamless development & testing
            if ($isLocal) {
                $devResetUrl = $resetUrl;
                if (!$sendResult['ok']) {
                    $devEmailError = $sendResult['error'] ?? 'Brevo delivery issue';
                }
            }
        }

        // Generic response to protect against account enumeration
        $message = "If an account exists for that email, we've sent instructions to reset your password. Please check your inbox (and spam folder).";
        $messageType = 'success';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . "/pwa_head.php"; ?>
<title>Forgot Password - TicketHub</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #14121A;
    --surface: rgba(255,255,255,0.04);
    --surface-hover: rgba(255,255,255,0.07);
    --field: rgba(255,255,255,0.05);
    --border: rgba(255,255,255,0.08);
    --border-hover: rgba(255,255,255,0.16);
    --text: #F1EEF7;
    --text-dim: #A9A3B8;
    --violet: #9D7CF9;
    --violet-soft: rgba(157,124,249,0.18);
    --rose: #F0729C;
    --green: #6FCF97;
    --amber: #F2C572;
    --font-display: 'Sora', 'Segoe UI', sans-serif;
    --font-body: 'Inter', 'Segoe UI', sans-serif;
}

* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: var(--font-body);
    background: var(--bg);
    color: var(--text);
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    overflow-x: hidden;
}

/* NAVBAR */
header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 48px;
    background: rgba(20,18,26,0.75);
    backdrop-filter: blur(14px);
    border-bottom: 1px solid var(--border);
    position: sticky;
    top: 0;
    z-index: 100;
}

.logo {
    font-family: var(--font-display);
    font-size: 22px;
    font-weight: 700;
    color: var(--text);
    text-decoration: none;
    letter-spacing: -0.01em;
}

.logo span { color: var(--violet); }

nav a {
    padding: 8px 14px;
    border-radius: 8px;
    text-decoration: none;
    color: var(--text-dim);
    font-size: 14px;
    font-weight: 500;
    transition: color 0.2s ease, background 0.2s ease;
}

nav a:hover {
    color: var(--text);
    background: var(--surface);
}

/* AMBIENT BACKGROUND GLOW */
.glow-wrap {
    position: fixed;
    inset: 0;
    overflow: hidden;
    pointer-events: none;
    z-index: 0;
}

.glow {
    position: absolute;
    width: 440px;
    height: 440px;
    border-radius: 50%;
    filter: blur(100px);
    opacity: 0.18;
}

.glow-violet {
    background: var(--violet);
    top: -60px;
    left: 50%;
    transform: translateX(-65%);
    animation: drift 14s ease-in-out infinite;
}

.glow-rose {
    background: var(--rose);
    top: 180px;
    left: 50%;
    transform: translateX(25%);
    animation: drift 14s ease-in-out infinite reverse;
    animation-delay: -5s;
}

/* MAIN CONTENT */
.wrap {
    position: relative;
    z-index: 1;
    flex: 1;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 60px 24px;
}

.card {
    width: 100%;
    max-width: 440px;
    background: var(--surface);
    border: 1px solid var(--border);
    padding: 40px 36px;
    border-radius: 20px;
    backdrop-filter: blur(20px);
    opacity: 0;
    transform: translateY(16px);
    animation: rise 0.6s cubic-bezier(0.16, 1, 0.3, 1) forwards;
}

.card-header {
    text-align: center;
    margin-bottom: 26px;
}

.card-header h1 {
    font-family: var(--font-display);
    font-size: 24px;
    font-weight: 700;
    margin: 0 0 8px;
    color: var(--text);
    letter-spacing: -0.01em;
}

.card-header p {
    color: var(--text-dim);
    font-size: 14px;
    margin: 0;
    line-height: 1.5;
}

/* ALERTS */
.msg {
    padding: 14px 16px;
    border-radius: 12px;
    font-size: 13px;
    line-height: 1.5;
    margin-bottom: 22px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
}

.msg.error {
    background: rgba(240, 114, 156, 0.12);
    border: 1px solid rgba(240, 114, 156, 0.32);
    color: #F8A7C0;
}

.msg.success {
    background: rgba(111, 207, 151, 0.12);
    border: 1px solid rgba(111, 207, 151, 0.32);
    color: var(--green);
}

/* FORM FIELDS */
.form-group {
    margin-bottom: 20px;
    text-align: left;
}

.form-group label {
    display: block;
    margin-bottom: 7px;
    color: var(--text);
    font-size: 13px;
    font-weight: 500;
}

.form-group input {
    width: 100%;
    padding: 13px 16px;
    border: 1px solid var(--border);
    border-radius: 12px;
    outline: none;
    background: var(--field);
    color: var(--text);
    font-family: var(--font-body);
    font-size: 14px;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}

.form-group input::placeholder { color: #6e6a7d; }

.form-group input:hover {
    border-color: var(--border-hover);
}

.form-group input:focus {
    border-color: var(--violet);
    box-shadow: 0 0 0 3px var(--violet-soft);
}

/* SUBMIT BUTTON */
.btn-submit {
    width: 100%;
    padding: 14px;
    font-family: var(--font-body);
    font-size: 15px;
    font-weight: 600;
    border-radius: 100px;
    border: none;
    cursor: pointer;
    color: #121017;
    background: var(--text);
    box-shadow: 0 4px 18px rgba(0,0,0,0.25);
    transition: transform 0.2s ease, background 0.2s ease, color 0.2s ease, box-shadow 0.2s ease;
}

.btn-submit:hover {
    background: var(--violet);
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 10px 24px -6px rgba(157, 124, 249, 0.5);
}

/* LOCALHOST DEV HELPER BOX */
.dev-card {
    background: rgba(157, 124, 249, 0.08);
    border: 1px dashed rgba(157, 124, 249, 0.45);
    border-radius: 14px;
    padding: 18px;
    margin-top: 20px;
    text-align: left;
}

.dev-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    background: rgba(157, 124, 249, 0.25);
    color: #CBB8FC;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 10px;
}

.dev-card p {
    font-size: 13px;
    color: var(--text);
    margin: 0 0 14px;
    line-height: 1.45;
}

.dev-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 100%;
    padding: 12px 18px;
    background: var(--violet);
    color: #ffffff;
    text-decoration: none;
    font-size: 14px;
    font-weight: 600;
    border-radius: 10px;
    transition: background 0.2s ease, transform 0.2s ease;
    margin-bottom: 12px;
}

.dev-btn:hover {
    background: #8960f7;
    transform: translateY(-1px);
}

.copy-row {
    display: flex;
    gap: 8px;
}

.copy-row input {
    flex: 1;
    background: rgba(0, 0, 0, 0.35);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 8px 10px;
    font-size: 11px;
    color: var(--text-dim);
    font-family: monospace;
    outline: none;
}

.btn-copy {
    background: var(--surface-hover);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 8px 14px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 500;
    cursor: pointer;
    transition: background 0.2s ease;
    white-space: nowrap;
}

.btn-copy:hover {
    background: rgba(255, 255, 255, 0.12);
}

.dev-warning {
    margin-top: 10px;
    font-size: 11px;
    color: #E2B275;
    line-height: 1.4;
}

/* CARD FOOTER */
.card-footer {
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid var(--border);
    text-align: center;
    font-size: 14px;
    color: var(--text-dim);
}

.card-footer a {
    color: var(--violet);
    text-decoration: none;
    font-weight: 600;
}

.card-footer a:hover {
    text-decoration: underline;
}

/* PAGE FOOTER */
footer {
    text-align: center;
    padding: 24px;
    border-top: 1px solid var(--border);
    color: var(--text-dim);
    font-size: 13px;
    position: relative;
    z-index: 1;
}

/* ANIMATIONS */
@keyframes rise {
    from { opacity: 0; transform: translateY(18px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes drift {
    0%, 100% { transform: translate(0, 0); }
    50% { transform: translate(25px, 25px); }
}

@media (max-width: 480px) {
    header { padding: 16px 20px; }
    .wrap { padding: 40px 18px; }
    .card { padding: 30px 22px; border-radius: 16px; }
}
</style>
</head>
<body>

<div class="glow-wrap" aria-hidden="true">
    <div class="glow glow-violet"></div>
    <div class="glow glow-rose"></div>
</div>

<header>
    <a href="index.php" class="logo">Ticket<span>Hub</span></a>
    <nav>
        <a href="login.php">← Back to login</a>
    </nav>
</header>

<main class="wrap">
    <div class="card">
        <div class="card-header">
            <h1>Forgot password?</h1>
            <p>Enter your registered email address and we'll help you reset your password.</p>
        </div>

        <?php if ($message !== ''): ?>
            <div class="msg <?php echo $messageType; ?>" role="alert">
                <span><?php echo ($messageType === 'success') ? '✓' : '⚠️'; ?></span>
                <span><?php echo htmlspecialchars($message); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($messageType !== 'success'): ?>
        <form method="POST" action="forgot_password.php">
            <div class="form-group">
                <label for="email">Email address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="name@example.com"
                    required
                    autocomplete="email"
                    value="<?php echo htmlspecialchars($_POST['email'] ?? $_GET['email'] ?? ''); ?>"
                    autofocus
                >
            </div>
            <button type="submit" class="btn-submit">Send Reset Link</button>
        </form>
        <?php endif; ?>

        <?php if ($devResetUrl !== ''): ?>
            <div class="dev-card">
                <div class="dev-badge">Localhost Development Mode</div>
                <p>Since you are testing locally on XAMPP, your reset link was generated instantly:</p>
                <a href="<?php echo htmlspecialchars($devResetUrl); ?>" class="dev-btn">
                    Reset Password Now →
                </a>
                <div class="copy-row">
                    <input type="text" id="devUrlField" readonly value="<?php echo htmlspecialchars($devResetUrl); ?>">
                    <button type="button" class="btn-copy" id="copyBtn" onclick="copyDevLink()">Copy Link</button>
                </div>
                <?php if ($devEmailError !== ''): ?>
                    <div class="dev-warning">
                        💡 <strong>Brevo API Notice:</strong> <?php echo htmlspecialchars($devEmailError); ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="card-footer">
            Remember your password? <a href="login.php">Back to login</a>
        </div>
    </div>
</main>

<footer>
    © 2026 TicketHub. All rights reserved.
</footer>

<script>
function copyDevLink() {
    const input = document.getElementById('devUrlField');
    const btn = document.getElementById('copyBtn');
    if (!input) return;
    input.select();
    input.setSelectionRange(0, 99999);
    navigator.clipboard.writeText(input.value).then(() => {
        btn.textContent = 'Copied!';
        setTimeout(() => { btn.textContent = 'Copy Link'; }, 2200);
    });
}
</script>

</body>
</html>
