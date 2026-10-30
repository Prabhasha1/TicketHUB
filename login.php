<?php
session_start();
require __DIR__ . "/config/app.php";

// Page to come back to after logging in (e.g. the ticket page)
$returnTo = safe_return_path($_GET["return"] ?? null);

// If already logged in, redirect to home or admin dashboard
if (isset($_SESSION['user_id'])) {
    if (isset($_SESSION['user_role']) && in_array($_SESSION['user_role'], ['admin', 'super_admin'], true)) {
        header("Location: " . ($returnTo ?? "admin_dashboard.php"));
    } else {
        header("Location: " . ($returnTo ?? "index.php"));
    }
    exit;
}

$error = trim($_GET['error'] ?? '');
$success = '';
if (isset($_GET['reset'])) {
    $success = "Password updated successfully! Please sign in with your new password.";
} elseif (isset($_GET['registered'])) {
    $success = "Account created successfully! You can now sign in.";
} elseif (!empty($_GET['msg'])) {
    $success = trim($_GET['msg']);
}
$emailVal = trim($_GET['email'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . "/pwa_head.php"; ?>
<title>Sign In - TicketHub</title>
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

nav {
    display: flex;
    align-items: center;
    gap: 6px;
}

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

nav a.nav-cta {
    background: var(--surface);
    color: var(--text);
    border: 1px solid var(--border);
    margin-left: 6px;
}

nav a.nav-cta:hover {
    background: var(--violet);
    color: #fff;
    border-color: var(--violet);
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

.login-card {
    width: 100%;
    max-width: 420px;
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
    margin-bottom: 28px;
}

.card-header h1 {
    font-family: var(--font-display);
    font-size: 26px;
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
    padding: 12px 16px;
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
}

.form-group label {
    display: block;
    margin-bottom: 7px;
    color: var(--text);
    font-size: 13px;
    font-weight: 500;
}

.field-wrap {
    position: relative;
    display: flex;
    align-items: center;
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
    transition: border-color 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
}

.field-wrap input {
    padding-right: 56px;
}

.form-group input::placeholder {
    color: #6e6a7d;
}

.form-group input:hover {
    border-color: var(--border-hover);
}

.form-group input:focus {
    border-color: var(--violet);
    background: rgba(255, 255, 255, 0.07);
    box-shadow: 0 0 0 3px var(--violet-soft);
}

.toggle-pw {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: none;
    border: none;
    color: var(--text-dim);
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    padding: 6px 8px;
    border-radius: 6px;
    font-family: var(--font-body);
    transition: color 0.2s ease, background 0.2s ease;
}

.toggle-pw:hover {
    color: var(--text);
    background: var(--surface-hover);
}

/* HELPER ROW */
.form-helper {
    display: flex;
    justify-content: flex-end;
    align-items: center;
    margin-top: -6px;
    margin-bottom: 22px;
}

.form-helper a {
    color: var(--violet);
    font-size: 13px;
    text-decoration: none;
    font-weight: 500;
    transition: opacity 0.2s ease;
}

.form-helper a:hover {
    text-decoration: underline;
    opacity: 0.85;
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

.btn-submit:active {
    transform: translateY(0);
}

/* CONTINUE WITH GOOGLE */
.or-divider {
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 22px 0;
    color: var(--text-dim);
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
}

.or-divider::before,
.or-divider::after {
    content: "";
    flex: 1;
    height: 1px;
    background: var(--border);
}

.btn-google {
    width: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    padding: 13px;
    font-family: var(--font-body);
    font-size: 15px;
    font-weight: 600;
    border-radius: 100px;
    border: 1px solid rgba(255,255,255,0.16);
    background: transparent;
    color: var(--text);
    text-decoration: none;
    transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
}

.btn-google:hover {
    background: rgba(255,255,255,0.07);
    border-color: rgba(255,255,255,0.28);
    transform: translateY(-2px);
}

.btn-google svg { width: 18px; height: 18px; flex-shrink: 0; }

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
    margin-left: 4px;
    transition: opacity 0.2s ease;
}

.card-footer a:hover {
    text-decoration: underline;
    opacity: 0.85;
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

/* KEYFRAMES */
@keyframes rise {
    from { opacity: 0; transform: translateY(18px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes drift {
    0%, 100% { transform: translate(0, 0); }
    50% { transform: translate(25px, 25px); }
}

@media (prefers-reduced-motion: reduce) {
    *, .login-card, .glow { animation: none !important; opacity: 1 !important; transform: none !important; }
}

@media (max-width: 480px) {
    header { padding: 16px 20px; }
    .wrap { padding: 40px 18px; }
    .login-card { padding: 30px 22px; border-radius: 16px; }
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
        <a href="index.php">Home</a>
        <a href="events.php">Events</a>
        <a href="my_tickets.php">My Tickets</a>
        <a href="register.php" class="nav-cta">Register</a>
    </nav>
</header>

<main class="wrap">
    <div class="login-card">
        <div class="card-header">
            <h1>Welcome back</h1>
            <p>Sign in to your TicketHub account to continue</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="msg error" role="alert">
                <span>⚠️</span>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="msg success" role="status">
                <span>✓</span>
                <span><?php echo htmlspecialchars($success); ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="login_process.php" autocomplete="on">
            <?php if ($returnTo): ?><input type="hidden" name="return" value="<?php echo htmlspecialchars($returnTo); ?>"><?php endif; ?>
            <div class="form-group">
                <label for="email">Email address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    placeholder="name@example.com"
                    required
                    autocomplete="email"
                    value="<?php echo htmlspecialchars($emailVal); ?>"
                    autofocus
                >
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="field-wrap">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        required
                        autocomplete="current-password"
                    >
                    <button type="button" class="toggle-pw" id="togglePw" aria-label="Toggle password visibility">Show</button>
                </div>
            </div>

            <div class="form-helper">
                <a href="forgot_password.php">Forgot password?</a>
            </div>

            <button type="submit" class="btn-submit">Sign In</button>
        </form>

        <div class="or-divider">or</div>

        <a href="google_login.php<?php echo $returnTo ? "?return=" . urlencode($returnTo) : ""; ?>" class="btn-google">
            <svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>
            Continue with Google
        </a>

        <div class="card-footer">
            Don't have an account? <a href="register.php">Create one</a>
        </div>
    </div>
</main>

<footer>
    © 2026 TicketHub. All rights reserved.
</footer>

<script>
const toggleBtn = document.getElementById('togglePw');
const pwInput = document.getElementById('password');

if (toggleBtn && pwInput) {
    toggleBtn.addEventListener('click', () => {
        const isPassword = pwInput.type === 'password';
        pwInput.type = isPassword ? 'text' : 'password';
        toggleBtn.textContent = isPassword ? 'Hide' : 'Show';
        toggleBtn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
    });
}
</script>

</body>
</html>