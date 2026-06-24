(function () {
    'use strict';

    var mount = document.getElementById('yt-dashboard-mount');
    var filter = 'aktif';
    var apiUrl = '';

    if (mount) {
        apiUrl = mount.getAttribute('data-api-url') || '';
        filter = mount.getAttribute('data-filter') || 'aktif';
    }
    if (!apiUrl) {
        var apiMeta = document.querySelector('meta[name="yp-timeline-api"]');
        apiUrl = apiMeta ? apiMeta.getAttribute('content') : '';
    }

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function dayDiff(a, b) {
        var ms = new Date(b + 'T12:00:00').getTime() - new Date(a + 'T12:00:00').getTime();
        return Math.max(1, Math.round(ms / 86400000) + 1);
    }

    function pctBetween(rangeStart, rangeEnd, dateStr) {
        var total = dayDiff(rangeStart, rangeEnd);
        var offset = dayDiff(rangeStart, dateStr) - 1;
        return Math.max(0, Math.min(100, (offset / total) * 100));
    }

    function categorySlug(cat) {
        var c = String(cat || '').toLowerCase();
        if (c === 'akademik') return 'akademik';
        if (c === 'asrama') return 'asrama';
        return 'yayasan';
    }

    function statusClass(status) {
        var s = String(status || '').toUpperCase();
        if (s === 'SELESAI' || s === 'DONE') return 'done';
        if (s === 'TERLAMBAT' || s === 'OVERDUE') return 'overdue';
        if (s === 'BERJALAN' || s === 'IN PROGRESS') return 'progress';
        return 'pending';
    }

    function buildMonthTicks(rangeStart, rangeEnd) {
        var ticks = [];
        var cur = new Date(rangeStart + 'T12:00:00');
        var end = new Date(rangeEnd + 'T12:00:00');
        cur.setDate(1);
        while (cur <= end) {
            var y = cur.getFullYear();
            var m = String(cur.getMonth() + 1).padStart(2, '0');
            var d = y + '-' + m + '-01';
            if (d >= rangeStart && d <= rangeEnd) {
                ticks.push({
                    date: d,
                    label: cur.toLocaleDateString('id-ID', { month: 'short', year: '2-digit' }),
                    left: pctBetween(rangeStart, rangeEnd, d),
                });
            }
            cur.setMonth(cur.getMonth() + 1);
        }
        return ticks;
    }

    function renderGantt(gantt) {
        var el = document.getElementById('yt-gantt');
        if (!el) return;

        if (!gantt || !gantt.items || !gantt.items.length) {
            el.innerHTML = '<div class="yt-gantt-empty"><i class="fa-solid fa-chart-gantt"></i><p>Belum ada tugas untuk ditampilkan pada grafik.</p></div>';
            return;
        }

        var rangeStart = gantt.range_start;
        var rangeEnd = gantt.range_end;
        var totalDays = dayDiff(rangeStart, rangeEnd);
        var today = new Date().toISOString().slice(0, 10);
        var todayPct = pctBetween(rangeStart, rangeEnd, today);
        var showToday = today >= rangeStart && today <= rangeEnd;
        var ticks = buildMonthTicks(rangeStart, rangeEnd);

        var html = '<div class="yt-gantt-board">';
        html += '<div class="yt-gantt-board__grid-head">';
        html += '<div class="yt-gantt-board__label-col">Kegiatan</div>';
        html += '<div class="yt-gantt-board__timeline-col">';
        html += '<div class="yt-gantt-axis">';
        ticks.forEach(function (t) {
            html += '<span class="yt-gantt-axis__tick" style="left:' + t.left + '%">' + esc(t.label) + '</span>';
        });
        html += '</div></div></div>';

        html += '<div class="yt-gantt-board__body">';
        gantt.items.forEach(function (item, idx) {
            var offset = dayDiff(rangeStart, item.start) - 1;
            var span = dayDiff(item.start, item.end);
            var left = Math.max(0, (offset / totalDays) * 100);
            var width = Math.min(100 - left, Math.max(2.5, (span / totalDays) * 100));
            var cat = categorySlug(item.category);
            var st = statusClass(item.status);
            var prog = Math.max(0, Math.min(100, parseInt(item.progress, 10) || 0));
            var tip = item.title + ' · ' + (item.status_label || item.status) + ' · ' + prog + '%';

            html += '<div class="yt-gantt-row" style="--row-i:' + idx + '">';
            html += '<div class="yt-gantt-row__info">';
            html += '<div class="yt-gantt-row__title" title="' + esc(item.title) + '">' + esc(item.title) + '</div>';
            html += '<div class="yt-gantt-row__meta">';
            html += '<span class="yt-gantt-pill yt-gantt-pill--' + cat + '">' + esc(item.category || 'Yayasan') + '</span>';
            html += '<span class="yt-gantt-pill yt-gantt-pill--' + st + '">' + esc(item.status_label || '') + '</span>';
            html += '</div>';
            if (item.pic_nama) {
                html += '<div class="yt-gantt-row__team" title="' + esc(item.pic_nama) + '">' + esc(item.pic_nama) + '</div>';
            }
            html += '</div>';

            html += '<div class="yt-gantt-row__track-wrap">';
            html += '<div class="yt-gantt-row__track">';
            if (showToday) {
                html += '<span class="yt-gantt-today" style="left:' + todayPct + '%" title="Hari ini"></span>';
            }
            html += '<button type="button" class="yt-gantt-bar yt-gantt-bar--' + cat + ' yt-gantt-bar--' + st + '" ';
            html += 'style="left:' + left + '%;width:' + width + '%" ';
            html += 'data-task-id="' + esc(String(item.id)) + '" title="' + esc(tip) + '">';
            html += '<span class="yt-gantt-bar__shine"></span>';
            html += '<span class="yt-gantt-bar__progress" style="width:' + prog + '%"></span>';
            html += '<span class="yt-gantt-bar__label">' + prog + '%</span>';
            html += '</button>';
            html += '</div>';
            html += '<div class="yt-gantt-row__dates">' + esc(item.start) + ' → ' + esc(item.end) + '</div>';
            html += '</div>';
            html += '</div>';
        });
        html += '</div></div>';

        el.innerHTML = html;

        el.querySelectorAll('.yt-gantt-bar[data-task-id]').forEach(function (bar) {
            bar.addEventListener('click', function () {
                var id = bar.getAttribute('data-task-id');
                var target = document.getElementById('yt-task-' + id);
                if (!target) return;
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                target.classList.add('yt-item--highlight');
                setTimeout(function () { target.classList.remove('yt-item--highlight'); }, 2200);
            });
        });
    }

    function renderWorkload(workload) {
        var el = document.getElementById('yt-workload');
        if (!el) return;
        if (!workload || !workload.length) {
            el.innerHTML = '<p class="text-muted small mb-0">Tidak ada beban kerja aktif.</p>';
            return;
        }
        var max = workload[0].active || 1;
        var html = '<div class="yt-workload">';
        workload.slice(0, 8).forEach(function (w) {
            var pct = Math.round((w.active / max) * 100);
            var hot = w.active >= 5 ? ' yt-workload__bar--hot' : '';
            html += '<div class="yt-workload__item"><div class="d-flex justify-content-between small mb-1"><span>' +
                esc(w.pic_nama) + '</span><strong>' + w.active + '</strong></div>';
            html += '<div class="yt-workload__track"><div class="yt-workload__bar' + hot + '" style="width:' + pct + '%"></div></div></div>';
        });
        html += '</div>';
        el.innerHTML = html;
    }

    function renderConflicts(conflicts, count) {
        var panel = document.getElementById('yt-conflict-panel');
        var list = document.getElementById('yt-conflict-list');
        if (!panel || !list) return;
        var badge = panel.querySelector('.yt-conflict-panel__count');
        if (count > 0) {
            panel.classList.add('yt-conflict-panel--alert');
            if (badge) badge.textContent = String(count);
        } else {
            panel.classList.remove('yt-conflict-panel--alert');
            if (badge) badge.textContent = '0';
        }
        if (!conflicts || !conflicts.length) {
            list.innerHTML = '<p class="text-muted small mb-0">Tidak ada jadwal bentrok.</p>';
            return;
        }
        var html = '<ul class="list-unstyled mb-0 small">';
        conflicts.forEach(function (c) {
            html += '<li class="mb-2"><strong>' + esc(c.pic_nama) + '</strong><br>';
            html += esc(c.task_a.judul) + ' (' + esc(c.task_a.start_at) + ')<br>';
            html += '↔ ' + esc(c.task_b.judul) + ' (' + esc(c.task_b.start_at) + ')</li>';
        });
        html += '</ul>';
        list.innerHTML = html;
    }

    function initFormPanel() {
        var panel = document.getElementById('yt-form-card');
        var toggle = document.getElementById('yt-form-toggle');
        if (!panel) return;

        function openForm() {
            panel.classList.add('is-open');
            panel.setAttribute('data-form-open', '1');
            if (toggle) toggle.classList.add('d-none');
            var first = panel.querySelector('input[name=judul], select, textarea');
            if (first) {
                setTimeout(function () { first.focus(); }, 280);
            }
        }

        function closeForm() {
            if (panel.getAttribute('data-edit-mode') === '1') return;
            panel.classList.remove('is-open');
            panel.setAttribute('data-form-open', '0');
            if (toggle) toggle.classList.remove('d-none');
        }

        if (panel.getAttribute('data-form-open') === '1') {
            openForm();
        }
        if (panel.querySelector('input[name="id"]')) {
            panel.setAttribute('data-edit-mode', '1');
        }

        if (toggle) {
            toggle.addEventListener('click', openForm);
        }
        panel.querySelectorAll('.yt-form-close').forEach(function (btn) {
            btn.addEventListener('click', closeForm);
        });
    }

    function initDoneButtons() {
        document.querySelectorAll('.yt-btn-done').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var form = btn.closest('form');
                if (!form) return;
                var range = form.querySelector('[type=range]');
                var label = form.querySelector('.yt-range-label');
                if (range) range.value = '100';
                if (label) label.textContent = '100%';
                form.submit();
            });
        });
    }

    function load() {
        if (!apiUrl || !mount) return;
        var ganttEl = document.getElementById('yt-gantt');
        if (ganttEl && !ganttEl.querySelector('.yt-gantt-board')) {
            ganttEl.innerHTML = '<div class="yt-gantt-skeleton"><div class="yt-gantt-skeleton__bar"></div><div class="yt-gantt-skeleton__bar"></div><div class="yt-gantt-skeleton__bar"></div></div>';
        }
        fetch(apiUrl + (apiUrl.indexOf('?') >= 0 ? '&' : '?') + 'filter=' + encodeURIComponent(filter), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) return;
                renderGantt(data.gantt);
                renderWorkload(data.workload);
                renderConflicts(data.conflicts, data.conflict_count || 0);
            })
            .catch(function () { /* abaikan */ });
    }

    initFormPanel();
    initDoneButtons();
    if (mount) load();
    document.addEventListener('yp:navigated', function () {
        initFormPanel();
        initDoneButtons();
        load();
    });
})();
