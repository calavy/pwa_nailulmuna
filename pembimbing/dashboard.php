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

ikhtibar_require_pembimbing_access();
ensure_akademik_ikhtibar_tables($pdo);

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
$bolehSemua = is_super_admin() || in_array($role, ['admin', 'pengurus'], true);

$pembimbingInfo = $bolehSemua ? null : pembimbing_dashboard_current_pembimbing($pdo, $userId);
$pembimbingId = $pembimbingInfo !== null ? (int) ($pembimbingInfo['id'] ?? 0) : 0;
$pembimbingNama = $pembimbingInfo !== null
    ? (string) ($pembimbingInfo['nama'] ?? '')
    : trim((string) ($_SESSION['user']['nama'] ?? ''));

$tingkatanMilik = pembimbing_dashboard_tingkatan_list($pdo, $pembimbingId > 0 ? $pembimbingId : null, $bolehSemua);
if ($tingkatanMilik === [] && $bolehSemua) {
    $tingkatanMilik = pembimbing_dashboard_semua_tingkatan($pdo);
}

$semuaTingkatanList = $tingkatanMilik;
$tingkatanFilter = trim((string) ($_GET['tingkatan'] ?? ''));
if ($tingkatanFilter !== '' && !in_array($tingkatanFilter, $semuaTingkatanList, true)) {
    $tingkatanFilter = '';
}
$tingkatanAktif = $tingkatanFilter !== '' ? [$tingkatanFilter] : $semuaTingkatanList;

$tahun = (int) ($_GET['tahun'] ?? (int) date('Y'));
if ($tahun < 2000 || $tahun > 2100) {
    $tahun = (int) date('Y');
}

$today = date('Y-m-d');
$nowTime = date('H:i:s');
$hariKe = (int) date('N');

// Konteks hijri & pasaran agar header dashboard pembimbing selaras dengan
// dashboard utama (tanpa membebani query kalau belum ada tingkatan).
ensure_hijri_mappings_table($pdo);
ensure_akademik_hijri_awal_bulan_table($pdo);
$hijriBulanNama = [
    1 => 'Muharram', 2 => 'Safar', 3 => "Rabi' I", 4 => "Rabi' II", 5 => 'Jumadil Awal', 6 => 'Jumadil Akhir',
    7 => 'Rajab', 8 => "Sya'ban", 9 => 'Ramadan', 10 => 'Syawal', 11 => "Dzulqa'dah", 12 => 'Dzulhijah',
];
$pbDashHijriLabel = akademik_hijri_label_dari_masehi($pdo, $today, $hijriBulanNama);
$pbDashPasaran = akademik_pasaran_tampilkan($pdo) ? akademik_pasaran_pada_tanggal($today, $pdo) : '';

$statSantri = pembimbing_dashboard_jumlah_santri($pdo, $tingkatanAktif);
$statIzinCount = pembimbing_dashboard_jumlah_izin_hari_ini($pdo, $tingkatanAktif, $today);
$statPresensi = pembimbing_dashboard_presensi_hari_ini($pdo, $tingkatanAktif, $today);
$santriIzinList = pembimbing_dashboard_santri_izin_hari_ini($pdo, $tingkatanAktif, $today, 50);
$keaktivanRows = pembimbing_dashboard_keaktivan_santri($pdo, $tingkatanAktif, $tahun, 300);
$kategoriRingkas = pembimbing_dashboard_ringkasan_kategori($keaktivanRows);
$kegiatanAktif = pembimbing_dashboard_kegiatan_aktif($pdo, $tingkatanAktif, $hariKe, $nowTime);
$kegiatanAktifGrouped = jadwal_kelompokkan_kegiatan_aktif($kegiatanAktif);
$tugasStats = pembimbing_dashboard_tugas_stats($pdo, $userId, $bolehSemua);
$perTingkatan = pembimbing_dashboard_per_tingkatan_stats($pdo, $tingkatanAktif, $today, $keaktivanRows);

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

$hour = (int) date('H');
$salam = $hour < 11 ? 'Selamat pagi' : ($hour < 15 ? 'Selamat siang' : ($hour < 18 ? 'Selamat sore' : 'Selamat malam'));
$labelUser = $pembimbingNama !== '' ? $pembimbingNama : 'Pembimbing';
$pbDashServerClockMs = (int) round(microtime(true) * 1000);

$pageTitle = 'Dashboard Pembimbing';
$bodyClass = 'dash-page';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
    .pb-tingkatan-summary .pb-tingkatan-card {
        display: flex;
        flex-direction: column;
        gap: 0.4rem;
        padding: 1rem 1.1rem;
        border-radius: 14px;
        border: 1px solid rgba(15, 118, 110, 0.16);
        background: linear-gradient(160deg, rgba(240, 253, 250, 0.95) 0%, #fff 100%);
        color: #0f172a;
        transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        min-height: 11rem;
    }
    .pb-tingkatan-summary .pb-tingkatan-card:hover {
        transform: translateY(-2px);
        border-color: rgba(15, 118, 110, 0.45);
        box-shadow: 0 10px 28px rgba(15, 118, 110, 0.14);
        color: #0f172a;
    }
    .pb-tingkatan-card--active {
        border-color: rgba(15, 118, 110, 0.55) !important;
        box-shadow: 0 0 0 2px rgba(15, 118, 110, 0.18);
    }
    .pb-tingkatan-card__head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.5rem;
        flex-wrap: wrap;
    }
    .pb-tingkatan-card__nama {
        font-weight: 700;
        font-size: 0.95rem;
        color: #0f766e;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        max-width: 100%;
    }
    .pb-tingkatan-card__big {
        display: flex;
        align-items: baseline;
        gap: 0.45rem;
        line-height: 1;
        margin-top: 0.1rem;
    }
    .pb-tingkatan-card__big-val {
        font-size: 2.4rem;
        font-weight: 800;
        color: #0f172a;
        letter-spacing: -0.02em;
    }
    .pb-tingkatan-card__big-lbl {
        font-size: 0.95rem;
        color: #64748b;
        font-weight: 600;
    }
    .pb-tingkatan-card__chips {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem;
    }
    .pb-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.18rem 0.5rem;
        border-radius: 999px;
        border: 1px solid rgba(15, 23, 42, 0.08);
        background: rgba(255, 255, 255, 0.7);
        font-size: 0.75rem;
        font-weight: 600;
        color: #334155;
    }
    .pb-chip i { font-size: 0.72rem; opacity: 0.85; }
    .pb-chip--warning { color: #b45309; background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.25); }
    .pb-chip--success { color: #047857; background: rgba(16, 185, 129, 0.1); border-color: rgba(16, 185, 129, 0.25); }
    .pb-chip--danger  { color: #b91c1c; background: rgba(220, 38, 38, 0.08); border-color: rgba(220, 38, 38, 0.22); }
    .pb-tingkatan-card__bar {
        display: flex;
        height: 8px;
        border-radius: 999px;
        overflow: hidden;
        background: #e5e7eb;
    }
    .pb-tingkatan-card__bar > span { display: block; height: 100%; }
    [data-theme="dark"] .pb-tingkatan-summary .pb-tingkatan-card {
        background: linear-gradient(160deg, rgba(15, 23, 42, 0.85) 0%, rgba(30, 41, 59, 0.92) 100%);
        border-color: rgba(71, 85, 105, 0.45);
        color: #e2e8f0;
    }
    [data-theme="dark"] .pb-tingkatan-card__nama { color: #5eead4; }
    [data-theme="dark"] .pb-tingkatan-card__big-val { color: #f8fafc; }
    [data-theme="dark"] .pb-tingkatan-card__big-lbl { color: #cbd5e1; }
    [data-theme="dark"] .pb-chip { background: rgba(15, 23, 42, 0.55); border-color: rgba(71, 85, 105, 0.45); color: #e2e8f0; }
    [data-theme="dark"] .pb-tingkatan-card__bar { background: rgba(71, 85, 105, 0.5); }

    .pb-keaktifan-table .pb-keaktifan-group-row td {
        background: rgba(15, 118, 110, 0.06);
        border-top: 1px solid rgba(15, 118, 110, 0.12);
    }
</style>

<div class="dash-page">
    <div class="dash-hero mb-4">
        <div class="dash-hero-inner">
            <div class="dash-hero-layout dash-hero-layout--slim">
                <div class="dash-hero-greeting">
                    <div class="dash-hero-kicker text-white-50">Portal Pembimbing</div>
                    <h1 class="h3 dash-hero-title mb-2"><?= htmlspecialchars($salam) ?>, <?= htmlspecialchars($labelUser) ?>!</h1>
                    <p class="dash-hero-sub mb-0 small text-white-50">
                        Pantau santri pada tingkatan kajian Anda — jumlah, izin hari ini, dan keaktifan tahun <?= (int) $tahun ?>.
                    </p>
                    <?php if ($pbDashHijriLabel !== '' || $pbDashPasaran !== ''): ?>
                        <p class="dash-hero-hijri mb-0 mt-2 small text-white-50">
                            <?php if ($pbDashHijriLabel !== ''): ?>
                                <i class="fa-solid fa-moon me-1" aria-hidden="true"></i>
                                <strong class="text-white"><?= htmlspecialchars($pbDashHijriLabel) ?></strong>
                            <?php endif; ?>
                            <?php if ($pbDashPasaran !== ''): ?>
                                <span class="<?= $pbDashHijriLabel !== '' ? 'ms-2' : '' ?>">
                                    <i class="fa-solid fa-sun me-1" aria-hidden="true"></i>
                                    Pasaran <strong class="text-white"><?= htmlspecialchars($pbDashPasaran) ?></strong>
                                </span>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </div>
                <div class="dash-hero-clock" aria-live="polite">
                    <div class="dash-hero-clock__top">
                        <span class="dash-hero-clock__label"><i class="fa-regular fa-clock me-1"></i> Waktu berjalan</span>
                        <span class="dash-hero-clock__live">Live</span>
                    </div>
                    <div class="dash-hero-clock__time" id="dashboard-live-clock">--:--:--</div>
                    <div class="dash-hero-clock__date" id="dashboard-live-date">—</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Filter ringkas (tingkatan + tahun) -->
    <form method="get" class="card border-0 shadow-sm mb-4 dash-panel">
        <div class="card-body py-2 px-3">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-auto">
                    <label class="form-label small mb-0" for="pb-dash-tingkatan">Tingkatan</label>
                    <select id="pb-dash-tingkatan" name="tingkatan" class="form-select form-select-sm" onchange="this.form.submit()">
                        <option value="">Semua tingkatan saya<?= $semuaTingkatanList !== [] ? ' (' . count($semuaTingkatanList) . ')' : '' ?></option>
                        <?php foreach ($semuaTingkatanList as $tk): ?>
                            <option value="<?= htmlspecialchars((string) $tk) ?>"<?= $tingkatanFilter === (string) $tk ? ' selected' : '' ?>>
                                <?= htmlspecialchars((string) $tk) ?>
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
                <?php if ($semuaTingkatanList !== []): ?>
                <div class="col-12 col-md ms-md-auto text-md-end">
                    <div class="small text-muted">Tingkatan Anda:</div>
                    <div class="d-flex flex-wrap gap-1 justify-content-md-end">
                        <?php foreach ($semuaTingkatanList as $tk): ?>
                            <span class="badge text-bg-light border"><?= htmlspecialchars((string) $tk) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </form>

    <?php if ($semuaTingkatanList === []): ?>
        <div class="card border-0 shadow-sm mb-4 dash-panel">
            <div class="card-body text-center py-5">
                <div class="display-6 text-muted mb-2" aria-hidden="true">
                    <i class="fa-solid fa-chalkboard-user opacity-50"></i>
                </div>
                <h2 class="h5 mb-1">Belum mendapat kelas / kajian</h2>
                <p class="text-muted mb-2">
                    Akun pembimbing Anda<?php if ($pembimbingNama !== ''): ?> (<strong><?= htmlspecialchars($pembimbingNama) ?></strong>)<?php endif; ?>
                    belum diset sebagai pembimbing pada jadwal kegiatan apa pun.
                </p>
                <p class="small text-muted mb-0">
                    Hubungi pengurus untuk menetapkan kelas / kajian Anda di <em>Jadwal Kegiatan</em>.
                    Data santri, izin, dan keaktifan akan otomatis muncul di sini setelah kelas tersedia.
                </p>
            </div>
        </div>
    <?php endif; ?>

    <!-- KPI utama (mirip dashboard utama) -->
    <div class="dash-kpi-grid mb-4" role="list" aria-label="Ringkasan tingkatan saya">
        <div class="dash-kpi-grid__item" role="listitem">
            <div class="dash-kpi-box dash-kpi-box--putra h-100">
                <div class="dash-kpi-box__icon" aria-hidden="true"><i class="fa-solid fa-user-group"></i></div>
                <div class="dash-kpi-box__label">Total santri</div>
                <div class="dash-kpi-box__value"><?= (int) $totalSantri ?></div>
                <div class="dash-kpi-box__hint">
                    Putra <?= (int) $statSantri['putra'] ?> · Putri <?= (int) $statSantri['putri'] ?>
                </div>
            </div>
        </div>
        <div class="dash-kpi-grid__item" role="listitem">
            <div class="dash-kpi-box dash-kpi-box--izin h-100">
                <div class="dash-kpi-box__icon" aria-hidden="true"><i class="fa-solid fa-person-walking-luggage"></i></div>
                <div class="dash-kpi-box__label">Sedang izin</div>
                <div class="dash-kpi-box__value"><?= (int) $statIzinCount ?></div>
                <div class="dash-kpi-box__hint">Hari ini <?= htmlspecialchars(date('d M Y', strtotime($today))) ?></div>
            </div>
        </div>
        <div class="dash-kpi-grid__item" role="listitem">
            <div class="dash-kpi-box dash-kpi-box--putri h-100">
                <div class="dash-kpi-box__icon" aria-hidden="true"><i class="fa-solid fa-circle-check"></i></div>
                <div class="dash-kpi-box__label">Hadir hari ini</div>
                <div class="dash-kpi-box__value"><?= (int) $statPresensi['hadir'] ?></div>
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

    <!-- Ringkasan keaktifan kategori -->
    <div class="dash-kpi-grid mb-4" role="list" aria-label="Kategori keaktifan tahun ini">
        <div class="dash-kpi-grid__item" role="listitem">
            <div class="dash-kpi-box h-100">
                <div class="dash-kpi-box__icon" aria-hidden="true" style="background:linear-gradient(135deg,#198754,#10b981)"><i class="fa-solid fa-circle-check"></i></div>
                <div class="dash-kpi-box__label">Keaktifan Bagus</div>
                <div class="dash-kpi-box__value text-success"><?= (int) $kategoriRingkas['bagus'] ?></div>
                <div class="dash-kpi-box__hint">Santri</div>
            </div>
        </div>
        <div class="dash-kpi-grid__item" role="listitem">
            <div class="dash-kpi-box h-100">
                <div class="dash-kpi-box__icon" aria-hidden="true" style="background:linear-gradient(135deg,#f59e0b,#fbbf24)"><i class="fa-solid fa-circle-minus"></i></div>
                <div class="dash-kpi-box__label">Keaktifan Sedang</div>
                <div class="dash-kpi-box__value text-warning"><?= (int) $kategoriRingkas['sedang'] ?></div>
                <div class="dash-kpi-box__hint">Santri</div>
            </div>
        </div>
        <div class="dash-kpi-grid__item" role="listitem">
            <div class="dash-kpi-box h-100">
                <div class="dash-kpi-box__icon" aria-hidden="true" style="background:linear-gradient(135deg,#dc3545,#ef4444)"><i class="fa-solid fa-circle-xmark"></i></div>
                <div class="dash-kpi-box__label">Keaktifan Buruk</div>
                <div class="dash-kpi-box__value text-danger"><?= (int) $kategoriRingkas['buruk'] ?></div>
                <div class="dash-kpi-box__hint">Santri</div>
            </div>
        </div>
        <div class="dash-kpi-grid__item" role="listitem">
            <div class="dash-kpi-box h-100">
                <div class="dash-kpi-box__icon" aria-hidden="true" style="background:linear-gradient(135deg,#dc2626,#f87171)"><i class="fa-solid fa-triangle-exclamation"></i></div>
                <div class="dash-kpi-box__label">Alpa hari ini</div>
                <div class="dash-kpi-box__value text-danger"><?= (int) $statPresensi['alpa'] ?></div>
                <div class="dash-kpi-box__hint">
                    Izin <?= (int) $statPresensi['izin'] ?> · Sakit <?= (int) $statPresensi['sakit'] ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Layout 2-kolom: Kegiatan + Aksi cepat (mengikuti dashboard utama) -->
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
                        <div class="dash-empty-chart py-5 text-center text-muted">
                            <div class="display-6 mb-2 opacity-50"><i class="fa-regular fa-calendar"></i></div>
                            <p class="mb-0 fw-semibold">Belum ada kegiatan di jam ini</p>
                            <p class="small mb-0 mt-1">Silakan cek jadwal atau waktu lain.</p>
                        </div>
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
        <aside class="dash-layout-aside">
            <div class="card border-0 shadow-sm h-100 dash-panel dash-panel-side dash-panel--lift">
                <div class="card-header bg-transparent border-0 pt-4 px-4 pb-0">
                    <h2 class="h5 mb-1">Aksi cepat</h2>
                    <p class="small text-muted mb-0">Modul pembimbing yang sering dipakai</p>
                </div>
                <div class="card-body px-4 pb-4 pt-3 d-flex flex-column gap-2">
                    <a href="<?= htmlspecialchars(app_href('/pembimbing/tugas/buat.php')) ?>" class="btn btn-primary btn-sm text-start">
                        <i class="fa-solid fa-pen-to-square me-2"></i> Buat soal / tugas baru
                    </a>
                    <a href="<?= htmlspecialchars(app_href('/pembimbing/tugas/nilai.php')) ?>" class="btn btn-outline-primary btn-sm text-start">
                        <i class="fa-solid fa-clipboard-check me-2"></i> Penilaian tugas
                    </a>
                    <a href="<?= htmlspecialchars(app_href('/pembimbing/tugas/index.php')) ?>" class="btn btn-outline-secondary btn-sm text-start">
                        <i class="fa-solid fa-list-check me-2"></i> Daftar Tugas Ikhtibar
                    </a>
                    <a href="<?= htmlspecialchars(app_href('/pembimbing/tugas/rekap.php')) ?>" class="btn btn-outline-secondary btn-sm text-start">
                        <i class="fa-solid fa-chart-pie me-2"></i> Rekap nilai tugas
                    </a>
                    <a href="<?= htmlspecialchars(app_href('/pembimbing/perizinan.php')) ?>" class="btn btn-outline-secondary btn-sm text-start">
                        <i class="fa-solid fa-person-walking-arrow-right me-2"></i> Izin pembimbing
                    </a>
                </div>
            </div>
        </aside>
    </div>

    <!-- Ringkasan per tingkatan (clickable filter) -->
    <?php if ($perTingkatan !== []): ?>
    <div class="card border-0 shadow-sm mb-4 dash-panel dash-panel--lift pb-tingkatan-summary">
        <div class="card-header bg-transparent border-0 d-flex flex-wrap justify-content-between align-items-center gap-2 pt-4 px-4 pb-0">
            <div>
                <h2 class="h5 mb-1">
                    <i class="fa-solid fa-layer-group text-primary me-1"></i>
                    Ringkasan per tingkatan yang dibimbing
                </h2>
                <p class="small text-muted mb-0">
                    <?= count($perTingkatan) ?> tingkatan · klik kartu untuk fokus ke salah satunya.
                </p>
            </div>
            <?php if ($tingkatanFilter !== ''): ?>
                <a href="<?= htmlspecialchars(app_href('/pembimbing/dashboard.php?tahun=' . (int) $tahun)) ?>"
                   class="btn btn-sm btn-outline-secondary rounded-pill">
                    <i class="fa-solid fa-list me-1"></i> Lihat semua tingkatan
                </a>
            <?php endif; ?>
        </div>
        <div class="card-body px-4 pb-4 pt-3">
            <div class="row g-3">
                <?php foreach ($perTingkatan as $tk):
                    $tkName = (string) $tk['tingkatan'];
                    $tkTotal = (int) $tk['total'];
                    $tkUrl = app_href('/pembimbing/dashboard.php?tingkatan=' . rawurlencode($tkName) . '&tahun=' . (int) $tahun);
                    $isActive = $tingkatanFilter !== '' && strcasecmp($tingkatanFilter, $tkName) === 0;
                ?>
                    <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
                        <a href="<?= htmlspecialchars($tkUrl) ?>"
                           class="pb-tingkatan-card text-decoration-none d-block h-100<?= $isActive ? ' pb-tingkatan-card--active' : '' ?>">
                            <div class="pb-tingkatan-card__head">
                                <span class="pb-tingkatan-card__nama" title="<?= htmlspecialchars($tkName) ?>">
                                    <i class="fa-solid fa-graduation-cap me-1"></i>
                                    <?= htmlspecialchars($tkName) ?>
                                </span>
                                <?php if ($isActive): ?>
                                    <span class="badge text-bg-primary small">Aktif</span>
                                <?php endif; ?>
                            </div>
                            <div class="pb-tingkatan-card__big">
                                <span class="pb-tingkatan-card__big-val"><?= $tkTotal ?></span>
                                <span class="pb-tingkatan-card__big-lbl">santri</span>
                            </div>
                            <div class="pb-tingkatan-card__sub small text-muted">
                                Putra <strong><?= (int) $tk['putra'] ?></strong>
                                · Putri <strong><?= (int) $tk['putri'] ?></strong>
                            </div>
                            <div class="pb-tingkatan-card__chips">
                                <span class="pb-chip pb-chip--warning" title="Sedang izin hari ini">
                                    <i class="fa-solid fa-person-walking-luggage"></i>
                                    Izin <?= (int) $tk['izin'] ?>
                                </span>
                                <span class="pb-chip pb-chip--success" title="Hadir hari ini">
                                    <i class="fa-solid fa-circle-check"></i>
                                    Hadir <?= (int) $tk['hadir_hari_ini'] ?>
                                </span>
                                <?php if ((int) $tk['alpa_hari_ini'] > 0): ?>
                                    <span class="pb-chip pb-chip--danger" title="Alpa hari ini">
                                        <i class="fa-solid fa-triangle-exclamation"></i>
                                        Alpa <?= (int) $tk['alpa_hari_ini'] ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="pb-tingkatan-card__bar mt-2" aria-hidden="true">
                                <?php
                                $sumKeaktifan = $tk['bagus'] + $tk['sedang'] + $tk['buruk'];
                                $pcB = $sumKeaktifan > 0 ? round($tk['bagus'] / $sumKeaktifan * 100, 1) : 0;
                                $pcS = $sumKeaktifan > 0 ? round($tk['sedang'] / $sumKeaktifan * 100, 1) : 0;
                                $pcK = $sumKeaktifan > 0 ? round($tk['buruk'] / $sumKeaktifan * 100, 1) : 0;
                                ?>
                                <?php if ($sumKeaktifan > 0): ?>
                                    <span style="width:<?= $pcB ?>%;background:#198754" title="Bagus <?= (int) $tk['bagus'] ?>"></span>
                                    <span style="width:<?= $pcS ?>%;background:#f59e0b" title="Sedang <?= (int) $tk['sedang'] ?>"></span>
                                    <span style="width:<?= $pcK ?>%;background:#dc3545" title="Buruk <?= (int) $tk['buruk'] ?>"></span>
                                <?php else: ?>
                                    <span style="width:100%;background:#e5e7eb" title="Belum ada data"></span>
                                <?php endif; ?>
                            </div>
                            <div class="pb-tingkatan-card__legend small text-muted mt-1">
                                Keaktifan thn <?= (int) $tahun ?>:
                                <span class="text-success">Bagus <strong><?= (int) $tk['bagus'] ?></strong></span>
                                ·
                                <span class="text-warning">Sedang <strong><?= (int) $tk['sedang'] ?></strong></span>
                                ·
                                <span class="text-danger">Buruk <strong><?= (int) $tk['buruk'] ?></strong></span>
                                <?php if ((int) $tk['belum'] > 0): ?>
                                    · <span class="text-secondary">Belum <strong><?= (int) $tk['belum'] ?></strong></span>
                                <?php endif; ?>
                            </div>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tabel santri sedang izin -->
    <?php if ($santriIzinList !== []): ?>
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
    <?php if ($keaktivanByTingkatan !== []): ?>
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
                                ?>
                                    <tr>
                                        <td class="ps-3 small text-muted"><?= $no ?></td>
                                        <td class="fw-semibold"><?= htmlspecialchars((string) $r['nama_santri']) ?></td>
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

<script>
(function () {
    var clockEl = document.getElementById('dashboard-live-clock');
    var dateEl = document.getElementById('dashboard-live-date');
    if (!clockEl) return;
    var serverMs = <?= (int) $pbDashServerClockMs ?>;
    var driftMs = serverMs - Date.now();
    var pad = function (n) { return n < 10 ? '0' + n : '' + n; };
    var hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', "Jum'at", 'Sabtu'];
    var bulan = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    function tick() {
        var now = new Date(Date.now() + driftMs);
        clockEl.textContent = pad(now.getHours()) + ':' + pad(now.getMinutes()) + ':' + pad(now.getSeconds());
        if (dateEl) {
            dateEl.textContent = hari[now.getDay()] + ', ' + now.getDate() + ' ' + bulan[now.getMonth()] + ' ' + now.getFullYear();
        }
    }
    tick();
    setInterval(tick, 1000);
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
