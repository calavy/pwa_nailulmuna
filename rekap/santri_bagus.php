<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/rekap_periode.php';
require_once __DIR__ . '/../helpers/hijri_kalender.php';
require_once __DIR__ . '/../helpers/presensi_jadwal.php';
require_once __DIR__ . '/../helpers/rekap_keaktifan.php';

$rekapKeaktifanPagePath = (string) ($rekapKeaktifanBasePath ?? '/rekap/santri_bagus.php');
$rekapKeaktifanModulKicker = (string) ($rekapKeaktifanModulLabel ?? 'Modul Kajian · Poin & Keaktifan');

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
$tampilan = trim((string) ($_GET['tampilan'] ?? 'semua'));
if (!in_array($tampilan, ['semua', 'santri', 'kegiatan', 'tingkatan'], true)) {
    $tampilan = 'semua';
}
if ($santriId > 0) {
    $tampilan = 'santri';
}
$paper = strtoupper((string) ($_GET['paper'] ?? 'A4'));
if (!in_array($paper, ['A4', 'F4'], true)) {
    $paper = 'A4';
}

$kegiatanList = table_exists($pdo, 'kegiatan')
    ? $pdo->query('SELECT id, nama_kegiatan FROM kegiatan WHERE is_active = 1 ORDER BY nama_kegiatan ASC')->fetchAll(PDO::FETCH_ASSOC)
    : [];
$hijriMonths = hijri_nama_bulan_list();
require_once __DIR__ . '/../helpers/santri_list_sort.php';
santri_list_sort_mode($_GET['santri_sort'] ?? null);
$santriList = $pdo->query('SELECT id, nama_santri, nis, tingkatan FROM santri ORDER BY ' . santri_list_order_sql('santri'))->fetchAll();
$selectedSantri = null;
foreach ($santriList as $santriOption) {
    if ((int) $santriOption['id'] === $santriId) {
        $selectedSantri = $santriOption;
        break;
    }
}

$rawRows = presensi_fetch_rows_rekap($pdo, $startDate, $endDate, $kegiatanId);
if ($tingkatan !== '') {
    $rawRows = array_values(array_filter($rawRows, static function (array $row) use ($tingkatan): bool {
        return strtolower((string) ($row['tingkatan'] ?? '')) === strtolower($tingkatan);
    }));
}
if ($santriId > 0) {
    $rawRows = array_values(array_filter($rawRows, static function (array $row) use ($santriId): bool {
        return (int) ($row['santri_id'] ?? 0) === $santriId;
    }));
}

$goodMax = (int) app_setting($pdo, 'kategori_baik_max', '1');
$mediumMax = (int) app_setting($pdo, 'kategori_sedang_max', '3');
$ranked = rekap_keaktifan_build_per_santri($rawRows, $goodMax, $mediumMax);
$byKegiatan = rekap_keaktifan_build_per_kegiatan($rawRows);
$byTingkatan = rekap_keaktifan_build_per_tingkatan($ranked);

$chartRows = presensi_fetch_rows_rekap($pdo, $startDate, $endDate, $kegiatanId);
$chartRanked = rekap_keaktifan_build_per_santri($chartRows, $goodMax, $mediumMax);
$byTingkatanChart = rekap_keaktifan_build_per_tingkatan($chartRanked);
$tingkatanKategoriPersen = rekap_keaktifan_kategori_persen_per_tingkatan($byTingkatanChart);
$tingkatanKategoriChart = rekap_keaktifan_chart_tingkatan_kategori($tingkatanKategoriPersen);
$showTingkatanKategoriChart = $santriId <= 0 && $tingkatanKategoriPersen !== [];

$kegiatanTanpaScan = rekap_keaktifan_kegiatan_tanpa_scan_bulan(
    $pdo,
    $startDate,
    $endDate,
    $tingkatan !== '' ? $tingkatan : null,
    $kegiatanId
);
$santriTanpaScan = rekap_keaktifan_santri_tanpa_scan_bulan(
    $pdo,
    $startDate,
    $endDate,
    $tingkatan !== '' ? $tingkatan : null
);
$showKegiatanTanpaScan = $santriId <= 0;
$showSantriTanpaScan = $santriId <= 0;

$totalSantriRekap = count($ranked);
$totalHadir = array_sum(array_column($ranked, 'hadir'));
$totalIzin = array_sum(array_column($ranked, 'izin'));
$totalSakit = array_sum(array_column($ranked, 'sakit'));
$totalAlpa = array_sum(array_column($ranked, 'alpa'));
$totalPresensi = array_sum(array_column($ranked, 'total'));
$rataHadir = $totalPresensi > 0 ? round(($totalHadir / $totalPresensi) * 100, 2) : 0;

$cakupanLabel = 'Semua Santri';
if ($selectedSantri) {
    $cakupanLabel = 'Per Santri — ' . (string) $selectedSantri['nama_santri'] . ' (' . (string) $selectedSantri['nis'] . ')';
} elseif ($tingkatan !== '') {
    $cakupanLabel = 'Per Tingkatan — ' . $tingkatan;
} elseif ($kegiatanId > 0) {
    foreach ($kegiatanList as $kg) {
        if ((int) $kg['id'] === $kegiatanId) {
            $cakupanLabel = 'Per Kegiatan — ' . (string) $kg['nama_kegiatan'];
            break;
        }
    }
}

$namaPonpes = app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren');
$jenisPendidikan = app_setting($pdo, 'jenis_pendidikan', '');
$alamatPonpes = app_setting($pdo, 'alamat_ponpes', '-');
$logo = app_pondok_logo_href($pdo, false);
$telpPonpes = app_setting($pdo, 'telp_ponpes', '');
$websitePonpes = app_setting($pdo, 'website_ponpes', '');
$namaPengasuh = app_setting($pdo, 'nama_pengasuh', '');
$pageTitle = 'Rekap Keaktifan Santri';

$buildQuery = static function (array $overrides = []) use ($mode, $month, $year, $tingkatan, $santriId, $kegiatanId, $tampilan, $paper, $rekapKeaktifanPagePath): string {
    $q = [
        'mode' => $mode,
        'month' => $month,
        'year' => $year,
        'tingkatan' => $tingkatan,
        'santri_id' => $santriId,
        'kegiatan_id' => $kegiatanId,
        'tampilan' => $tampilan,
        'paper' => $paper,
    ];
    foreach ($overrides as $k => $v) {
        $q[$k] = $v;
    }

    return app_href($rekapKeaktifanPagePath . '?' . http_build_query($q));
};

require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3 print-controls">
    <p class="page-intro-kicker mb-1"><?= htmlspecialchars($rekapKeaktifanModulKicker) ?></p>
    <h1 class="h4 mb-1">Rekap Keaktifan Santri</h1>
    <p class="text-muted mb-0 small">
        Hanya presensi yang <strong>terikat jadwal</strong> (tingkatan santri masuk jadwal kegiatan).
        Santri di luar jadwal tidak dihitung ALPA walau tidak scan.
    </p>
</div>

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
                <label class="form-label">Tampilan</label>
                <select class="form-select" name="tampilan">
                    <option value="semua" <?= $tampilan === 'semua' ? 'selected' : '' ?>>Semua (tabel)</option>
                    <option value="santri" <?= $tampilan === 'santri' ? 'selected' : '' ?>>Per santri (kartu)</option>
                    <option value="kegiatan" <?= $tampilan === 'kegiatan' ? 'selected' : '' ?>>Per kegiatan</option>
                    <option value="tingkatan" <?= $tampilan === 'tingkatan' ? 'selected' : '' ?>>Per tingkatan</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Kertas</label>
                <select class="form-select" name="paper">
                    <option value="A4" <?= $paper === 'A4' ? 'selected' : '' ?>>A4</option>
                    <option value="F4" <?= $paper === 'F4' ? 'selected' : '' ?>>F4</option>
                </select>
            </div>
            <div class="col-12 d-flex flex-wrap gap-2 align-items-center">
                <button class="btn btn-success">Tampilkan</button>
                <button type="button" class="btn btn-outline-dark" onclick="window.print()">Cetak</button>
                <a href="<?= htmlspecialchars(app_href('/rekap/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Rekap presensi</a>
                <?php if ($rekapKeaktifanPagePath !== '/yayasan/operasional.php'): ?>
                <a href="<?= htmlspecialchars(app_href('/yayasan/operasional.php#yp-keaktifan-bulan')) ?>" class="btn btn-outline-secondary btn-sm">Buka di Dashboard Yayasan</a>
                <?php endif; ?>
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
            <div class="app-mini-stat-label">Santri</div>
            <div class="app-mini-stat-value"><?= (int) $totalSantriRekap ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Hadir</div>
            <div class="app-mini-stat-value text-success"><?= (int) $totalHadir ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">Alpa</div>
            <div class="app-mini-stat-value text-danger"><?= (int) $totalAlpa ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="app-mini-stat h-100">
            <div class="app-mini-stat-label">% Hadir</div>
            <div class="app-mini-stat-value"><?= htmlspecialchars((string) $rataHadir) ?>%</div>
        </div>
    </div>
</div>

<p class="text-muted small mb-3 rekap-keaktifan-meta">
    <?= htmlspecialchars($cakupanLabel) ?> · <?= htmlspecialchars(date('d-m-Y', strtotime($startDate))) ?> s.d. <?= htmlspecialchars(date('d-m-Y', strtotime($endDate))) ?>
    · Hanya sesi jadwal yang relevan
</p>

<div class="card shadow-sm mb-4 keaktifan-kriteria-legend print-controls">
    <div class="card-body py-3">
        <h2 class="h6 mb-2">Kriteria kategori keaktifan</h2>
        <div class="d-flex flex-wrap gap-2">
            <span class="badge text-bg-success">Bagus: Alpa = 0</span>
            <span class="badge text-bg-info">Baik: Alpa 1–<?= (int) $goodMax ?></span>
            <span class="badge text-bg-warning">Sedang: Alpa <?= (int) ($goodMax + 1) ?>–<?= (int) $mediumMax ?></span>
            <span class="badge text-bg-danger">Buruk: Alpa &gt; <?= (int) $mediumMax ?></span>
        </div>
        <p class="small text-muted mb-0 mt-2">Ambang batas dapat diubah di Pengaturan Pondok (kategori baik/sedang max alpa).</p>
    </div>
</div>

<?php if ($showTingkatanKategoriChart): ?>
<div id="grafik-keaktifan-tingkatan" class="card shadow-sm mb-4 keaktifan-tingkatan-chart-card print-controls">
    <div class="card-body">
        <h2 class="h6 mb-1">Perbandingan kategori keaktifan per tingkatan</h2>
        <p class="small text-muted mb-3">
            Bulan <?= htmlspecialchars($periodeLabel) ?>
            (<?= $mode === 'hijriyah' ? 'kalender Hijriyah' : 'kalender Masehi' ?>).
            Persentase dihitung dari jumlah santri per tingkatan pada periode ini.
            <?= $kegiatanId > 0 ? 'Hanya kegiatan terpilih.' : 'Semua kegiatan jadwal.' ?>
        </p>
        <div class="table-responsive mb-4">
            <table class="table table-sm table-striped rekap-official-table mb-0">
                <thead>
                <tr>
                    <th>Tingkatan</th>
                    <th class="text-center">Santri</th>
                    <th class="text-center text-success">Bagus</th>
                    <th class="text-center text-info">Baik</th>
                    <th class="text-center text-warning">Sedang</th>
                    <th class="text-center text-danger">Buruk</th>
                    <th class="text-center text-info">% Baik</th>
                    <th class="text-center text-warning">% Sedang</th>
                    <th class="text-center text-danger">% Buruk</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($tingkatanKategoriPersen as $tg => $tkRow): ?>
                    <tr>
                        <td class="fw-semibold"><?= htmlspecialchars((string) $tg) ?></td>
                        <td class="text-center"><?= (int) $tkRow['santri_count'] ?></td>
                        <?php foreach (rekap_keaktifan_kategori_urutan() as $katKey): ?>
                            <td class="text-center"><?= (int) ($tkRow['kategori'][$katKey] ?? 0) ?></td>
                        <?php endforeach; ?>
                        <?php foreach (rekap_keaktifan_kategori_perbandingan() as $katKey): ?>
                            <td class="text-center fw-semibold"><?= htmlspecialchars((string) ($tkRow['persen'][$katKey] ?? 0)) ?>%</td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="row g-3">
            <div class="col-12 col-xl-7">
                <h3 class="h6 text-secondary">Diagram batang — Baik, Sedang, Buruk (%)</h3>
                <div class="position-relative" style="min-height: 300px;">
                    <canvas id="chartKeaktifanTingkatanGrouped" aria-label="Grafik perbandingan Baik Sedang Buruk per tingkatan"></canvas>
                </div>
            </div>
            <div class="col-12 col-xl-5">
                <h3 class="h6 text-secondary">Komposisi kategori per tingkatan (100%)</h3>
                <div class="position-relative" style="min-height: 300px;">
                    <canvas id="chartKeaktifanTingkatanStacked" aria-label="Grafik komposisi kategori keaktifan per tingkatan"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($showKegiatanTanpaScan): ?>
<div class="card shadow-sm mb-4 keaktifan-kegiatan-kosong-card print-controls">
    <div class="card-body">
        <h2 class="h6 mb-1">Jadwal tanpa scan hadir</h2>
        <p class="small text-muted mb-3">
            Periode <strong><?= htmlspecialchars($periodeLabel) ?></strong>
            (<?= $mode === 'hijriyah' ? 'bulan Hijriyah' : 'bulan Masehi' ?>:
            <?= htmlspecialchars(date('d-m-Y', strtotime($startDate))) ?> s.d. <?= htmlspecialchars(date('d-m-Y', strtotime($endDate))) ?>).
            Setiap baris = satu jadwal kegiatan (tanggal + tingkatan) tanpa satupun scan <strong>hadir</strong>.
            <?= $tingkatan !== '' ? 'Filter tingkatan: <strong>' . htmlspecialchars($tingkatan) . '</strong>.' : '' ?>
            <?= $kegiatanId > 0 ? 'Filter kegiatan aktif.' : '' ?>
        </p>
        <?php if ($kegiatanTanpaScan === []): ?>
            <div class="alert alert-success py-2 mb-0 small">
                Semua jadwal kegiatan yang sudah lewat waktu pada periode ini sudah pernah discan hadir oleh santri.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-striped rekap-official-table mb-3">
                    <thead>
                    <tr>
                        <th style="width:3rem">No</th>
                        <th>Tanggal</th>
                        <th>Nama kegiatan</th>
                        <th>Waktu</th>
                        <th>Tingkatan</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($kegiatanTanpaScan as $idx => $kgRow): ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td class="text-nowrap small">
                                <?= htmlspecialchars((string) ($kgRow['tanggal_tampil'] ?? '')) ?>
                                <span class="text-muted d-block"><?= htmlspecialchars((string) ($kgRow['hari'] ?? '')) ?></span>
                                <?php if (!empty($kgRow['tanggal_hijri'])): ?>
                                    <span class="text-muted d-block"><?= htmlspecialchars((string) $kgRow['tanggal_hijri']) ?> H</span>
                                <?php endif; ?>
                            </td>
                            <td class="fw-semibold text-danger"><?= htmlspecialchars((string) $kgRow['nama_kegiatan']) ?></td>
                            <td class="small text-nowrap"><?= htmlspecialchars((string) ($kgRow['jam'] ?? '')) ?></td>
                            <td><?= htmlspecialchars((string) $kgRow['tingkatan_label']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small text-muted mb-0">
                Total: <strong><?= count($kegiatanTanpaScan) ?></strong> jadwal tanpa scan hadir pada periode ini.
            </p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($showSantriTanpaScan): ?>
<div class="card shadow-sm mb-4 keaktifan-santri-kosong-card print-controls">
    <div class="card-body">
        <h2 class="h6 mb-1">Santri tanpa scan hadir sama sekali</h2>
        <p class="small text-muted mb-3">
            Periode <strong><?= htmlspecialchars($periodeLabel) ?></strong>
            (<?= $mode === 'hijriyah' ? 'bulan Hijriyah' : 'bulan Masehi' ?>).
            Santri aktif yang <strong>masuk jadwal</strong> kegiatan pada bulan ini tetapi tidak pernah scan status <strong>hadir</strong>.
            <?= $tingkatan !== '' ? 'Filter tingkatan: <strong>' . htmlspecialchars($tingkatan) . '</strong>.' : '' ?>
        </p>
        <?php if ($santriTanpaScan === []): ?>
            <div class="alert alert-success py-2 mb-0 small">
                Semua santri yang terikat jadwal pada periode ini sudah pernah scan hadir minimal sekali.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-striped rekap-official-table mb-3">
                    <thead>
                    <tr>
                        <th style="width:3rem">No</th>
                        <th>NIS</th>
                        <th>Nama santri</th>
                        <th>Tingkatan</th>
                        <th class="text-center">Hari wajib</th>
                        <th class="text-center">Slot jadwal</th>
                        <th class="print-controls"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($santriTanpaScan as $idx => $sRow): ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td><?= htmlspecialchars((string) $sRow['nis']) ?></td>
                            <td class="fw-semibold text-danger"><?= htmlspecialchars((string) $sRow['nama_santri']) ?></td>
                            <td><?= htmlspecialchars((string) $sRow['tingkatan']) ?></td>
                            <td class="text-center"><?= (int) $sRow['hari_wajib'] ?></td>
                            <td class="text-center"><?= (int) $sRow['slot_wajib'] ?></td>
                            <td class="print-controls">
                                <a href="<?= htmlspecialchars($buildQuery(['santri_id' => (int) $sRow['santri_id'], 'tampilan' => 'santri'])) ?>" class="btn btn-sm btn-outline-primary">Kartu</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small text-muted mb-0">
                Total: <strong><?= count($santriTanpaScan) ?></strong> santri belum pernah scan hadir pada bulan ini.
            </p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($tampilan === 'santri'): ?>
    <?php if ($ranked === []): ?>
        <div class="alert alert-light border">Tidak ada data keaktifan pada filter ini.</div>
    <?php else: ?>
        <div class="row g-3 keaktifan-card-grid">
            <?php foreach ($ranked as $row): ?>
                <?php
                $badge = rekap_keaktifan_kategori_badge_class((string) $row['kategori']);
                $colClass = $santriId > 0 ? 'col-12' : 'col-12 col-md-6 col-xl-4';
                ?>
                <div class="<?= htmlspecialchars($colClass) ?>">
                    <article class="card keaktifan-santri-card h-100 border-0 shadow-sm">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                <div class="min-w-0">
                                    <h2 class="h6 mb-0"><?= htmlspecialchars((string) $row['nama_santri']) ?></h2>
                                    <p class="small text-muted mb-0">NIS <?= htmlspecialchars((string) $row['nis']) ?> · <?= htmlspecialchars((string) $row['tingkatan']) ?></p>
                                </div>
                                <span class="badge text-bg-<?= htmlspecialchars($badge) ?>"><?= htmlspecialchars((string) $row['kategori']) ?></span>
                            </div>
                            <div class="row g-3 align-items-center mb-3">
                                <div class="col-auto">
                                    <div class="keaktifan-ring" style="--pct: <?= min(100, max(0, (float) $row['persen_hadir'])) ?>;">
                                        <div class="keaktifan-ring-inner">
                                            <span class="keaktifan-ring-value"><?= htmlspecialchars((string) $row['persen_hadir']) ?>%</span>
                                            <span class="keaktifan-ring-label">Hadir</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col">
                                    <div class="row g-2 text-center keaktifan-stat-row">
                                        <div class="col-3">
                                            <div class="keaktifan-stat keaktifan-stat--hadir">
                                                <div class="keaktifan-stat-n"><?= (int) $row['hadir'] ?></div>
                                                <div class="keaktifan-stat-l">Hadir</div>
                                            </div>
                                        </div>
                                        <div class="col-3">
                                            <div class="keaktifan-stat keaktifan-stat--alpa">
                                                <div class="keaktifan-stat-n"><?= (int) $row['alpa'] ?></div>
                                                <div class="keaktifan-stat-l">Alpa</div>
                                            </div>
                                        </div>
                                        <div class="col-3">
                                            <div class="keaktifan-stat keaktifan-stat--izin">
                                                <div class="keaktifan-stat-n"><?= (int) $row['izin'] ?></div>
                                                <div class="keaktifan-stat-l">Izin</div>
                                            </div>
                                        </div>
                                        <div class="col-3">
                                            <div class="keaktifan-stat keaktifan-stat--sakit">
                                                <div class="keaktifan-stat-n"><?= (int) $row['sakit'] ?></div>
                                                <div class="keaktifan-stat-l">Sakit</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php if (!empty($row['per_kegiatan'])): ?>
                                <section class="keaktifan-kegiatan-block mt-2">
                                    <h3 class="h6 text-uppercase text-muted fw-bold mb-2">Rincian per kegiatan</h3>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered keaktifan-kegiatan-table mb-0">
                                            <thead>
                                            <tr>
                                                <th>Kegiatan</th>
                                                <th class="text-center">Hadir</th>
                                                <th class="text-center">Alpa</th>
                                                <th class="text-center">Izin</th>
                                                <th class="text-center">Sakit</th>
                                                <th class="text-center">Total</th>
                                                <th class="text-center">%</th>
                                                <th class="text-center">Kategori</th>
                                            </tr>
                                            </thead>
                                            <tbody>
                                            <?php foreach ($row['per_kegiatan'] as $namaKg => $kg): ?>
                                                <?php $kgBadge = rekap_keaktifan_kategori_badge_class((string) ($kg['kategori'] ?? 'Buruk')); ?>
                                                <tr>
                                                    <td class="fw-semibold"><?= htmlspecialchars($namaKg) ?></td>
                                                    <td class="text-center text-success"><?= (int) $kg['hadir'] ?></td>
                                                    <td class="text-center text-danger"><?= (int) $kg['alpa'] ?></td>
                                                    <td class="text-center"><?= (int) $kg['izin'] ?></td>
                                                    <td class="text-center"><?= (int) $kg['sakit'] ?></td>
                                                    <td class="text-center"><?= (int) $kg['total'] ?></td>
                                                    <td class="text-center"><?= htmlspecialchars((string) ($kg['persen_hadir'] ?? 0)) ?>%</td>
                                                    <td class="text-center">
                                                        <span class="badge text-bg-<?= htmlspecialchars($kgBadge) ?>"><?= htmlspecialchars((string) ($kg['kategori'] ?? '-')) ?></span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </section>
                            <?php endif; ?>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php elseif ($tampilan === 'kegiatan'): ?>
    <div class="card shadow-sm rekap-official-card">
        <div class="card-body">
            <h2 class="h5 print-controls">Rekap per kegiatan</h2>
            <div class="table-responsive">
                <table class="table table-sm table-striped rekap-official-table">
                    <thead>
                    <tr>
                        <th>Kegiatan</th>
                        <th class="text-center">Santri</th>
                        <th class="text-center">Hadir</th>
                        <th class="text-center">Alpa</th>
                        <th class="text-center">Izin</th>
                        <th class="text-center">Sakit</th>
                        <th class="text-center">Total</th>
                        <th class="text-center">% Hadir</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($byKegiatan as $namaKg => $kg): ?>
                        <?php $pct = (int) $kg['total'] > 0 ? round(((int) $kg['hadir'] / (int) $kg['total']) * 100, 1) : 0; ?>
                        <tr>
                            <td class="fw-semibold"><?= htmlspecialchars($namaKg) ?></td>
                            <td class="text-center"><?= (int) $kg['santri_count'] ?></td>
                            <td class="text-center text-success"><?= (int) $kg['hadir'] ?></td>
                            <td class="text-center text-danger"><?= (int) $kg['alpa'] ?></td>
                            <td class="text-center"><?= (int) $kg['izin'] ?></td>
                            <td class="text-center"><?= (int) $kg['sakit'] ?></td>
                            <td class="text-center"><?= (int) $kg['total'] ?></td>
                            <td class="text-center"><?= htmlspecialchars((string) $pct) ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($byKegiatan === []): ?>
                        <tr><td colspan="8" class="text-center text-muted">Tidak ada data.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($tampilan === 'tingkatan'): ?>
    <?php if ($byTingkatan === []): ?>
        <div class="alert alert-light border">Tidak ada data keaktifan per tingkatan.</div>
    <?php else: ?>
        <div class="card shadow-sm rekap-official-card mb-4">
            <div class="card-body">
                <h2 class="h5 print-controls">Ringkasan per tingkatan</h2>
                <div class="table-responsive">
                    <table class="table table-sm table-striped rekap-official-table">
                        <thead>
                        <tr>
                            <th>Tingkatan</th>
                            <th class="text-center">Santri</th>
                            <th class="text-center">Hadir</th>
                            <th class="text-center">Alpa</th>
                            <th class="text-center">Izin</th>
                            <th class="text-center">Sakit</th>
                            <th class="text-center">Total</th>
                            <th class="text-center">% Hadir</th>
                            <th class="text-center text-success">Bagus</th>
                            <th class="text-center text-info">Baik</th>
                            <th class="text-center text-warning">Sedang</th>
                            <th class="text-center text-danger">Buruk</th>
                            <th class="text-center text-info">% Baik</th>
                            <th class="text-center text-warning">% Sedang</th>
                            <th class="text-center text-danger">% Buruk</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($byTingkatan as $tg => $data): ?>
                            <?php
                            $tkPersen = $tingkatanKategoriPersen[$tg]['persen'] ?? [];
                            $tkTotal = (int) ($data['santri_count'] ?? 0);
                            ?>
                            <tr>
                                <td class="fw-semibold"><?= htmlspecialchars($tg) ?></td>
                                <td class="text-center"><?= $tkTotal ?></td>
                                <td class="text-center text-success"><?= (int) $data['hadir'] ?></td>
                                <td class="text-center text-danger"><?= (int) $data['alpa'] ?></td>
                                <td class="text-center"><?= (int) $data['izin'] ?></td>
                                <td class="text-center"><?= (int) $data['sakit'] ?></td>
                                <td class="text-center"><?= (int) $data['total'] ?></td>
                                <td class="text-center"><?= htmlspecialchars((string) $data['persen_hadir']) ?>%</td>
                                <?php foreach (rekap_keaktifan_kategori_urutan() as $katKey): ?>
                                    <td class="text-center fw-semibold"><?= (int) ($data['kategori'][$katKey] ?? 0) ?></td>
                                <?php endforeach; ?>
                                <?php foreach (rekap_keaktifan_kategori_perbandingan() as $katKey): ?>
                                    <td class="text-center fw-semibold"><?= htmlspecialchars((string) ($tkPersen[$katKey] ?? ($tkTotal > 0 ? round(((int) ($data['kategori'][$katKey] ?? 0) / $tkTotal) * 100, 1) : 0))) ?>%</td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php foreach ($byTingkatan as $tg => $data): ?>
            <div class="card shadow-sm mb-4 keaktifan-tingkatan-card">
                <div class="card-body">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                        <h2 class="h5 mb-0">Tingkatan <?= htmlspecialchars($tg) ?></h2>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach (rekap_keaktifan_kategori_urutan() as $katKey): ?>
                                <?php $katBadge = rekap_keaktifan_kategori_badge_class($katKey); ?>
                                <?php $katCount = (int) ($data['kategori'][$katKey] ?? 0); ?>
                                <?php if ($katCount > 0): ?>
                                    <span class="badge text-bg-<?= htmlspecialchars($katBadge) ?>"><?= htmlspecialchars($katKey) ?>: <?= $katCount ?></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php foreach (rekap_keaktifan_kategori_urutan() as $katKey): ?>
                        <?php $santriKat = $data['santri_by_kategori'][$katKey] ?? []; ?>
                        <?php if ($santriKat === []) { continue; } ?>
                        <?php $katBadge = rekap_keaktifan_kategori_badge_class($katKey); ?>
                        <div class="keaktifan-kategori-group mb-3">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge text-bg-<?= htmlspecialchars($katBadge) ?>"><?= htmlspecialchars($katKey) ?></span>
                                <span class="small text-muted"><?= count($santriKat) ?> santri</span>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered mb-0">
                                    <thead>
                                    <tr>
                                        <th>NIS</th>
                                        <th>Nama</th>
                                        <th class="text-center">Hadir</th>
                                        <th class="text-center">Alpa</th>
                                        <th class="text-center">Izin</th>
                                        <th class="text-center">Sakit</th>
                                        <th class="text-center">Total</th>
                                        <th class="text-center">%</th>
                                        <th class="print-controls"></th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($santriKat as $sRow): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string) $sRow['nis']) ?></td>
                                            <td class="fw-semibold"><?= htmlspecialchars((string) $sRow['nama_santri']) ?></td>
                                            <td class="text-center text-success"><?= (int) $sRow['hadir'] ?></td>
                                            <td class="text-center text-danger"><?= (int) $sRow['alpa'] ?></td>
                                            <td class="text-center"><?= (int) $sRow['izin'] ?></td>
                                            <td class="text-center"><?= (int) $sRow['sakit'] ?></td>
                                            <td class="text-center"><?= (int) $sRow['total'] ?></td>
                                            <td class="text-center"><?= htmlspecialchars((string) $sRow['persen_hadir']) ?>%</td>
                                            <td class="print-controls">
                                                <a href="<?= htmlspecialchars($buildQuery(['santri_id' => (int) $sRow['santri_id'], 'tampilan' => 'santri', 'tingkatan' => $tg])) ?>" class="btn btn-sm btn-outline-primary">Kartu</a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

<?php else: ?>
    <div class="card shadow-sm rekap-official-card">
        <div class="card-body">
            <h2 class="h5 print-controls">Daftar keaktifan (semua santri)</h2>
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
                        <th class="print-controls"></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($ranked as $idx => $row): ?>
                        <?php $badge = rekap_keaktifan_kategori_badge_class((string) $row['kategori']); ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td><?= htmlspecialchars((string) $row['nis']) ?></td>
                            <td><?= htmlspecialchars((string) $row['nama_santri']) ?></td>
                            <td><?= htmlspecialchars((string) $row['tingkatan']) ?></td>
                            <td class="text-center"><?= (int) $row['hadir'] ?></td>
                            <td class="text-center"><?= (int) $row['alpa'] ?></td>
                            <td class="text-center"><?= (int) $row['izin'] ?></td>
                            <td class="text-center"><?= (int) $row['sakit'] ?></td>
                            <td class="text-center"><?= (int) $row['total'] ?></td>
                            <td class="text-center"><?= htmlspecialchars((string) $row['persen_hadir']) ?>%</td>
                            <td><span class="badge text-bg-<?= htmlspecialchars($badge) ?>"><?= htmlspecialchars((string) $row['kategori']) ?></span></td>
                            <td class="print-controls">
                                <a href="<?= htmlspecialchars($buildQuery(['santri_id' => (int) $row['santri_id'], 'tampilan' => 'santri'])) ?>" class="btn btn-sm btn-outline-primary">Kartu</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($ranked === []): ?>
                        <tr><td colspan="12" class="text-center text-muted">Tidak ada data pada filter ini.</td></tr>
                    <?php endif; ?>
                    </tbody>
                    <?php if ($ranked !== []): ?>
                        <tfoot class="rekap-print-total">
                        <tr>
                            <th colspan="4">Total</th>
                            <th><?= (int) $totalHadir ?></th>
                            <th><?= (int) $totalAlpa ?></th>
                            <th><?= (int) $totalIzin ?></th>
                            <th><?= (int) $totalSakit ?></th>
                            <th><?= (int) $totalPresensi ?></th>
                            <th><?= htmlspecialchars((string) $rataHadir) ?>%</th>
                            <th colspan="2">-</th>
                        </tr>
                        </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

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
    .keaktifan-santri-card { border-radius: 12px; overflow: hidden; }
    .keaktifan-ring {
        --ring-size: 88px;
        width: var(--ring-size);
        height: var(--ring-size);
        border-radius: 50%;
        background: conic-gradient(#198754 calc(var(--pct) * 1%), #e9ecef 0);
        display: flex;
        align-items: center;
        justify-content: center;
    }
    .keaktifan-ring-inner {
        width: calc(var(--ring-size) - 14px);
        height: calc(var(--ring-size) - 14px);
        border-radius: 50%;
        background: var(--bs-body-bg, #fff);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        line-height: 1.1;
    }
    .keaktifan-ring-value { font-weight: 700; font-size: 1rem; }
    .keaktifan-ring-label { font-size: 0.65rem; color: #6c757d; text-transform: uppercase; }
    .keaktifan-stat { padding: 0.35rem 0.25rem; border-radius: 8px; background: #f8f9fa; }
    .keaktifan-stat-n { font-weight: 700; font-size: 1.05rem; }
    .keaktifan-stat-l { font-size: 0.65rem; color: #6c757d; text-transform: uppercase; }
    .keaktifan-stat--hadir .keaktifan-stat-n { color: #198754; }
    .keaktifan-stat--alpa .keaktifan-stat-n { color: #dc3545; }
    .keaktifan-stat--izin .keaktifan-stat-n { color: #0d6efd; }
    .keaktifan-stat--sakit .keaktifan-stat-n { color: #fd7e14; }
    .keaktifan-kegiatan-table th { font-size: 0.75rem; white-space: nowrap; }
    .keaktifan-tingkatan-card { break-inside: avoid; }
    .keaktifan-tingkatan-chart-card { break-inside: avoid; }

    @media print {
        @page { size: <?= $paper === 'F4' ? '8.5in 13in' : 'A4' ?> portrait; margin: 12mm; }
        .navbar, .app-sidebar, .offcanvas, .print-controls, .keaktifan-kriteria-legend, .keaktifan-tingkatan-chart-card, .keaktifan-kegiatan-kosong-card, .keaktifan-santri-kosong-card { display: none !important; }
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
        .rekap-official-table,
        .keaktifan-kegiatan-table {
            width: 100% !important;
            border-collapse: collapse !important;
            font-size: 7.8pt !important;
            color: #111827 !important;
        }
        .rekap-official-table th,
        .rekap-official-table td,
        .keaktifan-kegiatan-table th,
        .keaktifan-kegiatan-table td {
            border: 1px solid #334155 !important;
            padding: 4px 5px !important;
            vertical-align: middle !important;
        }
        .rekap-official-table thead th,
        .rekap-print-total th,
        .keaktifan-kegiatan-table thead th {
            background: #f1f5f9 !important;
            font-weight: 800 !important;
            text-align: center !important;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
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
        .keaktifan-santri-card,
        .keaktifan-tingkatan-card {
            break-inside: avoid;
            border: 1px solid #dee2e6 !important;
            box-shadow: none !important;
            margin-bottom: 12px;
        }
        .keaktifan-santri-card .card-body {
            padding: 10px !important;
        }
    }
</style>

<?php if ($showTingkatanKategoriChart): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
    const labels = <?= json_encode($tingkatanKategoriChart['labels'], JSON_UNESCAPED_UNICODE) ?>;
    const grouped = <?= json_encode($tingkatanKategoriChart['datasets'], JSON_UNESCAPED_UNICODE) ?>;
    const stacked = <?= json_encode($tingkatanKategoriChart['stacked_datasets'], JSON_UNESCAPED_UNICODE) ?>;

    const groupedEl = document.getElementById('chartKeaktifanTingkatanGrouped');
    if (groupedEl && labels.length) {
        new Chart(groupedEl, {
            type: 'bar',
            data: { labels: labels, datasets: grouped },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: { callback: function (v) { return v + '%'; } },
                        title: { display: true, text: 'Persentase santri' }
                    }
                },
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ctx.dataset.label + ': ' + ctx.parsed.y + '%';
                            }
                        }
                    }
                }
            }
        });
    }

    const stackedEl = document.getElementById('chartKeaktifanTingkatanStacked');
    if (stackedEl && labels.length) {
        new Chart(stackedEl, {
            type: 'bar',
            data: { labels: labels, datasets: stacked },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: { stacked: true },
                    y: {
                        stacked: true,
                        beginAtZero: true,
                        max: 100,
                        ticks: { callback: function (v) { return v + '%'; } }
                    }
                },
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ctx.dataset.label + ': ' + ctx.parsed.y + '%';
                            }
                        }
                    }
                }
            }
        });
    }
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
