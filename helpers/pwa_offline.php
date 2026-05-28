<?php

declare(strict_types=1);

require_once __DIR__ . '/app_path.php';

/** Versi cache PWA — naikkan otomatis saat app.css berubah. */
function pwa_cache_version(): string
{
    $css = dirname(__DIR__) . '/assets/css/app.css';
    $mtime = is_file($css) ? (int) filemtime($css) : time();

    return 'pondok-pwa-' . $mtime;
}

/**
 * URL relatif (dengan base_path) untuk precache saat install.
 *
 * @return list<string>
 */
function pwa_precache_paths(string $basePath): array
{
    $base = rtrim($basePath, '/');
    $prefix = $base === '' ? '' : $base;

    return [
        $prefix . '/offline.php',
        $prefix . '/assets/css/app.css',
        $prefix . '/assets/js/app-shell.js',
        $prefix . '/assets/img/stempel-pondok.png',
        $prefix . '/assets/css/auth-portal.css',
        $prefix . '/assets/css/wali-portal.css',
    ];
}

/**
 * Generate isi service worker (offline + opsional FCM).
 */
function pwa_render_service_worker_js(PDO $pdo, string $basePath, bool $includeFcm = true): string
{
    $basePath = rtrim($basePath, '/');
    $baseJs = addslashes($basePath === '' ? '' : $basePath);
    $cacheVer = addslashes(pwa_cache_version());
    $precacheJson = json_encode(pwa_precache_paths($basePath), JSON_UNESCAPED_SLASHES);

    $fcmBlock = '';
    if ($includeFcm) {
        require_once __DIR__ . '/push_fcm.php';
        $cfg = push_fcm_web_config($pdo);
        $apiKey = addslashes((string) ($cfg['apiKey'] ?? ''));
        $projectId = addslashes((string) ($cfg['projectId'] ?? ''));
        $senderId = addslashes((string) ($cfg['senderId'] ?? ''));
        $appId = addslashes((string) ($cfg['appId'] ?? ''));
        $enabled = (($cfg['enabled'] ?? '') === '1' && $apiKey !== '' && $appId !== '') ? '1' : '0';
        $authDomain = addslashes($projectId !== '' ? $projectId . '.firebaseapp.com' : '');

        $fcmBlock = <<<JS

var PWA_FCM_ENABLED = '{$enabled}' === '1';
if (PWA_FCM_ENABLED) {
  try {
    importScripts('https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js');
    importScripts('https://www.gstatic.com/firebasejs/10.12.2/firebase-messaging-compat.js');
    firebase.initializeApp({
      apiKey: '{$apiKey}',
      authDomain: '{$authDomain}',
      projectId: '{$projectId}',
      messagingSenderId: '{$senderId}',
      appId: '{$appId}'
    });
    var messaging = firebase.messaging();
    messaging.onBackgroundMessage(function (payload) {
      var title = (payload.notification && payload.notification.title) || 'Pondok';
      var options = {
        body: (payload.notification && payload.notification.body) || '',
        data: payload.data || {},
        tag: (payload.data && payload.data.category) || 'pondok'
      };
      return self.registration.showNotification(title, options);
    });
  } catch (e) {}
}

self.addEventListener('notificationclick', function (event) {
  event.notification.close();
  var base = PWA_BASE;
  var raw = (event.notification.data && event.notification.data.url) || (base || '/');
  var url = raw;
  try {
    if (typeof raw === 'string' && raw.indexOf('://') < 0 && raw.charAt(0) === '/' && base && base !== '/' && raw.indexOf(base) !== 0) {
      url = base.replace(/\/$/, '') + raw;
    }
  } catch (err) {}
  event.waitUntil(clients.openWindow(url));
});

JS;
    }

    return <<<JS
/* PWA Pondok — cache offline + push (FCM) */
var PWA_BASE = '{$baseJs}';
var PWA_CACHE = '{$cacheVer}';
var PWA_PRECACHE = {$precacheJson};

function pwaUrl(path) {
  path = String(path || '');
  if (path.charAt(0) !== '/') {
    path = '/' + path;
  }
  return (PWA_BASE || '') + path;
}

function pwaIsApiRequest(url) {
  return url.pathname.indexOf('/api/') >= 0;
}

function pwaIsStaticAsset(url) {
  return /\.(css|js|map|png|jpe?g|gif|webp|ico|woff2?|svg)$/i.test(url.pathname);
}

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(PWA_CACHE).then(function (cache) {
      return cache.addAll(
        PWA_PRECACHE.map(function (p) {
          return new Request(p, { credentials: 'same-origin' });
        })
      ).catch(function () {});
    }).then(function () {
      return self.skipWaiting();
    })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        keys.filter(function (k) { return k !== PWA_CACHE; }).map(function (k) {
          return caches.delete(k);
        })
      );
    }).then(function () {
      return self.clients.claim();
    })
  );
});

self.addEventListener('fetch', function (event) {
  var req = event.request;
  if (req.method !== 'GET') {
    return;
  }
  var url = new URL(req.url);
  if (url.origin !== self.location.origin) {
    return;
  }
  if (pwaIsApiRequest(url)) {
    return;
  }

  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(function () {
        return caches.match(pwaUrl('/offline.php')).then(function (cached) {
          return cached || new Response('Tidak ada koneksi.', {
            status: 503,
            headers: { 'Content-Type': 'text/plain; charset=utf-8' }
          });
        });
      })
    );
    return;
  }

  if (pwaIsStaticAsset(url)) {
    event.respondWith(
      caches.match(req).then(function (cached) {
        var fetchPromise = fetch(req).then(function (res) {
          if (res && res.ok) {
            var clone = res.clone();
            caches.open(PWA_CACHE).then(function (cache) {
              cache.put(req, clone);
            });
          }
          return res;
        }).catch(function () {
          return cached;
        });
        return cached || fetchPromise;
      })
    );
  }
});

self.addEventListener('message', function (event) {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});
{$fcmBlock}
JS;
}
