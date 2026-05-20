<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/surat_nomor.php';

require_roles(['admin', 'pengurus']);
ensure_point_tables($pdo);

$santriId = (int) ($_GET['santri_id'] ?? 0);
$month = max(1, min(12, (int) ($_GET['month'] ?? date('m'))));
$year = max(2020, min(2100, (int) ($_GET['year'] ?? app_tahun_masehi_default($pdo))));
$spLevel = strtoupper(trim((string) ($_GET['sp'] ?? 'SP1')));
if (!in_array($spLevel, ['SP1', 'SP2'], true)) {
    $spLevel = 'SP1';
}
if ($santriId <= 0) {
    exit('Santri tidak valid.');
}

$santriStmt = $pdo->prepare('SELECT id, nis, nama_santri, tingkatan FROM santri WHERE id = :id');
$santriStmt->execute(['id' => $santriId]);
$santri = $santriStmt->fetch();
if (!$santri) {
    exit('Data santri tidak ditemukan.');
}

$startDate = sprintf('%04d-%02d-01', $year, $month);
$endDate = date('Y-m-t', strtotime($startDate));
$pointStmt = $pdo->prepare('
    SELECT COALESCE(SUM(point_delta), 0) AS total_poin
    FROM point_ledger
    WHERE santri_id = :santri_id
      AND tanggal BETWEEN :start_date AND :end_date
');
$pointStmt->execute([
    'santri_id' => $santriId,
    'start_date' => $startDate,
    'end_date' => $endDate,
]);
$totalPoin = (int) $pointStmt->fetchColumn();

if ($spLevel === 'SP2' && $totalPoin < 75) {
    exit('Belum memenuhi ambang SP2 (minimal 75 poin).');
}
if ($spLevel === 'SP1' && $totalPoin < 50) {
    exit('Belum memenuhi ambang SP1 (minimal 50 poin).');
}

$jenisKode = $spLevel === 'SP2' ? 'sp2' : 'sp1';
$refKey = $jenisKode . ':' . $santriId . ':' . $year . ':' . $month;
$nomorSurat = surat_nomor_ambil_atau_buat($pdo, $jenisKode, $refKey, $year, $month);

$bulanNama = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
];
$periodeLabel = ($bulanNama[$month] ?? (string) $month) . ' ' . $year;

$namaPonpes = app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren');
$jenisPendidikan = app_setting($pdo, 'jenis_pendidikan', '');
$alamatPonpes = app_setting($pdo, 'alamat_ponpes', '-');
$namaPengasuhDefault = app_setting($pdo, 'nama_pengasuh', '');
$telpPonpes = app_setting($pdo, 'telp_ponpes', '(021) 1234567');
$websitePonpes = app_setting($pdo, 'website_ponpes', 'www.pondokpesantren.com');
$logoPath = app_setting($pdo, 'logo_path', '');
$logoUrl = app_setting($pdo, 'logo_url', '');
$logo = $logoPath !== '' ? '/pwa_nailulmuna/' . $logoPath : $logoUrl;
$jamTerbit = date('d-m-Y H:i');

$judul = $spLevel === 'SP2' ? 'Surat Peringatan 2 (SP2)' : 'Surat Peringatan 1 (SP1)';
$isiSanksi = $spLevel === 'SP2'
    ? 'Pemanggilan orang tua/wali dan pembinaan kedisiplinan lanjutan sesuai ketentuan pondok.'
    : 'Pembinaan kedisiplinan tahap awal sesuai ketentuan pondok.';
$headerColor = $spLevel === 'SP2' ? '#b91c1c' : '#b45309';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($judul) ?></title>
    <style>
        @page { size: A5 portrait; margin: 6mm; }
        * { box-sizing: border-box; }
        body {
            font-family: "Segoe UI", Arial, sans-serif;
            font-size: 10.5pt;
            color: #111827;
            margin: 0;
            background: linear-gradient(180deg, #f8fafc 0%, #fff7ed 100%);
        }
        .sheet {
            position: relative;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            width: 100%;
            min-height: calc(210mm - 12mm);
            padding: 7mm 8mm;
            background: #fff;
            overflow: hidden;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.12);
            display: flex;
            flex-direction: column;
        }
        .sheet::before {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            width: 200px;
            height: 200px;
            background-image: url("<?= htmlspecialchars($logo) ?>");
            background-repeat: no-repeat;
            background-position: center;
            background-size: contain;
            opacity: 0.04;
            z-index: 0;
            pointer-events: none;
        }
        .header {
            display: flex;
            gap: 10px;
            align-items: center;
            border-bottom: 2px solid var(--sp-accent, #0f172a);
            padding-bottom: 7px;
            margin-bottom: 4px;
            position: relative;
            z-index: 1;
        }
        .header::after {
            content: "";
            display: block;
            border-bottom: 1px solid #334155;
            margin-top: 2px;
            width: 100%;
            position: absolute;
            bottom: -6px;
        }
        .logo { width: 58px; height: 58px; object-fit: cover; border-radius: 999px; border: 1px solid #d1d5db; }
        .brand { flex: 1; text-align: center; }
        .brand .small { margin: 0; font-size: 8.7pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; }
        .brand h2 { margin: 0; font-size: 14.5pt; color: #0f172a; font-weight: 800; text-transform: uppercase; line-height: 1.1; }
        .brand .addr { margin: 0; font-size: 7.7pt; font-style: italic; color: #334155; }
        .brand .contact { margin-top: 1px; font-size: 7.4pt; color: #475569; }
        .title { text-align: center; margin: 8px 0 7px; position: relative; z-index: 1; }
        .title strong { font-size: 11.4pt; text-decoration: underline; text-transform: uppercase; letter-spacing: 0.3px; }
        .title .doc-num { display: block; margin-top: 2px; font-size: 7.8pt; color: #475569; }
        .content { line-height: 1.42; position: relative; z-index: 1; font-size: 8.8pt; }
        .content p { margin: 0 0 7px; }
        .info { width: 100%; margin: 5px 0 6px; border-collapse: collapse; }
        .info td { vertical-align: top; padding: 1px 0; }
        .info td:first-child { width: 100px; color: #334155; font-weight: 700; }
        .box-note {
            margin-top: 6px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 8px;
            padding: 6px 8px;
            font-size: 8pt;
        }
        .ttd-wrap { margin-top: auto; margin-bottom: 8px; position: relative; z-index: 1; }
        .ttd-meta { text-align: right; font-size: 8pt; color: #334155; margin-bottom: 10px; }
        .ttd {
            margin-top: 8px;
            display: flex;
            justify-content: flex-end;
            position: relative;
            z-index: 1;
        }
        .box { width: 48%; text-align: center; padding: 0 4px; }
        .box .sign-space { height: 16mm; min-height: 48px; }
        .line {
            margin: 0 auto;
            width: 90%;
            border-top: 1px solid #0f172a;
            font-weight: 700;
            padding-top: 8px;
            margin-top: 4px;
            font-size: 8.5pt;
        }
        .print-time {
            margin-top: 8px;
            padding-top: 4px;
            border-top: 1px dashed #cbd5e1;
            text-align: right;
            font-size: 7.4pt;
            color: #64748b;
        }
        @media print {
            body { background: #fff; }
            .sheet { box-shadow: none; }
        }
    </style>
</head>
<body onload="window.print()" style="--sp-accent: <?= htmlspecialchars($headerColor) ?>;">
    <div class="sheet">
        <div class="header">
            <?php if ($logo): ?>
                <img src="<?= htmlspecialchars($logo) ?>" alt="logo" class="logo">
            <?php endif; ?>
            <div class="brand">
                <p class="small"><?= htmlspecialchars($jenisPendidikan !== '' ? $jenisPendidikan : 'Lembaga Pondok Pesantren') ?></p>
                <h2><?= htmlspecialchars($namaPonpes) ?></h2>
                <p class="addr"><?= htmlspecialchars($alamatPonpes) ?></p>
                <p class="contact">Telp: <?= htmlspecialchars($telpPonpes) ?> | Website: <?= htmlspecialchars($websitePonpes) ?></p>
            </div>
        </div>

        <div class="title">
            <strong><?= htmlspecialchars($judul) ?></strong>
            <div class="doc-num">Nomor: <?= htmlspecialchars($nomorSurat) ?></div>
        </div>

        <div class="content">
            <p>Yang bertanda tangan di bawah ini, pengurus <?= htmlspecialchars($namaPonpes) ?>, menerangkan bahwa:</p>
            <table class="info">
                <tr><td>Nama</td><td>: <?= htmlspecialchars((string) $santri['nama_santri']) ?></td></tr>
                <tr><td>NIS</td><td>: <?= htmlspecialchars((string) $santri['nis']) ?></td></tr>
                <tr><td>Tingkatan</td><td>: <?= htmlspecialchars((string) ($santri['tingkatan'] ?: '-')) ?></td></tr>
                <tr><td>Periode poin</td><td>: <?= htmlspecialchars($periodeLabel) ?></td></tr>
                <tr><td>Total poin</td><td>: <strong><?= (int) $totalPoin ?></strong> poin</td></tr>
            </table>
            <p>Santri tersebut telah mencapai akumulasi poin kedisiplinan sesuai ketentuan pondok, sehingga diberikan <strong><?= htmlspecialchars($judul) ?></strong>.</p>
            <div class="box-note">
                <strong>Tindak lanjut:</strong> <?= htmlspecialchars($isiSanksi) ?>
            </div>
            <p class="mb-0">Demikian surat ini dibuat untuk dipergunakan sebagaimana mestinya.</p>
        </div>

        <div class="ttd-wrap">
            <div class="ttd-meta">Muntilan, <?= htmlspecialchars(date('d-m-Y')) ?></div>
            <div class="ttd">
                <div class="box">
                    <div>Pengasuh,</div>
                    <div class="sign-space"></div>
                    <div class="line"><?= htmlspecialchars($namaPengasuhDefault !== '' ? $namaPengasuhDefault : '____________________') ?></div>
                </div>
            </div>
            <div class="print-time">Waktu cetak: <?= htmlspecialchars($jamTerbit) ?></div>
        </div>
    </div>
</body>
</html>
