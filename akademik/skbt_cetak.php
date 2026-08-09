<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/datetime_display.php';
require_once __DIR__ . '/../helpers/akademik_skbt.php';
require_once __DIR__ . '/../helpers/pondok_cetak.php';
require_once __DIR__ . '/../helpers/akademik.php';

require_roles(['admin', 'pengurus', 'kiai']);

$santriId = (int) ($_GET['santri_id'] ?? 0);
$periodeResolved = skbt_resolve_periode($pdo, $_GET);
$tahunSyawal = (int) ($periodeResolved['tahun_syawal'] ?? skbt_tahun_syawal_default($pdo));
$periodeKe = max(1, (int) ($_GET['periode_ke'] ?? 1));
$preview = isset($_GET['preview']);

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
$manualNilai = $laporan['manual_nilai'] ?? ['flat' => [], 'jumlah' => 0];
$akademikNilai = $laporan['akademik_nilai'] ?? skbt_akademik_nilai_gabung($ikhtibarNilai, $manualNilai);
$kop = pondok_kop_data($pdo);
$nomor = skbt_nomor_surat($santriId, $tahunSyawal, $periodeKe);

$logoHref = skbt_logo_abs_url($pdo, $kop);
$namaPengasuh = trim((string) ($kop['nama_pengasuh'] ?? ''));
$namaKepalaPondok = trim((string) app_setting($pdo, 'nama_kepala_pondok', ''));
if ($namaKepalaPondok === '') {
    $namaKepalaPondok = trim((string) ($kop['nama_ketua_yayasan'] ?? ''));
}
$waliKamar = trim((string) ($santri['nama_kamar'] ?? ''));

$periodeLabel = (string) ($laporan['periode']['label'] ?? '');
if (!empty($laporan['periode']['rentang_tampilan'])) {
    $periodeLabel .= ' · ' . (string) $laporan['periode']['rentang_tampilan'];
}

$autoPrint = !$preview;
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SKBT — <?= htmlspecialchars((string) ($santri['nama_santri'] ?? '')) ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars(app_asset_href('/assets/css/skbt-cetak.css')) ?>">
    <style>
        body.skbt-body { --skbt-watermark: url("<?= htmlspecialchars($logoHref) ?>"); }
    </style>
</head>
<body class="skbt-body skbt-body--f4">
<div class="no-print">
    <a href="<?= htmlspecialchars(app_href('/akademik/skbt.php?' . http_build_query(skbt_periode_query_params($pdo, $periodeResolved, ['santri_id' => $santriId])))) ?>">← Kembali</a>
    <button type="button" onclick="window.print()">Cetak F4</button>
</div>

<div class="skbt-sheet skbt-sheet--f4">
    <div class="skbt-inner">
        <?= skbt_kop_surat_html($pdo, $kop, $nomor, $periodeLabel) ?>

        <div class="skbt-jatidiri">
            <?= skbt_jatidiri_cetak_html($santri) ?>
        </div>

        <section class="skbt-prose-section">
            <h2 class="skbt-section-title">Disiplin Kelas</h2>
            <?= skbt_presensi_prose_html($laporan['disiplin_kelas'] ?? [], $periodeLabel) ?>
        </section>

        <section class="skbt-prose-section">
            <h2 class="skbt-section-title">Presensi Kegiatan</h2>
            <?= skbt_presensi_prose_html($laporan['presensi_jamaah'] ?? [], $periodeLabel) ?>
        </section>

        <?php $akFlat = $akademikNilai['flat'] ?? []; ?>
        <?php if ($akFlat !== []): ?>
        <section class="skbt-prose-section">
            <h2 class="skbt-section-title">Nilai Ikhtibar &amp; Manual</h2>
            <?php if (($akademikNilai['rata_nilai'] ?? null) !== null): ?>
                <p class="skbt-akademik-summary">
                    <?= (int) ($akademikNilai['ikhtibar_jumlah'] ?? 0) ?> ikhtibar
                    · <?= (int) ($akademikNilai['manual_jumlah'] ?? 0) ?> manual
                    · rata <?= htmlspecialchars((string) $akademikNilai['rata_nilai']) ?>
                </p>
            <?php endif; ?>
            <?php foreach ($akFlat as $ak): ?>
                <?php
                $label = trim((string) ($ak['mapel_label'] ?? ''));
                $judul = trim((string) ($ak['judul'] ?? ''));
                $tampil = $label;
                if ($judul !== '' && ($label === '' || !str_contains($label, $judul))) {
                    $tampil = $label !== '' ? $label . ' — ' . $judul : $judul;
                }
                if ($tampil === '') {
                    $tampil = '—';
                }
                $nilaiTampil = $ak['nilai_total'] !== null ? (string) $ak['nilai_total'] : '—';
                $predikat = (string) ($ak['predikat'] ?? '—');
                $sumber = strtoupper(trim((string) ($ak['sumber'] ?? '')));
                ?>
                <div class="skbt-prose-entry">
                    <p class="skbt-prose-title">
                        <strong><?= htmlspecialchars($tampil) ?></strong>
                        <span class="skbt-prose-sep"> — </span>
                        <em class="skbt-prose-value"><?= htmlspecialchars($sumber) ?> · <?= htmlspecialchars($nilaiTampil) ?><?= $predikat !== '—' ? ' · ' . htmlspecialchars($predikat) : '' ?></em>
                    </p>
                </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <?= skbt_ttd_cetak_html($waliKamar, $namaPengasuh, $namaKepalaPondok) ?>
    </div>
</div>

<?php if ($autoPrint): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
<?php endif; ?>
</body>
</html>
