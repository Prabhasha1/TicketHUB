<?php
/**
 * customers.php
 * ------------------------------------------------------------
 * Super Admin only: every customer account on TicketHUB.
 *   - platform-wide customer summary (growth, buyers, spend, refunds)
 *   - top customers + new sign-ups per month
 *   - searchable / filterable / sortable list → customer_details.php
 *   - ?export=csv downloads the list
 * ------------------------------------------------------------
 */
session_start();
require __DIR__ . "/config/database.php";
require __DIR__ . "/config/app.php";

if (($_SESSION['user_role'] ?? '') !== 'super_admin') {
    header("Location: " . (isset($_SESSION['user_id']) ? "index.php" : "login.php"));
    exit;
}

$customers = [];
$loadError = '';
try {
    $customers = $conn->query(
        "SELECT u.*, -- u.* so is_guest is picked up once migrated
                COALESCE(b.bookings, 0) AS bookings, COALESCE(b.tickets, 0) AS tickets, COALESCE(b.spent, 0) AS spent,
                COALESCE(b.attended, 0) AS attended, b.last_purchase, b.first_purchase,
                COALESCE(r.refunded, 0) AS refunded_amount, COALESCE(r.refunds, 0) AS refunds, COALESCE(r.pending, 0) AS pending_refunds,
                COALESCE(p.failed, 0) AS failed_payments
         FROM users u
         LEFT JOIN (
             SELECT user_id, COUNT(*) AS bookings, SUM(quantity) AS tickets, SUM(total_price) AS spent,
                    SUM(checked_in_at IS NOT NULL) AS attended, MAX(created_at) AS last_purchase, MIN(created_at) AS first_purchase
             FROM bookings WHERE status = 'confirmed' GROUP BY user_id
         ) b ON b.user_id = u.id
         LEFT JOIN (
             SELECT user_id, SUM(CASE WHEN status = 'refunded' THEN refund_amount ELSE 0 END) AS refunded,
                    SUM(status = 'refunded') AS refunds, SUM(status = 'pending') AS pending
             FROM refund_requests GROUP BY user_id
         ) r ON r.user_id = u.id
         LEFT JOIN (
             SELECT user_id, SUM(status IN ('failed','expired')) AS failed FROM payments GROUP BY user_id
         ) p ON p.user_id = u.id
         WHERE u.role = 'customer'
         ORDER BY u.created_at DESC"
    )->fetch_all(MYSQLI_ASSOC);
} catch (mysqli_sql_exception $e) {
    error_log("customers load failed (run files/setup_platform.php?): " . $e->getMessage());
    $loadError = "Could not load customers. If you just updated TicketHUB, run files/setup_platform.php once.";
}

// ---- CSV export ----
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tickethub-customers-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', 'Name', 'Email', 'Phone', 'Joined', 'Bookings', 'Tickets', 'Spent (LKR)', 'Events attended',
                   'Refunded (LKR)', 'Pending refunds', 'Loyalty points', 'First purchase', 'Last purchase']);
    foreach ($customers as $c) {
        fputcsv($out, [$c['id'], $c['name'], $c['email'], phone_display($c['phone']), $c['created_at'], $c['bookings'], $c['tickets'],
                       $c['spent'], $c['attended'], $c['refunded_amount'], $c['pending_refunds'], $c['loyalty_points'],
                       $c['first_purchase'], $c['last_purchase']]);
    }
    fclose($out);
    exit;
}

// ---- Summary ----
$total = count($customers);
$s = ['new30' => 0, 'buyers' => 0, 'repeat' => 0, 'spent' => 0.0, 'tickets' => 0, 'refunded' => 0.0, 'points' => 0, 'pending' => 0, 'inactive' => 0];
$signups = [];
for ($i = 5; $i >= 0; $i--) {
    $signups[date('Y-m', strtotime("first day of -$i month"))] = 0;
}
foreach ($customers as $c) {
    $joined = strtotime($c['created_at']);
    if ($joined >= strtotime('-30 days')) { $s['new30']++; }
    if ((int)$c['bookings'] > 0) { $s['buyers']++; }
    if ((int)$c['bookings'] > 1) { $s['repeat']++; }
    if ((int)$c['bookings'] > 0 && strtotime($c['last_purchase']) < strtotime('-90 days')) { $s['inactive']++; }
    $s['spent']    += (float)$c['spent'];
    $s['tickets']  += (int)$c['tickets'];
    $s['refunded'] += (float)$c['refunded_amount'];
    $s['points']   += (int)$c['loyalty_points'];
    $s['pending']  += (int)$c['pending_refunds'];
    $month = date('Y-m', $joined);
    if (isset($signups[$month])) { $signups[$month]++; }
}
$buyerRate   = $total ? $s['buyers'] / $total * 100 : 0;
$avgPerBuyer = $s['buyers'] ? $s['spent'] / $s['buyers'] : 0;
$repeatRate  = $s['buyers'] ? $s['repeat'] / $s['buyers'] * 100 : 0;

$top = $customers;
usort($top, fn($a, $b) => (float)$b['spent'] <=> (float)$a['spent']);
$top = array_values(array_filter(array_slice($top, 0, 5), fn($c) => (float)$c['spent'] > 0));
$signupMax = max(1, max($signups));

function lkr(float $n): string { return 'LKR ' . number_format($n, 2); }
function initials(string $name): string {
    $out = '';
    foreach (array_slice(preg_split('/\s+/', trim($name)), 0, 2) as $p) { $out .= mb_strtoupper(mb_substr($p, 0, 1)); }
    return $out ?: '?';
}
function ago(?string $ts): string {
    if (!$ts) return '—';
    $d = (int)floor((time() - strtotime($ts)) / 86400);
    return $d <= 0 ? 'Today' : ($d === 1 ? 'Yesterday' : ($d < 30 ? "$d days ago" : date('M d, Y', strtotime($ts))));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php include __DIR__ . "/pwa_head.php"; ?>
<title>Customers - TicketHub</title>
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
.container { max-width: 1180px; margin: 0 auto; padding: 40px 48px 100px; }
.page-head { display: flex; justify-content: space-between; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 24px; }
h1 { font-family: var(--font-display); font-size: 28px; margin: 0 0 4px; }
.lead { color: var(--text-dim); font-size: 14px; margin: 0; }
.btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 10px 18px; border-radius: 100px; font-weight: 600; font-size: 13px;
    text-decoration: none; color: var(--text); border: 1px solid var(--border); background: rgba(255,255,255,0.05);
}
.btn:hover { background: rgba(255,255,255,0.1); }
.flash.err { padding: 12px 18px; border-radius: 12px; margin-bottom: 20px; font-size: 14px; background: rgba(239,107,107,0.14); border: 1px solid rgba(239,107,107,0.35); color: #FFA5A5; }

.kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 22px; animation: rise 0.5s ease-out both; }
.kpi { background: var(--surface); border: 1px solid var(--border); border-radius: 16px; padding: 18px 20px; }
.kpi .label { font-size: 12px; font-weight: 600; letter-spacing: 0.5px; text-transform: uppercase; color: var(--text-dim); margin-bottom: 8px; }
.kpi .value { font-family: var(--font-display); font-size: 24px; font-weight: 700; }
.kpi .value small { font-size: 12px; color: var(--text-dim); font-weight: 600; margin-right: 4px; }
.kpi .hint { font-size: 12px; color: var(--text-dim); margin-top: 6px; }
.meter { height: 6px; border-radius: 100px; background: rgba(255,255,255,0.07); margin-top: 10px; overflow: hidden; }
.meter span { display: block; height: 100%; border-radius: 100px; background: var(--violet); }

.grid-2 { display: grid; grid-template-columns: 1.3fr 1fr; gap: 22px; margin-bottom: 22px; }
.panel { background: var(--surface); border: 1px solid var(--border); border-radius: 18px; padding: 20px 22px; min-width: 0; animation: rise 0.5s 0.08s ease-out both; }
.panel h3 { font-family: var(--font-display); font-size: 16px; margin: 0 0 4px; }
.panel .sub { font-size: 12.5px; color: var(--text-dim); margin: 0 0 16px; }

.top-row { display: flex; align-items: center; gap: 12px; padding: 9px 0; border-bottom: 1px solid rgba(255,255,255,0.05); text-decoration: none; color: inherit; }
.top-row:last-child { border-bottom: none; }
.top-row:hover .nm { color: var(--violet); }
.rank { width: 20px; color: var(--text-muted); font-weight: 700; font-size: 13px; }
.av {
    width: 34px; height: 34px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center;
    font-size: 12.5px; font-weight: 700; color: #fff; background: linear-gradient(135deg, var(--violet), var(--rose));
}
.top-row .nm { font-weight: 600; font-size: 14px; }
.top-row .em { font-size: 12px; color: var(--text-dim); }
.top-row .amt { margin-left: auto; text-align: right; font-weight: 700; font-size: 13.5px; white-space: nowrap; }
.top-row .amt small { display: block; font-weight: 500; color: var(--text-dim); font-size: 11.5px; }

.bars { display: flex; align-items: flex-end; gap: 10px; height: 150px; padding-top: 10px; border-bottom: 1px solid rgba(255,255,255,0.15); }
.bars .col { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%; position: relative; }
.bars .bar { width: 100%; max-width: 38px; background: var(--violet); border-radius: 4px 4px 0 0; min-height: 0; transition: background 0.15s ease; }
.bars .col:hover .bar { background: #B8A0FB; }
.bars .n { font-size: 11.5px; font-weight: 700; margin-bottom: 4px; color: var(--text-dim); }
.bar-labels { display: flex; gap: 10px; margin-top: 6px; }
.bar-labels span { flex: 1; text-align: center; font-size: 11px; color: var(--text-muted); }

.toolbar { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; align-items: center; margin-bottom: 14px; }
.search {
    background: rgba(255,255,255,0.05); border: 1px solid var(--border); border-radius: 10px; color: var(--text);
    font-family: var(--font-body); font-size: 13px; padding: 10px 12px; width: 280px; outline: none;
}
.search:focus { border-color: var(--violet); box-shadow: 0 0 0 3px rgba(157,124,249,0.15); }
.seg { display: inline-flex; flex-wrap: wrap; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.seg button { background: transparent; border: none; color: var(--text-dim); font-family: var(--font-body); font-size: 12.5px; font-weight: 600; padding: 9px 12px; cursor: pointer; }
.seg button.active { background: rgba(157,124,249,0.2); color: var(--text); }

.table-wrap { overflow-x: auto; margin: 0 -22px; padding: 0 22px; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
th { text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); padding: 10px; border-bottom: 1px solid var(--border); white-space: nowrap; }
th[data-sort] { cursor: pointer; user-select: none; }
th[data-sort]:hover { color: var(--text); }
th .arrow { opacity: 0.4; margin-left: 3px; }
th.sorted .arrow { opacity: 1; color: var(--violet); }
td { padding: 11px 10px; border-bottom: 1px solid rgba(255,255,255,0.05); vertical-align: middle; }
tbody tr { cursor: pointer; }
tbody tr:hover td { background: rgba(255,255,255,0.03); }
td.num, th.num { text-align: right; white-space: nowrap; }
.who { display: flex; align-items: center; gap: 10px; }
.who a { color: var(--text); font-weight: 600; text-decoration: none; }
.who a:hover { color: var(--violet); }
.who small { display: block; color: var(--text-dim); font-size: 12px; font-weight: 400; }
.muted { color: var(--text-dim); font-size: 12px; }
.tag { display: inline-block; font-size: 11px; font-weight: 700; padding: 2px 8px; border-radius: 100px; margin: 2px 4px 0 0; white-space: nowrap; }
.tag.new { color: var(--green); background: rgba(111,207,151,0.12); }
.tag.vip { color: var(--amber); background: rgba(242,197,114,0.14); }
.tag.refund { color: #FFC2D4; background: rgba(240,114,156,0.14); }
.tag.quiet { color: var(--text-dim); background: rgba(255,255,255,0.06); }
.empty { text-align: center; padding: 40px 10px; color: var(--text-dim); font-size: 14px; }

.footer { text-align: center; padding: 28px; border-top: 1px solid var(--border); color: var(--text-dim); font-size: 13px; }
@keyframes rise { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }
@media (prefers-reduced-motion: reduce) { * { animation: none !important; transition: none !important; } }
@media (max-width: 980px) { .kpis { grid-template-columns: repeat(2, 1fr); } .grid-2 { grid-template-columns: 1fr; } }
@media (max-width: 640px) { .container { padding: 24px 16px 70px; } .kpis { grid-template-columns: 1fr; } .search { width: 100%; } .table-wrap { margin: 0 -16px; padding: 0 16px; } }
</style>
</head>
<body>
<?php require __DIR__ . "/nav.php"; ?>

<div class="container">
    <div class="page-head">
        <div>
            <h1>👥 Customers</h1>
            <p class="lead">Everyone who has a customer account on TicketHUB, and how they buy.</p>
        </div>
        <a class="btn" href="customers.php?export=csv">⬇ Export CSV</a>
    </div>

    <?php if ($loadError): ?><div class="flash err">⚠ <?php echo htmlspecialchars($loadError); ?></div><?php endif; ?>

    <section class="kpis">
        <div class="kpi">
            <div class="label">👥 Customers</div>
            <div class="value"><?php echo number_format($total); ?></div>
            <div class="hint">+<?php echo $s['new30']; ?> joined in the last 30 days</div>
        </div>
        <div class="kpi">
            <div class="label">🛒 Have bought tickets</div>
            <div class="value"><?php echo number_format($s['buyers']); ?></div>
            <div class="meter"><span style="width: <?php echo $buyerRate; ?>%;"></span></div>
            <div class="hint"><?php echo number_format($buyerRate, 0); ?>% of customers · <?php echo number_format($repeatRate, 0); ?>% of buyers came back</div>
        </div>
        <div class="kpi">
            <div class="label">💳 Customer spend</div>
            <div class="value"><small>LKR</small><?php echo number_format($s['spent'], 2); ?></div>
            <div class="hint"><?php echo number_format($s['tickets']); ?> tickets · avg <?php echo lkr($avgPerBuyer); ?> per buyer</div>
        </div>
        <div class="kpi">
            <div class="label">↩ Refunded</div>
            <div class="value"><small>LKR</small><?php echo number_format($s['refunded'], 2); ?></div>
            <div class="hint"><?php echo $s['pending']; ?> request(s) pending · <?php echo number_format($s['points']); ?> loyalty pts held</div>
        </div>
    </section>

    <div class="grid-2">
        <section class="panel">
            <h3>🏅 Top customers</h3>
            <p class="sub">Highest lifetime spend on confirmed bookings.</p>
            <?php if (!$top): ?>
                <div class="empty">No purchases yet.</div>
            <?php else: foreach ($top as $i => $c): ?>
                <a class="top-row" href="customer_details.php?id=<?php echo (int)$c['id']; ?>">
                    <span class="rank"><?php echo $i + 1; ?></span>
                    <span class="av"><?php echo htmlspecialchars(initials($c['name'])); ?></span>
                    <span><span class="nm"><?php echo htmlspecialchars($c['name']); ?></span><br><span class="em"><?php echo htmlspecialchars($c['email']); ?></span></span>
                    <span class="amt"><?php echo lkr((float)$c['spent']); ?><small><?php echo (int)$c['tickets']; ?> ticket(s) · <?php echo (int)$c['bookings']; ?> booking(s)</small></span>
                </a>
            <?php endforeach; endif; ?>
        </section>

        <section class="panel">
            <h3>📈 New customers per month</h3>
            <p class="sub">Sign-ups over the last 6 months.</p>
            <div class="bars" role="img" aria-label="New customers per month">
                <?php foreach ($signups as $month => $n): ?>
                    <div class="col" title="<?php echo date('F Y', strtotime("$month-01")); ?>: <?php echo $n; ?> new customer(s)">
                        <span class="n"><?php echo $n; ?></span>
                        <span class="bar" style="height: <?php echo $n / $signupMax * 85; ?>%;"></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="bar-labels">
                <?php foreach ($signups as $month => $n): ?><span><?php echo date('M', strtotime("$month-01")); ?></span><?php endforeach; ?>
            </div>
        </section>
    </div>

    <section class="panel">
        <div class="toolbar">
            <div>
                <h3>All customers</h3>
                <p class="sub" style="margin: 0;"><span id="shownCount"><?php echo $total; ?></span> of <?php echo $total; ?> shown · click a customer to see everything about them</p>
            </div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <input type="search" class="search" id="search" placeholder="Search name, email or phone…" aria-label="Search customers">
                <div class="seg" id="filters" role="group" aria-label="Filter customers">
                    <button type="button" class="active" data-f="all">All</button>
                    <button type="button" data-f="buyer">Buyers</button>
                    <button type="button" data-f="none">No purchases</button>
                    <button type="button" data-f="new">New</button>
                    <button type="button" data-f="refund">Refund pending</button>
                </div>
            </div>
        </div>

        <?php if (!$customers): ?>
            <div class="empty">No customers have signed up yet.</div>
        <?php else: ?>
        <div class="table-wrap">
            <table id="customersTable">
                <thead>
                    <tr>
                        <th data-sort="name" data-type="text">Customer <span class="arrow">↕</span></th>
                        <th>Phone</th>
                        <th data-sort="joined" data-type="num">Joined <span class="arrow">↕</span></th>
                        <th class="num" data-sort="bookings" data-type="num">Bookings <span class="arrow">↕</span></th>
                        <th class="num" data-sort="tickets" data-type="num">Tickets <span class="arrow">↕</span></th>
                        <th class="num" data-sort="spent" data-type="num">Spent <span class="arrow">↕</span></th>
                        <th data-sort="last" data-type="num">Last purchase <span class="arrow">↕</span></th>
                        <th class="num" data-sort="points" data-type="num">Points <span class="arrow">↕</span></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $vipThreshold = $top ? (float)end($top)['spent'] : 0;
                foreach ($customers as $c):
                    $isNew = strtotime($c['created_at']) >= strtotime('-30 days');
                    $flags = [];
                    if ((int)$c['bookings'] > 0) { $flags[] = 'buyer'; } else { $flags[] = 'none'; }
                    if ($isNew) { $flags[] = 'new'; }
                    if ((int)$c['pending_refunds'] > 0) { $flags[] = 'refund'; }
                ?>
                    <tr data-href="customer_details.php?id=<?php echo (int)$c['id']; ?>"
                        data-f="<?php echo implode(' ', $flags); ?>"
                        data-s="<?php echo htmlspecialchars(mb_strtolower($c['name'] . ' ' . $c['email'] . ' ' . $c['phone'] . ' ' . phone_display($c['phone']))); ?>"
                        data-name="<?php echo htmlspecialchars(mb_strtolower($c['name'])); ?>"
                        data-joined="<?php echo strtotime($c['created_at']); ?>"
                        data-bookings="<?php echo (int)$c['bookings']; ?>"
                        data-tickets="<?php echo (int)$c['tickets']; ?>"
                        data-spent="<?php echo (float)$c['spent']; ?>"
                        data-last="<?php echo $c['last_purchase'] ? strtotime($c['last_purchase']) : 0; ?>"
                        data-points="<?php echo (int)$c['loyalty_points']; ?>">
                        <td>
                            <div class="who">
                                <span class="av"><?php echo htmlspecialchars(initials($c['name'])); ?></span>
                                <span>
                                    <a href="customer_details.php?id=<?php echo (int)$c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></a>
                                    <small><?php echo htmlspecialchars($c['email']); ?></small>
                                    <?php if (!empty($c['is_guest'])): ?><span class="tag quiet" title="Bought without an account (no password set yet)">Guest</span><?php endif; ?>
                                    <?php if ($isNew): ?><span class="tag new">New</span><?php endif; ?>
                                    <?php if ($vipThreshold > 0 && (float)$c['spent'] >= $vipThreshold): ?><span class="tag vip">★ Top customer</span><?php endif; ?>
                                    <?php if ((int)$c['pending_refunds']): ?><span class="tag refund">↩ Refund pending</span><?php endif; ?>
                                    <?php if ((int)$c['bookings'] > 0 && strtotime($c['last_purchase']) < strtotime('-90 days')): ?><span class="tag quiet">Quiet 90+ days</span><?php endif; ?>
                                </span>
                            </div>
                        </td>
                        <td class="muted"><?php echo $c['phone'] ? htmlspecialchars(phone_display($c['phone'])) : '—'; ?></td>
                        <td class="muted"><?php echo date('M d, Y', strtotime($c['created_at'])); ?></td>
                        <td class="num"><?php echo (int)$c['bookings']; ?></td>
                        <td class="num"><?php echo (int)$c['tickets']; ?></td>
                        <td class="num"><b><?php echo lkr((float)$c['spent']); ?></b></td>
                        <td class="muted"><?php echo ago($c['last_purchase']); ?></td>
                        <td class="num"><?php echo number_format((int)$c['loyalty_points']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div class="empty" id="noMatches" style="display: none;">No customers match.</div>
        </div>
        <?php endif; ?>
    </section>
</div>

<?php require __DIR__ . "/footer.php"; ?>

<script>
(function () {
    const table = document.getElementById('customersTable');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    const rows = [...tbody.rows];
    const search = document.getElementById('search');
    const buttons = document.querySelectorAll('#filters button');
    let filter = 'all';

    function apply() {
        const q = search.value.trim().toLowerCase();
        let shown = 0;
        rows.forEach(r => {
            const ok = (filter === 'all' || r.dataset.f.split(' ').includes(filter)) && (!q || r.dataset.s.includes(q));
            r.style.display = ok ? '' : 'none';
            if (ok) shown++;
        });
        document.getElementById('shownCount').textContent = shown;
        document.getElementById('noMatches').style.display = shown ? 'none' : '';
    }
    search.addEventListener('input', apply);
    buttons.forEach(b => b.addEventListener('click', () => {
        buttons.forEach(x => x.classList.remove('active'));
        b.classList.add('active');
        filter = b.dataset.f;
        apply();
    }));

    // Click a row to open the customer
    rows.forEach(r => r.addEventListener('click', e => { if (!e.target.closest('a')) location.href = r.dataset.href; }));

    // Sortable columns
    let sortKey = null, dir = 1;
    table.querySelectorAll('th[data-sort]').forEach(th => th.addEventListener('click', () => {
        const key = th.dataset.sort, isNum = th.dataset.type === 'num';
        dir = sortKey === key ? -dir : (isNum ? -1 : 1); // numbers: biggest first
        sortKey = key;
        table.querySelectorAll('th').forEach(x => x.classList.remove('sorted'));
        th.classList.add('sorted');
        th.querySelector('.arrow').textContent = dir === 1 ? '↑' : '↓';
        rows.sort((a, b) => {
            const x = a.dataset[key], y = b.dataset[key];
            return (isNum ? (parseFloat(x) - parseFloat(y)) : x.localeCompare(y)) * dir;
        }).forEach(r => tbody.appendChild(r));
    }));
})();
</script>
</body>
</html>
