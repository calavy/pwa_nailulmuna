/**
 * Format waktu 24 jam di seluruh aplikasi (tanpa AM/PM) + saran jam.
 */
(function () {
    'use strict';

    var timeSuggestListId = 'pondok-time-suggestions-24';

    function pad2(n) {
        return n < 10 ? '0' + n : String(n);
    }

    function formatTime24FromDate(d) {
        return pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
    }

    function formatDateTime24FromDate(d) {
        return pad2(d.getDate()) + '/' + pad2(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' +
            pad2(d.getHours()) + ':' + pad2(d.getMinutes());
    }

    function parseLooseDateTime(raw) {
        var s = String(raw || '').trim();
        if (!s) return null;
        if (/^\d{1,2}:\d{2}(:\d{2})?$/.test(s)) {
            var p = s.split(':');
            var now = new Date();
            now.setHours(parseInt(p[0], 10), parseInt(p[1], 10), parseInt(p[2] || '0', 10), 0);
            return now;
        }
        var d = new Date(s.replace(' ', 'T'));
        return isNaN(d.getTime()) ? null : d;
    }

    function normalizeTimeText(text) {
        var s = String(text || '').trim();
        if (!s) return s;
        var m = s.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([AaPp])\.?\s*[Mm]\.?$/);
        if (!m) return s;
        var h = parseInt(m[1], 10);
        var min = m[2];
        var sec = m[3] || '00';
        var ap = m[4].toUpperCase();
        if (ap === 'P' && h < 12) h += 12;
        if (ap === 'A' && h === 12) h = 0;
        return pad2(h) + ':' + min + (m[3] !== undefined ? ':' + sec : '');
    }

    function formatInputValue24(val) {
        var s = normalizeTimeText(String(val || '').trim());
        var m = s.match(/^(\d{1,2}):(\d{2})/);
        if (!m) return s;
        var h = Math.max(0, Math.min(23, parseInt(m[1], 10)));
        var min = Math.max(0, Math.min(59, parseInt(m[2], 10)));
        return pad2(h) + ':' + pad2(min);
    }

    function maskTimeTyping(inp) {
        var raw = String(inp.value || '').replace(/[^\d:]/g, '');
        if (raw.indexOf(':') >= 0) {
            var parts = raw.split(':');
            var h = parts[0].slice(0, 2);
            var m = (parts[1] || '').slice(0, 2);
            inp.value = h + (m.length || parts.length > 1 ? ':' + m : '');
            return;
        }
        if (raw.length <= 2) {
            inp.value = raw;
            return;
        }
        inp.value = raw.slice(0, 2) + ':' + raw.slice(2, 4);
    }

    function convertNativeTimeToText(inp) {
        if (inp.type === 'time') {
            var v = inp.value;
            inp.type = 'text';
            inp.setAttribute('inputmode', 'numeric');
            inp.setAttribute('pattern', '^([01]?[0-9]|2[0-3]):[0-5][0-9]$');
            inp.setAttribute('maxlength', '5');
            inp.removeAttribute('step');
            if (v) {
                inp.value = formatInputValue24(v);
            }
        }
    }

    function ensureGlobalTimeDatalist() {
        if (document.getElementById(timeSuggestListId)) {
            return;
        }
        var dl = document.createElement('datalist');
        dl.id = timeSuggestListId;
        for (var h = 5; h <= 22; h++) {
            [0, 15, 30, 45].forEach(function (m) {
                var opt = document.createElement('option');
                opt.value = pad2(h) + ':' + pad2(m);
                dl.appendChild(opt);
            });
        }
        document.body.appendChild(dl);
    }

    function ensureTimeInput24(inp) {
        if (!inp || inp.dataset.time24Ready === '1') return;
        convertNativeTimeToText(inp);
        inp.dataset.time24Ready = '1';
        inp.setAttribute('lang', 'en-GB');
        inp.classList.add('input-time-24');
        if (!inp.getAttribute('placeholder')) {
            inp.setAttribute('placeholder', 'HH:MM (ketik atau pilih)');
        }
        ensureGlobalTimeDatalist();
        inp.setAttribute('list', timeSuggestListId);
        inp.setAttribute('title', 'Format 24 jam. Ketik angka atau pilih saran.');

        function onBlur() {
            var v = formatInputValue24(inp.value);
            if (v && /^\d{2}:\d{2}$/.test(v)) {
                inp.value = v;
            }
        }

        inp.addEventListener('input', maskTimeTyping);
        inp.addEventListener('blur', onBlur);
        inp.addEventListener('change', onBlur);
    }

    function upgradeClockElements() {
        var clockEl = document.getElementById('dashboard-live-clock');
        var dateEl = document.getElementById('dashboard-live-date');
        if (!clockEl) return;

        var serverMs = window.PONDOK_SERVER_CLOCK_MS;
        if (typeof serverMs !== 'number') return;

        var driftMs = serverMs - Date.now();
        var hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', "Jum'at", 'Sabtu'];
        var bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        var bulanPendek = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

        function tick() {
            var now = new Date(Date.now() + driftMs);
            clockEl.textContent = formatTime24FromDate(now);
            if (dateEl) {
                var compact = document.body.classList.contains('pb-dash-home-mobile-fit')
                    || document.body.classList.contains('dash-home-mobile-fit');
                var bln = compact ? bulanPendek[now.getMonth()] : bulan[now.getMonth()];
                var dateStr = hari[now.getDay()] + ', ' + now.getDate() + ' ' + bln;
                if (!compact) {
                    dateStr += ' ' + now.getFullYear();
                }
                var pasaran = (dateEl.getAttribute('data-pasaran') || '').trim();
                if (pasaran !== '') {
                    dateStr += ' · ' + pasaran;
                }
                dateEl.textContent = dateStr;
            }
        }
        tick();
        setInterval(tick, 1000);
    }

    function scanTimeDisplays() {
        document.querySelectorAll('[data-time-24]').forEach(function (el) {
            var raw = el.getAttribute('data-time-24') || el.textContent;
            var d = parseLooseDateTime(raw);
            if (!d) {
                el.textContent = normalizeTimeText(el.textContent);
                return;
            }
            var mode = el.getAttribute('data-time-24-mode') || 'time';
            if (mode === 'datetime') {
                el.textContent = formatDateTime24FromDate(d);
            } else {
                el.textContent = formatTime24FromDate(d).slice(0, 5);
            }
        });

        document.querySelectorAll('.js-time-24').forEach(function (el) {
            el.textContent = normalizeTimeText(el.textContent);
        });
    }

    function init() {
        document.querySelectorAll('input[type="time"], input.input-time-24').forEach(ensureTimeInput24);

        if (!document.getElementById('dashboard-live-clock') || window.PONDOK_CLOCK_CUSTOM) {
            scanTimeDisplays();
            return;
        }
        window.PONDOK_CLOCK_CUSTOM = true;
        upgradeClockElements();
        scanTimeDisplays();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
