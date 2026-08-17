/**
 * Mode tampilan terang/gelap pondok — sumber: window.PONDOK_THEME_MODE (app_settings).
 * Hanya super admin (kartu #theme-settings-card) yang boleh mengubah; disimpan ke server.
 */
(function (global) {
    'use strict';

    var STORAGE_KEY = 'theme-mode';
    var DEBOUNCE_MS = 300;
    var TRANSITION_MS = 280;
    var pendingTimer = null;
    var lastApplied = null;
    var saveUrl = '';

    function resolveMode(mode) {
        return mode === 'dark' ? 'dark' : 'light';
    }

    function readPondokMode() {
        if (typeof global.PONDOK_THEME_MODE === 'string') {
            return resolveMode(global.PONDOK_THEME_MODE);
        }
        try {
            return resolveMode(global.localStorage.getItem(STORAGE_KEY));
        } catch (e) {
            return 'light';
        }
    }

    function paintRoot(mode) {
        var doc = global.document.documentElement;
        doc.setAttribute('data-theme', mode);
        doc.style.colorScheme = 'light';
        doc.style.backgroundColor = mode === 'dark' ? '#e2e8f0' : '#eef5ff';
    }

    function syncRadios(mode) {
        global.document.querySelectorAll('input[name="theme-mode"]').forEach(function (radio) {
            radio.checked = radio.value === mode;
            radio.disabled = false;
        });
    }

    function applyTheme(mode, options) {
        options = options || {};
        mode = resolveMode(mode);

        if (!options.force && lastApplied === mode) {
            syncRadios(mode);
            return mode;
        }

        var doc = global.document.documentElement;
        var animate = options.animate !== false && doc.classList.contains('theme-ready');

        if (animate) {
            doc.setAttribute('data-theme-transitioning', '1');
            global.setTimeout(function () {
                doc.removeAttribute('data-theme-transitioning');
            }, TRANSITION_MS + 40);
        }

        lastApplied = mode;
        global.PONDOK_THEME_MODE = mode;
        paintRoot(mode);

        try {
            global.localStorage.setItem(STORAGE_KEY, mode);
        } catch (e) {}

        syncRadios(mode);
        return mode;
    }

    function savePondokTheme(mode) {
        if (!saveUrl) {
            applyTheme(mode, { animate: true });
            return;
        }
        var body = new FormData();
        body.append('action', 'save_ui_theme');
        body.append('mode', mode);
        body.append('ajax', '1');
        fetch(saveUrl, {
            method: 'POST',
            body: body,
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then(function (res) {
                return res.json().then(function (data) {
                    return { ok: res.ok && data && data.ok, mode: data && data.mode ? data.mode : mode };
                });
            })
            .then(function (result) {
                applyTheme(result.ok ? result.mode : mode, { animate: true, force: true });
            })
            .catch(function () {
                syncRadios(readPondokMode());
            });
    }

    function scheduleSave(mode) {
        if (pendingTimer) {
            global.clearTimeout(pendingTimer);
        }
        global.document.querySelectorAll('input[name="theme-mode"]').forEach(function (radio) {
            radio.disabled = true;
        });
        pendingTimer = global.setTimeout(function () {
            pendingTimer = null;
            savePondokTheme(mode);
        }, DEBOUNCE_MS);
    }

    function onRadioChange(event) {
        var target = event.target;
        if (!target || target.name !== 'theme-mode' || !target.checked) {
            return;
        }
        scheduleSave(target.value);
    }

    function bindRadios() {
        var card = global.document.getElementById('theme-settings-card');
        if (!card) {
            return;
        }
        saveUrl = card.getAttribute('data-theme-save-url') || '';
        card.querySelectorAll('input[name="theme-mode"]').forEach(function (radio) {
            if (radio.dataset.themeBound === '1') {
                return;
            }
            radio.dataset.themeBound = '1';
            radio.addEventListener('change', onRadioChange);
        });
        syncRadios(readPondokMode());
    }

    function markReady() {
        var doc = global.document.documentElement;
        if (doc.classList.contains('theme-ready')) {
            return;
        }
        global.requestAnimationFrame(function () {
            global.requestAnimationFrame(function () {
                doc.classList.add('theme-ready');
            });
        });
    }

    function init() {
        bindRadios();
        markReady();
        applyTheme(readPondokMode(), { animate: false, force: true });
    }

    function bootstrapEarly() {
        var mode = readPondokMode();
        lastApplied = mode;
        paintRoot(mode);
    }

    global.PondokTheme = {
        bootstrapEarly: bootstrapEarly,
        apply: applyTheme,
        read: readPondokMode,
        init: init,
    };

    if (global.document.readyState === 'loading') {
        global.document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window);
