/**
 * Typeahead nama/NIS santri di kolom identitas login.php.
 */
(function () {
    'use strict';

    var input = document.getElementById('login-username');
    var list = document.getElementById('login-santri-suggest');
    if (!input || !list || input.getAttribute('data-santri-suggest') !== '1') {
        return;
    }

    var url = input.getAttribute('data-santri-suggest-url') || '';
    if (!url) {
        return;
    }

    var debounceMs = 250;
    var timer = null;
    var abortCtl = null;
    var items = [];
    var activeIndex = -1;
    var lastQuery = '';

    function hideList() {
        list.classList.add('d-none');
        list.setAttribute('hidden', 'hidden');
        list.innerHTML = '';
        items = [];
        activeIndex = -1;
        input.setAttribute('aria-expanded', 'false');
    }

    function highlight(index) {
        var buttons = list.querySelectorAll('.auth-portal-wali-pick');
        buttons.forEach(function (btn, i) {
            var on = i === index;
            btn.classList.toggle('is-selected', on);
            btn.setAttribute('aria-selected', on ? 'true' : 'false');
        });
        activeIndex = index;
    }

    function pick(item) {
        if (!item) {
            return;
        }
        input.value = item.nis || item.nama || '';
        hideList();
        var pin = document.getElementById('login-password');
        if (pin) {
            pin.focus();
        }
    }

    function render(rows) {
        items = Array.isArray(rows) ? rows : [];
        list.innerHTML = '';
        if (items.length === 0) {
            hideList();
            return;
        }
        items.forEach(function (row, i) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'auth-portal-wali-pick';
            btn.setAttribute('role', 'option');
            btn.setAttribute('aria-selected', 'false');
            var nama = document.createElement('span');
            nama.className = 'fw-semibold';
            nama.textContent = row.nama || '';
            var nis = document.createElement('span');
            nis.className = 'font-monospace text-muted';
            nis.textContent = row.nis || '';
            btn.appendChild(nama);
            btn.appendChild(nis);
            btn.addEventListener('mousedown', function (e) {
                e.preventDefault();
                pick(row);
            });
            btn.addEventListener('mouseenter', function () {
                highlight(i);
            });
            list.appendChild(btn);
        });
        list.classList.remove('d-none');
        list.removeAttribute('hidden');
        input.setAttribute('aria-expanded', 'true');
        highlight(-1);
    }

    function fetchSuggest(q) {
        if (abortCtl && typeof abortCtl.abort === 'function') {
            abortCtl.abort();
        }
        abortCtl = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var sep = url.indexOf('?') >= 0 ? '&' : '?';
        var reqUrl = url + sep + 'q=' + encodeURIComponent(q);
        var opts = { headers: { Accept: 'application/json' } };
        if (abortCtl) {
            opts.signal = abortCtl.signal;
        }
        fetch(reqUrl, opts)
            .then(function (res) {
                return res.json();
            })
            .then(function (data) {
                if (input.value.trim() !== q) {
                    return;
                }
                render(data && data.items ? data.items : []);
            })
            .catch(function () {
                /* abaikan abort / jaringan */
            });
    }

    function onInput() {
        var q = input.value.trim();
        if (q.length < 2) {
            lastQuery = q;
            hideList();
            return;
        }
        if (q === lastQuery) {
            return;
        }
        lastQuery = q;
        if (timer) {
            clearTimeout(timer);
        }
        timer = setTimeout(function () {
            fetchSuggest(q);
        }, debounceMs);
    }

    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', 'login-santri-suggest');
    input.setAttribute('aria-expanded', 'false');
    input.addEventListener('input', onInput);
    input.addEventListener('keydown', function (e) {
        if (list.classList.contains('d-none')) {
            return;
        }
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            highlight(Math.min(items.length - 1, activeIndex + 1));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            highlight(Math.max(0, activeIndex - 1));
        } else if (e.key === 'Enter' && activeIndex >= 0) {
            e.preventDefault();
            pick(items[activeIndex]);
        } else if (e.key === 'Escape') {
            hideList();
        }
    });
    input.addEventListener('blur', function () {
        setTimeout(hideList, 120);
    });
})();
