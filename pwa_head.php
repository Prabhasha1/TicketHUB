<?php
/**
 * pwa_head.php
 * ------------------------------------------------------------
 * Progressive Web App tags, included inside every page's <head>.
 * - Links the web manifest + app icons (Android / iOS install)
 * - Sets the browser/status bar colour
 * - Registers the service worker (sw.js)
 * Paths are relative so it works both at the site root and in a subfolder.
 * ------------------------------------------------------------
 */
?>
<link rel="manifest" href="manifest.webmanifest">
<meta name="theme-color" content="#14121A">
<link rel="icon" href="favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="icons/favicon-32.png">
<link rel="icon" type="image/png" sizes="16x16" href="icons/favicon-16.png">
<link rel="icon" type="image/png" sizes="192x192" href="icons/icon-192.png">
<link rel="apple-touch-icon" href="icons/apple-touch-icon.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black">
<meta name="apple-mobile-web-app-title" content="TicketHub">
<script>
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('sw.js').catch(function (err) {
            console.warn('TicketHub service worker registration failed:', err);
        });
    });
}
</script>
