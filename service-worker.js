// Cadurix — Service Worker v5.5
const CACHE_NAME = 'cadurix-v5.9'; // ⬆️ bump : ZXing auto-hébergé (scanner hors-ligne)

// Uniquement les assets statiques — JAMAIS de PHP, jamais '/', jamais /admin
const PRECACHE = [
  '/manifest.json',
  '/assets/js/chart.umd.min.js',
  '/scanner/zxing-0.21.3.min.js', // scanner ZXing auto-hébergé → dispo hors-ligne
];

const EXTERNAL_DOMAINS = [
  'cdn.jsdelivr.net',
  'cdnjs.cloudflare.com',
  'api.anthropic.com',
  'fonts.googleapis.com',
  'fonts.gstatic.com',
];

// ── Installation ──────────────────────────────────────────
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      // addAll est atomique : on cache chaque asset isolément pour qu'un 404 ne fasse pas échouer tout l'install
      .then(cache => Promise.all(
        PRECACHE.map(url => cache.add(url).catch(err => console.warn('[SW] précache ignoré:', url, err)))
      ))
      .then(() => self.skipWaiting())
  );
});

// ── Activation — purge anciens caches ─────────────────────
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys =>
      Promise.all(keys.filter(k => k !== CACHE_NAME).map(k => caches.delete(k)))
    ).then(() => self.clients.claim())
  );
});

// ── Fetch ─────────────────────────────────────────────────
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);

  // Ignore tout schéma non-http (chrome-extension://, data:, blob:…) — cache.put les refuse
  if (url.protocol !== 'http:' && url.protocol !== 'https:') return;

  // Toujours réseau pour les domaines externes
  if (EXTERNAL_DOMAINS.some(d => url.hostname.includes(d))) return;

  // Toujours réseau pour POST / AJAX / navigations
  if (event.request.method !== 'GET') return;
  if (event.request.mode === 'navigate') return; // toute navigation = réseau direct (jamais de page blanche servie par le SW)
  if (url.searchParams.has('ajax')) return;

  // Toujours réseau pour TOUTES les pages PHP, la racine et l'admin — sans exception
  if (url.pathname.endsWith('.php') || url.pathname === '/' || url.pathname.includes('/admin')) return;

  // Code applicatif (.js/.css) : NETWORK-FIRST — le fichier frais du serveur gagne toujours,
  // le cache ne sert que de secours hors-ligne. Fini le JS figé après une mise à jour.
  if (url.pathname.endsWith('.js') || url.pathname.endsWith('.css')) {
    event.respondWith(
      fetch(event.request).then(response => {
        if (response && response.status === 200 && response.type === 'basic') {
          const clone = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
        }
        return response;
      }).catch(() => caches.match(event.request).then(cached =>
        cached || new Response('Ressource non disponible hors ligne.', {
          status: 503, headers: { 'Content-Type': 'text/plain' }
        })
      ))
    );
    return;
  }

  // Autres assets statiques (images, polices, libs) : cache-first
  event.respondWith(
    caches.match(event.request).then(cached => {
      if (cached) return cached;
      return fetch(event.request).then(response => {
        if (response && response.status === 200 && response.type === 'basic') {
          const clone = response.clone();
          caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
        }
        return response;
      }).catch(() => new Response('Ressource non disponible hors ligne.', {
        status: 503, headers: { 'Content-Type': 'text/plain' }
      }));
    })
  );
});

// ── Push notifications ────────────────────────────────────
self.addEventListener('push', event => {
  const data = event.data ? event.data.json() : { title: 'Cadurix', body: 'Nouvelle notification' };
  event.waitUntil(
    self.registration.showNotification(data.title || 'Cadurix', {
      body: data.body || '',
      icon: '/assets/img/icon-192.png',
      badge: '/assets/img/badge-72.png',
      data: { url: data.url || '/' }
    })
  );
});

self.addEventListener('notificationclick', event => {
  event.notification.close();
  event.waitUntil(clients.openWindow(event.notification.data.url || '/'));
});
