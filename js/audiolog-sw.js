/**
 * Audiolog Service Worker
 * @author Jhonatan Jaworski
 * @copyright 2025
 *
 * Lightweight SW for the audiolog NextCloud app.
 *
 * Strategy:
 *   - Cache-first for own static assets:
 *       /apps/audiolog/css/
 *       /apps/audiolog/js/
 *       /apps/audiolog/img/
 *   - Network-first (with no-cache fallback) for API calls:
 *       /apps/audiolog/api/
 *       /index.php/apps/audiolog/api/
 *   - Pass through (no fetch handling) everything else, including:
 *       - WebSocket upgrades (wss://generativelanguage.googleapis.com)
 *       - NextCloud core endpoints (/index.php/..., /ocs/..., /apps/files/...)
 *       - cross-origin requests (Gemini API, Files API uploads, etc.)
 *
 * Known limitations of the resulting PWA:
 *   - iOS Safari does NOT allow recording with the screen off / app backgrounded.
 *     Once the user locks the device, the MediaRecorder is suspended.
 *   - Android Chrome works while the screen is on (Wake Lock is already
 *     requested by the main JS), but Android also throttles "true" background
 *     recording with the tab hidden — the PWA is not a substitute for a native
 *     pocket recorder.
 *   - Push notifications are intentionally NOT implemented here — out of scope.
 *   - The PWA install gives a home-screen shortcut and an app-like (standalone)
 *     UI; that is its main value over a regular bookmark.
 */

'use strict';

const CACHE_VERSION = 'audiolog-v1';
const APP_PREFIX = '/apps/audiolog/';
const API_PATTERNS = [
    '/apps/audiolog/api/',
    '/index.php/apps/audiolog/api/',
];
const STATIC_PATTERNS = [
    '/apps/audiolog/css/',
    '/apps/audiolog/js/',
    '/apps/audiolog/img/',
];

// ----- install: warm cache (best-effort) -------------------------------------
self.addEventListener('install', (event) => {
    // Activate the new SW as soon as it finishes installing.
    self.skipWaiting();
    event.waitUntil(
        caches.open(CACHE_VERSION).then((cache) => {
            // Best-effort warm; if any of these 404 we don't fail the install.
            return cache.addAll([
                APP_PREFIX + 'img/icon-192.svg',
                APP_PREFIX + 'img/icon-512.svg',
            ]).catch(() => undefined);
        }),
    );
});

// ----- activate: drop old caches --------------------------------------------
self.addEventListener('activate', (event) => {
    event.waitUntil((async () => {
        const keys = await caches.keys();
        await Promise.all(
            keys
                .filter((k) => k.startsWith('audiolog-') && k !== CACHE_VERSION)
                .map((k) => caches.delete(k)),
        );
        await self.clients.claim();
    })());
});

// ----- fetch -----------------------------------------------------------------
self.addEventListener('fetch', (event) => {
    const req = event.request;

    // Only handle GET — POST/PUT/etc. always go to network.
    if (req.method !== 'GET') return;

    let url;
    try {
        url = new URL(req.url);
    } catch (_) {
        return;
    }

    // Same-origin only — let cross-origin (Gemini, Files API, etc.) pass through.
    if (url.origin !== self.location.origin) return;

    const path = url.pathname;

    // Never intercept WebSocket upgrades. Browsers don't even fire `fetch` for
    // ws/wss, but be explicit just in case.
    if (req.headers.get('upgrade') === 'websocket') return;

    // API: network-first, no caching of responses (they're user-specific).
    if (API_PATTERNS.some((p) => path.startsWith(p))) {
        event.respondWith(networkFirstNoCache(req));
        return;
    }

    // Static app assets: cache-first.
    if (STATIC_PATTERNS.some((p) => path.startsWith(p))) {
        event.respondWith(cacheFirst(req));
        return;
    }

    // Default: don't intercept (let the browser do its normal thing).
});

/**
 * Cache-first: serve from cache, fall back to network and populate cache on
 * success. Failed network requests (offline) just propagate.
 */
async function cacheFirst(request) {
    const cache = await caches.open(CACHE_VERSION);
    const cached = await cache.match(request);
    if (cached) return cached;

    try {
        const response = await fetch(request);
        // Only cache successful, basic (same-origin) responses.
        if (response && response.ok && response.type === 'basic') {
            // Clone before caching — body is a one-shot stream.
            cache.put(request, response.clone()).catch(() => undefined);
        }
        return response;
    } catch (err) {
        // Last-ditch: maybe a stale cached copy exists for a slightly different
        // URL (with/without query string). Otherwise just rethrow.
        const fallback = await cache.match(request, { ignoreSearch: true });
        if (fallback) return fallback;
        throw err;
    }
}

/**
 * Network-first without caching the response. We just want offline detection
 * to surface a clean error rather than the SW intercepting and serving a
 * stale API payload.
 */
async function networkFirstNoCache(request) {
    return fetch(request);
}
