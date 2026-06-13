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

require_once __DIR__ . '/includes/layout.php';
santri_portal_layout_head('Beranda — Portal Santri', 'beranda');

$portalProfileRow = $santriPortalRow;
$portalProfileContext = 'santri';
$portalProfileShowLogout = true;
require __DIR__ . '/../includes/partials/portal_profile_hero.php';
?>
        <?php if ($tugasBelumSelesai > 0): ?>
            <div class="alert alert-info py-2 small mb-3 shadow-sm">
                <i class="fa-solid fa-circle-info me-1"></i>
                Ada <strong><?= (int) $tugasBelumSelesai ?></strong> tugas pembimbing yang belum selesai.
            </div>
        <?php endif; ?>

        <p class="text-center small text-muted mb-0">Butuh ubah PIN? Minta bantuan pengurus pondok.</p>
<?php
santri_portal_layout_foot('beranda');
