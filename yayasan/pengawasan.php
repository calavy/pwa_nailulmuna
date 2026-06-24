<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/yayasan.php';
require_once __DIR__ . '/../helpers/yayasan_dashboard.php';
require_once __DIR__ . '/../helpers/yayasan_portal.php';

require_roles(['admin', 'pengurus']);

yayasan_ensure_tables($pdo);

$pageTitle = 'Dashboard Pengawasan Yayasan';
$pageStylesheets = [app_asset_href('/assets/css/yayasan-portal.css')];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="yp-wrap" id="yp-pengawasan-root">
    <header class="mb-4">
        <?php $yayasanCrumbTail = 'Pengawasan'; require __DIR__ . '/../includes/partials/yayasan_crumb.php'; ?>
        <h1 class="h3 mb-1">Dashboard Pengawasan</h1>
        <p class="text-muted mb-0">Manajemen khusus — arus kas periodik & ketertiban hari ini.</p>
    </header>

    <section class="mb-4" id="yp-pengawasan-keuangan">
        <h2 class="h5 mb-3"><i class="fa-solid fa-chart-column me-2 text-primary"></i>Keuangan — 6 Bulan Terakhir</h2>
        <div class="card border-0 shadow-sm">
            <div class="card-body placeholder-glow" id="yp-pengawasan-keuangan-body">
                <div class="row g-2 mb-3 text-center small">
                    <div class="col-4"><span class="placeholder col-10"></span><span class="placeholder col-8 mt-2"></span></div>
                    <div class="col-4"><span class="placeholder col-10"></span><span class="placeholder col-8 mt-2"></span></div>
                    <div class="col-4"><span class="placeholder col-10"></span><span class="placeholder col-8 mt-2"></span></div>
                </div>
                <div class="yp-chart">
                    <?php for ($i = 0; $i < 6; $i++): ?>
                    <div class="yp-chart-col"><span class="placeholder w-100" style="height:120px"></span></div>
                    <?php endfor; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="mb-4" id="yp-pengawasan-ketertiban">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h2 class="h5 mb-0"><i class="fa-solid fa-gavel me-2 text-warning"></i>Ketertiban Hari Ini</h2>
            <div class="d-flex flex-wrap gap-2">
                <a class="btn btn-sm btn-outline-danger" href="<?= htmlspecialchars(app_href('/yayasan/kesehatan.php')) ?>">
                    <i class="fa-solid fa-heart-pulse me-1"></i>Laporan kesehatan
                </a>
                <a class="btn btn-sm btn-warning" href="<?= htmlspecialchars(app_href('/yayasan/ketertiban.php')) ?>">Menu Ketertiban</a>
            </div>
        </div>
        <div class="row g-3" id="yp-pengawasan-ketertiban-cards">
            <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body placeholder-glow"><span class="placeholder col-4 mb-2"></span><span class="placeholder col-8"></span></div></div></div>
            <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body placeholder-glow"><span class="placeholder col-4 mb-2"></span><span class="placeholder col-8"></span></div></div></div>
            <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body placeholder-glow"><span class="placeholder col-4 mb-2"></span><span class="placeholder col-8"></span></div></div></div>
        </div>
        <div id="yp-pengawasan-ketertiban-alert" class="mt-3"></div>
    </section>
</div>

<script>
window.__ypPengawasan = <?= json_encode(['api' => app_href('/api/yayasan/pengawasan_data.php')], JSON_UNESCAPED_UNICODE) ?>;
(function () {
    var cfg = window.__ypPengawasan || {};
    function esc(s) {
        var d = document.createElement('div');
        d.textContent = s == null ? '' : String(s);
        return d.innerHTML;
    }
    function render(data) {
        if (!data || !data.ok) return;
        var k = data.keuangan || {};
        var chart = Array.isArray(k.chart) ? k.chart : [];
        var keuBody = document.getElementById('yp-pengawasan-keuangan-body');
        if (keuBody) {
            var netClass = Number(k.net_bulan_ini || 0) < 0 ? 'text-danger' : 'text-success';
            var chartHtml = chart.map(function (col) {
                return '<div class="yp-chart-col"><div class="yp-chart-bars">'
                    + '<div class="yp-bar yp-bar--in" style="height:' + col.h_in + '%" title="Masuk ' + esc(col.masuk_fmt) + '"></div>'
                    + '<div class="yp-bar yp-bar--out" style="height:' + col.h_out + '%" title="Keluar ' + esc(col.keluar_fmt) + '"></div>'
                    + '</div><div class="yp-chart-label">' + esc(col.label) + '</div></div>';
            }).join('');
            keuBody.innerHTML = '<div class="row g-2 mb-3 text-center small">'
                + '<div class="col-4"><div class="text-muted">Masuk bln ini</div><div class="fw-bold text-success">' + esc(k.masuk_bulan_ini_fmt) + '</div></div>'
                + '<div class="col-4"><div class="text-muted">Keluar bln ini</div><div class="fw-bold text-danger">' + esc(k.keluar_bulan_ini_fmt) + '</div></div>'
                + '<div class="col-4"><div class="text-muted">Net</div><div class="fw-bold ' + netClass + '">' + esc(k.net_bulan_ini_fmt) + '</div></div>'
                + '</div><div class="yp-chart">' + chartHtml + '</div>'
                + '<div class="d-flex gap-3 justify-content-center small mt-2"><span><span class="yp-dot yp-dot--in"></span> Pemasukan</span><span><span class="yp-dot yp-dot--out"></span> Pengeluaran</span></div>'
                + '<div class="text-center mt-3"><a class="btn btn-sm btn-outline-primary" href="' + <?= json_encode(app_href('/keuangan/arus-kas.php')) ?> + '">Detail arus kas</a></div>';
        }
        var ket = data.ketertiban || {};
        var cards = document.getElementById('yp-pengawasan-ketertiban-cards');
        if (cards) {
            cards.innerHTML = '<div class="col-md-4"><div class="card border-0 shadow-sm h-100 border-start border-4 border-danger"><div class="card-body"><div class="fs-2 fw-bold text-danger">' + Number(ket.izin_lewat || 0) + '</div><div class="fw-semibold">Izin Melewati Toleransi</div><p class="small text-muted mb-0">Belum kembali setelah batas izin + grace.</p></div></div></div>'
                + '<div class="col-md-4"><div class="card border-0 shadow-sm h-100 border-start border-4 border-info"><div class="card-body"><div class="fs-2 fw-bold text-info">' + Number(ket.sakit || 0) + '</div><div class="fw-semibold">Sakit Perlu Penanganan</div><p class="small text-muted mb-0">Izin sakit aktif atau presensi sakit hari ini.</p></div></div></div>'
                + '<div class="col-md-4"><div class="card border-0 shadow-sm h-100 border-start border-4 border-dark"><div class="card-body"><div class="fs-2 fw-bold">' + Number(ket.alpa_beruntun || 0) + '</div><div class="fw-semibold">Alpa Kebangetan</div><p class="small text-muted mb-0">Bolos berturut-turut tanpa keterangan.</p></div></div></div>';
        }
        var alertBox = document.getElementById('yp-pengawasan-ketertiban-alert');
        if (alertBox && Number(ket.total || 0) > 0) {
            alertBox.innerHTML = '<div class="alert alert-warning mb-0 small"><i class="fa-solid fa-triangle-exclamation me-1"></i>'
                + Number(ket.total) + ' santri membutuhkan tindakan disiplin hari ini. <a href="' + <?= json_encode(app_href('/yayasan/ketertiban.php')) ?> + '" class="alert-link">Buka detail</a></div>';
        }
    }
    if (!cfg.api) return;
    fetch(cfg.api, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(render)
        .catch(function () {
            var keuBody = document.getElementById('yp-pengawasan-keuangan-body');
            if (keuBody) {
                keuBody.innerHTML = '<div class="alert alert-warning small mb-0">Gagal memuat grafik keuangan. <a href="#" onclick="location.reload();return false;">Coba lagi</a></div>';
            }
        });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
