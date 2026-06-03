<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/akademik_setoran.php';
require_once __DIR__ . '/../helpers/hijri_kalender.php';
require_once __DIR__ . '/../helpers/akademik_pasaran.php';

require_roles(['admin', 'pengurus']);
ensure_akademik_setoran_extended_schema($pdo);

$ctx = akademik_setoran_petugas_context($pdo);
$today = date('Y-m-d');
$bulanMulai = date('Y-m-01');
$bulanSelesai = $today;
$tahun = (int) date('Y');

$santriRows = akademik_setoran_santri_list_for_ctx($pdo, $ctx, $today);
$hariIni = ['setor' => 0, 'belum' => 0, 'izin' => 0, 'libur' => 0];
$santriByTingkatan = [];
foreach ($santriRows as $sr) {
    $st = (string) ($sr['status_hari_ini'] ?? 'BELUM');
    if ($st === 'SETOR') {
        $hariIni['setor']++;
    } elseif ($st === 'IZIN') {
        $hariIni['izin']++;
    } elseif ($st === 'LIBUR') {
        $hariIni['libur']++;
    } else {
        $hariIni['belum']++;
    }
    $tk = (string) ($sr['tingkatan'] ?? '—');
    if (!isset($santriByTingkatan[$tk])) {
        $santriByTingkatan[$tk] = [];
    }
    $santriByTingkatan[$tk][] = $sr;
}

$tingkatanList = $ctx['tingkatan_allowed'] ?? [];
$jumlahSantri = count($santriRows);
$jumlahTingkatan = count($tingkatanList);

$bulanRingkas = ['setor' => 0, 'izin' => 0, 'alpa' => 0];
if ($ctx['tingkatan_allowed'] !== []) {
    $kehadiranBulan = akademik_setoran_rekap_kehadiran($pdo, $bulanMulai, $bulanSelesai, $ctx['tingkatan_allowed']);
    foreach ($kehadiranBulan as $kr) {
        $st = (string) ($kr['status'] ?? '');
        if ($st === 'SETOR') {
            $bulanRingkas['setor']++;
        } elseif ($st === 'IZIN') {
            $bulanRingkas['izin']++;
        } elseif ($st === 'ALPA') {
            $bulanRingkas['alpa']++;
        }
    }
}

$pbDashHijriLabel = '';
$pbDashPasaran = '';
ensure_hijri_mappings_table($pdo);
ensure_akademik_hijri_awal_bulan_table($pdo);
$hijriBulanNama = [
    1 => 'Muharram', 2 => 'Safar', 3 => "Rabi' I", 4 => "Rabi' II", 5 => 'Jumadil Awal', 6 => 'Jumadil Akhir',
    7 => 'Rajab', 8 => "Sya'ban", 9 => 'Ramadan', 10 => 'Syawal', 11 => "Dzulqa'dah", 12 => 'Dzulhijah',
];
$pbDashHijriLabel = akademik_hijri_label_dari_masehi($pdo, $today, $hijriBulanNama);
$pbDashPasaran = akademik_pasaran_tampilkan($pdo) ? akademik_pasaran_pada_tanggal($today, $pdo) : '';

$setoranNavActive = 'home';
$pageTitle = 'Dashboard Setoran — Kajian';
$bodyClass = 'st-kajian-dash-page';
$pageStylesheets = [app_asset_href('/assets/css/setoran-portal.css')];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-2">
    <p class="page-intro-kicker mb-1">Kajian · Akademik</p>
</div>

<div class="st-portal-hero st-portal-dash-top mb-3">
    <div class="st-portal-hero__head">
        <div class="flex-grow-1">
            <div class="st-portal-hero__kicker">Setoran hafalan &amp; bait</div>
            <h1 class="st-portal-hero__name h5 mb-0">Dashboard setoran</h1>
            <p class="st-portal-hero__meta mb-0">
                <?= htmlspecialchars(date('l, d F Y', strtotime($today))) ?>
                <?php if ($pbDashHijriLabel !== ''): ?> · <?= htmlspecialchars($pbDashHijriLabel) ?><?php endif; ?>
                <?php if ($pbDashPasaran !== ''): ?> · <?= htmlspecialchars($pbDashPasaran) ?><?php endif; ?>
            </p>
            <p class="st-portal-hero__scope mb-0">
                <?= (int) $jumlahTingkatan ?> tingkatan · <?= (int) $jumlahSantri ?> santri aktif
            </p>
        </div>
        <div class="text-end">
            <div class="st-portal-hero__clock-time" id="st-kajian-live-clock">--:--</div>
        </div>
    </div>
    <div class="row g-2 st-portal-kpi">
        <div class="col-3">
            <div class="st-portal-kpi__box">
                <div class="st-portal-kpi__val"><?= (int) $hariIni['setor'] ?></div>
                <div class="st-portal-kpi__lbl">Setor hari ini</div>
            </div>
        </div>
        <div class="col-3">
            <div class="st-portal-kpi__box">
                <div class="st-portal-kpi__val"><?= (int) $hariIni['belum'] ?></div>
                <div class="st-portal-kpi__lbl">Belum</div>
            </div>
        </div>
        <div class="col-3">
            <div class="st-portal-kpi__box">
                <div class="st-portal-kpi__val"><?= (int) $hariIni['izin'] ?></div>
                <div class="st-portal-kpi__lbl">Izin/sakit</div>
            </div>
        </div>
        <div class="col-3">
            <div class="st-portal-kpi__box">
                <div class="st-portal-kpi__val"><?= (int) $hariIni['libur'] ?></div>
                <div class="st-portal-kpi__lbl">Libur</div>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-light border small py-2 mb-3">
    <i class="fa-solid fa-circle-info me-1 text-primary"></i>
    Setoran <strong>harian</strong> (bukan jadwal kegiatan). Alpa = tidak setor pada hari itu tanpa izin/sakit dari presensi.
    Input lapangan via <a href="<?= htmlspecialchars(app_href('/pembimbing/setoran_dashboard.php')) ?>">portal penerima setoran</a>.
</div>

<nav class="st-kajian-menu mb-3" aria-label="Menu dashboard setoran kajian">
    <a href="<?= htmlspecialchars(app_href('/akademik/setoran_rekap.php?tab=kehadiran&mulai=' . $bulanMulai . '&selesai=' . $bulanSelesai)) ?>" class="st-kajian-menu__item">
        <i class="fa-solid fa-calendar-check text-success" aria-hidden="true"></i>
        <span>Rekap kehadiran</span>
    </a>
    <a href="<?= htmlspecialchars(app_href('/akademik/setoran_rekap.php?tab=perolehan&mulai=' . $bulanMulai . '&selesai=' . $bulanSelesai)) ?>" class="st-kajian-menu__item">
        <i class="fa-solid fa-book-open text-primary" aria-hidden="true"></i>
        <span>Rekap perolehan</span>
    </a>
    <a href="<?= htmlspecialchars(app_href('/akademik/setoran_rekap_kitab.php?mulai=' . $bulanMulai . '&selesai=' . $bulanSelesai)) ?>" class="st-kajian-menu__item">
        <i class="fa-solid fa-layer-group text-info" aria-hidden="true"></i>
        <span>Rekap per kitab</span>
    </a>
    <a href="<?= htmlspecialchars(app_href('/pembimbing/setoran_keaktivan.php?tahun=' . $tahun)) ?>" class="st-kajian-menu__item">
        <i class="fa-solid fa-chart-line text-warning" aria-hidden="true"></i>
        <span>Keaktivan tahun</span>
    </a>
    <a href="<?= htmlspecialchars(app_href('/akademik/bait_kitab.php')) ?>" class="st-kajian-menu__item">
        <i class="fa-solid fa-gear text-secondary" aria-hidden="true"></i>
        <span>Pengaturan bait</span>
    </a>
    <a href="<?= htmlspecialchars(app_href('/akademik/setoran_penerima.php')) ?>" class="st-kajian-menu__item">
        <i class="fa-solid fa-user-check text-dark" aria-hidden="true"></i>
        <span>Penerima setoran</span>
    </a>
    <a href="<?= htmlspecialchars(app_href('/akademik/hafalan.php')) ?>" class="st-kajian-menu__item">
        <i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>
        <span>Input manual</span>
    </a>
    <a href="<?= htmlspecialchars(app_href('/pembimbing/setoran_dashboard.php')) ?>" class="st-kajian-menu__item">
        <i class="fa-solid fa-qrcode" aria-hidden="true"></i>
        <span>Portal scan</span>
    </a>
    <a href="<?= htmlspecialchars(app_href('/akademik/kalender.php')) ?>" class="st-kajian-menu__item">
        <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
        <span>Kalender &amp; libur</span>
    </a>
</nav>

<div class="card shadow-sm mb-3">
    <div class="card-body py-2">
        <div class="small text-muted mb-1">Ringkas bulan ini (<?= htmlspecialchars(date('F Y')) ?>)</div>
        <div class="d-flex flex-wrap gap-3">
            <span><strong class="text-success"><?= number_format($bulanRingkas['setor'], 0, ',', '.') ?></strong> setor</span>
            <span><strong class="text-primary"><?= number_format($bulanRingkas['izin'], 0, ',', '.') ?></strong> izin</span>
            <span><strong class="text-danger"><?= number_format($bulanRingkas['alpa'], 0, ',', '.') ?></strong> alpa</span>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header fw-semibold d-flex justify-content-between align-items-center py-2">
        <span>Santri hari ini</span>
        <span class="badge text-bg-light border"><?= (int) $jumlahSantri ?></span>
    </div>
    <div class="card-body p-0 st-portal-santri">
        <?php if ($santriByTingkatan === []): ?>
            <div class="p-3 text-muted small mb-0">Tidak ada data santri aktif pada tingkatan terdaftar.</div>
        <?php else: ?>
            <?php foreach ($santriByTingkatan as $tk => $rows): ?>
                <div class="st-portal-santri__tk">
                    <div class="st-portal-santri__tk-head"><?= htmlspecialchars((string) $tk) ?> (<?= count($rows) ?>)</div>
                    <ul class="st-portal-santri__list">
                        <?php foreach ($rows as $sr): ?>
                            <?php
                            $st = (string) ($sr['status_hari_ini'] ?? 'BELUM');
                            $badge = match ($st) {
                                'SETOR' => 'success',
                                'IZIN' => 'primary',
                                'LIBUR' => 'secondary',
                                default => 'warning',
                            };
                            $label = match ($st) {
                                'SETOR' => 'Sudah setor',
                                'IZIN' => 'Izin/sakit',
                                'LIBUR' => 'Libur',
                                default => 'Belum',
                            };
                            ?>
                            <li>
                                <div class="st-portal-santri__link" style="cursor:default">
                                    <span class="st-portal-santri__nama"><?= htmlspecialchars((string) ($sr['nama_santri'] ?? '')) ?></span>
                                    <span class="st-portal-santri__nis"><?= htmlspecialchars((string) ($sr['nis'] ?? '')) ?></span>
                                    <span class="badge text-bg-<?= $badge ?> st-portal-santri__badge"><?= htmlspecialchars($label) ?></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    function tick() {
        var el = document.getElementById('st-kajian-live-clock');
        if (el) {
            el.textContent = new Date().toLocaleTimeString('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
    }
    tick();
    setInterval(tick, 1000);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
