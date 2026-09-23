(function () {
    'use strict';

    var LOADING_CLASS = 'keu-nav-loading';
    var CACHE_TTL_MS = 3 * 60 * 1000;
    var pageCache = new Map();
    var inflight = null;
    var allowedPaths = null;

    function fragmentApiBase() {
        var meta = document.querySelector('meta[name="keu-fragment-api"]');
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
            return p.replace(/\/+$/, '') || '/';
        } catch (e) {
            return '';
        }
    }

    function hubTabPaths() {
        if (allowedPaths) {
            return allowedPaths;
        }
        allowedPaths = new Set();
        document.querySelectorAll('.app-hub-tabs__link[href]').forEach(function (a) {
            var p = normalizePath(a.href);
            if (p) {
                allowedPaths.add(p);
            }
        });
        return allowedPaths;
    }

    function shouldSkipNav(path, el) {
        if (!hubTabPaths().has(path)) {
            return true;
        }
        if (el && el.closest('[data-keu-full-nav]')) {
            return true;
        }
        if (/\?print=1(?:&|$)/i.test(String(el && el.href ? el.href : ''))) {
            return true;
        }
        return false;
    }

    function setLoading(on) {
        document.body.classList.toggle(LOADING_CLASS, !!on);
    }

    function updateHubTabActive(url) {
        var path = normalizePath(url);
        document.querySelectorAll('.app-hub-tabs__link[href]').forEach(function (link) {
            link.classList.toggle('active', normalizePath(link.href) === path);
        });
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
        updateHubTabActive(url);
        window.scrollTo(0, 0);
        document.dispatchEvent(new CustomEvent('keu:navigated', { detail: { url: url } }));
        return true;
    }

    function fetchFragment(url, silent) {
        var api = fragmentApiBase();
        if (!api) {
            window.location.href = url;
            return;
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
                        window.location.href = url;
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
                    window.location.href = url;
                }
            })
            .finally(function () {
                if (!silent && (!ctrl || inflight === ctrl)) {
                    inflight = null;
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
                    window.history.pushState({ keuNav: true }, '', url);
                } catch (e) { /* ignore */ }
            }
            fetchFragment(url, true);
            return;
        }
        if (push) {
            try {
                window.history.pushState({ keuNav: true }, '', url);
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

    document.addEventListener('click', function (e) {
        var a = e.target.closest('.app-hub-tabs__link[href]');
        if (!a || a.hasAttribute('download') || a.target === '_blank') {
            return;
        }
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) {
            return;
        }
        var href = a.getAttribute('href');
        if (!href || href.charAt(0) === '#' || href.indexOf('javascript:') === 0) {
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
        var a = e.target.closest('.app-hub-tabs__link[href]');
        if (a) {
            prefetchUrl(a.href);
        }
    }, { passive: true });

    document.addEventListener('touchstart', function (e) {
        var a = e.target.closest('.app-hub-tabs__link[href]');
        if (a) {
            prefetchUrl(a.href);
        }
    }, { passive: true });

    window.addEventListener('popstate', function () {
        if (window.history.state && window.history.state.keuNav) {
            navigateTo(window.location.href, false);
        }
    });

    window.__keuNavigate = navigateTo;

    if (fragmentApiBase() && hubTabPaths().size > 0) {
        if (!window.history.state || !window.history.state.keuNav) {
            window.history.replaceState({ keuNav: true }, '', window.location.href);
        }
        if ('requestIdleCallback' in window) {
            requestIdleCallback(function () {
                document.querySelectorAll('.app-hub-tabs__link[href]').forEach(function (a) {
                    prefetchUrl(a.href);
                });
            }, { timeout: 2500 });
        }
    }
})();
