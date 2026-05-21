<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/rekap_periode.php';
require_once __DIR__ . '/../helpers/hijri_kalender.php';

require_roles(['admin', 'pengurus', 'petugas_absensi']);

if (!table_exists($pdo, 'presensi')) {
    set_flash('error', 'Tabel presensi belum ada.');
    header('Location: ' . app_href('/dashboard.php'));
    exit;
}

$periode = rekap_resolve_periode($pdo, $_GET);
$mode = $periode['mode'];
$month = $periode['month'];
$year = $periode['year'];
$startDate = $periode['start_date'];
$endDate = $periode['end_date'];
$periodeLabel = $periode['label'];
$tingkatan = trim((string) ($_GET['tingkatan'] ?? ''));
$santriId = (int) ($_GET['santri_id'] ?? 0);
$kegiatanId = (int) ($_GET['kegiatan_id'] ?? 0);
$paper = strtoupper((string) ($_GET['paper'] ?? 'A4'));
if (!in_array($paper, ['A4', 'F4'], true)) {
    $paper = 'A4';
}

$kegiatanList = table_exists($pdo, 'kegiatan')
    ? $pdo->query('SELECT id, nama_kegiatan FROM kegiatan WHERE is_active = 1 ORDER BY nama_kegiatan ASC')->fetchAll(PDO::FETCH_ASSOC)
    : [];
$hijriMonths = hijri_nama_bulan_list();
$santriList = $pdo->query('SELECT id, nama_santri, nis, tingkatan FROM santri ORDER BY nama_santri ASC')->fetchAll();
$selectedSantri = null;
foreach ($santriList as $santriOption) {
    if ((int) $santriOption['id'] === $santriId) {
        $selectedSantri = $santriOption;
        break;
    }
}

$kegiatanSql = '';
$execParams = ['start_date' => $startDate, 'end_date' => $endDate];
if ($kegiatanId > 0) {
    $kegiatanSql = ' AND p.kegiatan_id = :kegiatan_id';
    $execParams['kegiatan_id'] = $kegiatanId;
}
$stmt = $pdo->prepare('
    SELECT
        s.id,
        s.nama_santri,
        s.nis,
        s.tingkatan,
        SUM(CASE WHEN p.status_presensi = "HADIR" THEN 1 ELSE 0 END) AS hadir,
        SUM(CASE WHEN p.status_presensi = "IZIN" THEN 1 ELSE 0 END) AS izin,
        SUM(CASE WHEN p.status_presensi = "SAKIT" THEN 1 ELSE 0 END) AS sakit,
        SUM(CASE WHEN p.status_presensi = "ALPA" THEN 1 ELSE 0 END) AS alpa,
        COUNT(p.id) AS total
    FROM presensi p
    INNER JOIN santri s ON s.id = p.santri_id
    WHERE p.tanggal_presensi BETWEEN :start_date AND :end_date' . $kegiatanSql . '
    GROUP BY s.id, s.nama_santri, s.nis, s.tingkatan
');
$stmt->execute($execParams);
$rows = $stmt->fetchAll();

$goodMax = (int) app_setting($pdo, 'kategori_baik_max', '1');
$mediumMax = (int) app_setting($pdo, 'kategori_sedang_max', '3');

$ranked = [];
foreach ($rows as $row) {
    if ($tingkatan !== '' && strtolower((string) $row['tingkatan']) !== strtolower($tingkatan)) {
        continue;
    }
    if ($santriId > 0 && (int) $row['id'] !== $santriId) {
        continue;
    }
    $total = (int) $row['total'];
    $hadir = (int) $row['hadir'];
    $alpa = (int) $row['alpa'];
    $persen = $total > 0 ? round(($hadir / $total) * 100, 2) : 0;
    $kategori = santri_category($alpa, $goodMax, $mediumMax);
    $skor = ($persen * 10) - ($alpa * 5);
    $ranked[] = [
        'nis' => $row['nis'],
        'nama_santri' => $row['nama_santri'],
        'tingkatan' => $row['tingkatan'],
        'hadir' => $hadir,
        'izin' => (int) $row['izin'],
        'sakit' => (int) $row['sakit'],
        'alpa' => $alpa,
        'total' => $total,
        'persen_hadir' => $persen,
        'kategori' => $kategori,
        'skor' => $skor,
    ];
}

usort($ranked, static function (array $a, array $b): int {
    if ($a['skor'] === $b['skor']) {
        return strcmp((string) $a['nama_santri'], (string) $b['nama_santri']);
    }
    return $b['skor'] <=> $a['skor'];
});

$totalSantriRekap = count($ranked);
$totalHadir = array_sum(array_column($ranked, 'hadir'));
$totalIzin = array_sum(array_column($ranked, 'izin'));
$totalSakit = array_sum(array_column($ranked, 'sakit'));
$totalAlpa = array_sum(array_column($ranked, 'alpa'));
$totalPresensi = array_sum(array_column($ranked, 'total'));
$rataHadir = $totalPresensi > 0 ? round(($totalHadir / $totalPresensi) * 100, 2) : 0;
$cakupanLabel = 'Semua Santri';
if ($selectedSantri) {
    $cakupanLabel = 'Per Santri - ' . (string) $selectedSantri['nama_santri'] . ' (' . (string) $selectedSantri['nis'] . ')';
} elseif ($tingkatan !== '') {
    $cakupanLabel = 'Per Tingkatan - ' . $tingkatan;
}

$namaPonpes = app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren');
$jenisPendidikan = app_setting($pdo, 'jenis_pendidikan', '');
$alamatPonpes = app_setting($pdo, 'alamat_ponpes', '-');
$logoPath = app_setting($pdo, 'logo_path', '');
$logoUrl = app_setting($pdo, 'logo_url', '');
$logo = $logoPath !== '' ? '/' . $logoPath : $logoUrl;
$telpPonpes = app_setting($pdo, 'telp_ponpes', '');
$websitePonpes = app_setting($pdo, 'website_ponpes', '');
$namaPengasuh = app_setting($pdo, 'nama_pengasuh', '');
$pageTitle = 'Rekap Keaktifan Santri';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card shadow-sm mb-4 print-controls">
    <div class="card-body">
        <form method="get" class="row g-2">
            <div class="col-md-2">
                <label class="form-label">Kalender</label>
                <select class="form-select" name="mode">
                    <option value="masehi" <?= $mode === 'masehi' ? 'selected' : '' ?>>Masehi</option>
                    <option value="hijriyah" <?= $mode === 'hijriyah' ? 'selected' : '' ?>>Hijriyah</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Bulan</label>
                <select class="form-select" name="month">
                    <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>"<?= $month === $m ? ' selected' : '' ?>><?= htmlspecialchars($mode === 'hijriyah' ? ($hijriMonths[$m] ?? (string) $m) : sprintf('%02d', $m)) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Tahun</label>
                <input class="form-control" type="number" min="1300" max="2100" name="year" value="<?= htmlspecialchars((string) $year) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Kegiatan</label>
                <select class="form-select" name="kegiatan_id">
                    <option value="0">Semua kegiatan</option>
                    <?php foreach ($kegiatanList as $kg): ?>
                        <option value="<?= (int) $kg['id'] ?>"<?= $kegiatanId === (int) $kg['id'] ? ' selected' : '' ?>><?= htmlspecialchars((string) $kg['nama_kegiatan']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Tingkatan</label>
                <input class="form-control" type="text" name="tingkatan" value="<?= htmlspecialchars($tingkatan) ?>" placeholder="Opsional">
            </div>
            <div class="col-md-2">
                <label class="form-label">Santri</label>
                <select class="form-select" name="santri_id">
                    <option value="0">Semua santri</option>
                    <?php foreach ($santriList as $santriOption): ?>
                        <option value="<?= (int) $santriOption['id'] ?>" <?= (int) $santriOption['id'] === $santriId ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) $santriOption['nama_santri']) ?> (<?= htmlspecialchars((string) $santriOption['nis']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Kertas</label>
                <select class="form-select" name="paper">
                    <option value="A4" <?= $paper === 'A4' ? 'selected' : '' ?>>A4</option>
                    <option value="F4" <?= $paper === 'F4' ? 'selected' : '' ?>>F4</option>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button class="btn btn-success w-50">Tampilkan</button>
                <button type="button" class="btn btn-outline-dark w-50 ms-2" onclick="window.print()">Cetak</button>
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
    <p class="print-kop-meta">Dicetak pada <?= htmlspecialchars(date('d-m-Y H:i')) ?> WIB</p>
</div>

<div class="rekap-print-title">
    <h2>REKAP KEAKTIFAN SANTRI</h2>
    <p>Periode <?= htmlspecialchars($periodeLabel) ?></p>
</div>

<div class="rekap-print-info">
    <table>
        <tr>
            <td>Jenis Rekap</td>
            <td>: <?= htmlspecialchars($cakupanLabel) ?></td>
        </tr>
        <tr>
            <td>Periode</td>
            <td>: <?= htmlspecialchars(date('d-m-Y', strtotime($startDate))) ?> s.d <?= htmlspecialchars(date('d-m-Y', strtotime($endDate))) ?></td>
        </tr>
        <tr>
            <td>Jumlah Santri</td>
            <td>: <?= (int) $totalSantriRekap ?> santri</td>
        </tr>
        <tr>
            <td>Rata-rata Kehadiran</td>
            <td>: <?= htmlspecialchars((string) $rataHadir) ?>%</td>
        </tr>
    </table>
</div>

<div class="row g-3 mb-3 print-controls">
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Jumlah Santri</div>
            <div class="app-mini-stat-value"><?= (int) $totalSantriRekap ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total Hadir</div>
            <div class="app-mini-stat-value text-success"><?= (int) $totalHadir ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Total Alpa</div>
            <div class="app-mini-stat-value text-danger"><?= (int) $totalAlpa ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Rata-rata Hadir</div>
            <div class="app-mini-stat-value"><?= htmlspecialchars((string) $rataHadir) ?>%</div>
        </div>
    </div>
</div>

<div class="card shadow-sm rekap-official-card">
    <div class="card-body">
        <h2 class="h5 print-controls">Daftar Keaktifan Santri</h2>
        <div class="table-responsive">
            <table class="table table-sm table-striped rekap-official-table">
                <thead>
                <tr>
                    <th>No</th>
                    <th>NIS</th>
                    <th>Nama</th>
                    <th>Tingkatan</th>
                    <th>Hadir</th>
                    <th>Alpa</th>
                    <th>Izin</th>
                    <th>Sakit</th>
                    <th>Total</th>
                    <th>% Hadir</th>
                    <th>Kategori</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($ranked as $idx => $row): ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td><?= htmlspecialchars($row['nis']) ?></td>
                        <td><?= htmlspecialchars($row['nama_santri']) ?></td>
                        <td><?= htmlspecialchars((string) $row['tingkatan']) ?></td>
                        <td><?= (int) $row['hadir'] ?></td>
                        <td><?= (int) $row['alpa'] ?></td>
                        <td><?= (int) $row['izin'] ?></td>
                        <td><?= (int) $row['sakit'] ?></td>
                        <td><?= (int) $row['total'] ?></td>
                        <td><?= htmlspecialchars((string) $row['persen_hadir']) ?>%</td>
                        <td><?= htmlspecialchars($row['kategori']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$ranked): ?>
                    <tr>
                        <td colspan="11" class="text-center text-muted">Tidak ada data pada filter ini.</td>
                    </tr>
                <?php endif; ?>
                </tbody>
                <?php if ($ranked): ?>
                    <tfoot class="rekap-print-total">
                    <tr>
                        <th colspan="4">Total</th>
                        <th><?= (int) $totalHadir ?></th>
                        <th><?= (int) $totalAlpa ?></th>
                        <th><?= (int) $totalIzin ?></th>
                        <th><?= (int) $totalSakit ?></th>
                        <th><?= (int) $totalPresensi ?></th>
                        <th><?= htmlspecialchars((string) $rataHadir) ?>%</th>
                        <th>-</th>
                    </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>
</div>

<div class="rekap-signature">
    <div class="rekap-signature-box">
        <p>Mengetahui,</p>
        <p class="rekap-signature-role">Pengasuh</p>
        <div class="rekap-signature-space"></div>
        <p class="rekap-signature-name"><?= htmlspecialchars($namaPengasuh !== '' ? $namaPengasuh : '................................') ?></p>
    </div>
    <div class="rekap-signature-box">
        <p>Muntilan, <?= htmlspecialchars(date('d-m-Y')) ?></p>
        <p class="rekap-signature-role">Petugas Rekap</p>
        <div class="rekap-signature-space"></div>
        <p class="rekap-signature-name"><?= htmlspecialchars((string) ($_SESSION['user']['nama'] ?? 'Petugas')) ?></p>
    </div>
</div>

<style>
    .rekap-print-title,
    .rekap-print-info,
    .rekap-signature {
        display: none;
    }

    @media print {
        @page { size: <?= $paper === 'F4' ? '8.5in 13in' : 'A4' ?> portrait; margin: 12mm; }
        .navbar, .app-sidebar, .offcanvas, .print-controls { display: none !important; }
        body { background: #fff !important; }
        .app-content, .app-main, main { padding: 0 !important; margin: 0 !important; }
        .rekap-print-title {
            display: block;
            text-align: center;
            margin: 14px 0 10px;
        }
        .rekap-print-title h2 {
            margin: 0;
            font-size: 12pt;
            font-weight: 800;
            color: #111827;
            text-decoration: underline;
            letter-spacing: 0.3px;
        }
        .rekap-print-title p {
            margin: 2px 0 0;
            font-size: 8.5pt;
            color: #334155;
        }
        .rekap-print-info {
            display: block;
            margin: 0 0 10px;
            font-size: 8.4pt;
            color: #111827;
        }
        .rekap-print-info table {
            width: 100%;
            border-collapse: collapse;
        }
        .rekap-print-info td {
            padding: 1px 0;
            vertical-align: top;
        }
        .rekap-print-info td:first-child {
            width: 120px;
            font-weight: 700;
        }
        .rekap-official-card {
            border: none !important;
            box-shadow: none !important;
            background: transparent !important;
        }
        .rekap-official-card .card-body {
            padding: 0 !important;
        }
        .table-responsive {
            overflow: visible !important;
        }
        .rekap-official-table {
            width: 100% !important;
            border-collapse: collapse !important;
            font-size: 7.8pt !important;
            color: #111827 !important;
        }
        .rekap-official-table th,
        .rekap-official-table td {
            border: 1px solid #334155 !important;
            padding: 4px 5px !important;
            vertical-align: middle !important;
        }
        .rekap-official-table thead th,
        .rekap-print-total th {
            background: #f1f5f9 !important;
            font-weight: 800 !important;
            text-align: center !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        .rekap-official-table td:nth-child(1),
        .rekap-official-table td:nth-child(5),
        .rekap-official-table td:nth-child(6),
        .rekap-official-table td:nth-child(7),
        .rekap-official-table td:nth-child(8),
        .rekap-official-table td:nth-child(9),
        .rekap-official-table td:nth-child(10) {
            text-align: center;
        }
        .rekap-signature {
            display: flex;
            justify-content: space-between;
            gap: 40px;
            margin-top: 22px;
            font-size: 8.5pt;
            break-inside: avoid;
        }
        .rekap-signature-box {
            width: 42%;
            text-align: center;
        }
        .rekap-signature-box p {
            margin: 0 0 2px;
        }
        .rekap-signature-role {
            font-weight: 600;
        }
        .rekap-signature-space {
            height: 22mm;
        }
        .rekap-signature-name {
            border-top: 1px solid #111827;
            padding-top: 6px;
            font-weight: 700;
        }
    }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
