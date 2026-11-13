/* Immo Maurice — hors ligne et notifications */
const CACHE = 'immo-v5';
const SHELL = ['./', 'index.html', 'assets/app.css?v=4', 'assets/app.js?v=4', 'assets/icon-192.png', 'manifest.webmanifest'];

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(CACHE).then((c) => c.addAll(SHELL)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((ks) => Promise.all(ks.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

// Réseau d'abord, copie locale si le réseau manque : les dernières annonces restent lisibles hors ligne.
self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== location.origin) return;
  const isApi = url.pathname.includes('/api/');
  if (isApi && !/[?&]a=(list|get)\b/.test(url.search)) return;
  e.respondWith(
    fetch(req).then((res) => {
      if (res.ok) { const copy = res.clone(); caches.open(CACHE).then((c) => c.put(req, copy)); }
      return res;
    }).catch(() => caches.match(req, { ignoreSearch: !isApi && url.pathname.endsWith('/') }).then((r) => r || caches.match('index.html')))
  );
});

self.addEventListener('push', (e) => {
  e.waitUntil((async () => {
    let items = [];
    try {
      const res = await fetch('api/index.php?a=push_pending', { credentials: 'same-origin', cache: 'no-store' });
      if (res.ok) items = await res.json();
    } catch (err) { /* hors ligne : message générique ci-dessous */ }
    if (!Array.isArray(items) || !items.length) items = [{ title: 'Nouvelles annonces', body: 'Ouvre Immo Maurice pour les voir.', tag: 'generique', url: './#/' }];
    for (const n of items) {
      await self.registration.showNotification(n.title, {
        body: n.body, tag: n.tag, icon: 'assets/icon-192.png', badge: 'assets/badge.png', data: { url: n.url || './#/' }, renotify: true,
      });
    }
  })());
});

self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  const target = new URL(e.notification.data && e.notification.data.url ? e.notification.data.url.replace(/^\//, './') : './', self.registration.scope).href;
  e.waitUntil(clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
    for (const c of list) { if ('focus' in c) { c.navigate(target); return c.focus(); } }
    return clients.openWindow(target);
  }));
});
