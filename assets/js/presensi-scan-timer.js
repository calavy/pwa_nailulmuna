/**
 * Hitung mundur jadwal scan presensi di atas kamera.
 */
(function (global) {
    'use strict';

    var tickTimer = null;

    function pad2(n) {
        return n < 10 ? '0' + n : String(n);
    }

    function formatDuration(totalSec) {
        var s = Math.max(0, Math.floor(totalSec));
        var h = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        var sec = s % 60;
        if (h > 0) {
            return h + ' jam ' + m + ' menit';
        }
        if (m > 0) {
            return m + ' menit ' + pad2(sec) + ' detik';
        }
        return sec + ' detik';
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

    function setTimerClass(box, state) {
        box.classList.remove('is-active', 'is-upcoming', 'is-ended', 'is-libur', 'is-none');
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
    }

    function render() {
        var box = document.getElementById('presensi-scan-timer');
        if (!box) {
            return;
        }

        var ctx = parseCtx();
        var titleEl = document.getElementById('presensi-scan-timer-title');
        var clockEl = document.getElementById('presensi-scan-timer-clock');
        var hintEl = document.getElementById('presensi-scan-timer-hint');
        var rangeEl = document.getElementById('presensi-scan-timer-range');

        if (!ctx) {
            setTimerClass(box, 'none');
            if (titleEl) titleEl.textContent = 'Jadwal scan';
            if (clockEl) clockEl.textContent = '--:--';
            if (hintEl) hintEl.textContent = 'Tidak ada data jadwal';
            return;
        }

        var state = ctx.state || 'none';
        setTimerClass(box, state);

        var nama = ctx.nama_kegiatan || '';
        var tingkat = ctx.tingkatan || '';
        var range = '';
        if (ctx.jam_mulai && ctx.jam_selesai) {
            range = ctx.jam_mulai + ' – ' + ctx.jam_selesai;
        }

        if (state === 'libur') {
            if (titleEl) titleEl.textContent = 'Hari libur';
            if (clockEl) clockEl.textContent = '—';
            if (hintEl) hintEl.textContent = ctx.libur_nama || 'Presensi tidak dicatat';
            if (rangeEl) rangeEl.textContent = '';
            return;
        }

        if (state === 'active' && ctx.ends_at) {
            var endMs = Date.parse(ctx.ends_at);
            var remain = Math.max(0, Math.floor((endMs - Date.now()) / 1000));
            if (titleEl) {
                titleEl.textContent = nama !== '' ? nama : 'Kegiatan aktif';
            }
            if (rangeEl) {
                rangeEl.textContent = range + (tingkat ? ' · ' + tingkat : '');
            }
            if (clockEl) {
                clockEl.textContent = formatClock(remain);
            }
            if (hintEl) {
                hintEl.textContent = 'Sisa waktu scan: ' + formatDuration(remain);
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
                hintEl.textContent = 'Scan dibuka dalam ' + formatDuration(until);
            }
            return;
        }

        if (state === 'ended') {
            if (titleEl) titleEl.textContent = 'Di luar jadwal';
            if (clockEl) clockEl.textContent = '00:00';
            if (hintEl) hintEl.textContent = 'Tidak ada sesi scan aktif hari ini';
            if (rangeEl) rangeEl.textContent = '';
            return;
        }

        if (titleEl) titleEl.textContent = 'Belum ada jadwal';
        if (clockEl) clockEl.textContent = '--:--';
        if (hintEl) hintEl.textContent = 'Atur jadwal kegiatan di menu Jadwal';
        if (rangeEl) rangeEl.textContent = '';
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
