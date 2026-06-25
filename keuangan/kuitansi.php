<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/keuangan_kuitansi.php';

require_roles(['admin', 'pengurus', 'petugas_absensi']);

$id = (int) ($_GET['id'] ?? 0);
$kuitansi = keuangan_kuitansi_context($pdo, $id);
if ($kuitansi === null) {
    set_flash('error', 'Data pembayaran tidak ditemukan.');
    header('Location: ' . app_href('/pembayaran/riwayat.php'));
    exit;
}

$pageTitle = 'Kuitansi Pembayaran';
$bodyClass = 'keuangan-module kuitansi-page';
$pageStylesheets = [app_asset_href('/assets/css/keuangan.css')];
require_once __DIR__ . '/../includes/header.php';

$noKuitansi = (string) ($kuitansi['no_kuitansi'] ?? '');
$verifyUrl = (string) ($kuitansi['verify_url'] ?? '');
$namaPetugas = trim((string) ($kuitansi['nama_petugas'] ?? ($_SESSION['user']['nama'] ?? 'Petugas')));
if (($kuitansi['nama_petugas'] ?? '') === '') {
    $kuitansi['nama_petugas'] = $namaPetugas;
}
$tanggalBayarFmt = (string) ($kuitansi['tanggal_bayar_fmt'] ?? '');
$stampelKuitansi = (string) ($kuitansi['stampel'] ?? '');
$nominalTotal = (int) ($kuitansi['nominal_total'] ?? 0);
$formatRupiah = static fn(int $n): string => keuangan_format_rupiah($n);
$details = (array) ($kuitansi['details'] ?? []);
$namaPonpes = (string) ($kuitansi['nama_ponpes'] ?? '');
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 noprint">
    <div>
        <h1 class="h4 mb-1">Kuitansi Pembayaran</h1>
        <p class="text-muted small mb-0">Tampilan besar &amp; jelas untuk wali / orang tua santri.</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-primary btn-sm" onclick="printMode('orangtua')"><i class="fa-solid fa-print me-1"></i> Cetak bukti</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="printMode('thermal')"><i class="fa-solid fa-receipt me-1"></i> Print termal</button>
        <button type="button" class="btn btn-outline-success btn-sm" onclick="downloadPng()"><i class="fa-solid fa-download me-1"></i> Simpan gambar</button>
        <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(app_href('/pembayaran/riwayat.php')) ?>">Kembali</a>
    </div>
</div>

<div class="kuitansi-wrap">
    <?php
    $sheetId = 'receipt-orangtua';
    $showQr = true;
    require __DIR__ . '/../includes/partials/kuitansi_orangtua.php';
    ?>

    <div id="receipt-thermal" class="receipt-thermal card shadow-sm mt-4 d-none d-md-block">
        <div class="card-body p-3" style="max-width:320px;margin:0 auto;font-size:12px">
            <div class="text-center fw-bold"><?= htmlspecialchars($namaPonpes) ?></div>
            <div class="text-center mb-2">KUITANSI <?= htmlspecialchars($noKuitansi) ?></div>
            <div>Tgl: <?= htmlspecialchars($tanggalBayarFmt) ?></div>
            <div>NIS: <?= htmlspecialchars((string) ($kuitansi['nis'] ?: '-')) ?></div>
            <div>Nama: <?= htmlspecialchars((string) ($kuitansi['nama_santri'] ?? '')) ?></div>
            <?php if (trim((string) ($kuitansi['bin_label'] ?? '')) !== ''): ?>
                <div><?= htmlspecialchars((string) $kuitansi['bin_label']) ?></div>
            <?php endif; ?>
            <div>Periode: <?= htmlspecialchars((string) ($kuitansi['periode_label'] ?? '')) ?></div>
            <hr>
            <?php foreach ($details as $d): ?>
                <div class="d-flex justify-content-between">
                    <span><?= htmlspecialchars((string) ($d['nama'] ?? '')) ?></span>
                    <span><?= htmlspecialchars((string) ($d['nominal_fmt'] ?? '')) ?></span>
                </div>
            <?php endforeach; ?>
            <hr>
            <div class="d-flex justify-content-between fw-bold">
                <span>TOTAL</span>
                <span><?= htmlspecialchars($formatRupiah($nominalTotal)) ?></span>
            </div>
            <div class="text-center kuitansi-stempel-thermal mt-2 pt-2 border-top">
                <img src="<?= htmlspecialchars($stampelKuitansi) ?>" alt="Stempel" style="width:72px;height:72px;object-fit:contain;display:block;margin:0 auto 4px;">
                <div style="font-size:10px;font-weight:700;">SAH · <?= htmlspecialchars($tanggalBayarFmt) ?></div>
                <div style="font-size:10px;">Petugas: <?= htmlspecialchars($namaPetugas) ?></div>
            </div>
            <div class="text-center mt-2">Terima kasih</div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
    function printMode(mode) {
        document.body.setAttribute('data-print-mode', mode);
        window.print();
        setTimeout(function () { document.body.removeAttribute('data-print-mode'); }, 1000);
    }
    async function downloadPng() {
        var target = document.getElementById('receipt-orangtua');
        if (!target || typeof html2canvas === 'undefined') return;
        var canvas = await html2canvas(target, { scale: 2, backgroundColor: '#ffffff' });
        var link = document.createElement('a');
        link.href = canvas.toDataURL('image/png');
        link.download = 'kuitansi-<?= htmlspecialchars($noKuitansi) ?>.png';
        link.click();
    }
    (function () {
        var el = document.getElementById('qrcode-verify-receipt-orangtua');
        if (!el || typeof QRCode === 'undefined') return;
        new QRCode(el, {
            text: <?= json_encode($verifyUrl, JSON_UNESCAPED_UNICODE) ?>,
            width: 88,
            height: 88
        });
    })();
</script>
<style>
.kuitansi-stempel-thermal img { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
@media print {
    .noprint { display: none !important; }
    body * { visibility: hidden !important; }
    body[data-print-mode="orangtua"] #receipt-orangtua,
    body[data-print-mode="orangtua"] #receipt-orangtua * { visibility: visible !important; }
    body[data-print-mode="thermal"] #receipt-thermal,
    body[data-print-mode="thermal"] #receipt-thermal * { visibility: visible !important; }
    #receipt-orangtua, #receipt-thermal { position: absolute; left: 0; top: 0; width: 100%; margin: 0 !important; }
    body[data-print-mode="thermal"] #receipt-thermal { display: block !important; }
    body[data-print-mode="thermal"] #receipt-thermal .card-body { max-width: 302px !important; }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
