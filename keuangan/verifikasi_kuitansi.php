<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_vendor.php';

$id = (int) ($_GET['id'] ?? 0);
$sig = trim((string) ($_GET['sig'] ?? ''));

$status = 'INVALID';
$message = 'Data verifikasi tidak lengkap.';
$kuitansi = null;

if ($id > 0 && $sig !== '') {
    $stmt = $pdo->prepare('
        SELECT p.id, p.tanggal_bayar, p.total_nominal, p.jenis_periode, p.bulan_tagihan, p.created_at,
               s.nis, COALESCE(NULLIF(s.nama_santri, ""), s.nama) AS nama_santri
        FROM keuangan_pembayaran p
        INNER JOIN santri s ON s.id = p.santri_id
        WHERE p.id = :id
        LIMIT 1
    ');
    $stmt->execute(['id' => $id]);
    $kuitansi = $stmt->fetch();
    if ($kuitansi) {
        $verifySecret = (string) app_setting($pdo, 'kuitansi_verify_secret', 'pwa_nailulmuna_secret');
        $expectedSig = substr(hash('sha256', $id . '|' . (string) $kuitansi['tanggal_bayar'] . '|' . (string) $kuitansi['total_nominal'] . '|' . $verifySecret), 0, 16);
        if (hash_equals($expectedSig, $sig)) {
            $status = 'VALID';
            $message = 'Kuitansi asli dan tervalidasi oleh sistem.';
        } else {
            $status = 'INVALID';
            $message = 'Tanda tangan verifikasi tidak cocok.';
        }
    } else {
        $status = 'INVALID';
        $message = 'Kuitansi tidak ditemukan.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Verifikasi Kuitansi</title>
    <link href="<?= htmlspecialchars(app_vendor_bootstrap_css_href()) ?>" rel="stylesheet" crossorigin="anonymous">
</head>
<body class="bg-light">
<div class="container py-4">
    <div class="card shadow-sm mx-auto" style="max-width:720px">
        <div class="card-body">
            <h1 class="h4 mb-3">Verifikasi Kuitansi</h1>
            <div class="alert <?= $status === 'VALID' ? 'alert-success' : 'alert-danger' ?>">
                <strong>Status:</strong> <?= htmlspecialchars($status) ?><br>
                <?= htmlspecialchars($message) ?>
            </div>
            <?php if ($kuitansi): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered mb-0">
                        <tr><th class="text-nowrap">No Kuitansi</th><td><?= htmlspecialchars('KW-' . str_pad((string) $kuitansi['id'], 6, '0', STR_PAD_LEFT)) ?></td></tr>
                        <tr><th>NIS</th><td><?= htmlspecialchars((string) ($kuitansi['nis'] ?? '-')) ?></td></tr>
                        <tr><th>Nama Santri</th><td><?= htmlspecialchars((string) ($kuitansi['nama_santri'] ?? '-')) ?></td></tr>
                        <tr><th>Tanggal Bayar</th><td><?= htmlspecialchars((string) $kuitansi['tanggal_bayar']) ?></td></tr>
                        <tr><th>Jenis Periode</th><td><?= htmlspecialchars((string) $kuitansi['jenis_periode']) ?></td></tr>
                        <tr><th>Total Nominal</th><td>Rp <?= number_format((int) ((float) $kuitansi['total_nominal']), 0, ',', '.') ?></td></tr>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
