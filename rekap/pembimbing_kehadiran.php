<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/rekap_periode.php';
require_once __DIR__ . '/../helpers/rekap_pembimbing_kehadiran.php';
require_once __DIR__ . '/../helpers/entity_list_sort.php';
require_once __DIR__ . '/../helpers/pkpps.php';

require_roles(['admin', 'pengurus', 'petugas_absensi']);

if (!table_exists($pdo, 'presensi_pembimbing')) {
    set_flash('error', 'Tabel presensi_pembimbing belum ada.');
    header('Location: ' . app_href('/dashboard.php'));
    exit;
}

payroll_pembimbing_ensure_schema($pdo);
pkpps_ensure_schema($pdo);

$periode = rekap_resolve_periode($pdo, $_GET);
$startDate = (string) $periode['start_date'];
$endDate = (string) $periode['end_date'];
$periodeLabel = (string) $periode['label'];

$pembimbingId = (int) ($_GET['pembimbing_id'] ?? 0);
$kegiatanId = (int) ($_GET['kegiatan_id'] ?? 0);
$hanya = trim((string) ($_GET['hanya'] ?? ''));
$hanyaTanpaScan = $hanya === 'tanpa_scan';

$pembimbingList = table_exists($pdo, 'pembimbing')
    ? ($pdo->query('SELECT id, nama_pembimbing, COALESCE(nip, "") AS nip FROM pembimbing ORDER BY ' . pembimbing_list_order_sql('pembimbing'))->fetchAll(PDO::FETCH_ASSOC) ?: [])
    : [];
$kegiatanList = table_exists($pdo, 'kegiatan')
    ? ($pdo->query('SELECT id, nama_kegiatan FROM kegiatan WHERE is_active = 1 ORDER BY nama_kegiatan ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [])
    : [];

$allRows = rekap_pembimbing_kehadiran_rows($pdo, $startDate, $endDate, $pembimbingId, $kegiatanId);
$rows = $hanyaTanpaScan ? rekap_pembimbing_kehadiran_filter_tanpa_scan($allRows) : $allRows;
$summary = rekap_pembimbing_kehadiran_summary($allRows);

$statusBadge = static function (string $status, string $label): string {
    if ($status === 'H') {
        return '<span class="badge text-bg-success">' . htmlspecialchars($label) . '</span>';
    }
    if ($status === 'I') {
        return '<span class="badge text-bg-warning text-dark">' . htmlspecialchars($label) . '</span>';
    }

    return '<span class="badge text-bg-secondary">' . htmlspecialchars($label) . '</span>';
};

$pageTitle = 'Rekap Kehadiran Pembimbing';
$bodyClass = 'rekap-pembimbing-kehadiran-page';
require_once __DIR__ . '/../includes/header.php';
?>

<style>
@media print {
    .rekap-pembimbing-kehadiran-page .print-hide,
    .rekap-pembimbing-kehadiran-page nav,
    .rekap-pembimbing-kehadiran-page .navbar,
    .rekap-pembimbing-kehadiran-page footer {
        display: none !important;
    }
    .rekap-pembimbing-kehadiran-page .card {
        border: none !important;
        box-shadow: none !important;
    }
}
.rekap-pembimbing-kehadiran-page .rpk-stat {
    border-radius: 12px;
    border: 1px solid var(--bs-border-color);
    padding: .85rem 1rem;
    background: var(--bs-body-bg);
}
</style>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/rekap/presensi.php')) ?>">Rekap Presensi</a></p>
    <h1 class="h4 mb-1">Rekap Kehadiran Pembimbing per Kegiatan</h1>
    <p class="text-muted mb-0 small">
        Daftar slot jadwal bulanan per pembimbing. Status <strong>Hadir</strong> dari scan, <strong>Izin</strong> dari perizinan pembimbing,
        tanda <strong>—</strong> untuk slot tanpa scan (perlu ditindaklanjuti).
    </p>
</div>

<?php
$wrapCard = false;
$rekapPeriodeExtraSlot = '
        <div class="col-md-3">
            <label class="form-label small mb-0">Pembimbing</label>
            <select name="pembimbing_id" class="form-select form-select-sm">
                <option value="0">Semua pembimbing</option>';
foreach ($pembimbingList as $pb) {
    $pid = (int) ($pb['id'] ?? 0);
    $rekapPeriodeExtraSlot .= '<option value="' . $pid . '"' . ($pembimbingId === $pid ? ' selected' : '') . '>'
        . htmlspecialchars((string) ($pb['nama_pembimbing'] ?? '-'))
        . '</option>';
}
$rekapPeriodeExtraSlot .= '
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-0">Kegiatan</label>
            <select name="kegiatan_id" class="form-select form-select-sm">
                <option value="0">Semua kegiatan</option>';
foreach ($kegiatanList as $kg) {
    $kid = (int) ($kg['id'] ?? 0);
    $rekapPeriodeExtraSlot .= '<option value="' . $kid . '"' . ($kegiatanId === $kid ? ' selected' : '') . '>'
        . htmlspecialchars((string) ($kg['nama_kegiatan'] ?? '-'))
        . '</option>';
}
$rekapPeriodeExtraSlot .= '
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-0">Tampilkan</label>
            <select name="hanya" class="form-select form-select-sm">
                <option value=""' . (!$hanyaTanpaScan ? ' selected' : '') . '>Semua slot jadwal</option>
                <option value="tanpa_scan"' . ($hanyaTanpaScan ? ' selected' : '') . '>Hanya tanpa scan</option>
            </select>
        </div>';
require __DIR__ . '/../includes/partials/rekap_kalender_bulan_filter.php';
unset($rekapPeriodeExtraSlot);
?>

<div class="row g-2 mb-3 print-hide">
    <div class="col-6 col-md-3">
        <div class="rpk-stat">
            <div class="small text-muted">Hadir</div>
            <div class="fs-4 fw-bold text-success"><?= (int) $summary['hadir'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="rpk-stat">
            <div class="small text-muted">Izin</div>
            <div class="fs-4 fw-bold text-warning"><?= (int) $summary['izin'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="rpk-stat">
            <div class="small text-muted">Tanpa scan</div>
            <div class="fs-4 fw-bold text-danger"><?= (int) $summary['tanpa_scan'] ?></div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="rpk-stat">
            <div class="small text-muted">Total slot</div>
            <div class="fs-4 fw-bold"><?= (int) $summary['total'] ?></div>
        </div>
    </div>
</div>

<div class="d-flex justify-content-end mb-2 print-hide">
    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
        <i class="fa-solid fa-print me-1"></i> Cetak
    </button>
</div>

<div class="card shadow-sm">
    <div class="card-header fw-semibold small d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>Periode: <?= htmlspecialchars($periodeLabel) ?></span>
        <span class="text-muted"><?= count($rows) ?> baris<?= $hanyaTanpaScan ? ' (tanpa scan)' : '' ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-striped table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Pembimbing</th>
                        <th>Kegiatan</th>
                        <th>Tanggal</th>
                        <th class="text-center">Hari</th>
                        <th class="text-center">Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($rows === []): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            <?= $hanyaTanpaScan ? 'Tidak ada slot tanpa scan pada filter ini.' : 'Tidak ada jadwal pembimbing pada periode ini.' ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr<?= (string) ($row['status'] ?? '') === '' ? ' class="table-warning"' : '' ?>>
                            <td>
                                <div class="fw-semibold small"><?= htmlspecialchars((string) ($row['nama_pembimbing'] ?? '-')) ?></div>
                                <?php if ((string) ($row['nip'] ?? '') !== ''): ?>
                                    <div class="text-muted" style="font-size:.72rem;"><?= htmlspecialchars((string) $row['nip']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="small"><?= htmlspecialchars((string) ($row['nama_kegiatan'] ?? '-')) ?></td>
                            <td class="small"><?= htmlspecialchars((string) ($row['tanggal_tampil'] ?? '')) ?></td>
                            <td class="text-center small"><?= htmlspecialchars((string) ($row['hari_label'] ?? '')) ?></td>
                            <td class="text-center"><?= $statusBadge((string) ($row['status'] ?? ''), (string) ($row['status_label'] ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
