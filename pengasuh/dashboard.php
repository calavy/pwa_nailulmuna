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
require_once __DIR__ . '/../helpers/pembimbing_dashboard.php';
require_once __DIR__ . '/../helpers/perizinan_approval.php';
require_once __DIR__ . '/../helpers/dashboard_insights.php';

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
$liburTampil = akademik_libur_presensi_tampilan($pdo, $today);

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

$izinPengasuhAntrian = perizinan_pengasuh_antrian($pdo, 8);
$izinPengasuhPendingCount = (int) ($izinPengasuhAntrian['total'] ?? 0);
$izinPengasuhIndividu = $izinPengasuhAntrian['individu'] ?? [];
$izinPengasuhRombongan = $izinPengasuhAntrian['rombongan'] ?? [];
$izinDashAlpaRows = $izinPengasuhIndividu;
foreach ($izinPengasuhRombongan as $rmAlpa) {
    foreach ($rmAlpa['anggota_rows'] ?? [] as $arAlpa) {
        $izinDashAlpaRows[] = $arAlpa;
    }
}
$izinDashAlpaMap = perizinan_alpa_map_for_rows($pdo, $izinDashAlpaRows);
$izinAksiHref = app_href('/pengasuh/izin_aksi.php');

$pageTitle = 'Dashboard Pengasuh';
$bodyClass = 'dash-page page-pengasuh-dashboard kh-wrap';
$pageStylesheets = [
    app_asset_href('/assets/css/keaktifan-hari.css'),
    app_asset_href('/assets/css/pengasuh-dashboard.css'),
];
$pageScripts = [app_asset_href('/assets/js/pengasuh-izin-setujui.js')];
$loadPushFcm = true;
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

    <div class="mb-4" id="pg-dash-izin">
        <div class="card border-warning shadow-sm dash-panel pg-dash-izin-panel<?= $izinPengasuhPendingCount > 0 ? '' : ' border-opacity-50' ?>">
            <div class="card-body">
                <div id="pg-dash-izin-flash" class="d-none"></div>
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                    <div>
                        <h2 class="h6 fw-bold mb-1 text-warning">
                            <i class="fa-solid fa-file-signature me-1"></i>
                            Persetujuan izin
                            <?php if ($izinPengasuhPendingCount > 0): ?>
                                <span class="badge text-bg-warning ms-1"><?= (int) $izinPengasuhPendingCount ?></span>
                            <?php endif; ?>
                        </h2>
                        <p class="small text-muted mb-0">
                            <?php if ($izinPengasuhPendingCount > 0): ?>
                                <strong><?= (int) $izinPengasuhPendingCount ?></strong> permohonan menunggu persetujuan pengasuh.
                            <?php else: ?>
                                Tidak ada permohonan izin yang menunggu saat ini.
                            <?php endif; ?>
                        </p>
                    </div>
                    <a href="<?= htmlspecialchars(app_href('/pengasuh/perizinan.php')) ?>" class="btn btn-sm btn-warning">
                        <i class="fa-solid fa-check-double me-1"></i> Buka semua
                    </a>
                </div>

                <?php if ($izinPengasuhRombongan !== []): ?>
                <div class="pg-dash-izin-list mb-3">
                    <div class="small fw-semibold text-warning mb-2">Izin rombongan</div>
                    <?php foreach ($izinPengasuhRombongan as $rm):
                        $rmRingkas = perizinan_alpa_pilih_rombongan($izinDashAlpaMap, $rm['izin_ids'] ?? []);
                        $alpaCek = $rmRingkas['cek'];
                        $blokirR = (int) $rmRingkas['blokir'];
                        $rmJudul = 'Rombongan #' . (int) $rm['id'] . ' · ' . (int) ($rm['jumlah'] ?? 0) . ' santri';
                        $rmTanggal = app_format_izin_rentang(
                            (string) ($rm['tanggal_mulai'] ?? ''),
                            (string) ($rm['tanggal_selesai'] ?? ''),
                            substr((string) ($rm['jam_mulai'] ?? ''), 0, 5),
                            substr((string) ($rm['jam_selesai'] ?? ''), 0, 5)
                        );
                        $rmNote = $blokirR > 0
                            ? ($blokirR . ' dari ' . (int) ($rm['jumlah'] ?? 0) . ' santri terhalang ALPA')
                            : '';
                        ?>
                        <article class="pg-dash-izin-card pg-dash-izin-card--rombongan">
                            <div class="pg-dash-izin-card__body">
                                <div class="fw-semibold"><?= htmlspecialchars($rmJudul) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars($rmTanggal) ?></div>
                            </div>
                            <div class="pg-dash-izin-card__actions">
                                <form method="post" action="<?= htmlspecialchars($izinAksiHref) ?>" class="pg-izin-setujui-form"
                                    data-judul="<?= htmlspecialchars($rmJudul) ?>"
                                    data-tanggal="<?= htmlspecialchars($rmTanggal) ?>"
                                    <?= perizinan_alpa_html_data_attrs($alpaCek, $rmNote !== '' ? ['data-alpa-rombongan-note' => $rmNote] : []) ?>>
                                    <input type="hidden" name="action" value="setujui_rombongan_pengasuh">
                                    <input type="hidden" name="rombongan_id" value="<?= (int) $rm['id'] ?>">
                                    <button type="submit" class="btn btn-success btn-sm pg-dash-izin-btn">Setujui</button>
                                </form>
                                <form method="post" action="<?= htmlspecialchars($izinAksiHref) ?>" class="pg-izin-tolak-form" data-confirm="Tolak izin rombongan ini?">
                                    <input type="hidden" name="action" value="tolak_rombongan_pengasuh">
                                    <input type="hidden" name="rombongan_id" value="<?= (int) $rm['id'] ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm pg-dash-izin-btn">Tolak</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($izinPengasuhIndividu !== []): ?>
                <div class="pg-dash-izin-list">
                    <?php foreach ($izinPengasuhIndividu as $ip):
                        $izinIdRow = (int) ($ip['id'] ?? 0);
                        $alpaCek = $izinDashAlpaMap[$izinIdRow] ?? ['subject' => false, 'allowed' => true];
                        $ipNama = (string) ($ip['nama_santri'] ?? '');
                        $ipTanggal = app_format_izin_rentang(
                            (string) ($ip['tanggal_mulai'] ?? ''),
                            (string) ($ip['tanggal_selesai'] ?? ''),
                            substr((string) ($ip['jam_mulai'] ?? ''), 0, 5),
                            substr((string) ($ip['jam_selesai'] ?? ''), 0, 5)
                        );
                        ?>
                        <article class="pg-dash-izin-card">
                            <div class="pg-dash-izin-card__body">
                                <div class="fw-semibold"><?= htmlspecialchars($ipNama) ?></div>
                                <div class="small text-muted"><?= htmlspecialchars($ipTanggal) ?></div>
                            </div>
                            <div class="pg-dash-izin-card__actions">
                                <form method="post" action="<?= htmlspecialchars($izinAksiHref) ?>" class="pg-izin-setujui-form"
                                    data-judul="<?= htmlspecialchars($ipNama) ?>"
                                    data-tanggal="<?= htmlspecialchars($ipTanggal) ?>"
                                    <?= perizinan_alpa_html_data_attrs($alpaCek) ?>>
                                    <input type="hidden" name="action" value="setujui_pengasuh">
                                    <input type="hidden" name="izin_id" value="<?= $izinIdRow ?>">
                                    <button type="submit" class="btn btn-success btn-sm pg-dash-izin-btn">Setujui</button>
                                </form>
                                <form method="post" action="<?= htmlspecialchars($izinAksiHref) ?>" class="pg-izin-tolak-form" data-confirm="Tolak permohonan izin ini?">
                                    <input type="hidden" name="action" value="tolak_pengasuh">
                                    <input type="hidden" name="izin_id" value="<?= $izinIdRow ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm pg-dash-izin-btn">Tolak</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <?php elseif ($izinPengasuhPendingCount === 0): ?>
                    <div class="small text-muted text-center py-2">Permohonan izin yang menunggu akan muncul di sini.</div>
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
<?php require __DIR__ . '/../includes/partials/pengasuh_izin_setujui_modal.php'; ?>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/keaktifan-hari.js')) ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
