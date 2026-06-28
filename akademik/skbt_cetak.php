<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/datetime_display.php';
require_once __DIR__ . '/../helpers/akademik_skbt.php';
require_once __DIR__ . '/../helpers/pondok_cetak.php';
require_once __DIR__ . '/../helpers/surat_cetak_templates.php';
require_once __DIR__ . '/../helpers/akademik.php';

require_roles(['admin', 'pengurus', 'kiai']);

$santriId = (int) ($_GET['santri_id'] ?? 0);
$tahunSyawal = (int) ($_GET['tahun_syawal'] ?? skbt_tahun_syawal_default($pdo));
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
$laporan = skbt_build_laporan_cached($pdo, $santriId, $tahunSyawal, $forceRefresh);
$kop = pondok_kop_data($pdo);
$nomor = skbt_nomor_surat($santriId, $tahunSyawal, $periodeKe);

$namaPonpes = trim((string) ($kop['nama_ponpes'] ?? 'Pondok Pesantren'));
$jenisPendidikan = trim((string) ($kop['jenis_pendidikan'] ?? ''));
$alamat = trim((string) ($kop['alamat_ponpes'] ?? ''));
$kontak = pondok_kop_contact_line($kop);
$logoHref = (string) ($kop['logo_href'] ?? '');

$tglCetak = date('Y-m-d');
$hijriCetak = konversiKeHijriah($pdo, $tglCetak);
$hijriTglLabel = is_array($hijriCetak)
    ? app_format_tanggal_id($tglCetak) . ' / ' . hijri_indeks_ke_nama((int) ($hijriCetak['bulan_hijriyah'] ?? 1)) . ' ' . (int) ($hijriCetak['tahun_hijriah'] ?? '')
    : app_format_tanggal_id($tglCetak);

$namaPengasuh = trim((string) ($kop['nama_pengasuh'] ?? ''));
$namaKetuaYayasan = trim((string) ($kop['nama_ketua_yayasan'] ?? ''));
$waliKelas = trim((string) ($santri['wali_kelas'] ?? ''));
$waliKamar = trim((string) ($santri['nama_kamar'] ?? ''));

/** Badge kelas CSS nilai otomatis. */
$nilaiBadgeClass = static function (string $kode): string {
    return match (strtoupper(trim($kode))) {
        'BAIK' => 'skbt-nilai-baik',
        'SEDANG' => 'skbt-nilai-sedang',
        default => 'skbt-nilai-buruk',
    };
};

/** Render blok kegiatan presensi (hanya bulan yang ada data). */
$renderKegiatanBlocks = static function (array $items) use ($nilaiBadgeClass): void {
    if ($items === []) {
        echo '<p class="skbt-section-placeholder">Belum ada data presensi untuk bagian ini.</p>';
        return;
    }
    foreach ($items as $kg) {
        $bulanAktif = $kg['bulan_aktif'] ?? [];
        if ($bulanAktif === []) {
            continue;
        }
        $nilaiKeg = (string) ($kg['nilai_keseluruhan'] ?? 'BAIK');
        echo '<div class="skbt-keg-block">';
        echo '<div class="skbt-keg-head"><strong>' . htmlspecialchars((string) ($kg['nama_kegiatan'] ?? '')) . '</strong>';
        echo ' <span class="skbt-nilai-badge ' . $nilaiBadgeClass($nilaiKeg) . '">' . htmlspecialchars($nilaiKeg) . '</span></div>';
        echo '<div class="skbt-keg-sub">' . htmlspecialchars((string) ($kg['subjudul'] ?? '')) . '</div>';
        echo '<table class="skbt-presensi-table"><thead><tr>';
        echo '<th>Bulan</th><th>Hadir</th><th>Ijin</th><th>Sakit</th><th>Ghoib</th><th>Nilai</th>';
        echo '</tr></thead><tbody>';
        foreach ($bulanAktif as $bm) {
            $n = (string) ($bm['nilai'] ?? 'BAIK');
            echo '<tr>';
            echo '<td>' . htmlspecialchars((string) ($bm['label'] ?? '')) . '</td>';
            echo '<td class="num">' . (int) ($bm['hadir'] ?? 0) . '</td>';
            echo '<td class="num">' . (int) ($bm['izin'] ?? 0) . '</td>';
            echo '<td class="num">' . (int) ($bm['sakit'] ?? 0) . '</td>';
            echo '<td class="num">' . (int) ($bm['ghoib'] ?? 0) . '</td>';
            echo '<td><span class="skbt-nilai-badge ' . $nilaiBadgeClass($n) . '">' . htmlspecialchars($n) . '</span></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
};

$page = 0;
$nextPage = static function () use (&$page): int {
    $page++;

    return $page;
};

$skbtJudul = strtoupper(surat_cetak_template_render($pdo, 'skbt_judul', [
    'nama_ponpes' => $namaPonpes,
    'nama_santri' => (string) ($santri['nama_santri'] ?? ''),
]));
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
        <?= pondok_kop_surat_css('#15803d', $logoHref) ?>
    </style>
</head>
<body class="skbt-body">
<div class="no-print">
    <a href="<?= htmlspecialchars(app_href('/akademik/skbt.php?santri_id=' . $santriId . '&tahun_syawal=' . $tahunSyawal)) ?>">← Kembali</a>
    <button type="button" onclick="window.print()">Cetak</button>
</div>

<!-- Halaman 1: Kop + Jatidiri + Disiplin -->
<div class="skbt-sheet">
    <div class="skbt-top-bar"></div>
    <div class="skbt-inner">
        <?= pondok_kop_surat_html($kop, '#15803d') ?>

        <div class="skbt-doc-title">
            <h1><?= htmlspecialchars($skbtJudul) ?></h1>
            <p class="skbt-subtitle">Surat Keterangan Belajar dan Tingkatan</p>
            <?php if ($alamat !== '' || $kontak !== ''): ?>
                <p class="skbt-kontak"><?= htmlspecialchars($alamat) ?><?= $kontak !== '' ? ' · ' . htmlspecialchars($kontak) : '' ?></p>
            <?php endif; ?>
        </div>
        <div class="skbt-nomor">NOMOR: <?= htmlspecialchars($nomor) ?></div>

        <h2 class="skbt-section-title">Jatidiri</h2>
        <dl class="skbt-jatidiri">
            <dt>NIS</dt><dd><?= htmlspecialchars((string) ($santri['nis'] ?? '-')) ?></dd>
            <dt>Nama</dt><dd><?= htmlspecialchars((string) ($santri['nama_santri'] ?? '-')) ?></dd>
            <dt>Bin</dt><dd><?= htmlspecialchars((string) ($santri['nama_ayah'] ?? '-')) ?></dd>
            <dt>Alamat</dt><dd><?= htmlspecialchars((string) ($santri['alamat_gabung'] ?? '-')) ?></dd>
            <dt>Tahun masuk</dt><dd><?= (int) ($santri['tahun_masuk'] ?? 0) ?: '—' ?></dd>
            <dt>Tahun ke</dt><dd><?= (int) ($santri['tahun_ke'] ?? 0) ?: '—' ?></dd>
            <dt>Tingkatan saat ini</dt><dd><?= htmlspecialchars((string) ($santri['tingkatan'] ?? '-')) ?></dd>
        </dl>

        <?php
        $ringkas = $laporan['ringkasan_penilaian'] ?? [];
        $totNilai = (array) ($ringkas['total'] ?? []);
        if ($totNilai !== []):
            $nilaiTa = (string) (($ringkas['per_kategori']['TAALIM']['nilai'] ?? '') ?: '—');
            $nilaiJm = (string) (($ringkas['per_kategori']['JAMAAH']['nilai'] ?? '') ?: '—');
            $nilaiAll = (string) ($totNilai['nilai'] ?? 'BAIK');
        ?>
        <h2 class="skbt-section-title">Penilaian Keaktifan (Otomatis)</h2>
        <?php if (!empty($laporan['penilaian']['legend'])): ?>
            <p class="skbt-penilaian-legend"><strong>Kriteria:</strong> <?= htmlspecialchars((string) $laporan['penilaian']['legend']) ?></p>
        <?php endif; ?>
        <table class="skbt-ringkasan-table">
            <thead>
                <tr><th>Ringkasan</th><th>Hadir</th><th>Ijin</th><th>Sakit</th><th>Ghoib</th><th>Nilai</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Seluruh kegiatan</strong></td>
                    <td class="num"><?= (int) ($totNilai['hadir'] ?? 0) ?></td>
                    <td class="num"><?= (int) ($totNilai['izin'] ?? 0) ?></td>
                    <td class="num"><?= (int) ($totNilai['sakit'] ?? 0) ?></td>
                    <td class="num"><?= (int) ($totNilai['ghoib'] ?? 0) ?></td>
                    <td><span class="skbt-nilai-badge <?= $nilaiBadgeClass($nilaiAll) ?>"><?= htmlspecialchars($nilaiAll) ?></span></td>
                </tr>
                <?php if ($nilaiTa !== '—'): ?>
                <tr>
                    <td>Ta'lim / disiplin kelas</td>
                    <td class="num"><?= (int) (($ringkas['per_kategori']['TAALIM']['hadir'] ?? 0)) ?></td>
                    <td class="num"><?= (int) (($ringkas['per_kategori']['TAALIM']['izin'] ?? 0)) ?></td>
                    <td class="num"><?= (int) (($ringkas['per_kategori']['TAALIM']['sakit'] ?? 0)) ?></td>
                    <td class="num"><?= (int) (($ringkas['per_kategori']['TAALIM']['ghoib'] ?? 0)) ?></td>
                    <td><span class="skbt-nilai-badge <?= $nilaiBadgeClass($nilaiTa) ?>"><?= htmlspecialchars($nilaiTa) ?></span></td>
                </tr>
                <?php endif; ?>
                <?php if ($nilaiJm !== '—'): ?>
                <tr>
                    <td>Jama'ah</td>
                    <td class="num"><?= (int) (($ringkas['per_kategori']['JAMAAH']['hadir'] ?? 0)) ?></td>
                    <td class="num"><?= (int) (($ringkas['per_kategori']['JAMAAH']['izin'] ?? 0)) ?></td>
                    <td class="num"><?= (int) (($ringkas['per_kategori']['JAMAAH']['sakit'] ?? 0)) ?></td>
                    <td class="num"><?= (int) (($ringkas['per_kategori']['JAMAAH']['ghoib'] ?? 0)) ?></td>
                    <td><span class="skbt-nilai-badge <?= $nilaiBadgeClass($nilaiJm) ?>"><?= htmlspecialchars($nilaiJm) ?></span></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <?php
        $tkLaporan = trim((string) ($laporan['tingkatan'] ?? $santri['tingkatan'] ?? ''));
        $kegJadwal = $laporan['kegiatan_jadwal'] ?? [];
        if ($tkLaporan !== '' && $kegJadwal !== []):
            $taalimJadwal = [];
            $jamaahJadwal = [];
            foreach ($kegJadwal as $kj) {
                $kat = strtoupper((string) ($kj['kategori_kegiatan'] ?? 'TAALIM'));
                $nama = trim((string) ($kj['nama_kegiatan'] ?? ''));
                if ($nama === '') {
                    continue;
                }
                if ($kat === 'JAMAAH') {
                    $jamaahJadwal[] = $nama;
                } else {
                    $taalimJadwal[] = $nama;
                }
            }
        ?>
        <h2 class="skbt-section-title">Kegiatan Tingkatan <?= htmlspecialchars($tkLaporan) ?></h2>
        <p class="skbt-section-placeholder" style="font-style:normal;color:#475569;margin-left:0">
            Data presensi di bawah mengikuti jadwal: <strong><?= htmlspecialchars((string) ($santri['nama_santri'] ?? '')) ?></strong>
            → tingkatan <strong><?= htmlspecialchars($tkLaporan) ?></strong> → kegiatan terjadwal.
        </p>
        <?php if ($taalimJadwal !== []): ?>
            <p class="mb-1" style="font-size:12pt;margin-left:1rem"><strong>Ta'lim:</strong> <?= htmlspecialchars(implode(', ', $taalimJadwal)) ?></p>
        <?php endif; ?>
        <?php if ($jamaahJadwal !== []): ?>
            <p class="mb-2" style="font-size:12pt;margin-left:1rem"><strong>Jama'ah:</strong> <?= htmlspecialchars(implode(', ', $jamaahJadwal)) ?></p>
        <?php endif; ?>
        <?php endif; ?>

        <h2 class="skbt-section-title">Disiplin Kelas — <?= htmlspecialchars($tkLaporan !== '' ? $tkLaporan : 'Ta\'lim') ?></h2>
        <?php $renderKegiatanBlocks($laporan['disiplin_kelas']); ?>

        <div class="skbt-footer-page">Halaman <?= $nextPage() ?> dari —</div>
    </div>
</div>

<!-- Halaman 2+: Nilai kelas (placeholder) + Ikhtibar + Jamaah -->
<div class="skbt-sheet skbt-page-break">
    <div class="skbt-top-bar"></div>
    <div class="skbt-inner">
        <h2 class="skbt-section-title">Nilai Kelas</h2>
        <p class="skbt-section-placeholder">
            Komponen nilai kitab (Nahwu, Shorof, Makna, Murod, Hafalan, Asilah) dapat dihubungkan ke modul ikhtibar/setoran pada pengembangan berikutnya.
            Keaktivan ta'lim tercatat di bagian Disiplin Kelas.
        </p>

        <h2 class="skbt-section-title">Nilai Ikhtibar</h2>
        <?php
        require_once __DIR__ . '/../helpers/akademik_rapor.php';
        $ikhtibarList = [];
        if (table_exists($pdo, 'ikhtibar_tugas')) {
            $periodeSatu = rekap_resolve_periode($pdo, ['mode' => 'hijriyah', 'month' => 9, 'year' => $tahunSyawal + 1]);
            $ikhtibarList = rapor_tugas_bulan($pdo, $santriId, $periodeSatu);
        }
        if ($ikhtibarList === []) {
            foreach ($laporan['disiplin_kelas'] as $dk) {
                $ikhtibarList[] = ['mapel_label' => (string) ($dk['nama_kegiatan'] ?? '')];
            }
        }
        if ($ikhtibarList === []) {
            echo '<p class="skbt-section-placeholder">Belum ada data ikhtibar.</p>';
        } else {
            echo '<ul class="mb-0" style="margin-left:1.2rem">';
            foreach ($ikhtibarList as $ik) {
                $label = trim((string) ($ik['mapel_label'] ?? ''));
                if ($label !== '') {
                    echo '<li>' . htmlspecialchars($label) . '</li>';
                }
            }
            echo '</ul>';
        }
        ?>

        <h2 class="skbt-section-title">Presensi Kegiatan (Jama'ah)</h2>
        <?php $renderKegiatanBlocks($laporan['presensi_jamaah']); ?>

        <?php if ($laporan['lainnya'] !== []): ?>
            <h2 class="skbt-section-title">Kegiatan Lain</h2>
            <?php $renderKegiatanBlocks($laporan['lainnya']); ?>
        <?php endif; ?>

        <div class="skbt-footer-page">Halaman <?= $nextPage() ?> dari —</div>
    </div>
</div>

<!-- Halaman: Mujahadah / Ekstra dari nama kegiatan -->
<?php
$ekstra = [];
foreach ($laporan['kegiatan'] as $kg) {
    $n = strtolower((string) ($kg['nama_kegiatan'] ?? ''));
    if (str_contains($n, 'mujahadah') || str_contains($n, 'ekstra')) {
        $ekstra[] = $kg;
    }
}
if ($ekstra !== []):
?>
<div class="skbt-sheet skbt-page-break">
    <div class="skbt-top-bar"></div>
    <div class="skbt-inner">
        <?php $renderKegiatanBlocks($ekstra); ?>
        <h2 class="skbt-section-title">Kelas Ramadhan</h2>
        <p class="skbt-section-placeholder">Isian kelas Ramadhan — sesuaikan di pengaturan kegiatan.</p>
        <h2 class="skbt-section-title">Kuota Muhafadzoh</h2>
        <p class="skbt-section-placeholder">Isian kuota muhafadzoh — hubungkan modul hafalan bila diperlukan.</p>
        <div class="skbt-footer-page">Halaman <?= $nextPage() ?> dari —</div>
    </div>
</div>
<?php endif; ?>

<!-- Halaman penutup -->
<div class="skbt-sheet skbt-page-break">
    <div class="skbt-top-bar"></div>
    <div class="skbt-inner">
        <h2 class="skbt-section-title">Kelanjutan Pendidikan</h2>
        <p class="skbt-kelanjutan">
            Pada tanggal <?= htmlspecialchars($hijriTglLabel) ?>, <strong><?= htmlspecialchars($namaPonpes) ?></strong>
            menerangkan bahwa santri atas nama <strong><?= htmlspecialchars((string) ($santri['nama_santri'] ?? '')) ?></strong>
            (NIS <?= htmlspecialchars((string) ($santri['nis'] ?? '')) ?>) dalam periode
            <strong><?= htmlspecialchars((string) ($laporan['periode']['label'] ?? '')) ?></strong>
            (nomor <?= htmlspecialchars($nomor) ?>) telah mengikuti kegiatan pendidikan dan pembinaan
            sesuai ketentuan pondok. Demikian SKBT ini dibuat untuk dipergunakan sebagaimana mestinya.
        </p>

        <div class="skbt-ttd-grid">
            <div class="skbt-ttd-box">
                <div class="role">Wali Kamar</div>
                <div class="name"><?= htmlspecialchars($waliKamar !== '' ? $waliKamar : '…………………') ?></div>
            </div>
            <div class="skbt-ttd-box">
                <div class="role">Wali Kelas</div>
                <div class="name"><?= htmlspecialchars($waliKelas !== '' ? $waliKelas : '…………………') ?></div>
            </div>
            <div class="skbt-ttd-mengetahui">Mengetahui</div>
            <div class="skbt-ttd-box">
                <div class="role">Pengasuh</div>
                <div class="name"><?= htmlspecialchars($namaPengasuh !== '' ? $namaPengasuh : '…………………') ?></div>
            </div>
            <div class="skbt-ttd-box">
                <div class="role">Ketua Yayasan</div>
                <div class="name"><?= htmlspecialchars($namaKetuaYayasan !== '' ? $namaKetuaYayasan : '…………………') ?></div>
            </div>
        </div>

        <div class="skbt-footer-page">Halaman <?= $nextPage() ?> dari <?= (int) $page ?></div>
    </div>
</div>

<script>
(function () {
    var total = <?= (int) $page ?>;
    document.querySelectorAll('.skbt-footer-page').forEach(function (el) {
        el.textContent = el.textContent.replace('dari —', 'dari ' + total);
    });
})();
<?php if ($autoPrint): ?>
window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 400); });
<?php endif; ?>
</script>
</body>
</html>
