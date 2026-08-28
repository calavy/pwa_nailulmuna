<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';
require_once __DIR__ . '/../helpers/wali_portal.php';
require_once __DIR__ . '/../helpers/rekap_keaktifan.php';
require_once __DIR__ . '/../helpers/santri_keaktifan_nilai.php';
require_once __DIR__ . '/../helpers/hijri_kalender.php';

$santriId = $santriPortalId;
if ((int) ($_SESSION['santri_portal']['santri_id'] ?? 0) !== $santriId) {
    set_flash('error', 'Akses ditolak.');
    header('Location: ' . app_href('/santri_portal/index.php'));
    exit;
}

ensure_santri_nilai_keaktifan_table($pdo);

$bulanFilter = wali_portal_keaktifan_bulan_parse($pdo, $_GET);
$hijriBulanList = hijri_nama_bulan_list();
$tingkatanTampil = trim((string) ($santriPortalRow['tingkatan'] ?? ''));
$rekapBulan = wali_portal_keaktifan_per_kegiatan(
    $pdo,
    $santriId,
    (string) $bulanFilter['start'],
    (string) $bulanFilter['end'],
    $tingkatanTampil
);
$totalsBulan = $rekapBulan['totals'];
$kegiatanBulan = $rekapBulan['kegiatan'];
require_once __DIR__ . '/../helpers/penilaian_kehadiran.php';
$hitBulan = penilaian_kehadiran_hitung(
    (int) ($totalsBulan['alpa'] ?? 0),
    (int) ($totalsBulan['izin'] ?? 0),
    (int) ($totalsBulan['telat'] ?? 0),
    (int) ($totalsBulan['sakit'] ?? 0),
    (int) ($totalsBulan['total'] ?? 0),
    (int) ($totalsBulan['hadir'] ?? 0)
);
$persenBulan = (int) ($totalsBulan['total'] ?? 0) > 0 ? $hitBulan['persen'] : 0;

require_once __DIR__ . '/includes/layout.php';
santri_portal_layout_head('Nilai Keaktifan — Portal Santri', 'keaktifan');
?>
<h1 class="h5 fw-bold mb-1">Nilai keaktifan</h1>
<p class="small text-muted mb-3"><?= htmlspecialchars((string) $santriPortalRow['nama_santri']) ?> · NIS <?= htmlspecialchars((string) $santriPortalRow['nis']) ?></p>

<?php if (table_exists($pdo, 'presensi')): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header py-2">
        <strong><i class="fa-solid fa-calendar-check me-1 text-primary"></i> Rekap bulan Hijriyah</strong>
    </div>
    <div class="card-body py-3">
        <form method="get" class="row g-2 align-items-end mb-3">
            <div class="col-5 col-sm-4">
                <label class="form-label small mb-0">Bulan</label>
                <select name="bulan_h" class="form-select form-select-sm" required>
                    <?php foreach ($hijriBulanList as $idx => $nama): ?>
                        <option value="<?= (int) $idx ?>"<?= (int) $bulanFilter['month'] === (int) $idx ? ' selected' : '' ?>><?= htmlspecialchars($nama) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-4 col-sm-3">
                <label class="form-label small mb-0">Tahun H</label>
                <input type="number" name="tahun_h" class="form-control form-control-sm" min="1300" max="1700" value="<?= (int) $bulanFilter['year'] ?>" required>
            </div>
            <div class="col-3 col-sm-2">
                <button type="submit" class="btn btn-primary btn-sm w-100">Tampil</button>
            </div>
        </form>
        <p class="small text-muted mb-2">
            <?= htmlspecialchars((string) $bulanFilter['label']) ?>
            <?= $tingkatanTampil !== '' ? ' · Tingkatan ' . htmlspecialchars($tingkatanTampil) : '' ?>
            — <?= htmlspecialchars(rekap_keaktifan_rekap_footnote($pdo)) ?>.
        </p>
        <div class="row g-2 text-center small mb-3">
            <div class="col"><div class="text-muted">Hadir</div><div class="fw-bold text-success"><?= (int) ($totalsBulan['hadir'] ?? 0) ?></div></div>
            <div class="col"><div class="text-muted">Telat</div><div class="fw-bold"><?= (int) ($totalsBulan['telat'] ?? 0) ?></div></div>
            <div class="col"><div class="text-muted">ALPA</div><div class="fw-bold text-danger"><?= (int) ($totalsBulan['alpa'] ?? 0) ?></div></div>
            <div class="col"><div class="text-muted">% Hadir</div><div class="fw-bold"><?= number_format($persenBulan, 1, ',', '.') ?>%</div></div>
        </div>
        <?php if ($kegiatanBulan === []): ?>
            <p class="small text-muted mb-0 text-center py-2">Belum ada data presensi jadwal pada periode ini.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Kegiatan</th><th class="text-center">Hadir</th><th class="text-center">Telat</th><th class="text-center">ALPA</th></tr></thead>
                    <tbody>
                    <?php foreach ($kegiatanBulan as $kg): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) ($kg['nama_kegiatan'] ?? '')) ?></td>
                            <td class="text-center"><?= (int) ($kg['hadir'] ?? 0) ?></td>
                            <td class="text-center"><?= (int) ($kg['telat'] ?? 0) ?></td>
                            <td class="text-center text-danger"><?= (int) ($kg['alpa'] ?? 0) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/partials/santri_keaktifan_nilai_view.php'; ?>

<?php
santri_portal_layout_foot('keaktifan');
