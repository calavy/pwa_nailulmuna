<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/hijri_kalender.php';
require_once __DIR__ . '/../helpers/rekap_periode.php';
require_once __DIR__ . '/../helpers/rekap_keaktifan.php';

require_roles(['admin', 'pengurus', 'petugas_absensi']);

if (!table_exists($pdo, 'presensi')) {
    set_flash('error', 'Tabel presensi belum ada.');
    header('Location: ' . app_href('/dashboard.php'));
    exit;
}

$periode = [
    'mode' => 'masehi',
    'month' => (int) date('n'),
    'year' => (int) date('Y'),
    'start_date' => date('Y-m-01'),
    'end_date' => date('Y-m-t'),
    'label' => date('F') . ' ' . date('Y'),
    'hijri_label' => '',
];
$queryError = '';
$periodeWarning = '';
try {
    $periode = rekap_resolve_periode($pdo, $_GET);
} catch (Throwable $e) {
    error_log('[presensi/rekap_tanpa_scan] periode: ' . $e->getMessage());
    $periodeWarning = 'Periode kalender gagal dimuat — menampilkan bulan masehi berjalan.';
}
$startDate = (string) $periode['start_date'];
$endDate = (string) $periode['end_date'];
$periodeLabel = (string) $periode['label'];
$mode = (string) $periode['mode'];
$month = (int) $periode['month'];
$year = (int) $periode['year'];
$tingkatan = trim((string) ($_GET['tingkatan'] ?? ''));
$hijriMonths = hijri_nama_bulan_list();
$masehiMonths = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
];

$tingkatanList = [];
if (table_exists($pdo, 'tingkatan')) {
    $tingkatanList = $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY nama_tingkatan ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];
}
if ($tingkatanList === [] && table_exists($pdo, 'santri')) {
    $tingkatanList = $pdo->query('
        SELECT DISTINCT TRIM(tingkatan) AS t
        FROM santri
        WHERE tingkatan IS NOT NULL AND TRIM(tingkatan) <> ""
        ORDER BY t ASC
    ')->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

$kegiatanTanpaScan = [];
try {
    $kegiatanTanpaScan = rekap_keaktifan_kegiatan_tanpa_scan_bulan(
        $pdo,
        $startDate,
        $endDate,
        $tingkatan !== '' ? $tingkatan : null
    );
} catch (Throwable $e) {
    error_log('[presensi/rekap_tanpa_scan] data: ' . $e->getMessage());
    $queryError = 'Gagal memuat data rekap. Coba bulan lain atau hubungi admin.';
}

$jumlahTanpaScan = count($kegiatanTanpaScan);
$tglAwal = date('d/m/Y', strtotime($startDate) ?: time());
$tglAkhir = date('d/m/Y', strtotime($endDate) ?: time());
$hijriLabelPeriode = (string) ($periode['hijri_label'] ?? '');

$pageTitle = 'Jadwal Tanpa Scan';
$bodyClass = 'presensi-rekap-tanpa-scan-page';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
.presensi-rekap-tanpa-scan-page .rts-filter {
    background: var(--bs-body-bg);
    border: 1px solid var(--bs-border-color);
    border-radius: 12px;
    padding: 1rem 1.1rem;
}
.presensi-rekap-tanpa-scan-page .rts-ringkasan {
    border-radius: 14px;
    padding: 1.25rem 1.35rem;
    border: 1px solid var(--bs-border-color);
    background: linear-gradient(135deg, #fef2f2 0%, #fff 55%);
}
[data-theme="dark"] .presensi-rekap-tanpa-scan-page .rts-ringkasan {
    background: linear-gradient(135deg, rgba(127,29,29,.25) 0%, var(--bs-body-bg) 60%);
}
.presensi-rekap-tanpa-scan-page .rts-ringkasan--ok {
    background: linear-gradient(135deg, #ecfdf5 0%, #fff 55%);
}
[data-theme="dark"] .presensi-rekap-tanpa-scan-page .rts-ringkasan--ok {
    background: linear-gradient(135deg, rgba(6,95,70,.2) 0%, var(--bs-body-bg) 60%);
}
.presensi-rekap-tanpa-scan-page .rts-angka {
    font-size: 2.75rem;
    font-weight: 800;
    line-height: 1;
}
.presensi-rekap-tanpa-scan-page .rts-info {
    font-size: .875rem;
    line-height: 1.5;
    border-left: 3px solid #3b82f6;
    padding: .65rem .85rem;
    background: rgba(59,130,246,.06);
    border-radius: 0 8px 8px 0;
}
</style>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="<?= htmlspecialchars(app_href('/presensi/scan.php')) ?>">Presensi</a>
    </p>
    <h1 class="h4 mb-1">Jadwal belum ada scan hadir</h1>
    <p class="text-muted mb-0 small">
        Hitung <strong>per jadwal kegiatan</strong> (tanggal + tingkatan): 1 kali jadwal tanpa scan hadir = <strong>1</strong>.
        Periode mengikuti bulan <?= $mode === 'hijriyah' ? 'Hijriyah' : 'Masehi' ?> yang dipilih.
    </p>
</div>

<div class="rts-info mb-3">
    <strong>Artinya:</strong> setiap baris = satu jadwal kegiatan yang sudah lewat waktunya tetapi belum ada scan hadir santri.
    Jika Subuh tanpa scan 5 hari dalam bulan ini, angka rekap = <strong>5</strong> (bukan 1).
</div>

<form method="get" action="<?= htmlspecialchars(app_href('/presensi/rekap_tanpa_scan.php')) ?>" class="rts-filter mb-3">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Kalender</label>
            <select name="mode" class="form-select form-select-sm">
                <option value="masehi" <?= $mode === 'masehi' ? 'selected' : '' ?>>Masehi</option>
                <option value="hijriyah" <?= $mode === 'hijriyah' ? 'selected' : '' ?>>Hijriyah</option>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Bulan</label>
            <select name="month" class="form-select form-select-sm">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <?php
                    $bulanLabel = $mode === 'hijriyah'
                        ? ($hijriMonths[$m] ?? (string) $m)
                        : ($masehiMonths[$m] ?? (string) $m);
                    ?>
                    <option value="<?= $m ?>" <?= $month === $m ? 'selected' : '' ?>><?= htmlspecialchars($bulanLabel) ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Tahun</label>
            <input type="number" name="year" class="form-control form-control-sm" value="<?= $year ?>" min="1300" max="2100">
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Tingkatan <span class="text-muted">(opsional)</span></label>
            <select name="tingkatan" class="form-select form-select-sm">
                <option value="">Semua tingkatan</option>
                <?php foreach ($tingkatanList as $tk): ?>
                    <option value="<?= htmlspecialchars((string) $tk) ?>" <?= $tingkatan === (string) $tk ? 'selected' : '' ?>><?= htmlspecialchars((string) $tk) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-2">
            <button type="submit" class="btn btn-primary btn-sm w-100">
                <i class="fa-solid fa-magnifying-glass me-1"></i> Tampilkan
            </button>
        </div>
    </div>
    <p class="small text-muted mb-0 mt-2">
        Periode <?= $mode === 'hijriyah' ? 'Hijriyah' : 'Masehi' ?>: <strong><?= htmlspecialchars($periodeLabel) ?></strong>
        (<?= htmlspecialchars($tglAwal) ?> – <?= htmlspecialchars($tglAkhir) ?> masehi)
        <?php if ($hijriLabelPeriode !== '' && $mode === 'masehi'): ?>
            · setara Hijriyah: <?= htmlspecialchars($hijriLabelPeriode) ?>
        <?php endif; ?>
        <?= $tingkatan !== '' ? ' · Tingkatan: <strong>' . htmlspecialchars($tingkatan) . '</strong>' : '' ?>
    </p>
</form>

<?php if ($periodeWarning !== ''): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="fa-solid fa-triangle-exclamation mt-1"></i>
        <div><?= htmlspecialchars($periodeWarning) ?></div>
    </div>
<?php endif; ?>

<?php if ($queryError !== ''): ?>
    <div class="alert alert-danger d-flex align-items-start gap-2">
        <i class="fa-solid fa-circle-exclamation mt-1"></i>
        <div><?= htmlspecialchars($queryError) ?></div>
    </div>
<?php else: ?>
    <div class="rts-ringkasan mb-3<?= $jumlahTanpaScan === 0 ? ' rts-ringkasan--ok' : '' ?>">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="rts-angka <?= $jumlahTanpaScan > 0 ? 'text-danger' : 'text-success' ?>"><?= $jumlahTanpaScan ?></div>
            <div class="flex-grow-1">
                <?php if ($jumlahTanpaScan === 0): ?>
                    <div class="fw-semibold text-success mb-1">
                        <i class="fa-solid fa-circle-check me-1"></i> Tidak ada masalah scan
                    </div>
                    <p class="small text-muted mb-0">
                        Semua jadwal kegiatan yang sudah lewat waktu pada periode ini sudah pernah ada scan hadir.
                    </p>
                <?php else: ?>
                    <div class="fw-semibold text-danger mb-1">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i> Perlu ditindaklanjuti
                    </div>
                    <p class="small text-muted mb-0">
                        Ada <strong><?= $jumlahTanpaScan ?></strong> jadwal kegiatan tanpa scan hadir
                        <?= $tingkatan !== '' ? ' (tingkatan ' . htmlspecialchars($tingkatan) . ')' : '' ?>.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($jumlahTanpaScan > 0): ?>
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white fw-semibold py-2">
            Daftar jadwal tanpa scan (<?= $jumlahTanpaScan ?>)
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th class="text-center" style="width:3rem">No</th>
                        <th>Tanggal</th>
                        <th>Kegiatan</th>
                        <th>Waktu</th>
                        <th>Tingkatan</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($kegiatanTanpaScan as $idx => $kgRow): ?>
                        <tr>
                            <td class="text-center text-muted"><?= $idx + 1 ?></td>
                            <td class="text-nowrap">
                                <span class="fw-semibold"><?= htmlspecialchars((string) ($kgRow['tanggal_tampil'] ?? '')) ?></span>
                                <span class="text-muted small d-block"><?= htmlspecialchars((string) ($kgRow['hari'] ?? '')) ?></span>
                                <?php if (!empty($kgRow['tanggal_hijri'])): ?>
                                    <span class="text-muted small d-block"><?= htmlspecialchars((string) $kgRow['tanggal_hijri']) ?> H</span>
                                <?php endif; ?>
                            </td>
                            <td class="fw-semibold"><?= htmlspecialchars((string) $kgRow['nama_kegiatan']) ?></td>
                            <td class="small text-nowrap"><?= htmlspecialchars((string) ($kgRow['jam'] ?? '')) ?></td>
                            <td class="small"><?= htmlspecialchars((string) $kgRow['tingkatan_label']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
