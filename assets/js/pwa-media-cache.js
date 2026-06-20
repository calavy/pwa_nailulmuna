/**
 * Simpan logo pondok & foto profil ke Cache API agar tampil sempurna saat offline.
 */
(function (global) {
    'use strict';

    function appBase() {
        var b = global.PONDOK_APP_BASE != null ? String(global.PONDOK_APP_BASE) : '';
        return b.replace(/\/$/, '');
    }

    function resolveUrl(raw) {
        raw = String(raw || '').trim();
        if (raw === '') {
            return '';
        }
        try {
            return new URL(raw, global.location.origin).href;
        } catch (e) {
            return '';
        }
    }

    function cacheName() {
        var meta = document.querySelector('meta[name="pondok-pwa-cache-ver"]');
        if (meta && meta.content) {
            return 'pondok-pwa-media-' + String(meta.content);
        }
        return 'pondok-pwa-media-runtime';
    }

    function putInCache(url) {
        if (!url || !('caches' in global)) {
            return Promise.resolve();
        }
        return caches.open(cacheName()).then(function (cache) {
            return cache.match(url).then(function (hit) {
                if (hit) {
                    return;
                }
                return fetch(url, { credentials: 'same-origin', mode: 'same-origin' }).then(function (res) {
                    if (res && res.ok) {
                        return cache.put(url, res);
                    }
                }).catch(function () {});
            });
        });
    }

    function collectFromMeta() {
        var urls = [];
        ['pondok-pwa-logo', 'pondok-pwa-logo-fallback', 'pondok-pwa-avatar', 'pondok-pwa-avatar-fallback'].forEach(function (name) {
            var el = document.querySelector('meta[name="' + name + '"]');
            if (el && el.content) {
                var u = resolveUrl(el.content);
                if (u) {
                    urls.push(u);
                }
            }
        });
        return urls;
    }

    function collectFromDom() {
        var sel = [
            'img.app-brand-logo',
            'img.app-sidebar-pondok-logo',
            'img.auth-portal-logo-img',
            'img.pondok-logo-preview',
            'img.app-user-avatar__img',
            'img.koperasi-topbar-logo',
            'img[data-pondok-cache="1"]',
        ];
        var urls = [];
        sel.forEach(function (s) {
            document.querySelectorAll(s).forEach(function (img) {
                var u = resolveUrl(img.currentSrc || img.src || '');
                if (u) {
                    urls.push(u);
                }
            });
        });
        return urls;
    }

    function warmAll() {
        var seen = {};
        collectFromMeta().concat(collectFromDom()).forEach(function (url) {
            if (!seen[url]) {
                seen[url] = true;
                putInCache(url);
            }
        });
    }

    function bindImageFallbacks() {
        document.querySelectorAll(
            'img.app-brand-logo, img.app-sidebar-pondok-logo, img.auth-portal-logo-img, img.wali-login-logo'
        ).forEach(function (img) {
            if (img.dataset.fallbackBound === '1') {
                return;
            }
            img.dataset.fallbackBound = '1';
            var fbSrc = img.getAttribute('data-fallback-src') || '';
            if (!fbSrc) {
                var fb = document.querySelector('meta[name="pondok-pwa-logo-fallback"]');
                fbSrc = fb && fb.content ? fb.content : '';
            }
            if (!fbSrc) {
                return;
            }
            img.addEventListener('error', function onErr() {
                img.removeEventListener('error', onErr);
                img.src = fbSrc;
            });
        });

        document.querySelectorAll('img.app-user-avatar__img').forEach(function (img) {
            if (img.dataset.fallbackBound === '1') {
                return;
            }
            img.dataset.fallbackBound = '1';
            var fbSrc = img.getAttribute('data-fallback-src') || '';
            if (!fbSrc) {
                var fb = document.querySelector('meta[name="pondok-pwa-avatar-fallback"]');
                fbSrc = fb && fb.content ? fb.content : '';
            }
            if (!fbSrc) {
                return;
            }
            img.addEventListener('error', function onErr() {
                img.removeEventListener('error', onErr);
                img.src = fbSrc;
            });
        });
    }

    function scheduleWarm() {
        if (typeof global.requestIdleCallback === 'function') {
            global.requestIdleCallback(function () { warmAll(); }, { timeout: 2500 });
        } else {
            global.setTimeout(warmAll, 400);
        }
    }

    function init() {
        if (!('caches' in global)) {
            return;
        }
        bindImageFallbacks();
        warmAll();
        scheduleWarm();
        global.addEventListener('online', warmAll);
        global.addEventListener('pageshow', function (ev) {
            if (ev.persisted) {
                warmAll();
            }
        });
        if (typeof MutationObserver === 'function') {
            var moTimer = null;
            var mo = new MutationObserver(function () {
                if (moTimer) {
                    global.clearTimeout(moTimer);
                }
                moTimer = global.setTimeout(warmAll, 600);
            });
            mo.observe(document.documentElement, { childList: true, subtree: true });
        }
    }

    global.PondokMediaCache = { warm: warmAll, put: putInCache };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window);
