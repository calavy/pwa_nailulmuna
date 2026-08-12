(function () {
    'use strict';

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function dayDiff(a, b) {
        var da = new Date(a + 'T12:00:00');
        var db = new Date(b + 'T12:00:00');
        return Math.round((db - da) / 86400000) + 1;
    }

    function pctBetween(rangeStart, rangeEnd, date) {
        var total = dayDiff(rangeStart, rangeEnd);
        var offset = dayDiff(rangeStart, date) - 1;
        return Math.max(0, Math.min(100, (offset / total) * 100));
    }

    function buildMonthTicks(rangeStart, rangeEnd) {
        var ticks = [];
        var cur = new Date(rangeStart + 'T12:00:00');
        var end = new Date(rangeEnd + 'T12:00:00');
        var months = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        while (cur <= end) {
            var ymd = cur.toISOString().slice(0, 10);
            ticks.push({
                left: pctBetween(rangeStart, rangeEnd, ymd),
                label: 'M' + (ticks.length + 1) + ': ' + cur.getDate() + ' ' + months[cur.getMonth()],
            });
            cur.setDate(cur.getDate() + 14);
            if (ticks.length >= 6) break;
        }
        return ticks;
    }

    function categorySlug(cat) {
        var c = String(cat || '').toLowerCase();
        if (c === 'tugas') return 'tugas';
        if (c === 'acara') return 'acara';
        return 'acara';
    }

    function statusClass(st) {
        var s = String(st || '').toLowerCase();
        if (s === 'selesai') return 'selesai';
        if (s === 'berjalan') return 'berjalan';
        return 'mendatang';
    }

    function highlightEvent(id) {
        document.querySelectorAll('.akr-event-card--highlight').forEach(function (el) {
            el.classList.remove('akr-event-card--highlight');
        });
        var target = document.getElementById('akr-event-' + id);
        if (target) {
            target.classList.add('akr-event-card--highlight');
            target.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    function renderGantt(gantt) {
        var el = document.getElementById('akr-gantt');
        if (!el) return;

        if (!gantt || !gantt.items || !gantt.items.length) {
            el.innerHTML = '<div class="akr-gantt-empty"><i class="fa-solid fa-chart-gantt fa-2x mb-2 d-block opacity-50"></i><p class="mb-0">Belum ada jadwal untuk ditampilkan pada timeline.</p></div>';
            return;
        }

        var rangeStart = gantt.range_start;
        var rangeEnd = gantt.range_end;
        var totalDays = dayDiff(rangeStart, rangeEnd);
        var today = new Date().toISOString().slice(0, 10);
        var todayPct = pctBetween(rangeStart, rangeEnd, today);
        var showToday = today >= rangeStart && today <= rangeEnd;
        var ticks = buildMonthTicks(rangeStart, rangeEnd);

        var html = '<div class="akr-gantt-board">';
        html += '<div class="akr-gantt-board__grid-head">';
        html += '<div class="akr-gantt-board__label-col">Kegiatan</div>';
        html += '<div class="akr-gantt-timeline-col"><div class="akr-gantt-axis">';
        ticks.forEach(function (t) {
            html += '<span class="akr-gantt-axis__tick" style="left:' + t.left + '%">' + esc(t.label) + '</span>';
        });
        html += '</div></div></div>';

        gantt.items.forEach(function (item) {
            var offset = dayDiff(rangeStart, item.start) - 1;
            var span = dayDiff(item.start, item.end);
            var left = Math.max(0, (offset / totalDays) * 100);
            var width = Math.min(100 - left, Math.max(3, (span / totalDays) * 100));
            var cat = categorySlug(item.category_slug || item.category);
            var st = statusClass(item.status);
            var jenisFilter = cat;
            var daysLabel = span + ' hari';

            html += '<div class="akr-gantt-row" data-agenda-id="' + esc(String(item.id)) + '" data-jenis="' + esc(jenisFilter) + '">';
            html += '<div class="akr-gantt-row__info">';
            html += '<div class="akr-gantt-row__title" title="' + esc(item.title) + '">' + esc(item.title) + '</div>';
            html += '<div class="akr-gantt-row__meta">';
            html += '<span class="akr-gantt-pill akr-gantt-pill--' + cat + '">' + esc(item.category || 'Acara') + '</span>';
            html += '<span class="akr-gantt-pill akr-gantt-pill--' + st + '">' + esc(item.status_label || '') + '</span>';
            html += '</div></div>';
            html += '<div class="akr-gantt-row__track-wrap">';
            html += '<div class="akr-gantt-row__track">';
            if (showToday) {
                html += '<span class="akr-gantt-today" style="left:' + todayPct + '%" title="Hari ini"></span>';
            }
            html += '<button type="button" class="akr-gantt-bar akr-gantt-bar--' + (st === 'selesai' ? 'selesai' : cat) + '" ';
            html += 'style="left:' + left + '%;width:' + width + '%" ';
            html += 'data-agenda-id="' + esc(String(item.id)) + '" title="' + esc(item.title + ' · ' + daysLabel) + '">';
            html += esc(daysLabel);
            html += '</button></div>';
            html += '<div class="akr-gantt-row__dates">' + esc(item.start) + ' → ' + esc(item.end) + '</div>';
            html += '</div></div>';
        });

        html += '</div>';
        el.innerHTML = html;

        el.querySelectorAll('.akr-gantt-bar[data-agenda-id]').forEach(function (bar) {
            bar.addEventListener('click', function () {
                highlightEvent(bar.getAttribute('data-agenda-id'));
            });
        });

        el.querySelectorAll('.akr-gantt-row[data-agenda-id]').forEach(function (row) {
            row.addEventListener('click', function (e) {
                if (e.target.closest('.akr-gantt-bar')) return;
                highlightEvent(row.getAttribute('data-agenda-id'));
            });
        });
    }

    function applyJenisFilter(jenis) {
        document.querySelectorAll('.akr-event-card[data-jenis]').forEach(function (card) {
            var show = jenis === 'semua' || card.getAttribute('data-jenis') === jenis;
            card.classList.toggle('akr-event-card--hidden', !show);
        });
        document.querySelectorAll('.akr-gantt-row[data-jenis]').forEach(function (row) {
            var show = jenis === 'semua' || row.getAttribute('data-jenis') === jenis;
            row.classList.toggle('akr-gantt-row--hidden', !show);
        });
    }

    function initFilters() {
        document.querySelectorAll('.akr-chip[data-jenis-filter]').forEach(function (chip) {
            chip.addEventListener('click', function () {
                document.querySelectorAll('.akr-chip[data-jenis-filter]').forEach(function (c) {
                    c.classList.remove('active');
                });
                chip.classList.add('active');
                applyJenisFilter(chip.getAttribute('data-jenis-filter') || 'semua');
            });
        });
    }

    function initSidebarClick() {
        document.querySelectorAll('.akr-event-card[data-agenda-id]').forEach(function (card) {
            card.addEventListener('click', function (e) {
                if (e.target.closest('form, button')) return;
                var id = card.getAttribute('data-agenda-id');
                highlightEvent(id);
                var row = document.querySelector('.akr-gantt-row[data-agenda-id="' + id + '"]');
                if (row) {
                    row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                }
            });
        });
    }

    function initFormDates() {
        var mulai = document.getElementById('akr-tgl-mulai');
        var selesai = document.getElementById('akr-tgl-selesai');
        var form = document.getElementById('akr-form-tambah');
        if (mulai && selesai) {
            mulai.addEventListener('change', function () {
                if (selesai.value === '' || selesai.value < mulai.value) {
                    selesai.value = mulai.value;
                }
                selesai.min = mulai.value;
            });
        }
        if (form) {
            form.addEventListener('submit', function (e) {
                if (mulai && selesai && selesai.value !== '' && selesai.value < mulai.value) {
                    e.preventDefault();
                    alert('Tanggal selesai tidak boleh sebelum tanggal mulai.');
                }
            });
        }
    }

    function init() {
        var mount = document.getElementById('akr-dashboard');
        if (!mount) return;

        var gantt = null;
        try {
            gantt = JSON.parse(mount.getAttribute('data-gantt') || '{}');
        } catch (err) {
            gantt = { items: [] };
        }

        renderGantt(gantt);
        initFilters();
        initSidebarClick();
        initFormDates();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
