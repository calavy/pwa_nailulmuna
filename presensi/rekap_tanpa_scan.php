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

$periode = rekap_resolve_periode($pdo, $_GET);
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
$queryError = '';
try {
    $kegiatanTanpaScan = rekap_keaktifan_kegiatan_tanpa_scan_bulan(
        $pdo,
        $startDate,
        $endDate,
        $tingkatan !== '' ? $tingkatan : null
    );
} catch (Throwable $e) {
    error_log('[presensi/rekap_tanpa_scan] ' . $e->getMessage());
    $queryError = 'Gagal memuat rekap. Coba bulan lain atau hubungi admin.';
}

$jumlahTanpaScan = count($kegiatanTanpaScan);
$totalTidakScan = array_sum(array_map(static fn(array $r): int => (int) ($r['jumlah_tidak_scan'] ?? 0), $kegiatanTanpaScan));
$tglAwal = date('d/m/Y', strtotime($startDate) ?: time());
$tglAkhir = date('d/m/Y', strtotime($endDate) ?: time());

$pageTitle = 'Kegiatan Tanpa Scan';
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
.presensi-rekap-tanpa-scan-page .rts-list {
    list-style: none;
    margin: 0;
    padding: 0;
}
.presensi-rekap-tanpa-scan-page .rts-item {
    border: 1px solid var(--bs-border-color);
    border-radius: 10px;
    margin-bottom: .5rem;
    background: var(--bs-body-bg);
    overflow: hidden;
}
.presensi-rekap-tanpa-scan-page .rts-item__head {
    display: flex;
    align-items: flex-start;
    gap: .75rem;
    padding: .85rem 1rem;
    width: 100%;
    border: 0;
    background: transparent;
    text-align: left;
    cursor: pointer;
}
.presensi-rekap-tanpa-scan-page .rts-item__head:hover {
    background: rgba(0,0,0,.025);
}
[data-theme="dark"] .presensi-rekap-tanpa-scan-page .rts-item__head:hover {
    background: rgba(255,255,255,.04);
}
.presensi-rekap-tanpa-scan-page .rts-item__head[aria-expanded="true"] {
    border-bottom: 1px solid var(--bs-border-color);
    background: rgba(254,226,226,.25);
}
.presensi-rekap-tanpa-scan-page .rts-item__chev {
    flex: 0 0 1rem;
    color: var(--bs-secondary-color);
    margin-top: .2rem;
    transition: transform .15s ease;
}
.presensi-rekap-tanpa-scan-page .rts-item__head[aria-expanded="true"] .rts-item__chev {
    transform: rotate(90deg);
}
.presensi-rekap-tanpa-scan-page .rts-item__count {
    flex: 0 0 auto;
    min-width: 2.5rem;
    text-align: center;
    font-weight: 700;
    font-size: .95rem;
    padding: .35rem .55rem;
    border-radius: 8px;
    background: #fee2e2;
    color: #b91c1c;
    line-height: 1.1;
}
[data-theme="dark"] .presensi-rekap-tanpa-scan-page .rts-item__count {
    background: rgba(185,28,28,.35);
    color: #fecaca;
}
.presensi-rekap-tanpa-scan-page .rts-item__count small {
    display: block;
    font-size: .62rem;
    font-weight: 600;
    opacity: .85;
}
.presensi-rekap-tanpa-scan-page .rts-item__detail {
    padding: .65rem 1rem .85rem 3.5rem;
    background: rgba(248,250,252,.7);
}
[data-theme="dark"] .presensi-rekap-tanpa-scan-page .rts-item__detail {
    background: rgba(15,23,42,.35);
}
.presensi-rekap-tanpa-scan-page .rts-item__detail[hidden] {
    display: none !important;
}
.presensi-rekap-tanpa-scan-page .rts-detail-table {
    font-size: .82rem;
    margin: 0;
}
.presensi-rekap-tanpa-scan-page .rts-detail-table th {
    font-weight: 600;
    white-space: nowrap;
}
.presensi-rekap-tanpa-scan-page .rts-item__no {
    flex: 0 0 1.75rem;
    height: 1.75rem;
    border-radius: 999px;
    background: #fee2e2;
    color: #b91c1c;
    font-size: .8rem;
    font-weight: 700;
    display: flex;
    align-items: center;
    justify-content: center;
}
[data-theme="dark"] .presensi-rekap-tanpa-scan-page .rts-item__no {
    background: rgba(185,28,28,.35);
    color: #fecaca;
}
.presensi-rekap-tanpa-scan-page .rts-item__nama {
    font-weight: 600;
    font-size: 1rem;
    color: var(--bs-body-color);
}
.presensi-rekap-tanpa-scan-page .rts-item__meta {
    font-size: .82rem;
    color: var(--bs-secondary-color);
    margin-top: .15rem;
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
    <h1 class="h4 mb-1">Kegiatan belum ada scan hadir</h1>
    <p class="text-muted mb-0 small">
        Daftar kegiatan rutin yang sudah masuk jadwal, tetapi <strong>belum satupun santri scan hadir</strong> pada bulan ini.
    </p>
</div>

<div class="rts-info mb-3">
    <strong>Artinya:</strong> kegiatan di bawah perlu dicek — apakah scan belum dilakukan, QR/jadwal salah, atau kegiatan memang kosong.
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
        Periode: <strong><?= htmlspecialchars($periodeLabel) ?></strong>
        (<?= htmlspecialchars($tglAwal) ?> – <?= htmlspecialchars($tglAkhir) ?>)
        <?= $tingkatan !== '' ? ' · Tingkatan: <strong>' . htmlspecialchars($tingkatan) . '</strong>' : '' ?>
    </p>
</form>

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
                        Semua kegiatan terjadwal bulan ini sudah pernah ada scan hadir santri.
                    </p>
                <?php else: ?>
                    <div class="fw-semibold text-danger mb-1">
                        <i class="fa-solid fa-triangle-exclamation me-1"></i> Perlu ditindaklanjuti
                    </div>
                    <p class="small text-muted mb-0">
                        Ada <strong><?= $jumlahTanpaScan ?></strong> kegiatan · total
                        <strong><?= $totalTidakScan ?></strong> jadwal tanpa scan hadir.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($jumlahTanpaScan > 0): ?>
    <div class="card shadow-sm border-0">
        <div class="card-header bg-white fw-semibold py-2 d-flex justify-content-between align-items-center">
            <span>Daftar kegiatan (<?= $jumlahTanpaScan ?>)</span>
            <span class="small text-muted fw-normal">Ketuk baris untuk lihat tanggal &amp; waktu</span>
        </div>
        <div class="card-body pt-2 pb-3">
            <ol class="rts-list">
                <?php foreach ($kegiatanTanpaScan as $idx => $kgRow):
                    $jmlTidak = (int) ($kgRow['jumlah_tidak_scan'] ?? 0);
                    $detailSlots = (array) ($kgRow['detail'] ?? []);
                    $itemId = 'rts-detail-' . (int) ($kgRow['kegiatan_id'] ?? $idx);
                    ?>
                    <li class="rts-item">
                        <button type="button" class="rts-item__head" aria-expanded="false" aria-controls="<?= htmlspecialchars($itemId) ?>" data-rts-toggle>
                            <span class="rts-item__chev"><i class="fa-solid fa-chevron-right"></i></span>
                            <span class="rts-item__no"><?= $idx + 1 ?></span>
                            <div class="flex-grow-1 min-w-0">
                                <div class="rts-item__nama"><?= htmlspecialchars((string) $kgRow['nama_kegiatan']) ?></div>
                                <div class="rts-item__meta">
                                    Tingkatan: <?= htmlspecialchars((string) $kgRow['tingkatan_label']) ?>
                                    · <?= (int) $kgRow['hari_terjadwal'] ?> hari terjadwal
                                </div>
                            </div>
                            <span class="rts-item__count" title="Jumlah jadwal tanpa scan">
                                <?= $jmlTidak ?>
                                <small>tidak scan</small>
                            </span>
                        </button>
                        <div class="rts-item__detail" id="<?= htmlspecialchars($itemId) ?>" hidden>
                            <?php if ($detailSlots === []): ?>
                                <p class="small text-muted mb-0">Detail tanggal/waktu tidak tersedia.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="table table-sm rts-detail-table mb-0">
                                        <thead>
                                        <tr>
                                            <th>Tanggal</th>
                                            <th>Hari</th>
                                            <th>Waktu</th>
                                            <th>Tingkatan</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($detailSlots as $slot): ?>
                                            <tr>
                                                <td><?= htmlspecialchars((string) ($slot['tanggal_tampil'] ?? '')) ?></td>
                                                <td><?= htmlspecialchars((string) ($slot['hari'] ?? '')) ?></td>
                                                <td><?= htmlspecialchars((string) ($slot['jam'] ?? '')) ?></td>
                                                <td><?= htmlspecialchars((string) ($slot['tingkatan'] ?? '')) ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </div>
    <?php endif; ?>
<?php endif; ?>

<script>
(function () {
    document.querySelectorAll('[data-rts-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var expanded = btn.getAttribute('aria-expanded') === 'true';
            var panel = btn.nextElementSibling;
            btn.setAttribute('aria-expanded', expanded ? 'false' : 'true');
            if (panel) {
                panel.hidden = expanded;
            }
        });
    });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
