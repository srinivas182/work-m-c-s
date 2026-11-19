// WURK's service worker (Sprint 24.5): the app opens without a signal, showing what was last seen. Built files are
// kept (they're versioned); pages and their data are fetched fresh when possible, else the last copy is shown.
// Nothing is cached for other origins, and nothing sent (POST) is touched here — the page queues those itself.
const CACHE = 'wurk-v2'; // v2: push notifications (26.2)

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

// Push notifications (Sprint 26.2): WURK's server sends them encrypted for this phone; tapping one opens its page
// (in an open WURK window when there is one). Only WURK's own pages are opened.
self.addEventListener('push', (event) => {
  let data = {};
  try {
    data = event.data ? event.data.json() : {};
  } catch {
    data = { title: 'WURK', body: event.data ? event.data.text() : '' };
  }
  const title = data.business ? `${data.title} · ${data.business}` : data.title || 'WURK';
  event.waitUntil(self.registration.showNotification(title, { body: data.body || '', tag: data.tag || undefined, renotify: Boolean(data.tag), icon: '/images/app-icon-192.png', badge: '/images/app-badge-96.png', data: { url: data.url || '/' } }));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  let url;
  try {
    url = new URL(event.notification.data?.url || '/', self.location.origin);
  } catch {
    url = new URL('/', self.location.origin);
  }
  if (url.origin !== self.location.origin) url = new URL('/', self.location.origin);
  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
      const open = windows.find((w) => new URL(w.url).origin === url.origin);
      return open ? open.navigate(url.href).then((w) => (w || open).focus()) : self.clients.openWindow(url.href);
    }),
  );
});
