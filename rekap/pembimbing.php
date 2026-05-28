<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_neraca.php';
require_once __DIR__ . '/../helpers/payroll_pembimbing.php';

require_roles(['admin']);

if (!table_exists($pdo, 'presensi_pembimbing')) {
    set_flash('error', 'Tabel presensi_pembimbing belum ada. Jalankan migrasi terbaru.');
    header('Location: ' . app_href('/dashboard.php'));
    exit;
}

payroll_pembimbing_ensure_schema($pdo);

$month = (int) ($_GET['month'] ?? date('m'));
$year = (int) ($_GET['year'] ?? app_tahun_masehi_default($pdo));
$calendarMode = strtolower((string) ($_GET['cal'] ?? 'masehi'));
$kegiatanFilter = (int) ($_GET['kegiatan_id'] ?? 0);
$paper = strtoupper((string) ($_GET['paper'] ?? 'A4'));
if (!in_array($paper, ['A4', 'F4'], true)) {
    $paper = 'A4';
}

$startDate = sprintf('%04d-%02d-01', $year, $month);
$endDate = date('Y-m-t', strtotime($startDate));
$tarifMap = payroll_pembimbing_tarif_map($pdo);
$kriteriaLabels = payroll_pembimbing_kriteria_labels();
$periodLabel = $calendarMode === 'hijriyah'
    ? get_hijri_ym_from_gregorian_month($year, $month)
    : sprintf('%02d/%04d', $month, $year);
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
        b.tarif_kriteria,
        SUM(CASE WHEN p.jenis_scan = "DATANG" THEN 1 ELSE 0 END) AS total_datang,
        COUNT(p.id) AS total_scan
    FROM pembimbing b
    LEFT JOIN presensi_pembimbing p
        ON p.pembimbing_id = b.id
       AND p.tanggal BETWEEN :start_date AND :end_date
    GROUP BY b.id, b.nip, b.nama_pembimbing, b.gaji_pokok, b.tarif_kriteria
    ORDER BY b.nama_pembimbing ASC
');
$stmt->execute([
    'start_date' => $startDate,
    'end_date' => $endDate,
]);
$rows = $stmt->fetchAll();
$kegiatanList = table_exists($pdo, 'kegiatan')
    ? ($pdo->query('SELECT id, nama_kegiatan FROM kegiatan ORDER BY nama_kegiatan ASC')->fetchAll() ?: [])
    : [];
$kegiatanMap = [];
foreach ($kegiatanList as $kg) {
    $kegiatanMap[(int) ($kg['id'] ?? 0)] = (string) ($kg['nama_kegiatan'] ?? '');
}
$scanGajiSql = '
    SELECT p.pembimbing_id,
           SUM(
               CASE
                   WHEN p.jenis_scan = "DATANG" AND j.jam_mulai IS NOT NULL AND j.jam_selesai IS NOT NULL
                       THEN GREATEST(TIMESTAMPDIFF(MINUTE, j.jam_mulai, j.jam_selesai), 0) / 60
                   WHEN p.jenis_scan = "DATANG" THEN 1
                   ELSE 0
               END
           ) AS total_jam
    FROM presensi_pembimbing p
    LEFT JOIN jadwal_kegiatan j ON j.kegiatan_id = p.kegiatan_id
    WHERE p.tanggal BETWEEN :start_date AND :end_date
';
if ($kegiatanFilter > 0) {
    $scanGajiSql .= ' AND p.kegiatan_id = :kegiatan_id';
}
$scanGajiSql .= ' GROUP BY p.pembimbing_id';
$scanGajiStmt = $pdo->prepare($scanGajiSql);
$scanParams = ['start_date' => $startDate, 'end_date' => $endDate];
if ($kegiatanFilter > 0) {
    $scanParams['kegiatan_id'] = $kegiatanFilter;
}
$scanGajiStmt->execute($scanParams);
$jamMap = [];
foreach ($scanGajiStmt->fetchAll(PDO::FETCH_ASSOC) as $jr) {
    $jamMap[(int) ($jr['pembimbing_id'] ?? 0)] = (float) ($jr['total_jam'] ?? 0);
}
$izinStmt = $pdo->prepare('
    SELECT pembimbing_id, jenis_izin, tanggal_mulai, tanggal_selesai
    FROM perizinan_pembimbing
    WHERE status_izin = "IZIN"
      AND tanggal_mulai <= :end_date
      AND tanggal_selesai >= :start_date
');
$izinStmt->execute([
    'start_date' => $startDate,
    'end_date' => $endDate,
]);
$izinRaw = $izinStmt->fetchAll();
$izinByPembimbing = [];
foreach ($izinRaw as $izin) {
    $pid = (int) $izin['pembimbing_id'];
    if (!isset($izinByPembimbing[$pid])) {
        $izinByPembimbing[$pid] = ['IZIN' => 0, 'SAKIT' => 0];
    }
    $from = max($startDate, (string) $izin['tanggal_mulai']);
    $to = min($endDate, (string) $izin['tanggal_selesai']);
    $days = ((int) ((strtotime($to) - strtotime($from)) / 86400)) + 1;
    if ($days < 1) {
        $days = 0;
    }
    $key = strtoupper((string) $izin['jenis_izin']) === 'SAKIT' ? 'SAKIT' : 'IZIN';
    $izinByPembimbing[$pid][$key] += $days;
}
$totalDays = (int) date('t', strtotime($startDate));

foreach ($rows as &$row) {
    $datang = (int) $row['total_datang'];
    $izin = (int) ($izinByPembimbing[(int) $row['pembimbing_id']]['IZIN'] ?? 0);
    $sakit = (int) ($izinByPembimbing[(int) $row['pembimbing_id']]['SAKIT'] ?? 0);
    $alpa = max(0, $totalDays - $datang - $izin - $sakit);
    $lateStmt = $pdo->prepare('
        SELECT COUNT(*)
        FROM presensi_pembimbing p
        INNER JOIN jadwal_kegiatan j ON j.kegiatan_id = p.kegiatan_id
        WHERE p.pembimbing_id = :pembimbing_id
          AND p.tanggal BETWEEN :start_date AND :end_date
          AND p.kegiatan_id IS NOT NULL
          AND TIME_TO_SEC(p.jam) > TIME_TO_SEC(ADDTIME(j.jam_mulai, SEC_TO_TIME(:late_sec)))
    ');
    $lateStmt->execute([
        'pembimbing_id' => (int) $row['pembimbing_id'],
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
    $row['telat'] = $telat;
    $row['izin'] = $izin;
    $row['sakit'] = $sakit;
    $row['alpa'] = $alpa;
    $row['kategori'] = $kategori;
    $totalJam = (float) ($jamMap[(int) $row['pembimbing_id']] ?? 0);
    $calc = payroll_pembimbing_compute(
        $totalJam,
        (float) ($row['gaji_pokok'] ?? 0),
        (string) ($row['tarif_kriteria'] ?? ''),
        $tarifMap
    );
    $row['total_jam'] = $calc['total_jam'];
    $row['gaji_pokok_n'] = $calc['gaji_pokok'];
    $row['tarif_per_jam'] = $calc['tarif_per_jam'];
    $row['kriteria'] = $calc['kriteria'];
    $row['kriteria_label'] = $calc['kriteria_label'];
    $row['gaji_per_jam'] = $calc['gaji_per_jam'];
    $row['gaji_bulanan'] = (int) round($calc['total_gaji']);
}
unset($row);

$namaPonpes = app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren');
$jenisPendidikan = app_setting($pdo, 'jenis_pendidikan', '');
$alamatPonpes = app_setting($pdo, 'alamat_ponpes', '-');
$logoPath = app_setting($pdo, 'logo_path', '');
$logoUrl = app_setting($pdo, 'logo_url', '');
$logo = $logoPath !== '' ? '/' . $logoPath : $logoUrl;
$telpPonpes = app_setting($pdo, 'telp_ponpes', '');
$websitePonpes = app_setting($pdo, 'website_ponpes', '');

$totalPembimbingRekap = count($rows);
$totalHadirRekap = 0;
$totalAlpaRekap = 0;
$totalTelatRekap = 0;
$kategoriBagus = 0;
foreach ($rows as $r) {
    $totalHadirRekap += (int) ($r['total_datang'] ?? 0);
    $totalAlpaRekap += (int) ($r['alpa'] ?? 0);
    $totalTelatRekap += (int) ($r['telat'] ?? 0);
    if (($r['kategori'] ?? '') === 'Bagus') {
        $kategoriBagus++;
    }
}
$periodeLabelP = $periodLabel;

$pageTitle = 'Rekap Pembimbing (Admin)';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3 print-controls">
    <p class="page-intro-kicker mb-1">Modul Rekap Pembimbing</p>
    <h1 class="h4 mb-1">Rekap kehadiran pembimbing</h1>
    <p class="text-muted mb-0">Rekap bulanan kehadiran pembimbing — periode <strong><?= htmlspecialchars($periodeLabelP) ?></strong>, toleransi telat <?= (int) $lateTolerance ?> menit. Pembimbing yang tidak tercatat hadir/izin/sakit pada periode ini dihitung otomatis sebagai ALPA.</p>
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

<div class="card shadow-sm mb-4 print-controls">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-6 col-md-3">
                <label class="form-label">Bulan</label>
                <input class="form-control" type="number" min="1" max="12" name="month" value="<?= htmlspecialchars((string) $month) ?>">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label">Tahun</label>
                <input class="form-control" type="number" min="1400" max="2100" name="year" value="<?= htmlspecialchars((string) $year) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label">Kalender</label>
                <select class="form-select" name="cal">
                    <option value="masehi" <?= $calendarMode === 'masehi' ? 'selected' : '' ?>>Masehi</option>
                    <option value="hijriyah" <?= $calendarMode === 'hijriyah' ? 'selected' : '' ?>>Hijriyah</option>
                </select>
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
        <div class="table-responsive">
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
                    <th class="text-center">Jam Kegiatan</th>
                    <th class="text-nowrap">Kriteria</th>
                    <th class="text-end text-nowrap">Tarif/jam</th>
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
                            <td><span class="badge text-bg-light border"><?= htmlspecialchars((string) ($row['kriteria_label'] ?? '-')) ?></span></td>
                            <td class="text-end"><?= htmlspecialchars(keuangan_format_rupiah((int) round((float) ($row['tarif_per_jam'] ?? 0)))) ?></td>
                            <td class="text-end"><?= htmlspecialchars(keuangan_format_rupiah((int) round((float) ($row['gaji_pokok_n'] ?? 0)))) ?></td>
                            <td class="text-end"><?= htmlspecialchars(keuangan_format_rupiah((int) round((float) ($row['gaji_per_jam'] ?? 0)))) ?></td>
                            <td class="text-end fw-semibold"><?= htmlspecialchars(keuangan_format_rupiah((int) ($row['gaji_bulanan'] ?? 0))) ?></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-success" href="<?= htmlspecialchars(app_href('/keuangan/index.php?tab=k&pembimbing_id=' . (int) ($row['pembimbing_id'] ?? 0) . '&bulan=' . (int) $month . '&tahun=' . (int) $year . '&cal=' . urlencode($calendarMode))) ?>">Bayar</a>
                            </td>
                            <td><span class="badge text-bg-<?= $katBadge ?>"><?= htmlspecialchars($kat) ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr><td colspan="15" class="text-center text-muted">Belum ada data pembimbing pada periode ini.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
    @media print {
        @page { size: <?= $paper === 'F4' ? '8.5in 13in' : 'A4' ?> portrait; margin: 12mm; }
        .navbar, .app-sidebar, .offcanvas, .print-controls { display: none !important; }
        .card { border: 1px solid #cbd5e1 !important; box-shadow: none !important; }
        body { background: #fff !important; }
    }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
