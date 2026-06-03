<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';

require_login();
require_roles(['admin', 'pengurus']);

$pageTitle = 'Panduan Alur Keuangan';
$bodyClass = keuangan_body_class('keuangan-panduan-page');
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">
        <a href="<?= htmlspecialchars(app_href('/keuangan/index.php')) ?>">Keuangan</a>
    </p>
    <h1 class="h4 mb-1">Panduan alur keuangan</h1>
    <p class="text-muted small mb-0">
        Dari pengaturan tarif, perhitungan tagihan, pembayaran, alokasi dana, akuntansi, cashless, hingga laporan.
    </p>
</div>

<?php require __DIR__ . '/partials/alur_keuangan_panduan.php'; ?>

<style>
.keu-panduan-accordion .accordion-button { font-size: 0.92rem; font-weight: 600; }
.keu-panduan-accordion .accordion-body { line-height: 1.55; }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
