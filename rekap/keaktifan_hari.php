<?php



declare(strict_types=1);



require_once __DIR__ . '/../config/database.php';

require_once __DIR__ . '/../includes/auth.php';

require_once __DIR__ . '/../helpers/app.php';

require_once __DIR__ . '/../helpers/rekap_keaktifan_hari.php';



require_roles(['admin', 'pengurus', 'kiai', 'pembimbing']);



$tanggal = trim((string) ($_GET['tanggal'] ?? date('Y-m-d')));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {

    $tanggal = date('Y-m-d');

}

$tingkatan = trim((string) ($_GET['tingkatan'] ?? ''));

$kategori = rekap_keaktifan_hari_normalize_kategori($_GET['kategori'] ?? null);

$kegiatanId = (int) ($_GET['kegiatan_id'] ?? 0);



$rows = rekap_keaktifan_hari_data($pdo, $tanggal, $tingkatan !== '' ? $tingkatan : null, $kategori);

$detailKeg = rekap_keaktifan_hari_detail_by_kegiatan($rows);

$ringkasan = rekap_keaktifan_hari_ringkasan_from_detail($detailKeg);

$totals = rekap_keaktifan_hari_totals($ringkasan);
$totalPerhatian = (int) ($totals['alpa'] ?? 0);
$kegiatanPerhatian = array_values(array_filter($detailKeg, static function (array $dk): bool {
    return ((int) ($dk['alpa'] ?? 0)) > 0;
}));



if ($kegiatanId > 0) {

    $detailKeg = array_values(array_filter(

        $detailKeg,

        static fn (array $d): bool => (int) ($d['kegiatan_id'] ?? 0) === $kegiatanId

    ));

}



$tingkatanList = [];

if (table_exists($pdo, 'santri')) {

    $tingkatanList = $pdo->query('SELECT DISTINCT TRIM(tingkatan) AS t FROM santri WHERE tingkatan IS NOT NULL AND TRIM(tingkatan)<>"" ORDER BY t')->fetchAll(PDO::FETCH_COLUMN) ?: [];

}



$bulanId = [

    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',

    7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',

];

$ts = strtotime($tanggal);

$tglLabel = $ts !== false

    ? (int) date('j', $ts) . ' ' . ($bulanId[(int) date('n', $ts)] ?? '') . ' ' . date('Y', $ts)

    : $tanggal;



$filterBase = static function (array $extra = []) use ($tanggal, $tingkatan, $kategori): string {

    $q = ['tanggal' => $tanggal];

    if ($tingkatan !== '') {

        $q['tingkatan'] = $tingkatan;

    }

    if ($kategori !== null) {

        $q['kategori'] = $kategori;

    }

    foreach ($extra as $k => $v) {

        if ($v === null || $v === '' || $v === 0) {

            unset($q[$k]);

        } else {

            $q[$k] = $v;

        }

    }



    return app_href('/rekap/keaktifan_hari.php?' . http_build_query($q));

};



$barPct = static function (int $n, int $total): float {

    return $total > 0 ? round(100 * $n / $total, 2) : 0.0;

};



$previewNames = static function (array $santriByStatus, int $limit = 3): string {

    $names = [];

    foreach (['ALPA'] as $st) {

        foreach ($santriByStatus[$st] ?? [] as $s) {

            $nama = trim((string) ($s['nama_santri'] ?? ''));

            if ($nama !== '') {

                $names[] = $nama;

            }

            if (count($names) >= $limit) {

                break 2;

            }

        }

    }

    if ($names === []) {

        return '';

    }

    $more = count($santriByStatus['ALPA'] ?? []) - count($names);

    $txt = implode(', ', $names);

    if ($more > 0) {

        $txt .= ' +' . $more;

    }



    return $txt;

};

$kategoriLabel = match ($kategori) {
    'JAMAAH' => "Jama'ah",
    'TAALIM' => "Ta'lim",
    default => 'Semua kategori',
};

$pageTitle = 'Keaktifan Hari Ini';

$pageStylesheets = [app_asset_href('/assets/css/keaktifan-hari.css')];

$bodyClass = 'page-keaktifan-hari';

$labelKegiatan = static function (string $nama): string {
    $nama = trim($nama);

    return $nama === '' ? '' : mb_convert_case($nama, MB_CASE_TITLE, 'UTF-8');
};

require_once __DIR__ . '/../includes/header.php';

?>



<div class="kh-wrap">

    <div class="page-intro mb-3 d-flex flex-wrap justify-content-between gap-2">

        <div>

            <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/rekap/hub.php')) ?>">Pusat Rekap</a></p>

            <h1 class="h4 mb-1 d-flex align-items-center flex-wrap gap-2">
                Keaktifan santri hari ini
                <button type="button" class="btn btn-link btn-sm p-0 kh-panduan-btn d-md-none" data-bs-toggle="modal" data-bs-target="#khPanduanModal" aria-label="Cara membaca halaman ini">
                    <i class="fa-solid fa-circle-info fa-lg"></i>
                </button>
            </h1>

            <p class="text-muted mb-0 small">Satu layar untuk semua kegiatan — lihat siapa sudah scan dan siapa perlu ditindaklanjuti.</p>

        </div>

        <a class="btn btn-sm btn-outline-secondary align-self-start" href="<?= htmlspecialchars(app_href('/rekap/hub.php')) ?>"><i class="fa-solid fa-arrow-left me-1"></i>Pusat Rekap</a>

    </div>



    <form class="row g-2 align-items-end kh-section kh-filter-form" method="get">

        <?php if ($kegiatanId > 0): ?>

            <input type="hidden" name="kegiatan_id" value="<?= (int) $kegiatanId ?>">

        <?php endif; ?>

        <div class="col-12 col-md-2">

            <label class="form-label small mb-0">Tanggal</label>

            <input type="date" name="tanggal" class="form-control form-control-sm" value="<?= htmlspecialchars($tanggal) ?>">

        </div>

        <div class="col-12 col-md-2">

            <label class="form-label small mb-0">Kategori</label>

            <select name="kategori" class="form-select form-select-sm">

                <option value="" <?= $kategori === null ? 'selected' : '' ?>>Semua</option>

                <option value="JAMAAH" <?= $kategori === 'JAMAAH' ? 'selected' : '' ?>>Jama'ah saja</option>

                <option value="TAALIM" <?= $kategori === 'TAALIM' ? 'selected' : '' ?>>Ta'lim saja</option>

            </select>

        </div>

        <div class="col-12 col-md-2">

            <label class="form-label small mb-0">Tingkatan</label>

            <select name="tingkatan" class="form-select form-select-sm">

                <option value="">Semua tingkatan</option>

                <?php foreach ($tingkatanList as $tk): ?>

                    <option value="<?= htmlspecialchars((string) $tk) ?>" <?= $tingkatan === (string) $tk ? 'selected' : '' ?>><?= htmlspecialchars((string) $tk) ?></option>

                <?php endforeach; ?>

            </select>

        </div>

        <div class="col-12 col-md-auto">

            <button type="submit" class="btn btn-primary btn-sm kh-filter-submit"><i class="fa-solid fa-filter me-1"></i>Terapkan</button>

        </div>

    </form>



    <?php if ($detailKeg === []): ?>

        <div class="card shadow-sm border-0">

            <div class="card-body text-center text-muted py-5">

                <div class="display-6 mb-2 opacity-50"><i class="fa-regular fa-calendar-xmark"></i></div>

                <p class="mb-0 fw-semibold">Tidak ada kegiatan aktif atau data santri</p>

                <p class="small mb-0">Ubah tanggal atau filter tingkatan.</p>

            </div>

        </div>

    <?php else: ?>

    <?php
    $khShowHero = true;
    $khHeroSubtitle = $kategoriLabel . ($tingkatan !== '' ? ' · ' . $tingkatan : ($kategori === null ? ' · Seluruh pondok' : ''));
    $khHeroEntriLabel = 'pencatatan (santri × kegiatan)';
    require __DIR__ . '/../includes/partials/keaktifan_hari_kegiatan_cards.php';
    ?>

    <?php endif; ?>

</div>

<div class="modal fade" id="khPanduanModal" tabindex="-1" aria-labelledby="khPanduanModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h2 class="modal-title h6 mb-0" id="khPanduanModalLabel"><i class="fa-solid fa-circle-info me-1 text-primary"></i> Cara membaca</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body small">
                <p class="mb-2"><span class="kh-panduan__item kh-panduan__item--hadir">Hadir</span> — santri sudah scan.</p>
                <p class="mb-2"><span class="kh-panduan__item kh-panduan__item--izin">Izin</span> / <span class="kh-panduan__item kh-panduan__item--sakit">Sakit</span> — ada keterangan resmi.</p>
                <p class="mb-2"><span class="kh-panduan__item kh-panduan__item--alpa">Alpa</span> — tidak scan sampai jam kegiatan selesai (tanpa izin/sakit).</p>
                <p class="mb-0">Geser tab kegiatan ke kiri/kanan. Ketuk kotak jumlah atau <strong>Daftar santri</strong> untuk melihat nama lengkap.</p>
            </div>
        </div>
    </div>
</div>

<script src="<?= htmlspecialchars(app_asset_href('/assets/js/keaktifan-hari.js')) ?>"></script>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>

