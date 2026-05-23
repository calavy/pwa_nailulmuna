<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';
require_once __DIR__ . '/../helpers/akademik_ikhtibar.php';

ensure_akademik_ikhtibar_tables($pdo);
$santriIdPortal = (int) ($santriPortalRow['id'] ?? 0);
$tingkatanPortal = (string) ($santriPortalRow['tingkatan'] ?? '');
$tugasTersedia = ikhtibar_tugas_tersedia_santri($pdo, $santriIdPortal, $tingkatanPortal);
$tugasBelumSelesai = 0;
foreach ($tugasTersedia as $tugasRow) {
    if ((string) ($tugasRow['sesi_status'] ?? '') !== 'selesai') {
        $tugasBelumSelesai++;
    }
}

$namaPonpes = trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren'));
require_once __DIR__ . '/../includes/auth_portal_layout.php';

auth_portal_layout_begin([
    'title' => 'Beranda — Portal Santri',
    'welcome' => (string) ($santriPortalRow['nama_santri'] ?? 'Santri'),
    'subtitle' => 'NIS ' . (string) ($santriPortalRow['nis'] ?? ''),
    'nama_ponpes' => $namaPonpes,
    'max_width' => '480px',
    'accent' => 'teal',
]);
$ok = get_flash('success');
?>
<?php if ($ok): ?>
    <div class="alert alert-success py-2 small"><?= htmlspecialchars($ok) ?></div>
<?php endif; ?>

<?php if ($tugasBelumSelesai > 0): ?>
    <div class="alert alert-info py-2 small mb-2">
        Ada <strong><?= (int) $tugasBelumSelesai ?></strong> tugas dari pembimbing yang belum selesai dikerjakan.
    </div>
<?php endif; ?>

<div class="d-grid gap-2">
    <a href="<?= htmlspecialchars(app_href('/santri_portal/tugas/index.php')) ?>" class="btn btn-auth-primary position-relative">
        <i class="fa-solid fa-list-check me-1"></i> Tugas Ikhtibar (ujian)
        <?php if ($tugasBelumSelesai > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= (int) $tugasBelumSelesai ?></span>
        <?php endif; ?>
    </a>
    <a href="/santri_portal/riwayat.php" class="btn btn-outline-secondary">
        <i class="fa-solid fa-clock-rotate-left me-1"></i> Riwayat domisili, khidmah &amp; pelanggaran
    </a>
    <a href="/santri_portal/logout.php" class="btn btn-outline-secondary">Keluar</a>
</div>

<p class="small text-muted text-center mt-3 mb-0">Nilai keaktifan presensi hanya untuk pengasuh pondok.</p>
<?php
auth_portal_layout_end([], true);
