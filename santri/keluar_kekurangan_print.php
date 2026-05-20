<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/santri_keluar.php';

require_roles(['admin', 'pengurus']);
ensure_santri_identity_columns($pdo);
ensure_santri_keluar_columns($pdo);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    exit('Parameter id tidak valid.');
}

$st = $pdo->prepare('SELECT * FROM santri WHERE id = :id LIMIT 1');
$st->execute(['id' => $id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    exit('Data santri tidak ditemukan.');
}

$isNon = strtoupper(trim((string) ($row['status_santri'] ?? 'AKTIF'))) === 'NON_AKTIF' || (int) ($row['is_aktif'] ?? 1) === 0;
$settled = trim((string) ($row['keluar_settled_at'] ?? '')) !== '';

if (!$isNon) {
    http_response_code(400);
    exit('Ringkasan ini hanya untuk santri non aktif.');
}

if ($settled) {
    http_response_code(400);
    exit('Administrasi keluar sudah selesai — ringkasan kekurangan tidak lagi dipakai.');
}

$periodeMulai = (int) app_setting($pdo, 'keuangan_periode_mulai', (string) (int) date('Y'));
$periodeSelesai = (int) app_setting($pdo, 'keuangan_periode_selesai', (string) ($periodeMulai + 1));
if ($periodeSelesai < $periodeMulai) {
    $periodeSelesai = $periodeMulai + 1;
}

$kelasKategori = trim((string) ($row['kategori_kelas'] ?? ''));
if ($kelasKategori === '' && trim((string) ($row['tingkatan'] ?? '')) !== '') {
    $kelasKategori = (string) $row['tingkatan'];
}

$outstanding = santri_outstanding_bulanan_rows($pdo, $id, $kelasKategori, $periodeMulai, $periodeSelesai);
$cashlessSaldo = santri_cashless_balance($pdo, $id);
$totalTagihan = 0;
foreach ($outstanding as $o) {
    $totalTagihan += (int) ($o['sisa'] ?? 0);
}

$namaPonpes = trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren'));
$jenisPendidikan = trim((string) app_setting($pdo, 'jenis_pendidikan', ''));
$alamatPonpes = trim((string) app_setting($pdo, 'alamat_ponpes', ''));
$logoPath = trim((string) app_setting($pdo, 'logo_path', ''));
$logoUrl = trim((string) app_setting($pdo, 'logo_url', ''));
$logo = $logoPath !== '' ? '/' . ltrim($logoPath, '/') : $logoUrl;

$bulanNama = [
    1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
    7 => 'Jul', 8 => 'Ags', 9 => 'Sep', 10 => 'Okt', 11 => 'Nop', 12 => 'Des',
];

$tglCetak = date('d/m/Y H:i');
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ringkasan kekurangan — <?= htmlspecialchars((string) $row['nama_santri']) ?></title>
    <style>
        @page { size: A4; margin: 14mm; }
        * { box-sizing: border-box; }
        body { font-family: "Segoe UI", Tahoma, Arial, sans-serif; font-size: 11pt; color: #111827; margin: 0; background: #f1f5f9; }
        .sheet { max-width: 720px; margin: 12px auto; padding: 18px 20px; background: #fff; border: 1px solid #cbd5e1; border-radius: 8px; }
        h1 { font-size: 1rem; margin: 0 0 4px; letter-spacing: 0.02em; }
        .muted { color: #64748b; font-size: 9.5pt; margin: 0 0 14px; }
        .kop { display: flex; gap: 12px; align-items: center; border-bottom: 2px solid #0f172a; padding-bottom: 10px; margin-bottom: 14px; }
        .kop img { width: 52px; height: 52px; object-fit: cover; border-radius: 50%; border: 1px solid #e2e8f0; }
        .kop-mid { flex: 1; text-align: center; }
        .kop-mid .tag { font-size: 8.5pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; margin: 0; color: #334155; }
        .kop-mid .nama { font-size: 13pt; font-weight: 800; margin: 2px 0; color: #0f172a; }
        .kop-mid .addr { font-size: 8.5pt; color: #475569; margin: 0; font-style: italic; }
        .ident { font-size: 10pt; margin-bottom: 12px; line-height: 1.45; }
        table { width: 100%; border-collapse: collapse; font-size: 10pt; }
        th, td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }
        th { background: #f8fafc; font-weight: 700; }
        td.num { text-align: right; font-variant-numeric: tabular-nums; }
        .total-row td { font-weight: 700; background: #f1f5f9; }
        .cash { margin-top: 12px; padding: 10px 12px; background: #ecfdf5; border: 1px solid #6ee7b7; border-radius: 6px; font-size: 10pt; }
        .foot { margin-top: 16px; font-size: 9pt; color: #64748b; }
        .no-print { margin: 0 0 12px; text-align: right; }
        @media print {
            body { background: #fff; }
            .sheet { margin: 0; border: none; border-radius: 0; max-width: none; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>
    <div class="sheet">
        <div class="no-print">
            <button type="button" onclick="window.print()" style="padding:6px 14px;font-weight:600;cursor:pointer;border-radius:6px;border:1px solid #94a3b8;background:#fff;">Cetak</button>
        </div>
        <div class="kop">
            <?php if ($logo !== ''): ?>
                <img src="<?= htmlspecialchars($logo) ?>" alt="">
            <?php endif; ?>
            <div class="kop-mid">
                <p class="tag"><?= htmlspecialchars($jenisPendidikan !== '' ? $jenisPendidikan : 'Lembaga') ?></p>
                <p class="nama"><?= htmlspecialchars($namaPonpes) ?></p>
                <?php if ($alamatPonpes !== ''): ?>
                    <p class="addr"><?= htmlspecialchars($alamatPonpes) ?></p>
                <?php endif; ?>
            </div>
        </div>
        <h1>Ringkasan kekurangan administrasi keluar</h1>
        <p class="muted">Tahun ajaran <?= (int) $periodeMulai ?>/<?= (int) $periodeSelesai ?> · dicetak <?= htmlspecialchars($tglCetak) ?></p>
        <div class="ident">
            <strong><?= htmlspecialchars((string) $row['nama_santri']) ?></strong><br>
            NIS: <?= htmlspecialchars((string) $row['nis']) ?> · Tingkatan: <?= htmlspecialchars((string) ($row['tingkatan'] ?? '—')) ?><br>
            Tanggal keluar: <?= htmlspecialchars((string) ($row['tanggal_keluar'] ?? '—')) ?> · Alasan: <?= htmlspecialchars((string) ($row['alasan_keluar'] ?? '—')) ?>
        </div>
        <?php if ($outstanding === []): ?>
            <p style="margin:0;font-size:10pt;">Tidak ada sisa tagihan bulanan (Syahriyah/Makan/Saku) menurut data pembayaran.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Bulan</th>
                        <th>Pos</th>
                        <th class="num">Sisa (Rp)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($outstanding as $o): ?>
                        <tr>
                            <td><?= (int) $o['bulan'] ?> (<?= htmlspecialchars($bulanNama[(int) $o['bulan']] ?? '') ?>)</td>
                            <td><?= htmlspecialchars((string) $o['nama']) ?></td>
                            <td class="num"><?= number_format((int) $o['sisa'], 0, ',', '.') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="total-row">
                        <td colspan="2">Jumlah kekurangan tagihan</td>
                        <td class="num"><?= number_format($totalTagihan, 0, ',', '.') ?></td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>
        <div class="cash">
            <strong>Saldo cashless</strong> (akan dipakai otomatis saat penyelesaian administrasi): Rp <?= number_format($cashlessSaldo, 0, ',', '.') ?>
        </div>
        <p class="foot">Dokumen internal — penyelesaian administrasi keluar tetap dilakukan di aplikasi.</p>
    </div>
</body>
</html>
