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
    <h1 class="h4 mb-1">Panduan &amp; penjelasan keuangan</h1>
    <p class="text-muted small mb-0">
        Rumus tagihan/kas, tampilan laporan, alur operasional (tarif → tagihan → bayar → jurnal → rekap).
    </p>
</div>

<div class="alert alert-info border-info small mb-3">
    <strong>Dokumentasi (markdown):</strong>
    <a href="<?= htmlspecialchars(app_href('/docs/PENJELASAN_KEUANGAN.md')) ?>" target="_blank" rel="noopener"><strong>Penjelasan keuangan (rumus &amp; tampilan)</strong></a>
    ·
    <a href="<?= htmlspecialchars(app_href('/docs/ALUR_KEUANGAN.md')) ?>" target="_blank" rel="noopener">Alur keuangan lengkap</a>
    (pipeline, deploy).
    <a href="<?= htmlspecialchars(app_href('/docs/PANDUAN-KEUANGAN.md')) ?>" target="_blank" rel="noopener">Panduan operator harian</a>
    (FAQ).
    Accordion di bawah = versi in-app.
</div>

<?php require __DIR__ . '/partials/penjelasan_rumus_tampilan.php'; ?>

<h2 class="h6 mt-4 mb-2 text-muted">Alur operasional modul</h2>

<?php require __DIR__ . '/partials/alur_keuangan_panduan.php'; ?>

<style>
.keu-panduan-accordion .accordion-button { font-size: 0.92rem; font-weight: 600; }
.keu-panduan-accordion .accordion-body { line-height: 1.55; }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
