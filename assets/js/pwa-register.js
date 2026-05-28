/**
 * Daftarkan service worker PWA (offline + FCM).
 */
(function (global) {
    'use strict';

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
        el.textContent = 'Mode offline — data baru membutuhkan koneksi internet.';
        document.body.appendChild(el);
    }

    function updateOnlineClass() {
        var offline = !navigator.onLine;
        document.documentElement.classList.toggle('pondok-offline', offline);
        var banner = document.getElementById('pondok-offline-banner');
        if (banner) {
            banner.hidden = !offline;
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
            reg.addEventListener('updatefound', function () {
                var worker = reg.installing;
                if (!worker) {
                    return;
                }
                worker.addEventListener('statechange', function () {
                    if (worker.state === 'installed' && navigator.serviceWorker.controller) {
                        document.documentElement.classList.add('pondok-pwa-update');
                    }
                });
            });
            return reg;
        } catch (e) {
            return null;
        }
    }

    global.PondokPwa = {
        register: registerPwa,
        getRegistration: function () {
            return navigator.serviceWorker.getRegistration(swScope());
        },
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', ensureOfflineBanner);
    } else {
        ensureOfflineBanner();
    }

    updateOnlineClass();
    window.addEventListener('online', updateOnlineClass);
    window.addEventListener('offline', updateOnlineClass);

    if (document.readyState === 'complete') {
        registerPwa();
    } else {
        window.addEventListener('load', registerPwa);
    }
})(window);
