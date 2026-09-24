<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/akademik.php';
require_once __DIR__ . '/../helpers/hijri_kalender.php';
require_once __DIR__ . '/../helpers/akademik_hari_khusus.php';
require_once __DIR__ . '/../helpers/akademik_pasaran.php';
require_once __DIR__ . '/../helpers/pengasuh_dashboard.php';
require_once __DIR__ . '/../helpers/pengasuh_laporan_hari.php';
require_once __DIR__ . '/../helpers/perizinan_approval.php';

require_pengasuh_dashboard();

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
$dashHijriLabel = akademik_hijri_badge_dashboard($pdo, $today, $hijriBulanNamaDash);
$dashHijriClock = akademik_hijri_label_h($pdo, $today, $hijriBulanNamaDash);
$dashPasaran = akademik_pasaran_tampilkan($pdo) ? akademik_pasaran_pada_tanggal($today, $pdo) : '';

$pgDashUseCache = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !isset($_GET['refresh']);
$pgDashBundle = pengasuh_dashboard_page_bundle($pdo, $today, $nowTime, $pgDashUseCache);
$kegiatanAktif = $pgDashBundle['kegiatanAktif'];
$kegiatanAktifGrouped = $pgDashBundle['kegiatanAktifGrouped'];
$keaktivanPanels = $pgDashBundle['keaktivanPanels'];
$adaKegiatanLive = (bool) ($pgDashBundle['adaKegiatanLive'] ?? false);
$keaktivanModeLive = (bool) ($pgDashBundle['keaktivanModeLive'] ?? false);
$keaktivanModeProgress = (bool) ($pgDashBundle['keaktivanModeProgress'] ?? false);
$kegiatanAktifPresensi = $pgDashBundle['kegiatanAktifPresensi'];
$jumlahKegiatanBerlangsung = (int) ($pgDashBundle['jumlahKegiatanBerlangsung'] ?? 0);
$pgIdleData = $pgDashBundle['pgIdleData'];
$liburTampil = $pgDashBundle['liburTampil'];

$konteks = pengasuh_laporan_hari_konteks($pdo, $today, count($kegiatanAktifGrouped));
$dashServerClockMs = (int) round(microtime(true) * 1000);
$pgDashRefreshHref = app_href('/pengasuh/dashboard.php?refresh=1');

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

$izinPengasuhPendingCount = perizinan_pengasuh_pending_count($pdo);
$pgDashIzinHref = app_href('/pengasuh/perizinan.php');

$santriPenepianHariCount = count(pengasuh_dashboard_santri_penepian_hari_ini($pdo, $today, 50));
$pgDashPenepianHref = app_href('/pengasuh/penepian.php?tanggal=' . urlencode($today));

$pageTitle = 'Dashboard Pengasuh';
$bodyClass = 'dash-page page-pengasuh-dashboard kh-wrap';
$pageStylesheets = [
    app_asset_href('/assets/css/keaktifan-hari.css'),
    app_asset_href('/assets/css/pengasuh-dashboard.css'),
];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="dash-page">
    <div class="dash-hero-split mb-3">
        <section class="dash-identity-card">
            <div class="dash-identity-card__brand">
                <?php
                $brandTitle = $namaPonpes;
                $brandKicker = $dashHeroKicker;
                $brandAlamat = $alamatPonpes;
                $brandLogoHref = $dashLogoHref;
                $brandLogoInitial = $dashLogoInitial;
                require __DIR__ . '/../includes/partials/dash_hero_brand.php';
                ?>
            </div>
            <div class="dash-identity-card__meta">
                <div class="dash-identity-card__role">
                    <span class="dash-identity-card__role-kicker">Pengasuh · Beranda</span>
                    <div class="dash-identity-card__role-value">
                        <i class="fa-solid fa-circle-check" aria-hidden="true"></i>
                        Pengasuh
                    </div>
                </div>
                <div class="dash-identity-card__greeting">
                    <h1 class="h3 dash-hero-title mb-2"><?= htmlspecialchars($labelUser) ?></h1>
                    <p class="small mb-0">
                        <?= htmlspecialchars((string) ($konteks['hari_label'] ?? '')) ?>
                        · <?= htmlspecialchars((string) ($konteks['tgl_label'] ?? $today)) ?>
                        <?php if (($konteks['libur_label'] ?? '') !== ''): ?>
                            · <span class="text-warning"><?= htmlspecialchars((string) $konteks['libur_label']) ?></span>
                        <?php endif; ?>
                        · <a href="<?= htmlspecialchars($pgDashRefreshHref) ?>" class="text-muted">Segarkan data</a>
                    </p>
                </div>
            </div>
        </section>
        <section class="dash-clock-card" aria-live="polite">
            <div class="dash-hero-clock__top">
                <span class="dash-hero-clock__label"><i class="fa-regular fa-clock me-1"></i> Waktu berjalan server</span>
                <span class="dash-hero-clock__live">Live</span>
            </div>
            <div class="dash-hero-clock__time" id="dashboard-live-clock">--:--:--</div>
            <div class="dash-clock-card__tz">WIB</div>
            <div class="dash-hero-clock__date" id="dashboard-live-date"<?= $dashPasaran !== '' ? ' data-pasaran="' . htmlspecialchars($dashPasaran) . '"' : '' ?><?= $dashHijriClock !== '' ? ' data-hijri="' . htmlspecialchars($dashHijriClock) . '"' : '' ?>>—</div>
        </section>
    </div>

    <div class="row g-3 mb-4 pg-dash-absensi-row align-items-stretch">
    <div class="col-12 col-lg-7" id="pg-dash-izin">
        <div class="card border-warning shadow-sm dash-panel pg-dash-izin-panel pg-dash-absensi-nav-card<?= $izinPengasuhPendingCount > 0 ? '' : ' border-opacity-50' ?>">
            <div class="card-body p-0 position-relative">
                <a href="<?= htmlspecialchars($pgDashIzinHref) ?>" class="pg-dash-absensi-toggle stretched-link px-3 py-3 d-block">
                    <span class="pg-dash-absensi-toggle__row">
                        <span class="pg-dash-absensi-toggle__title text-warning">
                            <i class="fa-solid fa-file-signature me-1"></i>
                            Persetujuan izin
                            <span class="badge text-bg-warning ms-1 pg-dash-absensi-count"><?= (int) $izinPengasuhPendingCount ?></span>
                        </span>
                        <span class="pg-dash-absensi-toggle__hint small text-muted">Ketuk untuk buka daftar</span>
                        <i class="fa-solid fa-chevron-right pg-dash-absensi-chevron" aria-hidden="true"></i>
                    </span>
                </a>
            </div>
        </div>
    </div>

    <div class="col-12 col-lg-5" id="pg-dash-penepian">
        <div class="card border-secondary shadow-sm dash-panel pg-dash-penepian-panel pg-dash-absensi-nav-card">
            <div class="card-body p-0 position-relative">
                <a href="<?= htmlspecialchars($pgDashPenepianHref) ?>" class="pg-dash-absensi-toggle stretched-link px-3 py-3 d-block">
                    <span class="pg-dash-absensi-toggle__row">
                        <span class="pg-dash-absensi-toggle__title">
                            <i class="fa-solid fa-house-circle-xmark text-secondary me-1"></i>
                            Santri menepi keaktifan
                            <span class="badge text-bg-light border ms-1 pg-dash-absensi-count"><?= (int) $santriPenepianHariCount ?></span>
                        </span>
                        <span class="pg-dash-absensi-toggle__hint small text-muted">Ketuk untuk buka daftar</span>
                        <i class="fa-solid fa-chevron-right pg-dash-absensi-chevron" aria-hidden="true"></i>
                    </span>
                </a>
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
