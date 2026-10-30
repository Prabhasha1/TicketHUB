<?php
/**
 * footer.php
 * ------------------------------------------------------------
 * Shared site footer: brand + newsletter signup, Account links,
 * Customer care pages, contact details and social links.
 * Include just before </body>:  require __DIR__ . "/footer.php";
 * ------------------------------------------------------------
 */
require_once __DIR__ . "/config/app.php";
require_once __DIR__ . "/config/site.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$footLoggedIn = isset($_SESSION['user_id']);
$footCsrf     = csrf_token();
$footSocial   = array_filter(SITE_SOCIAL);
$footIcons    = ['Facebook' => 'f', 'Instagram' => '◎', 'TikTok' => '♪', 'YouTube' => '▶', 'WhatsApp' => '✆'];
?>
<style>
.site-footer {
    margin-top: 40px; border-top: 1px solid rgba(255,255,255,0.08);
    background: linear-gradient(180deg, rgba(157,124,249,0.05), rgba(20,18,26,0) 60%), #121018;
    color: #A9A3B8; font-family: 'Inter', 'Segoe UI', sans-serif; font-size: 14px;
}
.site-footer .wrap { max-width: 1180px; margin: 0 auto; padding: 48px 48px 20px; }
.site-footer .cols { display: grid; grid-template-columns: 1.6fr 1fr 1fr 1.2fr; gap: 36px; }
.site-footer .logo { font-family: 'Sora', 'Segoe UI', sans-serif; font-size: 22px; font-weight: 700; color: #F1EEF7; text-decoration: none; }
.site-footer .logo span { color: #9D7CF9; }
.site-footer .tagline { margin: 12px 0 20px; line-height: 1.6; max-width: 380px; }
.site-footer h4 {
    margin: 4px 0 14px; font-family: 'Sora', 'Segoe UI', sans-serif; font-size: 13px; font-weight: 700;
    letter-spacing: 0.6px; text-transform: uppercase; color: #F1EEF7;
}
.site-footer ul { list-style: none; margin: 0; padding: 0; }
.site-footer li { margin-bottom: 10px; }
.site-footer a.fl { color: #A9A3B8; text-decoration: none; transition: color 0.2s ease; }
.site-footer a.fl:hover { color: #F1EEF7; }
.site-footer .contact li { display: flex; gap: 10px; align-items: flex-start; }
.site-footer .contact .ic { width: 18px; text-align: center; flex-shrink: 0; }

.site-footer .sub-label { font-size: 13px; font-weight: 600; color: #F1EEF7; margin-bottom: 8px; }
.site-footer form.subscribe { display: flex; gap: 8px; max-width: 400px; }
.site-footer form.subscribe input {
    flex: 1; min-width: 0; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); border-radius: 100px;
    color: #F1EEF7; font-family: inherit; font-size: 14px; padding: 11px 16px; outline: none;
}
.site-footer form.subscribe input:focus { border-color: #9D7CF9; box-shadow: 0 0 0 3px rgba(157,124,249,0.15); }
.site-footer form.subscribe button {
    padding: 11px 20px; border: none; border-radius: 100px; cursor: pointer; font-family: inherit; font-weight: 700; font-size: 14px;
    background: #F1EEF7; color: #14121A; transition: background 0.2s ease, color 0.2s ease;
}
.site-footer form.subscribe button:hover:not(:disabled) { background: #9D7CF9; color: #fff; }
.site-footer form.subscribe button:disabled { opacity: 0.6; cursor: default; }
.site-footer .sub-msg { font-size: 12.5px; margin-top: 8px; min-height: 18px; }
.site-footer .sub-msg.ok { color: #6FCF97; }
.site-footer .sub-msg.err { color: #FFA5A5; }

.site-footer .social { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 4px; }
.site-footer .social a {
    width: 38px; height: 38px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
    background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); color: #F1EEF7; text-decoration: none; font-weight: 700;
    transition: background 0.2s ease, border-color 0.2s ease, transform 0.2s ease;
}
.site-footer .social a:hover { background: rgba(157,124,249,0.25); border-color: rgba(157,124,249,0.5); transform: translateY(-2px); }

.site-footer .bottom {
    display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; align-items: center;
    margin-top: 40px; padding-top: 18px; border-top: 1px solid rgba(255,255,255,0.06); font-size: 12.5px; color: #7D7790;
}
.site-footer .bottom a { color: #7D7790; text-decoration: none; margin-left: 14px; }
.site-footer .bottom a:hover { color: #F1EEF7; }

@media (max-width: 900px) { .site-footer .cols { grid-template-columns: 1fr 1fr; } .site-footer .brand { grid-column: 1 / -1; } }
@media (max-width: 560px) {
    .site-footer .wrap { padding: 36px 16px 18px; }
    .site-footer .cols { grid-template-columns: 1fr; gap: 26px; }
    .site-footer .bottom a { margin: 0 14px 0 0; }
}
</style>

<footer class="site-footer">
    <div class="wrap">
        <div class="cols">
            <div class="brand">
                <a href="index.php" class="logo">Ticket<span>Hub</span></a>
                <p class="tagline"><?php echo htmlspecialchars(SITE_TAGLINE); ?></p>
                <div class="sub-label">📣 Get new events in your inbox</div>
                <form class="subscribe" id="footerSubscribe" method="POST" action="subscribe.php" novalidate>
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($footCsrf); ?>">
                    <input type="email" name="email" placeholder="Your email address" aria-label="Email address" required maxlength="150" autocomplete="email">
                    <button type="submit">Subscribe</button>
                </form>
                <div class="sub-msg" id="footerSubscribeMsg" role="status" aria-live="polite"></div>
            </div>

            <div>
                <h4>Account</h4>
                <ul>
                    <li><a class="fl" href="my_tickets.php">My Tickets</a></li>
                    <li><a class="fl" href="profile.php">Profile</a></li>
                    <li><a class="fl" href="events.php">Browse events</a></li>
                    <?php if ($footLoggedIn): ?>
                        <li><a class="fl" href="logout.php">Logout</a></li>
                    <?php else: ?>
                        <li><a class="fl" href="login.php">Login</a></li>
                        <li><a class="fl" href="register.php">Create account</a></li>
                    <?php endif; ?>
                </ul>
            </div>

            <div>
                <h4>Customer care</h4>
                <ul>
                    <li><a class="fl" href="info.php?page=about">About us</a></li>
                    <li><a class="fl" href="info.php?page=refunds">Refund policy</a></li>
                    <li><a class="fl" href="info.php?page=terms">Terms of use</a></li>
                    <li><a class="fl" href="info.php?page=privacy">Privacy policy</a></li>
                    <li><a class="fl" href="info.php?page=contact">Help &amp; contact</a></li>
                </ul>
            </div>

            <div>
                <h4>Contact us</h4>
                <ul class="contact">
                    <?php if (SITE_PHONE !== ''): ?>
                        <li><span class="ic">📞</span><a class="fl" href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', SITE_PHONE)); ?>"><?php echo htmlspecialchars(SITE_PHONE); ?></a></li>
                    <?php endif; ?>
                    <?php if (SITE_EMAIL !== ''): ?>
                        <li><span class="ic">✉</span><a class="fl" href="mailto:<?php echo htmlspecialchars(SITE_EMAIL); ?>"><?php echo htmlspecialchars(SITE_EMAIL); ?></a></li>
                    <?php endif; ?>
                    <?php if (SITE_CAREERS_EMAIL !== ''): ?>
                        <li><span class="ic">💼</span><a class="fl" href="mailto:<?php echo htmlspecialchars(SITE_CAREERS_EMAIL); ?>"><?php echo htmlspecialchars(SITE_CAREERS_EMAIL); ?></a></li>
                    <?php endif; ?>
                    <?php if (SITE_ADDRESS !== ''): ?>
                        <li><span class="ic">📍</span><span><?php echo htmlspecialchars(SITE_ADDRESS); ?></span></li>
                    <?php endif; ?>
                </ul>
                <?php if ($footSocial): ?>
                    <h4 style="margin-top: 22px;">Stay updated</h4>
                    <div class="social">
                        <?php foreach ($footSocial as $network => $url): ?>
                            <a href="<?php echo htmlspecialchars($url); ?>" target="_blank" rel="noopener noreferrer" aria-label="TicketHub on <?php echo $network; ?>" title="<?php echo $network; ?>"><?php echo $footIcons[$network] ?? '↗'; ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="bottom">
            <span>© <?php echo date('Y'); ?> TicketHub. All rights reserved. · 🔒 Payments secured by Stripe</span>
            <span>
                <a href="info.php?page=terms">Terms</a>
                <a href="info.php?page=privacy">Privacy</a>
                <a href="info.php?page=refunds">Refunds</a>
            </span>
        </div>
    </div>
</footer>

<script>
(function () {
    const form = document.getElementById('footerSubscribe');
    if (!form || form.dataset.bound) return;
    form.dataset.bound = '1';
    const msg = document.getElementById('footerSubscribeMsg');
    form.addEventListener('submit', async e => {
        e.preventDefault();
        const email = form.email.value.trim();
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            msg.className = 'sub-msg err';
            msg.textContent = 'Please enter a valid email address.';
            form.email.focus();
            return;
        }
        const btn = form.querySelector('button');
        btn.disabled = true;
        btn.textContent = '…';
        try {
            const res = await fetch('subscribe.php', { method: 'POST', body: new FormData(form), headers: { 'Accept': 'application/json' } });
            const data = await res.json();
            msg.className = 'sub-msg ' + (data.ok ? 'ok' : 'err');
            msg.textContent = data.message;
            if (data.ok) form.email.value = '';
        } catch (err) {
            msg.className = 'sub-msg err';
            msg.textContent = 'Could not subscribe right now. Please try again.';
        }
        btn.disabled = false;
        btn.textContent = 'Subscribe';
    });
})();
</script>
