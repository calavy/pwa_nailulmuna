/**
 * Daftarkan service worker PWA (offline + FCM) dan banner pembaruan.
 */
(function (global) {
    'use strict';

    var updateCheckIntervalMs = 5 * 60 * 1000;
    var activeRegistration = null;
    var refreshing = false;

    function appBase() {
        var b = (global.PONDOK_PWA_BASE != null)
            ? String(global.PONDOK_PWA_BASE)
            : (global.PONDOK_APP_BASE != null ? String(global.PONDOK_APP_BASE) : '');
        return b.replace(/\/$/, '');
    }

    function appPath(relative) {
        relative = String(relative || '').replace(/^\//, '');
        var base = appBase();
        return (base === '' ? '' : base) + '/' + relative;
    }

    function swScriptUrl() {
        if (global.PONDOK_PWA_SW) {
            return String(global.PONDOK_PWA_SW);
        }
        var scopeHint = String(global.PONDOK_PWA_SCOPE || '');
        if (scopeHint.indexOf('/wali') >= 0 || /\/wali\//.test(global.location.pathname)) {
            return appPath('wali/sw.php');
        }
        return appPath('api/pwa/app-sw.php');
    }

    function swScope() {
        var scope = String(global.PONDOK_PWA_SCOPE || '');
        if (scope !== '') {
            return scope;
        }
        var base = appBase();
        return base === '' ? '/' : base + '/';
    }

    function ensureOfflineBanner() {
        if (!document.body || document.getElementById('pondok-offline-banner')) {
            return;
        }
        var el = document.createElement('div');
        el.id = 'pondok-offline-banner';
        el.setAttribute('role', 'status');
        el.setAttribute('aria-live', 'polite');
        el.hidden = true;
        el.textContent = 'Mode offline — presensi & poin masuk antrian lokal. Buka halaman scan/poin sekali saat online agar siap dipakai offline.';
        document.body.appendChild(el);
    }

    function ensureUpdateBanner() {
        if (!document.body || document.getElementById('pondok-pwa-update-banner')) {
            return;
        }
        var el = document.createElement('div');
        el.id = 'pondok-pwa-update-banner';
        el.setAttribute('role', 'status');
        el.setAttribute('aria-live', 'polite');
        el.hidden = true;
        el.innerHTML = '<span class="pondok-pwa-update-text">Pembaruan aplikasi tersedia.</span>'
            + '<button type="button" class="pondok-pwa-update-btn" id="pondok-pwa-update-btn">Muat ulang</button>';
        document.body.appendChild(el);
    }

    function showUpdateBanner(reg) {
        ensureUpdateBanner();
        document.documentElement.classList.add('pondok-pwa-update');
        var el = document.getElementById('pondok-pwa-update-banner');
        if (!el) {
            return;
        }
        el.hidden = false;
        var btn = document.getElementById('pondok-pwa-update-btn');
        if (btn && !btn._pondokUpdateBound) {
            btn._pondokUpdateBound = true;
            btn.addEventListener('click', function () {
                var waiting = reg && reg.waiting;
                if (waiting) {
                    waiting.postMessage({ type: 'SKIP_WAITING' });
                } else if (reg && reg.installing) {
                    reg.installing.addEventListener('statechange', function () {
                        if (reg.installing && reg.installing.state === 'installed' && reg.waiting) {
                            reg.waiting.postMessage({ type: 'SKIP_WAITING' });
                        }
                    });
                }
                btn.disabled = true;
                btn.textContent = 'Memuat…';
                window.setTimeout(function () {
                    window.location.reload();
                }, 400);
            });
        }
    }

    function bindUpdateFound(reg) {
        reg.addEventListener('updatefound', function () {
            var worker = reg.installing;
            if (!worker) {
                return;
            }
            worker.addEventListener('statechange', function () {
                if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                    showUpdateBanner(reg);
                }
            });
        });
    }

    function updateOnlineClass() {
        var offline = !navigator.onLine;
        var root = document.documentElement;
        if (offline) {
            root.classList.add('pondok-offline');
        } else {
            root.classList.remove('pondok-offline');
        }
        var banner = document.getElementById('pondok-offline-banner');
        if (banner) {
            banner.hidden = !offline;
        }
    }

    function warmUiCacheWhenOnline() {
        if (!navigator.onLine) {
            return;
        }
        var base = appBase();
        var paths = [
            '/assets/vendor/bootstrap/5.3.3/bootstrap.min.css',
            '/assets/vendor/bootstrap/5.3.3/bootstrap.bundle.min.js',
            '/api/vendor/fontawesome.css.php',
            '/assets/vendor/fontawesome/6.5.2/all.min.css',
            '/assets/vendor/fontawesome/6.5.2/webfonts/fa-solid-900.woff2',
            '/assets/css/app.css',
            '/assets/css/offline-sync.css',
            '/assets/js/app-shell.js',
            '/assets/js/pwa-register.js',
            '/assets/js/offline-sync.js',
            '/assets/js/login-offline.js',
            '/assets/js/login-scan-kegiatan.js',
            '/assets/js/santri-select.js',
            '/login.php',
            '/login.php?scan=1',
            '/dashboard.php',
            '/pembimbing/dashboard.php',
            '/presensi/scan.php',
            '/poin/input.php',
        ];
        paths.forEach(function (rel) {
            var url = (base === '' ? '' : base) + rel;
            fetch(url, { credentials: 'same-origin', cache: 'no-cache' }).catch(function () {});
        });
        if (navigator.serviceWorker.controller) {
            navigator.serviceWorker.controller.postMessage({
                type: 'PRECACHE_SCAN',
                paths: [
                    '/login.php',
                    '/login.php?scan=1',
                    '/dashboard.php',
                    '/pembimbing/dashboard.php',
                    '/presensi/scan.php',
                    '/poin/input.php',
                    '/assets/js/santri-select.js',
                    '/assets/js/login-scan-kegiatan.js',
                    '/assets/js/login-offline.js',
                ],
            });
        }
    }

    async function registerPwa() {
        if (!('serviceWorker' in navigator)) {
            return null;
        }
        var url = swScriptUrl();
        var scope = swScope();
        try {
            var reg = await navigator.serviceWorker.register(url, { scope: scope, updateViaCache: 'none' });
            activeRegistration = reg;
            try {
                await reg.update();
            } catch (updateErr) {
                /* abaikan */
            }
            if (reg.waiting && navigator.serviceWorker.controller) {
                showUpdateBanner(reg);
            }
            bindUpdateFound(reg);
            return reg;
        } catch (e) {
            return null;
        }
    }

    function scheduleUpdateChecks() {
        window.setInterval(function () {
            if (!navigator.onLine || !activeRegistration) {
                return;
            }
            activeRegistration.update().catch(function () {});
        }, updateCheckIntervalMs);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'visible' && navigator.onLine && activeRegistration) {
                activeRegistration.update().catch(function () {});
            }
        });
        window.addEventListener('pageshow', function (ev) {
            if (ev.persisted && navigator.onLine && activeRegistration) {
                activeRegistration.update().catch(function () {});
            }
        });
    }

    global.PondokPwa = {
        register: registerPwa,
        getRegistration: function () {
            return navigator.serviceWorker.getRegistration(swScope());
        },
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            ensureOfflineBanner();
            ensureUpdateBanner();
        });
    } else {
        ensureOfflineBanner();
        ensureUpdateBanner();
    }

    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.addEventListener('controllerchange', function () {
            if (refreshing) {
                return;
            }
            refreshing = true;
            window.location.reload();
        });
    }

    updateOnlineClass();
    window.addEventListener('online', function () {
        updateOnlineClass();
        warmUiCacheWhenOnline();
    });
    window.addEventListener('offline', updateOnlineClass);

    if (document.readyState === 'complete') {
        registerPwa().then(function () {
            warmUiCacheWhenOnline();
            scheduleUpdateChecks();
        });
    } else {
        window.addEventListener('load', function () {
            registerPwa().then(function () {
                warmUiCacheWhenOnline();
                scheduleUpdateChecks();
            });
        });
    }
})(window);
