(function () {
    'use strict';

    var LOADING_CLASS = 'yp-nav-loading';
    var CACHE_TTL_MS = 3 * 60 * 1000;
    var pageCache = new Map();
    var inflight = null;

    function fragmentApiBase() {
        var meta = document.querySelector('meta[name="yp-fragment-api"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function appBase() {
        var b = window.PONDOK_APP_BASE || '';
        return b.replace(/\/$/, '');
    }

    function normalizePath(href) {
        try {
            var u = new URL(href, window.location.origin);
            var p = u.pathname;
            var base = appBase();
            if (base && p.indexOf(base) === 0) {
                p = p.slice(base.length) || '/';
            }
            if (p === '/yayasan' || p === '/yayasan/') {
                p = '/yayasan/operasional.php';
            }
            return p.replace(/\/+$/, '') || '/';
        } catch (e) {
            return '';
        }
    }

    function isYayasanInternal(path) {
        return path === '/yayasan' || path.indexOf('/yayasan/') === 0;
    }

    function shouldSkipNav(path, el) {
        if (!isYayasanInternal(path)) {
            return true;
        }
        if (el && el.closest('[data-yp-full-nav]')) {
            return true;
        }
        if (/(\/scan_musyawarah|\/musyawarah_presensi|\/kartu_sdm|\/executive)\.php$/i.test(path)) {
            return true;
        }
        return false;
    }

    function setLoading(on) {
        document.body.classList.toggle(LOADING_CLASS, !!on);
    }

    function escAttr(s) {
        return String(s).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
    }

    function cacheKey(url) {
        try {
            var u = new URL(url, window.location.origin);
            return u.pathname + u.search;
        } catch (e) {
            return url;
        }
    }

    function runScriptsIn(root) {
        var scripts = Array.prototype.slice.call(root.querySelectorAll('script'));
        function runNext(i) {
            if (i >= scripts.length) {
                return;
            }
            var oldScript = scripts[i];
            var s = document.createElement('script');
            Array.prototype.forEach.call(oldScript.attributes, function (attr) {
                s.setAttribute(attr.name, attr.value);
            });
            if (oldScript.src) {
                var src = oldScript.getAttribute('src');
                if (src && document.querySelector('script[src="' + escAttr(src) + '"]')) {
                    runNext(i + 1);
                    return;
                }
                s.onload = function () { runNext(i + 1); };
                s.onerror = function () { runNext(i + 1); };
                oldScript.parentNode.replaceChild(s, oldScript);
            } else {
                s.textContent = oldScript.textContent;
                oldScript.parentNode.replaceChild(s, oldScript);
                runNext(i + 1);
            }
        }
        runNext(0);
    }

    function mergeStylesheets(list) {
        if (!list || !list.length) {
            return;
        }
        list.forEach(function (href) {
            if (!href || document.querySelector('link[rel="stylesheet"][href="' + escAttr(href) + '"]')) {
                return;
            }
            var link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = href;
            document.head.appendChild(link);
        });
    }

    function mergeScripts(list) {
        if (!list || !list.length) {
            return;
        }
        list.forEach(function (src) {
            if (!src || document.querySelector('script[src="' + escAttr(src) + '"]')) {
                return;
            }
            var s = document.createElement('script');
            s.src = src;
            s.defer = true;
            document.body.appendChild(s);
        });
    }

    function applyPayload(data, url) {
        var curMain = document.querySelector('main.app-main');
        if (!curMain || !data || !data.html) {
            return false;
        }
        mergeStylesheets(data.stylesheets);
        curMain.innerHTML = data.html;
        if (data.title) {
            document.title = data.title;
        }
        var curTitle = document.querySelector('.app-topbar-page-title');
        if (curTitle && data.title) {
            curTitle.textContent = data.title;
        }
        var curMobile = document.querySelector('.app-topbar-title-mobile');
        if (curMobile && data.title) {
            curMobile.textContent = data.title;
        }
        mergeScripts(data.scripts);
        runScriptsIn(curMain);
        window.scrollTo(0, 0);
        document.dispatchEvent(new CustomEvent('yp:navigated', { detail: { url: url } }));
        return true;
    }

    function fetchFragment(url, silent) {
        var api = fragmentApiBase();
        if (!api) {
            return fetchHtmlFallback(url, silent);
        }
        var u = new URL(url, window.location.origin);
        var qs = new URLSearchParams({ path: normalizePath(url) });
        u.searchParams.forEach(function (v, k) {
            qs.set(k, v);
        });
        var ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
        if (!silent) {
            if (inflight) {
                try { inflight.abort(); } catch (e) { /* ignore */ }
            }
            inflight = ctrl;
            setLoading(true);
        }
        return fetch(api + '?' + qs.toString(), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: ctrl ? ctrl.signal : undefined
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    if (!silent) {
                        return fetchHtmlFallback(url, false);
                    }
                    return;
                }
                var key = cacheKey(url);
                pageCache.set(key, { data: data, ts: Date.now() });
                applyPayload(data, url);
            })
            .catch(function (err) {
                if (err && err.name === 'AbortError') {
                    return;
                }
                if (!silent) {
                    return fetchHtmlFallback(url, false);
                }
            })
            .finally(function () {
                if (!silent && (!ctrl || inflight === ctrl)) {
                    inflight = null;
                    setLoading(false);
                }
            });
    }

    function fetchHtmlFallback(url, silent) {
        if (!silent) {
            setLoading(true);
        }
        return fetch(url, {
            credentials: 'same-origin',
            headers: { Accept: 'text/html', 'X-YP-Nav': '1' }
        })
            .then(function (r) {
                if (!r.ok) {
                    throw new Error('http');
                }
                return r.text();
            })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var newMain = doc.querySelector('main.app-main');
                if (!newMain) {
                    window.location.href = url;
                    return;
                }
                applyPayload({
                    html: newMain.innerHTML,
                    title: doc.title,
                    stylesheets: [],
                    scripts: []
                }, url);
            })
            .catch(function () {
                window.location.href = url;
            })
            .finally(function () {
                if (!silent) {
                    setLoading(false);
                }
            });
    }

    function navigateTo(url, push) {
        if (push === undefined) {
            push = true;
        }
        var path = normalizePath(url);
        if (shouldSkipNav(path, null)) {
            window.location.href = url;
            return;
        }
        var key = cacheKey(url);
        var cached = pageCache.get(key);
        if (cached && (Date.now() - cached.ts) < CACHE_TTL_MS) {
            applyPayload(cached.data, url);
            if (push) {
                try {
                    window.history.pushState({ ypNav: true }, '', url);
                } catch (e) { /* ignore */ }
            }
            fetchFragment(url, true);
            return;
        }
        if (push) {
            try {
                window.history.pushState({ ypNav: true }, '', url);
            } catch (e) { /* ignore */ }
        }
        return fetchFragment(url, false);
    }

    function prefetchUrl(url) {
        var path = normalizePath(url);
        if (shouldSkipNav(path, null)) {
            return;
        }
        var key = cacheKey(url);
        if (pageCache.has(key)) {
            return;
        }
        var api = fragmentApiBase();
        if (!api) {
            return;
        }
        var u = new URL(url, window.location.origin);
        var qs = new URLSearchParams({ path: path });
        u.searchParams.forEach(function (v, k) { qs.set(k, v); });
        fetch(api + '?' + qs.toString(), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.ok) {
                    pageCache.set(key, { data: data, ts: Date.now() });
                }
            })
            .catch(function () { /* ignore */ });
    }

    function linkFromEvent(e) {
        var a = e.target.closest('a[href]');
        if (!a || a.hasAttribute('download') || a.target === '_blank') {
            return null;
        }
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
            return null;
        }
        var href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) {
            return null;
        }
        return a;
    }

    document.addEventListener('click', function (e) {
        var a = linkFromEvent(e);
        if (!a) {
            return;
        }
        var url = a.href;
        if (shouldSkipNav(normalizePath(url), a)) {
            return;
        }
        e.preventDefault();
        navigateTo(url, true);
    });

    document.addEventListener('mouseover', function (e) {
        var a = e.target.closest('a[href]');
        if (a) {
            prefetchUrl(a.href);
        }
    }, { passive: true });

    document.addEventListener('touchstart', function (e) {
        var a = e.target.closest('a[href]');
        if (a) {
            prefetchUrl(a.href);
        }
    }, { passive: true });

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'get') {
            return;
        }
        if (form.hasAttribute('data-yp-full-nav')) {
            return;
        }
        var action = form.getAttribute('action') || window.location.href;
        var url = new URL(action, window.location.origin);
        new FormData(form).forEach(function (v, k) {
            if (v !== '') {
                url.searchParams.set(k, v);
            }
        });
        if (shouldSkipNav(normalizePath(url.href), form)) {
            return;
        }
        e.preventDefault();
        navigateTo(url.href, true);
    });

    window.addEventListener('popstate', function () {
        if (window.history.state && window.history.state.ypNav) {
            navigateTo(window.location.href, false);
        }
    });

    window.__ypNavigate = navigateTo;

    if (!window.history.state || !window.history.state.ypNav) {
        window.history.replaceState({ ypNav: true }, '', window.location.href);
    }

    if ('requestIdleCallback' in window) {
        requestIdleCallback(function () {
            document.querySelectorAll('#yp-operasional-root a[href*="/yayasan/"]').forEach(function (a) {
                prefetchUrl(a.href);
            });
        }, { timeout: 2500 });
    }
})();
