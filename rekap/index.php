<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/akademik.php';
require_once __DIR__ . '/../helpers/hijri_kalender.php';
require_once __DIR__ . '/../helpers/santri_operasional.php';
require_once __DIR__ . '/../helpers/presensi_jadwal.php';

require_roles(['admin', 'pengurus']);

if (!table_exists($pdo, 'presensi')) {
    set_flash('error', 'Tabel presensi belum ada.');
    header('Location: ' . app_href('/dashboard.php'));
    exit;
}

require_once __DIR__ . '/../helpers/pondok_kalender.php';
$defaultRekapMode = pondok_kalender_hijriyah($pdo) ? 'hijriyah' : 'masehi';
$mode = $_GET['mode'] ?? $defaultRekapMode;
if (!in_array($mode, ['masehi', 'hijriyah'], true)) {
    $mode = $defaultRekapMode;
}
$previousMode = $_GET['previous_mode'] ?? $mode;
if (!in_array($previousMode, ['masehi', 'hijriyah'], true)) {
    $previousMode = $mode;
}
$appTahunMasehiDefault = app_tahun_masehi_default($pdo);
$tahunMasehiLiteralBerjalan = (int) date('Y');
$anchorMasehiYear = (int) ($_GET['anchor_masehi_year'] ?? $appTahunMasehiDefault);
$month = (int) ($_GET['month'] ?? date('m'));
$year = (int) ($_GET['year'] ?? $appTahunMasehiDefault);
$month = max(1, min(12, $month));
if ($previousMode !== $mode) {
    if ($previousMode === 'masehi' && $mode === 'hijriyah') {
        $anchorMasehiYear = $year;
        $convertedHijriYm = get_hijri_ym_from_gregorian_month($year, $month);
        $year = (int) substr($convertedHijriYm, 0, 4);
        $month = (int) substr($convertedHijriYm, 5, 2);
    } elseif ($previousMode === 'hijriyah' && $mode === 'masehi') {
        [$convertedStartDate, $convertedEndDate] = akademik_gregorian_range_from_hijri_month($pdo, $year, $month);
        $year = (int) date('Y', strtotime($convertedStartDate));
        $month = (int) date('m', strtotime($convertedStartDate));
    }
}
$currentMasehiYear = $tahunMasehiLiteralBerjalan;
$anchorHijriIni = akademik_hijri_anchor_hari_ini($pdo);
$currentHijriYm = sprintf('%04d-%02d', $anchorHijriIni['y'], $anchorHijriIni['m']);
$currentHijriYear = $anchorHijriIni['y'];
$yearMin = $mode === 'hijriyah' ? 1300 : 1900;
$yearMax = $mode === 'hijriyah' ? 1700 : 2100;
if ($year <= 0) {
    $year = $mode === 'hijriyah' ? $currentHijriYear : $appTahunMasehiDefault;
}
$year = max($yearMin, min($yearMax, $year));
if ($mode === 'masehi') {
    $anchorMasehiYear = $year;
} else {
    $anchorMasehiYear = max(1900, min(2100, $anchorMasehiYear));
    $convertedHijriFromAnchor = get_hijri_ym_from_gregorian_month($anchorMasehiYear, $month);
    $year = (int) substr($convertedHijriFromAnchor, 0, 4);
}
$tingkatan = trim($_GET['tingkatan'] ?? '');
$reportType = trim($_GET['report_type'] ?? 'all');
$show = ($_GET['show'] ?? '1') === '1';
$paper = strtoupper((string) ($_GET['paper'] ?? 'A4'));
if (!in_array($paper, ['A4', 'F4'], true)) {
    $paper = 'A4';
}

$tingkatanList = table_exists($pdo, 'tingkatan')
    ? $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY nama_tingkatan ASC')->fetchAll(PDO::FETCH_COLUMN)
    : [];

$goodMax = (int) app_setting($pdo, 'kategori_baik_max', '1');
$mediumMax = (int) app_setting($pdo, 'kategori_sedang_max', '3');
$namaPonpes = app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren');
$jenisPendidikan = app_setting($pdo, 'jenis_pendidikan', '');
$alamatPonpes = app_setting($pdo, 'alamat_ponpes', '-');
$logo = app_pondok_logo_href($pdo, false);
$telpPonpes = app_setting($pdo, 'telp_ponpes', '');
$websitePonpes = app_setting($pdo, 'website_ponpes', '');
$masehiMonths = [
    1 => 'Januari',
    2 => 'Februari',
    3 => 'Maret',
    4 => 'April',
    5 => 'Mei',
    6 => 'Juni',
    7 => 'Juli',
    8 => 'Agustus',
    9 => 'September',
    10 => 'Oktober',
    11 => 'November',
    12 => 'Desember',
];
$hijriyahMonths = hijri_nama_bulan_list();
$monthName = $mode === 'hijriyah' ? ($hijriyahMonths[$month] ?? '-') : ($masehiMonths[$month] ?? '-');
$hijriEquivalent = get_hijri_ym_from_gregorian_month($year, $month);
[$hijriToGregorianStart, $hijriToGregorianEnd] = akademik_gregorian_range_from_hijri_month($pdo, $year, $month);
$hijriEquivalentMonth = (int) substr($hijriEquivalent, 5, 2);
$hijriEquivalentYear = (int) substr($hijriEquivalent, 0, 4);
$hijriEquivalentLabel = ($hijriyahMonths[$hijriEquivalentMonth] ?? $hijriEquivalentMonth) . ' ' . $hijriEquivalentYear;
$masehiEquivalentMonth = (int) date('m', strtotime($hijriToGregorianStart));
$masehiEquivalentYear = (int) date('Y', strtotime($hijriToGregorianStart));
$masehiEquivalentLabel = ($masehiMonths[$masehiEquivalentMonth] ?? $masehiEquivalentMonth) . ' ' . $masehiEquivalentYear;
$periodBridgeLabel = $mode === 'masehi'
    ? ($monthName . ' ' . $year . ' <-> ' . $hijriEquivalentLabel)
    : ($masehiEquivalentLabel . ' <-> ' . $monthName . ' ' . $year);

$sqlAktifSantriRekap = santri_sql_aktif_only('s');

$rows = [];
if ($show) {
    $filterStartPre = $mode === 'hijriyah' ? $hijriToGregorianStart : sprintf('%04d-%02d-01', $year, $month);
    $filterEndPre = $mode === 'hijriyah' ? $hijriToGregorianEnd : date('Y-m-t', strtotime($filterStartPre));
    $auditUserId = (int) ($_SESSION['user']['id'] ?? 1);
    presensi_finalize_date_range($pdo, $filterStartPre, $filterEndPre, $auditUserId > 0 ? $auditUserId : 1);

    if ($mode === 'hijriyah') {
        $statement = $pdo->prepare('
            SELECT
                p.tanggal_presensi,
                p.status_presensi,
                p.catatan,
                s.id AS santri_id,
                s.nama_santri,
                s.tingkatan,
                p.kegiatan_id,
                COALESCE(k.nama_kegiatan, "Tanpa Kegiatan") AS nama_kegiatan
            FROM presensi p
            INNER JOIN santri s ON s.id = p.santri_id
            LEFT JOIN kegiatan k ON k.id = p.kegiatan_id
            WHERE p.tanggal_presensi BETWEEN :start_date AND :end_date
              AND p.kalender_hijriyah = :kalender_hijriyah
              AND ' . $sqlAktifSantriRekap . '
        ');
        $statement->execute([
            'start_date' => $hijriToGregorianStart,
            'end_date' => $hijriToGregorianEnd,
            'kalender_hijriyah' => sprintf('%04d-%02d', $year, $month),
        ]);
        $rows = $statement->fetchAll();
        if ($tingkatan !== '') {
            $rows = array_values(array_filter($rows, static function ($item) use ($tingkatan): bool {
                return strtolower((string) $item['tingkatan']) === strtolower($tingkatan);
            }));
        }
    } else {
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));
        $statement = $pdo->prepare('
            SELECT
                p.tanggal_presensi,
                p.status_presensi,
                p.catatan,
                s.id AS santri_id,
                s.nama_santri,
                s.tingkatan,
                p.kegiatan_id,
                COALESCE(k.nama_kegiatan, "Tanpa Kegiatan") AS nama_kegiatan
            FROM presensi p
            INNER JOIN santri s ON s.id = p.santri_id
            LEFT JOIN kegiatan k ON k.id = p.kegiatan_id
            WHERE p.tanggal_presensi BETWEEN :start_date AND :end_date
              AND ' . $sqlAktifSantriRekap . '
        ');
        $statement->execute([
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);
        $rows = $statement->fetchAll();
        if ($tingkatan !== '') {
            $rows = array_values(array_filter($rows, static function ($item) use ($tingkatan): bool {
                return strtolower((string) $item['tingkatan']) === strtolower($tingkatan);
            }));
        }
    }

    $filterStart = $mode === 'hijriyah' ? $hijriToGregorianStart : sprintf('%04d-%02d-01', $year, $month);
    $filterEnd = $mode === 'hijriyah' ? $hijriToGregorianEnd : date('Y-m-t', strtotime($filterStart));
    $rows = presensi_filter_rows_eligible($pdo, $rows, $filterStart, $filterEnd);
}

$byTingkatan = [];
$bySantri = [];
$byTingkatanKegiatan = [];
$bySantriKegiatan = [];
$byKegiatan = [];
foreach ($rows as $row) {
    $tg = $row['tingkatan'] ?: '-';
    $kegiatanLabel = trim((string) ($row['nama_kegiatan'] ?? '')) !== '' ? (string) $row['nama_kegiatan'] : 'Tanpa Kegiatan';
    if (!isset($byTingkatan[$tg])) {
        $byTingkatan[$tg] = ['HADIR' => 0, 'ALPA' => 0, 'IZIN' => 0, 'SAKIT' => 0, 'TELAT' => 0];
    }
    if (!isset($byTingkatan[$tg][$row['status_presensi']])) {
        $byTingkatan[$tg][$row['status_presensi']] = 0;
    }
    $byTingkatan[$tg][$row['status_presensi']]++;
    $isLate = stripos((string) ($row['catatan'] ?? ''), 'terlambat') !== false;
    if ($isLate) {
        $byTingkatan[$tg]['TELAT']++;
    }
    if (!isset($byTingkatanKegiatan[$tg])) {
        $byTingkatanKegiatan[$tg] = [];
    }
    if (!isset($byTingkatanKegiatan[$tg][$kegiatanLabel])) {
        $byTingkatanKegiatan[$tg][$kegiatanLabel] = ['HADIR' => 0, 'ALPA' => 0, 'IZIN' => 0, 'SAKIT' => 0, 'TELAT' => 0];
    }
    if (!isset($byTingkatanKegiatan[$tg][$kegiatanLabel][$row['status_presensi']])) {
        $byTingkatanKegiatan[$tg][$kegiatanLabel][$row['status_presensi']] = 0;
    }
    $byTingkatanKegiatan[$tg][$kegiatanLabel][$row['status_presensi']]++;
    if ($isLate) {
        $byTingkatanKegiatan[$tg][$kegiatanLabel]['TELAT']++;
    }

    $sid = (int) $row['santri_id'];
    if (!isset($bySantri[$sid])) {
        $bySantri[$sid] = [
            'nama' => $row['nama_santri'],
            'tingkatan' => $tg,
            'HADIR' => 0,
            'ALPA' => 0,
            'IZIN' => 0,
            'SAKIT' => 0,
            'TELAT' => 0,
        ];
    }
    if (!isset($bySantriKegiatan[$sid])) {
        $bySantriKegiatan[$sid] = [];
    }
    if (!isset($bySantriKegiatan[$sid][$kegiatanLabel])) {
        $bySantriKegiatan[$sid][$kegiatanLabel] = ['HADIR' => 0, 'ALPA' => 0, 'IZIN' => 0, 'SAKIT' => 0, 'TELAT' => 0];
    }
    if (!isset($bySantri[$sid][$row['status_presensi']])) {
        $bySantri[$sid][$row['status_presensi']] = 0;
    }
    if (!isset($bySantriKegiatan[$sid][$kegiatanLabel][$row['status_presensi']])) {
        $bySantriKegiatan[$sid][$kegiatanLabel][$row['status_presensi']] = 0;
    }
    $bySantri[$sid][$row['status_presensi']]++;
    $bySantriKegiatan[$sid][$kegiatanLabel][$row['status_presensi']]++;
    if ($isLate) {
        $bySantri[$sid]['TELAT']++;
        $bySantriKegiatan[$sid][$kegiatanLabel]['TELAT']++;
    }

    if (!isset($byKegiatan[$kegiatanLabel])) {
        $byKegiatan[$kegiatanLabel] = [
            'HADIR' => 0,
            'ALPA' => 0,
            'IZIN' => 0,
            'SAKIT' => 0,
            'TELAT' => 0,
            'santri' => [],
        ];
    }
    if (!isset($byKegiatan[$kegiatanLabel][$row['status_presensi']])) {
        $byKegiatan[$kegiatanLabel][$row['status_presensi']] = 0;
    }
    $byKegiatan[$kegiatanLabel][$row['status_presensi']]++;
    if ($isLate) {
        $byKegiatan[$kegiatanLabel]['TELAT']++;
    }
    $byKegiatan[$kegiatanLabel]['santri'][$sid] = [
        'nama' => $row['nama_santri'],
        'tingkatan' => $tg,
    ];
}

/** Grafik perbandingan 12 bulan (berdasarkan tanggal presensi Masehi). */
$chartLabelsYm = [];
$chartLabelsHuman = [];
$chartStackHadir = [];
$chartStackIzin = [];
$chartStackSakit = [];
$chartStackAlpa = [];
$chartLineTelat = [];
$chartLinePersenHadir = [];
if ($show) {
    if ($mode === 'masehi') {
        $chartAnchorTs = strtotime(sprintf('%04d-%02d-01', $year, $month));
    } else {
        $chartAnchorTs = strtotime(date('Y-m-01', strtotime($hijriToGregorianEnd)));
    }
    if ($chartAnchorTs === false) {
        $chartAnchorTs = time();
    }
    $namaBulanSingkat = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    for ($i = 11; $i >= 0; $i--) {
        $ts = strtotime('-' . $i . ' months', $chartAnchorTs);
        if ($ts === false) {
            continue;
        }
        $ym = date('Y-m', $ts);
        $chartLabelsYm[] = $ym;
        $chartLabelsHuman[] = $namaBulanSingkat[(int) date('n', $ts)] . ' ' . date('Y', $ts);
    }
    if ($chartLabelsYm !== []) {
        $rangeStart = $chartLabelsYm[0] . '-01';
        $rangeEnd = date('Y-m-t', strtotime(end($chartLabelsYm) . '-01'));
        $sqlChart = '
            SELECT DATE_FORMAT(p.tanggal_presensi, "%Y-%m") AS ym,
                SUM(CASE WHEN p.status_presensi = "HADIR" THEN 1 ELSE 0 END) AS hadir,
                SUM(CASE WHEN p.status_presensi = "IZIN" THEN 1 ELSE 0 END) AS izin,
                SUM(CASE WHEN p.status_presensi = "SAKIT" THEN 1 ELSE 0 END) AS sakit,
                SUM(CASE WHEN p.status_presensi = "ALPA" THEN 1 ELSE 0 END) AS alpa,
                SUM(CASE WHEN p.catatan IS NOT NULL AND LOWER(p.catatan) LIKE "%terlambat%" THEN 1 ELSE 0 END) AS telat
            FROM presensi p
            INNER JOIN santri s ON s.id = p.santri_id
            WHERE p.tanggal_presensi BETWEEN :start_date AND :end_date
              AND ' . $sqlAktifSantriRekap . '
        ';
        $paramsChart = ['start_date' => $rangeStart, 'end_date' => $rangeEnd];
        if ($tingkatan !== '') {
            $sqlChart .= ' AND LOWER(s.tingkatan) = LOWER(:tingkatan)';
            $paramsChart['tingkatan'] = $tingkatan;
        }
        $sqlChart .= ' GROUP BY ym ORDER BY ym';
        $stmtChart = $pdo->prepare($sqlChart);
        $stmtChart->execute($paramsChart);
        $bucketHadir = array_fill_keys($chartLabelsYm, 0);
        $bucketIzin = array_fill_keys($chartLabelsYm, 0);
        $bucketSakit = array_fill_keys($chartLabelsYm, 0);
        $bucketAlpa = array_fill_keys($chartLabelsYm, 0);
        $bucketTelat = array_fill_keys($chartLabelsYm, 0);
        foreach ($stmtChart->fetchAll() as $cr) {
            $ym = (string) ($cr['ym'] ?? '');
            if ($ym === '' || !array_key_exists($ym, $bucketHadir)) {
                continue;
            }
            $bucketHadir[$ym] = (int) $cr['hadir'];
            $bucketIzin[$ym] = (int) $cr['izin'];
            $bucketSakit[$ym] = (int) $cr['sakit'];
            $bucketAlpa[$ym] = (int) $cr['alpa'];
            $bucketTelat[$ym] = (int) $cr['telat'];
        }
        foreach ($chartLabelsYm as $ym) {
            $h = $bucketHadir[$ym];
            $chartStackHadir[] = $h;
            $chartStackIzin[] = $bucketIzin[$ym];
            $chartStackSakit[] = $bucketSakit[$ym];
            $chartStackAlpa[] = $bucketAlpa[$ym];
            $chartLineTelat[] = $bucketTelat[$ym];
            $totalSts = $h + $bucketIzin[$ym] + $bucketSakit[$ym] + $bucketAlpa[$ym];
            $chartLinePersenHadir[] = $totalSts > 0 ? round(($h / $totalSts) * 100, 1) : null;
        }
    }
}
$totalRecordPresensi = count($rows);
$totalTingkatanTampil = count($byTingkatan);
$totalSantriTampil = count($bySantri);

$pageTitle = 'Rekap Presensi';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Modul Rekap</p>
    <h1 class="h4 mb-1">Rekap presensi bulanan</h1>
    <p class="text-muted mb-0">Analisis presensi per tingkatan, per santri, dan per kegiatan dengan mode Masehi/Hijriyah. Hanya sesi yang tingkatan santri masuk jadwal kegiatan.</p>
</div>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Record presensi</div>
            <div class="app-mini-stat-value"><?= $totalRecordPresensi ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Tingkatan terhitung</div>
            <div class="app-mini-stat-value"><?= $totalTingkatanTampil ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Santri terhitung</div>
            <div class="app-mini-stat-value"><?= $totalSantriTampil ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Periode aktif</div>
            <div class="app-mini-stat-value" style="font-size:1.05rem;"><?= htmlspecialchars($monthName . ' ' . $year) ?></div>
        </div>
    </div>
</div>
<div class="card shadow-sm mb-4 print-controls">
    <div class="card-body">
        <?php if (($_SESSION['user']['role'] ?? '') === 'admin'): ?>
            <div class="d-flex justify-content-end mb-2">
                <a href="/rekap/perizinan.php" class="btn btn-outline-primary btn-sm me-2">Rekap Perizinan</a>
                <a href="/rekap/pembimbing.php" class="btn btn-outline-secondary btn-sm">Rekap Pembimbing</a>
            </div>
        <?php else: ?>
            <div class="d-flex justify-content-end mb-2">
                <a href="/rekap/perizinan.php" class="btn btn-outline-primary btn-sm">Rekap Perizinan</a>
            </div>
        <?php endif; ?>
        <form method="get" class="row g-2" id="rekap-filter-form">
            <input type="hidden" name="previous_mode" id="previous-mode" value="<?= htmlspecialchars($mode) ?>">
            <input type="hidden" name="anchor_masehi_year" id="anchor-masehi-year" value="<?= htmlspecialchars((string) $anchorMasehiYear) ?>">
            <div class="col-md-2">
                <label class="form-label">Mode</label>
                <select class="form-select" name="mode" id="mode-kalender">
                    <option value="masehi" <?= $mode === 'masehi' ? 'selected' : '' ?>>Masehi</option>
                    <option value="hijriyah" <?= $mode === 'hijriyah' ? 'selected' : '' ?>>Hijriyah</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Bulan</label>
                <select class="form-select" name="month" id="periode-bulan">
                    <?php $activeMonthList = $mode === 'hijriyah' ? $hijriyahMonths : $masehiMonths; ?>
                    <?php foreach ($activeMonthList as $monthNumber => $monthLabel): ?>
                        <option value="<?= $monthNumber ?>" <?= $month === $monthNumber ? 'selected' : '' ?>>
                            <?= htmlspecialchars($monthLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Tahun</label>
                <input
                    class="form-control"
                    type="number"
                    min="<?= $yearMin ?>"
                    max="<?= $yearMax ?>"
                    name="year"
                    id="periode-tahun"
                    <?= $mode === 'hijriyah' ? 'readonly' : '' ?>
                    value="<?= htmlspecialchars((string) $year) ?>"
                >
            </div>
            <input type="hidden" name="show" value="1">
            <div class="col-md-3">
                <label class="form-label">Jenis Rekap</label>
                <select class="form-select" name="report_type">
                    <option value="all" <?= $reportType === 'all' ? 'selected' : '' ?>>Semua</option>
                    <option value="per_tingkatan" <?= $reportType === 'per_tingkatan' ? 'selected' : '' ?>>Per Tingkatan (berdasarkan kegiatan)</option>
                    <option value="per_santri" <?= $reportType === 'per_santri' ? 'selected' : '' ?>>Per Santri (AIST per kegiatan)</option>
                    <option value="per_kegiatan" <?= $reportType === 'per_kegiatan' ? 'selected' : '' ?>>Per Kegiatan (daftar santri)</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Tingkatan (opsional)</label>
                <?php if ($tingkatanList): ?>
                    <select class="form-select" name="tingkatan">
                        <option value="">Semua Tingkatan</option>
                        <?php foreach ($tingkatanList as $tg): ?>
                            <option value="<?= htmlspecialchars($tg) ?>" <?= strtolower($tingkatan) === strtolower($tg) ? 'selected' : '' ?>><?= htmlspecialchars($tg) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <input class="form-control" type="text" name="tingkatan" value="<?= htmlspecialchars($tingkatan) ?>" placeholder="Contoh: SMP">
                <?php endif; ?>
            </div>
            <div class="col-md-2">
                <label class="form-label">Kertas</label>
                <select class="form-select" name="paper">
                    <option value="A4" <?= $paper === 'A4' ? 'selected' : '' ?>>A4</option>
                    <option value="F4" <?= $paper === 'F4' ? 'selected' : '' ?>>F4</option>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-success w-100">Tampilkan Rekap</button>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="button" class="btn btn-outline-dark w-100" onclick="window.print()">Cetak Resmi A4/F4</button>
            </div>
        </form>
        <div class="mt-2 small text-muted">
            Rekap dan grafik perbandingan bulan memuat otomatis untuk periode terpilih; ubah filter lalu <strong>Tampilkan Rekap</strong>.
            <br>
            <?php if ($mode === 'masehi'): ?>
                Periode Masehi: <strong><?= htmlspecialchars($monthName . ' ' . $year) ?></strong>.
                Konversi otomatis ke Hijriyah: <strong><?= htmlspecialchars($hijriEquivalentLabel) ?></strong>.
            <?php else: ?>
                Periode Hijriyah: <strong><?= htmlspecialchars($monthName . ' ' . $year) ?></strong>.
                Konversi otomatis ke Masehi:
                <strong><?= htmlspecialchars($hijriToGregorianStart) ?></strong> s/d <strong><?= htmlspecialchars($hijriToGregorianEnd) ?></strong>.
            <?php endif; ?>
            <br>
            Padanan periode: <strong><?= htmlspecialchars($periodBridgeLabel) ?></strong>.
        </div>
    </div>
</div>
<?php if (!$show): ?>
<div class="card shadow-sm mb-4">
    <div class="card-body text-center text-muted">
        Tampilan rekap dinonaktifkan. Hapus <code>?show=0</code> dari alamat atau kirim ulang formulir dengan <strong>Tampilkan Rekap</strong>.
    </div>
</div>
<?php else: ?>
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
    <p class="print-kop-meta">
        Rekap Presensi Santri Periode <?= htmlspecialchars($monthName) ?> <?= htmlspecialchars((string) $year) ?>
        (<?= htmlspecialchars(ucfirst($mode)) ?>)
    </p>
</div>

<?php if ($chartLabelsYm !== []): ?>
<div id="grafik-rekap-bulanan" class="chart-section mb-4">
    <div class="card shadow-sm mb-3">
        <div class="card-body py-3">
            <h2 class="h6 mb-1">Perbandingan presensi antar bulan</h2>
            <p class="small text-muted mb-2 mb-lg-3">
                12 bulan berakhir pada <?= htmlspecialchars((string) ($chartLabelsHuman[count($chartLabelsHuman) - 1] ?? '')) ?>
                (sumbu waktu <strong>Masehi</strong> menurut tanggal presensi).
                <?= $tingkatan !== '' ? 'Filter tingkatan: <strong>' . htmlspecialchars($tingkatan) . '</strong>.' : 'Semua tingkatan.' ?>
                Mode kalender rekap (Masehi/Hijriyah) hanya memengaruhi tabel di bawah; grafik memakai pembagian bulan gregorian yang sama agar bulan bisa dibandingkan.
            </p>
            <div class="row g-3">
                <div class="col-12 col-xl-8">
                    <h3 class="h6 text-secondary">Jumlah per status (bertumpuk)</h3>
                    <div class="position-relative" style="min-height: 280px;">
                        <canvas id="chartRekapBulanStacked" aria-label="Grafik perbandingan status per bulan"></canvas>
                    </div>
                </div>
                <div class="col-12 col-xl-4">
                    <h3 class="h6 text-secondary">Persentase kehadiran &amp; telat</h3>
                    <div class="position-relative" style="min-height: 280px;">
                        <canvas id="chartRekapBulanLine" aria-label="Grafik persentase kehadiran per bulan"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <?php if ($show && ($reportType === 'all' || $reportType === 'per_tingkatan')): ?>
    <div class="col-lg-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Rekap per Tingkatan (berdasarkan Kegiatan)</h2>
                <div class="table-responsive">
                <table class="table table-sm table-striped table-hover">
                    <thead><tr><th>Tingkatan</th><th>Kegiatan</th><th>Hadir</th><th>Izin</th><th>Sakit</th><th>Alpa</th><th>Telat</th></tr></thead>
                    <tbody>
                    <?php if ($byTingkatanKegiatan): ?>
                        <?php foreach ($byTingkatanKegiatan as $label => $kegiatanRows): ?>
                            <?php foreach ($kegiatanRows as $kegiatanNama => $stat): ?>
                                <tr>
                                    <td><?= htmlspecialchars($label) ?></td>
                                    <td><?= htmlspecialchars((string) $kegiatanNama) ?></td>
                                    <td><?= (int) ($stat['HADIR'] ?? 0) ?></td>
                                    <td><?= (int) ($stat['IZIN'] ?? 0) ?></td>
                                    <td><?= (int) ($stat['SAKIT'] ?? 0) ?></td>
                                    <td><?= (int) ($stat['ALPA'] ?? 0) ?></td>
                                    <td><?= (int) ($stat['TELAT'] ?? 0) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center text-muted">Tidak ada data untuk tingkatan.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($show && ($reportType === 'all' || $reportType === 'per_santri')): ?>
    <div class="col-lg-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Rekap per Santri (AIST per Kegiatan)</h2>
                <div class="table-responsive">
                <table class="table table-sm table-striped table-hover">
                    <thead><tr><th>Santri</th><th>Tingkatan</th><th>Kegiatan</th><th>A</th><th>I</th><th>S</th><th>T</th><th>Kriteria</th></tr></thead>
                    <tbody>
                    <?php if ($bySantri): ?>
                        <?php foreach ($bySantri as $sid => $santri): ?>
                            <?php $kategori = santri_category((int) ($santri['ALPA'] ?? 0), $goodMax, $mediumMax); ?>
                            <?php foreach (($bySantriKegiatan[$sid] ?? []) as $kegiatanNama => $stat): ?>
                                <tr>
                                    <td><?= htmlspecialchars($santri['nama']) ?></td>
                                    <td><?= htmlspecialchars($santri['tingkatan']) ?></td>
                                    <td><?= htmlspecialchars((string) $kegiatanNama) ?></td>
                                    <td><?= (int) ($stat['ALPA'] ?? 0) ?></td>
                                    <td><?= (int) ($stat['IZIN'] ?? 0) ?></td>
                                    <td><?= (int) ($stat['SAKIT'] ?? 0) ?></td>
                                    <td><?= (int) ($stat['TELAT'] ?? 0) ?></td>
                                    <td><?= htmlspecialchars($kategori) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="8" class="text-center text-muted">Tidak ada data untuk santri.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if ($show && ($reportType === 'all' || $reportType === 'per_kegiatan')): ?>
    <div class="col-lg-12">
        <div class="card shadow-sm">
            <div class="card-body">
                <h2 class="h5">Rekap per Kegiatan (Daftar Santri Mengikuti Kegiatan)</h2>
                <div class="table-responsive">
                <table class="table table-sm table-striped table-hover">
                    <thead><tr><th>Kegiatan</th><th>Hadir</th><th>Izin</th><th>Sakit</th><th>Alpa</th><th>Telat</th><th>Santri Mengikuti</th></tr></thead>
                    <tbody>
                    <?php if ($byKegiatan): ?>
                        <?php foreach ($byKegiatan as $kegiatanNama => $stat): ?>
                            <?php
                            $peserta = array_map(static function (array $item): string {
                                return $item['nama'] . ' (' . $item['tingkatan'] . ')';
                            }, array_values($stat['santri']));
                            ?>
                            <tr>
                                <td><?= htmlspecialchars((string) $kegiatanNama) ?></td>
                                <td><?= (int) ($stat['HADIR'] ?? 0) ?></td>
                                <td><?= (int) ($stat['IZIN'] ?? 0) ?></td>
                                <td><?= (int) ($stat['SAKIT'] ?? 0) ?></td>
                                <td><?= (int) ($stat['ALPA'] ?? 0) ?></td>
                                <td><?= (int) ($stat['TELAT'] ?? 0) ?></td>
                                <td><?= htmlspecialchars(implode(', ', $peserta)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="text-center text-muted">Tidak ada data untuk kegiatan.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<style>
    @media print {
        @page { size: <?= $paper === 'F4' ? '8.5in 13in' : 'A4' ?> portrait; margin: 12mm; }
        .navbar, .app-sidebar, .offcanvas, .print-controls, .chart-section { display: none !important; }
        .print-header, .card, .table-responsive { break-inside: avoid; }
        .card { border: 1px solid #cbd5e1 !important; box-shadow: none !important; }
        body { background: #fff !important; }
    }
</style>
<?php if ($show && $chartLabelsYm !== []): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
    const labels = <?= json_encode($chartLabelsHuman, JSON_UNESCAPED_UNICODE) ?>;
    const hadir = <?= json_encode($chartStackHadir, JSON_UNESCAPED_UNICODE) ?>;
    const izin = <?= json_encode($chartStackIzin, JSON_UNESCAPED_UNICODE) ?>;
    const sakit = <?= json_encode($chartStackSakit, JSON_UNESCAPED_UNICODE) ?>;
    const alpa = <?= json_encode($chartStackAlpa, JSON_UNESCAPED_UNICODE) ?>;
    const telat = <?= json_encode($chartLineTelat, JSON_UNESCAPED_UNICODE) ?>;
    const persenHadir = <?= json_encode($chartLinePersenHadir, JSON_UNESCAPED_UNICODE) ?>;

    const stackedEl = document.getElementById('chartRekapBulanStacked');
    if (stackedEl) {
        new Chart(stackedEl, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: 'Hadir', data: hadir, backgroundColor: '#16a34a', stack: 's' },
                    { label: 'Izin', data: izin, backgroundColor: '#f59e0b', stack: 's' },
                    { label: 'Sakit', data: sakit, backgroundColor: '#3b82f6', stack: 's' },
                    { label: 'Alpa', data: alpa, backgroundColor: '#ef4444', stack: 's' }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { stacked: true },
                    y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } }
                },
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } }
                }
            }
        });
    }

    const lineEl = document.getElementById('chartRekapBulanLine');
    if (lineEl) {
        new Chart(lineEl, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: '% kehadiran',
                        data: persenHadir,
                        borderColor: '#059669',
                        backgroundColor: 'rgba(5, 150, 105, 0.12)',
                        fill: true,
                        tension: 0.25,
                        yAxisID: 'y',
                        spanGaps: true
                    },
                    {
                        label: 'Catatan telat',
                        data: telat,
                        borderColor: '#ea580c',
                        backgroundColor: 'rgba(234, 88, 12, 0.08)',
                        fill: false,
                        tension: 0.25,
                        yAxisID: 'y1',
                        spanGaps: true
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                scales: {
                    y: {
                        type: 'linear',
                        position: 'left',
                        min: 0,
                        max: 100,
                        title: { display: true, text: '%' }
                    },
                    y1: {
                        type: 'linear',
                        position: 'right',
                        beginAtZero: true,
                        grid: { drawOnChartArea: false },
                        title: { display: true, text: 'Telat' }
                    }
                },
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } }
                }
            }
        });
    }
})();
</script>
<?php endif; ?>
<script>
    (function () {
        const form = document.getElementById('rekap-filter-form');
        const modeSelect = document.getElementById('mode-kalender');
        const monthSelect = document.getElementById('periode-bulan');
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
                    yearInput.readOnly = true;
                    const yearVal = parseInt(yearInput.value || '0', 10);
                    if (!yearVal || yearVal < 1300 || yearVal > 1700) {
                        yearInput.value = '<?= (int) $currentHijriYear ?>';
                    }
                } else {
                    yearInput.min = '1900';
                    yearInput.max = '2100';
                    yearInput.readOnly = false;
                    const yearVal = parseInt(yearInput.value || '0', 10);
                    if (!yearVal || yearVal < 1900 || yearVal > 2100) {
                        yearInput.value = '<?= (int) $currentMasehiYear ?>';
                    }
                }
            }
            // Otomatis muat ulang agar konversi kalender langsung terapkan.
            form.submit();
        });
        if (monthSelect) {
            monthSelect.addEventListener('change', function () {
                form.submit();
            });
        }
        if (yearInput) {
            yearInput.addEventListener('change', function () {
                if (anchorMasehiYearInput && modeSelect.value === 'masehi') {
                    anchorMasehiYearInput.value = yearInput.value || '<?= (int) $currentMasehiYear ?>';
                }
                form.submit();
            });
        }
    })();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
