<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/santri_keluar.php';

require_roles(['admin', 'pengurus']);
ensure_santri_keluar_columns($pdo);
ensure_wali_santri_table($pdo);

$id = (int) ($_GET['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM santri WHERE id = :id LIMIT 1');
$st->execute(['id' => $id]);
$s = $st->fetch(PDO::FETCH_ASSOC);
if (!$s || trim((string) ($s['keluar_settled_at'] ?? '')) === '') {
    http_response_code(404);
    exit('Surat belum tersedia. Selesaikan administrasi keluar terlebih dahulu.');
}

$namaPonpes = app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren');
$jenisPendidikan = app_setting($pdo, 'jenis_pendidikan', '');
$alamatPonpes = app_setting($pdo, 'alamat_ponpes', '-');
$logo = app_pondok_logo_href($pdo, false);
$namaPengasuhDefault = app_setting($pdo, 'nama_pengasuh', '');
$telpPonpes = app_setting($pdo, 'telp_ponpes', '(021) 1234567');
$websitePonpes = app_setting($pdo, 'website_ponpes', 'www.pondokpesantren.com');
$nomorSurat = trim((string) ($s['nomor_surat_keluar'] ?? '-'));
$wali = santri_wali_display_row($pdo, $s);
$katLabel = keluar_kategori_label((string) ($s['keluar_kategori'] ?? ''));
$jamTerbit = date('d-m-Y H:i');
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Surat Keluar Santri</title>
    <style>
        @page { size: A5 portrait; margin: 6mm; }
        * { box-sizing: border-box; }
        body { font-family: "Segoe UI", Arial, sans-serif; font-size: 10.5pt; color: #111827; margin: 0; background: linear-gradient(180deg, #f8fafc 0%, #ecfeff 100%); }
        .sheet { position: relative; border: 1px solid #cbd5e1; border-radius: 12px; width: 100%; min-height: calc(210mm - 12mm); padding: 7mm 8mm; background: #fff; overflow: hidden; box-shadow: 0 10px 24px rgba(15, 23, 42, 0.12); }
        .sheet::before { content: ""; position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); width: 230px; height: 230px; background-image: url("<?= htmlspecialchars($logo) ?>"); background-repeat: no-repeat; background-position: center; background-size: contain; opacity: 0.05; z-index: 0; pointer-events: none; }
        .header { display: flex; gap: 10px; align-items: center; border-bottom: 2px solid #0f172a; padding-bottom: 7px; margin-bottom: 4px; position: relative; z-index: 1; }
        .logo { width: 58px; height: 58px; object-fit: cover; border-radius: 999px; border: 1px solid #d1d5db; }
        .brand { flex: 1; text-align: center; }
        .brand .small { margin: 0; font-size: 8.7pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; }
        .brand h2 { margin: 0; font-size: 14.5pt; color: #065f46; font-weight: 800; text-transform: uppercase; line-height: 1.1; }
        .brand .addr { margin: 0; font-size: 7.7pt; font-style: italic; color: #334155; }
        .brand .contact { margin-top: 1px; font-size: 7.4pt; color: #475569; }
        .title { text-align: center; margin: 10px 0 8px; position: relative; z-index: 1; }
        .title strong { font-size: 11.4pt; text-decoration: underline; text-transform: uppercase; letter-spacing: 0.3px; }
        .title .doc-num { display: block; margin-top: 2px; font-size: 7.8pt; color: #475569; }
        .content { line-height: 1.45; position: relative; z-index: 1; font-size: 8.8pt; }
        .info { width: 100%; margin: 6px 0; border-collapse: collapse; }
        .info td { vertical-align: top; padding: 2px 0; }
        .info td:first-child { width: 100px; color: #334155; font-weight: 700; }
        .ttd-wrap { margin-top: 14px; position: relative; z-index: 1; text-align: right; font-size: 8.8pt; }
        .sign-space { height: 48px; }
        @media print { body { background: #fff; } .sheet { box-shadow: none; } }
    </style>
</head>
<body onload="window.print()">
    <div class="sheet">
        <div class="header">
            <?php if ($logo): ?>
                <img src="<?= htmlspecialchars($logo) ?>" alt="" class="logo">
            <?php endif; ?>
            <div class="brand">
                <p class="small"><?= htmlspecialchars($jenisPendidikan !== '' ? $jenisPendidikan : 'Lembaga Pondok Pesantren') ?></p>
                <h2><?= htmlspecialchars($namaPonpes) ?></h2>
                <p class="addr"><?= htmlspecialchars($alamatPonpes) ?></p>
                <p class="contact">Telp: <?= htmlspecialchars($telpPonpes) ?> | Website: <?= htmlspecialchars($websitePonpes) ?></p>
            </div>
        </div>
        <div class="title">
            <strong>SURAT KETERANGAN KELUAR SANTRI</strong>
            <div class="doc-num">Nomor: <?= htmlspecialchars($nomorSurat) ?></div>
        </div>
        <div class="content">
            <p>Yang bertanda tangan di bawah ini, pengurus <?= htmlspecialchars($namaPonpes) ?>, menerangkan bahwa:</p>
            <table class="info">
                <tr><td>Nama santri</td><td>: <?= htmlspecialchars((string) $s['nama_santri']) ?></td></tr>
                <tr><td>NIS</td><td>: <?= htmlspecialchars((string) $s['nis']) ?></td></tr>
                <tr><td>Tingkatan</td><td>: <?= htmlspecialchars((string) ($s['tingkatan'] ?? '-')) ?></td></tr>
                <tr><td>Status keluar</td><td>: <?= htmlspecialchars($katLabel) ?></td></tr>
                <tr><td>Tanggal keluar</td><td>: <?= htmlspecialchars((string) ($s['tanggal_keluar'] ?? '-')) ?></td></tr>
                <tr><td>Alasan</td><td>: <?= nl2br(htmlspecialchars((string) ($s['alasan_keluar'] ?? '-'))) ?></td></tr>
            </table>
            <?php if ($wali): ?>
                <p><strong>Data wali / orang tua:</strong></p>
                <table class="info">
                    <tr><td>Nama wali</td><td>: <?= htmlspecialchars($wali['nama']) ?></td></tr>
                    <tr><td>No. WA</td><td>: <?= htmlspecialchars($wali['no_wa'] !== '' ? $wali['no_wa'] : '—') ?></td></tr>
                    <tr><td>No. ID wali</td><td>: <?= htmlspecialchars($wali['nomor_id']) ?></td></tr>
                    <tr><td>Alamat</td><td>: <?= nl2br(htmlspecialchars($wali['alamat'])) ?></td></tr>
                </table>
            <?php endif; ?>
            <p>Administrasi keuangan pondok atas nama santri tersebut telah diselesaikan sesuai ketentuan yang berlaku pada tanggal diterbitkannya surat ini.</p>
            <p class="mb-0">Demikian surat keterangan ini dibuat untuk dipergunakan sebagaimana mestinya.</p>
        </div>
        <div class="ttd-wrap">
            <div><?= htmlspecialchars(date('d-m-Y')) ?></div>
            <div>Pengasuh / Kepala Madrasah,</div>
            <div class="sign-space"></div>
            <div><strong><?= htmlspecialchars($namaPengasuhDefault !== '' ? $namaPengasuhDefault : '(_______________________)') ?></strong></div>
            <div class="mt-2 small text-muted">Waktu cetak: <?= htmlspecialchars($jamTerbit) ?></div>
        </div>
    </div>
</body>
</html>
