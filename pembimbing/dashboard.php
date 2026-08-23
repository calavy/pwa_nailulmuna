<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/akademik_ikhtibar.php';
require_once __DIR__ . '/../helpers/pembimbing_dashboard.php';
require_once __DIR__ . '/../helpers/jadwal_ui.php';
require_once __DIR__ . '/../helpers/santri_list_sort.php';
require_once __DIR__ . '/../helpers/hijri_kalender.php';
require_once __DIR__ . '/../helpers/akademik.php';
require_once __DIR__ . '/../helpers/akademik_hari_khusus.php';
require_once __DIR__ . '/../helpers/akademik_pasaran.php';
require_once __DIR__ . '/../helpers/pembimbing_pkpps.php';
require_once __DIR__ . '/../helpers/pembimbing_nilai_manual.php';
require_once __DIR__ . '/../helpers/munawib_portal.php';
require_once __DIR__ . '/../helpers/dashboard_insights.php';

ikhtibar_require_pembimbing_access();
munawib_portal_require_konteks();

$isMunawibPortal = munawib_is_portal_session();
$munawibPortalKonteks = munawib_portal_konteks();

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
$bolehSemua = is_super_admin() || in_array($role, ['admin', 'pengurus'], true);

$pbDashViewEarly = strtolower(trim((string) ($_GET['view'] ?? 'home')));
if (!in_array($pbDashViewEarly, ['home', 'keaktivan'], true)) {
    $pbDashViewEarly = 'home';
}
$isPbHomeRingkasEarly = !$bolehSemua && $pbDashViewEarly === 'home';
$isPbKeaktivanOnly = !$bolehSemua && $pbDashViewEarly === 'keaktivan';
if (!$isPbHomeRingkasEarly && !$isPbKeaktivanOnly) {
    ensure_akademik_ikhtibar_tables($pdo);
}

$pembimbingInfo = $bolehSemua ? null : pembimbing_dashboard_current_pembimbing($pdo, $userId);
$pembimbingId = $pembimbingInfo !== null ? (int) ($pembimbingInfo['id'] ?? 0) : 0;
$hasPkppsJadwal = $pembimbingId > 0 && pembimbing_pkpps_has_jadwal($pdo, $pembimbingId);
$hasKajianJadwal = $pembimbingId > 0 && pembimbing_dashboard_has_kajian_jadwal($pdo, $pembimbingId);
$pembimbingNama = $pembimbingInfo !== null
    ? (string) ($pembimbingInfo['nama'] ?? '')
    : trim((string) ($_SESSION['user']['nama'] ?? ''));

$tingkatanMilik = pembimbing_dashboard_tingkatan_list($pdo, $pembimbingId > 0 ? $pembimbingId : null, $bolehSemua);
$tingkatanKajian = $bolehSemua
    ? pembimbing_dashboard_semua_tingkatan($pdo)
    : pembimbing_dashboard_kajian_tingkatan_list($pdo, $pembimbingId > 0 ? $pembimbingId : null, false);
$tingkatanPkpps = $bolehSemua ? [] : pembimbing_pkpps_tingkatan_labels($pdo, $pembimbingId);

$rekapJenis = strtolower(trim((string) ($_GET['rekap_jenis'] ?? '')));
if (!in_array($rekapJenis, ['kajian', 'pkpps'], true)) {
    if ($hasPkppsJadwal && !$hasKajianJadwal) {
        $rekapJenis = 'pkpps';
    } else {
        $rekapJenis = 'kajian';
    }
}
if (!$bolehSemua) {
    if ($rekapJenis === 'pkpps' && !$hasPkppsJadwal) {
        $rekapJenis = 'kajian';
    }
    if ($rekapJenis === 'kajian' && !$hasKajianJadwal && $hasPkppsJadwal) {
        $rekapJenis = 'pkpps';
    }
}

if ($tingkatanMilik === [] && $bolehSemua) {
    $tingkatanMilik = pembimbing_dashboard_semua_tingkatan($pdo);
}

/** Tingkatan yang diasuh pembimbing (otomatis dari jadwal). */
$tingkatanAsuhan = $tingkatanMilik;
$semuaTingkatanList = $tingkatanAsuhan;
$tingkatanFilter = trim((string) ($_GET['tingkatan'] ?? ''));
if ($isPbKeaktivanOnly && !$bolehSemua) {
    $semuaTingkatanList = $rekapJenis === 'pkpps' ? $tingkatanPkpps : $tingkatanKajian;
    $tingkatanAsuhan = $semuaTingkatanList;
}
if ($tingkatanFilter !== '' && !in_array($tingkatanFilter, $semuaTingkatanList, true)) {
    $tingkatanFilter = '';
}
$modeView = strtolower(trim((string) ($_GET['mode'] ?? 'ringkas')));
if (!in_array($modeView, ['ringkas', 'detail'], true)) {
    $modeView = 'ringkas';
}
$keaktifanView = strtolower(trim((string) ($_GET['keaktifan_view'] ?? 'kegiatan')));
if (!in_array($keaktifanView, ['kegiatan', 'santri'], true)) {
    $keaktifanView = 'kegiatan';
}
$pbDashView = strtolower(trim((string) ($_GET['view'] ?? 'home')));
if (!in_array($pbDashView, ['home', 'keaktivan'], true)) {
    $pbDashView = 'home';
}
if ($pbDashView === 'keaktivan' && !array_key_exists('keaktifan_view', $_GET)) {
    $keaktifanView = 'santri';
}
if ($isMunawibPortal && $pbDashView !== 'home' && $pbDashView !== 'keaktivan') {
    $pbDashView = 'home';
}
/** Scope tampilan detail (filter manual atau semua tingkatan asuhan). */
$tingkatanAktif = $tingkatanFilter !== '' ? [$tingkatanFilter] : $tingkatanAsuhan;

$tahun = (int) ($_GET['tahun'] ?? (int) date('Y'));
if ($tahun < 2000 || $tahun > 2100) {
    $tahun = (int) date('Y');
}

$today = date('Y-m-d');
$nowTime = date('H:i:s');
$hariKe = (int) date('N');

$isPbHomeRingkas = $isPbHomeRingkasEarly;
$pbDashHijriLabel = '';
$pbDashHijriClock = '';
$pbDashPasaran = '';
if (!$isPbKeaktivanOnly) {
    ensure_hijri_mappings_table($pdo);
    ensure_akademik_hijri_awal_bulan_table($pdo);
    $hijriBulanNama = [
        1 => 'Muharram', 2 => 'Safar', 3 => "Rabi' I", 4 => "Rabi' II", 5 => 'Jumadil Awal', 6 => 'Jumadil Akhir',
        7 => 'Rajab', 8 => "Sya'ban", 9 => 'Ramadan', 10 => 'Syawal', 11 => "Dzulqa'dah", 12 => 'Dzulhijah',
    ];
    $pbDashHijriLabel = akademik_hijri_badge_dashboard($pdo, $today, $hijriBulanNama);
    $pbDashHijriClock = akademik_hijri_label_h($pdo, $today, $hijriBulanNama);
    $pbDashPasaran = akademik_pasaran_tampilkan($pdo) ? akademik_pasaran_pada_tanggal($today, $pdo) : '';
}

require_once __DIR__ . '/../helpers/presensi_jadwal.php';
$pbAuditUserId = (int) ($_SESSION['user']['id'] ?? 1);

$statSantri = ['total' => 0, 'putra' => 0, 'putri' => 0];
$santriPerTingkatanMap = [];
$keaktivanRowsAsuhan = [];
$perTingkatan = [];
$kegiatanAktif = [];
$kegiatanAktifGrouped = [];
$tingkatanMengajar = [];
$modeMengajar = false;
$statIzinCount = 0;
$statPresensi = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'total' => 0];
$santriIzinList = [];
$keaktivanRows = [];
$kategoriRingkas = ['bagus' => 0, 'sedang' => 0, 'buruk' => 0, 'belum' => 0];
$rekapPerKegiatan = [];
$rekapKegiatanTotal = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'total' => 0];
$rosterHariIni = [];
$nilaiKelasHariIni = [];
$pbSudahHadir = false;
$tugasStats = ['total' => 0, 'published' => 0, 'draft' => 0, 'sesi_selesai' => 0];
$kegiatanMendekati = [];
$kegiatanAktifPresensi = [];
$pbDashTickerItems = [];
$santriMapPerTingkatan = [];
$pbSantriMapApiUrl = '';

if ($isPbKeaktivanOnly) {
    $tingkatanAktif = $tingkatanFilter !== '' ? [$tingkatanFilter] : $tingkatanAsuhan;
    $keaktivanScope = $tingkatanFilter !== '' ? $tingkatanAktif : $tingkatanAsuhan;
    $alpaHariIni = 0;
    foreach (pembimbing_dashboard_presensi_hari_ini_map($pdo, $tingkatanAktif, $today) as $row) {
        $alpaHariIni += (int) ($row['alpa'] ?? 0);
    }
    $statPresensi = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => $alpaHariIni, 'total' => 0];
    $useKeaktivanCache = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
        && !isset($_GET['refresh']);
    $keaktivanBundle = pembimbing_dashboard_keaktivan_bundle(
        $pdo,
        $keaktivanScope,
        $tahun,
        $userId,
        300,
        $useKeaktivanCache,
        $rekapJenis,
        $pembimbingId,
        $bolehSemua
    );
    $keaktivanRows = $keaktivanBundle['rows'];
    $kategoriRingkas = $keaktivanBundle['kategori'];
    $rekapPerKegiatan = $keaktivanBundle['rekap'];
    foreach ($rekapPerKegiatan as $rk) {
        $rekapKegiatanTotal['hadir'] += (int) ($rk['hadir'] ?? 0);
        $rekapKegiatanTotal['izin'] += (int) ($rk['izin'] ?? 0);
        $rekapKegiatanTotal['sakit'] += (int) ($rk['sakit'] ?? 0);
        $rekapKegiatanTotal['alpa'] += (int) ($rk['alpa'] ?? 0);
        $rekapKegiatanTotal['total'] += (int) ($rk['total'] ?? 0);
    }
} elseif (!$isPbHomeRingkas) {
    presensi_finalize_date_range($pdo, $today, $today, $pbAuditUserId > 0 ? $pbAuditUserId : 1);

    $statSantri = pembimbing_dashboard_jumlah_santri($pdo, $tingkatanAsuhan);
    $santriPerTingkatanMap = pembimbing_dashboard_jumlah_santri_map($pdo, $tingkatanAsuhan);
    $keaktivanRowsAsuhan = pembimbing_dashboard_keaktivan_santri($pdo, $tingkatanAsuhan, $tahun, 300);
    $perTingkatan = pembimbing_dashboard_per_tingkatan_stats($pdo, $tingkatanAsuhan, $today, $keaktivanRowsAsuhan);

    $kegiatanAktif = pembimbing_dashboard_kegiatan_aktif(
        $pdo,
        $tingkatanAsuhan,
        $hariKe,
        $nowTime,
        !$bolehSemua && $pembimbingId > 0 ? $pembimbingId : null
    );
    $kegiatanAktifGrouped = jadwal_kelompokkan_kegiatan_aktif($kegiatanAktif);
    $tingkatanMengajar = pembimbing_dashboard_tingkatan_dari_kegiatan_aktif($kegiatanAktif);
    $modeMengajar = !$bolehSemua && $tingkatanMengajar !== [] && $tingkatanFilter === '';
    if ($modeMengajar) {
        $tingkatanAktif = $tingkatanMengajar;
    }

    $statIzinCount = pembimbing_dashboard_jumlah_izin_hari_ini($pdo, $tingkatanAktif, $today);
    $statPresensi = pembimbing_dashboard_presensi_hari_ini($pdo, $tingkatanAktif, $today, false);
    $santriIzinList = pembimbing_dashboard_santri_izin_hari_ini($pdo, $tingkatanAktif, $today, 50);

    $tingkatanAktifKey = array_values(array_map(static fn (string $t): string => trim($t), $tingkatanAktif));
    $tingkatanAsuhanKey = array_values(array_map(static fn (string $t): string => trim($t), $tingkatanAsuhan));
    sort($tingkatanAktifKey);
    sort($tingkatanAsuhanKey);
    if ($tingkatanAktifKey === $tingkatanAsuhanKey) {
        $keaktivanRows = $keaktivanRowsAsuhan;
    } elseif ($keaktivanRowsAsuhan !== []) {
        $aktifFlip = array_fill_keys($tingkatanAktif, true);
        $keaktivanRows = array_values(array_filter(
            $keaktivanRowsAsuhan,
            static fn (array $r): bool => isset($aktifFlip[trim((string) ($r['tingkatan'] ?? ''))])
        ));
    } else {
        $keaktivanRows = pembimbing_dashboard_keaktivan_santri($pdo, $tingkatanAktif, $tahun, 300);
    }
    $kategoriRingkas = pembimbing_dashboard_ringkasan_kategori($keaktivanRows);
    $rekapPerKegiatan = pembimbing_dashboard_presensi_rekap_per_kegiatan($pdo, $tingkatanAsuhan, $tahun);
    foreach ($rekapPerKegiatan as $rk) {
        $rekapKegiatanTotal['hadir'] += (int) ($rk['hadir'] ?? 0);
        $rekapKegiatanTotal['izin'] += (int) ($rk['izin'] ?? 0);
        $rekapKegiatanTotal['sakit'] += (int) ($rk['sakit'] ?? 0);
        $rekapKegiatanTotal['alpa'] += (int) ($rk['alpa'] ?? 0);
        $rekapKegiatanTotal['total'] += (int) ($rk['total'] ?? 0);
    }

    $kegiatanIdsAktif = array_values(array_filter(array_map(static fn (array $k): int => (int) ($k['kegiatan_id'] ?? $k['id'] ?? 0), $kegiatanAktif)));
    $rosterHariIni = pembimbing_dashboard_roster_hari_ini($pdo, $tingkatanAktif, $today, $kegiatanIdsAktif);
    $nilaiKelasHariIni = pembimbing_dashboard_nilai_kelas_hari_ini($pdo, $tingkatanAktif, $today, $userId, $bolehSemua);
    $pbSudahHadir = $pembimbingId > 0 && pembimbing_dashboard_sudah_hadir_hari_ini($pdo, $pembimbingId, $today);
    $tugasStats = pembimbing_dashboard_tugas_stats($pdo, $userId, $bolehSemua);
    $kegiatanMendekati = pembimbing_dashboard_kegiatan_mendekati(
        $pdo,
        $tingkatanAsuhan,
        $hariKe,
        $nowTime,
        5,
        !$bolehSemua && $pembimbingId > 0 ? $pembimbingId : null
    );
    $kegiatanAktifPresensi = pembimbing_dashboard_presensi_kegiatan_berlangsung($pdo, $kegiatanAktifGrouped, $today);
    $pbDashTickerItems = pembimbing_dashboard_ticker_kegiatan($kegiatanAktifGrouped, $kegiatanMendekati, $nowTime, $kegiatanAktifPresensi);
    $santriMapPerTingkatan = pembimbing_dashboard_santri_list_map(
        $pdo,
        $tingkatanAsuhan,
        400,
        !$bolehSemua && $pembimbingId > 0 ? $pembimbingId : null
    );
} else {
    $statSantri = pembimbing_dashboard_jumlah_santri($pdo, $tingkatanAsuhan);
    $santriPerTingkatanMap = pembimbing_dashboard_jumlah_santri_map($pdo, $tingkatanAsuhan);
    $kegiatanAktif = pembimbing_dashboard_kegiatan_aktif(
        $pdo,
        $tingkatanAsuhan,
        $hariKe,
        $nowTime,
        !$bolehSemua && $pembimbingId > 0 ? $pembimbingId : null
    );
    $kegiatanAktifGrouped = jadwal_kelompokkan_kegiatan_aktif($kegiatanAktif);
    $kegiatanMendekati = pembimbing_dashboard_kegiatan_mendekati(
        $pdo,
        $tingkatanAsuhan,
        $hariKe,
        $nowTime,
        5,
        !$bolehSemua && $pembimbingId > 0 ? $pembimbingId : null
    );
    $kegiatanAktifPresensi = pembimbing_dashboard_presensi_kegiatan_berlangsung($pdo, $kegiatanAktifGrouped, $today);
    $pbDashTickerItems = pembimbing_dashboard_ticker_kegiatan($kegiatanAktifGrouped, $kegiatanMendekati, $nowTime, $kegiatanAktifPresensi);
    $pbSudahHadir = $pembimbingId > 0 && pembimbing_dashboard_sudah_hadir_hari_ini($pdo, $pembimbingId, $today);
    $pbSantriMapApiUrl = app_href('/api/pembimbing/santri_map.php');
}

// Kelompokkan baris keaktifan per tingkatan untuk tabel berkelompok di bawah.
$keaktivanByTingkatan = [];
foreach ($keaktivanRows as $kr) {
    $tk = trim((string) ($kr['tingkatan'] ?? '')) ?: '—';
    if (!isset($keaktivanByTingkatan[$tk])) {
        $keaktivanByTingkatan[$tk] = [];
    }
    $keaktivanByTingkatan[$tk][] = $kr;
}
ksort($keaktivanByTingkatan, SORT_NATURAL | SORT_FLAG_CASE);

$totalSantri = (int) $statSantri['total'];
$kehadiranPersen = $statPresensi['total'] > 0
    ? round($statPresensi['hadir'] / $statPresensi['total'] * 100, 1)
    : 0.0;

$pbKpiTrends = dashboard_pembimbing_kpi_trends($pdo, $today, $tingkatanAsuhan, $statPresensi);
$pbIdleEmpty = $kegiatanAktifGrouped === [] && ($kegiatanAktifPresensi ?? []) === [];
$pbIdleData = $pbIdleEmpty
    ? dashboard_idle_panel_data($pdo, $today, $nowTime, $tingkatanAsuhan !== [] ? $tingkatanAsuhan : null)
    : ['agenda' => [], 'presensi' => [], 'jadwal_berikutnya' => []];
$pbJamLabel = substr($nowTime, 0, 5);

$labelUser = $pembimbingNama !== '' ? $pembimbingNama : 'Pembimbing';
if ($isMunawibPortal && is_array($munawibPortalKonteks)) {
    $mwPb = trim((string) ($munawibPortalKonteks['pembimbing_nama'] ?? ''));
    if ($mwPb !== '') {
        $labelUser = $mwPb;
    }
}
$pbDashServerClockMs = (int) round(microtime(true) * 1000);
$jumlahTingkatanHome = count($tingkatanAsuhan);

$tingkatanBarisHome = array_map(
    static function (string $tk) use ($santriPerTingkatanMap, $perTingkatan): array {
        if ($perTingkatan !== []) {
            foreach ($perTingkatan as $row) {
                if ((string) ($row['tingkatan'] ?? '') === $tk) {
                    return $row;
                }
            }
        }

        return [
            'tingkatan' => $tk,
            'total' => (int) ($santriPerTingkatanMap[$tk]['total'] ?? 0),
        ];
    },
    $tingkatanAsuhan
);
$pageTitle = 'Dashboard Pembimbing';
$bodyClass = 'dash-page' . (!$bolehSemua && $pbDashView === 'home' ? ' pb-dash-bg-putih pb-dash-has-setoran-bottom pb-dash-home-mobile-fit' : '');
$loadPushFcm = !$isPbHomeRingkas && !$isPbKeaktivanOnly;
$pageStylesheets = [app_asset_href('/assets/css/pembimbing-dashboard.css')];
require_once __DIR__ . '/../includes/header.php';
$baseDashQuery = 'tahun=' . (int) $tahun . '&keaktifan_view=' . rawurlencode($keaktifanView);
if (!$bolehSemua && ($hasKajianJadwal || $hasPkppsJadwal)) {
    $baseDashQuery .= '&rekap_jenis=' . rawurlencode($rekapJenis);
}
if ($tingkatanFilter !== '') {
    $baseDashQuery .= '&tingkatan=' . rawurlencode($tingkatanFilter);
}
$keaktivanUrl = app_href('/pembimbing/dashboard.php?' . $baseDashQuery . '&view=keaktivan');
$homeUrl = app_href('/pembimbing/dashboard.php?' . $baseDashQuery);
?>

<div class="dash-page">

    <?php if (!$bolehSemua && $pbDashView === 'keaktivan'): ?>
        <?php require __DIR__ . '/partials/keaktivan_page.php'; ?>
    <?php elseif (!$bolehSemua && $pbDashView === 'home'): ?>
        <?php
        require_once __DIR__ . '/../helpers/pembimbing_portal_banner.php';
        $pbBannerVariant = pembimbing_portal_banner_resolve_variant(
            (bool) ($isMunawibPortal ?? false),
            $hasPkppsJadwal,
            $hasKajianJadwal,
            $rekapJenis
        );
        $pbBannerCfg = pembimbing_portal_banner_get($pdo, $pbBannerVariant);
        if (($pbBannerCfg['enabled'] ?? '1') !== '1') {
            $pbBannerCfg = pembimbing_portal_banner_defaults('default');
            $pbBannerVariant = 'default';
        }
        $pbDashShowSetoranBottom = true;
        $jumlahTingkatan = $jumlahTingkatanHome;
        $tingkatanBaris = $tingkatanBarisHome;
        $pbDashHasPkpps = $hasPkppsJadwal;
        $isMunawibPortal = $isMunawibPortal ?? false;
        $munawibPortalKonteks = $munawibPortalKonteks ?? null;
        $kegiatanAktifPresensi = $kegiatanAktifPresensi ?? [];
        require __DIR__ . '/partials/dashboard_home_top.php';
        ?>
    <?php else: ?>
    <div class="dash-hero-split pb-dash-admin-hero mb-4">
        <section class="dash-identity-card">
            <div class="dash-identity-card__greeting">
                <h1 class="h3 dash-hero-title mb-0"><?= htmlspecialchars($labelUser) ?></h1>
                <span class="dash-identity-card__role-value">
                    <i class="fa-solid <?= $pbSudahHadir ? 'fa-circle-check' : 'fa-clock' ?>" aria-hidden="true"></i>
                    <?= $pbSudahHadir ? 'Hadir' : 'Belum scan' ?>
                </span>
            </div>
            <?php
            $pbAdminNip = isset($pembimbingInfo) && is_array($pembimbingInfo)
                ? trim((string) ($pembimbingInfo['nip'] ?? ''))
                : '';
            if ($pbAdminNip !== ''):
            ?>
                <p class="pb-dash-admin-hero__nip mb-0">NIP <?= htmlspecialchars($pbAdminNip) ?></p>
            <?php endif; ?>
        </section>
        <section class="dash-clock-card" aria-live="polite">
            <div class="dash-hero-clock__top">
                <span class="dash-hero-clock__label"><i class="fa-regular fa-clock me-1"></i> Waktu berjalan server</span>
                <span class="dash-hero-clock__live">Live</span>
            </div>
            <div class="dash-hero-clock__time" id="dashboard-live-clock">--:--:--</div>
            <div class="dash-clock-card__tz">WIB</div>
            <div class="dash-hero-clock__date" id="dashboard-live-date"<?= $pbDashPasaran !== '' ? ' data-pasaran="' . htmlspecialchars($pbDashPasaran) . '"' : '' ?><?= ($pbDashHijriClock ?? '') !== '' ? ' data-hijri="' . htmlspecialchars((string) $pbDashHijriClock) . '"' : '' ?>>—</div>
        </section>
    </div>
    <?php endif; ?>
    <?php require __DIR__ . '/../includes/partials/dash_offline_status.php'; ?>

    <?php if ($bolehSemua): ?>
    <!-- Filter ringkas (tingkatan + tahun) -->
    <form method="get" class="card border-0 shadow-sm mb-4 dash-panel">
        <div class="card-body py-2 px-3">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-auto">
                    <label class="form-label small mb-0" for="pb-dash-tingkatan">Tingkatan</label>
                    <select id="pb-dash-tingkatan" name="tingkatan" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Semua tingkatan diasuh<?= $semuaTingkatanList !== [] ? ' (' . count($semuaTingkatanList) . ')' : '' ?></option>
                        <?php foreach ($semuaTingkatanList as $tk):
                            $jmlTk = (int) ($santriPerTingkatanMap[$tk]['total'] ?? 0);
                        ?>
                            <option value="<?= htmlspecialchars((string) $tk) ?>"<?= $tingkatanFilter === (string) $tk ? ' selected' : '' ?>>
                                <?= htmlspecialchars((string) $tk) ?> (<?= $jmlTk ?> santri)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-auto">
                    <label class="form-label small mb-0" for="pb-dash-tahun">Tahun keaktifan</label>
                    <input id="pb-dash-tahun" type="number" name="tahun" class="form-control form-control-sm"
                        min="2000" max="2100" value="<?= (int) $tahun ?>" style="width:6rem">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fa-solid fa-filter me-1"></i> Terapkan
                    </button>
                </div>
                <div class="col-auto ms-md-auto">
                    <?php if ($modeView === 'detail'): ?>
                        <a href="<?= htmlspecialchars(app_href('/pembimbing/dashboard.php?' . $baseDashQuery . '&mode=ringkas')) ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fa-solid fa-minimize me-1"></i> Mode ringkas
                        </a>
                    <?php else: ?>
                        <a href="<?= htmlspecialchars(app_href('/pembimbing/dashboard.php?' . $baseDashQuery . '&mode=detail')) ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fa-solid fa-maximize me-1"></i> Lihat detail
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </form>
    <?php endif; ?>

    <?php if ($semuaTingkatanList !== [] && $modeView === 'detail'): ?>
    <div class="card border-0 shadow-sm mb-4 dash-panel pb-tingkatan-block">
        <div class="card-body py-3 px-3">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                <div>
                    <h2 class="h6 mb-0 fw-bold">Tingkatan diasuh</h2>
                    <p class="small text-muted mb-0"><?= count($semuaTingkatanList) ?> kelas · <?= (int) $totalSantri ?> santri</p>
                </div>
                <?php if ($tingkatanFilter !== ''): ?>
                    <a href="<?= htmlspecialchars(app_href('/pembimbing/dashboard.php?tahun=' . (int) $tahun)) ?>"
                       class="btn btn-sm btn-link text-decoration-none p-0">Semua tingkatan</a>
                <?php endif; ?>
            </div>
            <div class="pb-tk-list" role="list">
                <?php
                $tingkatanBaris = $perTingkatan !== [] ? $perTingkatan : array_map(
                    static function (string $tk) use ($santriPerTingkatanMap): array {
                        return [
                            'tingkatan' => $tk,
                            'total' => (int) ($santriPerTingkatanMap[$tk]['total'] ?? 0),
                        ];
                    },
                    $semuaTingkatanList
                );
                foreach ($tingkatanBaris as $tk):
                    $tkName = (string) ($tk['tingkatan'] ?? '');
                    $tkTotal = (int) ($tk['total'] ?? 0);
                    $tkUrl = app_href('/pembimbing/dashboard.php?tingkatan=' . rawurlencode($tkName) . '&tahun=' . (int) $tahun);
                    $isActive = $tingkatanFilter !== '' && strcasecmp($tingkatanFilter, $tkName) === 0;
                ?>
                <a href="<?= htmlspecialchars($tkUrl) ?>"
                   class="pb-tk-row<?= $isActive ? ' is-active' : '' ?>"
                   role="listitem"
                   title="Filter ke <?= htmlspecialchars($tkName) ?>">
                    <span class="pb-tk-row__name"><?= htmlspecialchars($tkName) ?></span>
                    <span class="pb-tk-row__count"><?= $tkTotal ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($semuaTingkatanList === [] && ($bolehSemua || $pbDashView !== 'home')): ?>
        <div class="card border-0 shadow-sm mb-4 dash-panel">
            <div class="card-body text-center py-5">
                <div class="display-6 text-muted mb-2" aria-hidden="true">
                    <i class="fa-solid fa-chalkboard-user opacity-50"></i>
                </div>
                <h2 class="h5 mb-1"><?= $hasPkppsJadwal && $tingkatanMilik === [] ? 'Belum ada jadwal kajian' : 'Belum mendapat kelas / kajian' ?></h2>
                <p class="text-muted mb-2">
                    Akun pembimbing Anda<?php if ($pembimbingNama !== ''): ?> (<strong><?= htmlspecialchars($pembimbingNama) ?></strong>)<?php endif; ?>
                    <?php if ($hasPkppsJadwal): ?>
                        sudah terdaftar sebagai <strong>pembimbing PKPPS</strong>. Gunakan menu <em>Santri PKPPS</em> di atas untuk melihat santri.
                    <?php else: ?>
                        belum diset sebagai pembimbing pada jadwal kegiatan apa pun.
                    <?php endif; ?>
                </p>
                <?php if (!$hasPkppsJadwal): ?>
                <p class="small text-muted mb-3">
                    Hubungi pengurus bila kelas belum muncul, atau tambahkan sendiri lewat tombol di bawah.
                </p>
                <a href="<?= htmlspecialchars(app_href('/jadwal/index.php?panel=kegiatan')) ?>" class="btn btn-warning">
                    <i class="fa-solid fa-plus me-1"></i> Tambah kegiatan
                </a>
                <?php else: ?>
                <a href="<?= htmlspecialchars(app_href('/pembimbing/pkpps_santri.php')) ?>" class="btn btn-primary">
                    <i class="fa-solid fa-users me-1"></i> Lihat santri PKPPS
                </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($modeView === 'ringkas' && $semuaTingkatanList !== [] && $bolehSemua): ?>
    <div class="card border-0 shadow-sm mb-4 dash-panel pb-dash-unified">
        <div class="card-body p-3 p-md-4">
            <div class="row g-3 align-items-stretch pb-dash-unified-top">
                <div class="col-md-4 col-lg-3">
                    <div class="dash-kpi-box dash-kpi-box--putra h-100 text-center py-3 pb-dash-kpi-compact">
                        <div class="dash-kpi-box__label">Santri dibimbing</div>
                        <div class="dash-kpi-box__value display-6"><?= (int) $totalSantri ?></div>
                        <div class="dash-kpi-box__hint">
                            <?= count($tingkatanAsuhan) ?> tingkatan · Putra <?= (int) $statSantri['putra'] ?> · Putri <?= (int) $statSantri['putri'] ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-8 col-lg-9">
                    <div class="pb-dash-kegiatan-live h-100">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                            <h2 class="h6 mb-0 fw-bold"><i class="fa-solid fa-circle-play text-success me-1"></i> Kegiatan kelas berlangsung</h2>
                            <span class="badge text-bg-light border small font-monospace"><?= htmlspecialchars(app_format_jam(date('H:i:s'))) ?> WIB</span>
                        </div>
                        <?php if ($kegiatanAktifPresensi !== []): ?>
                            <?php $inBanner = false; require __DIR__ . '/partials/dashboard_kegiatan_berlangsung_cards.php'; ?>
                        <?php elseif ($kegiatanAktifGrouped === []): ?>
                            <?php
                            $idleContext = 'pembimbing';
                            $jamLabel = $pbJamLabel;
                            $idleData = $pbIdleData;
                            $canJadwalLink = false;
                            require __DIR__ . '/../includes/partials/dashboard_kegiatan_idle.php';
                            ?>
                        <?php else: ?>
                            <div class="d-flex flex-column gap-1">
                                <?php foreach ($kegiatanAktifGrouped as $namaKegiatan => $slotRows): ?>
                                    <div class="pb-dash-kegiatan-chip">
                                        <span class="fw-semibold small"><?= htmlspecialchars((string) $namaKegiatan) ?></span>
                                        <span class="text-muted small">
                                            <?= htmlspecialchars(substr((string) ($slotRows[0]['jam_mulai'] ?? ''), 0, 5)) ?>–<?= htmlspecialchars(substr((string) ($slotRows[0]['jam_selesai'] ?? ''), 0, 5)) ?>
                                        </span>
                                        <?php foreach ($slotRows as $kg): ?>
                                            <span class="badge text-bg-light border"><?= htmlspecialchars((string) ($kg['tingkatan'] ?? '—')) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <hr class="my-3 opacity-25">

            <?php if ($bolehSemua):
                $rekapPanelClass = '';
                $rekapFormMode = 'ringkas';
                require __DIR__ . '/partials/rekap_keaktivan_inline.php';
            endif; ?>
        </div>
    </div>

    <?php if ($rosterHariIni !== [] && $bolehSemua): ?>
    <div class="card border-0 shadow-sm mb-4 dash-panel">
        <div class="card-header bg-transparent border-0 pt-3 px-3 pb-0">
            <h2 class="h6 mb-1 fw-bold">Daftar santri scan hari ini</h2>
            <p class="small text-muted mb-0">Status presensi kegiatan aktif · <?= htmlspecialchars(implode(', ', $tingkatanAktif)) ?></p>
        </div>
        <div class="card-body px-0 pb-3 pt-2">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr><th class="ps-3">Santri</th><th>Tingkatan</th><th>Status</th><th class="pe-3">Jam</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rosterHariIni as $rs):
                        $st = strtoupper((string) ($rs['status_hari_ini'] ?? ''));
                        $stLabel = match ($st) {
                            'HADIR' => 'Hadir',
                            'IZIN' => 'Izin',
                            'SAKIT' => 'Sakit',
                            'ALPA' => 'Alpa',
                            default => '—',
                        };
                        $badge = match ($st) {
                            'HADIR' => 'success',
                            'IZIN', 'SAKIT' => 'warning',
                            'ALPA' => 'danger',
                            default => 'secondary',
                        };
                    ?>
                        <tr>
                            <td class="ps-3"><div class="fw-semibold small"><?= htmlspecialchars((string) $rs['nama_santri']) ?></div><div class="text-muted font-monospace" style="font-size:.7rem"><?= htmlspecialchars((string) $rs['nis']) ?></div></td>
                            <td class="small"><?= htmlspecialchars((string) $rs['tingkatan']) ?></td>
                            <td><span class="badge text-bg-<?= $badge ?>-subtle text-<?= $badge ?> border border-<?= $badge ?>-subtle"><?= htmlspecialchars($stLabel) ?></span></td>
                            <td class="pe-3 small font-monospace"><?= !empty($rs['jam_presensi']) ? htmlspecialchars(substr((string) $rs['jam_presensi'], 0, 5)) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($bolehSemua || $pbDashView === 'home'): ?>
    <div class="text-end mb-4">
        <a href="<?= htmlspecialchars(app_href('/pembimbing/dashboard.php?' . $baseDashQuery . '&mode=detail')) ?>" class="btn btn-sm btn-outline-secondary">
            <i class="fa-solid fa-table-list me-1"></i> Lihat dashboard detail
        </a>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($modeView === 'detail'): ?>
    <!-- KPI detail (hanya mode detail) -->
    <div class="dash-kpi-grid mb-4" role="list" aria-label="Ringkasan tingkatan saya">
        <div class="dash-kpi-grid__item" role="listitem">
            <div class="dash-kpi-box dash-kpi-box--putra h-100">
                <div class="dash-kpi-box__icon" aria-hidden="true"><i class="fa-solid fa-user-group"></i></div>
                <div class="dash-kpi-box__label">Total santri diasuh</div>
                <div class="dash-kpi-box__value"><?= (int) $totalSantri ?></div>
                <?php $dashKpiTrend = $pbKpiTrends['santri'] ?? null; require __DIR__ . '/../includes/partials/dashboard_kpi_trend.php'; ?>
                <div class="dash-kpi-box__hint">
                    Putra <?= (int) $statSantri['putra'] ?> · Putri <?= (int) $statSantri['putri'] ?>
                    <?php if ($tingkatanAsuhan !== []): ?>
                        <span class="d-block mt-1"><?= count($tingkatanAsuhan) ?> tingkatan diasuh</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="dash-kpi-grid__item" role="listitem">
            <div class="dash-kpi-box dash-kpi-box--izin h-100">
                <div class="dash-kpi-box__icon" aria-hidden="true"><i class="fa-solid fa-person-walking-luggage"></i></div>
                <div class="dash-kpi-box__label">Sedang izin</div>
                <div class="dash-kpi-box__value"><?= (int) $statIzinCount ?></div>
                <?php $dashKpiTrend = $pbKpiTrends['izin'] ?? null; require __DIR__ . '/../includes/partials/dashboard_kpi_trend.php'; ?>
                <div class="dash-kpi-box__hint">Hari ini <?= htmlspecialchars(date('d M Y', strtotime($today))) ?></div>
            </div>
        </div>
        <div class="dash-kpi-grid__item" role="listitem">
            <div class="dash-kpi-box dash-kpi-box--putri h-100">
                <div class="dash-kpi-box__icon" aria-hidden="true"><i class="fa-solid fa-circle-check"></i></div>
                <div class="dash-kpi-box__label">Hadir hari ini</div>
                <div class="dash-kpi-box__value"><?= (int) $statPresensi['hadir'] ?></div>
                <?php $dashKpiTrend = $pbKpiTrends['hadir'] ?? null; require __DIR__ . '/../includes/partials/dashboard_kpi_trend.php'; ?>
                <div class="dash-kpi-box__hint">
                    dari <?= (int) $statPresensi['total'] ?> presensi
                    <?php if ($statPresensi['total'] > 0): ?>· <?= number_format($kehadiranPersen, 1, ',', '.') ?>%<?php endif; ?>
                </div>
            </div>
        </div>
        <div class="dash-kpi-grid__item" role="listitem">
            <div class="dash-kpi-box dash-kpi-box--mukimin h-100">
                <div class="dash-kpi-box__icon" aria-hidden="true"><i class="fa-solid fa-list-check"></i></div>
                <div class="dash-kpi-box__label">Tugas Ikhtibar</div>
                <div class="dash-kpi-box__value"><?= (int) $tugasStats['total'] ?></div>
                <div class="dash-kpi-box__hint">
                    Publish <?= (int) $tugasStats['published'] ?> · Draf <?= (int) $tugasStats['draft'] ?>
                </div>
            </div>
        </div>
    </div>

    <div class="pb-keaktifan-kpi mb-4" role="list" aria-label="Kategori keaktifan tahun ini">
        <div class="pb-keaktifan-kpi__card pb-keaktifan-kpi__card--bagus" role="listitem">
            <div class="pb-keaktifan-kpi__label">Keaktifan bagus</div>
            <div class="pb-keaktifan-kpi__value"><?= (int) $kategoriRingkas['bagus'] ?></div>
        </div>
        <div class="pb-keaktifan-kpi__card pb-keaktifan-kpi__card--sedang" role="listitem">
            <div class="pb-keaktifan-kpi__label">Sedang</div>
            <div class="pb-keaktifan-kpi__value"><?= (int) $kategoriRingkas['sedang'] ?></div>
        </div>
        <div class="pb-keaktifan-kpi__card pb-keaktifan-kpi__card--buruk" role="listitem">
            <div class="pb-keaktifan-kpi__label">Buruk</div>
            <div class="pb-keaktifan-kpi__value"><?= (int) $kategoriRingkas['buruk'] ?></div>
        </div>
        <div class="pb-keaktifan-kpi__card pb-keaktifan-kpi__card--alpa" role="listitem">
            <div class="pb-keaktifan-kpi__label">Alpa hari ini</div>
            <div class="pb-keaktifan-kpi__value"><?= (int) $statPresensi['alpa'] ?></div>
        </div>
    </div>

    <!-- Kegiatan berlangsung (detail) -->
    <div class="dash-layout-grid mb-4">
        <section class="dash-layout-main">
            <div class="card border-0 shadow-sm h-100 dash-panel dash-panel--lift">
                <div class="card-header bg-transparent border-0 d-flex flex-wrap justify-content-between align-items-start gap-2 pt-4 px-4 pb-0">
                    <div>
                        <h2 class="h5 mb-1">Kegiatan berlangsung</h2>
                        <p class="small text-muted mb-0">Jadwal slot waktu sekarang · tingkatan Anda</p>
                    </div>
                </div>
                <div class="card-body px-4 pb-4 pt-3">
                    <?php if ($kegiatanAktifGrouped === []): ?>
                        <?php
                        $idleContext = 'pembimbing';
                        $jamLabel = $pbJamLabel;
                        $idleData = $pbIdleData;
                        $canJadwalLink = false;
                        require __DIR__ . '/../includes/partials/dashboard_kegiatan_idle.php';
                        ?>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-2">
                            <?php foreach ($kegiatanAktifGrouped as $namaKegiatan => $slotRows): ?>
                                <div class="dash-jadwal-row dash-jadwal-row--compact">
                                    <div class="dash-jadwal-row-main">
                                        <span class="dash-jadwal-nama"><?= htmlspecialchars((string) $namaKegiatan) ?></span>
                                        <span class="dash-jadwal-time">
                                            <i class="fa-regular fa-clock me-1 opacity-75"></i>
                                            <?= htmlspecialchars(substr((string) ($slotRows[0]['jam_mulai'] ?? ''), 0, 5)) ?>–<?= htmlspecialchars(substr((string) ($slotRows[0]['jam_selesai'] ?? ''), 0, 5)) ?>
                                        </span>
                                    </div>
                                    <div class="dash-jadwal-tingkatan-wrap">
                                        <?php foreach ($slotRows as $kg): ?>
                                            <span class="badge text-bg-light border text-dark jadwal-tingkatan-badge"><?= htmlspecialchars((string) ($kg['tingkatan'] ?? '—')) ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php
                                    $tempatList = array_values(array_unique(array_filter(array_map(
                                        static fn(array $r): string => trim((string) ($r['tempat'] ?? '')),
                                        $slotRows
                                    ))));
                                    ?>
                                    <?php if ($tempatList !== []): ?>
                                        <div class="dash-jadwal-meta dash-jadwal-tempat small">
                                            <i class="fa-solid fa-location-dot me-1"></i><?= htmlspecialchars(implode(' · ', $tempatList)) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    </div>
    <?php endif; ?>

    <?php if ($modeView === 'detail' && $rosterHariIni !== []): ?>
    <div class="card border-0 shadow-sm mb-4 dash-panel">
        <div class="card-header bg-transparent border-0 pt-3 px-4 pb-0">
            <h2 class="h5 mb-1">Daftar santri kelas<?= $modeMengajar ? ' (sedang mengajar)' : '' ?> — hari ini</h2>
            <p class="small text-muted mb-0">Status presensi kegiatan aktif · hanya tingkatan <?= htmlspecialchars(implode(', ', $tingkatanAktif)) ?></p>
        </div>
        <div class="card-body px-4 pb-4 pt-2">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Santri</th><th>Tingkatan</th><th>Status</th><th>Jam</th></tr></thead>
                    <tbody>
                    <?php foreach ($rosterHariIni as $rs):
                        $st = strtoupper((string) ($rs['status_hari_ini'] ?? ''));
                        $stLabel = match ($st) {
                            'HADIR' => 'Hadir',
                            'IZIN' => 'Izin',
                            'SAKIT' => 'Sakit',
                            'ALPA' => 'Alpa',
                            default => '—',
                        };
                        $badge = match ($st) {
                            'HADIR' => 'success',
                            'IZIN', 'SAKIT' => 'warning',
                            'ALPA' => 'danger',
                            default => 'secondary',
                        };
                    ?>
                        <tr>
                            <td><div class="fw-semibold small"><?= htmlspecialchars((string) $rs['nama_santri']) ?></div><div class="text-muted font-monospace" style="font-size:.7rem"><?= htmlspecialchars((string) $rs['nis']) ?></div></td>
                            <td class="small"><?= htmlspecialchars((string) $rs['tingkatan']) ?></td>
                            <td><span class="badge text-bg-<?= $badge ?>-subtle text-<?= $badge ?> border border-<?= $badge ?>-subtle"><?= htmlspecialchars($stLabel) ?></span></td>
                            <td class="small font-monospace"><?= !empty($rs['jam_presensi']) ? htmlspecialchars(substr((string) $rs['jam_presensi'], 0, 5)) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($modeView === 'detail' && $nilaiKelasHariIni !== []): ?>
    <div class="card border-0 shadow-sm mb-4 dash-panel">
        <div class="card-header bg-transparent border-0 pt-3 px-4 pb-0">
            <h2 class="h5 mb-1">Nilai tugas hari ini (kelas Anda)</h2>
        </div>
        <div class="card-body px-4 pb-4 pt-2">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Santri</th><th>Tugas</th><th>Nilai</th><th>Waktu</th></tr></thead>
                    <tbody>
                    <?php foreach ($nilaiKelasHariIni as $nk): ?>
                        <tr>
                            <td class="small"><?= htmlspecialchars((string) $nk['nama_santri']) ?> <span class="text-muted">(<?= htmlspecialchars((string) $nk['tingkatan']) ?>)</span></td>
                            <td class="small"><?= htmlspecialchars((string) ($nk['tugas_judul'] ?? '')) ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars((string) ($nk['nilai'] ?? '—')) ?></td>
                            <td class="small text-muted"><?= !empty($nk['submitted_at']) ? htmlspecialchars(substr((string) $nk['submitted_at'], 11, 5)) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tabel santri sedang izin -->
    <?php if ($modeView === 'detail' && $santriIzinList !== []): ?>
        <div class="card border-0 shadow-sm mb-4 dash-panel dash-panel--lift">
            <div class="card-header bg-transparent border-0 d-flex flex-wrap justify-content-between align-items-center gap-2 pt-4 px-4 pb-0">
                <div>
                    <h2 class="h5 mb-1">Santri sedang izin</h2>
                    <p class="small text-muted mb-0">Tingkatan Anda · <?= htmlspecialchars(date('d M Y', strtotime($today))) ?></p>
                </div>
                <span class="badge text-bg-light border"><?= (int) $statIzinCount ?> santri</span>
            </div>
            <div class="card-body px-4 pb-4 pt-2">
                <div class="table-responsive rounded-3 border">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Nama</th>
                                <th>NIS</th>
                                <th>Tingkatan</th>
                                <th>Jenis</th>
                                <th class="pe-3 text-nowrap">Periode</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($santriIzinList as $iz): ?>
                                <tr>
                                    <td class="ps-3 fw-semibold"><?= htmlspecialchars((string) ($iz['nama_santri'] ?? '')) ?></td>
                                    <td class="small text-muted"><?= htmlspecialchars((string) ($iz['nis'] ?? '')) ?></td>
                                    <td class="small"><?= htmlspecialchars((string) ($iz['tingkatan'] ?? '—')) ?></td>
                                    <td>
                                        <?php $jen = (string) ($iz['jenis_izin'] ?? 'KELUAR'); ?>
                                        <span class="badge text-bg-light border">
                                            <?= htmlspecialchars(jenis_izin_label($jen)) ?>
                                        </span>
                                    </td>
                                    <td class="pe-3 small text-nowrap">
                                        <?= htmlspecialchars(date('d M', strtotime((string) ($iz['tanggal_mulai'] ?? '')))) ?>
                                        – <?= htmlspecialchars(date('d M', strtotime((string) ($iz['tanggal_selesai'] ?? '')))) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Tabel keaktifan santri (grouped per tingkatan) -->
    <?php if ($modeView === 'detail' && $keaktivanByTingkatan !== []): ?>
        <div class="card border-0 shadow-sm dash-panel dash-panel--lift">
            <div class="card-header bg-transparent border-0 d-flex flex-wrap justify-content-between align-items-center gap-2 pt-4 px-4 pb-0">
                <div>
                    <h2 class="h5 mb-1"><i class="fa-solid fa-chart-line text-success me-1"></i> Keaktifan santri tahun <?= (int) $tahun ?></h2>
                    <p class="small text-muted mb-0">Dikelompokkan per tingkatan. Persentase dari total presensi tahun ini.</p>
                </div>
                <div class="small text-muted">
                    Sumber:
                    <span class="badge text-bg-light border">presensi otomatis</span>
                    <span class="badge text-bg-info-subtle text-info border">nilai pengasuh</span>
                </div>
            </div>
            <div class="card-body px-0 pb-4 pt-2">
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 pb-keaktifan-table">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3" style="min-width:3rem">#</th>
                                <th>Nama santri</th>
                                <th>NIS</th>
                                <th class="text-center">Hadir</th>
                                <th class="text-center">Izin</th>
                                <th class="text-center">Sakit</th>
                                <th class="text-center">Alpa</th>
                                <th class="text-center">%</th>
                                <th class="pe-3">Keaktifan</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($keaktivanByTingkatan as $tkLabel => $rowsTk):
                                $countTk = count($rowsTk);
                            ?>
                                <tr class="pb-keaktifan-group-row table-active">
                                    <td colspan="9" class="ps-3">
                                        <span class="fw-semibold">
                                            <i class="fa-solid fa-graduation-cap text-primary me-1"></i>
                                            <?= htmlspecialchars((string) $tkLabel) ?>
                                        </span>
                                        <span class="badge text-bg-primary-subtle text-primary border ms-2">
                                            <?= (int) $countTk ?> santri
                                        </span>
                                    </td>
                                </tr>
                                <?php $no = 0; foreach ($rowsTk as $r):
                                    $no++;
                                    $kat = strtoupper((string) ($r['kategori'] ?? ''));
                                    $badge = match (true) {
                                        $kat === 'BAIK' || $kat === 'BAGUS' => 'success',
                                        $kat === 'SEDANG' => 'warning',
                                        $kat === 'BURUK' || $kat === 'JELEK' => 'danger',
                                        default => 'secondary',
                                    };
                                    $sumberBadge = ($r['sumber'] ?? '') === 'pengasuh' ? 'info' : '';
                                    $santriDetailUrl = pembimbing_dashboard_keaktifan_santri_url((int) ($r['santri_id'] ?? 0), (int) $tahun, $rekapJenis);
                                ?>
                                    <tr>
                                        <td class="ps-3 small text-muted"><?= $no ?></td>
                                        <td class="fw-semibold">
                                            <a href="<?= htmlspecialchars($santriDetailUrl) ?>" class="text-decoration-none"><?= htmlspecialchars((string) $r['nama_santri']) ?></a>
                                        </td>
                                        <td class="small text-muted"><?= htmlspecialchars((string) $r['nis']) ?></td>
                                        <td class="text-center text-success small"><?= (int) $r['hadir'] ?></td>
                                        <td class="text-center text-warning small"><?= (int) $r['izin'] ?></td>
                                        <td class="text-center text-info small"><?= (int) $r['sakit'] ?></td>
                                        <td class="text-center text-danger small"><?= (int) $r['alpa'] ?></td>
                                        <td class="text-center small">
                                            <?= $r['total'] > 0 ? number_format((float) $r['persen_hadir'], 1, ',', '.') . '%' : '—' ?>
                                        </td>
                                        <td class="pe-3">
                                            <span class="badge text-bg-<?= $badge ?>"><?= htmlspecialchars((string) $r['label']) ?></span>
                                            <?php if ($sumberBadge !== ''): ?>
                                                <span class="badge text-bg-<?= $sumberBadge ?>-subtle text-<?= $sumberBadge ?> border small ms-1">
                                                    <i class="fa-solid fa-user-tie me-1"></i>pengasuh
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer bg-transparent border-0 small text-muted px-4">
                Kategori otomatis: <strong>Bagus</strong> jika tanpa alpa,
                <strong>Sedang</strong> jika alpa ≤ ambang sedang, <strong>Jelek</strong> bila melebihi (sesuai pengaturan poin).
                Pengasuh dapat menimpa nilai via menu <em>Nilai Keaktifan Santri</em>.
            </div>
        </div>
    <?php endif; ?>

</div>

<?php if (!empty($pbDashShowSetoranBottom)): ?>
    <?php require __DIR__ . '/partials/dashboard_setoran_scan_bottom.php'; ?>
<?php endif; ?>

<script>window.PONDOK_SERVER_CLOCK_MS = <?= (int) $pbDashServerClockMs ?>;</script>
<script>
(function () {
    var mapEl = document.getElementById('pb-santri-map-json');
    var santriPanel = document.getElementById('pb-santri-panel');
    var santriList = document.getElementById('pb-santri-panel-list');
    var santriTitle = document.getElementById('pb-santri-panel-title');
    var tkPick = document.getElementById('pb-tk-pick');
    var closeBtn = document.getElementById('pb-santri-panel-close');
    var lihatBtn = document.querySelector('.js-pb-lihat-santri');
    if (!mapEl || !santriPanel || !santriList || !lihatBtn) return;

    var santriMap = {};
    try { santriMap = JSON.parse(mapEl.textContent || '{}'); } catch (e) { santriMap = {}; }
    var mapLoadPromise = null;
    var mapApiUrl = '';
    var cfgEl = document.getElementById('pb-santri-map-config');
    if (cfgEl) {
        try {
            var cfg = JSON.parse(cfgEl.textContent || '{}');
            mapApiUrl = cfg.api || '';
        } catch (e) { /* abaikan */ }
    }
    function ensureMapLoaded() {
        if (!mapApiUrl) {
            return Promise.resolve(santriMap);
        }
        if (Object.keys(santriMap).length > 0) {
            return Promise.resolve(santriMap);
        }
        if (!mapLoadPromise) {
            mapLoadPromise = fetch(mapApiUrl, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    if (data && data.ok && data.map) {
                        santriMap = data.map;
                    }
                    mapApiUrl = '';
                    return santriMap;
                })
                .catch(function () {
                    mapApiUrl = '';
                    return santriMap;
                });
        }
        return mapLoadPromise;
    }
    function refreshMultiTk() {
        return Object.keys(santriMap).length > 1;
    }
    var multiTk = refreshMultiTk();

    function renderList(tk) {
        var rows = santriMap[tk] || [];
        santriTitle.textContent = 'Santri · ' + tk + ' (' + rows.length + ')';
        santriList.innerHTML = '';
        if (rows.length === 0) {
            santriList.innerHTML = '<li class="pb-dash-santri-panel__empty">Belum ada santri.</li>';
            return;
        }
        rows.forEach(function (r) {
            var li = document.createElement('li');
            li.className = 'pb-dash-santri-panel__item';
            li.innerHTML = '<span class="pb-dash-santri-panel__name"></span><span class="pb-dash-santri-panel__meta"></span>';
            li.querySelector('.pb-dash-santri-panel__name').textContent = r.nama_santri || '—';
            li.querySelector('.pb-dash-santri-panel__meta').textContent = (r.nis || '') + (r.tingkatan ? ' · ' + r.tingkatan : '');
            santriList.appendChild(li);
        });
    }

    function openPanel(tk) {
        if (multiTk && tkPick) {
            tkPick.classList.remove('d-none');
            tkPick.hidden = false;
        }
        renderList(tk);
        santriPanel.classList.remove('d-none');
        santriPanel.hidden = false;
        lihatBtn.setAttribute('aria-expanded', 'true');
        lihatBtn.classList.add('is-active');
        document.querySelectorAll('.js-pb-pick-tingkatan').forEach(function (b) {
            b.classList.toggle('is-active', b.getAttribute('data-tingkatan') === tk);
        });
    }

    function closePanel() {
        santriPanel.classList.add('d-none');
        santriPanel.hidden = true;
        lihatBtn.setAttribute('aria-expanded', 'false');
        lihatBtn.classList.remove('is-active');
        if (tkPick) {
            tkPick.classList.add('d-none');
            tkPick.hidden = true;
        }
        document.querySelectorAll('.js-pb-pick-tingkatan').forEach(function (b) { b.classList.remove('is-active'); });
    }

    lihatBtn.addEventListener('click', function () {
        if (!santriPanel.classList.contains('d-none') && !santriPanel.hidden) {
            closePanel();
            return;
        }
        if (mapApiUrl || (mapLoadPromise && Object.keys(santriMap).length === 0)) {
            santriTitle.textContent = 'Memuat daftar santri…';
            santriList.innerHTML = '<li class="pb-dash-santri-panel__empty">Memuat…</li>';
            santriPanel.classList.remove('d-none');
            santriPanel.hidden = false;
            lihatBtn.setAttribute('aria-expanded', 'true');
            lihatBtn.classList.add('is-active');
            ensureMapLoaded().then(function () {
                multiTk = refreshMultiTk();
                closePanel();
                lihatBtn.click();
            });
            return;
        }
        var keys = Object.keys(santriMap);
        if (keys.length === 0) {
            santriTitle.textContent = 'Belum ada santri';
            santriList.innerHTML = '<li class="pb-dash-santri-panel__empty">Belum ada santri dibimbing.</li>';
            santriPanel.classList.remove('d-none');
            santriPanel.hidden = false;
            lihatBtn.setAttribute('aria-expanded', 'true');
            lihatBtn.classList.add('is-active');
            return;
        }
        if (keys.length === 1) {
            openPanel(keys[0]);
            return;
        }
        if (tkPick) {
            tkPick.classList.remove('d-none');
            tkPick.hidden = false;
            santriPanel.classList.add('d-none');
            santriPanel.hidden = true;
            santriTitle.textContent = 'Pilih tingkatan di bawah';
            lihatBtn.setAttribute('aria-expanded', 'true');
            lihatBtn.classList.add('is-active');
        }
    });

    document.querySelectorAll('.js-pb-pick-tingkatan').forEach(function (btnTk) {
        btnTk.addEventListener('click', function () {
            openPanel(btnTk.getAttribute('data-tingkatan') || '');
        });
    });

    if (closeBtn) closeBtn.addEventListener('click', closePanel);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
