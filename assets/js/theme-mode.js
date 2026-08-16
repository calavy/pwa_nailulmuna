/**
 * Mode tampilan terang/gelap — disimpan di localStorage, transisi halus, anti spam klik.
 */
(function (global) {
    'use strict';

    var STORAGE_KEY = 'theme-mode';
    var DEBOUNCE_MS = 300;
    var TRANSITION_MS = 280;
    var pendingTimer = null;
    var lastApplied = null;

    function resolveMode(mode) {
        return mode === 'dark' ? 'dark' : 'light';
    }

    function readStored() {
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
        doc.style.backgroundColor = mode === 'dark' ? '#f1f5f9' : '#eef5ff';
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
        paintRoot(mode);

        try {
            global.localStorage.setItem(STORAGE_KEY, mode);
        } catch (e) {}

        syncRadios(mode);
        return mode;
    }

    function scheduleApply(mode) {
        if (pendingTimer) {
            global.clearTimeout(pendingTimer);
        }
        global.document.querySelectorAll('input[name="theme-mode"]').forEach(function (radio) {
            radio.disabled = true;
        });
        pendingTimer = global.setTimeout(function () {
            pendingTimer = null;
            applyTheme(mode, { animate: true });
        }, DEBOUNCE_MS);
    }

    function onRadioChange(event) {
        var target = event.target;
        if (!target || target.name !== 'theme-mode' || !target.checked) {
            return;
        }
        scheduleApply(target.value);
    }

    function bindRadios() {
        global.document.querySelectorAll('input[name="theme-mode"]').forEach(function (radio) {
            if (radio.dataset.themeBound === '1') {
                return;
            }
            radio.dataset.themeBound = '1';
            radio.addEventListener('change', onRadioChange);
        });
        syncRadios(readStored());
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
    }

    /** Dipanggil dari inline &lt;head&gt; sebelum CSS — cegah FOUC / layout rusak saat buka. */
    function bootstrapEarly() {
        var mode = readStored();
        lastApplied = mode;
        paintRoot(mode);
    }

    global.PondokTheme = {
        bootstrapEarly: bootstrapEarly,
        apply: applyTheme,
        read: readStored,
        init: init,
    };

    if (global.document.readyState === 'loading') {
        global.document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window);
