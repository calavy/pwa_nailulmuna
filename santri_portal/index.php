<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';

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

<div class="d-grid gap-2">
    <a href="<?= htmlspecialchars(app_href('/santri_portal/tugas/index.php')) ?>" class="btn btn-auth-primary">
        <i class="fa-solid fa-list-check me-1"></i> Tugas Ikhtibar (ujian)
    </a>
    <a href="/santri_portal/riwayat.php" class="btn btn-outline-secondary">
        <i class="fa-solid fa-clock-rotate-left me-1"></i> Riwayat domisili, khidmah &amp; pelanggaran
    </a>
    <a href="/santri_portal/logout.php" class="btn btn-outline-secondary">Keluar</a>
</div>

<p class="small text-muted text-center mt-3 mb-0">Nilai keaktifan presensi hanya untuk pengasuh pondok.</p>
<?php
auth_portal_layout_end([], true);
