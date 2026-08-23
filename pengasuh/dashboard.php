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
require_once __DIR__ . '/../helpers/perizinan_approval.php';
require_once __DIR__ . '/../helpers/dashboard_insights.php';

require_pengasuh_dashboard();
require_once __DIR__ . '/../helpers/keaktifan_alpa_tanpa_scan.php';
keaktifan_alpa_tanpa_scan_redirect_if_saved($pdo);

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
$pgIdleData = !$adaKegiatanLive
    ? dashboard_idle_panel_data($pdo, $today, $nowTime)
    : ['agenda' => [], 'presensi' => [], 'jadwal_berikutnya' => []];

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

$izinPengasuhAntrian = perizinan_pengasuh_antrian($pdo, 6);
$izinPengasuhPendingCount = (int) ($izinPengasuhAntrian['total'] ?? 0);
$izinPengasuhIndividu = $izinPengasuhAntrian['individu'] ?? [];
$izinPengasuhRombongan = $izinPengasuhAntrian['rombongan'] ?? [];

$userId = (int) ($_SESSION['user']['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'setujui_pengasuh') {
        $res = perizinan_pengasuh_setujui($pdo, (int) ($_POST['izin_id'] ?? 0), $userId, false);
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/pengasuh/dashboard.php'));
        exit;
    }
    if ($action === 'tolak_pengasuh') {
        $res = perizinan_tolak_izin_satu($pdo, (int) ($_POST['izin_id'] ?? 0), $userId, 'pengasuh');
        set_flash($res['ok'] ? 'success' : 'error', $res['message']);
        header('Location: ' . app_href('/pengasuh/dashboard.php'));
        exit;
    }
}

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
    <?php require __DIR__ . '/../includes/partials/keaktifan_alpa_tanpa_scan_toggle.php'; ?>
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

    <div class="mb-4">
        <div class="card border-warning shadow-sm dash-panel<?= $izinPengasuhPendingCount > 0 ? '' : ' border-opacity-50' ?>">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <h2 class="h6 fw-bold mb-1 text-warning">
                            <i class="fa-solid fa-file-signature me-1"></i>
                            Persetujuan izin syar'i
                            <?php if ($izinPengasuhPendingCount > 0): ?>
                                <span class="badge text-bg-warning ms-1"><?= (int) $izinPengasuhPendingCount ?></span>
                            <?php endif; ?>
                        </h2>
                        <p class="small text-muted mb-0">
                            <?php if ($izinPengasuhPendingCount > 0): ?>
                                <strong><?= (int) $izinPengasuhPendingCount ?></strong> permohonan menunggu persetujuan pengasuh.
                            <?php else: ?>
                                Tidak ada permohonan izin syar'i yang menunggu saat ini.
                            <?php endif; ?>
                        </p>
                    </div>
                    <a href="<?= htmlspecialchars(app_href('/pengasuh/perizinan.php')) ?>" class="btn btn-sm btn-warning">
                        <i class="fa-solid fa-check-double me-1"></i> Buka persetujuan
                    </a>
                </div>

                <?php if ($izinPengasuhRombongan !== []): ?>
                <div class="mb-3">
                    <div class="small fw-semibold text-warning mb-2">Izin rombongan</div>
                    <div class="d-flex flex-column gap-2">
                        <?php foreach ($izinPengasuhRombongan as $rm): ?>
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 border rounded-3 px-3 py-2 bg-warning-subtle">
                                <div class="small">
                                    <strong>Rombongan #<?= (int) $rm['id'] ?></strong>
                                    · <?= (int) ($rm['jumlah'] ?? 0) ?> santri
                                    · <?= htmlspecialchars(app_format_izin_rentang(
                                        (string) ($rm['tanggal_mulai'] ?? ''),
                                        (string) ($rm['tanggal_selesai'] ?? ''),
                                        substr((string) ($rm['jam_mulai'] ?? ''), 0, 5),
                                        substr((string) ($rm['jam_selesai'] ?? ''), 0, 5)
                                    )) ?>
                                </div>
                                <a class="btn btn-sm btn-success" href="<?= htmlspecialchars(app_href('/pengasuh/perizinan.php')) ?>">Setujui</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($izinPengasuhIndividu !== []): ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0 pg-dash-izin-table">
                        <thead class="table-light">
                            <tr>
                                <th>Santri</th>
                                <th>Alasan</th>
                                <th>Periode</th>
                                <th class="text-end">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($izinPengasuhIndividu as $ip):
                            $izinIdRow = (int) ($ip['id'] ?? 0);
                            $alasanSingkat = trim((string) ($ip['alasan'] ?? ''));
                            if (mb_strlen($alasanSingkat) > 80) {
                                $alasanSingkat = mb_substr($alasanSingkat, 0, 77) . '…';
                            }
                            if ($alasanSingkat === '') {
                                $alasanSingkat = '—';
                            }
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold small"><?= htmlspecialchars((string) ($ip['nama_santri'] ?? '')) ?></div>
                                    <div class="text-muted font-monospace" style="font-size:.72rem"><?= htmlspecialchars((string) ($ip['nis'] ?? '')) ?></div>
                                </td>
                                <td class="small text-muted"><?= htmlspecialchars($alasanSingkat) ?></td>
                                <td class="small text-nowrap">
                                    <?= htmlspecialchars(app_format_izin_rentang(
                                        (string) ($ip['tanggal_mulai'] ?? ''),
                                        (string) ($ip['tanggal_selesai'] ?? ''),
                                        substr((string) ($ip['jam_mulai'] ?? ''), 0, 5),
                                        substr((string) ($ip['jam_selesai'] ?? ''), 0, 5)
                                    )) ?>
                                </td>
                                <td class="text-end">
                                    <div class="pg-dash-izin-actions">
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="action" value="setujui_pengasuh">
                                            <input type="hidden" name="izin_id" value="<?= $izinIdRow ?>">
                                            <button type="submit" class="btn btn-success btn-lg pg-dash-izin-btn">Setujui</button>
                                        </form>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Tolak permohonan izin ini?');">
                                            <input type="hidden" name="action" value="tolak_pengasuh">
                                            <input type="hidden" name="izin_id" value="<?= $izinIdRow ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-lg pg-dash-izin-btn">Tolak</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php elseif ($izinPengasuhPendingCount === 0): ?>
                    <div class="small text-muted text-center py-2">Permohonan baru dari wali santri akan muncul di sini.</div>
                <?php endif; ?>
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
<script>
(function () {
    document.querySelectorAll('form').forEach(function (form) {
        var actionInput = form.querySelector('input[name="action"]');
        if (!actionInput || actionInput.value !== 'setujui_pengasuh') {
            return;
        }
        form.addEventListener('submit', function (e) {
            if (form.getAttribute('data-submitting') === '1') {
                e.preventDefault();
                return;
            }
            form.setAttribute('data-submitting', '1');
            var btn = form.querySelector('.pg-dash-izin-btn[type="submit"]');
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'Memproses…';
            }
        });
    });
})();
</script>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/keaktifan-hari.js')) ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
