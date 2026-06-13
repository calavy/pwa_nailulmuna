<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/santri_keaktifan_nilai.php';

$santriId = $santriPortalId;
if (!user_can_view_keaktifan_nilai_for_santri($santriId)) {
    set_flash('error', 'Akses ditolak.');
    header('Location: ' . app_href('/santri_portal/index.php'));
    exit;
}

ensure_santri_nilai_keaktifan_table($pdo);

require_once __DIR__ . '/includes/layout.php';
santri_portal_layout_head('Nilai Keaktifan — Portal Santri', 'keaktifan');
?>
<h1 class="h5 fw-bold mb-1">Nilai keaktifan</h1>
<p class="small text-muted mb-3"><?= htmlspecialchars((string) $santriPortalRow['nama_santri']) ?> · NIS <?= htmlspecialchars((string) $santriPortalRow['nis']) ?></p>

<?php require __DIR__ . '/../includes/partials/santri_keaktifan_nilai_view.php'; ?>

<?php
santri_portal_layout_foot('keaktifan');
