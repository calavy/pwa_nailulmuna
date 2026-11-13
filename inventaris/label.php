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
    $namaLabel = trim((string) ($row['nama_label'] ?? ''));
    if ($namaLabel === '') {
        $namaLabel = (string) $row['nama'];
    }
    $kodeQr[] = [
        'kode' => (string) $row['kode'],
        'nama_label' => $namaLabel,
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
        <article class="card shadow-sm inv-label-kartu">
            <div class="card-body text-center">
                <div class="inv-qr mx-auto" id="inv-qr-<?= (int) $i ?>" data-url="<?= htmlspecialchars($item['url']) ?>" data-kode="<?= htmlspecialchars($item['kode']) ?>" data-nama="<?= htmlspecialchars($item['nama_label']) ?>"></div>
                <div class="fw-semibold mt-2"><?= htmlspecialchars($item['nama_label']) ?></div>
                <div class="small text-muted"><?= htmlspecialchars($item['kode']) ?></div>
                <button type="button" class="btn btn-success btn-sm mt-2" data-unduh-qr="<?= (int) $i ?>">Unduh JPG</button>
            </div>
        </article>
    <?php endforeach; ?>
</div>

<style>
.inv-label-kartu { width: 280px; }
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

    function teksLabel(el) {
        var teks = String(el.getAttribute('data-nama') || '').trim();
        if (!teks) teks = el.getAttribute('data-kode') || 'BARANG';
        return teks.slice(0, 160);
    }

    function namaBerkas(teks) {
        var aman = String(teks || 'BARANG').replace(/[^A-Za-z0-9_-]+/g, '-').replace(/^-+|-+$/g, '');
        if (!aman) aman = 'BARANG';
        return aman + '.jpg';
    }

    function bungkus(ctx, teks, maxWidth) {
        var kata = String(teks).split(/\s+/).filter(Boolean);
        var baris = [];
        var sekarang = '';
        function pecahKata(k) {
            var potong = '';
            for (var i = 0; i < k.length; i++) {
                var berikutnya = potong + k.charAt(i);
                if (ctx.measureText(berikutnya).width > maxWidth && potong) {
                    baris.push(potong);
                    potong = k.charAt(i);
                } else {
                    potong = berikutnya;
                }
            }
            return potong;
        }
        kata.forEach(function (k) {
            var calon = sekarang ? sekarang + ' ' + k : k;
            if (ctx.measureText(calon).width <= maxWidth) {
                sekarang = calon;
                return;
            }
            if (sekarang) baris.push(sekarang);
            sekarang = ctx.measureText(k).width <= maxWidth ? k : pecahKata(k);
        });
        if (sekarang) baris.push(sekarang);
        return baris.length ? baris : [''];
    }

    function unduh(el) {
        var sumber = el.querySelector('canvas');
        if (!sumber) return;
        var nama = teksLabel(el);
        var kode = String(el.getAttribute('data-kode') || '');
        var qr = sumber.width;
        var pad = 32;
        var ukur = document.createElement('canvas').getContext('2d');
        if (!ukur) return;
        ukur.font = '600 32px sans-serif';
        var barisNama = bungkus(ukur, nama, qr);
        ukur.font = '500 22px sans-serif';
        var barisKode = kode === '' ? [] : bungkus(ukur, kode, qr);
        var tinggiNama = 42;
        var tinggiKode = 30;
        var out = document.createElement('canvas');
        out.width = qr + pad * 2;
        out.height = pad + qr + 20 + (barisNama.length * tinggiNama) + 8 + (barisKode.length * tinggiKode) + pad;
        var ctx = out.getContext('2d');
        if (!ctx) return;
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, out.width, out.height);
        ctx.drawImage(sumber, pad, pad);
        ctx.textAlign = 'center';
        ctx.textBaseline = 'top';
        ctx.fillStyle = '#111111';
        ctx.font = '600 32px sans-serif';
        var y = pad + qr + 20;
        barisNama.forEach(function (baris) {
            ctx.fillText(baris, out.width / 2, y);
            y += tinggiNama;
        });
        ctx.fillStyle = '#666666';
        ctx.font = '500 22px sans-serif';
        y += 8;
        barisKode.forEach(function (baris) {
            ctx.fillText(baris, out.width / 2, y);
            y += tinggiKode;
        });
        var tautan = document.createElement('a');
        tautan.href = out.toDataURL('image/jpeg', 0.92);
        tautan.download = namaBerkas(nama);
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
