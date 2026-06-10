<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pondok_cetak.php';
require_once __DIR__ . '/../helpers/santri_kartu.php';

require_roles(['admin', 'pengurus']);

$idsRaw = $_GET['ids'] ?? [];
if (!is_array($idsRaw)) {
    $idsRaw = explode(',', (string) $idsRaw);
}
$ids = [];
foreach ($idsRaw as $raw) {
    $id = (int) $raw;
    if ($id > 0) {
        $ids[] = $id;
    }
}
$ids = array_values(array_unique($ids));
if ($ids === []) {
    set_flash('error', 'Pilih minimal satu santri untuk cetak batch kartu tes.');
    header('Location: ' . app_href('/santri/index.php'));
    exit;
}

$cards = santri_kartu_fetch_many($pdo, $ids);
if ($cards === []) {
    set_flash('error', 'Tidak ada data santri yang valid untuk dicetak.');
    header('Location: ' . app_href('/santri/index.php'));
    exit;
}

$kop = pondok_kop_data($pdo);
$headerColor = '#065f46';
$logoHref = (string) ($kop['logo_href'] ?? '');
$kotaPonpes = (string) ($kop['kota_ponpes'] ?? 'Muntilan');

$pageTitle = 'Batch Cetak Kartu Tes Santri';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
    <div>
        <h1 class="h4 mb-1">Batch Cetak Kartu Tes</h1>
        <p class="text-muted small mb-0"><?= count($cards) ?> lembar A5 (satu kartu per halaman).</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= htmlspecialchars(app_href('/santri/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Kembali</a>
        <button class="btn btn-primary btn-sm" type="button" onclick="window.print()">
            <i class="fa-solid fa-print me-1"></i> Cetak Semua
        </button>
    </div>
</div>

<?php
require __DIR__ . '/partials/kartu_tes_styles.php';
?>

<div class="kartu-tes-wrap">
    <?php foreach ($cards as $row):
        require __DIR__ . '/partials/kartu_tes_sheet.php';
    endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
