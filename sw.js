/**
 * Service Worker · Jacks Taco Rock POS
 * Estrategia:
 *  - App shell: cache-first (HTML, CSS, JS)
 *  - API GET: network-first con fallback al último cache
 *  - API POST: solo network (no cachear mutaciones)
 *  - SSE: nunca pasa por el SW
 */
const CACHE = 'jacks-pos-v1';
const ROOT  = '/jacks-rock';

const APP_SHELL = [
  `${ROOT}/index.php`,
  `${ROOT}/login.php`,
  `${ROOT}/assets/css/styles.css`,
  `${ROOT}/assets/js/api.js`,
  `${ROOT}/assets/js/app.js`,
  `${ROOT}/manifest.json`,
];

self.addEventListener('install', e => {
  self.skipWaiting();
  e.waitUntil(
    caches.open(CACHE).then(c => c.addAll(APP_SHELL).catch(()=>{}))
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys().then(keys => Promise.all(
      keys.filter(k => k !== CACHE).map(k => caches.delete(k))
    )).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  // No tocar SSE (necesita stream persistente)
  if (url.pathname.endsWith('/api/sse.php')) return;

  // Mutaciones del API: pasar directo
  if (e.request.method !== 'GET') return;

  // Misma origin solamente
  if (url.origin !== self.location.origin) return;

  // Estrategia network-first con fallback a cache
  e.respondWith(
    fetch(e.request)
      .then(res => {
        const copy = res.clone();
        caches.open(CACHE).then(c => c.put(e.request, copy)).catch(()=>{});
        return res;
      })
      .catch(() => caches.match(e.request).then(r => r || new Response(
        JSON.stringify({ error: 'Sin conexión' }),
        { headers: { 'Content-Type': 'application/json' }, status: 503 }
      )))
  );
});
