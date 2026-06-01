/**
 * Hitung mundur jadwal scan presensi di atas kamera.
 */
(function (global) {
    'use strict';

    var tickTimer = null;

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
            + escapeHtml(label)
            + '</span>';
    }

    function updateMarquee(slots) {
        var marqueeEl = document.getElementById('presensi-scan-timer-marquee');
        var trackEl = document.getElementById('presensi-scan-timer-marquee-track');
        var titleEl = document.getElementById('presensi-scan-timer-title');
        var rangeEl = document.getElementById('presensi-scan-timer-range');
        var useMarquee = Array.isArray(slots) && slots.length > 1;

        if (marqueeEl) {
            marqueeEl.classList.toggle('d-none', !useMarquee);
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

        var html = '';
        var labels = slots.map(slotLabel);
        var pass;
        for (pass = 0; pass < 2; pass += 1) {
            labels.forEach(function (label) {
                html += buildMarqueeItem(label);
            });
        }
        if (trackEl.innerHTML !== html) {
            trackEl.innerHTML = html;
        }
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
        var useMarquee = state === 'active' && slots.length > 1;
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
            if (hintEl) hintEl.textContent = 'Waktu scan kurang 00:00 menit lagi';
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
                hintEl.textContent = 'Waktu scan kurang ' + formatClock(remain) + ' menit lagi';
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
                hintEl.textContent = 'Waktu scan kurang ' + formatClock(until) + ' menit lagi';
            }
            return;
        }

        if (state === 'ended') {
            if (titleEl) titleEl.textContent = 'Di luar jadwal';
            if (clockEl) clockEl.textContent = '00:00';
            if (rangeEl) rangeEl.textContent = '';
            if (hintEl) hintEl.textContent = 'Waktu scan kurang 00:00 menit lagi';
            return;
        }

        if (titleEl) titleEl.textContent = 'Belum ada jadwal';
        if (clockEl) clockEl.textContent = '--:--';
        if (rangeEl) rangeEl.textContent = '';
        if (hintEl) hintEl.textContent = 'Waktu scan kurang 00:00 menit lagi';
    }

    function start() {
        render();
        if (tickTimer) {
            clearInterval(tickTimer);
        }
        tickTimer = setInterval(render, 1000);
    }

    global.PresensiScanTimer = { start: start, render: render };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})(window);
