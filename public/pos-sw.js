const CACHE_NAME = 'popstar-pos-v3';
// ไลบรารีภายนอกที่หน้า POS ต้องใช้ตอนออฟไลน์ (เวอร์ชันล็อก จึง cache ได้ถาวร)
const EXTERNAL_ASSETS = ['https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js'];

const APP_SHELL = [
  './pos',
  './manifest.webmanifest',
  './pos-icon.svg',
  './pos-offline.js',
  './vendor/qrcodejs/qrcode.min.js',
  './vendor/bootstrap-icons/bootstrap-icons.min.css',
  './vendor/bootstrap-icons/fonts/bootstrap-icons.woff2',
  './vendor/alpinejs/alpine.min.js',
  './vendor/sweetalert2/sweetalert2.all.min.js'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(APP_SHELL)
        .then(() => Promise.all(EXTERNAL_ASSETS.map((url) => fetch(url, { mode: 'no-cors' })
          .then((res) => cache.put(url, res)).catch(() => null)))))
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

  if (EXTERNAL_ASSETS.includes(request.url)) {
    event.respondWith(caches.match(request.url).then((cached) => cached || fetch(request).then((res) => {
      const copy = res.clone();
      caches.open(CACHE_NAME).then((cache) => cache.put(request.url, copy));
      return res;
    })));
    return;
  }
  if (!isSameOrigin) {
    return;
  }
  // ping ต้องถึง Host จริงเสมอ ห้ามตอบจาก cache ไม่งั้นจะคิดว่าออนไลน์
  if (url.pathname.endsWith('/pos/ping')) {
    return;
  }
  const isPosPage = isSameOrigin && (url.pathname.endsWith('/pos') || url.pathname.endsWith('/pos/'));
  const isPosApi = isSameOrigin && (
    url.pathname.endsWith('/pos/products') ||
    url.pathname.endsWith('/pos/promotions') ||
    url.pathname.endsWith('/pos/shift') ||
    url.pathname.endsWith('/search/customers')
  );
  const isStaticAsset = isSameOrigin && (
    url.pathname.endsWith('/manifest.webmanifest') ||
    url.pathname.endsWith('/pos-icon.svg') ||
    url.pathname.includes('/vendor/') ||
    url.pathname.includes('/build/') ||
    url.pathname.endsWith('/pos-offline.js')
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
