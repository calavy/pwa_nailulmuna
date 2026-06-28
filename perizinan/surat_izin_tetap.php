<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/santri_izin_tetap.php';
require_once __DIR__ . '/../helpers/izin_tetap_hidmah_kategori.php';
require_once __DIR__ . '/../helpers/surat_nomor.php';
require_once __DIR__ . '/../helpers/pondok_cetak.php';
require_once __DIR__ . '/../helpers/surat_cetak_templates.php';

require_login();
require_roles(['admin', 'pengurus', 'petugas_absensi']);

$id = (int) ($_GET['id'] ?? 0);
$kelompokId = (int) ($_GET['kelompok_id'] ?? 0);
$idsParam = santri_izin_tetap_ids_dari_get($_GET);

$anggotaRows = [];
if ($kelompokId > 0) {
    $anggotaRows = santri_izin_tetap_anggota_by_kelompok($pdo, $kelompokId);
} elseif ($idsParam !== []) {
    $anggotaRows = santri_izin_tetap_anggota_by_ids($pdo, $idsParam);
} elseif ($id > 0) {
    $single = santri_izin_tetap_for_print($pdo, $id);
    if ($single) {
        $anggotaRows = [$single];
    }
}

$isGabungan = count($anggotaRows) > 1;
$validasi = $isGabungan
    ? santri_izin_tetap_validasi_cetak_gabungan($anggotaRows)
    : ['ok' => $anggotaRows !== [], 'message' => 'Data izin tetap tidak ditemukan.', 'anggota' => $anggotaRows];

if (!$validasi['ok']) {
    http_response_code(403);
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Surat belum dapat dicetak</title>';
    echo '<style>body{font-family:Segoe UI,Arial,sans-serif;background:#f8fafc;padding:2rem;}'
        . '.box{max-width:520px;margin:auto;padding:24px;background:#fff;border-radius:12px;border:1px solid #e2e8f0;}'
        . 'h1{font-size:18px;color:#b45309;} p{font-size:14px;color:#334155;line-height:1.5;}'
        . 'a{color:#1d4ed8;}</style></head><body><div class="box">';
    echo '<h1>Surat belum dapat dicetak</h1>';
    echo '<p>' . htmlspecialchars((string) ($validasi['message'] ?? 'Data tidak ditemukan.')) . '</p>';
    echo '<p><a href="' . htmlspecialchars(app_href('/perizinan/izin_tetap.php')) . '">Kembali ke Izin Tetap</a></p>';
    echo '</div></body></html>';
    exit;
}

santri_izin_tetap_redirect_gabungan_jika_perlu($pdo, $anggotaRows, $kelompokId, $idsParam);

$izin = $anggotaRows[0];
$izinId = (int) ($izin['id'] ?? 0);
$kelompokCetakId = $kelompokId > 0 ? $kelompokId : (int) ($izin['kelompok_id'] ?? 0);

if (!$isGabungan && (int) ($izin['is_aktif'] ?? 0) !== 1) {
    http_response_code(403);
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><title>Surat belum dapat dicetak</title>';
    echo '<style>body{font-family:Segoe UI,Arial,sans-serif;background:#f8fafc;padding:2rem;}'
        . '.box{max-width:480px;margin:auto;padding:24px;background:#fff;border-radius:12px;border:1px solid #e2e8f0;}'
        . 'h1{font-size:18px;color:#b45309;} p{font-size:14px;color:#334155;line-height:1.5;}'
        . 'a{color:#1d4ed8;}</style></head><body><div class="box">';
    echo '<h1>Surat belum dapat dicetak</h1>';
    echo '<p>Izin tetap atas nama <strong>' . htmlspecialchars((string) ($izin['nama_santri'] ?? '-')) . '</strong> sedang nonaktif. Aktifkan terlebih dahulu di halaman Izin Tetap.</p>';
    echo '<p><a href="' . htmlspecialchars(app_href('/perizinan/izin_tetap.php')) . '">Kembali ke Izin Tetap</a></p>';
    echo '</div></body></html>';
    exit;
}

$namaPonpes = app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren');
$jenisPendidikan = app_setting($pdo, 'jenis_pendidikan', '');
$alamatPonpes = app_setting($pdo, 'alamat_ponpes', '-');
$logo = app_pondok_logo_href($pdo, false);
$telpPonpes = app_setting($pdo, 'telp_ponpes', '(021) 1234567');
$websitePonpes = app_setting($pdo, 'website_ponpes', 'www.pondokpesantren.com');
$jamTerbit = date('d-m-Y H:i');

$jenisRaw = strtoupper((string) ($izin['jenis'] ?? 'HIDMAH'));
$jenisLabel = santri_izin_tetap_jenis_label($jenisRaw);
$headerColor = $jenisRaw === 'TUGAS' ? '#dc2626' : '#0d9488';
$categoryClass = $jenisRaw === 'TUGAS' ? 'cat-tugas' : 'cat-hidmah';

$nomorSurat = trim((string) ($izin['nomor_surat'] ?? ''));
if ($isGabungan) {
    $nomorKey = 'izin_tetap_kelompok:' . ($kelompokCetakId > 0 ? $kelompokCetakId : implode('-', array_column($anggotaRows, 'id')));
    $nomorSurat = surat_nomor_ambil_atau_buat($pdo, 'izin_tetap', $nomorKey);
    if (column_exists($pdo, 'santri_izin_tetap', 'nomor_surat')) {
        $updNomor = $pdo->prepare('UPDATE santri_izin_tetap SET nomor_surat = :n WHERE id = :id');
        foreach ($anggotaRows as $aRow) {
            $updNomor->execute(['n' => $nomorSurat, 'id' => (int) ($aRow['id'] ?? 0)]);
        }
    }
} elseif ($nomorSurat === '') {
    $nomorSurat = surat_nomor_ambil_atau_buat($pdo, 'izin_tetap', 'izin_tetap:' . $izinId);
    if (column_exists($pdo, 'santri_izin_tetap', 'nomor_surat')) {
        $pdo->prepare('UPDATE santri_izin_tetap SET nomor_surat = :n WHERE id = :id')->execute([
            'n' => $nomorSurat,
            'id' => $izinId,
        ]);
    }
}

$tglMulai = (string) ($izin['tanggal_mulai'] ?? '-');
$tglSelesai = trim((string) ($izin['tanggal_selesai'] ?? ''));
$periodeTampil = $tglSelesai !== '' ? ($tglMulai . ' s.d. ' . $tglSelesai) : ($tglMulai . ' (berlaku tanpa batas waktu)');
$judulKegiatan = santri_izin_tetap_surat_teks_bersih(trim((string) ($izin['judul'] ?? '')));
$suratKonteks = santri_izin_tetap_surat_konteks($jenisRaw, $judulKegiatan);
$kegiatanItems = $isGabungan
    ? santri_izin_tetap_kegiatan_items_for_print_gabungan($pdo, $anggotaRows)
    : santri_izin_tetap_kegiatan_items_for_print($pdo, $izin);
$kegiatanDitinggalkan = $kegiatanItems !== [] ? implode(', ', $kegiatanItems) : '';
$kegiatanKolomKiri = $kegiatanItems;
$kegiatanKolomKanan = [];
if (count($kegiatanItems) > 4) {
    $kegiatanKolomKiri = array_slice($kegiatanItems, 0, 4);
    $kegiatanKolomKanan = array_slice($kegiatanItems, 4);
}
$tampilkanKotakKegiatan = in_array($jenisRaw, ['HIDMAH', 'TUGAS'], true);
$kategoriHidmahKode = trim((string) ($izin['kategori_hidmah'] ?? ''));
$kategoriHidmahLabel = $kategoriHidmahKode !== '' ? izin_tetap_hidmah_kategori_label($pdo, $kategoriHidmahKode) : '';
$keterangan = trim((string) ($izin['keterangan'] ?? ''));

$namaKetua = pondok_ketua_yayasan_nama($pdo);
$kopIzinTetap = pondok_kop_data($pdo);
$namaPengasuh = (string) ($kopIzinTetap['nama_pengasuh'] ?? '');
$namaPenanggungJawab = trim((string) ($izin['penanggung_jawab'] ?? ''));
if ($namaPenanggungJawab === '') {
    $namaPenanggungJawab = trim((string) app_setting($pdo, 'nama_penanggung_jawab', ''));
}

$kotip = trim((string) app_setting($pdo, 'kota_ponpes', 'Muntilan'));
if ($kotip === '') {
    $kotip = 'Muntilan';
}

$tplVars = [
    'nama_ponpes' => (string) $namaPonpes,
    'kota_ponpes' => $kotip,
    'uraian_kalimat' => (string) ($suratKonteks['uraian_kalimat'] ?? ''),
    'jumlah_santri' => (string) count($anggotaRows),
];
$izinTetapJudul = surat_cetak_template_render($pdo, 'izin_tetap_judul', $tplVars);
$pembukaSlug = $isGabungan ? 'izin_tetap_gabungan_pembuka' : 'izin_tetap_pembuka';
$izinTetapPembuka = surat_cetak_template_render($pdo, $pembukaSlug, $tplVars);
$izinTetapCatatan = surat_cetak_template_render($pdo, 'izin_tetap_catatan', $tplVars);
$izinTetapPenutup = surat_cetak_template_render($pdo, 'izin_tetap_penutup', $tplVars);

$slotHtml = santri_izin_tetap_slot_hari_surat_html($pdo, $izinId);
$totalSantri = count($anggotaRows);
$density = santri_izin_tetap_surat_density_config($totalSantri, $isGabungan);
$suratRingkas = (bool) ($density['ringkas'] ?? false);
$tabelCols = (int) ($density['tabel_cols'] ?? 1);
$pageMargin = (string) ($density['page_margin'] ?? '12mm');
$sheetPadding = (string) ($density['sheet_padding'] ?? '12mm 14mm');
$logoPx = (int) ($density['logo_px'] ?? 72);
$signMm = (int) ($density['sign_mm'] ?? 18);
$sheetClass = trim('sheet ' . (string) ($density['class'] ?? ''));
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Surat Keterangan Izin Tetap</title>
    <style>
        @page { size: A4 portrait; margin: <?= htmlspecialchars($pageMargin) ?>; }
        * { box-sizing: border-box; }
        body {
            font-family: "Segoe UI", Arial, sans-serif;
            font-size: 11pt;
            color: #111827;
            margin: 0;
            padding: 10mm 0;
            background: #e2e8f0;
        }
        .sheet {
            position: relative;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            width: 210mm;
            max-width: 100%;
            min-height: calc(297mm - 24mm);
            margin: 0 auto;
            padding: <?= htmlspecialchars($sheetPadding) ?>;
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
            width: 280px;
            height: 280px;
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
            border-bottom: 2px solid var(--izin-accent, #0f766e);
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
        .logo { width: <?= $logoPx ?>px; height: <?= $logoPx ?>px; object-fit: cover; border-radius: 999px; border: 1px solid #d1d5db; }
        .brand { flex: 1; text-align: center; }
        .brand .small { margin: 0; font-size: 9.5pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.3px; }
        .brand h2 { margin: 0; font-size: 16pt; color: #065f46; font-weight: 800; text-transform: uppercase; line-height: 1.1; }
        .brand .addr { margin: 0; font-size: 8.5pt; font-style: italic; color: #334155; }
        .brand .contact { margin-top: 2px; font-size: 8pt; color: #475569; }
        .title { text-align: center; margin: 12px 0 10px; position: relative; z-index: 1; }
        .title strong { font-size: 12.5pt; text-decoration: underline; text-transform: uppercase; letter-spacing: 0.3px; }
        .title .doc-num { display: block; margin-top: 3px; font-size: 8.5pt; color: #475569; }
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
        }
        .badge.cat-hidmah { background: #0d9488; border: 1px solid #0f766e; }
        .badge.cat-tugas { background: #dc2626; border: 1px solid #b91c1c; }
        .content { line-height: 1.5; position: relative; z-index: 1; font-size: 10pt; flex: 1; }
        .content p { margin: 0 0 9px; text-align: justify; }
        .info { width: 100%; margin: 8px 0 10px; border-collapse: collapse; }
        .info-izin-tetap--compact td.info-pair {
            width: 50%;
            vertical-align: top;
            padding: 2px 8px 2px 0;
            line-height: 1.35;
            font-size: 9.5pt;
        }
        .info-izin-tetap--compact td.info-pair--empty {
            border: none;
        }
        .info-pair__label {
            color: #334155;
            font-weight: 700;
            white-space: nowrap;
        }
        .info-pair__sep {
            margin: 0 3px;
            color: #334155;
        }
        .info-pair__value {
            color: #0f172a;
        }
        .info-pair__value .hari-surat-row {
            display: inline-flex;
            flex-wrap: wrap;
            gap: 2px 14px;
            align-items: center;
            vertical-align: baseline;
        }
        .hari-surat-item {
            display: inline-block;
            min-width: 2.75rem;
            font-weight: 600;
            color: #0f172a;
        }
        table.santri { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 9.5pt; }
        table.santri th, table.santri td { border: 1px solid #cbd5e1; padding: 5px 8px; text-align: left; }
        table.santri th { background: #f1f5f9; font-size: 9pt; }
        table.santri td.num { width: 2.2rem; text-align: center; }
        table.santri td.nis { width: 5.5rem; font-family: Consolas, monospace; font-size: 9pt; }
        .santri-cols {
            display: flex;
            gap: 6px;
            align-items: flex-start;
            margin: 6px 0;
        }
        .santri-col { flex: 1 1 0; min-width: 0; }
        .santri-col table.santri { margin: 0; font-size: 7.8pt; }
        .santri-col table.santri th, .santri-col table.santri td { padding: 2px 4px; }
        .santri-col table.santri td.nis { width: 4rem; font-size: 7.5pt; }
        .sheet--compact .brand h2 { font-size: 14pt; }
        .sheet--compact .title { margin: 8px 0 6px; }
        .sheet--compact .title strong { font-size: 11.5pt; }
        .sheet--compact .content { font-size: 9.5pt; }
        .sheet--compact .content p { margin-bottom: 6px; }
        .sheet--compact table.santri { margin: 6px 0; font-size: 9pt; }
        .sheet--compact table.santri th, .sheet--compact table.santri td { padding: 3px 6px; }
        .sheet--compact .box-kegiatan, .sheet--compact .box-nb, .sheet--compact .box-note { margin-top: 4px; padding: 5px 7px; }
        .sheet--compact .sign-space { height: <?= max(10, $signMm - 4) ?>mm; min-height: 40px; }
        .sheet--dense .brand .small { font-size: 8.5pt; }
        .sheet--dense .brand h2 { font-size: 12.5pt; }
        .sheet--dense .brand .addr, .sheet--dense .brand .contact { font-size: 7.5pt; }
        .sheet--dense .header { padding-bottom: 5px; margin-bottom: 2px; }
        .sheet--dense .title { margin: 5px 0 4px; }
        .sheet--dense .title strong { font-size: 10.5pt; }
        .sheet--dense .title .doc-num { font-size: 7.5pt; margin-top: 1px; }
        .sheet--dense .badge { padding: 2px 8px; font-size: 7.2pt; margin-top: 2px; }
        .sheet--dense .content { font-size: 9pt; line-height: 1.35; }
        .sheet--dense .content p { margin-bottom: 4px; }
        .sheet--dense table.santri { margin: 4px 0; font-size: 8.5pt; }
        .sheet--dense table.santri th, .sheet--dense table.santri td { padding: 2px 5px; }
        .sheet--dense .info-izin-tetap--compact td.info-pair { font-size: 8.8pt; padding: 1px 6px 1px 0; }
        .sheet--dense .box-kegiatan ul { font-size: 8.5pt; }
        .sheet--dense .box-nb { font-size: 7.5pt; line-height: 1.3; }
        .sheet--dense .ttd-meta { margin-bottom: 6px; font-size: 7.5pt; }
        .sheet--dense .sign-space { height: <?= max(8, $signMm - 2) ?>mm; min-height: 36px; }
        .sheet--dense .ttd-row--single .sign-space { height: <?= $signMm ?>mm; min-height: 40px; }
        .sheet--extra-dense .brand h2 { font-size: 11pt; }
        .sheet--extra-dense .title strong { font-size: 10pt; }
        .sheet--extra-dense .content { font-size: 8.5pt; }
        .sheet--extra-dense .info-izin-tetap--compact td.info-pair { font-size: 8.2pt; }
        .sheet--extra-dense .sign-space { height: <?= $signMm ?>mm; min-height: 32px; }
        .sheet--extra-dense .print-time { display: none; }
        .box-note {
            margin-top: 6px;
            background: #f8fafc;
            border: 1px solid #dbeafe;
            border-radius: 8px;
            padding: 6px 8px;
        }
        .box-kegiatan {
            margin: 8px 0 10px;
            border: 1.5px solid #0d9488;
            border-radius: 8px;
            padding: 8px 10px;
            background: #f0fdfa;
        }
        .box-kegiatan strong {
            display: block;
            font-size: 9pt;
            color: #0f766e;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.2px;
        }
        .box-kegiatan ul {
            margin: 0;
            padding-left: 1.1rem;
            font-size: 9.5pt;
            line-height: 1.45;
        }
        .box-kegiatan__cols {
            display: flex;
            gap: 14px;
            align-items: flex-start;
        }
        .box-kegiatan__cols ul {
            flex: 1 1 0;
            min-width: 0;
        }
        .box-kegiatan li { margin: 1px 0; }
        .box-nb {
            margin-top: 6px;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 8px;
            padding: 6px 8px;
            font-size: 8pt;
            line-height: 1.35;
        }
        .ttd-wrap { margin-top: auto; position: relative; z-index: 1; }
        .ttd-meta { text-align: right; font-size: 8pt; color: #334155; margin-bottom: 10px; }
        .ttd-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 12px;
        }
        .ttd-row--duo { margin-bottom: 4px; }
        .ttd-row--single { justify-content: center; margin-top: 2px; }
        .ttd-row--single .box { flex: 0 1 42%; max-width: 240px; min-width: 140px; }
        .box { flex: 1 1 0; min-width: 0; text-align: center; padding: 0 2px; }
        .box .jab { font-size: 7.6pt; color: #475569; margin-bottom: 4px; min-height: 2.2em; }
        .sign-space { height: <?= $signMm ?>mm; min-height: 48px; }
        .ttd-row--single .sign-space { height: <?= min(20, $signMm + 2) ?>mm; min-height: 52px; }
        .line {
            margin: 0 auto;
            width: 92%;
            border-top: 1px solid #0f172a;
            font-weight: 700;
            padding-top: 6px;
            font-size: 8pt;
            word-break: break-word;
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
            body { background: #fff; padding: 0; margin: 0; }
            .sheet {
                border: none;
                box-shadow: none;
                border-radius: 0;
                width: auto;
                max-width: none;
                margin: 0;
                min-height: auto;
                height: auto;
                page-break-inside: avoid;
                break-inside: avoid-page;
            }
            .sheet--compact, .sheet--dense, .sheet--extra-dense {
                page-break-after: avoid;
            }
            .badge {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .box-kegiatan {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            table.santri th {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
        }
    </style>
</head>
<body onload="window.print()" style="--izin-accent: <?= htmlspecialchars($headerColor) ?>;">
    <div class="<?= htmlspecialchars($sheetClass) ?>">
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
            <strong><?= htmlspecialchars($izinTetapJudul) ?></strong>
            <div class="doc-num">Nomor: <?= htmlspecialchars($nomorSurat) ?></div>
            <span class="badge <?= htmlspecialchars($categoryClass) ?>">Jenis: <?= htmlspecialchars(strtoupper($jenisLabel)) ?><?= $isGabungan ? ' · ' . $totalSantri . ' santri' : '' ?></span>
        </div>

        <div class="content">
            <p><?= htmlspecialchars($izinTetapPembuka) ?></p>
            <?php if ($isGabungan): ?>
                <?php require __DIR__ . '/partials/surat_izin_tetap_tabel_santri.php'; ?>
            <?php endif; ?>
            <?php require __DIR__ . '/partials/surat_izin_tetap_info.php'; ?>
            <?php if ($tampilkanKotakKegiatan && !$suratRingkas): ?>
            <div class="box-kegiatan">
                <strong><?= htmlspecialchars((string) $suratKonteks['label_kegiatan_box']) ?></strong>
                <?php if ($kegiatanItems !== []): ?>
                <?php if ($kegiatanKolomKanan !== []): ?>
                <div class="box-kegiatan__cols">
                    <ul>
                        <?php foreach ($kegiatanKolomKiri as $kgNama): ?>
                            <li><?= htmlspecialchars($kgNama) ?></li>
                        <?php endforeach; ?>
                    </ul>
                    <ul>
                        <?php foreach ($kegiatanKolomKanan as $kgNama): ?>
                            <li><?= htmlspecialchars($kgNama) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php else: ?>
                <ul>
                    <?php foreach ($kegiatanKolomKiri as $kgNama): ?>
                        <li><?= htmlspecialchars($kgNama) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
                <?php else: ?>
                <p class="mb-0 small text-muted">Tidak ada kegiatan tercatat.</p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php if ($keterangan !== '' && !$suratRingkas): ?>
            <div class="box-note">
                <strong>Keterangan:</strong><br>
                <?= nl2br(htmlspecialchars($keterangan)) ?>
            </div>
            <?php endif; ?>
            <div class="box-nb">
                <strong>Catatan:</strong>
                <?= $suratRingkas ? ' ' : '<br>' ?>
                <?= htmlspecialchars($izinTetapCatatan) ?>
                <?php if (!$suratRingkas): ?>
                <?php if ($kegiatanDitinggalkan !== '' && !$suratKonteks['is_tugas']): ?>
                Ketidakhadiran pada kegiatan Jama'ah yang disebutkan dicatat izin, bukan alpa.
                <?php elseif ($kegiatanDitinggalkan !== '' && $suratKonteks['is_tugas']): ?>
                Ketidakhadiran pada kegiatan terkait yang disebutkan dicatat izin sesuai ketentuan pondok.
                <?php endif; ?>
                Izin tetap berlaku selama status aktif dan dapat ditinjau ulang oleh pengurus bila diperlukan.
                <?php endif; ?>
            </div>
            <?php if (!$suratRingkas): ?>
            <p><?= htmlspecialchars($izinTetapPenutup) ?></p>
            <?php endif; ?>
        </div>

        <div class="ttd-wrap">
            <div class="ttd-meta"><?= htmlspecialchars($kotip) ?>, <?= htmlspecialchars(date('d-m-Y')) ?></div>
            <div class="ttd-row ttd-row--duo">
                <div class="box">
                    <div class="jab">Ketua Yayasan,</div>
                    <div class="sign-space"></div>
                    <div class="line"><?= htmlspecialchars($namaKetua !== '' ? $namaKetua : '(_______________________)') ?></div>
                </div>
                <div class="box">
                    <div class="jab">Koordinator,</div>
                    <div class="sign-space"></div>
                    <div class="line"><?= htmlspecialchars($namaPenanggungJawab !== '' ? $namaPenanggungJawab : '(_______________________)') ?></div>
                </div>
            </div>
            <div class="ttd-row ttd-row--single">
                <div class="box">
                    <div class="jab">Mengetahui,<br>Pengasuh,</div>
                    <div class="sign-space"></div>
                    <div class="line"><?= htmlspecialchars($namaPengasuh !== '' ? $namaPengasuh : '(_______________________)') ?></div>
                </div>
            </div>
            <div class="print-time">Waktu cetak: <?= htmlspecialchars($jamTerbit) ?></div>
        </div>
    </div>
</body>
</html>
