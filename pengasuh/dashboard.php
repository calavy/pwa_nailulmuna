<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/hijri_kalender.php';
require_once __DIR__ . '/../helpers/akademik_hari_khusus.php';
require_once __DIR__ . '/../helpers/akademik_pasaran.php';
require_once __DIR__ . '/../helpers/pengasuh_dashboard.php';
require_once __DIR__ . '/../helpers/pengasuh_laporan_hari.php';
require_once __DIR__ . '/../helpers/pembimbing_dashboard.php';

require_roles(['admin', 'pengurus', 'kiai']);

$today = date('Y-m-d');
$nowTime = date('H:i:s');
$jamServerLabel = substr($nowTime, 0, 5);

ensure_hijri_mappings_table($pdo);
ensure_akademik_hijri_awal_bulan_table($pdo);
$hijriBulanNamaDash = [
    1 => 'Muharram', 2 => 'Safar', 3 => "Rabi' I", 4 => "Rabi' II", 5 => 'Jumadil Awal', 6 => 'Jumadil Akhir',
    7 => 'Rajab', 8 => "Sya'ban", 9 => 'Ramadan', 10 => 'Syawal', 11 => "Dzulqa'dah", 12 => 'Dzulhijah',
];
$dashSyncKey = 'pengasuh_dashboard_hijri_sync_' . date('Y-m-d');
if (empty($_SESSION[$dashSyncKey])) {
    hijri_sync_from_akademik_awal_bulan($pdo);
    akademik_libur_sinkron_hari_khusus_tahun($pdo, (int) date('Y'), $hijriBulanNamaDash);
    $_SESSION[$dashSyncKey] = 1;
}
$dashHijriLabel = akademik_hijri_label_dari_masehi($pdo, $today, $hijriBulanNamaDash);
$dashPasaran = akademik_pasaran_tampilkan($pdo) ? akademik_pasaran_pada_tanggal($today, $pdo) : '';

$kegiatanAktif = pengasuh_dashboard_kegiatan_aktif($pdo, $nowTime);
$kegiatanAktifGrouped = jadwal_kelompokkan_kegiatan_aktif($kegiatanAktif);

$rowsHari = rekap_keaktifan_hari_data($pdo, $today);
$keaktivanPanels = [
    'TAALIM' => array_merge(
        ['key' => 'TAALIM', 'label' => "Ta'lim", 'slug' => 'taalim'],
        pengasuh_dashboard_keaktivan_bundle($pdo, $today, $rowsHari, $kegiatanAktif, 'TAALIM')
    ),
    'JAMAAH' => array_merge(
        ['key' => 'JAMAAH', 'label' => "Jama'ah", 'slug' => 'jamaah'],
        pengasuh_dashboard_keaktivan_bundle($pdo, $today, $rowsHari, $kegiatanAktif, 'JAMAAH')
    ),
];
$adaKegiatanLive = $kegiatanAktif !== [];
$keaktivanModeLive = ($keaktivanPanels['TAALIM']['mode'] ?? '') === 'live'
    || ($keaktivanPanels['JAMAAH']['mode'] ?? '') === 'live';
$keaktivanModeProgress = ($keaktivanPanels['TAALIM']['mode'] ?? '') === 'progress'
    || ($keaktivanPanels['JAMAAH']['mode'] ?? '') === 'progress';

$rowsLive = pengasuh_dashboard_filter_rows_berlangsung($rowsHari, $kegiatanAktif);
$detailLive = pengasuh_dashboard_urutkan_kegiatan(rekap_keaktifan_hari_detail_by_kegiatan($rowsLive));
$ringkasanLive = rekap_keaktifan_hari_ringkasan_from_detail($detailLive);
$totalsLive = rekap_keaktifan_hari_totals($ringkasanLive);
$keaktivanByTingkatan = pengasuh_dashboard_keaktivan_by_tingkatan($rowsHari, $kegiatanAktif);
$sdmByTingkatan = pengasuh_dashboard_sdm_by_tingkatan($pdo, $today, $kegiatanAktif);

$kegiatanAktifPresensi = [];
if ($kegiatanAktifGrouped !== []) {
    $kegiatanAktifPresensi = pembimbing_dashboard_presensi_kegiatan_berlangsung($pdo, $kegiatanAktifGrouped, $today, false);
}

$jumlahKegiatanBerlangsung = count($kegiatanAktifGrouped);
if ($jumlahKegiatanBerlangsung === 0 && $kegiatanAktifPresensi !== []) {
    $jumlahKegiatanBerlangsung = count($kegiatanAktifPresensi);
}
if ($jumlahKegiatanBerlangsung === 0 && $kegiatanAktif !== []) {
    $jumlahKegiatanBerlangsung = count(array_unique(array_map(
        static fn (array $r): int => (int) ($r['kegiatan_id'] ?? 0),
        $kegiatanAktif
    )));
}

$konteks = pengasuh_laporan_hari_konteks($pdo, $today, count($detailLive));
$dashServerClockMs = (int) round(microtime(true) * 1000);

$namaUser = trim((string) ($_SESSION['user']['nama'] ?? ''));
$labelUser = $namaUser !== '' ? $namaUser : 'Pengasuh';
$brandDash = app_header_brand_context($pdo);
$namaPonpes = (string) ($brandDash['title'] ?? 'Pondok Pesantren');
$alamatPonpes = (string) ($brandDash['alamat'] ?? '');
$dashLogoHref = app_pondok_logo_href($pdo);
$dashHeroKicker = (string) ($brandDash['tagline'] ?? '');
$dashLogoInitial = (string) ($brandDash['initials'] ?? 'AP');

$labelKegiatan = static function (string $nama): string {
    $nama = trim($nama);

    return $nama === '' ? '' : mb_convert_case($nama, MB_CASE_TITLE, 'UTF-8');
};

$barPct = static function (int $n, int $total): float {
    return $total > 0 ? round(100 * $n / $total, 2) : 0.0;
};

$previewNames = static function (array $santriByStatus, int $limit = 3): string {
    $names = [];
    foreach (['ALPA'] as $st) {
        foreach ($santriByStatus[$st] ?? [] as $s) {
            $nama = trim((string) ($s['nama_santri'] ?? ''));
            if ($nama !== '') {
                $names[] = $nama;
            }
            if (count($names) >= $limit) {
                break 2;
            }
        }
    }
    if ($names === []) {
        return '';
    }
    $more = count($santriByStatus['ALPA'] ?? []) - count($names);
    $txt = implode(', ', $names);

    return $more > 0 ? $txt . ' +' . $more : $txt;
};

$tglLabel = (string) ($konteks['tgl_label'] ?? $today);

$pageTitle = 'Dashboard Pengasuh';
$bodyClass = 'dash-page dash-home-mobile-fit page-pengasuh-dashboard kh-wrap';
$pageStylesheets = [
    app_asset_href('/assets/css/keaktifan-hari.css'),
    app_asset_href('/assets/css/pengasuh-dashboard.css'),
];
$loadPushFcm = true;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dash-page">
    <div class="dash-hero mb-3 pg-dash-hero">
        <div class="dash-hero-inner">
            <?php
            $brandTitle = $namaPonpes;
            $brandKicker = $dashHeroKicker;
            $brandAlamat = $alamatPonpes;
            $brandLogoHref = $dashLogoHref;
            $brandLogoInitial = $dashLogoInitial;
            require __DIR__ . '/../includes/partials/dash_hero_brand.php';
            ?>
            <div class="dash-hero-layout dash-hero-layout--slim">
                <div class="dash-hero-greeting">
                    <div class="dash-hero-kicker text-white-50">Pengasuh · Beranda</div>
                    <h1 class="h3 dash-hero-title mb-2"><?= htmlspecialchars($labelUser) ?></h1>
                    <p class="small text-white-50 mb-0">
                        <?= htmlspecialchars((string) ($konteks['hari_label'] ?? '')) ?>
                        · <?= htmlspecialchars((string) ($konteks['tgl_label'] ?? $today)) ?>
                        <?php if (($konteks['libur_label'] ?? '') !== ''): ?>
                            · <span class="text-warning"><?= htmlspecialchars((string) $konteks['libur_label']) ?></span>
                        <?php endif; ?>
                    </p>
                    <?php if ($dashHijriLabel !== ''): ?>
                        <p class="dash-hero-hijri mb-0 small text-white-50 d-none d-md-block">
                            <i class="fa-solid fa-moon" aria-hidden="true"></i>
                            <strong class="text-white"><?= htmlspecialchars($dashHijriLabel) ?></strong>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="dash-hero-clock" aria-live="polite">
                    <div class="dash-hero-clock__top">
                        <span class="dash-hero-clock__label"><i class="fa-regular fa-clock me-1"></i> Waktu berjalan</span>
                        <span class="dash-hero-clock__live">Live</span>
                    </div>
                    <div class="dash-hero-clock__time" id="dashboard-live-clock">--:--:--</div>
                    <div class="dash-hero-clock__date" id="dashboard-live-date"<?= $dashPasaran !== '' ? ' data-pasaran="' . htmlspecialchars($dashPasaran) . '"' : '' ?>>—</div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($jumlahKegiatanBerlangsung > 0): ?>
    <div class="mb-4">
        <div class="card border-0 shadow-sm dash-panel dash-panel--lift pg-dash-keg-live-summary">
            <div class="card-body px-4 py-3">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                    <div class="min-w-0">
                        <h2 class="h6 fw-bold mb-1">
                            <i class="fa-solid fa-circle-play text-success me-1"></i> Kegiatan berlangsung
                        </h2>
                        <p class="small text-muted mb-0">
                            Ada <strong><?= (int) $jumlahKegiatanBerlangsung ?></strong>
                            kegiatan sedang berlangsung
                            · slot <span data-pg-sync-clock="hm"><?= htmlspecialchars($jamServerLabel) ?></span> WIB
                        </p>
                    </div>
                    <?php if ($kegiatanAktifPresensi !== []): ?>
                    <button type="button"
                        class="btn btn-sm btn-primary rounded-pill px-3 pg-dash-keg-live-toggle"
                        data-bs-toggle="collapse"
                        data-bs-target="#pgDashKegiatanLiveDetail"
                        aria-expanded="false"
                        aria-controls="pgDashKegiatanLiveDetail">
                        <i class="fa-solid fa-eye me-1"></i> Lihat
                    </button>
                    <?php else: ?>
                    <a href="#pg-dash-keaktivan"
                        class="btn btn-sm btn-primary rounded-pill px-3">
                        <i class="fa-solid fa-eye me-1"></i> Lihat
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <?php if ($kegiatanAktifPresensi !== []): ?>
            <div class="collapse" id="pgDashKegiatanLiveDetail">
                <div class="card-body px-4 pb-4 pt-0 border-top">
                    <?php require __DIR__ . '/../includes/partials/dashboard_kegiatan_berlangsung_live.php'; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php require __DIR__ . '/partials/dashboard_keaktivan_berlangsung.php'; ?>
</div>

<script>
    window.PONDOK_SERVER_CLOCK_MS = <?= (int) $dashServerClockMs ?>;
    (function () {
        var detail = document.getElementById('pgDashKegiatanLiveDetail');
        var btn = document.querySelector('.pg-dash-keg-live-toggle');
        if (!detail || !btn) {
            return;
        }
        detail.addEventListener('show.bs.collapse', function () {
            btn.innerHTML = '<i class="fa-solid fa-eye-slash me-1"></i> Sembunyikan';
            btn.setAttribute('aria-expanded', 'true');
        });
        detail.addEventListener('hide.bs.collapse', function () {
            btn.innerHTML = '<i class="fa-solid fa-eye me-1"></i> Lihat';
            btn.setAttribute('aria-expanded', 'false');
        });
    })();
</script>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/keaktifan-hari.js')) ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
