<?php

declare(strict_types=1);

require_once __DIR__ . '/app_path.php';

/** Versi cache PWA — naikkan otomatis saat CSS inti berubah. */
function pwa_cache_version(): string
{
    $root = dirname(__DIR__);
    $parts = [];
    foreach ([
        '/assets/css/app.css',
        '/assets/css/auth-portal.css',
        '/assets/css/offline-sync.css',
        '/assets/css/presensi-scan.css',
        '/assets/css/cashless-scan.css',
        '/assets/vendor/bootstrap/5.3.3/bootstrap.min.css',
        '/assets/vendor/fontawesome/6.5.2/all.min.css',
        '/assets/vendor/html5-qrcode/2.3.8/html5-qrcode.min.js',
        '/assets/js/app-shell.js',
        '/assets/js/pwa-register.js',
        '/assets/js/offline-sync.js',
        '/assets/js/keuangan-offline-db.js',
        '/assets/js/theme-mode.js',
        '/assets/js/presensi-scan-timer.js',
        '/assets/js/presensi-scan-camera.js',
        '/assets/js/presensi-scan-feedback.js',
        '/assets/js/login-scan-kegiatan.js',
        '/keuangan/cashless_scan.php',
        '/koperasi/scan.php',
        '/keuangan/offline-data.php',
        '/keuangan/neraca.php',
        '/keuangan/arus-kas.php',
        '/keuangan/riwayat_pembayaran.php',
        '/keuangan/cashless_laporan.php',
        '/presensi/scan.php',
        '/poin/input.php',
        '/assets/js/santri-select.js',
    ] as $rel) {
        $full = $root . $rel;
        $parts[] = is_file($full) ? (string) filemtime($full) : '0';
    }

    return 'pondok-pwa-' . implode('-', $parts);
}

/**
 * Logo pondok, avatar default, dan aset identitas untuk tampilan offline.
 *
 * @return list<string>
 */
function pwa_media_precache_paths(string $basePath, ?PDO $pdo = null): array
{
    require_once __DIR__ . '/app.php';

    $base = rtrim($basePath, '/');
    $prefix = $base === '' ? '' : $base;
    $paths = [
        $prefix . app_pwa_default_icon_src(),
        $prefix . '/assets/images/avatar-default.svg',
        $prefix . '/assets/images/avatar-default-laki.svg',
        $prefix . '/assets/images/avatar-default-perempuan.svg',
    ];

    $pdo = app_pwa_resolve_pdo($pdo);
    if ($pdo !== null) {
        if (function_exists('pwa_brand_icon_relative_path')) {
            foreach ([192, 512] as $px) {
                $iconRel = pwa_brand_icon_relative_path($pdo, $px);
                if ($iconRel !== '') {
                    $paths[] = $prefix . '/' . ltrim($iconRel, '/');
                }
                $maskRel = pwa_brand_icon_relative_path($pdo, 512, true);
                if ($maskRel !== '') {
                    $paths[] = $prefix . '/' . ltrim($maskRel, '/');
                }
            }
        }
        $logo = app_pondok_logo_src($pdo);
        if ($logo !== '') {
            $paths[] = $prefix . '/' . ltrim($logo, '/');
        }
    }
    $paths[] = $prefix . '/assets/img/pwa-splash-bg.svg';

    return array_values(array_unique($paths));
}

/** CSS/JS inti agar layout & ikon tetap rapi saat offline (tanpa query ?v=). */
function pwa_ui_static_precache_relative_paths(): array
{
    $paths = [
        '/assets/css/app.css',
        '/assets/css/pwa-ui.css',
        '/assets/css/offline-sync.css',
        '/assets/css/auth-portal.css',
        '/assets/css/wali-portal.css',
        '/assets/css/ikhtibar-soal-arabic.css',
        '/assets/css/ikhtibar-kerjakan.css',
        '/assets/js/app-shell.js',
        '/assets/js/ikhtibar-kerjakan-ui.js',
        '/assets/js/ikhtibar-pratinjau-nilai.js',
        '/assets/js/theme-mode.js',
        '/assets/js/offline-sync.js',
        '/assets/js/keuangan-offline-db.js',
        '/assets/js/pwa-media-cache.js',
        '/assets/js/pwa-register.js',
        '/assets/js/app-datetime-24h.js',
        '/assets/images/avatar-default.svg',
        '/assets/images/avatar-default-laki.svg',
        '/assets/images/avatar-default-perempuan.svg',
    ];
    $root = dirname(__DIR__);
    $out = [];
    foreach ($paths as $rel) {
        if (is_file($root . $rel)) {
            $out[] = $rel;
        }
    }

    return $out;
}

/** Aset scan QR — precache on-demand saat halaman scan dibuka. */
function pwa_scan_precache_relative_paths(): array
{
    require_once __DIR__ . '/app_vendor.php';
    $paths = [
        '/assets/css/presensi-scan.css',
        '/assets/css/cashless-scan.css',
        '/assets/js/presensi-scan-feedback.js',
        '/assets/js/presensi-scan-timer.js',
        '/assets/js/presensi-scan-camera.js',
    ];
    foreach (app_vendor_scan_precache_relative_paths() as $rel) {
        $paths[] = $rel;
    }
    $root = dirname(__DIR__);
    $out = [];
    foreach ($paths as $rel) {
        if (is_file($root . $rel)) {
            $out[] = $rel;
        }
    }

    return $out;
}

/**
 * Shell HTML + aset modul kritis untuk cold-start offline (presensi/poin).
 *
 * @return list<string>
 */
function pwa_module_shell_precache_relative_paths(): array
{
    $paths = [
        '/presensi/scan.php',
        '/poin/input.php',
        '/assets/js/santri-select.js',
    ];
    foreach (pwa_scan_precache_relative_paths() as $rel) {
        $paths[] = $rel;
    }
    $root = dirname(__DIR__);
    $out = [];
    foreach (array_values(array_unique($paths)) as $rel) {
        if (str_ends_with($rel, '.php') || is_file($root . $rel)) {
            $out[] = $rel;
        }
    }

    return $out;
}

function pwa_precache_paths(string $basePath, ?PDO $pdo = null): array
{
    require_once __DIR__ . '/app.php';
    require_once __DIR__ . '/app_vendor.php';

    $base = rtrim($basePath, '/');
    $prefix = $base === '' ? '' : $base;
    $vendor = array_map(static fn (string $p): string => $prefix . $p, app_vendor_precache_relative_paths());
    $uiStatic = array_map(static fn (string $p): string => $prefix . $p, pwa_ui_static_precache_relative_paths());
    $moduleShell = array_map(static fn (string $p): string => $prefix . $p, pwa_module_shell_precache_relative_paths());

    return array_values(array_unique(array_merge([
        $prefix . '/offline.php',
        $prefix . '/api/vendor/fontawesome.css.php',
    ], $uiStatic, $vendor, $moduleShell, pwa_media_precache_paths($basePath, $pdo))));
}

/** Precache portal wali saja (tanpa shell scan/staf). */
function pwa_wali_precache_paths(string $basePath, ?PDO $pdo = null): array
{
    require_once __DIR__ . '/app.php';
    require_once __DIR__ . '/app_vendor.php';

    $base = rtrim($basePath, '/');
    $prefix = $base === '' ? '' : $base;
    $root = dirname(__DIR__);
    $rel = [
        '/assets/css/app.css',
        '/assets/css/pwa-ui.css',
        '/assets/css/auth-portal.css',
        '/assets/css/wali-portal.css',
        '/assets/js/app-shell.js',
        '/assets/js/theme-mode.js',
        '/assets/js/pwa-register.js',
        '/wali/login.php',
        '/wali/index.php',
        '/offline.php',
        '/api/vendor/fontawesome.css.php',
    ];
    $out = [];
    foreach ($rel as $p) {
        if (str_ends_with($p, '.php') || is_file($root . $p)) {
            $out[] = $prefix . $p;
        }
    }
    foreach (app_vendor_precache_relative_paths() as $p) {
        $out[] = $prefix . $p;
    }

    return array_values(array_unique(array_merge($out, pwa_media_precache_paths($basePath, $pdo))));
}

/**
 * Generate isi service worker (offline + opsional FCM).
 */
function pwa_render_service_worker_js(PDO $pdo, string $basePath, bool $includeFcm = true, bool $waliPortal = false): string
{
    $basePath = rtrim($basePath, '/');
    $baseJs = addslashes($basePath === '' ? '' : $basePath);
    $cacheVer = addslashes(pwa_cache_version() . ($waliPortal ? '-wali' : ''));
    $mediaCacheVer = addslashes(substr(md5(pwa_cache_version()), 0, 12));
    $precacheJson = json_encode(
        $waliPortal ? pwa_wali_precache_paths($basePath, $pdo) : pwa_precache_paths($basePath, $pdo),
        JSON_UNESCAPED_SLASHES
    );
    $variantJs = $waliPortal ? 'wali' : 'app';

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
var PWA_MEDIA_CACHE = 'pondok-pwa-media-{$mediaCacheVer}';
var PWA_PRECACHE = {$precacheJson};
var PWA_VARIANT = '{$variantJs}';

function pwaUrl(path) {
  path = String(path || '');
  if (path.charAt(0) !== '/') {
    path = '/' + path;
  }
  return (PWA_BASE || '') + path;
}

function pwaIsApiRequest(url) {
  if (url.pathname.indexOf('/api/vendor/') >= 0) {
    return false;
  }
  return url.pathname.indexOf('/api/') >= 0;
}

function pwaIsStaticAsset(url) {
  return /\.(css|js|map|png|jpe?g|gif|webp|ico|woff2?|svg)$/i.test(url.pathname);
}

function pwaIsBrandMedia(url) {
  var p = url.pathname;
  return p.indexOf('/uploads/') >= 0
    || p.indexOf('/assets/images/') >= 0
    || p.indexOf('/assets/img/') >= 0
    || p.indexOf('/assets/vendor/') >= 0;
}

function pwaIsOfflineNavAllowlist(url) {
  var p = url.pathname;
  if (p.endsWith('/offline.php')) {
    return true;
  }
  if (PWA_VARIANT === 'wali') {
    return p.indexOf('/wali/') >= 0;
  }
  if (p.indexOf('/login.php') >= 0 && url.search.indexOf('scan=1') >= 0) {
    return true;
  }
  return p.indexOf('/presensi/scan') >= 0
    || p.indexOf('/poin/input') >= 0
    || p.indexOf('/cashless/scan') >= 0
    || p.indexOf('/keuangan/cashless_scan') >= 0
    || p.indexOf('/koperasi/scan') >= 0
    || p.indexOf('/presensi/kiosk') >= 0
    || p.indexOf('/keuangan/offline-data') >= 0
    || p.indexOf('/keuangan/neraca') >= 0
    || p.indexOf('/keuangan/arus-kas') >= 0
    || p.indexOf('/keuangan/riwayat_pembayaran') >= 0
    || p.indexOf('/keuangan/cashless_laporan') >= 0;
}

function pwaNormalizeCacheUrl(request) {
  var u = new URL(request.url);
  u.search = '';
  u.hash = '';
  return u.href;
}

function pwaCacheMatch(request) {
  return caches.match(request).then(function (hit) {
    if (hit) {
      return hit;
    }
    var norm = pwaNormalizeCacheUrl(request);
    if (norm !== request.url) {
      return caches.match(norm);
    }
    return undefined;
  });
}

function pwaPutCache(request, response) {
  if (!response || !response.ok) {
    return;
  }
  caches.open(PWA_CACHE).then(function (cache) {
    cache.put(request, response.clone());
    var normReq = new Request(pwaNormalizeCacheUrl(request), { credentials: 'same-origin' });
    if (normReq.url !== request.url) {
      cache.put(normReq, response.clone());
    }
  });
}

function pwaNetworkFirstStatic(request) {
  var netReq = request;
  try {
    var u = new URL(request.url);
    if (u.search.indexOf('v=') >= 0) {
      netReq = new Request(request, { cache: 'no-cache' });
    }
  } catch (e) {}
  return fetch(netReq).then(function (res) {
    pwaPutCache(request, res);
    return res;
  }).catch(function () {
    return pwaCacheMatch(request).then(function (cached) {
      return cached || new Response('', { status: 504, statusText: 'Offline' });
    });
  });
}

function pwaCacheFirstMedia(request) {
  return pwaCacheMatch(request).then(function (cached) {
    if (cached) {
      return cached;
    }
    return fetch(request).then(function (res) {
      pwaPutCache(request, res);
      return res;
    }).catch(function () {
      return new Response('', { status: 504, statusText: 'Offline' });
    });
  });
}

self.addEventListener('install', function (event) {
  event.waitUntil(
    caches.open(PWA_CACHE).then(function (cache) {
      // Satu gagal (mis. shell auth) tidak membatalkan seluruh precache.
      return Promise.all(
        PWA_PRECACHE.map(function (p) {
          var req = new Request(p, { credentials: 'same-origin', cache: 'no-cache' });
          return fetch(req).then(function (res) {
            if (res && res.ok) {
              return cache.put(req, res.clone()).then(function () {
                var normHref = pwaNormalizeCacheUrl(req);
                if (normHref !== req.url) {
                  return cache.put(new Request(normHref, { credentials: 'same-origin' }), res.clone());
                }
              });
            }
          }).catch(function () {});
        })
      );
    }).then(function () {
      return self.skipWaiting();
    })
  );
});

self.addEventListener('activate', function (event) {
  event.waitUntil(
    caches.keys().then(function (keys) {
      return Promise.all(
        keys.filter(function (k) {
          return k !== PWA_CACHE && k !== PWA_MEDIA_CACHE;
        }).map(function (k) {
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
    var navReq = req;
    if (pwaIsOfflineNavAllowlist(url)) {
      navReq = new Request(req, { cache: 'no-cache' });
    }
    event.respondWith(
      fetch(navReq).then(function (res) {
        if (res && res.ok && res.type === 'basic' && pwaIsOfflineNavAllowlist(url)) {
          pwaPutCache(req, res);
        }
        return res;
      }).catch(function () {
        return caches.match(req).then(function (pageHit) {
          if (pageHit) {
            return pageHit;
          }
          return caches.match(pwaUrl('/offline.php')).then(function (cached) {
            return cached || new Response('Tidak ada koneksi.', {
              status: 503,
              headers: { 'Content-Type': 'text/plain; charset=utf-8' }
            });
          });
        });
      })
    );
    return;
  }

  if (pwaIsBrandMedia(url)) {
    event.respondWith(pwaCacheFirstMedia(req));
    return;
  }

  if (pwaIsStaticAsset(url)) {
    event.respondWith(pwaNetworkFirstStatic(req));
    return;
  }
});

self.addEventListener('message', function (event) {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
  if (event.data && event.data.type === 'PRECACHE_SCAN' && Array.isArray(event.data.paths)) {
    event.waitUntil(
      caches.open(PWA_CACHE).then(function (cache) {
        return Promise.all(
          event.data.paths.map(function (rel) {
            var path = String(rel || '');
            if (path.indexOf('http://') === 0 || path.indexOf('https://') === 0) {
              return fetch(path, { credentials: 'same-origin' }).then(function (res) {
                if (res && res.ok) {
                  return cache.put(path, res);
                }
              }).catch(function () {});
            }
            if (path.charAt(0) !== '/') {
              path = '/' + path;
            }
            return fetch(pwaUrl(path), { credentials: 'same-origin' }).then(function (res) {
              if (res && res.ok) {
                return cache.put(pwaUrl(path), res);
              }
            }).catch(function () {});
          })
        );
      })
    );
  }
});
{$fcmBlock}
JS;
}
