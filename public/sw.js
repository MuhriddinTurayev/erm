/* Oftob_Sohtumon — сервис-воркер: программа ставится на телефон и открывается без интернета */
const V = 'oftob-v1';
const STATIC = /\/(assets|app\/(css|js)|icons)\/|fonts\.(googleapis|gstatic)\.com|cdnjs\.cloudflare\.com/;

self.addEventListener('install', e => {
  e.waitUntil(caches.open(V).then(c => c.addAll(['/offline.html', '/assets/tokens.css', '/assets/ui.css', '/icons/icon-192.png'])).then(() => self.skipWaiting()));
});
self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== V).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', e => {
  const r = e.request;
  if (r.method !== 'GET') return;
  const u = new URL(r.url);
  // API, файлы и вход — только по сети
  if (u.origin === location.origin && /^\/(api|files|company\/files|webhook|auth|logout|login)/.test(u.pathname)) return;
  if (STATIC.test(r.url)) {
    // статика: сразу из кэша, обновляем в фоне
    e.respondWith(caches.open(V).then(c => c.match(r).then(hit => {
      const net = fetch(r).then(res => { if (res.ok) c.put(r, res.clone()); return res; }).catch(() => hit);
      return hit || net;
    })));
    return;
  }
  if (r.mode === 'navigate') {
    // страницы: сначала сеть, без сети — последняя сохранённая версия или страница «нет связи»
    e.respondWith(fetch(r).then(res => {
      if (res.ok && u.origin === location.origin) caches.open(V).then(c => c.put(r, res.clone()));
      return res;
    }).catch(() => caches.match(r).then(hit => hit || caches.match('/offline.html'))));
  }
});
