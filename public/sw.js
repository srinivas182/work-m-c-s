// WURK's service worker (Sprint 24.5): the app opens without a signal, showing what was last seen. Built files are
// kept (they're versioned); pages and their data are fetched fresh when possible, else the last copy is shown.
// Nothing is cached for other origins, and nothing sent (POST) is touched here — the page queues those itself.
const CACHE = 'wurk-v1';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => {
  event.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (event) => {
  const req = event.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== self.location.origin) return;
  if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/i18n/')) {
    // Versioned files: from the phone first.
    event.respondWith(caches.open(CACHE).then(async (cache) => (await cache.match(req)) ?? fetch(req).then((res) => (res.ok && cache.put(req, res.clone()), res))));
    return;
  }
  const page = req.mode === 'navigate' || req.headers.get('X-Inertia') === 'true';
  if (!page) return;
  event.respondWith(
    fetch(req)
      .then((res) => {
        if (res.ok) caches.open(CACHE).then((cache) => cache.put(req, res.clone()));
        return res;
      })
      .catch(async () => (await caches.match(req)) ?? new Response('You’re offline, and this page hasn’t been opened on this phone yet.', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } })),
  );
});
