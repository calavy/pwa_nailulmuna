<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';

require_roles(['admin', 'pengurus']);

if (!table_exists($pdo, 'perizinan')) {
    set_flash('error', 'Tabel perizinan belum ada.');
    header('Location: ' . app_href('/dashboard.php'));
    exit;
}

$month = (int) ($_GET['month'] ?? date('m'));
$year = (int) ($_GET['year'] ?? app_tahun_masehi_default($pdo));
$tingkatan = trim((string) ($_GET['tingkatan'] ?? ''));
$santriId = (int) ($_GET['santri_id'] ?? 0);
$reportType = trim((string) ($_GET['report_type'] ?? 'all'));
// Tanpa ?show=0, halaman langsung menampilkan data (bulan/tahun default = sekarang).
$show = ($_GET['show'] ?? '1') === '1';
$paper = strtoupper((string) ($_GET['paper'] ?? 'A4'));
if (!in_array($paper, ['A4', 'F4'], true)) {
    $paper = 'A4';
}

if (!in_array($reportType, ['all', 'per_tingkatan', 'per_santri'], true)) {
    $reportType = 'all';
}

$tingkatanList = table_exists($pdo, 'tingkatan')
    ? $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY nama_tingkatan ASC')->fetchAll(PDO::FETCH_COLUMN)
    : [];
require_once __DIR__ . '/../helpers/santri_list_sort.php';
santri_list_sort_mode($_GET['santri_sort'] ?? null);
$santriList = $pdo->query('SELECT id, nama_santri, nis, tingkatan FROM santri ORDER BY ' . santri_list_order_sql('santri'))->fetchAll();

$records = [];
if ($show) {
    $startDate = sprintf('%04d-%02d-01', $year, $month);
    $endDate = date('Y-m-t', strtotime($startDate));
    $stmt = $pdo->prepare('
        SELECT i.id, i.jenis_izin, i.status_izin, i.approval_status, i.alasan, i.tanggal_mulai, i.tanggal_selesai, i.jam_mulai, i.jam_selesai,
               i.waktu_keluar, i.waktu_kembali, i.grace_menit, s.id AS santri_id, s.nama_santri, s.nis, s.tingkatan
        FROM perizinan i
        INNER JOIN santri s ON s.id = i.santri_id
        WHERE i.tanggal_mulai BETWEEN :start_date AND :end_date
        ORDER BY i.id DESC
    ');
    $stmt->execute([
        'start_date' => $startDate,
        'end_date' => $endDate,
    ]);
    $records = $stmt->fetchAll();
    if ($tingkatan !== '') {
        $records = array_values(array_filter($records, static function ($row) use ($tingkatan): bool {
            return strtolower((string) ($row['tingkatan'] ?? '')) === strtolower($tingkatan);
        }));
    }
    if ($santriId > 0) {
        $records = array_values(array_filter($records, static function ($row) use ($santriId): bool {
            return (int) ($row['santri_id'] ?? 0) === $santriId;
        }));
    }
}
$izinCountPerSantri = [];
if ($show && $records) {
    $santriIds = array_values(array_unique(array_map(static fn(array $row): int => (int) ($row['santri_id'] ?? 0), $records)));
    if ($santriIds) {
        $inClause = implode(',', array_map('intval', $santriIds));
        $countRows = $pdo->query("
            SELECT santri_id, COUNT(*) AS total_izin
            FROM perizinan
            WHERE santri_id IN ({$inClause})
            GROUP BY santri_id
        ")->fetchAll();
        foreach ($countRows as $c) {
            $izinCountPerSantri[(int) $c['santri_id']] = (int) $c['total_izin'];
        }
    }
}

function izin_timeliness(array $item): array
{
    $dueDate = (string) ($item['tanggal_selesai'] ?? '');
    $dueTime = (string) ($item['jam_selesai'] ?? '00:00:00');
    $backAt = (string) ($item['waktu_kembali'] ?? '');
    $grace = (int) ($item['grace_menit'] ?? 15);
    if ($dueDate === '' || $backAt === '') {
        return ['label' => 'Belum kembali', 'late_minutes' => null];
    }
    $dueTs = strtotime($dueDate . ' ' . $dueTime);
    $backTs = strtotime($backAt);
    if ($dueTs === false || $backTs === false) {
        return ['label' => '-', 'late_minutes' => null];
    }
    $diffMinutes = (int) floor(($backTs - $dueTs) / 60);
    if ($diffMinutes <= $grace) {
        return ['label' => 'Tepat waktu', 'late_minutes' => 0];
    }
    $late = $diffMinutes - $grace;
    $days = intdiv($late, 1440);
    $hours = intdiv($late % 1440, 60);
    $minutes = $late % 60;
    $parts = [];
    if ($days > 0) {
        $parts[] = $days . ' hari';
    }
    if ($hours > 0) {
        $parts[] = $hours . ' jam';
    }
    if ($minutes > 0 || !$parts) {
        $parts[] = $minutes . ' menit';
    }
    return ['label' => 'Telat ' . implode(' ', $parts), 'late_minutes' => $late];
}

$byTingkatan = [];
$bySantri = [];
$belumHadirKembali = [];
$riwayatSantri = [];
$summary = [
    'total_izin' => 0,
    'total_kembali' => 0,
    'total_belum_kembali' => 0,
    'total_telat' => 0,
    'jenis_sakit' => 0,
    'jenis_keluar' => 0,
    'jenis_tugas' => 0,
];
foreach ($records as $row) {
    $summary['total_izin']++;
    $timeInfo = izin_timeliness($row);
    if (strtoupper((string) ($row['status_izin'] ?? '')) === 'IZIN' && empty($row['waktu_kembali'])) {
        $belumHadirKembali[] = $row;
        $summary['total_belum_kembali']++;
    } else {
        $summary['total_kembali']++;
    }
    $jenisUpper = strtoupper((string) ($row['jenis_izin'] ?? ''));
    if ($jenisUpper === 'SAKIT') {
        $summary['jenis_sakit']++;
    } elseif ($jenisUpper === 'PULANG' || $jenisUpper === 'TUGAS') {
        $summary['jenis_tugas']++;
    } else {
        $summary['jenis_keluar']++;
    }
    $tg = (string) ($row['tingkatan'] ?? '-');
    $sid = (int) ($row['santri_id'] ?? 0);
    if (!isset($byTingkatan[$tg])) {
        $byTingkatan[$tg] = ['total' => 0, 'tepat_waktu' => 0, 'telat' => 0];
    }
    $byTingkatan[$tg]['total']++;
    if (($timeInfo['late_minutes'] ?? null) !== null) {
        if ((int) $timeInfo['late_minutes'] > 0) {
            $byTingkatan[$tg]['telat']++;
            $summary['total_telat']++;
        } else {
            $byTingkatan[$tg]['tepat_waktu']++;
        }
    }

    if (!isset($bySantri[$sid])) {
        $bySantri[$sid] = [
            'nama' => (string) ($row['nama_santri'] ?? '-'),
            'nis' => (string) ($row['nis'] ?? '-'),
            'tingkatan' => $tg,
            'total' => 0,
            'tepat_waktu' => 0,
            'telat' => 0,
        ];
    }
    $bySantri[$sid]['total']++;
    if (($timeInfo['late_minutes'] ?? null) !== null) {
        if ((int) $timeInfo['late_minutes'] > 0) {
            $bySantri[$sid]['telat']++;
        } else {
            $bySantri[$sid]['tepat_waktu']++;
        }
    }

    if (!isset($riwayatSantri[$sid])) {
        $riwayatSantri[$sid] = [
            'santri_id' => $sid,
            'nama' => (string) ($row['nama_santri'] ?? '-'),
            'nis' => (string) ($row['nis'] ?? '-'),
            'tingkatan' => $tg,
            'sakit' => 0,
            'keluar' => 0,
            'tugas' => 0,
            'total' => 0,
            'terakhir' => (string) ($row['tanggal_mulai'] ?? ''),
        ];
    }
    $jenisSantri = strtoupper((string) ($row['jenis_izin'] ?? 'KELUAR'));
    if ($jenisSantri === 'SAKIT') {
        $riwayatSantri[$sid]['sakit']++;
    } elseif ($jenisSantri === 'PULANG' || $jenisSantri === 'TUGAS') {
        $riwayatSantri[$sid]['tugas']++;
    } else {
        $riwayatSantri[$sid]['keluar']++;
    }
    $riwayatSantri[$sid]['total']++;
    if ((string) ($row['tanggal_mulai'] ?? '') > (string) $riwayatSantri[$sid]['terakhir']) {
        $riwayatSantri[$sid]['terakhir'] = (string) $row['tanggal_mulai'];
    }
}
$riwayatSantri = array_values($riwayatSantri);
usort($riwayatSantri, static function (array $a, array $b): int {
    if ($a['total'] === $b['total']) {
        return strcmp((string) $b['terakhir'], (string) $a['terakhir']);
    }
    return $b['total'] <=> $a['total'];
});

/** Grafik: 12 bulan berakhir pada bulan/tahun filter (untuk tren & keaktifan). */
$chartLabelsYm = [];
$chartLabelsHuman = [];
if ($show) {
    $chartAnchor = strtotime(sprintf('%04d-%02d-01', $year, $month));
    if ($chartAnchor === false) {
        $chartAnchor = time();
    }
    $namaBulanSingkat = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    for ($i = 11; $i >= 0; $i--) {
        $ts = strtotime('-' . $i . ' months', $chartAnchor);
        if ($ts === false) {
            continue;
        }
        $ym = date('Y-m', $ts);
        $chartLabelsYm[] = $ym;
        $chartLabelsHuman[] = $namaBulanSingkat[(int) date('n', $ts)] . ' ' . date('Y', $ts);
    }
}

$chartIzinTrendSakit = array_fill_keys($chartLabelsYm, 0);
$chartIzinTrendKeluar = array_fill_keys($chartLabelsYm, 0);
$chartIzinTrendTugas = array_fill_keys($chartLabelsYm, 0);
$chartKeaktifanSkor = array_fill_keys($chartLabelsYm, null);
$chartKeaktifanPersen = array_fill_keys($chartLabelsYm, null);
$chartKeaktifanAvailable = false;

if ($show && $chartLabelsYm !== [] && table_exists($pdo, 'perizinan')) {
    $rangeStart = $chartLabelsYm[0] . '-01';
    $rangeEnd = date('Y-m-t', strtotime(end($chartLabelsYm) . '-01'));
    $sqlTrend = '
        SELECT DATE_FORMAT(i.tanggal_mulai, "%Y-%m") AS ym,
            SUM(CASE WHEN i.jenis_izin = "SAKIT" THEN 1 ELSE 0 END) AS sakit,
            SUM(CASE WHEN i.jenis_izin = "KELUAR" THEN 1 ELSE 0 END) AS keluar,
            SUM(CASE WHEN i.jenis_izin IN ("TUGAS","PULANG") THEN 1 ELSE 0 END) AS tugas
        FROM perizinan i
        INNER JOIN santri s ON s.id = i.santri_id
        WHERE i.tanggal_mulai BETWEEN :start_date AND :end_date
    ';
    $paramsTrend = ['start_date' => $rangeStart, 'end_date' => $rangeEnd];
    if ($tingkatan !== '') {
        $sqlTrend .= ' AND LOWER(s.tingkatan) = LOWER(:tingkatan)';
        $paramsTrend['tingkatan'] = $tingkatan;
    }
    if ($santriId > 0) {
        $sqlTrend .= ' AND i.santri_id = :santri_id';
        $paramsTrend['santri_id'] = $santriId;
    }
    $sqlTrend .= ' GROUP BY ym ORDER BY ym';
    $trendStmt = $pdo->prepare($sqlTrend);
    $trendStmt->execute($paramsTrend);
    foreach ($trendStmt->fetchAll() as $tr) {
        $ym = (string) ($tr['ym'] ?? '');
        if ($ym === '' || !isset($chartIzinTrendSakit[$ym])) {
            continue;
        }
        $chartIzinTrendSakit[$ym] = (int) $tr['sakit'];
        $chartIzinTrendKeluar[$ym] = (int) $tr['keluar'];
        $chartIzinTrendTugas[$ym] = (int) ($tr['tugas'] ?? 0);
    }
}

if ($show && $chartLabelsYm !== [] && table_exists($pdo, 'presensi')) {
    $chartKeaktifanAvailable = true;
    $rangeStart = $chartLabelsYm[0] . '-01';
    $rangeEnd = date('Y-m-t', strtotime(end($chartLabelsYm) . '-01'));
    $sqlPres = '
        SELECT DATE_FORMAT(p.tanggal_presensi, "%Y-%m") AS ym, p.santri_id,
            SUM(CASE WHEN p.status_presensi = "HADIR" THEN 1 ELSE 0 END) AS hadir,
            SUM(CASE WHEN p.status_presensi = "ALPA" THEN 1 ELSE 0 END) AS alpa,
            COUNT(*) AS total
        FROM presensi p
        INNER JOIN santri s ON s.id = p.santri_id
        WHERE p.tanggal_presensi BETWEEN :start_date AND :end_date
    ';
    $paramsPres = ['start_date' => $rangeStart, 'end_date' => $rangeEnd];
    if ($tingkatan !== '') {
        $sqlPres .= ' AND LOWER(s.tingkatan) = LOWER(:tingkatan)';
        $paramsPres['tingkatan'] = $tingkatan;
    }
    if ($santriId > 0) {
        $sqlPres .= ' AND p.santri_id = :santri_id';
        $paramsPres['santri_id'] = $santriId;
    }
    $sqlPres .= ' GROUP BY ym, p.santri_id';
    $presStmt = $pdo->prepare($sqlPres);
    $presStmt->execute($paramsPres);
    $skorBuckets = [];
    $aggHadir = array_fill_keys($chartLabelsYm, 0);
    $aggTotal = array_fill_keys($chartLabelsYm, 0);
    foreach ($presStmt->fetchAll() as $pr) {
        $ym = (string) ($pr['ym'] ?? '');
        if ($ym === '' || !in_array($ym, $chartLabelsYm, true)) {
            continue;
        }
        if (!isset($skorBuckets[$ym])) {
            $skorBuckets[$ym] = [];
        }
        $total = (int) $pr['total'];
        if ($total < 1) {
            continue;
        }
        $hadir = (int) $pr['hadir'];
        $alpa = (int) $pr['alpa'];
        $persen = round(($hadir / $total) * 100, 2);
        $skor = ($persen * 10) - ($alpa * 5);
        $skorBuckets[$ym][] = $skor;
        $aggHadir[$ym] += $hadir;
        $aggTotal[$ym] += $total;
    }
    foreach ($chartLabelsYm as $ym) {
        if (!empty($skorBuckets[$ym])) {
            $chartKeaktifanSkor[$ym] = round(array_sum($skorBuckets[$ym]) / count($skorBuckets[$ym]), 1);
        }
        if (($aggTotal[$ym] ?? 0) > 0) {
            $chartKeaktifanPersen[$ym] = round(($aggHadir[$ym] / $aggTotal[$ym]) * 100, 1);
        }
    }
}

$pageTitle = 'Rekap Perizinan';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="card shadow-sm mb-3 print-controls border-0 bg-light">
    <div class="card-body py-2 px-3">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <strong class="small text-secondary me-1">Filter</strong>
            <span class="small text-muted">Bulan berjalan ditampilkan otomatis; ubah lalu klik terapkan.</span>
        </div>
        <form method="get" class="row g-2 align-items-end small">
            <input type="hidden" name="show" value="1">
            <div class="col-6 col-sm-4 col-md-1">
                <label class="form-label small mb-0">Bln</label>
                <input class="form-control form-control-sm" type="number" min="1" max="12" name="month" value="<?= htmlspecialchars((string) $month) ?>">
            </div>
            <div class="col-6 col-sm-4 col-md-1">
                <label class="form-label small mb-0">Thn</label>
                <input class="form-control form-control-sm" type="number" min="2000" max="2100" name="year" value="<?= htmlspecialchars((string) $year) ?>">
            </div>
            <div class="col-12 col-sm-6 col-md-2">
                <label class="form-label small mb-0">Jenis rekap</label>
                <select class="form-select form-select-sm" name="report_type">
                    <option value="all" <?= $reportType === 'all' ? 'selected' : '' ?>>Semua</option>
                    <option value="per_tingkatan" <?= $reportType === 'per_tingkatan' ? 'selected' : '' ?>>Per tingkatan</option>
                    <option value="per_santri" <?= $reportType === 'per_santri' ? 'selected' : '' ?>>Per santri</option>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-md-2">
                <label class="form-label small mb-0">Tingkatan</label>
                <select class="form-select form-select-sm" name="tingkatan">
                    <option value="">Semua</option>
                    <?php foreach ($tingkatanList as $tg): ?>
                        <option value="<?= htmlspecialchars((string) $tg) ?>" <?= strtolower($tingkatan) === strtolower((string) $tg) ? 'selected' : '' ?>><?= htmlspecialchars((string) $tg) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label small mb-0">Santri</label>
                <select class="form-select form-select-sm" name="santri_id">
                    <option value="0">Semua</option>
                    <?php foreach ($santriList as $s): ?>
                        <option value="<?= (int) $s['id'] ?>" <?= $santriId === (int) $s['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $s['nama_santri']) ?> (<?= htmlspecialchars((string) $s['tingkatan']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-1">
                <label class="form-label small mb-0">Kertas</label>
                <select class="form-select form-select-sm" name="paper">
                    <option value="A4" <?= $paper === 'A4' ? 'selected' : '' ?>>A4</option>
                    <option value="F4" <?= $paper === 'F4' ? 'selected' : '' ?>>F4</option>
                </select>
            </div>
            <div class="col-6 col-md-1">
                <button type="submit" class="btn btn-success btn-sm w-100">Terapkan</button>
            </div>
            <div class="col-12 col-md-1">
                <button type="button" class="btn btn-outline-secondary btn-sm w-100" onclick="window.print()">Cetak</button>
            </div>
        </form>
    </div>
</div>

<?php if ($show): ?>
<div class="card shadow-sm mb-3 print-header">
    <div class="card-body py-3">
        <h1 class="h5 mb-1">Rekap Perizinan Santri</h1>
        <div class="text-muted small">Periode <?= htmlspecialchars(sprintf('%02d/%04d', $month, $year)) ?><?= $tingkatan !== '' ? ' | Tingkatan: ' . htmlspecialchars($tingkatan) : '' ?><?= $santriId > 0 ? ' | Satu santri (filter)' : '' ?></div>
    </div>
</div>

<?php if ($chartLabelsYm !== []): ?>
<div id="grafik-perizinan" class="row g-3 mb-3 chart-section">
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-body py-3">
                <h2 class="h6 mb-1">Komposisi jenis izin</h2>
                <p class="small text-muted mb-2 mb-lg-0" style="font-size: 0.8rem;">Periode <?= htmlspecialchars(sprintf('%02d/%04d', $month, $year)) ?> · mengikuti filter.</p>
                <div class="position-relative chart-canvas-wrap" style="height: 220px;">
                    <canvas id="chartIzinPie" aria-label="Grafik komposisi jenis izin"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card shadow-sm h-100">
            <div class="card-body py-3">
                <h2 class="h6 mb-1">Tren perizinan per bulan</h2>
                <p class="small text-muted mb-2 mb-lg-0" style="font-size: 0.8rem;">12 bulan berakhir <?= htmlspecialchars(sprintf('%02d/%04d', $month, $year)) ?>.</p>
                <div class="position-relative chart-canvas-wrap" style="height: 220px;">
                    <canvas id="chartIzinTrend" aria-label="Grafik tren perizinan"></canvas>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-body py-3">
                <h2 class="h6 mb-1">Nilai keaktifan per bulan</h2>
                <?php if ($chartKeaktifanAvailable): ?>
                    <p class="small text-muted mb-2" style="font-size: 0.8rem;">
                        Rata-rata skor: <code>(% hadir × 10) − (alpa × 5)</code> per santri. Garis ungu: % kehadiran agregat.
                    </p>
                    <div class="position-relative chart-canvas-wrap" style="height: 240px;">
                        <canvas id="chartKeaktifanBulan" aria-label="Grafik keaktifan per bulan"></canvas>
                    </div>
                <?php else: ?>
                    <p class="text-muted small mb-0">Data presensi belum tersedia untuk grafik keaktifan.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4 rekap-below-charts">
    <div class="col-md-3"><div class="card shadow-sm"><div class="card-body py-3"><div class="small text-muted">Total Izin</div><div class="h4 mb-0"><?= (int) $summary['total_izin'] ?></div></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm"><div class="card-body py-3"><div class="small text-muted">Sudah Kembali</div><div class="h4 mb-0"><?= (int) $summary['total_kembali'] ?></div></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm"><div class="card-body py-3"><div class="small text-muted">Belum Kembali</div><div class="h4 mb-0 text-warning"><?= (int) $summary['total_belum_kembali'] ?></div></div></div></div>
    <div class="col-md-3"><div class="card shadow-sm"><div class="card-body py-3"><div class="small text-muted">Total Telat</div><div class="h4 mb-0 text-danger"><?= (int) $summary['total_telat'] ?></div></div></div></div>
    <div class="col-md-4"><div class="card shadow-sm"><div class="card-body py-2"><div class="small text-muted">Jenis Sakit</div><div class="h5 mb-0"><?= (int) $summary['jenis_sakit'] ?></div></div></div></div>
    <div class="col-md-4"><div class="card shadow-sm"><div class="card-body py-2"><div class="small text-muted">Jenis Keluar</div><div class="h5 mb-0"><?= (int) $summary['jenis_keluar'] ?></div></div></div></div>
    <div class="col-md-4"><div class="card shadow-sm"><div class="card-body py-2"><div class="small text-muted">Jenis Tugas</div><div class="h5 mb-0"><?= (int) $summary['jenis_tugas'] ?></div></div></div></div>
</div>

<?php if ($reportType === 'all' || $reportType === 'per_tingkatan'): ?>
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5">Rekap Izin per Tingkatan</h2>
        <table class="table table-sm table-striped">
            <thead><tr><th>Tingkatan</th><th>Total Izin</th><th>Tepat Waktu</th><th>Telat</th></tr></thead>
            <tbody>
            <?php if ($byTingkatan): ?>
                <?php foreach ($byTingkatan as $label => $stat): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $label) ?></td>
                        <td><?= (int) $stat['total'] ?></td>
                        <td><?= (int) $stat['tepat_waktu'] ?></td>
                        <td><?= (int) $stat['telat'] ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="4" class="text-center text-muted">Tidak ada data.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if ($reportType === 'all' || $reportType === 'per_santri'): ?>
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5">Rekap Izin per Santri</h2>
        <table class="table table-sm table-striped">
            <thead><tr><th>Santri</th><th>Tingkatan</th><th>Total Izin</th><th>Tepat Waktu</th><th>Telat</th></tr></thead>
            <tbody>
            <?php if ($bySantri): ?>
                <?php foreach ($bySantri as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['nama']) ?> (<?= htmlspecialchars($item['nis']) ?>)</td>
                        <td><?= htmlspecialchars($item['tingkatan']) ?></td>
                        <td><?= (int) $item['total'] ?></td>
                        <td><?= (int) $item['tepat_waktu'] ?></td>
                        <td><?= (int) $item['telat'] ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="5" class="text-center text-muted">Tidak ada data.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5">Siapa Saja yang Pernah Izin (Urutan Terbanyak)</h2>
        <table class="table table-sm table-striped">
            <thead><tr><th>No</th><th>Santri</th><th>Tingkatan</th><th>Izin Sakit</th><th>Izin Keluar</th><th>Izin Tugas</th><th>Total Izin</th><th>Terakhir Izin</th></tr></thead>
            <tbody>
            <?php if ($riwayatSantri): ?>
                <?php foreach ($riwayatSantri as $i => $item): ?>
                    <tr>
                        <td><?= (int) ($i + 1) ?></td>
                        <td><?= htmlspecialchars((string) $item['nama']) ?> (<?= htmlspecialchars((string) $item['nis']) ?>)</td>
                        <td><?= htmlspecialchars((string) ($item['tingkatan'] ?: '-')) ?></td>
                        <td><?= (int) $item['sakit'] ?> kali</td>
                        <td><?= (int) $item['keluar'] ?> kali</td>
                        <td><?= (int) $item['tugas'] ?> kali</td>
                        <td><strong><?= (int) $item['total'] ?> kali</strong></td>
                        <td><?= htmlspecialchars((string) ($item['terakhir'] ?: '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="8" class="text-center text-muted">Belum ada data riwayat perizinan.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h2 class="h5">Data Santri Belum Hadir Kembali (Masih Izin)</h2>
        <table class="table table-sm table-striped">
            <thead>
                <tr>
                    <th>Santri</th>
                    <th>Tingkatan</th>
                    <th>Jenis Izin</th>
                    <th>Tanggal Mulai</th>
                    <th>Batas Kembali</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($belumHadirKembali): ?>
                <?php foreach ($belumHadirKembali as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars((string) $item['nama_santri']) ?> (<?= htmlspecialchars((string) $item['nis']) ?>)</td>
                        <td><?= htmlspecialchars((string) $item['tingkatan']) ?></td>
                        <td><?= htmlspecialchars(jenis_izin_label((string) ($item['jenis_izin'] ?? '-'))) ?></td>
                        <td><?= htmlspecialchars((string) ($item['tanggal_mulai'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string) ($item['tanggal_selesai'] ?? '-')) ?> <?= htmlspecialchars(substr((string) ($item['jam_selesai'] ?? ''), 0, 5)) ?></td>
                        <td><span class="badge text-bg-warning">Belum Hadir Kembali</span></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="6" class="text-center text-muted">Semua santri izin pada filter ini sudah kembali.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5">Riwayat Perizinan</h2>
        <table class="table table-sm table-bordered align-middle">
            <thead>
                <tr>
                    <th>No</th>
                    <th>No Surat</th>
                    <th>Santri</th>
                    <th>Total Izin Santri</th>
                    <th>Tingkatan</th>
                    <th>Jenis</th>
                    <th>Status Izin</th>
                    <th>Approval</th>
                    <th>Tanggal Izin</th>
                    <th>Jam Izin</th>
                    <th>Batas Kembali</th>
                    <th>Waktu Kembali</th>
                    <th>Keterangan</th>
                    <th>Alasan</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($records): ?>
                <?php foreach ($records as $idx => $row): ?>
                    <?php $timeInfo = izin_timeliness($row); ?>
                    <tr>
                        <td><?= (int) ($idx + 1) ?></td>
                        <td>#<?= (int) $row['id'] ?></td>
                        <td><?= htmlspecialchars((string) $row['nama_santri']) ?> (<?= htmlspecialchars((string) $row['nis']) ?>)</td>
                        <td><?= (int) ($izinCountPerSantri[(int) ($row['santri_id'] ?? 0)] ?? 0) ?> kali</td>
                        <td><?= htmlspecialchars((string) $row['tingkatan']) ?></td>
                        <td><?= htmlspecialchars(jenis_izin_label((string) ($row['jenis_izin'] ?? ''))) ?></td>
                        <td><?= htmlspecialchars((string) ($row['status_izin'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string) ($row['approval_status'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars((string) ($row['tanggal_mulai'] ?? '-')) ?> s/d <?= htmlspecialchars((string) ($row['tanggal_selesai'] ?? '-')) ?></td>
                        <td><?= htmlspecialchars(substr((string) ($row['jam_mulai'] ?? ''), 0, 5) ?: '-') ?> - <?= htmlspecialchars(substr((string) ($row['jam_selesai'] ?? ''), 0, 5) ?: '-') ?></td>
                        <td><?= htmlspecialchars((string) $row['tanggal_selesai']) ?> <?= htmlspecialchars(substr((string) ($row['jam_selesai'] ?? ''), 0, 5)) ?></td>
                        <td><?= htmlspecialchars((string) ($row['waktu_kembali'] ?? '-')) ?></td>
                        <td>
                            <?php if (($timeInfo['late_minutes'] ?? null) === null): ?>
                                <span class="badge text-bg-secondary"><?= htmlspecialchars($timeInfo['label']) ?></span>
                            <?php elseif ((int) $timeInfo['late_minutes'] > 0): ?>
                                <span class="badge text-bg-danger"><?= htmlspecialchars($timeInfo['label']) ?></span>
                            <?php else: ?>
                                <span class="badge text-bg-success"><?= htmlspecialchars($timeInfo['label']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars((string) ($row['alasan'] ?? '-')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="14" class="text-center text-muted">Tidak ada data perizinan pada filter ini.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php else: ?>
<div class="card shadow-sm">
    <div class="card-body text-muted text-center">Data rekap disembunyikan. Buka alamat tanpa <code>?show=0</code> atau klik <strong>Terapkan filter</strong>.</div>
</div>
<?php endif; ?>

<style>
@media print {
    @page { size: <?= $paper === 'F4' ? '8.5in 13in' : 'A4' ?> portrait; margin: 10mm; }
    .navbar, .app-sidebar, .offcanvas, .print-controls, .chart-section { display: none !important; }
    .card { box-shadow: none !important; border: 1px solid #cbd5e1 !important; }
}
</style>

<?php if ($show && $chartLabelsYm !== []): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
    const labels = <?= json_encode($chartLabelsHuman, JSON_UNESCAPED_UNICODE) ?>;
    const colorSakit = '#15803d';
    const colorKeluar = '#1d4ed8';
    const colorTugas = '#dc2626';

    const pieCanvas = document.getElementById('chartIzinPie');
    if (pieCanvas) {
        const pieData = [
            <?= (int) $summary['jenis_sakit'] ?>,
            <?= (int) $summary['jenis_keluar'] ?>,
            <?= (int) $summary['jenis_tugas'] ?>
        ];
        const pieLabels = <?= json_encode([
            jenis_izin_label('SAKIT'),
            jenis_izin_label('KELUAR'),
            jenis_izin_label('TUGAS'),
        ], JSON_UNESCAPED_UNICODE) ?>;
        new Chart(pieCanvas, {
            type: 'doughnut',
            data: {
                labels: pieLabels,
                datasets: [{
                    data: pieData,
                    backgroundColor: [colorSakit, colorKeluar, colorTugas],
                    borderWidth: 1,
                    borderColor: '#fff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } }
                }
            }
        });
    }

    const trendCanvas = document.getElementById('chartIzinTrend');
    if (trendCanvas) {
        const sakit = <?= json_encode(array_values(array_map(static fn(string $ym): int => $chartIzinTrendSakit[$ym] ?? 0, $chartLabelsYm))) ?>;
        const keluar = <?= json_encode(array_values(array_map(static fn(string $ym): int => $chartIzinTrendKeluar[$ym] ?? 0, $chartLabelsYm))) ?>;
        const tugas = <?= json_encode(array_values(array_map(static fn(string $ym): int => $chartIzinTrendTugas[$ym] ?? 0, $chartLabelsYm))) ?>;
        new Chart(trendCanvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    { label: <?= json_encode(jenis_izin_label('SAKIT'), JSON_UNESCAPED_UNICODE) ?>, data: sakit, backgroundColor: colorSakit, stack: 'a' },
                    { label: <?= json_encode(jenis_izin_label('KELUAR'), JSON_UNESCAPED_UNICODE) ?>, data: keluar, backgroundColor: colorKeluar, stack: 'a' },
                    { label: <?= json_encode(jenis_izin_label('TUGAS'), JSON_UNESCAPED_UNICODE) ?>, data: tugas, backgroundColor: colorTugas, stack: 'a' }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { stacked: true },
                    y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } }
                },
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } }
            }
        });
    }

    const keaktifanCanvas = document.getElementById('chartKeaktifanBulan');
    if (keaktifanCanvas) {
        const skorData = <?= json_encode(array_values(array_map(static fn(string $ym) => $chartKeaktifanSkor[$ym] ?? null, $chartLabelsYm))) ?>;
        const persenData = <?= json_encode(array_values(array_map(static fn(string $ym) => $chartKeaktifanPersen[$ym] ?? null, $chartLabelsYm))) ?>;
        new Chart(keaktifanCanvas, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Rata-rata skor keaktifan',
                        data: skorData,
                        borderColor: '#0f766e',
                        backgroundColor: 'rgba(15, 118, 110, 0.12)',
                        fill: true,
                        tension: 0.25,
                        yAxisID: 'y',
                        spanGaps: true
                    },
                    {
                        label: '% kehadiran (agregat)',
                        data: persenData,
                        borderColor: '#7c3aed',
                        backgroundColor: 'rgba(124, 58, 237, 0.08)',
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
                        beginAtZero: true,
                        title: { display: true, text: 'Skor' }
                    },
                    y1: {
                        type: 'linear',
                        position: 'right',
                        beginAtZero: true,
                        max: 100,
                        grid: { drawOnChartArea: false },
                        title: { display: true, text: '% hadir' }
                    }
                },
                plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } }
            }
        });
    }
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
