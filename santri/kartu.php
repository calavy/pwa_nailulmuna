<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pondok_cetak.php';
require_once __DIR__ . '/../helpers/santri_kartu.php';

require_roles(['admin', 'pengurus']);

$id = (int) ($_GET['id'] ?? 0);
$row = santri_kartu_fetch($pdo, $id);
if ($row === null) {
    set_flash('error', 'Data santri tidak ditemukan.');
    header('Location: ' . app_href('/santri/index.php'));
    exit;
}

$kop = pondok_kop_data($pdo);
$headerColor = '#065f46';
$logoHref = (string) ($kop['logo_href'] ?? '');
$kotaPonpes = (string) ($kop['kota_ponpes'] ?? 'Muntilan');

$pageTitle = 'Cetak Kartu Tes Santri';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
    <div>
        <h1 class="h4 mb-1">Kartu Tes Santri</h1>
        <p class="text-muted small mb-0">Ukuran kertas A5 — nama &amp; NIS terisi; tingkatan &amp; hasil tes diisi manual.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= htmlspecialchars(app_href('/santri/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Kembali</a>
        <a href="<?= htmlspecialchars(app_href('/santri/kartu_id.php?id=' . $id)) ?>" class="btn btn-outline-primary btn-sm">
            <i class="fa-solid fa-id-card me-1"></i> Kartu ID
        </a>
        <button class="btn btn-primary btn-sm" type="button" onclick="window.print()">
            <i class="fa-solid fa-print me-1"></i> Cetak
        </button>
    </div>
</div>

<?php
require __DIR__ . '/partials/kartu_tes_styles.php';
?>

<div class="kartu-tes-wrap">
    <?php require __DIR__ . '/partials/kartu_tes_sheet.php'; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
