<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/yayasan.php';
require_once __DIR__ . '/../helpers/yayasan_keaktifan_bulan.php';

require_roles(['admin', 'pengurus']);

yayasan_ensure_tables($pdo);

$kbPanelOpen = (string) ($_GET['kb_open'] ?? '') === '1'
    || (isset($_SERVER['REQUEST_URI']) && str_contains((string) $_SERVER['REQUEST_URI'], '#yp-keaktifan-bulan'));
$kb = yayasan_keaktifan_bulan_pack_light($pdo);
$pageTitle = 'Yayasan';
$pageStylesheets = [app_asset_href('/assets/css/yayasan-portal.css')];
$pageScripts = [app_asset_href('/assets/js/yayasan-period.js')];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="yp-wrap" id="yp-operasional-root">
    <header class="yp-hero yp-hero--operasional mb-4">
        <p class="page-intro-kicker mb-1">Yayasan</p>
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-end gap-3">
            <div>
                <h1 class="h3 mb-1">Dashboard Yayasan</h1>
                <p class="text-muted mb-0">Fokus keuangan & operasional cepat — <?= htmlspecialchars(date('l, d F Y')) ?></p>
            </div>
            <span class="yp-kas-badge yp-kas-badge--secondary" id="yp-kas-badge">
                <i class="fa-solid fa-spinner fa-spin me-1"></i>Memuat status…
            </span>
        </div>
    </header>

    <div class="row g-3 mb-4" id="yp-kas-row">
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100 yp-card-kas yp-card-kas--secondary" id="yp-card-kas-status">
                <div class="card-body">
                    <div class="placeholder-glow">
                        <span class="placeholder col-6 mb-2"></span>
                        <span class="placeholder col-8 mb-3" style="height:2rem"></span>
                        <span class="placeholder col-12 mb-2"></span>
                        <div class="row g-2">
                            <div class="col-6"><span class="placeholder col-12"></span></div>
                            <div class="col-6"><span class="placeholder col-12"></span></div>
                            <div class="col-6"><span class="placeholder col-12"></span></div>
                            <div class="col-6"><span class="placeholder col-12"></span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100" id="yp-card-tagihan">
                <div class="card-body placeholder-glow">
                    <span class="placeholder col-5 mb-2"></span>
                    <span class="placeholder col-7 mb-3" style="height:2rem"></span>
                    <span class="placeholder col-12 mb-2"></span>
                    <span class="placeholder col-12 mb-3" style="height:8px"></span>
                    <span class="placeholder col-4"></span>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm h-100 bg-primary text-white">
                <div class="card-body d-flex flex-column">
                    <div class="small text-uppercase fw-bold opacity-75 mb-1">Pembayaran Cepat</div>
                    <p class="small opacity-90 mb-3">Catat transaksi pembayaran santri langsung di lokasi tanpa menu panjang.</p>
                    <div class="mt-auto d-grid gap-2">
                        <a class="btn btn-light fw-semibold" href="<?= htmlspecialchars(app_href('/keuangan/pembayaran.php')) ?>">
                            <i class="fa-solid fa-bolt me-1"></i>Input Pembayaran
                        </a>
                        <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars(app_href('/presensi/scan.php')) ?>">
                            <i class="fa-solid fa-qrcode me-1"></i>Scan Presensi
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <?php if (!empty($kb['ready'])): ?>
        <div class="col-md-4">
            <button
                type="button"
                class="card border-0 shadow-sm text-start w-100 h-100 yp-nav-card yp-nav-card--toggle<?= $kbPanelOpen ? '' : ' collapsed' ?>"
                id="ypKeaktifanBulanToggle"
                data-bs-toggle="collapse"
                data-bs-target="#ypKeaktifanBulanPanel"
                aria-expanded="<?= $kbPanelOpen ? 'true' : 'false' ?>"
                aria-controls="ypKeaktifanBulanPanel"
            >
                <div class="card-body">
                    <i class="fa-solid fa-calendar-days text-info mb-2"></i>
                    <div class="fw-semibold text-dark">Rekap Keaktifan Bulanan</div>
                    <div class="small text-muted">Bulan Hijriyah · ringkasan &amp; peringatan scan</div>
                    <span class="badge text-bg-secondary mt-2" id="yp-kb-badge">Ketuk untuk muat rekap</span>
                    <div class="small text-primary mt-2 yp-nav-card__hint">
                        <i class="fa-solid fa-chevron-down me-1 yp-nav-card__chev" aria-hidden="true"></i>
                        <span class="yp-nav-card__hint-text"><?= $kbPanelOpen ? 'Ketuk untuk tutup' : 'Ketuk untuk buka' ?></span>
                    </div>
                </div>
            </button>
        </div>
        <?php endif; ?>
        <div class="col-md-4">
            <a class="card border-0 shadow-sm text-decoration-none h-100 yp-nav-card" href="<?= htmlspecialchars(app_href('/yayasan/pengawasan.php')) ?>">
                <div class="card-body">
                    <i class="fa-solid fa-chart-line text-primary mb-2"></i>
                    <div class="fw-semibold text-dark">Dashboard Pengawasan</div>
                    <div class="small text-muted">Grafik arus kas & ringkasan ketertiban</div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a class="card border-0 shadow-sm text-decoration-none h-100 yp-nav-card" href="<?= htmlspecialchars(app_href('/yayasan/ringkasan.php')) ?>">
                <div class="card-body">
                    <i class="fa-solid fa-list-check text-success mb-2"></i>
                    <div class="fw-semibold text-dark">To-Do &amp; Agenda</div>
                    <div class="small text-muted">Tugas mendesak & kegiatan terdekat</div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a class="card border-0 shadow-sm text-decoration-none h-100 yp-nav-card" href="<?= htmlspecialchars(app_href('/yayasan/keaktifan.php')) ?>">
                <div class="card-body">
                    <i class="fa-solid fa-signal text-info mb-2"></i>
                    <div class="fw-semibold text-dark">Keaktifan Hari Ini</div>
                    <div class="small text-muted">Rekap scan real-time & drill-down</div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a class="card border-0 shadow-sm text-decoration-none h-100 yp-nav-card" href="<?= htmlspecialchars(app_href('/yayasan/keaktifan_ranking.php')) ?>">
                <div class="card-body">
                    <i class="fa-solid fa-ranking-star text-warning mb-2"></i>
                    <div class="fw-semibold text-dark">Ranking per Tingkatan</div>
                    <div class="small text-muted">Perbandingan keaktifan antar tingkatan</div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a class="card border-0 shadow-sm text-decoration-none h-100 yp-nav-card" href="<?= htmlspecialchars(app_href('/yayasan/kesehatan.php')) ?>">
                <div class="card-body">
                    <i class="fa-solid fa-heart-pulse text-danger mb-2"></i>
                    <div class="fw-semibold text-dark">Laporan Kesehatan</div>
                    <div class="small text-muted">Izin sakit, E-Health &amp; grafik tren</div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a class="card border-0 shadow-sm text-decoration-none h-100 yp-nav-card" href="<?= htmlspecialchars(app_href('/yayasan/ketertiban.php')) ?>">
                <div class="card-body">
                    <i class="fa-solid fa-shield-halved text-danger mb-2"></i>
                    <div class="fw-semibold text-dark">Ketertiban</div>
                    <div class="small text-muted">Pelanggaran & tindak lanjut</div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a class="card border-0 shadow-sm text-decoration-none h-100 yp-nav-card" href="<?= htmlspecialchars(app_href('/yayasan/timeline.php')) ?>">
                <div class="card-body">
                    <i class="fa-solid fa-route text-warning mb-2"></i>
                    <div class="fw-semibold text-dark">Timeline &amp; Tugas</div>
                    <div class="small text-muted">Hasil rapat, progres, kalender</div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a class="card border-0 shadow-sm text-decoration-none h-100 yp-nav-card" href="<?= htmlspecialchars(app_href('/yayasan/sdm_hari.php')) ?>">
                <div class="card-body">
                    <i class="fa-solid fa-user-check text-info mb-2"></i>
                    <div class="fw-semibold text-dark">Keaktifan SDM Hari Ini</div>
                    <div class="small text-muted">Pembimbing & munawib hadir</div>
                </div>
            </a>
        </div>
        <div class="col-md-4">
            <a class="card border-0 shadow-sm text-decoration-none h-100 yp-nav-card" href="<?= htmlspecialchars(app_href('/yayasan/rapat.php')) ?>">
                <div class="card-body">
                    <i class="fa-solid fa-handshake text-secondary mb-2"></i>
                    <div class="fw-semibold text-dark">Rapat &amp; Musyawarah</div>
                    <div class="small text-muted">Jadwal rapat, presensi &amp; notulen</div>
                </div>
            </a>
        </div>
    </div>

    <?php if (!empty($kb['ready'])): ?>
    <div class="collapse<?= $kbPanelOpen ? ' show' : '' ?>" id="ypKeaktifanBulanPanel">
        <div id="ypKeaktifanBulanMount" class="mt-3" data-loaded="0">
            <?php if ($kbPanelOpen): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center text-muted py-4">
                    <i class="fa-solid fa-spinner fa-spin me-1"></i> Memuat rekap keaktifan bulanan…
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
window.__ypOperasional = <?= json_encode([
    'kasApi' => app_href('/api/yayasan/kas_status.php'),
    'kbApi' => app_href('/api/yayasan/keaktifan_bulan_panel.php'),
    'kbQuery' => array_filter([
        'kb_refresh' => ($_GET['kb_refresh'] ?? '') === '1' ? '1' : null,
        'tanpa_scan' => ($_GET['tanpa_scan'] ?? '') === '1' ? '1' : null,
    ], static fn ($v) => $v !== null && $v !== ''),
    'kbOpen' => $kbPanelOpen,
], JSON_UNESCAPED_UNICODE) ?>;

(function () {
    var cfg = window.__ypOperasional || {};
    var kasBadge = document.getElementById('yp-kas-badge');
    var kasCard = document.getElementById('yp-card-kas-status');
    var tagihanCard = document.getElementById('yp-card-tagihan');
    var kbMount = document.getElementById('ypKeaktifanBulanMount');
    var kbPanel = document.getElementById('ypKeaktifanBulanPanel');
    var kbBadge = document.getElementById('yp-kb-badge');
    var kbLoaded = false;

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }

    function renderKas(k) {
        if (!k) return;
        if (kasBadge) {
            kasBadge.className = 'yp-kas-badge yp-kas-badge--' + esc(k.badge);
            kasBadge.innerHTML = '<i class="fa-solid fa-shield-halved me-1"></i>' + esc(k.label);
        }
        if (kasCard) {
            kasCard.className = 'card border-0 shadow-sm h-100 yp-card-kas yp-card-kas--' + esc(k.level);
            kasCard.innerHTML = '<div class="card-body">'
                + '<div class="small text-uppercase fw-bold opacity-75 mb-1">Status Keuangan</div>'
                + '<div class="display-6 fw-bold mb-2">' + esc(k.label) + '</div>'
                + '<p class="small mb-3">' + esc(k.ringkasan) + '</p>'
                + '<div class="row g-2 small">'
                + '<div class="col-6"><div class="text-muted">Saldo kas</div><div class="fw-semibold">' + esc(k.saldo_kas_fmt) + '</div></div>'
                + '<div class="col-6"><div class="text-muted">Net bulan ini</div><div class="fw-semibold ' + (k.net_negatif ? 'text-danger' : 'text-success') + '">' + esc(k.net_bulan_ini_fmt) + '</div></div>'
                + '<div class="col-6"><div class="text-muted">Tertagih</div><div class="fw-semibold">' + Number(k.persen_tertagih || 0).toLocaleString('id-ID', {maximumFractionDigits: 1}) + '%</div></div>'
                + '<div class="col-6"><div class="text-muted">Neraca</div><div class="fw-semibold">' + (k.neraca_seimbang ? 'Seimbang' : 'Selisih') + '</div></div>'
                + '</div></div>';
        }
        if (tagihanCard && k.tagihan) {
            var t = k.tagihan;
            tagihanCard.innerHTML = '<div class="card-body">'
                + '<div class="d-flex justify-content-between align-items-start mb-2">'
                + '<div><div class="small text-uppercase fw-bold text-muted">Tagihan Aktif</div>'
                + '<div class="fs-2 fw-bold text-danger">' + esc(t.total_piutang_fmt) + '</div></div>'
                + '<i class="fa-solid fa-file-invoice-dollar fs-3 text-danger opacity-50"></i></div>'
                + '<p class="small text-muted mb-2">' + esc(t.jumlah_penunggak) + ' santri penunggak · ' + esc(t.bulan_label)
                + (t.ta_label ? ' TA ' + esc(t.ta_label) : '') + '</p>'
                + '<div class="progress mb-2" style="height:8px"><div class="progress-bar bg-success" style="width:' + Math.min(100, Number(t.persen_tertagih || 0)) + '%"></div></div>'
                + '<a class="btn btn-outline-danger btn-sm" href="' + <?= json_encode(app_href('/pembayaran/tagihan_syahriyah.php')) ?> + '"><i class="fa-solid fa-list me-1"></i>Lihat tagihan</a>'
                + '</div>';
        }
    }

    function loadKas() {
        if (!cfg.kasApi) return;
        fetch(cfg.kasApi, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) { if (data && data.ok) renderKas(data.kas); })
            .catch(function () {
                if (kasBadge) kasBadge.innerHTML = '<i class="fa-solid fa-triangle-exclamation me-1"></i>Gagal memuat';
                if (kasCard) kasCard.querySelector('.card-body')?.insertAdjacentHTML('beforeend', '<p class="small text-danger mt-2 mb-0">Status keuangan gagal dimuat. <a href="#" onclick="location.reload();return false;">Muat ulang</a></p>');
            });
    }

    function runScriptsIn(root) {
        var scripts = Array.prototype.slice.call(root.querySelectorAll('script'));
        function runNext(i) {
            if (i >= scripts.length) return;
            var oldScript = scripts[i];
            var s = document.createElement('script');
            Array.prototype.forEach.call(oldScript.attributes, function (attr) {
                s.setAttribute(attr.name, attr.value);
            });
            if (oldScript.src) {
                s.onload = function () { runNext(i + 1); };
                s.onerror = function () { runNext(i + 1); };
                oldScript.parentNode.replaceChild(s, oldScript);
            } else {
                s.textContent = oldScript.textContent;
                oldScript.parentNode.replaceChild(s, oldScript);
                runNext(i + 1);
            }
        }
        runNext(0);
    }

    function loadKbPanel(force) {
        if (!kbMount || !cfg.kbApi) return;
        if (kbLoaded && !force) return;
        kbLoaded = true;
        kbMount.dataset.loaded = 'loading';
        kbMount.innerHTML = '<div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-4"><i class="fa-solid fa-spinner fa-spin me-1"></i> Memuat rekap…</div></div>';
        var params = new URLSearchParams(cfg.kbQuery || {});
        fetch(cfg.kbApi + '?' + params.toString(), { credentials: 'same-origin', headers: { Accept: 'application/json' } })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.ok && typeof data.html === 'string') {
                    kbMount.innerHTML = data.html;
                    runScriptsIn(kbMount);
                    kbMount.dataset.loaded = '1';
                    if (kbBadge) {
                        var n = Number(data.perhatian_count || 0);
                        kbBadge.className = 'badge mt-2 ' + (n > 0 ? 'text-bg-danger' : 'text-bg-success');
                        kbBadge.textContent = n > 0 ? (n + ' perlu perhatian') : 'Kondisi baik';
                    }
                } else {
                    kbLoaded = false;
                    kbMount.innerHTML = '<div class="alert alert-warning small mb-0">Gagal memuat rekap bulanan.</div>';
                }
            })
            .catch(function () {
                kbLoaded = false;
                kbMount.innerHTML = '<div class="alert alert-warning small mb-0">Gagal memuat rekap bulanan.</div>';
            });
    }

    window.__ypReloadKbPanel = function (form, extra) {
        if (!kbMount) return;
        var q = {};
        if (form instanceof HTMLFormElement) {
            new FormData(form).forEach(function (v, k) {
                if (v !== '') q[k] = v;
            });
        } else {
            q = Object.assign({}, cfg.kbQuery || {});
        }
        if (extra && typeof extra === 'object') {
            Object.keys(extra).forEach(function (k) {
                if (extra[k] === null || extra[k] === undefined || extra[k] === '') {
                    delete q[k];
                } else {
                    q[k] = extra[k];
                }
            });
        }
        cfg.kbQuery = q;
        kbLoaded = false;
        loadKbPanel(true);
        try {
            var url = new URL(window.location.href);
            ['kb_mode', 'kb_month', 'kb_year', 'kb_tingkatan', 'kb_refresh', 'tanpa_scan'].forEach(function (k) {
                url.searchParams.delete(k);
            });
            if (q.kb_refresh) url.searchParams.set('kb_refresh', q.kb_refresh);
            if (q.tanpa_scan) url.searchParams.set('tanpa_scan', q.tanpa_scan);
            url.hash = 'yp-keaktifan-bulan';
            window.history.replaceState({}, '', url.pathname + url.search + url.hash);
        } catch (e) { /* ignore */ }
    };

    document.addEventListener('click', function (e) {
        var refreshBtn = e.target.closest('[data-yp-kb-refresh]');
        if (refreshBtn && kbMount && kbMount.contains(refreshBtn)) {
            window.__ypReloadKbPanel(null, { kb_refresh: '1' });
            return;
        }
        var tanpaBtn = e.target.closest('[data-yp-kb-load-tanpa-scan]');
        if (tanpaBtn && kbMount && kbMount.contains(tanpaBtn)) {
            window.__ypReloadKbPanel(null, { tanpa_scan: '1' });
        }
    });

    if (kbPanel) {
        kbPanel.addEventListener('show.bs.collapse', loadKbPanel);
    }
    if (cfg.kbOpen) {
        loadKbPanel();
    }
    if ('requestIdleCallback' in window) {
        requestIdleCallback(loadKas, { timeout: 1200 });
    } else {
        setTimeout(loadKas, 50);
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
