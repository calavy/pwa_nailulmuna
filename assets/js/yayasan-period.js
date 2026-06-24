(function () {
    'use strict';

    var chartJsUrl = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js';
    var chartJsLoading = null;
    var rankChartInstances = {};
    var kesChartInstances = {};

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function formParams(form) {
        var q = {};
        new FormData(form).forEach(function (v, k) {
            if (v !== '') {
                q[k] = v;
            }
        });
        return q;
    }

    function ensureChartJs() {
        if (typeof Chart !== 'undefined') {
            return Promise.resolve();
        }
        if (chartJsLoading) {
            return chartJsLoading;
        }
        chartJsLoading = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = chartJsUrl;
            s.onload = function () { resolve(); };
            s.onerror = function () { reject(new Error('chart.js')); };
            document.head.appendChild(s);
        });
        return chartJsLoading;
    }

    function destroyChart(map, key) {
        if (map[key]) {
            try {
                map[key].destroy();
            } catch (e) { /* ignore */ }
            delete map[key];
        }
    }

    function initRankCharts(chart) {
        if (!chart || typeof Chart === 'undefined' || !chart.labels || !chart.labels.length) {
            return;
        }
        var uid = chart.uid || '';
        var hadirEl = document.getElementById('chart' + uid + 'Hadir');
        var stackedEl = document.getElementById('chart' + uid + 'Stacked');

        destroyChart(rankChartInstances, uid + 'Hadir');
        destroyChart(rankChartInstances, uid + 'Stacked');

        if (hadirEl) {
            rankChartInstances[uid + 'Hadir'] = new Chart(hadirEl, {
                type: 'bar',
                data: {
                    labels: chart.labels,
                    datasets: [{
                        label: '% Hadir',
                        data: chart.persen_hadir,
                        backgroundColor: chart.bar_colors,
                        borderRadius: 4,
                        barThickness: 18
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            callbacks: {
                                label: function (ctx) { return ctx.parsed.x.toFixed(2) + '% hadir'; }
                            }
                        }
                    },
                    scales: {
                        x: {
                            beginAtZero: true,
                            max: 100,
                            ticks: { callback: function (v) { return v + '%'; } }
                        },
                        y: { ticks: { font: { size: 11 } } }
                    }
                }
            });
        }

        if (stackedEl) {
            rankChartInstances[uid + 'Stacked'] = new Chart(stackedEl, {
                type: 'bar',
                data: { labels: chart.labels, datasets: chart.stacked_datasets },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        x: {
                            stacked: true,
                            beginAtZero: true,
                            max: 100,
                            ticks: { callback: function (v) { return v + '%'; } }
                        },
                        y: { stacked: true, ticks: { font: { size: 11 } } }
                    },
                    plugins: {
                        legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } }
                    }
                }
            });
        }
    }

    function initKesehatanCharts(charts) {
        if (!charts || typeof Chart === 'undefined') {
            return;
        }
        var bulan = charts.bulan || {};
        var tingkatan = charts.tingkatan || {};
        var status = charts.status || {};
        var suhu = charts.suhu || {};

        ['bulan', 'tingkatan', 'status', 'suhu'].forEach(function (k) {
            destroyChart(kesChartInstances, k);
        });

        var elBulan = document.getElementById('chartKesehatanBulan');
        if (elBulan && bulan.labels && bulan.labels.length) {
            kesChartInstances.bulan = new Chart(elBulan, {
                type: 'line',
                data: {
                    labels: bulan.labels,
                    datasets: [
                        {
                            label: 'Kasus izin sakit',
                            data: bulan.kasus,
                            borderColor: '#0d6efd',
                            backgroundColor: 'rgba(13, 110, 253, 0.12)',
                            fill: true,
                            tension: 0.25,
                            yAxisID: 'y'
                        },
                        {
                            label: 'Santri unik',
                            data: bulan.santri,
                            borderColor: '#198754',
                            backgroundColor: 'rgba(25, 135, 84, 0.08)',
                            fill: false,
                            tension: 0.25,
                            yAxisID: 'y'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } }
                }
            });
        }

        var elTingkatan = document.getElementById('chartKesehatanTingkatan');
        if (elTingkatan && tingkatan.labels && tingkatan.labels.length) {
            kesChartInstances.tingkatan = new Chart(elTingkatan, {
                type: 'bar',
                data: {
                    labels: tingkatan.labels,
                    datasets: [
                        { label: 'Hari sakit', data: tingkatan.hari, backgroundColor: '#3b82f6' },
                        { label: 'Kasus', data: tingkatan.kasus, backgroundColor: '#93c5fd' }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } }
                }
            });
        }

        var elStatus = document.getElementById('chartKesehatanStatus');
        if (elStatus && status.labels && status.labels.length) {
            kesChartInstances.status = new Chart(elStatus, {
                type: 'doughnut',
                data: {
                    labels: status.labels,
                    datasets: [{
                        data: status.values,
                        backgroundColor: ['#0d6efd', '#dc3545', '#fd7e14', '#198754']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } }
                }
            });
        }

        var elSuhu = document.getElementById('chartKesehatanSuhu');
        if (elSuhu && suhu.labels && suhu.labels.length) {
            kesChartInstances.suhu = new Chart(elSuhu, {
                type: 'bar',
                data: {
                    labels: suhu.labels,
                    datasets: [{
                        label: 'Catatan',
                        data: suhu.values,
                        backgroundColor: ['#22c55e', '#facc15', '#fb923c', '#ef4444', '#94a3b8']
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                    plugins: { legend: { display: false } }
                }
            });
        }
    }

    function updateRankHero(stats) {
        var wrap = document.getElementById('yp-rank-hero-stats');
        if (!wrap || !stats) {
            return;
        }
        if (!stats.tingkatan_count) {
            wrap.classList.add('d-none');
            wrap.innerHTML = '';
            return;
        }
        wrap.classList.remove('d-none');
        wrap.innerHTML = ''
            + '<span class="yp-rank-stat-chip"><i class="fa-solid fa-layer-group"></i> ' + esc(stats.tingkatan_count) + ' tingkatan</span>'
            + '<span class="yp-rank-stat-chip"><i class="fa-solid fa-calendar"></i> ' + esc(stats.periode_label) + '</span>'
            + '<span class="yp-rank-stat-chip"><i class="fa-solid fa-filter"></i> ' + esc(stats.kategori_label) + '</span>';
    }

    function updateKesSubtitle(label) {
        var el = document.getElementById('yp-kes-subtitle');
        if (el && label) {
            el.textContent = 'Rekap izin sakit disetujui & catatan E-Health — ' + label;
        }
    }

    function pushUrl(params) {
        try {
            var boot = window.__ypPeriodBoot || {};
            var url = new URL(window.location.href);
            if (boot.lockPeriode) {
                ['mode', 'month', 'year', 'kb_mode', 'kb_month', 'kb_year'].forEach(function (k) {
                    url.searchParams.delete(k);
                });
            }
            var urlParams = params;
            if (boot.lockPeriode) {
                urlParams = {};
                ['kategori', 'tingkatan'].forEach(function (k) {
                    if (params[k] !== null && params[k] !== undefined && params[k] !== '') {
                        urlParams[k] = params[k];
                    }
                });
            }
            Object.keys(urlParams).forEach(function (k) {
                if (urlParams[k] === null || urlParams[k] === undefined || urlParams[k] === '') {
                    url.searchParams.delete(k);
                } else {
                    url.searchParams.set(k, String(urlParams[k]));
                }
            });
            url.searchParams.delete('refresh');
            window.history.replaceState({}, '', url.pathname + url.search + url.hash);
        } catch (e) { /* ignore */ }
    }

    function openRankDetail(tingkatan) {
        if (!tingkatan) {
            return;
        }
        var cards = document.querySelectorAll('[data-tingkatan]');
        for (var i = 0; i < cards.length; i++) {
            var card = cards[i];
            if (card.getAttribute('data-tingkatan') === tingkatan) {
                card.click();
                card.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                break;
            }
        }
    }

    function loadPeriod(apiUrl, mountId, params, handlers) {
        var mount = document.getElementById(mountId);
        if (!mount || !apiUrl) {
            return Promise.resolve();
        }
        mount.innerHTML = '<div class="text-center py-5 text-muted"><i class="fa-solid fa-spinner fa-spin me-1"></i> Memuat data…</div>';
        var qs = new URLSearchParams(params);
        return fetch(apiUrl + '?' + qs.toString(), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data || !data.ok) {
                    mount.innerHTML = '<div class="alert alert-warning mb-0">' + esc((data && data.message) || 'Gagal memuat.') + '</div>';
                    return;
                }
                mount.innerHTML = data.html || '';
                pushUrl(params);
                if (handlers && typeof handlers.after === 'function') {
                    return handlers.after(data);
                }
            })
            .catch(function () {
                mount.innerHTML = '<div class="alert alert-warning mb-0">Gagal memuat data. Coba lagi.</div>';
            });
    }

    function loadRank(apiUrl, mountId, params) {
        return loadPeriod(apiUrl, mountId, params, {
            after: function (data) {
                updateRankHero(data.stats);
                if (data.lazy) {
                    window.__rekapRankLazy = data.lazy;
                }
                return ensureChartJs().then(function () {
                    initRankCharts(data.chart);
                    if (data.open_tingkatan) {
                        window.setTimeout(function () { openRankDetail(data.open_tingkatan); }, 80);
                    }
                });
            }
        });
    }

    function loadKesehatan(apiUrl, mountId, params) {
        return loadPeriod(apiUrl, mountId, params, {
            after: function (data) {
                updateKesSubtitle(data.periode_label);
                return ensureChartJs().then(function () {
                    initKesehatanCharts(data.charts);
                });
            }
        });
    }

    function handlePeriodForm(form, extraParams) {
        var apiUrl = form.getAttribute('data-yp-period-api');
        var mountId = form.getAttribute('data-yp-period-mount');
        if (!apiUrl || !mountId) {
            return;
        }
        var params = formParams(form);
        if (extraParams) {
            Object.assign(params, extraParams);
        }
        var boot = window.__ypPeriodBoot || {};
        if (boot.type === 'kesehatan') {
            loadKesehatan(apiUrl, mountId, params);
        } else {
            loadRank(apiUrl, mountId, params);
        }
    }

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        if (!form.matches('[data-yp-period-ajax="1"]')) {
            return;
        }
        e.preventDefault();
        handlePeriodForm(form);
    });

    document.addEventListener('click', function (e) {
        var a = e.target.closest('a[href*="refresh=1"]');
        if (!a) {
            return;
        }
        var card = a.closest('.rekap-periode-card');
        if (!card) {
            return;
        }
        var form = card.querySelector('[data-yp-period-ajax="1"]');
        if (!form) {
            return;
        }
        e.preventDefault();
        handlePeriodForm(form, { refresh: '1' });
    });

    function boot() {
        var bootCfg = window.__ypPeriodBoot;
        if (!bootCfg || !bootCfg.api || !bootCfg.mount) {
            return;
        }
        var params = bootCfg.params || {};
        if (bootCfg.type === 'kesehatan') {
            loadKesehatan(bootCfg.api, bootCfg.mount, params);
        } else {
            loadRank(bootCfg.api, bootCfg.mount, params);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
    document.addEventListener('yp:navigated', boot);
})();
