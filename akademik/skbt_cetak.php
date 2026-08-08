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
$namaKetuaYayasan = trim((string) ($kop['nama_ketua_yayasan'] ?? ''));
$waliKelas = trim((string) ($santri['wali_kelas'] ?? ''));
$waliKamar = trim((string) ($santri['nama_kamar'] ?? ''));

$periodeLabel = (string) ($laporan['periode']['label'] ?? '');
if (!empty($laporan['periode']['rentang_tampilan'])) {
    $periodeLabel .= ' · ' . (string) $laporan['periode']['rentang_tampilan'];
}


$nilaiBadgeClass = static function (string $kode): string {
    return match (strtoupper(trim($kode))) {
        'BAIK' => 'skbt-nilai-baik',
        'SEDANG' => 'skbt-nilai-sedang',
        default => 'skbt-nilai-buruk',
    };
};

$renderPresensiTable = static function (array $items) use ($nilaiBadgeClass): void {
    if ($items === []) {
        echo '<p class="skbt-section-placeholder">—</p>';
        return;
    }
    $rendered = false;
    foreach ($items as $kg) {
        $bulanAktif = $kg['bulan_aktif'] ?? [];
        if ($bulanAktif === []) {
            continue;
        }
        $rendered = true;
        $rowCount = count($bulanAktif);
        $tableClass = 'skbt-presensi-table skbt-presensi-table--compact skbt-data-table';
        if ($rowCount <= 3) {
            $tableClass .= ' skbt-data-table--fit';
        }
        $nilaiKeg = (string) ($kg['nilai_keseluruhan'] ?? 'BAIK');
        echo '<div class="skbt-keg-block skbt-keg-block--compact">';
        echo '<div class="skbt-keg-head"><strong>' . htmlspecialchars((string) ($kg['nama_kegiatan'] ?? '')) . '</strong>';
        echo ' <span class="skbt-keg-meta">Aktiv ' . (int) ($kg['jml_hari_aktiv'] ?? 0);
        echo ' · Persen ' . htmlspecialchars(skbt_format_persen_tampilan(isset($kg['persen']) ? (float) $kg['persen'] : null));
        echo ' · <span class="skbt-nilai-badge ' . $nilaiBadgeClass($nilaiKeg) . '">' . htmlspecialchars($nilaiKeg) . '</span></span></div>';
        echo '<div class="skbt-table-wrap"><table class="' . $tableClass . '"><colgroup>';
        echo '<col class="col-label"><col class="col-aktiv"><col class="col-hisg"><col class="col-hisg"><col class="col-hisg"><col class="col-hisg"><col class="col-persen"><col class="col-krit">';
        echo '</colgroup><thead><tr>';
        echo '<th>Bulan</th><th class="col-aktiv">Jml Hari Aktiv</th><th>H</th><th>I</th><th>S</th><th>G</th><th class="col-persen">Persen</th><th>Kriteria</th>';
        echo '</tr></thead><tbody>';
        foreach ($bulanAktif as $bm) {
            $n = (string) ($bm['nilai'] ?? 'BAIK');
            echo '<tr>';
            echo '<td>' . htmlspecialchars((string) ($bm['label'] ?? '')) . '</td>';
            echo '<td class="num">' . (int) ($bm['jml_hari_aktiv'] ?? $bm['total'] ?? 0) . '</td>';
            echo '<td class="num">' . (int) ($bm['hadir'] ?? 0) . '</td>';
            echo '<td class="num">' . (int) ($bm['izin'] ?? 0) . '</td>';
            echo '<td class="num">' . (int) ($bm['sakit'] ?? 0) . '</td>';
            echo '<td class="num">' . (int) ($bm['ghoib'] ?? 0) . '</td>';
            echo '<td class="num">' . htmlspecialchars(skbt_format_persen_tampilan(isset($bm['persen']) ? (float) $bm['persen'] : null)) . '</td>';
            echo '<td><span class="skbt-nilai-badge ' . $nilaiBadgeClass($n) . '">' . htmlspecialchars($n) . '</span></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }
    if (!$rendered) {
        echo '<p class="skbt-section-placeholder">—</p>';
    }
};

$skbtCountBulanRows = static function (array $items): int {
    $n = 0;
    foreach ($items as $kg) {
        $n += count($kg['bulan_aktif'] ?? []);
    }

    return $n;
};

$bulanDisiplin = $skbtCountBulanRows($laporan['disiplin_kelas'] ?? []);
$bulanJamaah = $skbtCountBulanRows($laporan['presensi_jamaah'] ?? []);
$maxBulanRows = max($bulanDisiplin, $bulanJamaah);
$gridClass = 'skbt-grid-2';
if ($bulanDisiplin === 0 || $bulanJamaah === 0 || $maxBulanRows <= 3) {
    $gridClass .= ' skbt-grid-2--stack';
}

$ringkas = $laporan['ringkasan_penilaian'] ?? [];
$totNilai = (array) ($ringkas['total'] ?? []);
$ringkasRowCount = $totNilai !== [] ? 1 : 0;
foreach (['TAALIM', 'JAMAAH'] as $kat) {
    if (!empty($ringkas['per_kategori'][$kat])) {
        $ringkasRowCount++;
    }
}
$ringkasTableClass = 'skbt-ringkasan-table skbt-ringkasan-table--compact skbt-data-table';
if ($ringkasRowCount <= 3) {
    $ringkasTableClass .= ' skbt-data-table--fit';
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

        <div class="skbt-jatidiri skbt-jatidiri--compact">
            <span><strong>NIS</strong> <?= htmlspecialchars((string) ($santri['nis'] ?? '-')) ?></span>
            <span><strong>Nama</strong> <?= htmlspecialchars((string) ($santri['nama_santri'] ?? '-')) ?></span>
            <span><strong>Bin</strong> <?= htmlspecialchars((string) ($santri['nama_ayah'] ?? '-')) ?></span>
            <span><strong>Tingkatan</strong> <?= htmlspecialchars((string) ($santri['tingkatan'] ?? '-')) ?></span>
            <span><strong>Tahun ke</strong> <?= (int) ($santri['tahun_ke'] ?? 0) ?: '—' ?></span>
        </div>

        <?php if ($totNilai !== []): ?>
        <h2 class="skbt-section-title skbt-section-title--compact">Ringkasan Keaktifan</h2>
        <?php if (!empty($laporan['penilaian']['legend'])): ?>
            <p class="skbt-penilaian-legend skbt-penilaian-legend--compact"><?= htmlspecialchars((string) $laporan['penilaian']['legend']) ?></p>
        <?php endif; ?>
        <div class="skbt-table-wrap">
        <table class="<?= htmlspecialchars($ringkasTableClass) ?>">
            <colgroup>
                <col class="col-label">
                <col class="col-aktiv">
                <col class="col-hisg"><col class="col-hisg"><col class="col-hisg"><col class="col-hisg">
                <col class="col-persen">
                <col class="col-krit">
            </colgroup>
            <thead>
                <tr>
                    <th>Ringkasan</th>
                    <th class="col-aktiv">Jml Hari Aktiv</th>
                    <th>H</th><th>I</th><th>S</th><th>G</th>
                    <th class="col-persen">Persen</th>
                    <th>Kriteria</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $rowsRingkas = [['label' => 'Seluruh kegiatan', 'data' => $totNilai]];
                foreach (['TAALIM' => "Ta'lim", 'JAMAAH' => "Jama'ah"] as $kat => $lbl) {
                    if (!empty($ringkas['per_kategori'][$kat])) {
                        $rowsRingkas[] = ['label' => $lbl, 'data' => $ringkas['per_kategori'][$kat]];
                    }
                }
                foreach ($rowsRingkas as $rr):
                    $d = (array) $rr['data'];
                    $krit = (string) ($d['nilai'] ?? 'BAIK');
                    ?>
                <tr>
                    <td><?= htmlspecialchars((string) $rr['label']) ?></td>
                    <td class="num"><?= (int) ($d['jml_hari_aktiv'] ?? $d['kuota'] ?? 0) ?></td>
                    <td class="num"><?= (int) ($d['hadir'] ?? 0) ?></td>
                    <td class="num"><?= (int) ($d['izin'] ?? 0) ?></td>
                    <td class="num"><?= (int) ($d['sakit'] ?? 0) ?></td>
                    <td class="num"><?= (int) ($d['ghoib'] ?? 0) ?></td>
                    <td class="num"><?= htmlspecialchars(skbt_format_persen_tampilan(isset($d['persen']) ? (float) $d['persen'] : null)) ?></td>
                    <td><span class="skbt-nilai-badge <?= $nilaiBadgeClass($krit) ?>"><?= htmlspecialchars($krit) ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

        <div class="<?= htmlspecialchars($gridClass) ?>">
            <div>
                <h2 class="skbt-section-title skbt-section-title--compact">Disiplin Kelas</h2>
                <?php $renderPresensiTable($laporan['disiplin_kelas']); ?>
            </div>
            <div>
                <h2 class="skbt-section-title skbt-section-title--compact">Jama'ah</h2>
                <?php $renderPresensiTable($laporan['presensi_jamaah']); ?>
            </div>
        </div>

        <?php $akFlat = $akademikNilai['flat'] ?? []; ?>
        <?php if ($akFlat !== []): ?>
        <h2 class="skbt-section-title skbt-section-title--compact">Nilai Ikhtibar &amp; Manual</h2>
        <?php if (($akademikNilai['rata_nilai'] ?? null) !== null): ?>
            <p class="skbt-penilaian-legend skbt-penilaian-legend--compact">
                <?= (int) ($akademikNilai['ikhtibar_jumlah'] ?? 0) ?> ikhtibar
                · <?= (int) ($akademikNilai['manual_jumlah'] ?? 0) ?> manual
                · rata <?= htmlspecialchars((string) $akademikNilai['rata_nilai']) ?>
            </p>
        <?php endif; ?>
        <div class="skbt-table-wrap">
        <table class="skbt-ikhtibar-table skbt-ikhtibar-table--compact skbt-akademik-table<?= count($akFlat) <= 4 ? ' skbt-data-table--fit' : '' ?>">
            <colgroup>
                <col class="col-sumber"><col class="col-label"><col><col class="col-krit">
            </colgroup>
            <thead>
                <tr><th>Sumber</th><th>Mapel / Tugas</th><th>Nilai</th><th>Predikat</th></tr>
            </thead>
            <tbody>
            <?php foreach ($akFlat as $ak): ?>
                <?php
                $label = trim((string) ($ak['mapel_label'] ?? ''));
                $judul = trim((string) ($ak['judul'] ?? ''));
                $tampil = $label;
                if ($judul !== '' && ($label === '' || !str_contains($label, $judul))) {
                    $tampil = $label !== '' ? $label . ' — ' . $judul : $judul;
                }
                ?>
                <tr>
                    <td><?= htmlspecialchars((string) ($ak['sumber'] ?? '')) ?></td>
                    <td><?= htmlspecialchars($tampil !== '' ? $tampil : '—') ?></td>
                    <td class="num"><?= $ak['nilai_total'] !== null ? htmlspecialchars((string) $ak['nilai_total']) : '—' ?></td>
                    <td><?= htmlspecialchars((string) ($ak['predikat'] ?? '—')) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>

        <div class="skbt-ttd-grid skbt-ttd-grid--compact">
            <div class="skbt-ttd-box"><div class="role">Wali Kamar</div><div class="name"><?= htmlspecialchars($waliKamar !== '' ? $waliKamar : '…………') ?></div></div>
            <div class="skbt-ttd-box"><div class="role">Wali Kelas</div><div class="name"><?= htmlspecialchars($waliKelas !== '' ? $waliKelas : '…………') ?></div></div>
            <div class="skbt-ttd-box"><div class="role">Pengasuh</div><div class="name"><?= htmlspecialchars($namaPengasuh !== '' ? $namaPengasuh : '…………') ?></div></div>
            <div class="skbt-ttd-box"><div class="role">Ketua Yayasan</div><div class="name"><?= htmlspecialchars($namaKetuaYayasan !== '' ? $namaKetuaYayasan : '…………') ?></div></div>
        </div>
    </div>
</div>

<?php if ($autoPrint): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });</script>
<?php endif; ?>
</body>
</html>
