<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_neraca.php';
require_once __DIR__ . '/../helpers/payroll_pembimbing.php';
require_once __DIR__ . '/../helpers/pkpps.php';
require_once __DIR__ . '/../helpers/entity_list_sort.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';

require_roles(['admin']);

keuangan_ensure_schema_deferred($pdo);

if (!table_exists($pdo, 'presensi_pembimbing')) {
    set_flash('error', 'Tabel presensi_pembimbing belum ada. Jalankan migrasi terbaru.');
    header('Location: ' . app_href('/dashboard.php'));
    exit;
}

payroll_pembimbing_ensure_schema($pdo);
payroll_pembimbing_ensure_gaji_table($pdo);
pkpps_ensure_schema($pdo);

$payrollPageBase = (defined('PAYROLL_FROM_KEUANGAN') && PAYROLL_FROM_KEUANGAN)
    ? '/keuangan/gaji_pembimbing.php'
    : '/rekap/pembimbing.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bayar_gaji') {
    $res = payroll_pembimbing_bayar($pdo, $_POST, (int) ($_SESSION['user']['id'] ?? 0));
    set_flash($res['ok'] ? 'success' : 'error', $res['message']);
    $redirectQs = http_build_query(array_filter([
        'month' => (int) ($_POST['month'] ?? 0),
        'year' => (int) ($_POST['year'] ?? 0),
        'cal' => (string) ($_POST['cal'] ?? ''),
        'kegiatan_id' => (int) ($_POST['kegiatan_id'] ?? 0) ?: null,
        'paper' => (string) ($_POST['paper'] ?? ''),
        'jam_mode' => payroll_pembimbing_normalize_jam_mode((string) ($_POST['jam_mode'] ?? '')) !== 'jadwal'
            ? payroll_pembimbing_normalize_jam_mode((string) ($_POST['jam_mode'] ?? ''))
            : null,
        'detail' => (int) ($_POST['detail'] ?? 0) ?: null,
    ]));
    header('Location: ' . app_href($payrollPageBase . '?' . $redirectQs));
    exit;
}

$period = payroll_pembimbing_resolve_period($pdo, $_GET);
$month = (int) $period['month'];
$year = (int) $period['year'];
$calendarMode = (string) $period['calendar_mode'];
$startDate = (string) $period['start_date'];
$endDate = (string) $period['end_date'];
$totalDays = (int) $period['total_days'];
$periodLabel = (string) $period['period_label'];
$periodBridge = (string) $period['period_bridge'];
$masehiMonths = $period['masehi_months'];
$hijriyahMonths = $period['hijriyah_months'];
$yearMin = (int) $period['year_min'];
$yearMax = (int) $period['year_max'];
$anchorMasehiYear = (int) $period['anchor_masehi_year'];
$currentHijriYear = (int) $period['current_hijri_year'];
$currentMasehiYear = (int) $period['current_masehi_year'];

$kegiatanFilter = (int) ($_GET['kegiatan_id'] ?? 0);
$detailPembimbingId = (int) ($_GET['detail'] ?? 0);
$paper = strtoupper((string) ($_GET['paper'] ?? 'A4'));
if (!in_array($paper, ['A4', 'F4'], true)) {
    $paper = 'A4';
}
$jamMode = payroll_pembimbing_normalize_jam_mode((string) ($_GET['jam_mode'] ?? 'jadwal'));
$jamModeLabels = payroll_pembimbing_jam_mode_labels();
$jamModeLabel = $jamModeLabels[$jamMode] ?? 'Durasi jadwal';

$tarifMap = payroll_pembimbing_tarif_map($pdo);
$kriteriaLabels = payroll_pembimbing_kriteria_labels();
$lateTolerance = (int) app_setting($pdo, 'batas_telat_menit', '15');
if ($lateTolerance < 0) {
    $lateTolerance = 0;
}

$stmt = $pdo->prepare('
    SELECT
        b.id AS pembimbing_id,
        b.nip,
        b.nama_pembimbing,
        b.gaji_pokok,
        b.tarif_kriteria
    FROM pembimbing b
    ORDER BY ' . pembimbing_list_order_sql('b') . '
');
$stmt->execute();
$rows = $stmt->fetchAll();
$kegiatanList = table_exists($pdo, 'kegiatan')
    ? ($pdo->query('SELECT id, nama_kegiatan FROM kegiatan ORDER BY nama_kegiatan ASC')->fetchAll() ?: [])
    : [];
$kegiatanMap = [];
foreach ($kegiatanList as $kg) {
    $kegiatanMap[(int) ($kg['id'] ?? 0)] = (string) ($kg['nama_kegiatan'] ?? '');
}

$presensiAgg = payroll_pembimbing_presensi_agg_map($pdo, $startDate, $endDate, $kegiatanFilter, $jamMode);
$jamKriteriaMap = payroll_pembimbing_jam_kriteria_map($pdo, $startDate, $endDate, $kegiatanFilter, $jamMode);
$expectedSlotsMap = payroll_pembimbing_expected_slots_by_pembimbing($pdo, $startDate, $endDate, $kegiatanFilter);
$hadirSlotMap = payroll_pembimbing_hadir_slot_keys_map($pdo, $startDate, $endDate, $kegiatanFilter);
$izinDatesMap = payroll_pembimbing_izin_dates_by_pembimbing($pdo, $startDate, $endDate);

foreach ($rows as &$row) {
    $pid = (int) ($row['pembimbing_id'] ?? 0);
    $agg = $presensiAgg[$pid] ?? ['total_jam' => 0.0, 'total_datang' => 0, 'hari_hadir' => 0];
    $datang = (int) ($agg['total_datang'] ?? 0);
    $hariHadir = (int) ($agg['hari_hadir'] ?? 0);
    $izinDates = $izinDatesMap[$pid] ?? ['IZIN' => [], 'SAKIT' => []];
    $izin = count((array) ($izinDates['IZIN'] ?? []));
    $sakit = count((array) ($izinDates['SAKIT'] ?? []));
    $expectedSlots = (array) ($expectedSlotsMap[$pid] ?? []);
    $hadirKeys = (array) ($hadirSlotMap[$pid] ?? []);
    $alpa = payroll_pembimbing_hitung_alpa(
        $pid,
        $expectedSlots,
        $hadirKeys,
        $izinDates,
        $totalDays,
        $hariHadir,
        $izin,
        $sakit
    );

    $lateSql = '
        SELECT COUNT(*)
        FROM presensi_pembimbing p
        ' . payroll_pembimbing_scan_jadwal_join_sql($pdo, 'p') . '
        WHERE p.pembimbing_id = :pembimbing_id
          AND p.tanggal BETWEEN :start_date AND :end_date
          AND p.jenis_scan = "DATANG"
          AND COALESCE(j.jam_mulai, pj.jam_mulai) IS NOT NULL
          AND TIME_TO_SEC(p.jam) > TIME_TO_SEC(ADDTIME(COALESCE(j.jam_mulai, pj.jam_mulai), SEC_TO_TIME(:late_sec)))
    ';
    if (payroll_pembimbing_presensi_has_kegiatan_id($pdo)) {
        $lateSql = '
        SELECT COUNT(*)
        FROM presensi_pembimbing p
        ' . payroll_pembimbing_scan_jadwal_join_sql($pdo, 'p') . '
        WHERE p.pembimbing_id = :pembimbing_id
          AND p.tanggal BETWEEN :start_date AND :end_date
          AND p.jenis_scan = "DATANG"
          AND p.kegiatan_id IS NOT NULL
          AND COALESCE(j.jam_mulai, pj.jam_mulai) IS NOT NULL
          AND TIME_TO_SEC(p.jam) > TIME_TO_SEC(ADDTIME(COALESCE(j.jam_mulai, pj.jam_mulai), SEC_TO_TIME(:late_sec)))
    ';
    }
    $lateStmt = $pdo->prepare($lateSql);
    $lateStmt->execute([
        'pembimbing_id' => $pid,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'late_sec' => $lateTolerance * 60,
    ]);
    $telat = (int) $lateStmt->fetchColumn();

    if ($alpa === 0) {
        $kategori = 'Bagus';
    } elseif ($alpa <= 1) {
        $kategori = 'Baik';
    } elseif ($alpa <= 3) {
        $kategori = 'Sedang';
    } else {
        $kategori = 'Buruk';
    }

    $row['total_datang'] = $datang;
    $row['total_scan'] = $datang;
    $row['telat'] = $telat;
    $row['izin'] = $izin;
    $row['sakit'] = $sakit;
    $row['alpa'] = $alpa;
    $row['kategori'] = $kategori;

    $totalJam = (float) ($agg['total_jam'] ?? 0);
    $byKriteria = $jamKriteriaMap[$pid]['by_kriteria'] ?? [];
    $calc = payroll_pembimbing_compute_mixed(
        (float) ($row['gaji_pokok'] ?? 0),
        $byKriteria,
        $tarifMap
    );
    $row['total_jam'] = $totalJam > 0 ? $totalJam : $calc['total_jam'];
    $row['gaji_pokok_n'] = $calc['gaji_pokok'];
    $row['tarif_per_jam'] = $calc['tarif_per_jam'];
    $row['kriteria'] = $calc['kriteria'];
    $row['kriteria_label'] = $calc['kriteria_label'];
    $row['gaji_per_jam'] = $calc['gaji_per_jam'];
    $row['gaji_bulanan'] = (int) round($calc['total_gaji']);
}
unset($row);

$detailPembimbing = null;
$detailPresensiRows = [];
if ($detailPembimbingId > 0) {
    foreach ($rows as $r) {
        if ((int) ($r['pembimbing_id'] ?? 0) === $detailPembimbingId) {
            $detailPembimbing = $r;
            break;
        }
    }
    if ($detailPembimbing === null) {
        $stDet = $pdo->prepare('SELECT id AS pembimbing_id, nip, nama_pembimbing, gaji_pokok, tarif_kriteria FROM pembimbing WHERE id = :id LIMIT 1');
        $stDet->execute(['id' => $detailPembimbingId]);
        $detailPembimbing = $stDet->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if ($detailPembimbing !== null) {
        $detailPresensiRows = payroll_pembimbing_presensi_valid_rows($pdo, $detailPembimbingId, $startDate, $endDate, $kegiatanFilter, $jamMode);
    }
}

$namaPonpes = app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren');
$jenisPendidikan = app_setting($pdo, 'jenis_pendidikan', '');
$alamatPonpes = app_setting($pdo, 'alamat_ponpes', '-');
$logo = app_pondok_logo_href($pdo, false);
$telpPonpes = app_setting($pdo, 'telp_ponpes', '');
$websitePonpes = app_setting($pdo, 'website_ponpes', '');

$periodeLabelP = $periodLabel;
$periodeModePay = $calendarMode === 'hijriyah' ? 'HIJRIYAH' : 'MASEHI';
$paidGajiMap = payroll_pembimbing_paid_map($pdo, $periodeModePay, $month, $year);

$totalPembimbingRekap = count($rows);
$totalHadirRekap = 0;
$totalAlpaRekap = 0;
$totalTelatRekap = 0;
$totalGajiKeseluruhan = 0;
$totalGajiSudahBayar = 0;
$totalGajiBelumBayar = 0;
$kategoriBagus = 0;
foreach ($rows as $r) {
    $totalHadirRekap += (int) ($r['total_datang'] ?? 0);
    $totalAlpaRekap += (int) ($r['alpa'] ?? 0);
    $totalTelatRekap += (int) ($r['telat'] ?? 0);
    if (($r['kategori'] ?? '') === 'Bagus') {
        $kategoriBagus++;
    }
    $gajiRow = (int) ($r['gaji_bulanan'] ?? 0);
    $totalGajiKeseluruhan += $gajiRow;
    $pidRowSum = (int) ($r['pembimbing_id'] ?? 0);
    if (isset($paidGajiMap[$pidRowSum])) {
        $totalGajiSudahBayar += $gajiRow;
    } else {
        $totalGajiBelumBayar += $gajiRow;
    }
}
$akunRowsBayar = keuangan_fetch_akun_aktif($pdo);
$defaultAkunBayar = 0;
foreach ($akunRowsBayar as $ar) {
    if ((int) ($ar['is_default'] ?? 0) === 1) {
        $defaultAkunBayar = (int) ($ar['id'] ?? 0);
        break;
    }
}
if ($defaultAkunBayar <= 0 && $akunRowsBayar !== []) {
    $defaultAkunBayar = (int) ($akunRowsBayar[0]['id'] ?? 0);
}

if (($_GET['download'] ?? '') === 'xlsx') {
    require_once __DIR__ . '/../helpers/excel.php';
    $exportRekap = [];
    foreach ($rows as $r) {
        $pidExport = (int) ($r['pembimbing_id'] ?? 0);
        $exportRekap[] = array_merge($r, [
            'status_bayar' => isset($paidGajiMap[$pidExport])
                ? 'Lunas ' . (string) ($paidGajiMap[$pidExport]['tanggal_bayar'] ?? '')
                : 'Belum',
        ]);
    }
    $detailExport = payroll_pembimbing_presensi_export_rows($pdo, $startDate, $endDate, $kegiatanFilter, $jamMode);
    $rentangExport = (string) ($period['rentang_tampilan'] ?? ($startDate . ' s/d ' . $endDate));
    $fn = 'presensi_pembimbing_' . sprintf('%04d', $year) . '_' . sprintf('%02d', $month) . '.xlsx';
    send_xlsx_download(
        $fn,
        payroll_pembimbing_build_xlsx_rows($periodLabel, $rentangExport, $exportRekap, $detailExport, $jamMode),
        'Presensi Pembimbing'
    );
    exit;
}

$pageTitle = (defined('PAYROLL_FROM_KEUANGAN') && PAYROLL_FROM_KEUANGAN) ? 'Payroll Pembimbing' : 'Rekap Pembimbing (Admin)';
require_once __DIR__ . '/../includes/header.php';
$payrollQueryBase = http_build_query(array_filter([
    'cal' => $calendarMode,
    'month' => $month,
    'year' => $year,
    'kegiatan_id' => $kegiatanFilter > 0 ? $kegiatanFilter : null,
    'paper' => $paper !== 'A4' ? $paper : null,
    'jam_mode' => $jamMode !== 'jadwal' ? $jamMode : null,
]));
?>

<div class="page-intro mb-3 print-controls">
    <?php if (defined('PAYROLL_FROM_KEUANGAN') && PAYROLL_FROM_KEUANGAN): ?>
        <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/keuangan/index.php')) ?>">Keuangan</a></p>
        <h1 class="h4 mb-1">Payroll pembimbing</h1>
    <?php else: ?>
        <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/rekap/presensi.php')) ?>">Rekap Presensi</a></p>
        <h1 class="h4 mb-1">Rekap kehadiran pembimbing</h1>
    <?php endif; ?>
    <p class="text-muted mb-0">Rekap bulanan kehadiran pembimbing — periode <strong><?= htmlspecialchars($periodeLabelP) ?></strong> (<?= htmlspecialchars($periodBridge) ?>), toleransi telat <?= (int) $lateTolerance ?> menit. Metode hitung jam: <strong><?= htmlspecialchars($jamModeLabel) ?></strong>. Gaji per jam mengikuti <a href="<?= htmlspecialchars(app_href('/settings/payroll_kegiatan.php')) ?>">beban payroll per kegiatan Ta'lim</a>. Unduh <strong>XLSX</strong> untuk rekap + detail presensi per bulan.</p>
</div>

<div class="row g-3 mb-3 print-controls">
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total pembimbing</div>
            <div class="app-mini-stat-value"><?= $totalPembimbingRekap ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total hadir</div>
            <div class="app-mini-stat-value text-success"><?= $totalHadirRekap ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total telat</div>
            <div class="app-mini-stat-value text-warning"><?= $totalTelatRekap ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total alpa</div>
            <div class="app-mini-stat-value text-danger"><?= $totalAlpaRekap ?></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-3 print-controls">
    <div class="col-12 col-md-4">
        <div class="app-mini-stat h-100 border border-success border-opacity-25">
            <div class="app-mini-stat-label">Total gaji periode</div>
            <div class="app-mini-stat-value text-success" style="font-size:1.15rem;"><?= htmlspecialchars(keuangan_format_rupiah($totalGajiKeseluruhan)) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Sudah dibayar</div>
            <div class="app-mini-stat-value"><?= htmlspecialchars(keuangan_format_rupiah($totalGajiSudahBayar)) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="app-mini-stat h-100 border border-warning border-opacity-25">
            <div class="app-mini-stat-label">Belum dibayar</div>
            <div class="app-mini-stat-value text-warning" style="font-size:1.15rem;"><?= htmlspecialchars(keuangan_format_rupiah($totalGajiBelumBayar)) ?></div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-4 print-controls">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end" id="payroll-filter-form">
            <input type="hidden" name="previous_mode" id="previous-mode" value="<?= htmlspecialchars($calendarMode) ?>">
            <input type="hidden" name="anchor_masehi_year" id="anchor-masehi-year" value="<?= (int) $anchorMasehiYear ?>">
            <div class="col-6 col-md-2">
                <label class="form-label">Kalender</label>
                <select class="form-select" name="cal" id="mode-kalender">
                    <option value="masehi" <?= $calendarMode === 'masehi' ? 'selected' : '' ?>>Masehi</option>
                    <option value="hijriyah" <?= $calendarMode === 'hijriyah' ? 'selected' : '' ?>>Hijriyah</option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">Bulan</label>
                <select class="form-select" name="month" id="periode-bulan">
                    <?php $activeMonthList = $calendarMode === 'hijriyah' ? $hijriyahMonths : $masehiMonths; ?>
                    <?php foreach ($activeMonthList as $monthNumber => $monthLabel): ?>
                        <option value="<?= (int) $monthNumber ?>" <?= $month === (int) $monthNumber ? 'selected' : '' ?>>
                            <?= htmlspecialchars($monthLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Tahun</label>
                <input class="form-control" type="number"
                       min="<?= (int) $yearMin ?>" max="<?= (int) $yearMax ?>"
                       name="year" id="periode-tahun"
                       value="<?= htmlspecialchars((string) $year) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Kegiatan</label>
                <select class="form-select" name="kegiatan_id">
                    <option value="0">Semua kegiatan</option>
                    <?php foreach ($kegiatanList as $kg): ?>
                        <?php $kgId = (int) ($kg['id'] ?? 0); ?>
                        <option value="<?= $kgId ?>" <?= $kegiatanFilter === $kgId ? 'selected' : '' ?>><?= htmlspecialchars((string) ($kg['nama_kegiatan'] ?? '-')) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">Hitung jam</label>
                <select class="form-select" name="jam_mode" title="Cara menghitung jam kerja untuk gaji per jam">
                    <?php foreach ($jamModeLabels as $jmKey => $jmLabel): ?>
                        <option value="<?= htmlspecialchars($jmKey) ?>" <?= $jamMode === $jmKey ? 'selected' : '' ?>><?= htmlspecialchars($jmLabel) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">Jadwal = durasi slot · Scan = 1 jam/hadir</div>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Kertas</label>
                <select class="form-select" name="paper">
                    <option value="A4" <?= $paper === 'A4' ? 'selected' : '' ?>>A4</option>
                    <option value="F4" <?= $paper === 'F4' ? 'selected' : '' ?>>F4</option>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <button class="btn btn-success w-100">Tampilkan</button>
            </div>
            <div class="col-6 col-md-2">
                <a class="btn btn-outline-success w-100"
                   href="<?= htmlspecialchars(app_href($payrollPageBase . '?' . ($payrollQueryBase !== '' ? $payrollQueryBase . '&' : '') . 'download=xlsx')) ?>">
                    <i class="fa-solid fa-file-excel me-1"></i> XLSX
                </a>
            </div>
            <div class="col-12 col-md-2">
                <button type="button" class="btn btn-outline-dark w-100" onclick="window.print()">Cetak</button>
            </div>
        </form>
    </div>
</div>

<div class="print-kop print-header">
    <div class="print-kop-row">
        <?php if ($logo): ?>
            <img src="<?= htmlspecialchars($logo) ?>" alt="logo" class="print-kop-logo">
        <?php endif; ?>
        <div class="print-kop-brand">
            <p class="print-kop-small"><?= htmlspecialchars($jenisPendidikan !== '' ? $jenisPendidikan : 'Lembaga Pondok Pesantren') ?></p>
            <h1 class="print-kop-title"><?= htmlspecialchars($namaPonpes) ?></h1>
            <p class="print-kop-addr"><?= htmlspecialchars($alamatPonpes) ?></p>
            <?php if ($telpPonpes !== '' || $websitePonpes !== ''): ?>
                <p class="print-kop-contact">
                    <?php if ($telpPonpes !== ''): ?>Telp: <?= htmlspecialchars($telpPonpes) ?><?php endif; ?>
                    <?php if ($telpPonpes !== '' && $websitePonpes !== ''): ?> | <?php endif; ?>
                    <?php if ($websitePonpes !== ''): ?>Website: <?= htmlspecialchars($websitePonpes) ?><?php endif; ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
    <p class="print-kop-meta">Rekap Pembimbing <?= htmlspecialchars($periodeLabelP) ?></p>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <h2 class="h5 mb-0">Rekap kehadiran pembimbing</h2>
            <span class="small text-muted print-controls"><?= $kategoriBagus ?> kategori &ldquo;Bagus&rdquo; dari <?= $totalPembimbingRekap ?> pembimbing</span>
        </div>
        <div class="table-responsive app-table-mobile">
            <table class="table table-sm table-striped table-hover align-middle mb-0">
                <thead>
                <tr>
                    <th>NIP</th>
                    <th>Nama Pembimbing</th>
                    <th class="text-center">Hadir</th>
                    <th class="text-center">Izin</th>
                    <th class="text-center">Sakit</th>
                    <th class="text-center">Alpa</th>
                    <th class="text-center">Telat</th>
                    <th class="text-center" title="<?= htmlspecialchars($jamModeLabel) ?>">Jam Kegiatan</th>
                    <th class="text-nowrap">Kriteria Beban</th>
                    <th class="text-end text-nowrap">Tarif efektif/jam</th>
                    <th class="text-end text-nowrap">Gaji pokok</th>
                    <th class="text-end text-nowrap">Gaji per jam</th>
                    <th class="text-end text-nowrap">Total gaji</th>
                    <th class="text-end">Aksi</th>
                    <th>Kategori</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($rows): ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $kat = (string) ($row['kategori'] ?? '-');
                        $katBadge = 'secondary';
                        if ($kat === 'Bagus') { $katBadge = 'success'; }
                        elseif ($kat === 'Baik') { $katBadge = 'info'; }
                        elseif ($kat === 'Sedang') { $katBadge = 'warning'; }
                        elseif ($kat === 'Buruk') { $katBadge = 'danger'; }
                        ?>
                        <tr>
                            <td class="font-monospace small"><?= htmlspecialchars($row['nip']) ?></td>
                            <td class="fw-semibold"><?= htmlspecialchars($row['nama_pembimbing']) ?></td>
                            <td class="text-center"><?= (int) $row['total_datang'] ?></td>
                            <td class="text-center"><?= (int) $row['izin'] ?></td>
                            <td class="text-center"><?= (int) $row['sakit'] ?></td>
                            <td class="text-center"><?= (int) $row['alpa'] ?></td>
                            <td class="text-center"><?= (int) ($row['telat'] ?? 0) ?></td>
                            <td class="text-center"><?= number_format((float) ($row['total_jam'] ?? 0), 2, ',', '.') ?></td>
                            <td><span class="badge text-bg-light border small"><?= htmlspecialchars((string) ($row['kriteria_label'] ?? '-')) ?></span></td>
                            <td class="text-end"><?= htmlspecialchars(keuangan_format_rupiah((int) round((float) ($row['tarif_per_jam'] ?? 0)))) ?></td>
                            <td class="text-end"><?= htmlspecialchars(keuangan_format_rupiah((int) round((float) ($row['gaji_pokok_n'] ?? 0)))) ?></td>
                            <td class="text-end"><?= htmlspecialchars(keuangan_format_rupiah((int) round((float) ($row['gaji_per_jam'] ?? 0)))) ?></td>
                            <td class="text-end fw-semibold"><?= htmlspecialchars(keuangan_format_rupiah((int) ($row['gaji_bulanan'] ?? 0))) ?></td>
                            <td class="text-end text-nowrap">
                                <?php
                                $pidRow = (int) ($row['pembimbing_id'] ?? 0);
                                $sudahBayar = isset($paidGajiMap[$pidRow]);
                                $detailQs = $payrollQueryBase !== '' ? ($payrollQueryBase . '&detail=' . $pidRow) : ('detail=' . $pidRow);
                                ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars(app_href($payrollPageBase . '?' . $detailQs)) ?>">Detail</a>
                                <?php if ($sudahBayar): ?>
                                    <span class="badge text-bg-success"><i class="fa-solid fa-check me-1"></i>Lunas</span>
                                    <div class="small text-muted"><?= htmlspecialchars((string) ($paidGajiMap[$pidRow]['tanggal_bayar'] ?? '')) ?></div>
                                <?php elseif ((int) ($row['gaji_bulanan'] ?? 0) > 0): ?>
                                    <button type="button" class="btn btn-sm btn-outline-success btn-bayar-gaji"
                                            data-bs-toggle="modal" data-bs-target="#modalBayarGaji"
                                            data-pembimbing-id="<?= $pidRow ?>"
                                            data-nama="<?= htmlspecialchars((string) ($row['nama_pembimbing'] ?? ''), ENT_QUOTES) ?>"
                                            data-nominal="<?= (int) ($row['gaji_bulanan'] ?? 0) ?>">
                                        Bayar
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted small">—</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge text-bg-<?= $katBadge ?>"><?= htmlspecialchars($kat) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="15" class="text-center text-muted">Belum ada data pembimbing pada periode ini.</td></tr>
                <?php endif; ?>
                </tbody>
                <?php if ($rows): ?>
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td colspan="2">Total keseluruhan</td>
                        <td class="text-center"><?= $totalHadirRekap ?></td>
                        <td colspan="3"></td>
                        <td class="text-center"><?= $totalTelatRekap ?></td>
                        <td colspan="4"></td>
                        <td class="text-end"><?= htmlspecialchars(keuangan_format_rupiah($totalGajiKeseluruhan)) ?></td>
                        <td class="text-end small text-muted">
                            Lunas: <?= htmlspecialchars(keuangan_format_rupiah($totalGajiSudahBayar)) ?><br>
                            Sisa: <?= htmlspecialchars(keuangan_format_rupiah($totalGajiBelumBayar)) ?>
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<?php if ($detailPembimbing !== null): ?>
    <?php
    $validCount = 0;
    $validJam = 0.0;
    foreach ($detailPresensiRows as $dr) {
        if ($jamMode === 'per_scan' || !empty($dr['valid_jadwal'])) {
            if (!empty($dr['valid_jadwal'])) {
                $validCount++;
            }
            $validJam += (float) ($dr['jam_hitung'] ?? 0);
        }
    }
    ?>
    <div class="card shadow-sm mb-4 print-controls" id="payroll-detail">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <div>
                    <h2 class="h5 mb-1">Rincian presensi — <?= htmlspecialchars((string) ($detailPembimbing['nama_pembimbing'] ?? '')) ?></h2>
                    <p class="small text-muted mb-0">Periode <?= htmlspecialchars($periodeLabelP) ?> · <?= count($detailPresensiRows) ?> scan DATANG · <?= $validCount ?> valid jadwal · <?= number_format($validJam, 2, ',', '.') ?> jam dihitung · metode: <?= htmlspecialchars($jamModeLabel) ?></p>
                </div>
                <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_href($payrollPageBase . ($payrollQueryBase !== '' ? '?' . $payrollQueryBase : ''))) ?>">Tutup detail</a>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Tanggal</th>
                            <th>Jam scan</th>
                            <th>Kegiatan</th>
                            <th>Kriteria</th>
                            <th>Jadwal</th>
                            <th class="text-center">Jam hitung</th>
                            <th class="text-end">Gaji baris</th>
                            <th class="text-center">Valid</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if ($detailPresensiRows === []): ?>
                        <tr><td colspan="8" class="text-muted text-center py-3">Tidak ada presensi DATANG pada periode ini.</td></tr>
                    <?php else: ?>
                        <?php foreach ($detailPresensiRows as $dr): ?>
                            <tr class="<?= empty($dr['valid_jadwal']) ? 'table-warning' : '' ?>">
                                <td><?= htmlspecialchars((string) $dr['tanggal']) ?></td>
                                <td class="font-monospace small"><?= htmlspecialchars(substr((string) ($dr['jam'] ?? ''), 0, 5) ?: '—') ?></td>
                                <td><?= htmlspecialchars((string) $dr['nama_kegiatan']) ?></td>
                                <td><span class="badge text-bg-light border"><?= htmlspecialchars((string) ($dr['payroll_kriteria_label'] ?? '-')) ?></span></td>
                                <td class="small font-monospace">
                                    <?php if (!empty($dr['jadwal_mulai'])): ?>
                                        <?= htmlspecialchars(substr((string) $dr['jadwal_mulai'], 0, 5)) ?>–<?= htmlspecialchars(substr((string) $dr['jadwal_selesai'], 0, 5)) ?>
                                    <?php else: ?>
                                        <span class="text-muted">Tidak cocok jadwal</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?= number_format((float) ($dr['jam_hitung'] ?? 0), 2, ',', '.') ?></td>
                                <td class="text-end"><?= htmlspecialchars(keuangan_format_rupiah((int) ($dr['gaji_baris'] ?? 0))) ?></td>
                                <td class="text-center">
                                    <?php if (!empty($dr['valid_jadwal'])): ?>
                                        <span class="badge text-bg-success">Ya</span>
                                    <?php else: ?>
                                        <span class="badge text-bg-warning">Tidak</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="modal fade" id="modalBayarGaji" tabindex="-1" aria-labelledby="modalBayarGajiLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="bayar_gaji">
            <input type="hidden" name="pembimbing_id" id="bayar-pembimbing-id" value="">
            <input type="hidden" name="month" value="<?= (int) $month ?>">
            <input type="hidden" name="year" value="<?= (int) $year ?>">
            <input type="hidden" name="cal" value="<?= htmlspecialchars($calendarMode) ?>">
            <input type="hidden" name="kegiatan_id" value="<?= (int) $kegiatanFilter ?>">
            <input type="hidden" name="paper" value="<?= htmlspecialchars($paper) ?>">
            <input type="hidden" name="jam_mode" value="<?= htmlspecialchars($jamMode) ?>">
            <input type="hidden" name="detail" value="<?= (int) $detailPembimbingId ?>">
            <div class="modal-header">
                <h2 class="modal-title h5" id="modalBayarGajiLabel">Bayar gaji pembimbing</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-2">Periode <strong><?= htmlspecialchars($periodeLabelP) ?></strong>. Pembayaran otomatis tercatat sebagai pengeluaran kas (arus kas berkurang).</p>
                <p class="mb-3 fw-semibold" id="bayar-pembimbing-nama">—</p>
                <div class="mb-2">
                    <label class="form-label">Tanggal bayar</label>
                    <input type="date" class="form-control" name="tanggal_bayar" value="<?= htmlspecialchars(date('Y-m-d')) ?>" required>
                </div>
                <div class="mb-2">
                    <label class="form-label">Nominal (Rp)</label>
                    <input type="number" class="form-control" name="nominal_bayar" id="bayar-nominal" min="1" required>
                </div>
                <?php if ($akunRowsBayar !== []): ?>
                <div class="mb-2">
                    <label class="form-label">Akun kas/bank</label>
                    <select class="form-select" name="akun_id">
                        <?php foreach ($akunRowsBayar as $ar): ?>
                            <option value="<?= (int) ($ar['id'] ?? 0) ?>" <?= (int) ($ar['id'] ?? 0) === $defaultAkunBayar ? 'selected' : '' ?>>
                                <?= htmlspecialchars((string) ($ar['nama_akun'] ?? '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="btn btn-success" onclick="return confirm('Catat pembayaran gaji dan kurangi arus kas?')">Bayar &amp; catat</button>
            </div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('.btn-bayar-gaji').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var idEl = document.getElementById('bayar-pembimbing-id');
        var namaEl = document.getElementById('bayar-pembimbing-nama');
        var nominalEl = document.getElementById('bayar-nominal');
        if (idEl) { idEl.value = btn.getAttribute('data-pembimbing-id') || ''; }
        if (namaEl) { namaEl.textContent = btn.getAttribute('data-nama') || '—'; }
        if (nominalEl) { nominalEl.value = btn.getAttribute('data-nominal') || ''; }
    });
});
(function () {
    const form = document.getElementById('payroll-filter-form');
    const modeSelect = document.getElementById('mode-kalender');
    const yearInput = document.getElementById('periode-tahun');
    const previousModeInput = document.getElementById('previous-mode');
    const anchorMasehiYearInput = document.getElementById('anchor-masehi-year');
    if (!form || !modeSelect) {
        return;
    }
    const initialMode = modeSelect.value;
    modeSelect.addEventListener('change', function () {
        if (previousModeInput) {
            previousModeInput.value = initialMode;
        }
        if (anchorMasehiYearInput && yearInput && initialMode === 'masehi') {
            anchorMasehiYearInput.value = yearInput.value || '<?= (int) $currentMasehiYear ?>';
        }
        if (yearInput) {
            if (modeSelect.value === 'hijriyah') {
                yearInput.min = '1300';
                yearInput.max = '1700';
                yearInput.readOnly = false;
                const y = parseInt(yearInput.value || '0', 10);
                if (!y || y < 1300 || y > 1700) {
                    yearInput.value = '<?= (int) $currentHijriYear ?>';
                }
            } else {
                yearInput.min = '1900';
                yearInput.max = '2100';
                yearInput.readOnly = false;
                const y = parseInt(yearInput.value || '0', 10);
                if (!y || y < 1900 || y > 2100) {
                    yearInput.value = '<?= (int) $currentMasehiYear ?>';
                }
            }
        }
        form.submit();
    });
})();
</script>

<style>
    @media print {
        @page { size: <?= $paper === 'F4' ? '8.5in 13in' : 'A4' ?> portrait; margin: 12mm; }
        .navbar, .app-sidebar, .offcanvas, .print-controls { display: none !important; }
        .card { border: 1px solid #cbd5e1 !important; box-shadow: none !important; }
        body { background: #fff !important; }
    }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
