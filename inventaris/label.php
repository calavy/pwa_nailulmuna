<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_vendor.php';
require_once __DIR__ . '/../helpers/inventaris_barang.php';
require_once __DIR__ . '/../helpers/user_permissions.php';

migrate_inventaris_barang_permission($pdo);
require_roles(['admin', 'pengurus']);

$ids = [];
if (isset($_POST['ids']) && is_array($_POST['ids'])) {
    $ids = $_POST['ids'];
} elseif ((int) ($_GET['id'] ?? 0) > 0) {
    $ids = [(int) $_GET['id']];
}
$rows = inventaris_barang_by_ids($pdo, $ids);
if ($rows === []) {
    set_flash('error', 'Pilih barang yang akan dibuat kode QR-nya.');
    header('Location: ' . app_href('/inventaris/index.php'));
    exit;
}

$kodeQr = [];
foreach ($rows as $row) {
    $kodeQr[] = [
        'kode' => (string) $row['kode'],
        'url' => inventaris_barang_cek_url((string) $row['kode']),
    ];
}

$pageTitle = 'Kode QR Barang';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap gap-2 mb-3">
    <a class="btn btn-outline-secondary btn-sm" href="<?= htmlspecialchars(app_href('/inventaris/index.php')) ?>">Kembali ke daftar</a>
    <?php if (count($kodeQr) > 1): ?>
        <button type="button" class="btn btn-success btn-sm" id="unduh-semua-qr">Unduh semua JPG</button>
    <?php endif; ?>
    <span class="small text-muted align-self-center"><?= count($kodeQr) ?> kode QR.</span>
</div>

<div class="d-flex flex-wrap gap-3">
    <?php foreach ($kodeQr as $i => $item): ?>
        <article class="card shadow-sm">
            <div class="card-body text-center">
                <div class="inv-qr mx-auto" id="inv-qr-<?= (int) $i ?>" data-url="<?= htmlspecialchars($item['url']) ?>" data-kode="<?= htmlspecialchars($item['kode']) ?>"></div>
                <div class="small text-muted mt-2"><?= htmlspecialchars($item['kode']) ?></div>
                <button type="button" class="btn btn-success btn-sm mt-2" data-unduh-qr="<?= (int) $i ?>">Unduh JPG</button>
            </div>
        </article>
    <?php endforeach; ?>
</div>

<style>
.inv-qr { width: 240px; height: 240px; }
.inv-qr img, .inv-qr canvas { width: 240px !important; height: 240px !important; }
</style>
<script src="<?= htmlspecialchars(app_vendor_qrcodejs_js_href()) ?>"></script>
<script>
(function () {
    if (typeof QRCode === 'undefined') return;
    document.querySelectorAll('.inv-qr').forEach(function (el) {
        var url = el.getAttribute('data-url') || '';
        if (!url) return;
        new QRCode(el, {
            text: url,
            width: 480,
            height: 480,
            correctLevel: QRCode.CorrectLevel.M
        });
    });

    function namaBerkas(kode) {
        var aman = String(kode || 'BARANG').replace(/[^A-Za-z0-9_-]+/g, '-');
        return 'QR-' + aman + '.jpg';
    }

    function unduh(el) {
        var sumber = el.querySelector('canvas');
        if (!sumber) return;
        var out = document.createElement('canvas');
        out.width = sumber.width;
        out.height = sumber.height;
        var ctx = out.getContext('2d');
        if (!ctx) return;
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, out.width, out.height);
        ctx.drawImage(sumber, 0, 0);
        var tautan = document.createElement('a');
        tautan.href = out.toDataURL('image/jpeg', 0.92);
        tautan.download = namaBerkas(el.getAttribute('data-kode'));
        tautan.click();
    }

    document.querySelectorAll('[data-unduh-qr]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var kotak = document.getElementById('inv-qr-' + btn.getAttribute('data-unduh-qr'));
            if (kotak) unduh(kotak);
        });
    });

    var semua = document.getElementById('unduh-semua-qr');
    if (semua) {
        semua.addEventListener('click', function () {
            var tombol = Array.prototype.slice.call(document.querySelectorAll('[data-unduh-qr]'));
            var i = 0;
            function berikutnya() {
                if (i >= tombol.length) return;
                tombol[i].click();
                i += 1;
                setTimeout(berikutnya, 400);
            }
            berikutnya();
        });
    }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
