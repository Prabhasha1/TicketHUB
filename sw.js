/**
 * sw.js
 * ------------------------------------------------------------
 * TicketHub service worker.
 * - PHP pages: always network (sessions, prices, ticket status stay live);
 *   falls back to offline.html when there is no connection.
 * - Static assets (icons, event photos, css/js, fonts): stale-while-revalidate.
 * - Never touches POST requests, payments, webhooks or login flows.
 * Bump CACHE_VERSION whenever this file or offline.html changes.
 * ------------------------------------------------------------
 */
const CACHE_VERSION = 'tickethub-v2';
const STATIC_CACHE  = CACHE_VERSION + '-static';
const PRECACHE = [
    'offline.html',
    'icons/icon-192.png',
    'icons/icon-512.png',
    'icons/apple-touch-icon.png',
    'icons/favicon-32.png',
    'favicon.ico',
    'manifest.webmanifest'
];
const BYPASS = /(stripe_webhook|login_process|register_process|google_login|google_callback|logout|payment_success|payment_cancelled)\.php/;

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(
            keys.filter((k) => !k.startsWith(CACHE_VERSION)).map((k) => caches.delete(k))
        )).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    const sameOrigin = url.origin === self.location.origin;
    const isFont = /fonts\.(googleapis|gstatic)\.com$/.test(url.hostname);
    if (!sameOrigin && !isFont) return;
    if (sameOrigin && BYPASS.test(url.pathname)) return;

    // Page navigations: network only, offline page as fallback.
    if (req.mode === 'navigate') {
        event.respondWith(
            fetch(req).catch(() => caches.match('offline.html'))
        );
        return;
    }

    // Static assets: serve cached copy fast, refresh in background.
    if (isFont || /\.(png|jpe?g|gif|webp|svg|ico|css|js|woff2?)$/i.test(url.pathname)) {
        event.respondWith(
            caches.open(STATIC_CACHE).then((cache) =>
                cache.match(req).then((cached) => {
                    const network = fetch(req).then((res) => {
                        if (res && (res.ok || res.type === 'opaque')) cache.put(req, res.clone());
                        return res;
                    }).catch(() => cached);
                    return cached || network;
                })
            )
        );
    }
});
