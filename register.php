<?php
session_start();
// Check if user is logged in
$loggedIn = isset($_SESSION['user_id']);
$userName = $loggedIn ? $_SESSION['user_name'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . "/pwa_head.php"; ?>
<title>TicketHub - Register</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root {
    --bg: #14121A;
    --surface: rgba(255,255,255,0.04);
    --field: rgba(255,255,255,0.06);
    --border: rgba(255,255,255,0.08);
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
    overflow-x: hidden;
    min-height: 100vh;
}

/* NAVBAR */
header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 22px 48px;
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
    letter-spacing: -0.01em;
    color: var(--text);
}

.logo span { color: var(--violet); }

nav { display: flex; align-items: center; gap: 4px; }

nav a, nav span {
    padding: 8px 14px;
    border-radius: 8px;
    text-decoration: none;
    color: var(--text-dim);
    font-size: 14px;
    font-weight: 500;
    transition: color 0.25s ease, background 0.25s ease;
}

nav a:hover { color: var(--text); background: var(--surface); }
nav a.cta { color: var(--bg); background: var(--text); margin-left: 6px; }
nav a.cta:hover { background: var(--violet); color: #fff; }
nav span.welcome { color: var(--violet); font-weight: 600; }

/* AMBIENT GLOW */
.glow {
    position: absolute;
    width: 380px;
    height: 380px;
    border-radius: 50%;
    filter: blur(90px);
    opacity: 0.2;
    pointer-events: none;
    z-index: 0;
}
.glow-violet { background: var(--violet); top: -80px; left: 50%; transform: translateX(-65%); animation: drift 14s ease-in-out infinite; }
.glow-rose { background: var(--rose); top: 140px; left: 50%; transform: translateX(20%); animation: drift 14s ease-in-out infinite reverse; animation-delay: -4s; }

/* REGISTER CARD */
.register-wrap {
    position: relative;
    display: flex;
    justify-content: center;
    padding: 80px 24px;
    min-height: calc(100vh - 73px - 70px);
    align-items: center;
}

.register-container {
    position: relative;
    z-index: 1;
    width: 100%;
    max-width: 400px;
    background: var(--surface);
    border: 1px solid var(--border);
    padding: 40px;
    border-radius: 20px;
    text-align: center;
    opacity: 0;
    transform: translateY(20px);
    animation: rise 0.7s ease-out forwards;
}

.register-container h2 {
    font-family: var(--font-display);
    font-size: 24px;
    font-weight: 700;
    color: var(--text);
    margin: 0 0 6px;
}

.register-container .subtitle {
    color: var(--text-dim);
    font-size: 14px;
    margin: 0 0 28px;
}

.form-group {
    margin-bottom: 18px;
    text-align: left;
}

.form-group label {
    display: block;
    margin-bottom: 6px;
    color: var(--text-dim);
    font-size: 13px;
    font-weight: 500;
}

.field-wrap { position: relative; }

.form-group input {
    width: 100%;
    padding: 12px 14px;
    border: 1px solid var(--border);
    border-radius: 10px;
    outline: none;
    background: var(--field);
    color: var(--text);
    font-family: var(--font-body);
    font-size: 14px;
    transition: border-color 0.25s ease, box-shadow 0.25s ease;
}

.form-group input::placeholder { color: #6e6a7d; }

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
    font-weight: 500;
    cursor: pointer;
    padding: 4px 6px;
    font-family: var(--font-body);
}

.toggle-pw:hover { color: var(--violet); }

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

.error-msg {
    display: none;
    background: rgba(240,114,156,0.12);
    border: 1px solid rgba(240,114,156,0.35);
    color: #F4A4BE;
    font-size: 13px;
    padding: 10px 14px;
    border-radius: 10px;
    margin-bottom: 18px;
    text-align: left;
}

.error-msg.show { display: block; }

.btn {
    width: 100%;
    margin-top: 8px;
    padding: 14px;
    font-family: var(--font-body);
    font-size: 15px;
    font-weight: 600;
    border-radius: 100px;
    border: none;
    cursor: pointer;
    color: var(--bg);
    background: var(--text);
    transition: transform 0.25s ease, background 0.25s ease, color 0.25s ease, box-shadow 0.25s ease, opacity 0.25s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn:hover:not(:disabled) {
    background: var(--violet);
    color: #fff;
    transform: translateY(-2px);
    box-shadow: 0 12px 30px -8px rgba(157,124,249,0.55);
}

.btn:disabled { opacity: 0.7; cursor: default; transform: none; }

.spinner {
    width: 16px;
    height: 16px;
    border: 2px solid rgba(20,18,26,0.3);
    border-top-color: var(--bg);
    border-radius: 50%;
    display: none;
    animation: spin 0.7s linear infinite;
}

.btn:disabled .spinner { display: inline-block; }

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

.switch-link {
    margin-top: 22px;
    font-size: 14px;
    color: var(--text-dim);
}

.switch-link a { color: var(--violet); text-decoration: none; font-weight: 600; }
.switch-link a:hover { text-decoration: underline; }

/* FOOTER */
.footer {
    text-align: center;
    padding: 28px;
    border-top: 1px solid var(--border);
    color: var(--text-dim);
    font-size: 13px;
}

/* ANIMATIONS */
@keyframes rise {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

@keyframes drift {
    0%, 100% { transform: translate(0, 0); }
    50% { transform: translate(25px, 30px); }
}

@keyframes spin { to { transform: rotate(360deg); } }

@media (prefers-reduced-motion: reduce) {
    *, .register-container, .glow { animation: none !important; opacity: 1 !important; transform: none !important; }
}

@media (max-width: 480px) {
    header { padding: 18px 20px; }
    .register-wrap { padding: 50px 20px; }
    .register-container { padding: 32px 24px; }
}
</style>
</head>
<body>

<header>
    <div class="logo">Ticket<span>Hub</span></div>
    <nav>
        <a href="index.php">Home</a>
        <a href="events.php">Events</a>
        <a href="my_tickets.php">My Tickets</a>
        <?php if($loggedIn): ?>
            <span class="welcome">Welcome, <?php echo htmlspecialchars($userName); ?></span>
            <a href="logout.php" class="cta">Logout</a>
        <?php else: ?>
            <a href="login.php">Login</a>
            <a href="register.php" class="cta">Register</a>
        <?php endif; ?>
    </nav>
</header>

<div class="register-wrap">
    <div class="glow glow-violet"></div>
    <div class="glow glow-rose"></div>

    <div class="register-container">
        <h2>Create your account</h2>
        <p class="subtitle">Join TicketHub to start booking events</p>

        <div class="error-msg" id="errorMsg"></div>

        <form method="POST" action="register_process.php" id="registerForm" novalidate>
            <div class="form-group">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" placeholder="Enter your full name" required>
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" placeholder="Enter your email" required>
            </div>
            <div class="form-group">
                <label for="phone">Mobile number <span style="color:#6e6a7d; font-weight:400;">(optional — for SMS tickets)</span></label>
                <input type="tel" id="phone" name="phone" inputmode="tel" autocomplete="tel" placeholder="077 123 4567">
            </div>
            <div class="form-group">
                <label for="password">Password</label>
                <div class="field-wrap">
                    <input type="password" id="password" name="password" placeholder="Enter your password" required minlength="8">
                    <button type="button" class="toggle-pw" id="togglePw">Show</button>
                </div>
                <div class="strength-bar">
                    <span></span><span></span><span></span><span></span>
                </div>
                <span class="strength-label" id="strengthLabel"></span>
            </div>
            <button type="submit" class="btn" id="registerBtn">
                <span class="spinner"></span>
                <span id="btnLabel">Register</span>
            </button>
        </form>

        <div class="or-divider">or</div>

        <a href="google_login.php" class="btn-google">
            <svg viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></svg>
            Continue with Google
        </a>

        <p class="switch-link">Already have an account? <a href="login.php">Login</a></p>
    </div>
</div>

<div class="footer">
    © 2026 TicketHub. All rights reserved.
</div>

<script>
const togglePw = document.getElementById('togglePw');
const pwField = document.getElementById('password');
togglePw.addEventListener('click', () => {
    const isHidden = pwField.type === 'password';
    pwField.type = isHidden ? 'text' : 'password';
    togglePw.textContent = isHidden ? 'Hide' : 'Show';
});

const bars = document.querySelectorAll('.strength-bar span');
const strengthLabel = document.getElementById('strengthLabel');
const colors = ['#E2566F', '#F2C572', '#9D7CF9', '#6FCF97'];
const labels = ['Weak', 'Fair', 'Good', 'Strong'];

pwField.addEventListener('input', () => {
    const val = pwField.value;
    let score = 0;
    if (val.length >= 8) score++;
    if (/[A-Z]/.test(val) && /[a-z]/.test(val)) score++;
    if (/\d/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    bars.forEach((bar, i) => {
        bar.style.background = i < score && val.length > 0 ? colors[Math.max(score - 1, 0)] : 'var(--border)';
    });
    strengthLabel.textContent = val.length > 0 ? labels[Math.max(score - 1, 0)] : '';
    strengthLabel.style.color = val.length > 0 ? colors[Math.max(score - 1, 0)] : 'var(--text-dim)';
});

const form = document.getElementById('registerForm');
const btn = document.getElementById('registerBtn');
const btnLabel = document.getElementById('btnLabel');
const errorMsg = document.getElementById('errorMsg');

const params = new URLSearchParams(window.location.search);
if (params.get('error')) {
    errorMsg.textContent = decodeURIComponent(params.get('error'));
    errorMsg.classList.add('show');
}

form.addEventListener('submit', () => {
    btn.disabled = true;
    btnLabel.textContent = 'Creating account…';
});
</script>

</body>
</html>