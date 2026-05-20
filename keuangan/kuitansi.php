<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';

require_roles(['admin', 'pengurus', 'petugas_absensi']);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    set_flash('error', 'Kuitansi tidak ditemukan.');
    header('Location: /pwa_nailulmuna/pembayaran/riwayat.php');
    exit;
}

$santriNameExpr = column_exists($pdo, 'santri', 'nama_santri') ? 's.nama_santri' : 's.nama';
$santriLevelExpr = column_exists($pdo, 'santri', 'tingkatan') ? 's.tingkatan' : "''";
$joinKelas = '';
if (!column_exists($pdo, 'santri', 'tingkatan') && column_exists($pdo, 'santri', 'kelas_id') && table_exists($pdo, 'kelas')) {
    $joinKelas = ' LEFT JOIN kelas k ON k.id = s.kelas_id ';
    $santriLevelExpr = 'k.nama_kelas';
}

$stmt = $pdo->prepare("
    SELECT p.*, s.nis, {$santriNameExpr} AS nama_santri, {$santriLevelExpr} AS tingkatan, u.nama AS nama_petugas
    FROM keuangan_pembayaran p
    INNER JOIN santri s ON s.id = p.santri_id
    LEFT JOIN users u ON u.id = p.created_by
    {$joinKelas}
    WHERE p.id = :id
    LIMIT 1
");
$stmt->execute(['id' => $id]);
$row = $stmt->fetch();
if (!$row) {
    set_flash('error', 'Data pembayaran tidak ditemukan.');
    header('Location: /pwa_nailulmuna/pembayaran/riwayat.php');
    exit;
}

$detStmt = $pdo->prepare("SELECT pos_nama, nominal FROM keuangan_pembayaran_detail WHERE pembayaran_id = :id ORDER BY id ASC");
$detStmt->execute(['id' => $id]);
$details = $detStmt->fetchAll();

$bulanMap = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
];
$bulanTagihan = (int) ($row['bulan_tagihan'] ?? 0);
$periodeLabel = $bulanTagihan > 0 ? ($bulanMap[$bulanTagihan] ?? ('Bulan ' . $bulanTagihan)) : 'Awal Tahun';
$nominalTotal = (int) ((float) ($row['total_nominal'] ?? 0));
$formatRupiah = static fn(int $nominal): string => 'Rp ' . number_format($nominal, 0, ',', '.');

$namaPonpes = app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren');
$alamatPonpes = app_setting($pdo, 'alamat_ponpes', '-');
$jenisPendidikan = app_setting($pdo, 'jenis_pendidikan', '');
$logoPath = app_setting($pdo, 'logo_path', '');
$logoUrl = app_setting($pdo, 'logo_url', '');
$logo = $logoPath !== '' ? '/pwa_nailulmuna/' . $logoPath : $logoUrl;
$noKuitansi = 'KW-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
$verifySecret = (string) app_setting($pdo, 'kuitansi_verify_secret', 'pwa_nailulmuna_secret');
$verifySig = substr(hash('sha256', $id . '|' . (string) $row['tanggal_bayar'] . '|' . (string) $row['total_nominal'] . '|' . $verifySecret), 0, 16);
$verifyUrl = 'http://localhost/pwa_nailulmuna/keuangan/verifikasi_kuitansi.php?id=' . $id . '&sig=' . $verifySig;
$namaPetugas = trim((string) ($row['nama_petugas'] ?? ($_SESSION['user']['nama'] ?? 'Petugas')));

$pageTitle = 'Kuitansi Pembayaran';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">Kuitansi Pembayaran</h1>
    <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-secondary" onclick="printMode('official')">Print Resmi</button>
        <button class="btn btn-sm btn-outline-dark" onclick="printMode('thermal')">Print Termal</button>
        <button class="btn btn-sm btn-success" onclick="downloadPng()">Download PNG</button>
        <a class="btn btn-sm btn-outline-primary" href="/pwa_nailulmuna/pembayaran/riwayat.php">Kembali</a>
    </div>
</div>

<div class="kuitansi-wrap">
    <div id="receipt-official" class="receipt-paper card shadow-sm">
        <div class="card-body p-4">
            <div class="d-flex justify-content-between align-items-start border-bottom pb-3 mb-3">
                <div class="d-flex gap-3 align-items-start">
                    <?php if ($logo !== ''): ?>
                        <img src="<?= htmlspecialchars($logo) ?>" alt="Logo" style="width:68px;height:68px;object-fit:contain;">
                    <?php endif; ?>
                    <div>
                        <div class="fw-bold fs-5"><?= htmlspecialchars($namaPonpes) ?></div>
                        <?php if ($jenisPendidikan !== ''): ?><div class="small"><?= htmlspecialchars($jenisPendidikan) ?></div><?php endif; ?>
                        <div class="small text-muted"><?= htmlspecialchars($alamatPonpes) ?></div>
                    </div>
                </div>
                <div class="text-end">
                    <div class="small text-muted">No. Kuitansi</div>
                    <div class="fw-bold"><?= htmlspecialchars($noKuitansi) ?></div>
                    <div class="small text-muted mt-2">Tanggal Bayar</div>
                    <div><?= htmlspecialchars((string) $row['tanggal_bayar']) ?></div>
                </div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-md-6"><strong>NIS:</strong> <?= htmlspecialchars((string) ($row['nis'] ?: '-')) ?></div>
                <div class="col-md-6"><strong>Nama:</strong> <?= htmlspecialchars((string) $row['nama_santri']) ?></div>
                <div class="col-md-6"><strong>Tingkatan:</strong> <?= htmlspecialchars((string) (($row['tingkatan'] ?? '') !== '' ? $row['tingkatan'] : '-')) ?></div>
                <div class="col-md-6"><strong>Periode:</strong> <?= htmlspecialchars((string) $row['jenis_periode']) ?> - <?= htmlspecialchars($periodeLabel) ?></div>
            </div>
            <div class="table-responsive mb-3">
                <table class="table table-sm table-bordered mb-0">
                    <thead class="table-light"><tr><th>Komponen</th><th class="text-end">Nominal</th></tr></thead>
                    <tbody>
                    <?php foreach ($details as $d): ?>
                        <tr>
                            <td><?= htmlspecialchars((string) $d['pos_nama']) ?></td>
                            <td class="text-end"><?= htmlspecialchars($formatRupiah((int) ((float) $d['nominal']))) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="table-light fw-bold">
                        <td>Total</td>
                        <td class="text-end"><?= htmlspecialchars($formatRupiah($nominalTotal)) ?></td>
                    </tr>
                    </tbody>
                </table>
            </div>
            <div class="small text-muted">Catatan: <?= htmlspecialchars((string) ($row['keterangan'] !== '' ? $row['keterangan'] : '-')) ?></div>
            <div class="mt-4 d-flex justify-content-between align-items-end gap-3">
                <div>
                    <div class="small text-muted mb-1">Verifikasi online:</div>
                    <div id="qrcode-verify" style="width:96px;height:96px;"></div>
                    <div class="small text-muted mt-1">Scan QR untuk validasi kuitansi</div>
                </div>
                <div class="text-center" style="width:240px">
                    <div class="small text-muted mb-1">Stempel Digital Keuangan</div>
                    <div style="height:58px; border:2px solid #dc3545; border-radius:10px; color:#dc3545; display:flex; align-items:center; justify-content:center; font-weight:700;">
                        SAH • <?= htmlspecialchars(date('d-m-Y', strtotime((string) $row['tanggal_bayar']))) ?>
                    </div>
                    <div class="mt-2">Petugas: <strong><?= htmlspecialchars($namaPetugas) ?></strong></div>
                </div>
            </div>
        </div>
    </div>

    <div id="receipt-thermal" class="receipt-thermal card shadow-sm mt-4">
        <div class="card-body p-3" style="max-width:320px;margin:0 auto;font-size:12px">
            <div class="text-center fw-bold"><?= htmlspecialchars($namaPonpes) ?></div>
            <div class="text-center mb-2">KUITANSI <?= htmlspecialchars($noKuitansi) ?></div>
            <div>Tgl: <?= htmlspecialchars((string) $row['tanggal_bayar']) ?></div>
            <div>NIS: <?= htmlspecialchars((string) ($row['nis'] ?: '-')) ?></div>
            <div>Nama: <?= htmlspecialchars((string) $row['nama_santri']) ?></div>
            <div>Periode: <?= htmlspecialchars((string) $row['jenis_periode']) ?> / <?= htmlspecialchars($periodeLabel) ?></div>
            <hr>
            <?php foreach ($details as $d): ?>
                <div class="d-flex justify-content-between">
                    <span><?= htmlspecialchars((string) $d['pos_nama']) ?></span>
                    <span><?= htmlspecialchars($formatRupiah((int) ((float) $d['nominal']))) ?></span>
                </div>
            <?php endforeach; ?>
            <hr>
            <div class="d-flex justify-content-between fw-bold">
                <span>TOTAL</span>
                <span><?= htmlspecialchars($formatRupiah($nominalTotal)) ?></span>
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
        setTimeout(() => document.body.removeAttribute('data-print-mode'), 1000);
    }
    async function downloadPng() {
        const target = document.getElementById('receipt-official');
        if (!target) return;
        const canvas = await html2canvas(target, { scale: 2, backgroundColor: '#ffffff' });
        const link = document.createElement('a');
        link.href = canvas.toDataURL('image/png');
        link.download = 'kuitansi-<?= htmlspecialchars($noKuitansi) ?>.png';
        link.click();
    }
    (function () {
        const el = document.getElementById('qrcode-verify');
        if (!el || typeof QRCode === 'undefined') return;
        new QRCode(el, {
            text: <?= json_encode($verifyUrl) ?>,
            width: 96,
            height: 96
        });
    })();
</script>
<style>
@media print {
    body * { visibility: hidden !important; }
    body[data-print-mode="official"] #receipt-official,
    body[data-print-mode="official"] #receipt-official * { visibility: visible !important; }
    body[data-print-mode="thermal"] #receipt-thermal,
    body[data-print-mode="thermal"] #receipt-thermal * { visibility: visible !important; }
    #receipt-official, #receipt-thermal { position: absolute; left: 0; top: 0; width: 100%; margin: 0 !important; }
    body[data-print-mode="thermal"] #receipt-thermal .card-body { max-width: 302px !important; }
}
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
