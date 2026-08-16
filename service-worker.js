/**
 * Cash Book PWA - Service Worker
 * Version: 2.0.0
 * Features: 
 * - Network First for HTML/PHP (Always get fresh data if possible)
 * - Cache First for Assets (CSS, JS, Images, Fonts)
 * - Offline Fallback for Navigation
 * - Background Sync Support (Conceptual, uses postMessage for trigger)
 */

const CACHE_VERSION = 'cashbook-v17';
const STATIC_CACHE = `static-${CACHE_VERSION}`;
const DYNAMIC_CACHE = `dynamic-${CACHE_VERSION}`;


// Assets that must be available offline
const PRECACHE_ASSETS = [
    './',
    'index.php',
    'add.php',
    'accounts.php',
    'report.php',
    'settings.php',
    'help.php',
    'login.php',
    'register.php',
    'manifest.php',
    'assets/css/style.css',
    'assets/css/index.css',
    'assets/js/app.js',
    'assets/js/index.js',
    'assets/js/voice-assistant.js',
    'assets/js/report.js',
    'assets/js/offline-sync.js',
    'assets/js/jspdf.umd.min.js',
    'assets/js/jspdf.plugin.autotable.min.js',
    'assets/fonts/NotoSans-Regular.ttf',
    'assets/icons/icon.svg',
    'assets/icons/icon-192.png',
    'assets/icons/icon-512.png',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css',
    'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap'
];

// Helper to determine if a request is for an asset
const isAsset = (url) => {
    return url.match(/\.(js|css|png|jpg|jpeg|gif|svg|ico|woff|woff2|ttf|otf|json)$/) || 
           url.includes('fonts.googleapis.com') || 
           url.includes('cdnjs.cloudflare.com') ||
           url.includes('fonts.gstatic.com');
};

// Install Event - Pre-cache static assets
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(STATIC_CACHE).then(cache => {
            console.log('[SW] Pre-caching static assets');
            return Promise.allSettled(
                PRECACHE_ASSETS.map(url => {
                    return fetch(url).then(response => {
                        if (response.ok) return cache.put(url, response);
                        throw new Error(`Failed to fetch ${url}`);
                    }).catch(err => console.warn(`[SW] Pre-cache failed for ${url}:`, err));
                })
            );
        })
    );
    self.skipWaiting();
});

// Activate Event - Clean up old caches
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => {
            return Promise.all(
                keys.filter(key => key !== STATIC_CACHE && key !== DYNAMIC_CACHE)
                    .map(key => caches.delete(key))
            );
        })
    );
    self.clients.claim();
});

// Fetch Event - Dynamic Caching Strategy
self.addEventListener('fetch', event => {
    const { request } = event;
    const url = new URL(request.url);

    // Skip non-GET and chrome-extension requests
    if (request.method !== 'GET' || url.protocol === 'chrome-extension:') return;

    // Strategy for Assets: Network First for local JS/CSS, Cache First for media/fonts
    if (isAsset(request.url)) {
        const isLocalScriptOrStyle = request.url.includes('/assets/js/') || request.url.includes('/assets/css/');
        if (isLocalScriptOrStyle) {
            event.respondWith(
                fetch(request).then(networkResponse => {
                    if (networkResponse && networkResponse.status === 200) {
                        const cacheCopy = networkResponse.clone();
                        caches.open(STATIC_CACHE).then(cache => cache.put(request, cacheCopy));
                    }
                    return networkResponse;
                }).catch(() => {
                    return caches.match(request);
                })
            );
            return;
        }

        event.respondWith(
            caches.match(request).then(cachedResponse => {
                if (cachedResponse) return cachedResponse;

                return fetch(request).then(networkResponse => {
                    if (networkResponse && networkResponse.status === 200) {
                        const cacheCopy = networkResponse.clone();
                        caches.open(STATIC_CACHE).then(cache => cache.put(request, cacheCopy));
                    }
                    return networkResponse;
                }).catch(() => {
                    // Fail silently for external assets
                });
            })
        );
        return;
    }


    // Strategy for Pages (HTML/PHP): Network First, fallback to Cache
    event.respondWith(
        fetch(request)
            .then(networkResponse => {
                // If network works, update the dynamic cache
                if (networkResponse && networkResponse.status === 200) {
                    const cacheCopy = networkResponse.clone();
                    caches.open(DYNAMIC_CACHE).then(cache => cache.put(request, cacheCopy));
                }
                return networkResponse;
            })
            .catch(() => {
                // Network failed, try cache
                return caches.match(request, { ignoreSearch: true }).then(cachedResponse => {
                    if (cachedResponse) return cachedResponse;

                    // If it's a navigation request and not in cache, try index.php or login.php
                    if (request.mode === 'navigate') {
                        return caches.match('index.php') || caches.match('login.php');
                    }
                });
            })
    );
});

// Listen for messages from client (e.g. trigger sync)
self.addEventListener('message', event => {
    if (event.data.action === 'skipWaiting') {
        self.skipWaiting();
    }
});
