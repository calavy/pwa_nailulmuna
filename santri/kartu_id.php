<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/santri_kartu.php';

require_roles(['admin', 'pengurus']);

$id = (int) ($_GET['id'] ?? 0);
$row = santri_kartu_fetch($pdo, $id);
if ($row === null) {
    set_flash('error', 'Data santri tidak ditemukan.');
    header('Location: ' . app_href('/santri/index.php'));
    exit;
}

$row = santri_kartu_prepare_row($row);
$brand = santri_kartu_brand($pdo);
$kartuVariant = 'utama';
$nisSlug = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) ($row['nis'] ?? 'santri')) ?: 'santri';
$downloadName = 'kartu-santri-' . $nisSlug;

$pageTitle = 'Kartu Santri';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
    <div>
        <h1 class="h4 mb-1">Kartu Santri</h1>
        <p class="text-muted small mb-0">
            Ukuran ID portrait <strong>54 × 86 mm</strong> — <?= htmlspecialchars((string) ($row['nama_santri'] ?? '')) ?>
            · QR: <code><?= htmlspecialchars((string) ($row['kode_qr_final'] ?? '')) ?></code>
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= htmlspecialchars(app_href('/santri/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Kembali</a>
        <a href="<?= htmlspecialchars(app_href('/santri/kartu.php?id=' . $id)) ?>" class="btn btn-outline-secondary btn-sm">
            <i class="fa-solid fa-file-lines me-1"></i> Kartu tes A5
        </a>
        <a href="<?= htmlspecialchars(app_href('/santri/kartu_sementara.php?id=' . $id)) ?>" class="btn btn-warning btn-sm">
            <i class="fa-solid fa-id-card-clip me-1"></i> Kartu sementara
        </a>
        <button class="btn btn-primary btn-sm" type="button" id="btnDownloadKartuJpg">
            <i class="fa-solid fa-image me-1"></i> Download JPG
        </button>
        <button class="btn btn-outline-primary btn-sm" type="button" onclick="window.print()">
            <i class="fa-solid fa-print me-1"></i> Cetak
        </button>
    </div>
</div>

<?php require __DIR__ . '/partials/kartu_id_styles.php'; ?>

<div class="st-kartu-wrap">
    <?php
    $cardDomId = 'st-kartu-santri-card';
    require __DIR__ . '/partials/kartu_id_card.php';
    ?>
</div>

<p class="text-muted small text-center no-print mb-0">
    Nama panjang otomatis mengecil agar muat di kartu. Motto: setting <code>kartu_santri_motto</code>.
    Alamat &amp; telepon dari <a href="<?= htmlspecialchars(app_href('/settings/pesantren.php')) ?>">Profil Pondok</a>.
</p>

<?php require __DIR__ . '/partials/kartu_id_download.js.php'; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
