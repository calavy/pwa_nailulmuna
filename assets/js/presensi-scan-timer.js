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
    var parsedCtxBase = null;
    var lastStateKey = '';
    var lastMarqueeSig = '';
    var daySlotBounds = null;
    var expandBound = false;

    function pad2(n) {
        return n < 10 ? '0' + n : String(n);
    }

    function formatClock(totalSec) {
        var s = Math.max(0, Math.floor(totalSec));
        var h = Math.floor(s / 3600);
        var m = Math.floor((s % 3600) / 60);
        var sec = s % 60;
        return pad2(h) + ':' + pad2(m) + ':' + pad2(sec);
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

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function buildMarqueeItem(slot, index, total) {
        var nama = String((slot && slot.nama_kegiatan) || 'Kegiatan').trim() || 'Kegiatan';
        var inner = '<span class="presensi-scan-timer-marquee__kegiatan">' + escapeHtml(nama) + '</span>';
        var mulai = String((slot && slot.jam_mulai) || '').slice(0, 5);
        var selesai = String((slot && slot.jam_selesai) || '').slice(0, 5);
        if (mulai && selesai) {
            inner += '<span class="presensi-scan-timer-marquee__waktu">' + escapeHtml(mulai + '–' + selesai) + '</span>';
        }
        if (slot && slot.tingkatan) {
            inner += '<span class="presensi-scan-timer-marquee__meta">' + escapeHtml(String(slot.tingkatan)) + '</span>';
        }
        if (slot && slot.tempat) {
            inner += '<span class="presensi-scan-timer-marquee__tempat">' + escapeHtml(String(slot.tempat)) + '</span>';
        }
        var tone = '';
        if (total > 1) {
            tone = (index % 2 === 0) ? ' is-tone-yellow' : ' is-tone-white';
        }
        return '<span class="presensi-scan-timer-marquee__item' + tone + '">'
            + '<i class="fa-solid fa-bolt" aria-hidden="true"></i>'
            + inner
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
        marqueeEl.addEventListener('click', function (e) {
            e.stopPropagation();
            if (marqueeEl.classList.contains('is-static')) {
                return;
            }
            marqueeEl.classList.toggle('is-paused');
        });
    }

    function setTimerExpanded(open) {
        var box = document.getElementById('presensi-scan-timer');
        var inner = box ? box.querySelector('.presensi-scan-timer-inner') : null;
        if (!box || !inner) {
            return;
        }
        box.classList.toggle('is-expanded', open);
        inner.setAttribute('aria-expanded', open ? 'true' : 'false');
        inner.setAttribute('title', open ? 'Ketuk untuk sembunyikan jadwal' : 'Ketuk untuk lihat jadwal');
        if (open) {
            marqueeSyncRetries = 0;
            scheduleMarqueeSync(50);
        }
    }

    function bindExpandToggle() {
        if (expandBound) {
            return;
        }
        var box = document.getElementById('presensi-scan-timer');
        var inner = box ? box.querySelector('.presensi-scan-timer-inner') : null;
        if (!box || !inner) {
            return;
        }
        expandBound = true;

        inner.addEventListener('click', function () {
            setTimerExpanded(!box.classList.contains('is-expanded'));
        });
        inner.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                setTimerExpanded(!box.classList.contains('is-expanded'));
            }
        });
    }

    function getParsedCtx() {
        if (!parsedCtxBase) {
            parsedCtxBase = parseCtx();
            daySlotBounds = null;
        }
        return parsedCtxBase;
    }

    function getDaySlotBounds(ctx) {
        if (daySlotBounds) {
            return daySlotBounds;
        }
        if (!ctx || !Array.isArray(ctx.day_slots) || ctx.day_slots.length === 0) {
            daySlotBounds = [];
            return daySlotBounds;
        }
        var out = [];
        var i;
        for (i = 0; i < ctx.day_slots.length; i += 1) {
            var slot = ctx.day_slots[i];
            var startMs = Date.parse(slot.starts_at || '');
            var endMs = Date.parse(slot.ends_at || '');
            if (!isNaN(startMs) && !isNaN(endMs)) {
                out.push({ slot: slot, startMs: startMs, endMs: endMs });
            }
        }
        daySlotBounds = out;
        return daySlotBounds;
    }

    function slotsSignature(slots) {
        if (!Array.isArray(slots) || slots.length === 0) {
            return '';
        }
        return slots.map(function (slot) {
            return String(slot.kegiatan_id || '') + '@' + String(slot.starts_at || '') + '-' + String(slot.ends_at || '');
        }).join('|');
    }

    function activeMarqueeSlots(ctx, slots) {
        if (Array.isArray(slots) && slots.length > 0) {
            return slots;
        }
        if (!ctx || ctx.state !== 'active') {
            return [];
        }
        var nama = String(ctx.nama_kegiatan || '').trim();
        if (!nama) {
            return [];
        }
        return [{
            nama_kegiatan: nama,
            jam_mulai: ctx.jam_mulai || '',
            jam_selesai: ctx.jam_selesai || '',
            tingkatan: ctx.tingkatan || '',
            tempat: ctx.tempat || '',
            starts_at: ctx.starts_at || '',
            ends_at: ctx.ends_at || ''
        }];
    }

    function updateMarquee(slots, force) {
        var marqueeEl = document.getElementById('presensi-scan-timer-marquee');
        var trackEl = document.getElementById('presensi-scan-timer-marquee-track');
        var titleEl = document.getElementById('presensi-scan-timer-title');
        var rangeEl = document.getElementById('presensi-scan-timer-range');
        var useMarquee = Array.isArray(slots) && slots.length > 0;

        if (marqueeEl) {
            marqueeEl.classList.toggle('d-none', !useMarquee);
            marqueeEl.classList.toggle('is-always-scroll', useMarquee);
            marqueeEl.classList.toggle('is-ready', useMarquee);
        }

        if (!useMarquee || !trackEl) {
            lastMarqueeSig = '';
            return;
        }

        var sig = slotsSignature(slots);
        if (!force && sig === lastMarqueeSig) {
            return;
        }
        lastMarqueeSig = sig;

        var html = '';
        var pass;
        var i;
        var repeatPasses = slots.length === 1 ? 6 : 2;
        for (pass = 0; pass < repeatPasses; pass += 1) {
            for (i = 0; i < slots.length; i += 1) {
                if (i > 0 || pass > 0) {
                    html += buildMarqueeSeparator();
                }
                html += buildMarqueeItem(slots[i], i, slots.length);
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

    function formatWallClock(dateObj) {
        return pad2(dateObj.getHours()) + ':' + pad2(dateObj.getMinutes()) + ':' + pad2(dateObj.getSeconds());
    }

    function recomputeLiveContext(ctx) {
        if (!ctx) {
            return null;
        }
        if (ctx.state === 'libur') {
            return ctx;
        }
        var daySlots = Array.isArray(ctx.day_slots) ? ctx.day_slots : null;
        if (!daySlots || daySlots.length === 0) {
            return ctx;
        }

        var now = Date.now();
        var active = [];
        var upcoming = [];
        var bounds = getDaySlotBounds(ctx);
        var i;

        for (i = 0; i < bounds.length; i += 1) {
            var bound = bounds[i];
            var slot = bound.slot;
            if (now >= bound.startMs && now <= bound.endMs) {
                active.push(slot);
            } else if (now < bound.startMs) {
                upcoming.push(slot);
            }
        }

        if (active.length > 0) {
            active.sort(function (a, b) {
                return Date.parse(a.ends_at || '') - Date.parse(b.ends_at || '');
            });
            var primary = active[0];
            return {
                state: 'active',
                nama_kegiatan: primary.nama_kegiatan || '',
                tingkatan: primary.tingkatan || '',
                jam_mulai: primary.jam_mulai || '',
                jam_selesai: primary.jam_selesai || '',
                tempat: primary.tempat || '',
                ends_at: primary.ends_at || '',
                starts_at: primary.starts_at || '',
                slots: active,
                day_slots: daySlots,
                libur_nama: ctx.libur_nama || ''
            };
        }

        if (upcoming.length > 0) {
            upcoming.sort(function (a, b) {
                return Date.parse(a.starts_at || '') - Date.parse(b.starts_at || '');
            });
            var next = upcoming[0];
            return {
                state: 'upcoming',
                nama_kegiatan: next.nama_kegiatan || '',
                tingkatan: next.tingkatan || '',
                jam_mulai: next.jam_mulai || '',
                jam_selesai: next.jam_selesai || '',
                tempat: next.tempat || '',
                ends_at: next.ends_at || '',
                starts_at: next.starts_at || '',
                slots: [],
                day_slots: daySlots,
                libur_nama: ctx.libur_nama || ''
            };
        }

        return {
            state: 'ended',
            nama_kegiatan: '',
            tingkatan: '',
            jam_mulai: '',
            jam_selesai: '',
            tempat: '',
            ends_at: '',
            starts_at: '',
            slots: [],
            day_slots: daySlots,
            libur_nama: ctx.libur_nama || ''
        };
    }

    function hintForState(state, remainSec) {
        if (state === 'libur') {
            return 'Hari libur — scan ditolak';
        }
        if (state === 'ended' || state === 'none') {
            return 'Belum ada kegiatan berlangsung';
        }
        if (state === 'active') {
            return 'Sisa waktu scan';
        }
        if (state === 'upcoming') {
            return 'Mulai scan dalam';
        }
        return '';
    }

    function applyStaticUi(box, ctx, state, slots, useMarquee) {
        var titleEl = document.getElementById('presensi-scan-timer-title');
        var rangeEl = document.getElementById('presensi-scan-timer-range');

        setTimerClass(box, state, useMarquee);
        updateMarquee(useMarquee ? slots : [], false);

        if (useMarquee) {
            setTimerExpanded(true);
        } else {
            setTimerExpanded(false);
        }

        if (state === 'libur') {
            if (titleEl) titleEl.textContent = 'Hari libur';
            if (rangeEl) rangeEl.textContent = '';
            return;
        }
        if (useMarquee) {
            return;
        }
        if (state === 'upcoming') {
            if (titleEl) titleEl.textContent = 'Kegiatan yang akan berlangsung';
            if (rangeEl) {
                var parts = [];
                var nama = String(ctx.nama_kegiatan || '').trim();
                var jamMulai = String(ctx.jam_mulai || '').slice(0, 5);
                var jamSelesai = String(ctx.jam_selesai || '').slice(0, 5);
                var tingkat = String(ctx.tingkatan || '').trim();
                if (nama) {
                    parts.push(nama);
                }
                if (jamMulai && jamSelesai) {
                    parts.push(jamMulai + ' – ' + jamSelesai);
                }
                if (tingkat) {
                    parts.push(tingkat);
                }
                rangeEl.textContent = parts.join(' · ');
            }
            return;
        }
        if (titleEl) titleEl.textContent = 'Belum ada kegiatan berlangsung';
        if (rangeEl) rangeEl.textContent = '';
    }

    function updateDynamicUi(ctx, state) {
        var clockEl = document.getElementById('presensi-scan-timer-clock');
        var wallEl = document.getElementById('presensi-scan-timer-wall');
        var hintEl = document.getElementById('presensi-scan-timer-hint');
        var nowWall = formatWallClock(new Date());

        if (wallEl) {
            var wallValue = document.getElementById('presensi-scan-timer-wall-value');
            if (wallValue) {
                wallValue.textContent = nowWall;
            } else {
                wallEl.textContent = nowWall;
            }
        }

        if (state === 'libur') {
            if (clockEl) clockEl.textContent = '—';
            if (hintEl) hintEl.textContent = hintForState('libur', 0);
            return;
        }
        if (state === 'active' && ctx.ends_at) {
            var endMs = Date.parse(ctx.ends_at);
            var remain = Math.max(0, Math.floor((endMs - Date.now()) / 1000));
            if (clockEl) clockEl.textContent = formatClock(remain);
            if (hintEl) hintEl.textContent = hintForState('active', remain);
            return;
        }
        if (state === 'upcoming' && ctx.starts_at) {
            var startMs = Date.parse(ctx.starts_at);
            var until = Math.max(0, Math.floor((startMs - Date.now()) / 1000));
            if (clockEl) clockEl.textContent = formatClock(until);
            if (hintEl) hintEl.textContent = hintForState('upcoming', until);
            return;
        }
        if (state === 'ended') {
            if (clockEl) clockEl.textContent = '00:00:00';
            if (hintEl) hintEl.textContent = hintForState('ended', 0);
            return;
        }
        if (clockEl) clockEl.textContent = '--:--:--';
        if (hintEl) hintEl.textContent = hintForState('none', 0);
    }

    function render() {
        var box = document.getElementById('presensi-scan-timer');
        if (!box) {
            return;
        }

        var ctx = recomputeLiveContext(getParsedCtx());
        if (!ctx) {
            if (lastStateKey !== 'none') {
                lastStateKey = 'none';
                lastMarqueeSig = '';
                setTimerClass(box, 'none', false);
                updateMarquee([], true);
                var titleEl = document.getElementById('presensi-scan-timer-title');
                var rangeEl = document.getElementById('presensi-scan-timer-range');
                if (titleEl) titleEl.textContent = 'Belum ada kegiatan berlangsung';
                if (rangeEl) rangeEl.textContent = '';
            }
            updateDynamicUi({ ends_at: '', starts_at: '' }, 'none');
            return;
        }

        var state = ctx.state || 'none';
        var slots = activeMarqueeSlots(ctx, Array.isArray(ctx.slots) ? ctx.slots : []);
        var useMarquee = state === 'active' && slots.length > 0;
        var stateKey = state + '|' + slotsSignature(slots) + '|' + String(ctx.nama_kegiatan || '') + '|' + String(ctx.starts_at || '') + '|' + String(ctx.ends_at || '');

        if (stateKey !== lastStateKey) {
            lastStateKey = stateKey;
            applyStaticUi(box, ctx, state, slots, useMarquee);
        }
        updateDynamicUi(ctx, state);
    }

    function start() {
        bindExpandToggle();
        render();
        syncMarqueeSpeed();
        scheduleMarqueeSync(150);
        if (tickTimer) {
            clearInterval(tickTimer);
        }
        tickTimer = setInterval(function () {
            var ctx = recomputeLiveContext(getParsedCtx());
            if (!ctx) {
                render();
                return;
            }
            var state = ctx.state || 'none';
            var slots = activeMarqueeSlots(ctx, Array.isArray(ctx.slots) ? ctx.slots : []);
            var stateKey = state + '|' + slotsSignature(slots) + '|' + String(ctx.nama_kegiatan || '') + '|' + String(ctx.starts_at || '') + '|' + String(ctx.ends_at || '');
            if (stateKey !== lastStateKey) {
                render();
                return;
            }
            updateDynamicUi(ctx, state);
        }, 1000);
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
