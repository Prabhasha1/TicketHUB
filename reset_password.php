<?php
session_start();
require __DIR__ . "/config/database.php";

$token = trim($_GET['token'] ?? '');

// Clean up expired tokens
$conn->query("DELETE FROM password_resets WHERE expires_at < NOW()");

$error = '';
$success = false;
$validToken = false;
$tokenEmail = '';

// Validate token
if ($token === '') {
    $error = "No reset token provided. Please request a new password reset link.";
} else {
    $stmt = $conn->prepare(
        "SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW() LIMIT 1"
    );
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        $error = "This password reset link has expired or has already been used. Please request a new one.";
    } else {
        $validToken = true;
        $tokenEmail = $row['email'];
    }
}

// Handle form submission
if ($validToken && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword     = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (strlen($newPassword) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif ($newPassword !== $confirmPassword) {
        $error = "Passwords do not match. Please ensure both fields are identical.";
    } else {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);

        // Update the user's password
        $upd = $conn->prepare("UPDATE users SET password = ? WHERE LOWER(email) = LOWER(?)");
        $upd->bind_param("ss", $hash, $tokenEmail);
        $upd->execute();
        $upd->close();

        // A guest-checkout account that sets a password becomes a normal account
        try {
            $claim = $conn->prepare("UPDATE users SET is_guest = 0 WHERE LOWER(email) = LOWER(?)");
            $claim->bind_param("s", $tokenEmail);
            $claim->execute();
            $claim->close();
        } catch (mysqli_sql_exception $e) {
            // is_guest column not migrated yet — nothing to claim
        }

        // Invalidate all tokens for this email (single-use security)
        $del = $conn->prepare("DELETE FROM password_resets WHERE LOWER(email) = LOWER(?)");
        $del->bind_param("s", $tokenEmail);
        $del->execute();
        $del->close();

        $success = true;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . "/pwa_head.php"; ?>
<title>Reset Password - TicketHub</title>
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
    text-align: left;
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

.field-wrap {
    position: relative;
    display: flex;
    align-items: center;
}

.form-group input {
    width: 100%;
    padding: 13px 56px 13px 16px;
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

/* PASSWORD STRENGTH */
.strength-bar {
    display: flex;
    gap: 4px;
    margin-top: 8px;
}

.strength-bar span {
    height: 4px;
    flex: 1;
    border-radius: 2px;
    background: var(--border);
    transition: background 0.25s ease;
}

.strength-label {
    font-size: 12px;
    color: var(--text-dim);
    margin-top: 6px;
    display: block;
    min-height: 16px;
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

.btn-cta {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    width: 100%;
    padding: 14px;
    font-family: var(--font-body);
    font-size: 15px;
    font-weight: 600;
    border-radius: 100px;
    text-decoration: none;
    color: #fff;
    background: var(--violet);
    box-shadow: 0 10px 24px -6px rgba(157, 124, 249, 0.5);
    transition: transform 0.2s ease, background 0.2s ease;
}

.btn-cta:hover {
    background: #8960f7;
    transform: translateY(-2px);
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

        <?php if ($success): ?>
            <div class="card-header">
                <div style="font-size: 44px; margin-bottom: 12px;">🎉</div>
                <h1>Password updated!</h1>
                <p>Your password has been changed successfully. You can now use your new credentials to sign in.</p>
            </div>
            <div class="msg success">
                <span>✓</span>
                <span>Security confirmed: All previous reset tokens have been invalidated.</span>
            </div>
            <a href="login.php?reset=1" class="btn-cta">Proceed to Sign In →</a>

        <?php elseif (!$validToken): ?>
            <div class="card-header">
                <div style="font-size: 44px; margin-bottom: 12px;">⏰</div>
                <h1>Link Expired or Invalid</h1>
                <p>This password reset link is invalid or has expired.</p>
            </div>
            <div class="msg error">
                <span>⚠️</span>
                <span><?php echo htmlspecialchars($error); ?></span>
            </div>
            <a href="forgot_password.php" class="btn-cta">Request New Reset Link</a>
            <div class="card-footer">
                <a href="login.php">Back to login</a>
            </div>

        <?php else: ?>
            <div class="card-header">
                <h1>Set new password</h1>
                <p>Choose a strong, secure password for <strong style="color:var(--text);"><?php echo htmlspecialchars($tokenEmail); ?></strong></p>
            </div>

            <?php if ($error !== ''): ?>
                <div class="msg error" role="alert">
                    <span>⚠️</span>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="reset_password.php?token=<?php echo urlencode($token); ?>">
                <div class="form-group">
                    <label for="password">New password</label>
                    <div class="field-wrap">
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="At least 8 characters"
                            required
                            minlength="8"
                            autocomplete="new-password"
                            autofocus
                        >
                        <button type="button" class="toggle-pw" id="togglePw1" aria-label="Toggle password visibility">Show</button>
                    </div>
                    <div class="strength-bar" aria-hidden="true">
                        <span></span><span></span><span></span><span></span>
                    </div>
                    <span class="strength-label" id="strengthLabel"></span>
                </div>

                <div class="form-group">
                    <label for="confirm_password">Confirm new password</label>
                    <div class="field-wrap">
                        <input
                            type="password"
                            id="confirm_password"
                            name="confirm_password"
                            placeholder="Re-enter your new password"
                            required
                            minlength="8"
                            autocomplete="new-password"
                        >
                        <button type="button" class="toggle-pw" id="togglePw2" aria-label="Toggle password visibility">Show</button>
                    </div>
                </div>

                <button type="submit" class="btn-submit">Save New Password</button>
            </form>

            <div class="card-footer">
                Never mind? <a href="login.php">Back to login</a>
            </div>
        <?php endif; ?>

    </div>
</main>

<footer>
    © 2026 TicketHub. All rights reserved.
</footer>

<script>
// Show / Hide password toggles
[['togglePw1','password'], ['togglePw2','confirm_password']].forEach(([btnId, fieldId]) => {
    const btn = document.getElementById(btnId);
    const field = document.getElementById(fieldId);
    if (!btn || !field) return;

    btn.addEventListener('click', () => {
        const isPassword = field.type === 'password';
        field.type = isPassword ? 'text' : 'password';
        btn.textContent = isPassword ? 'Hide' : 'Show';
        btn.setAttribute('aria-label', isPassword ? 'Hide password' : 'Show password');
    });
});

// Real-time password strength meter
const pwField = document.getElementById('password');
const bars = document.querySelectorAll('.strength-bar span');
const label = document.getElementById('strengthLabel');
const colors = ['#E2566F', '#F2C572', '#9D7CF9', '#6FCF97'];
const labels = ['Weak — add uppercase, numbers, or symbols', 'Fair — good progress', 'Good — strong password', 'Excellent — very secure'];

if (pwField && bars.length && label) {
    pwField.addEventListener('input', () => {
        const val = pwField.value;
        let score = 0;
        if (val.length >= 8) score++;
        if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
        if (/\d/.test(val)) score++;
        if (/[^A-Za-z0-9]/.test(val)) score++;

        bars.forEach((bar, idx) => {
            bar.style.background = (idx < score && val.length > 0) ? colors[Math.max(score - 1, 0)] : 'var(--border)';
        });

        label.textContent = val.length > 0 ? labels[Math.max(score - 1, 0)] : '';
        label.style.color = val.length > 0 ? colors[Math.max(score - 1, 0)] : 'var(--text-dim)';
    });
}
</script>

</body>
</html>
