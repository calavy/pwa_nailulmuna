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
$qrUrlLarge = santri_kartu_qr_image_url((string) $row['kode_qr_final'], 900);
$namaPonpes = app_brand_nama_ponpes($pdo);

$pageTitle = 'Cetak QR Santri';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
    <div>
        <h1 class="h4 mb-1">Cetak QR Santri</h1>
        <p class="text-muted small mb-0">QR besar untuk uji scan presensi — kode: <code><?= htmlspecialchars((string) $row['kode_qr_final']) ?></code></p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?= htmlspecialchars(app_href('/santri/index.php')) ?>" class="btn btn-outline-secondary btn-sm">Kembali</a>
        <a href="<?= htmlspecialchars(app_href('/santri/kartu.php?id=' . $id)) ?>" class="btn btn-outline-success btn-sm">
            <i class="fa-solid fa-id-card me-1"></i> Kartu ID
        </a>
        <button class="btn btn-primary btn-sm" type="button" onclick="window.print()">
            <i class="fa-solid fa-print me-1"></i> Cetak
        </button>
    </div>
</div>

<style>
.st-qr-sheet {
    max-width: 420px;
    margin: 0 auto;
    text-align: center;
    padding: 1.5rem;
    border: 1px solid var(--bs-border-color);
    border-radius: 12px;
    background: #fff;
}
.st-qr-sheet__ponpes { font-size: .85rem; color: var(--bs-secondary-color); margin-bottom: .5rem; }
.st-qr-sheet__nama { font-size: 1.25rem; font-weight: 700; margin-bottom: .25rem; }
.st-qr-sheet__meta { font-size: .9rem; color: var(--bs-secondary-color); margin-bottom: 1rem; }
.st-qr-sheet__img {
    width: min(280px, 80vw);
    height: auto;
    margin: 0 auto 1rem;
    display: block;
    border: 4px solid #f1f5f9;
    border-radius: 8px;
    padding: 8px;
    background: #fff;
}
.st-qr-sheet__code {
    font-family: ui-monospace, monospace;
    font-size: .95rem;
    font-weight: 600;
    word-break: break-all;
}
@media print {
    .no-print { display: none !important; }
    .st-qr-sheet { border: none; box-shadow: none; max-width: none; }
}
</style>

<div class="st-qr-sheet" id="st-qr-print">
    <div class="st-qr-sheet__ponpes"><?= htmlspecialchars($namaPonpes) ?></div>
    <div class="st-qr-sheet__nama"><?= htmlspecialchars((string) ($row['nama_santri'] ?? '')) ?></div>
    <div class="st-qr-sheet__meta">
        NIS <?= htmlspecialchars((string) ($row['nis'] ?? '-')) ?>
        <?php if (trim((string) ($row['tingkatan'] ?? '')) !== ''): ?>
            · <?= htmlspecialchars((string) $row['tingkatan']) ?>
        <?php endif; ?>
    </div>
    <img src="<?= htmlspecialchars($qrUrlLarge) ?>" alt="QR Santri" class="st-qr-sheet__img">
    <div class="st-qr-sheet__code"><?= htmlspecialchars((string) $row['kode_qr_final']) ?></div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
