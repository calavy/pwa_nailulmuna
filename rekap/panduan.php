<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';

require_roles(['admin', 'pengurus']);

$pageTitle = 'Panduan Alur Presensi → Rekap';
$bodyClass = 'rekap-panduan-page';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="<?= htmlspecialchars(app_href('/rekap/index.php')) ?>">Rekap</a>
    </p>
    <h1 class="h4 mb-1">Alur presensi → rekap</h1>
    <p class="text-muted small mb-0">
        Dari scan presensi, sinkronisasi status otomatis (IZIN/SAKIT/ALPA), hingga agregasi di halaman rekap.
    </p>
</div>

<?php require __DIR__ . '/partials/alur_presensi_rekap_panduan.php'; ?>

<style>
.rekap-panduan-accordion .accordion-button { font-size: 0.92rem; font-weight: 600; }
.rekap-panduan-accordion .accordion-body { line-height: 1.55; }
.rekap-panduan-flow {
    display: flex;
    flex-wrap: wrap;
    gap: 0.35rem 0.5rem;
    align-items: center;
    font-size: 0.85rem;
}
.rekap-panduan-flow__arrow { color: #94a3b8; }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
