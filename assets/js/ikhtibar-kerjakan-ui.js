(function () {
    'use strict';

    var STORAGE_KEY = 'ikhtibar_kerjakan_text_scale';
    var LEVELS = [0.9, 1, 1.1, 1.2, 1.35];
    var DEFAULT_INDEX = 1;

    function readIndex() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            if (raw === null) {
                return DEFAULT_INDEX;
            }
            var idx = parseInt(raw, 10);
            if (isNaN(idx) || idx < 0 || idx >= LEVELS.length) {
                return DEFAULT_INDEX;
            }
            return idx;
        } catch (e) {
            return DEFAULT_INDEX;
        }
    }

    function writeIndex(idx) {
        try {
            localStorage.setItem(STORAGE_KEY, String(idx));
        } catch (e) {}
    }

    function applyScale(root, idx) {
        if (!root) {
            return;
        }
        root.style.setProperty('--ikhtibar-soal-scale', String(LEVELS[idx]));
        root.setAttribute('data-text-scale', String(LEVELS[idx]));
    }

    function init() {
        var root = document.querySelector('.ikhtibar-kerjakan-page');
        if (!root) {
            return;
        }

        var idx = readIndex();
        applyScale(root, idx);

        document.addEventListener('click', function (event) {
            var btn = event.target.closest('[data-ikhtibar-text-action]');
            if (!btn || !root.contains(btn)) {
                return;
            }
            event.preventDefault();
            var action = btn.getAttribute('data-ikhtibar-text-action');
            if (action === 'increase' && idx < LEVELS.length - 1) {
                idx++;
            } else if (action === 'decrease' && idx > 0) {
                idx--;
            } else if (action === 'reset') {
                idx = DEFAULT_INDEX;
            } else {
                return;
            }
            writeIndex(idx);
            applyScale(root, idx);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
