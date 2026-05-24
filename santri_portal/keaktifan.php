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

$namaPonpes = trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren'));
require_once __DIR__ . '/../includes/auth_portal_layout.php';

auth_portal_layout_begin([
    'title' => 'Nilai Keaktifan — Portal Santri',
    'welcome' => 'Nilai keaktifan saya',
    'subtitle' => htmlspecialchars((string) $santriPortalRow['nama_santri']) . ' · NIS ' . htmlspecialchars((string) $santriPortalRow['nis']),
    'nama_ponpes' => $namaPonpes,
    'max_width' => '640px',
    'accent' => 'teal',
]);
?>
<p class="mb-3"><a href="/santri_portal/index.php" class="small">&larr; Beranda</a></p>

<?php require __DIR__ . '/../includes/partials/santri_keaktifan_nilai_view.php'; ?>

<p class="text-center mt-3 mb-0"><a href="/santri_portal/logout.php" class="btn btn-sm btn-outline-secondary">Keluar</a></p>
<?php
auth_portal_layout_end([], true);
