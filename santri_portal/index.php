<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';
require_once __DIR__ . '/../helpers/akademik_ikhtibar.php';
require_once __DIR__ . '/../helpers/akademik_pkpps_tugas.php';

ensure_akademik_ikhtibar_tables($pdo);
$santriIdPortal = (int) ($santriPortalRow['id'] ?? 0);
$tingkatanPortal = (string) ($santriPortalRow['tingkatan'] ?? '');
$tugasTersedia = ikhtibar_tugas_tersedia_santri($pdo, $santriIdPortal, $tingkatanPortal, IKHTIBAR_TUGAS_SUMBER);
$tugasBelumSelesai = 0;
foreach ($tugasTersedia as $tugasRow) {
    if ((string) ($tugasRow['sesi_status'] ?? '') !== 'selesai') {
        $tugasBelumSelesai++;
    }
}
$tugasPkppsBelumSelesai = 0;
if (!empty($santriPortalPkppsAktif)) {
    foreach (pkpps_tugas_tersedia_santri($pdo, $santriIdPortal, $tingkatanPortal) as $tugasRow) {
        if ((string) ($tugasRow['sesi_status'] ?? '') !== 'selesai') {
            $tugasPkppsBelumSelesai++;
        }
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
                Ada <strong><?= (int) $tugasBelumSelesai ?></strong> tugas ikhtibar kajian yang belum selesai.
                <a href="<?= htmlspecialchars(app_href('/santri_portal/tugas/index.php')) ?>" class="alert-link">Kerjakan</a>
            </div>
        <?php endif; ?>
        <?php if ($tugasPkppsBelumSelesai > 0): ?>
            <div class="alert alert-warning py-2 small mb-3 shadow-sm">
                <i class="fa-solid fa-book-open me-1"></i>
                Ada <strong><?= (int) $tugasPkppsBelumSelesai ?></strong> tugas PKPPS yang belum selesai.
                <a href="<?= htmlspecialchars(app_href('/santri_portal/pkpps/tugas/index.php')) ?>" class="alert-link">Kerjakan</a>
            </div>
        <?php endif; ?>

        <p class="text-center small text-muted mb-0">Butuh ubah PIN? Minta bantuan pengurus pondok.</p>
<?php
santri_portal_layout_foot('beranda');
