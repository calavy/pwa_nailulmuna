<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/datetime_display.php';
require_once __DIR__ . '/../helpers/akademik_skbt.php';
require_once __DIR__ . '/../helpers/skbt_settings.php';
require_once __DIR__ . '/../helpers/pondok_cetak.php';
require_once __DIR__ . '/../helpers/akademik.php';

require_roles(['admin', 'pengurus', 'kiai']);

$santriId = (int) ($_GET['santri_id'] ?? 0);
$periodeResolved = skbt_resolve_periode($pdo, $_GET);
$tahunSyawal = (int) ($periodeResolved['tahun_syawal'] ?? skbt_tahun_syawal_default($pdo));
$preview = isset($_GET['preview']);
$embed = isset($_GET['embed']);

if ($santriId <= 0) {
    exit('Pilih santri terlebih dahulu.');
}

$santri = skbt_santri_profil($pdo, $santriId, true);
if (!$santri) {
    exit('Data santri tidak ditemukan.');
}

$forceRefresh = isset($_GET['refresh']);
$laporan = skbt_build_laporan_cached($pdo, $santriId, $tahunSyawal, $forceRefresh, $periodeResolved);
$ikhtibarNilai = $laporan['ikhtibar_nilai'] ?? ['flat' => [], 'groups' => []];
$meta = skbt_parse_cetak_meta($_GET, $laporan['periode'] ?? $periodeResolved);
$periodeKe = (int) $meta['periode_ke'];
$taMasehiLabel = (string) ($meta['ta_masehi_label'] ?? '');

$kop = pondok_kop_data($pdo);
$nomor = skbt_nomor_surat($pdo, $santriId, $tahunSyawal, $periodeKe, $taMasehiLabel !== '' ? $taMasehiLabel : null);
$accent = trim((string) ($kop['kop_accent_color'] ?? '#38a169')) ?: '#38a169';

$logoHref = skbt_logo_abs_url($pdo, $kop);
$ttd = skbt_ttd_resolve($pdo, $santri, $kop);
$waliKamar = $ttd['wali_kamar'];
$waliKelas = $ttd['wali_kelas'];
$namaPengasuh = $ttd['pengasuh'];
$namaKepalaPondok = $ttd['kepala_pondok'];

$disiplinKelas = $laporan['disiplin_kelas'] ?? [];
$presensiKegiatan = array_merge($laporan['presensi_jamaah'] ?? [], $laporan['lainnya'] ?? []);

$backQs = skbt_periode_query_params($pdo, $periodeResolved, ['santri_id' => $santriId]);
$autoPrint = !$preview && !$embed;
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKBT — <?= htmlspecialchars((string) ($santri['nama_santri'] ?? '')) ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_asset_href('/assets/css/skbt-cetak.css')) ?>">
    <style>
        body.skbt-body {
            --skbt-watermark: url("<?= htmlspecialchars($logoHref) ?>");
            --skbt-accent: <?= htmlspecialchars($accent, ENT_QUOTES) ?>;
        }
    </style>
</head>
<body class="skbt-body skbt-body--f4">
<?php if (!$embed): ?>
<div class="no-print">
    <a href="<?= htmlspecialchars(app_href('/akademik/skbt.php?' . http_build_query($backQs))) ?>">← Kembali</a>
    <button type="button" onclick="window.print()">Cetak F4</button>
</div>
<?php endif; ?>

<div class="skbt-sheet skbt-sheet--f4">
    <div class="skbt-inner">
        <?= skbt_kop_surat_html($pdo, $kop) ?>
        <?= skbt_nomor_surat_html($nomor) ?>

        <div class="section-title">JATIDIRI</div>
        <div class="skbt-jatidiri">
            <?= skbt_jatidiri_cetak_html($santri) ?>
        </div>

        <section class="skbt-section">
            <div class="section-title">Disiplin Kelas</div>
            <?= skbt_presensi_prose_html($disiplinKelas) ?>
        </section>

        <section class="skbt-section">
            <div class="section-title">Nilai Kelas</div>
            <?= skbt_nilai_kelas_html($pdo, $santriId, $laporan['periode'] ?? $periodeResolved, $disiplinKelas) ?>
        </section>

        <section class="skbt-section">
            <div class="section-title">Nilai Ikhtibar</div>
            <?= skbt_nilai_ikhtibar_ringkas_html($ikhtibarNilai) ?>
        </section>

        <section class="skbt-section">
            <div class="section-title">Presensi Kegiatan</div>
            <?= skbt_presensi_prose_html($presensiKegiatan) ?>
        </section>

        <section class="skbt-section">
            <div class="section-title">Catatan Pendidikan</div>
            <?= skbt_catatan_pendidikan_html($meta['catatan']) ?>
        </section>

        <?= skbt_narasi_kelangsungan_html($pdo, array_merge($meta, [
            'nomor' => $nomor,
            'nama_ponpes' => trim((string) ($kop['nama_ponpes'] ?? 'API Nailul Muna')),
        ])) ?>

        <?= skbt_ttd_cetak_html($waliKamar, $waliKelas, $namaPengasuh, $namaKepalaPondok) ?>
    </div>
</div>

<?php if ($autoPrint): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
<?php endif; ?>
</body>
</html>
