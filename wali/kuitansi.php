<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';
require_once __DIR__ . '/../helpers/keuangan_kuitansi.php';

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', 'Bukti pembayaran tidak ditemukan.');
    header('Location: ' . app_href('/wali/keuangan.php?tab=bayar'));
    exit;
}

$row = wali_portal_fetch_pembayaran_for_wali($pdo, $id, $waliSantriId);
if (!$row) {
    set_flash('error', 'Bukti pembayaran tidak dapat diakses.');
    header('Location: ' . app_href('/wali/keuangan.php?tab=bayar'));
    exit;
}

$details = [];
if (table_exists($pdo, 'keuangan_pembayaran_detail')) {
    $detStmt = $pdo->prepare('SELECT pos_nama, nominal FROM keuangan_pembayaran_detail WHERE pembayaran_id = :id ORDER BY id ASC');
    $detStmt->execute(['id' => $id]);
    $details = $detStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$kuitansi = keuangan_kuitansi_context_build($pdo, $id, $row, $details);
$kuitansi['footer_note'] = 'Bukti ini dari portal wali santri. Simpan sebagai arsip pembayaran. Terima kasih.';
$noKuitansi = (string) ($kuitansi['no_kuitansi'] ?? '');
$verifyUrl = (string) ($kuitansi['verify_url'] ?? '');

require_once __DIR__ . '/includes/layout.php';
wali_layout_head('Bukti pembayaran ' . $noKuitansi, true, 'keuangan');
?>

        <div class="d-flex justify-content-between align-items-center mb-3 noprint">
            <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_href('/wali/keuangan.php?tab=bayar')) ?>"><i class="fa-solid fa-arrow-left me-1"></i> Kembali</a>
            <button type="button" class="btn btn-sm btn-teal" onclick="window.print()"><i class="fa-solid fa-print me-1"></i> Cetak bukti</button>
        </div>

        <?php
        $sheetId = 'wali-kuitansi-sheet';
        $showQr = true;
        require __DIR__ . '/../includes/partials/kuitansi_orangtua.php';
        ?>

<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
    (function () {
        var el = document.getElementById('qrcode-verify-wali-kuitansi-sheet');
        if (!el || typeof QRCode === 'undefined') return;
        new QRCode(el, {
            text: <?= json_encode($verifyUrl, JSON_UNESCAPED_UNICODE) ?>,
            width: 88,
            height: 88
        });
    })();
</script>
<link rel="stylesheet" href="<?= htmlspecialchars(app_asset_href('/assets/css/keuangan.css')) ?>">
<style>
@media print {
    body.wali-portal { background: #fff !important; padding: 0 !important; }
    .wali-bottom-nav, .btn, .wali-nav-scroll, .noprint { display: none !important; }
    .kuitansi-ortu { box-shadow: none !important; border: 1px solid #333 !important; }
    .kuitansi-ortu img { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>

<?php
wali_layout_foot(true, 'keuangan');
