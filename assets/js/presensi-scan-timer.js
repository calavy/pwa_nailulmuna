/**
 * Hitung mundur jadwal scan presensi di atas kamera.
 */
(function (global) {
    'use strict';

    var tickTimer = null;
    var marqueeBound = false;
    var marqueeResizeObs = null;
    var marqueeSyncRetries = 0;
    var MARQUEE_PX_PER_SEC = 38;
    var MARQUEE_SYNC_MAX_RETRIES = 8;

    function pad2(n) {
        return n < 10 ? '0' + n : String(n);
    }

    function formatClock(totalSec) {
        var s = Math.max(0, Math.floor(totalSec));
        var m = Math.floor(s / 60);
        var sec = s % 60;
        return pad2(m) + ':' + pad2(sec);
    }

    function parseCtx() {
        var el = document.getElementById('presensi-scan-timer-data');
        if (!el || !el.textContent) {
            return null;
        }
        try {
            return JSON.parse(el.textContent);
        } catch (e) {
            return null;
        }
    }

    function setTimerClass(box, state, hasMarquee) {
        box.classList.remove('is-active', 'is-upcoming', 'is-ended', 'is-libur', 'is-none', 'has-marquee');
        if (state === 'active') {
            box.classList.add('is-active');
        } else if (state === 'upcoming') {
            box.classList.add('is-upcoming');
        } else if (state === 'ended') {
            box.classList.add('is-ended');
        } else if (state === 'libur') {
            box.classList.add('is-libur');
        } else {
            box.classList.add('is-none');
        }
        if (hasMarquee) {
            box.classList.add('has-marquee');
        }
    }

    function slotLabel(slot) {
        var label = String(slot.nama_kegiatan || 'Kegiatan');
        var mulai = String(slot.jam_mulai || '').slice(0, 5);
        var selesai = String(slot.jam_selesai || '').slice(0, 5);
        if (mulai && selesai) {
            label += ' · ' + mulai + '–' + selesai;
        }
        if (slot.tingkatan) {
            label += ' · ' + String(slot.tingkatan);
        }
        if (slot.tempat) {
            label += ' · ' + String(slot.tempat);
        }
        return label;
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function buildMarqueeItem(label) {
        return '<span class="presensi-scan-timer-marquee__item">'
            + '<i class="fa-solid fa-bolt" aria-hidden="true"></i>'
            + '<span>' + escapeHtml(label) + '</span>'
            + '</span>';
    }

    function buildMarqueeSeparator() {
        return '<span class="presensi-scan-timer-marquee__sep" aria-hidden="true"></span>';
    }

    function trackHalfWidth(trackEl) {
        if (!trackEl) {
            return 0;
        }
        var w = trackEl.scrollWidth;
        if (w <= 0) {
            w = trackEl.offsetWidth;
        }
        return w > 0 ? w / 2 : 0;
    }

    function scheduleMarqueeSync(delayMs) {
        global.setTimeout(function () {
            syncMarqueeSpeed();
        }, delayMs);
    }

    function bindMarqueeResize() {
        if (marqueeResizeObs || typeof global.ResizeObserver !== 'function') {
            return;
        }
        var marqueeEl = document.getElementById('presensi-scan-timer-marquee');
        var trackEl = document.getElementById('presensi-scan-timer-marquee-track');
        if (!marqueeEl || !trackEl) {
            return;
        }
        marqueeResizeObs = new global.ResizeObserver(function () {
            syncMarqueeSpeed();
        });
        marqueeResizeObs.observe(marqueeEl);
        marqueeResizeObs.observe(trackEl);
    }

    function syncMarqueeSpeed() {
        var marqueeEl = document.getElementById('presensi-scan-timer-marquee');
        var trackEl = document.getElementById('presensi-scan-timer-marquee-track');
        var viewportEl = marqueeEl ? marqueeEl.querySelector('.presensi-scan-timer-marquee__viewport') : null;
        if (!marqueeEl || !trackEl || !viewportEl || marqueeEl.classList.contains('d-none')) {
            return;
        }

        var halfWidth = trackHalfWidth(trackEl);
        var viewWidth = viewportEl.clientWidth || marqueeEl.clientWidth;

        if (halfWidth <= 4 && trackEl.childElementCount > 0 && marqueeSyncRetries < MARQUEE_SYNC_MAX_RETRIES) {
            marqueeSyncRetries += 1;
            scheduleMarqueeSync(80 * marqueeSyncRetries);
            return;
        }
        marqueeSyncRetries = 0;

        if (halfWidth <= 4) {
            return;
        }

        marqueeEl.classList.remove('is-static');
        var minScrollWidth = Math.max(halfWidth, viewWidth + 48);
        var durationSec = Math.min(120, Math.max(18, minScrollWidth / MARQUEE_PX_PER_SEC));
        trackEl.style.setProperty('--marquee-duration', durationSec.toFixed(1) + 's');
    }

    function bindMarqueePause() {
        if (marqueeBound) {
            return;
        }
        var marqueeEl = document.getElementById('presensi-scan-timer-marquee');
        if (!marqueeEl) {
            return;
        }
        marqueeBound = true;
        marqueeEl.setAttribute('title', 'Ketuk untuk jeda / lanjut teks jadwal');
        marqueeEl.addEventListener('click', function () {
            if (marqueeEl.classList.contains('is-static')) {
                return;
            }
            marqueeEl.classList.toggle('is-paused');
        });
    }

    function updateMarquee(slots) {
        var marqueeEl = document.getElementById('presensi-scan-timer-marquee');
        var trackEl = document.getElementById('presensi-scan-timer-marquee-track');
        var titleEl = document.getElementById('presensi-scan-timer-title');
        var rangeEl = document.getElementById('presensi-scan-timer-range');
        var useMarquee = Array.isArray(slots) && slots.length > 0;

        if (marqueeEl) {
            marqueeEl.classList.toggle('d-none', !useMarquee);
            marqueeEl.classList.toggle('is-always-scroll', useMarquee);
        }
        if (titleEl) {
            titleEl.classList.toggle('d-none', useMarquee);
        }
        if (rangeEl) {
            rangeEl.classList.toggle('d-none', useMarquee);
        }

        if (!useMarquee || !trackEl) {
            return;
        }

        var labels = slots.map(slotLabel);
        var html = '';
        var pass;
        var i;
        var repeatPasses = labels.length === 1 ? 6 : 2;
        for (pass = 0; pass < repeatPasses; pass += 1) {
            for (i = 0; i < labels.length; i += 1) {
                if (i > 0 || pass > 0) {
                    html += buildMarqueeSeparator();
                }
                html += buildMarqueeItem(labels[i]);
            }
        }
        if (trackEl.innerHTML !== html) {
            trackEl.innerHTML = html;
        }
        bindMarqueePause();
        bindMarqueeResize();
        marqueeSyncRetries = 0;
        global.requestAnimationFrame(function () {
            syncMarqueeSpeed();
            global.requestAnimationFrame(function () {
                syncMarqueeSpeed();
                scheduleMarqueeSync(120);
                scheduleMarqueeSync(400);
                scheduleMarqueeSync(900);
            });
        });
    }

    function hintForState(state, remainSec) {
        if (state === 'libur') {
            return 'Hari libur — scan ditolak';
        }
        if (state === 'ended') {
            return 'Di luar jadwal — scan ditolak';
        }
        if (state === 'none') {
            return 'Belum ada jadwal aktif';
        }
        if (state === 'active') {
            return 'Sisa waktu scan: ' + formatClock(remainSec);
        }
        if (state === 'upcoming') {
            return 'Mulai scan dalam: ' + formatClock(remainSec);
        }
        return '';
    }

    function render() {
        var box = document.getElementById('presensi-scan-timer');
        if (!box) {
            return;
        }

        var ctx = parseCtx();
        var titleEl = document.getElementById('presensi-scan-timer-title');
        var clockEl = document.getElementById('presensi-scan-timer-clock');
        var rangeEl = document.getElementById('presensi-scan-timer-range');
        var hintEl = document.getElementById('presensi-scan-timer-hint');

        if (!ctx) {
            setTimerClass(box, 'none', false);
            updateMarquee([]);
            if (titleEl) titleEl.textContent = 'Jadwal scan';
            if (clockEl) clockEl.textContent = '--:--';
            if (rangeEl) rangeEl.textContent = '';
            if (hintEl) hintEl.textContent = '';
            return;
        }

        var state = ctx.state || 'none';
        var slots = Array.isArray(ctx.slots) ? ctx.slots : [];
        var useMarquee = state === 'active' && slots.length > 0;
        setTimerClass(box, state, useMarquee);
        updateMarquee(useMarquee ? slots : []);

        var nama = ctx.nama_kegiatan || '';
        var tingkat = ctx.tingkatan || '';
        var range = '';
        if (ctx.jam_mulai && ctx.jam_selesai) {
            range = ctx.jam_mulai + ' – ' + ctx.jam_selesai;
        }

        if (state === 'libur') {
            if (titleEl) titleEl.textContent = 'Hari libur';
            if (clockEl) clockEl.textContent = '—';
            if (rangeEl) rangeEl.textContent = '';
            if (hintEl) hintEl.textContent = hintForState('libur', 0);
            return;
        }

        if (state === 'active' && ctx.ends_at) {
            var endMs = Date.parse(ctx.ends_at);
            var remain = Math.max(0, Math.floor((endMs - Date.now()) / 1000));
            if (titleEl && !useMarquee) {
                titleEl.textContent = nama !== '' ? nama : 'Kegiatan aktif';
            }
            if (rangeEl && !useMarquee) {
                rangeEl.textContent = range + (tingkat ? ' · ' + tingkat : '');
            }
            if (clockEl) {
                clockEl.textContent = formatClock(remain);
            }
            if (hintEl) {
                hintEl.textContent = hintForState('active', remain);
            }
            return;
        }

        if (state === 'upcoming' && ctx.starts_at) {
            var startMs = Date.parse(ctx.starts_at);
            var until = Math.max(0, Math.floor((startMs - Date.now()) / 1000));
            if (titleEl) {
                titleEl.textContent = nama !== '' ? nama : 'Kegiatan berikutnya';
            }
            if (rangeEl) {
                rangeEl.textContent = range + (tingkat ? ' · ' + tingkat : '');
            }
            if (clockEl) {
                clockEl.textContent = formatClock(until);
            }
            if (hintEl) {
                hintEl.textContent = hintForState('upcoming', until);
            }
            return;
        }

        if (state === 'ended') {
            if (titleEl) titleEl.textContent = 'Di luar jadwal';
            if (clockEl) clockEl.textContent = '00:00';
            if (rangeEl) rangeEl.textContent = '';
            if (hintEl) hintEl.textContent = hintForState('ended', 0);
            return;
        }

        if (titleEl) titleEl.textContent = 'Belum ada jadwal';
        if (clockEl) clockEl.textContent = '--:--';
        if (rangeEl) rangeEl.textContent = '';
        if (hintEl) hintEl.textContent = hintForState('none', 0);
    }

    function start() {
        render();
        if (tickTimer) {
            clearInterval(tickTimer);
        }
        tickTimer = setInterval(render, 1000);
        global.addEventListener('resize', syncMarqueeSpeed);
        global.addEventListener('load', syncMarqueeSpeed);
        global.addEventListener('orientationchange', function () {
            scheduleMarqueeSync(200);
        });
        if (global.document && global.document.fonts && typeof global.document.fonts.ready === 'object') {
            global.document.fonts.ready.then(function () {
                syncMarqueeSpeed();
            }).catch(function () { /* abaikan */ });
        }
    }

    global.PresensiScanTimer = { start: start, render: render };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})(window);
