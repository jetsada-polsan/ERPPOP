const CACHE_NAME = 'popstar-pos-v2';

const APP_SHELL = [
  './pos',
  './manifest.webmanifest',
  './pos-icon.svg',
  './vendor/bootstrap-icons/bootstrap-icons.min.css',
  './vendor/bootstrap-icons/fonts/bootstrap-icons.woff2',
  './vendor/alpinejs/alpine.min.js',
  './vendor/sweetalert2/sweetalert2.all.min.js'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(APP_SHELL))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.map((key) => key === CACHE_NAME ? null : caches.delete(key))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;

  if (request.method !== 'GET') {
    return;
  }

  const url = new URL(request.url);
  const isSameOrigin = url.origin === self.location.origin;
  const isPosPage = isSameOrigin && (url.pathname.endsWith('/pos') || url.pathname.endsWith('/pos/'));
  const isPosApi = isSameOrigin && (
    url.pathname.endsWith('/pos/products') ||
    url.pathname.endsWith('/search/customers')
  );
  const isStaticAsset = isSameOrigin && (
    url.pathname.endsWith('/manifest.webmanifest') ||
    url.pathname.endsWith('/pos-icon.svg') ||
    url.pathname.includes('/vendor/')
  );

  if (isPosApi) {
    event.respondWith(fetch(request).catch(() => caches.match(request)));
    return;
  }

  if (!isPosPage && !isStaticAsset) {
    return;
  }

  if (isPosPage) {
    event.respondWith(
      fetch(request)
        .then((response) => {
          const copy = response.clone();
          if (response.ok) {
            caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
          }
          return response;
        })
        .catch(() => caches.match(request))
    );
    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => {
      return cached || fetch(request).then((response) => {
        const copy = response.clone();
        if (response.ok) {
          caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
        }
        return response;
      });
    })
  );
});
