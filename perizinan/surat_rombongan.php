<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/datetime_display.php';
require_once __DIR__ . '/../helpers/pondok_cetak.php';
require_once __DIR__ . '/../helpers/perizinan_rombongan.php';
require_once __DIR__ . '/../helpers/perizinan_approval.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (user_is_pengasuh_kiai()) {
    http_response_code(403);
    exit('Akses cetak surat tidak tersedia untuk akun pengasuh.');
}

$id = (int) ($_GET['id'] ?? 0);
$meta = perizinan_rombongan_meta($pdo, $id);
if (!$meta) {
    exit('Data izin rombongan tidak ditemukan.');
}
if ((string) ($meta['approval_status'] ?? '') !== 'DISETUJUI') {
    http_response_code(403);
    exit('Surat rombongan hanya dapat dicetak setelah disetujui.');
}

$anggotaGrouped = perizinan_rombongan_anggota_grouped($pdo, $id);
$totalSantri = 0;
foreach ($anggotaGrouped as $rows) {
    $totalSantri += count($rows);
}

$kop = pondok_kop_data($pdo);
$namaPonpes = (string) $kop['nama_ponpes'];
$kotaPonpes = (string) $kop['kota_ponpes'];
$jamTerbit = app_format_datetime_id(date('Y-m-d H:i:s'));
$pengasuhBlok = perizinan_rombongan_surat_blok_pengasuh($pdo, $id, $meta);

$returnCode = trim((string) ($meta['qr_token'] ?? ''));
if ($returnCode === '') {
    $returnCode = bin2hex(random_bytes(16));
    $pdo->prepare('UPDATE perizinan_rombongan_meta SET qr_token = :qr WHERE id = :id')->execute(['qr' => $returnCode, 'id' => $id]);
    $pdo->prepare('UPDATE perizinan SET qr_token = :qr WHERE rombongan_id = :rid')->execute(['qr' => $returnCode, 'rid' => $id]);
}
$returnQr = 'https://quickchart.io/qr?size=360&margin=1&text=' . urlencode($returnCode);

$jenisIzin = (string) ($meta['jenis_izin'] ?? 'KELUAR');
if (strtoupper($jenisIzin) === 'PULANG') {
    $jenisIzin = 'TUGAS';
}
$headerColor = '#1d4ed8';
if ($jenisIzin === 'TUGAS') {
    $headerColor = '#dc2626';
} elseif ($jenisIzin === 'SAKIT') {
    $headerColor = '#15803d';
}
$categoryLabel = jenis_izin_label($jenisIzin);
$categoryClass = $jenisIzin === 'SAKIT' ? 'cat-sakit' : ($jenisIzin === 'TUGAS' ? 'cat-pulang' : 'cat-keluar');
$nomorSurat = 'IR-ROM/' . str_pad((string) $id, 5, '0', STR_PAD_LEFT) . '/' . date('Y');
$harusDatang = app_format_tanggal_id((string) ($meta['tanggal_selesai'] ?? '')) . ' pukul ' . app_format_jam((string) ($meta['jam_selesai'] ?? ''));
$rentangIzin = app_format_izin_rentang(
    (string) ($meta['tanggal_mulai'] ?? ''),
    (string) ($meta['tanggal_selesai'] ?? ''),
    (string) ($meta['jam_mulai'] ?? ''),
    (string) ($meta['jam_selesai'] ?? '')
);
$pemberiIzin = trim((string) ($meta['pemberi_izin'] ?? ''));
$alasan = trim((string) ($meta['alasan'] ?? ''));
$autoPrint = ($_GET['print'] ?? '1') !== '0';
$logoHref = (string) ($kop['logo_href'] ?? '');
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Surat Izin Rombongan #<?= $id ?></title>
    <style>
        @page { size: A4 portrait; margin: 12mm; }
        * { box-sizing: border-box; }
        body {
            font-family: "Segoe UI", Arial, sans-serif;
            font-size: 10.5pt;
            color: #111827;
            margin: 0;
            background: linear-gradient(180deg, #f8fafc 0%, #ecfeff 100%);
        }
        .no-print {
            padding: 12px;
            text-align: center;
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        .no-print button {
            padding: 8px 20px;
            font-size: 14px;
            border-radius: 8px;
            border: none;
            background: #0f766e;
            color: #fff;
            cursor: pointer;
            font-weight: 600;
        }
        .sheet-wrap { padding: 12px; }
        .sheet {
            position: relative;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            padding: 10mm 12mm;
            background: #fff;
            max-width: 210mm;
            margin: 0 auto;
            min-height: calc(297mm - 24mm);
            display: flex;
            flex-direction: column;
            box-shadow: 0 10px 24px rgba(15, 23, 42, 0.1);
            overflow: hidden;
        }
        <?= pondok_kop_surat_css($headerColor, $logoHref) ?>
        .title { text-align: center; margin: 12px 0 10px; position: relative; z-index: 1; }
        .title strong { font-size: 12pt; text-decoration: underline; text-transform: uppercase; letter-spacing: 0.04em; }
        .doc-num { display: block; margin-top: 4px; font-size: 9pt; color: #475569; }
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 6px;
            color: #fff;
            font-weight: 700;
            margin-top: 6px;
            font-size: 8.5pt;
            text-transform: uppercase;
        }
        .badge.cat-sakit { background: #15803d; border: 1px solid #166534; }
        .badge.cat-pulang { background: #dc2626; border: 1px solid #b91c1c; }
        .badge.cat-keluar { background: #1d4ed8; border: 1px solid #1e40af; }
        .content { line-height: 1.5; position: relative; z-index: 1; font-size: 10pt; }
        .content p { margin: 0 0 8px; }
        table.santri { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 9.5pt; }
        table.santri th, table.santri td { border: 1px solid #cbd5e1; padding: 5px 8px; text-align: left; }
        table.santri th { background: #f1f5f9; font-size: 9pt; }
        table.santri td.num { width: 2.2rem; text-align: center; }
        table.santri td.nis { width: 5.5rem; font-family: Consolas, monospace; font-size: 9pt; }
        tr.tingkatan-row td { background: #ecfdf5; font-weight: 700; color: #065f46; font-size: 9pt; }
        .info { width: 100%; margin: 6px 0; border-collapse: collapse; font-size: 9.5pt; }
        .info td { vertical-align: top; padding: 2px 0; }
        .info td:first-child { width: 110px; font-weight: 700; color: #334155; }
        .box-note { margin: 8px 0; background: #f8fafc; border: 1px solid #dbeafe; border-radius: 8px; padding: 8px 10px; }
        .box-nb { margin: 8px 0; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 8px 10px; font-size: 9pt; }
        .return-box { margin-top: auto; border: 1px dashed #94a3b8; border-radius: 10px; padding: 8px; display: flex; align-items: center; gap: 10px; z-index: 1; }
        .return-box img { width: 3cm; height: 3cm; min-width: 3cm; min-height: 3cm; object-fit: contain; }
        .return-box p { margin: 0; font-size: 8.5pt; line-height: 1.35; }
        .ttd-wrap { margin-top: 14px; z-index: 1; }
        .ttd-meta { text-align: right; font-size: 9pt; margin-bottom: 10px; }
        .ttd { display: flex; justify-content: space-between; gap: 24px; }
        .ttd .box { width: 46%; text-align: center; }
        .ttd .sign-space { height: 20mm; min-height: 56px; }
        .ttd .line { margin: 0 auto; width: 90%; border-top: 1px solid #0f172a; font-weight: 700; padding-top: 6px; font-size: 9pt; }
        .print-time { margin-top: 10px; border-top: 1px dashed #cbd5e1; text-align: right; font-size: 8pt; color: #64748b; }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .sheet-wrap { padding: 0; }
            .sheet { border: 1px solid #cbd5e1; border-radius: 0; box-shadow: none; max-width: none; }
            .badge { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
        }
    </style>
</head>
<body style="--izin-accent: <?= htmlspecialchars($headerColor) ?>;"<?= $autoPrint ? ' onload="window.print()"' : '' ?>>
<div class="no-print">
    <button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
    <span class="text-muted small ms-2"><?= $totalSantri ?> santri · <?= count($anggotaGrouped) ?> tingkatan</span>
</div>
<div class="sheet-wrap">
<div class="sheet sheet--kop-watermark">
    <?= pondok_kop_surat_html($kop, $headerColor) ?>

    <div class="title">
        <strong>Surat Keterangan Izin Keluar Rombongan Santri</strong>
        <span class="doc-num">Nomor: <?= htmlspecialchars($nomorSurat) ?></span>
        <span class="badge <?= htmlspecialchars($categoryClass) ?>">Kategori: <?= htmlspecialchars($categoryLabel) ?></span>
    </div>

    <div class="content">
        <p>Yang bertanda tangan di bawah ini, Pengurus <?= htmlspecialchars($namaPonpes) ?>, dengan ini menerangkan bahwa santri-santri yang namanya tercantum dalam daftar berikut diizinkan untuk keluar pesantren secara <strong>rombongan</strong> dengan ketentuan dan masa berlaku sebagaimana disebutkan di bawah ini.</p>

        <table class="santri">
            <thead>
                <tr>
                    <th class="num">No</th>
                    <th class="nis">NIS</th>
                    <th>Nama Santri</th>
                    <th>Tingkatan</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $no = 1;
            foreach ($anggotaGrouped as $tingkatanLabel => $santriRows):
                ?>
                <tr class="tingkatan-row">
                    <td colspan="4">Tingkatan: <?= htmlspecialchars($tingkatanLabel) ?> (<?= count($santriRows) ?> santri)</td>
                </tr>
                <?php foreach ($santriRows as $a): ?>
                    <tr>
                        <td class="num"><?= $no++ ?></td>
                        <td class="nis"><?= htmlspecialchars((string) $a['nis']) ?></td>
                        <td><?= htmlspecialchars((string) $a['nama_santri']) ?></td>
                        <td><?= htmlspecialchars($tingkatanLabel) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
            </tbody>
        </table>

        <table class="info">
            <tr><td>Jumlah santri</td><td>: <?= $totalSantri ?> orang</td></tr>
            <tr><td>Masa berlaku izin</td><td>: <?= htmlspecialchars($rentangIzin) ?></td></tr>
            <tr><td>Batas waktu kembali</td><td>: <?= htmlspecialchars($harusDatang) ?></td></tr>
        </table>

        <div class="box-note">
            <strong>Keperluan / alasan izin:</strong><br>
            <?= $alasan !== '' ? nl2br(htmlspecialchars($alasan)) : '—' ?>
        </div>

        <div class="box-nb">
            <strong>Catatan:</strong> Surat ini berlaku untuk seluruh rombongan dengan satu kode verifikasi (QR). Saat rombongan tiba kembali di pesantren, petugas memindai QR ini lalu mencatat kehadiran tiap santri yang telah masuk asrama melalui sistem perizinan.
        </div>

        <p>Demikian surat keterangan izin keluar rombongan ini dibuat dengan sebenarnya untuk dapat dipergunakan sebagaimana mestinya. Seluruh santri rombongan wajib mematuhi tata tertib pondok dan kembali tepat pada waktu yang ditetapkan.</p>
    </div>

    <div class="return-box">
        <img src="<?= htmlspecialchars($returnQr) ?>" alt="QR verifikasi kembali">
        <p>
            <strong>Verifikasi kedatangan rombongan:</strong> pindai QR ini kepada petugas keamanan atau petugas perizinan saat rombongan tiba di lingkungan pesantren.<br>
            <span style="font-family:monospace;font-size:8pt">Kode: <?= htmlspecialchars($returnCode) ?></span>
        </p>
    </div>

    <div class="ttd-wrap">
        <div class="ttd-meta"><?= htmlspecialchars($kotaPonpes) ?>, <?= htmlspecialchars(app_format_tanggal_id(date('Y-m-d'))) ?></div>
        <div class="ttd">
            <div class="box">
                <div>Pemberi Izin,</div>
                <div class="sign-space"></div>
                <div class="line"><?= htmlspecialchars($pemberiIzin !== '' ? $pemberiIzin : '(_____________________)') ?></div>
            </div>
            <div class="box">
                <div>Pengasuh,</div>
                <?php if ($pengasuhBlok['disetujui']): ?>
                    <div class="sign-space" style="font-size:9pt;color:#15803d;display:flex;align-items:center;justify-content:center;text-align:center;font-weight:600;">
                        <?= htmlspecialchars($pengasuhBlok['keterangan']) ?>
                    </div>
                    <div class="line"><?= htmlspecialchars($pengasuhBlok['nama']) ?></div>
                    <div class="line" style="font-size:9pt;color:#475569;"><?= htmlspecialchars($pengasuhBlok['waktu']) ?></div>
                <?php else: ?>
                    <div class="sign-space"></div>
                    <div class="line"><?= htmlspecialchars($pengasuhBlok['nama'] !== '' ? $pengasuhBlok['nama'] : '(_____________________)') ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="print-time">Dicetak: <?= htmlspecialchars($jamTerbit) ?></div>
    </div>
</div>
</div>
</body>
</html>
