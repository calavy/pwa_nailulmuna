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

require_login();
require_roles(['admin', 'pengurus', 'petugas_absensi']);

$id = (int) ($_GET['id'] ?? 0);
$izin = santri_izin_tetap_for_print($pdo, $id);

if (!$izin) {
    exit('Data izin tetap tidak ditemukan.');
}

if ((int) ($izin['is_aktif'] ?? 0) !== 1) {
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
if ($nomorSurat === '') {
    $nomorSurat = surat_nomor_ambil_atau_buat($pdo, 'izin_tetap', 'izin_tetap:' . $id);
    if (column_exists($pdo, 'santri_izin_tetap', 'nomor_surat')) {
        $pdo->prepare('UPDATE santri_izin_tetap SET nomor_surat = :n WHERE id = :id')->execute([
            'n' => $nomorSurat,
            'id' => $id,
        ]);
    }
}

$tglMulai = (string) ($izin['tanggal_mulai'] ?? '-');
$tglSelesai = trim((string) ($izin['tanggal_selesai'] ?? ''));
$periodeTampil = $tglSelesai !== '' ? ($tglMulai . ' s.d. ' . $tglSelesai) : ($tglMulai . ' (berlaku tanpa batas waktu)');
$judulKegiatan = santri_izin_tetap_surat_teks_bersih(trim((string) ($izin['judul'] ?? '')));
$suratKonteks = santri_izin_tetap_surat_konteks($jenisRaw, $judulKegiatan);
$kegiatanItems = santri_izin_tetap_kegiatan_items_for_print($pdo, $izin);
$kegiatanDitinggalkan = $kegiatanItems !== [] ? implode(', ', $kegiatanItems) : '';
$kegiatanKolomKiri = $kegiatanItems;
$kegiatanKolomKanan = [];
if (count($kegiatanItems) > 4) {
    $kegiatanKolomKiri = array_slice($kegiatanItems, 0, 4);
    $kegiatanKolomKanan = array_slice($kegiatanItems, 4);
}
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

$slotHtml = santri_izin_tetap_slot_hari_html($pdo, $id);
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Surat Keterangan Izin Tetap</title>
    <style>
        @page { size: A4 portrait; margin: 12mm; }
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
            padding: 12mm 14mm;
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
        .logo { width: 72px; height: 72px; object-fit: cover; border-radius: 999px; border: 1px solid #d1d5db; }
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
        .info td { vertical-align: top; padding: 2px 0; }
        .info td:first-child { width: 130px; color: #334155; font-weight: 700; }
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
        .ttd {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 8px;
            flex-wrap: wrap;
        }
        .box { flex: 1 1 30%; min-width: 0; text-align: center; padding: 0 2px; }
        .box .jab { font-size: 7.6pt; color: #475569; margin-bottom: 4px; min-height: 2.4em; }
        .sign-space { height: 22mm; min-height: 56px; }
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
            body { background: #fff; padding: 0; }
            .sheet { border: none; box-shadow: none; border-radius: 0; width: auto; max-width: none; margin: 0; }
            .badge {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .box-kegiatan {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
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
            <strong>SURAT KETERANGAN IZIN TETAP SANTRI</strong>
            <div class="doc-num">Nomor: <?= htmlspecialchars($nomorSurat) ?></div>
            <span class="badge <?= htmlspecialchars($categoryClass) ?>">Jenis: <?= htmlspecialchars(strtoupper($jenisLabel)) ?></span>
        </div>

        <div class="content">
            <p>
                Yang bertanda tangan di bawah ini, Pengurus <?= htmlspecialchars($namaPonpes) ?> menerangkan bahwa
                santri berikut memperoleh <strong>izin tetap</strong> untuk melaksanakan
                <strong><?= htmlspecialchars((string) $suratKonteks['uraian_kalimat']) ?></strong>
                pada hari dan waktu yang tercantum.
            </p>
            <table class="info">
                <tr><td>Nama Santri</td><td>: <?= htmlspecialchars((string) ($izin['nama_santri'] ?? '-')) ?></td></tr>
                <tr><td>NIS</td><td>: <?= htmlspecialchars((string) ($izin['nis'] ?? '-')) ?></td></tr>
                <tr><td>Tingkatan</td><td>: <?= htmlspecialchars((string) ($izin['tingkatan'] ?? '-')) ?></td></tr>
                <tr><td>Jenis Izin</td><td>: <?= htmlspecialchars((string) $suratKonteks['jenis_label']) ?></td></tr>
                <?php if (!$suratKonteks['is_tugas'] && $kategoriHidmahLabel !== ''): ?>
                <tr><td>Kategori Hidmah</td><td>: <?= htmlspecialchars($kategoriHidmahLabel) ?></td></tr>
                <?php endif; ?>
                <tr><td><?= htmlspecialchars((string) $suratKonteks['label_uraian']) ?></td><td>: <?= htmlspecialchars((string) ($suratKonteks['detail_teks'] ?? '') !== '' ? (string) $suratKonteks['detail_teks'] : '—') ?></td></tr>
                <tr><td>Masa Berlaku</td><td>: <?= htmlspecialchars($periodeTampil) ?></td></tr>
                <tr><td><?= htmlspecialchars((string) $suratKonteks['label_jadwal']) ?></td><td>: <?= $slotHtml ?></td></tr>
            </table>
            <?php if ($kegiatanItems !== []): ?>
            <div class="box-kegiatan">
                <strong><?= htmlspecialchars((string) $suratKonteks['label_kegiatan_box']) ?></strong>
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
            </div>
            <?php endif; ?>
            <?php if ($keterangan !== ''): ?>
            <div class="box-note">
                <strong>Keterangan:</strong><br>
                <?= nl2br(htmlspecialchars($keterangan)) ?>
            </div>
            <?php endif; ?>
            <div class="box-nb">
                <strong>Catatan:</strong><br>
                Santri wajib mematuhi tata tertib pondok dan menjaga nama baik lembaga.
                <?php if ($kegiatanDitinggalkan !== '' && !$suratKonteks['is_tugas']): ?>
                Ketidakhadiran pada kegiatan Jama'ah yang disebutkan dicatat <strong>izin</strong>, bukan alpa.
                <?php elseif ($kegiatanDitinggalkan !== '' && $suratKonteks['is_tugas']): ?>
                Ketidakhadiran pada kegiatan terkait yang disebutkan dicatat <strong>izin</strong> sesuai ketentuan pondok.
                <?php endif; ?>
                Izin tetap berlaku selama status aktif dan dapat ditinjau ulang oleh pengurus bila diperlukan.
            </div>
            <p>
                Demikian surat keterangan ini dibuat dengan sebenarnya untuk dipergunakan sebagaimana mestinya.
            </p>
        </div>

        <div class="ttd-wrap">
            <div class="ttd-meta"><?= htmlspecialchars($kotip) ?>, <?= htmlspecialchars(date('d-m-Y')) ?></div>
            <div class="ttd">
                <div class="box">
                    <div class="jab">Ketua Yayasan,</div>
                    <div class="sign-space"></div>
                    <div class="line"><?= htmlspecialchars($namaKetua !== '' ? $namaKetua : '(_______________________)') ?></div>
                </div>
                <div class="box">
                    <div class="jab">Penanggung Jawab,</div>
                    <div class="sign-space"></div>
                    <div class="line"><?= htmlspecialchars($namaPenanggungJawab !== '' ? $namaPenanggungJawab : '(_______________________)') ?></div>
                </div>
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
