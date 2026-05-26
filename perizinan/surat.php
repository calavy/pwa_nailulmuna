<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/surat_nomor.php';

$id = (int) ($_GET['id'] ?? 0);

$statement = $pdo->prepare('
    SELECT i.*, s.nama_santri, s.nis, s.tingkatan, s.qr
    FROM perizinan i
    INNER JOIN santri s ON s.id = i.santri_id
    WHERE i.id = :id
');
$statement->execute(['id' => $id]);
$izin = $statement->fetch();

if (!$izin) {
    exit('Data izin tidak ditemukan.');
}

$approvalStatus = strtoupper((string) ($izin['approval_status'] ?? ''));
$existingToken = trim((string) ($izin['qr_token'] ?? ''));
$blockPrint = $approvalStatus === 'DITOLAK' || ($approvalStatus === 'PENDING' && $existingToken === '');
if ($blockPrint) {
    http_response_code(403);
    $statusLabel = $approvalStatus === 'DITOLAK' ? 'ditolak' : 'belum disetujui';
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><title>Surat belum dapat dicetak</title>';
    echo '<style>body{font-family:"Segoe UI",Arial,sans-serif;background:#f8fafc;color:#0f172a;margin:0;padding:0;}'
        . '.box{max-width:480px;margin:8vh auto;padding:28px 32px;background:#fff;border-radius:14px;border:1px solid #e2e8f0;box-shadow:0 12px 30px rgba(15,23,42,.08);}'
        . 'h1{font-size:20px;margin:0 0 8px;color:#b45309;}'
        . 'p{margin:0 0 14px;line-height:1.5;color:#334155;font-size:14px;}'
        . '.btn{display:inline-block;padding:8px 16px;border-radius:8px;background:#1d4ed8;color:#fff;text-decoration:none;font-weight:600;font-size:14px;}'
        . '</style></head><body><div class="box">';
    echo '<h1>Surat izin belum dapat dicetak</h1>';
    echo '<p>Permohonan izin atas nama <strong>' . htmlspecialchars((string) ($izin['nama_santri'] ?? '-')) . '</strong> ' . htmlspecialchars($statusLabel) . '. Surat hanya dapat dicetak setelah pengurus berwenang menyetujui permohonan.</p>';
    echo '<p>Silakan kembali ke halaman <a class="btn" href="/perizinan/index.php">Perizinan</a> untuk meninjau atau menyetujui permohonan ini.</p>';
    echo '</div></body></html>';
    exit;
}

$namaPonpes = app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren');
$jenisPendidikan = app_setting($pdo, 'jenis_pendidikan', '');
$alamatPonpes = app_setting($pdo, 'alamat_ponpes', '-');
$logoPath = app_setting($pdo, 'logo_path', '');
$logoUrl = app_setting($pdo, 'logo_url', '');
$logo = $logoPath !== '' ? '/' . $logoPath : $logoUrl;
$namaPengasuhDefault = app_setting($pdo, 'nama_pengasuh', '');
$telpPonpes = app_setting($pdo, 'telp_ponpes', '(021) 1234567');
$websitePonpes = app_setting($pdo, 'website_ponpes', 'www.pondokpesantren.com');
$jamTerbit = date('d-m-Y H:i');
$returnCode = trim((string) ($izin['qr_token'] ?? ''));
if ($returnCode === '') {
    $returnCode = bin2hex(random_bytes(16));
    $updateToken = $pdo->prepare('UPDATE perizinan SET qr_token = :qr_token WHERE id = :id');
    $updateToken->execute([
        'qr_token' => $returnCode,
        'id' => (int) $izin['id'],
    ]);
}
$returnQr = 'https://quickchart.io/qr?size=130&text=' . urlencode($returnCode);
$jenisIzin = (string) ($izin['jenis_izin'] ?? 'KELUAR');
if (strtoupper($jenisIzin) === 'PULANG') {
    $jenisIzin = 'TUGAS';
}
// Sakit: hijau | Tugas: merah | Keluar: biru
$headerColor = '#1d4ed8';
if ($jenisIzin === 'TUGAS') {
    $headerColor = '#dc2626';
} elseif ($jenisIzin === 'SAKIT') {
    $headerColor = '#15803d';
}
$categoryLabel = jenis_izin_label($jenisIzin);
$categoryClass = $jenisIzin === 'SAKIT' ? 'cat-sakit' : ($jenisIzin === 'TUGAS' ? 'cat-pulang' : 'cat-keluar');
$nomorSurat = trim((string) ($izin['nomor_surat'] ?? ''));
if ($nomorSurat === '') {
    $jenisKey = surat_nomor_jenis_from_izin($jenisIzin);
    $nomorSurat = surat_nomor_ambil_atau_buat($pdo, $jenisKey, 'izin:' . (int) $izin['id']);
    if (column_exists($pdo, 'perizinan', 'nomor_surat')) {
        $pdo->prepare('UPDATE perizinan SET nomor_surat = :n WHERE id = :id')->execute([
            'n' => $nomorSurat,
            'id' => (int) $izin['id'],
        ]);
    }
}
$tanggalMulai = (string) ($izin['tanggal_mulai'] ?? '-');
$tanggalSelesai = (string) ($izin['tanggal_selesai'] ?? '-');
$jamMulai = trim((string) ($izin['jam_mulai'] ?? ''));
$jamSelesai = trim((string) ($izin['jam_selesai'] ?? ''));
$jamMulaiTampil = $jamMulai !== '' ? substr($jamMulai, 0, 5) : '-';
$jamSelesaiTampil = $jamSelesai !== '' ? substr($jamSelesai, 0, 5) : '-';
$harusDatang = $tanggalSelesai . ' pukul ' . $jamSelesaiTampil;
$nbText = $jenisIzin === 'TUGAS'
    ? 'Jika ingin melakukan perpanjangan izin tugas, harap sowan pengasuh terlebih dahulu.'
    : 'Jika ingin melakukan perpanjangan izin sakit/keluar, harap konfirmasi kepada petugas.';
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Surat Izin</title>
    <style>
        @page { size: A5 portrait; margin: 6mm; }
        * { box-sizing: border-box; }
        body {
            font-family: "Segoe UI", Arial, sans-serif;
            font-size: 10.5pt;
            color: #111827;
            margin: 0;
            background: linear-gradient(180deg, #f8fafc 0%, #ecfeff 100%);
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
        
        /* Watermark */
        .sheet::before {
            content: "";
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) rotate(-30deg);
            width: 230px;
            height: 230px;
            background-image: url("<?= htmlspecialchars($logo) ?>");
            background-repeat: no-repeat;
            background-position: center;
            background-size: contain;
            opacity: 0.05;
            z-index: 0;
            pointer-events: none;
        }

        .header {
            display: flex; 
            gap: 10px;
            align-items: center; 
            border-bottom: 2px solid var(--izin-accent, #0f172a);
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
        .brand h2 { margin: 0; font-size: 14.5pt; color: #065f46; font-weight: 800; text-transform: uppercase; line-height: 1.1; }
        .brand .addr { margin: 0; font-size: 7.7pt; font-style: italic; color: #334155; }
        .brand .contact { margin-top: 1px; font-size: 7.4pt; color: #475569; }

        .meta { margin-top: 8px; font-size: 7.3pt; color: #64748b; font-style: italic; text-align: right; }
        
        .title { text-align: center; margin: 8px 0 7px; position: relative; z-index: 1; }
        .title strong { font-size: 11.4pt; text-decoration: underline; text-transform: uppercase; letter-spacing: 0.3px; }
        .title .doc-num { display: block; margin-top: 2px; font-size: 7.8pt; color: #475569; }
        
        .badge {
            display: inline-block; 
            padding: 4px 12px;
            border-radius: 6px;
            color: #fff;
            font-weight: 700; 
            margin-top: 4px;
            font-size: 8.1pt;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            box-shadow: inset 0 -1px 0 rgba(255,255,255,0.2);
        }
        .badge.cat-sakit { background: #15803d; border: 1px solid #166534; }
        .badge.cat-pulang { background: #dc2626; border: 1px solid #b91c1c; }
        .badge.cat-keluar { background: #1d4ed8; border: 1px solid #1e40af; }

        .content { line-height: 1.42; position: relative; z-index: 1; font-size: 8.8pt; }
        .content p { margin: 0 0 7px; }
        .content p:last-child { margin-bottom: 4px; }
        .info { width: 100%; margin: 5px 0 6px; border-collapse: collapse; }
        .info td { vertical-align: top; padding: 1px 0; }
        .info td:first-child { width: 88px; color: #334155; font-weight: 700; }

        .box-note {
            margin-top: 6px;
            background: #f8fafc;
            border: 1px solid #dbeafe;
            border-radius: 8px;
            padding: 6px 8px;
        }
        .box-nb {
            margin-top: 6px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 8px;
            padding: 6px 8px;
            font-size: 8pt;
            line-height: 1.35;
        }

        .return-box {
            margin-top: 8px;
            border: 1px dashed #94a3b8;
            border-radius: 10px;
            padding: 6px;
            display: flex; 
            align-items: center; 
            gap: 8px; 
            background-color: #fff;
        }
        .return-box img { width: 50px; height: 50px; }
        .return-box p { margin: 0; font-size: 7.5pt; color: #334155; line-height: 1.25; }

        .ttd-wrap { margin-top: auto; margin-bottom: 10px; position: relative; z-index: 1; }
        .ttd-meta { text-align: right; font-size: 8pt; color: #334155; margin-bottom: 14px; }
        .ttd {
            margin-top: 10px;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 28px;
            position: relative;
            z-index: 1;
        }
        .box { width: 45%; text-align: center; padding: 0 4px; }
        .box > div:first-child { margin-bottom: 6px; }
        .box .sign-space { height: 18mm; min-height: 52px; }
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
            .sheet { border: 1px solid #cbd5e1; box-shadow: none; padding: 7mm 8mm; min-height: calc(210mm - 12mm); }
            .badge {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                color-adjust: exact !important;
            }
        }
    </style>
</head>
<body onload="window.print()" style="--izin-accent: <?= htmlspecialchars($headerColor) ?>;">
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
            <strong>SURAT IZIN SANTRI</strong>
            <div class="doc-num">Nomor: <?= htmlspecialchars($nomorSurat) ?></div>
            <span class="badge <?= htmlspecialchars($categoryClass) ?>">KATEGORI: <?= htmlspecialchars($categoryLabel) ?></span>
        </div>

        <div class="content">
            <p>Yang bertanda tangan di bawah ini, pengurus pondok pesantren, menerangkan bahwa santri berikut memperoleh izin resmi sesuai ketentuan pondok.</p>
            <table class="info">
                <tr><td>Nama</td><td>: <?= htmlspecialchars($izin['nama_santri']) ?></td></tr>
                <tr><td>NIS</td><td>: <?= htmlspecialchars($izin['nis']) ?></td></tr>
                <tr><td>Tingkatan</td><td>: <?= htmlspecialchars($izin['tingkatan']) ?></td></tr>
                <tr><td>Tanggal Izin</td><td>: <?= htmlspecialchars($tanggalMulai) ?> s.d <?= htmlspecialchars($tanggalSelesai) ?></td></tr>
                <tr><td>Waktu Izin</td><td>: <?= htmlspecialchars($jamMulaiTampil) ?> s.d <?= htmlspecialchars($jamSelesaiTampil) ?></td></tr>
                <?php if ($jenisIzin !== 'SAKIT'): ?>
                    <tr><td>Harus Datang</td><td>: <?= htmlspecialchars($harusDatang) ?></td></tr>
                <?php endif; ?>
            </table>

            <div class="box-note">
                <strong>Keperluan/Alasan Izin:</strong><br>
                <?= nl2br(htmlspecialchars($izin['alasan'])) ?>
            </div>
            <div class="box-nb">
                <strong>NB:</strong><br>
                <?= htmlspecialchars($nbText) ?>
            </div>
            <p class="mb-0">Demikian surat izin ini dibuat agar dapat dipergunakan sebagaimana mestinya dan dipatuhi waktu kembalinya.</p>

        </div>

        <div class="ttd-wrap">
            <div class="ttd-meta">
                Muntilan, <?= htmlspecialchars(date('d-m-Y')) ?>
            </div>
            <div class="ttd">
                <div class="box">
                    <div>Pemberi Izin,</div>
                    <div class="sign-space"></div>
                    <div class="line"><?= htmlspecialchars($izin['pemberi_izin']) ?></div>
                </div>
                <div class="box">
                    <div>Pengasuh,</div>
                    <div class="sign-space"></div>
                    <div class="line"><?= htmlspecialchars($namaPengasuhDefault !== '' ? $namaPengasuhDefault : $izin['penandatangan_pengasuh']) ?></div>
                </div>
            </div>
            <div class="return-box">
                <img src="<?= htmlspecialchars($returnQr) ?>" alt="QR Kembali">
                <p>
                    <strong>VERIFIKASI IZIN SELESAI:</strong> Scan QR ini kepada petugas saat santri kembali ke pesantren.<br>
                    ID: <strong><?= htmlspecialchars($returnCode) ?></strong>
                </p>
            </div>
            <div class="print-time">Waktu Cetak: <?= htmlspecialchars($jamTerbit) ?></div>
        </div>
    </div>
</body>
</html>
