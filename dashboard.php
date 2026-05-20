<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/helpers/app.php';
require_once __DIR__ . '/helpers/akademik.php';
require_once __DIR__ . '/helpers/hijri_kalender.php';
require_once __DIR__ . '/helpers/akademik_hari_khusus.php';
require_once __DIR__ . '/helpers/akademik_pasaran.php';
require_once __DIR__ . '/helpers/santri_operasional.php';

ensure_santri_identity_columns($pdo);
require_once __DIR__ . '/helpers/mukimin.php';
require_once __DIR__ . '/helpers/dashboard_menu.php';

require_roles(['admin', 'pengurus', 'petugas_absensi']);

$today = date('Y-m-d');

ensure_hijri_mappings_table($pdo);
ensure_akademik_hijri_awal_bulan_table($pdo);
hijri_sync_from_akademik_awal_bulan($pdo);
$hijriBulanNamaDash = [
    1 => 'Muharram', 2 => 'Safar', 3 => "Rabi' I", 4 => "Rabi' II", 5 => 'Jumadil Awal', 6 => 'Jumadil Akhir',
    7 => 'Rajab', 8 => "Sya'ban", 9 => 'Ramadan', 10 => 'Syawal', 11 => "Dzulqa'dah", 12 => 'Dzulhijah',
];
akademik_libur_sinkron_hari_khusus_tahun($pdo, (int) date('Y'), $hijriBulanNamaDash);
$dashHijriLabel = akademik_hijri_label_dari_masehi($pdo, $today, $hijriBulanNamaDash);
$dashPasaran = akademik_pasaran_tampilkan($pdo) ? akademik_pasaran_pada_tanggal($today, $pdo) : '';
$nowTime = date('H:i:s');
$hariKe = (int) date('N');

$putra = 0;
$putri = 0;
if (table_exists($pdo, 'santri') && column_exists($pdo, 'santri', 'jenis_kelamin')) {
    $aktifSql = '';
    if (column_exists($pdo, 'santri', 'status_santri')) {
        $aktifSql = ' WHERE ' . santri_sql_aktif_only('santri');
    } elseif (column_exists($pdo, 'santri', 'is_aktif')) {
        $aktifSql = ' WHERE COALESCE(is_aktif, 1) = 1';
    }
    $row = $pdo->query(
        'SELECT
            SUM(CASE WHEN TRIM(jenis_kelamin) = "Laki-laki" THEN 1 ELSE 0 END) AS putra,
            SUM(CASE WHEN TRIM(jenis_kelamin) = "Perempuan" THEN 1 ELSE 0 END) AS putri
         FROM santri' . $aktifSql
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $putra = (int) ($row['putra'] ?? 0);
    $putri = (int) ($row['putri'] ?? 0);
}

$mukiminCount = mukimin_count($pdo);

$izinAktifCount = 0;
$izinAktifRows = [];
$sqlAktifSantri = santri_sql_aktif_only('s');
if (table_exists($pdo, 'perizinan') && table_exists($pdo, 'santri')) {
    $approvalSql = '';
    if (column_exists($pdo, 'perizinan', 'approval_status')) {
        $approvalSql = ' AND i.approval_status = "DISETUJUI"';
    }
    $cntStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM perizinan i
         INNER JOIN santri s ON s.id = i.santri_id AND ' . $sqlAktifSantri . '
         WHERE i.status_izin = "IZIN"
           AND :today BETWEEN i.tanggal_mulai AND i.tanggal_selesai' . $approvalSql
    );
    $cntStmt->execute(['today' => $today]);
    $izinAktifCount = (int) $cntStmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT i.id, i.jenis_izin, i.tanggal_mulai, i.tanggal_selesai, s.nama_santri, s.nis, s.tingkatan
         FROM perizinan i
         INNER JOIN santri s ON s.id = i.santri_id AND ' . $sqlAktifSantri . '
         WHERE i.status_izin = "IZIN"
           AND :today2 BETWEEN i.tanggal_mulai AND i.tanggal_selesai' . $approvalSql . '
         ORDER BY s.nama_santri ASC
         LIMIT 24'
    );
    $stmt->execute(['today2' => $today]);
    $izinAktifRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$kegiatanAktif = [];
if (table_exists($pdo, 'jadwal_kegiatan') && table_exists($pdo, 'kegiatan')) {
    ensure_jadwal_kegiatan_tempat($pdo);
    $stmt = $pdo->prepare(
        'SELECT k.nama_kegiatan, j.tingkatan, j.jam_mulai, j.jam_selesai, j.tempat
         FROM jadwal_kegiatan j
         INNER JOIN kegiatan k ON k.id = j.kegiatan_id
         WHERE (j.hari_ke = 0 OR j.hari_ke = :hari_ke)
           AND :jam_now BETWEEN j.jam_mulai AND j.jam_selesai
           AND k.is_active = 1
         ORDER BY j.jam_mulai ASC, j.tingkatan ASC'
    );
    $stmt->execute(['hari_ke' => $hariKe, 'jam_now' => $nowTime]);
    $kegiatanAktif = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** Anchor jam live agar selaras dengan waktu server yang dipakai query jadwal (bukan jam lokal browser). */
$dashServerClockMs = (int) round(microtime(true) * 1000);

$iconForPath = static function (string $path): string {
    if (str_contains($path, 'dashboard')) {
        return 'fa-solid fa-house';
    }
    if (str_contains($path, 'santri')) {
        return 'fa-solid fa-user-group';
    }
    if (str_contains($path, 'wali')) {
        return 'fa-solid fa-people-roof';
    }
    if (str_contains($path, 'pembimbing')) {
        return 'fa-solid fa-chalkboard-user';
    }
    if (str_contains($path, 'presensi')) {
        return 'fa-solid fa-qrcode';
    }
    if (str_contains($path, 'jadwal')) {
        return 'fa-solid fa-calendar-days';
    }
    if (str_contains($path, 'akademik')) {
        return 'fa-solid fa-book';
    }
    if (str_contains($path, 'perizinan')) {
        return 'fa-solid fa-person-walking-arrow-right';
    }
    if (str_contains($path, 'admin')) {
        return 'fa-solid fa-file-lines';
    }
    if (str_contains($path, 'poin')) {
        return 'fa-solid fa-star';
    }
    if (str_contains($path, 'keuangan') || str_contains($path, 'pembayaran')) {
        return 'fa-solid fa-wallet';
    }
    if (str_contains($path, 'rekap')) {
        return 'fa-solid fa-chart-column';
    }
    if (str_contains($path, 'settings')) {
        return 'fa-solid fa-gear';
    }
    return 'fa-solid fa-arrow-right';
};

$hour = (int) date('H');
$salam = $hour < 11 ? 'Selamat pagi' : ($hour < 15 ? 'Selamat siang' : ($hour < 18 ? 'Selamat sore' : 'Selamat malam'));
$namaUser = trim((string) ($_SESSION['user']['nama'] ?? ''));
$labelUser = $namaUser !== '' ? $namaUser : 'Bapak/Ibu';
$namaPonpes = trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren'));
$alamatPonpes = trim((string) app_setting($pdo, 'alamat_ponpes', ''));
$logoPath = trim((string) app_setting($pdo, 'logo_path', ''));
$logoUrlSetting = trim((string) app_setting($pdo, 'logo_url', ''));
$dashLogo = $logoPath !== '' ? '/' . ltrim($logoPath, '/') : $logoUrlSetting;
$dashHeroKicker = trim((string) app_setting($pdo, 'jenis_pendidikan', ''));
$lettersOnly = preg_replace('/[^A-Za-z]/u', '', $namaPonpes);
$dashLogoInitial = strtoupper(substr(($lettersOnly !== '' ? $lettersOnly : 'AP'), 0, 2));

$menuPack = require __DIR__ . '/includes/menu_data.php';
$dashMenuItems = filter_menu_items_by_acl($pdo, $menuPack['menuItems'], $menuPack['permissionPathMap']);
$dashMenuStructure = $menuPack['menuStructure'];

$dashTiles = dashboard_build_quick_tiles($dashMenuItems, $dashMenuStructure, $iconForPath);
$sideQuickActions = dashboard_filter_quick_actions($dashMenuItems);
$sideQuickCount = count($sideQuickActions);
$dashTileCount = count($dashTiles);

$canJadwal = user_can_access_menu_path('/jadwal/index.php', $dashMenuItems);
$canPerizinan = user_can_access_menu_path('/perizinan/index.php', $dashMenuItems);

$pageTitle = 'Dashboard';
require_once __DIR__ . '/includes/header.php';
?>

<div class="dash-page">
    <div class="dash-hero mb-4">
        <div class="dash-hero-inner">
            <div class="dash-hero-brand dash-hero-brand--top mb-3 mb-md-4">
                <?php if ($dashLogo !== ''): ?>
                    <div class="dash-hero-logo-wrap">
                        <img src="<?= htmlspecialchars($dashLogo) ?>" alt="" class="dash-hero-logo" width="96" height="96" decoding="async">
                    </div>
                <?php else: ?>
                    <div class="dash-hero-logo-wrap dash-hero-logo-wrap--placeholder" aria-hidden="true">
                        <span class="dash-hero-logo-fallback"><?= htmlspecialchars($dashLogoInitial) ?></span>
                    </div>
                <?php endif; ?>
                <div class="dash-hero-brand-text min-w-0">
                    <div class="dash-hero-pesantren-kicker text-white-50"><?= $dashHeroKicker !== '' ? htmlspecialchars($dashHeroKicker) : 'Pesantren' ?></div>
                    <div class="dash-hero-pesantren text-white"><?= htmlspecialchars($namaPonpes) ?></div>
                    <?php if ($alamatPonpes !== ''): ?>
                        <p class="dash-hero-pesantren-sub mb-0 mt-2 text-white-50 d-flex align-items-start gap-2">
                            <i class="fa-solid fa-location-dot mt-1 flex-shrink-0 opacity-75" aria-hidden="true"></i>
                            <span class="min-w-0"><?= htmlspecialchars($alamatPonpes) ?></span>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="dash-hero-divider"></div>
            <div class="dash-hero-grid">
                <div class="dash-hero-greeting">
                    <div class="dash-hero-kicker text-white-50">Beranda</div>
                    <h1 class="h3 dash-hero-title mb-2"><?= htmlspecialchars($salam) ?>, <?= htmlspecialchars($labelUser) ?>!</h1>
                    <p class="dash-hero-date mb-0">
                        <span id="dashboard-hero-date"></span>
                    </p>
                    <?php if ($dashHijriLabel !== '' || $dashPasaran !== ''): ?>
                        <p class="dash-hero-hijri mb-0 mt-1 small text-white-50">
                            <?php if ($dashHijriLabel !== ''): ?>
                                <i class="fa-solid fa-moon me-1" aria-hidden="true"></i>
                                <strong class="text-white"><?= htmlspecialchars($dashHijriLabel) ?></strong>
                            <?php endif; ?>
                            <?php if ($dashPasaran !== ''): ?>
                                <span class="<?= $dashHijriLabel !== '' ? 'ms-2' : '' ?>"><i class="fa-solid fa-sun me-1" aria-hidden="true"></i>Pasaran <strong class="text-white"><?= htmlspecialchars($dashPasaran) ?></strong></span>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="dash-hero-summary d-none d-md-block text-md-end">
                    <span class="dash-hero-badge">Ringkasan hari ini</span>
                    <p class="dash-hero-hint text-white-50 mt-2 mb-0">Santri aktif, izin, slot jadwal, dan jam berjalan — ringkas di satu layar.</p>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-2">
            <div class="card h-100 border-0 shadow-sm dash-kpi dash-kpi--putra">
                <div class="card-body">
                    <div class="dash-kpi-inner">
                        <div class="dash-kpi-ico" aria-hidden="true"><i class="fa-solid fa-user-graduate"></i></div>
                        <div class="min-w-0">
                            <div class="dash-kpi-label">Santri Putra</div>
                            <div class="dash-kpi-value"><?= $putra ?></div>
                            <div class="dash-kpi-hint small">Jumlah aktif</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card h-100 border-0 shadow-sm dash-kpi dash-kpi--putri">
                <div class="card-body">
                    <div class="dash-kpi-inner">
                        <div class="dash-kpi-ico" aria-hidden="true"><i class="fa-solid fa-user-graduate"></i></div>
                        <div class="min-w-0">
                            <div class="dash-kpi-label">Santri Putri</div>
                            <div class="dash-kpi-value"><?= $putri ?></div>
                            <div class="dash-kpi-hint small">Jumlah aktif</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card h-100 border-0 shadow-sm dash-kpi">
                <div class="card-body">
                    <div class="dash-kpi-inner">
                        <div class="dash-kpi-ico" aria-hidden="true"><i class="fa-solid fa-book-open"></i></div>
                        <div class="min-w-0">
                            <div class="dash-kpi-label">Data Mukimin</div>
                            <div class="dash-kpi-value"><?= $mukiminCount ?></div>
                            <div class="dash-kpi-hint small">Santri non aktif</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card h-100 border-0 shadow-sm dash-kpi dash-kpi--izin">
                <div class="card-body">
                    <div class="dash-kpi-inner">
                        <div class="dash-kpi-ico" aria-hidden="true"><i class="fa-solid fa-person-walking-arrow-right"></i></div>
                        <div class="min-w-0">
                            <div class="dash-kpi-label">Sedang izin</div>
                            <div class="dash-kpi-value"><?= $izinAktifCount ?></div>
                            <div class="dash-kpi-hint small">Hari ini</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="card h-100 border-0 shadow-sm dash-kpi dash-kpi--waktu dash-status-card--live">
                <div class="card-body">
                    <div class="dash-kpi-inner">
                        <div class="dash-kpi-ico" aria-hidden="true"><i class="fa-solid fa-clock"></i></div>
                        <div class="min-w-0 flex-grow-1">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                                <div class="dash-kpi-label mb-0">Waktu berjalan</div>
                                <span class="badge rounded-pill dash-pill-live small">Live</span>
                            </div>
                            <div class="dash-kpi-value dash-kpi-value--clock dash-clock-live" id="dashboard-live-clock">--:--:--</div>
                            <div class="dash-kpi-hint small mb-0" id="dashboard-live-date"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4 mb-4 dash-main-panels">
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm h-100 dash-panel dash-panel--lift">
                <div class="card-header bg-transparent border-0 d-flex flex-wrap justify-content-between align-items-start gap-2 pt-4 px-4 pb-0">
                    <div>
                        <h2 class="h5 mb-1">Kegiatan berlangsung</h2>
                        <p class="small text-muted mb-0">Jadwal pada slot waktu sekarang</p>
                    </div>
                    <?php if ($canJadwal): ?>
                    <a href="/jadwal/index.php" class="btn btn-sm btn-outline-primary rounded-pill">Jadwal lengkap</a>
                    <?php endif; ?>
                </div>
                <div class="card-body px-4 pb-4 pt-3">
                    <?php if ($kegiatanAktif === []): ?>
                        <div class="dash-empty-chart py-5 text-center text-muted">
                            <div class="display-6 mb-2 opacity-50"><i class="fa-regular fa-calendar"></i></div>
                            <p class="mb-0 fw-semibold">Belum ada kegiatan di jam ini</p>
                            <p class="small mb-0 mt-1">Silakan cek jadwal atau waktu lain.</p>
                        </div>
                    <?php else: ?>
                        <div class="d-flex flex-column gap-2">
                            <?php foreach ($kegiatanAktif as $kg): ?>
                                <div class="dash-jadwal-row">
                                    <div class="dash-jadwal-time">
                                        <i class="fa-regular fa-clock me-1 opacity-75"></i>
                                        <?= htmlspecialchars(substr((string) ($kg['jam_mulai'] ?? ''), 0, 5)) ?>–<?= htmlspecialchars(substr((string) ($kg['jam_selesai'] ?? ''), 0, 5)) ?>
                                    </div>
                                    <div class="dash-jadwal-row-main">
                                        <span class="dash-jadwal-nama"><?= htmlspecialchars((string) ($kg['nama_kegiatan'] ?? '')) ?></span>
                                        <span class="dash-jadwal-meta"><?= htmlspecialchars((string) ($kg['tingkatan'] ?? '')) ?></span>
                                    </div>
                                    <?php if (trim((string) ($kg['tempat'] ?? '')) !== ''): ?>
                                        <div class="dash-jadwal-meta dash-jadwal-tempat">
                                            <i class="fa-solid fa-location-dot me-1"></i><?= htmlspecialchars(trim((string) $kg['tempat'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm h-100 dash-panel dash-panel-side dash-panel--lift">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                    <h2 class="h5 mb-1">Aksi cepat</h2>
                    <p class="small text-muted mb-0">Modul yang sering dipakai</p>
                </div>
                <div class="card-body px-4 pb-4 pt-3 d-flex flex-column gap-2">
                    <?php foreach ($sideQuickActions as $act): ?>
                        <a href="<?= htmlspecialchars($act['path']) ?>" class="<?= htmlspecialchars($act['class']) ?> rounded-3 py-2">
                            <i class="fa-solid <?= htmlspecialchars($act['icon']) ?> me-2"></i> <?= htmlspecialchars($act['label']) ?>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($sideQuickCount === 0): ?>
                        <p class="small text-muted mb-0">Gunakan menu modul di samping untuk membuka fitur yang tersedia.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($izinAktifRows !== []): ?>
        <div class="card border-0 shadow-sm mb-4 dash-panel dash-panel--lift">
            <div class="card-header bg-transparent border-0 d-flex flex-wrap justify-content-between align-items-center gap-2 pt-4 px-4 pb-0">
                <div>
                    <h2 class="h5 mb-1">Santri sedang izin</h2>
                    <p class="small text-muted mb-0">Disetujui · hari ini</p>
                </div>
                <?php if ($canPerizinan): ?>
                <a href="/perizinan/index.php" class="btn btn-sm btn-outline-secondary rounded-pill">Kelola perizinan</a>
                <?php endif; ?>
            </div>
            <div class="card-body px-4 pb-4 pt-2">
                <div class="table-responsive rounded-3 border">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th class="ps-3">Nama</th>
                                <th>NIS</th>
                                <th>Tingkatan</th>
                                <th class="pe-3">Jenis</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($izinAktifRows as $ir): ?>
                                <tr>
                                    <td class="ps-3 fw-semibold"><?= htmlspecialchars((string) ($ir['nama_santri'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string) ($ir['nis'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string) ($ir['tingkatan'] ?? '')) ?></td>
                                    <td class="pe-3"><span class="badge text-bg-light border"><?= htmlspecialchars(jenis_izin_label((string) ($ir['jenis_izin'] ?? ''))) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <details class="dash-quick-details card border-0 shadow-sm mb-2" open>
        <summary class="dash-quick-details__summary">
            <span class="dash-quick-details__lead">
                <span class="dash-quick-details__ico" aria-hidden="true"><i class="fa-solid fa-bolt"></i></span>
                <span class="dash-quick-details__text">
                    <span class="dash-quick-details__title">Menu cepat</span>
                    <span class="dash-quick-details__meta"><?= (int) $dashTileCount ?> pintasan · sesuai hak akses</span>
                </span>
            </span>
            <span class="dash-quick-details__chev" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span>
        </summary>
        <div class="dash-quick-details__body">
            <?php if ($dashTiles === []): ?>
                <p class="small text-muted mb-0 px-3 pb-3">Belum ada modul yang dapat ditampilkan.</p>
            <?php else: ?>
                <div class="dash-quick-grid dash-quick-grid--compact">
                    <?php foreach ($dashTiles as $idx => $tile): ?>
                        <?php $accent = (int) ($idx % 6); ?>
                        <a class="dash-quick-tile dash-quick-tile--compact dash-quick-tile--a<?= $accent ?>" href="<?= htmlspecialchars($tile['path']) ?>" title="<?= htmlspecialchars($tile['label']) ?>">
                            <i class="<?= htmlspecialchars($tile['icon']) ?>" aria-hidden="true"></i>
                            <span class="dash-quick-tile-main">
                                <span class="dash-quick-tile-line1"><?= htmlspecialchars($tile['label']) ?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </details>
</div>

<script>
    (function () {
        const clockEl = document.getElementById('dashboard-live-clock');
        const dateEl = document.getElementById('dashboard-live-date');
        const heroDate = document.getElementById('dashboard-hero-date');
        if (!clockEl || !dateEl) return;
        const serverAtLoad = <?= (int) $dashServerClockMs ?>;
        const perfAtLoad = performance.now();
        function nowSynced() {
            return new Date(serverAtLoad + (performance.now() - perfAtLoad));
        }
        const tz = 'Asia/Jakarta';
        const fmtTime = new Intl.DateTimeFormat('en-GB', { timeZone: tz, hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false });
        const fmtDate = new Intl.DateTimeFormat('id-ID', { timeZone: tz, weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
        function tick() {
            const d = nowSynced();
            clockEl.textContent = fmtTime.format(d);
            const dateStr = fmtDate.format(d);
            dateEl.textContent = dateStr;
            if (heroDate) heroDate.textContent = dateStr;
        }
        tick();
        setInterval(tick, 1000);
    })();
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
