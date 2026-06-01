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

$kegiatanId = (int) ($_GET['kegiatan_id'] ?? 0);



$rows = rekap_keaktifan_hari_data($pdo, $tanggal, $tingkatan !== '' ? $tingkatan : null);

$ringkasan = rekap_keaktifan_hari_ringkasan_kegiatan($pdo, $tanggal, $tingkatan !== '' ? $tingkatan : null);

$totals = rekap_keaktifan_hari_totals($ringkasan);

$detailKeg = rekap_keaktifan_hari_detail_by_kegiatan($rows);
$totalPerhatian = (int) ($totals['alpa'] ?? 0) + (int) ($totals['belum'] ?? 0);
$kegiatanPerhatian = array_values(array_filter($detailKeg, static function (array $dk): bool {
    return ((int) ($dk['alpa'] ?? 0) + (int) ($dk['belum'] ?? 0)) > 0;
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



$filterBase = static function (array $extra = []) use ($tanggal, $tingkatan): string {

    $q = ['tanggal' => $tanggal];

    if ($tingkatan !== '') {

        $q['tingkatan'] = $tingkatan;

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

    foreach (['ALPA', 'BELUM'] as $st) {

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

    $more = count($santriByStatus['ALPA'] ?? []) + count($santriByStatus['BELUM'] ?? []) - count($names);

    $txt = implode(', ', $names);

    if ($more > 0) {

        $txt .= ' +' . $more;

    }



    return $txt;

};



$pageTitle = 'Keaktifan Hari Ini';

$pageStylesheets = [app_asset_href('/assets/css/keaktifan-hari.css')];

require_once __DIR__ . '/../includes/header.php';

?>



<div class="kh-wrap">

    <div class="page-intro mb-3 d-flex flex-wrap justify-content-between gap-2">

        <div>

            <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href('/rekap/hub.php')) ?>">Pusat Rekap</a></p>

            <h1 class="h4 mb-1">Keaktifan santri hari ini</h1>

            <p class="text-muted mb-0 small">Satu layar untuk semua kegiatan — lihat siapa sudah scan dan siapa perlu ditindaklanjuti.</p>

        </div>

        <a class="btn btn-sm btn-outline-secondary align-self-start" href="<?= htmlspecialchars(app_href('/rekap/hub.php')) ?>"><i class="fa-solid fa-arrow-left me-1"></i>Pusat Rekap</a>

    </div>



    <form class="row g-2 align-items-end mb-3" method="get">

        <?php if ($kegiatanId > 0): ?>

            <input type="hidden" name="kegiatan_id" value="<?= (int) $kegiatanId ?>">

        <?php endif; ?>

        <div class="col-6 col-md-3">

            <label class="form-label small mb-0">Tanggal</label>

            <input type="date" name="tanggal" class="form-control form-control-sm" value="<?= htmlspecialchars($tanggal) ?>">

        </div>

        <div class="col-6 col-md-3">

            <label class="form-label small mb-0">Tingkatan</label>

            <select name="tingkatan" class="form-select form-select-sm">

                <option value="">Semua tingkatan</option>

                <?php foreach ($tingkatanList as $tk): ?>

                    <option value="<?= htmlspecialchars((string) $tk) ?>" <?= $tingkatan === (string) $tk ? 'selected' : '' ?>><?= htmlspecialchars((string) $tk) ?></option>

                <?php endforeach; ?>

            </select>

        </div>

        <div class="col-auto">

            <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-filter me-1"></i>Terapkan</button>

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

    <div class="alert alert-light border mb-3 py-2 small kh-panduan">
        <strong class="d-block mb-1"><i class="fa-solid fa-circle-info me-1 text-primary"></i> Cara membaca</strong>
        <span class="kh-panduan__item kh-panduan__item--hadir">Hadir</span> sudah scan ·
        <span class="kh-panduan__item kh-panduan__item--izin">Izin</span> / <span class="kh-panduan__item kh-panduan__item--sakit">Sakit</span> ada keterangan ·
        <span class="kh-panduan__item kh-panduan__item--belum">Belum</span> kegiatan masih berlangsung, belum scan ·
        <span class="kh-panduan__item kh-panduan__item--alpa">Alpa</span> tidak scan sampai jam kegiatan selesai.
        Klik nama kegiatan di bawah untuk fokus, lalu <em>Daftar santri</em> untuk lihat nama.
    </div>

    <?php if ($totalPerhatian > 0): ?>
    <div class="card border-warning mb-3 shadow-sm">
        <div class="card-body py-2">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <span class="fw-semibold text-warning"><i class="fa-solid fa-triangle-exclamation me-1"></i><?= (int) $totalPerhatian ?> santri perlu perhatian</span>
                    <span class="text-muted small ms-1">(alpa + belum scan)</span>
                </div>
                <span class="small text-muted"><?= count($kegiatanPerhatian) ?> kegiatan terdampak</span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="kh-hero mb-3">

        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">

            <div>

                <div class="kh-hero__date"><?= htmlspecialchars($tglLabel) ?><?= $tingkatan !== '' ? ' · ' . htmlspecialchars($tingkatan) : ' · Seluruh pondok' ?></div>

                <div class="small text-muted"><?= count($detailKeg) ?> kegiatan · <?= (int) $totals['total'] ?> pencatatan (santri × kegiatan)</div>

            </div>

            <div class="text-end">

                <div class="kh-hero__pct text-success"><?= number_format($totals['persen'], 1, ',', '.') ?>%</div>

                <div class="small text-muted">tingkat hadir</div>

            </div>

        </div>

        <div class="kh-totals mb-2">

            <div class="kh-total-pill kh-total-pill--hadir"><div class="kh-total-pill__n"><?= (int) $totals['hadir'] ?></div><div class="kh-total-pill__l">Hadir</div></div>

            <div class="kh-total-pill kh-total-pill--izin"><div class="kh-total-pill__n"><?= (int) $totals['izin'] ?></div><div class="kh-total-pill__l">Izin</div></div>

            <div class="kh-total-pill kh-total-pill--sakit"><div class="kh-total-pill__n"><?= (int) $totals['sakit'] ?></div><div class="kh-total-pill__l">Sakit</div></div>

            <div class="kh-total-pill kh-total-pill--alpa"><div class="kh-total-pill__n"><?= (int) $totals['alpa'] ?></div><div class="kh-total-pill__l">Alpa</div></div>

            <div class="kh-total-pill kh-total-pill--belum"><div class="kh-total-pill__n"><?= (int) $totals['belum'] ?></div><div class="kh-total-pill__l">Belum</div></div>

        </div>

        <div class="kh-legend">

            <span class="l-hadir">Hadir</span>

            <span class="l-izin">Izin</span>

            <span class="l-sakit">Sakit</span>

            <span class="l-alpa">Alpa</span>

            <span class="l-belum">Belum scan</span>

        </div>

    </div>



    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">

        <div class="kh-chips">

            <a class="kh-chip <?= $kegiatanId === 0 ? 'is-active' : '' ?>" href="<?= htmlspecialchars($filterBase(['kegiatan_id' => null])) ?>">Semua kegiatan</a>

            <?php foreach ($ringkasan as $rg): ?>

                <?php $kid = (int) ($rg['kegiatan_id'] ?? 0); ?>

                <a class="kh-chip <?= $kegiatanId === $kid ? 'is-active' : '' ?>" href="<?= htmlspecialchars($filterBase(['kegiatan_id' => $kid])) ?>">

                    <?= htmlspecialchars((string) $rg['nama_kegiatan']) ?>

                    <span class="badge rounded-pill <?= $kegiatanId === $kid ? 'text-bg-light' : 'text-bg-secondary' ?>"><?= (int) $rg['hadir'] ?>/<?= (int) $rg['total'] ?></span>
                    <?php $rgPerlu = (int) ($rg['alpa'] ?? 0) + (int) ($rg['belum'] ?? 0); ?>
                    <?php if ($rgPerlu > 0): ?>
                        <span class="badge rounded-pill text-bg-warning text-dark"><?= $rgPerlu ?> perlu</span>
                    <?php endif; ?>

                </a>

            <?php endforeach; ?>

        </div>

        <button type="button" class="btn btn-sm btn-outline-secondary" id="khToggleAll" data-expanded="0">Buka semua detail</button>

    </div>



    <div class="kh-grid" id="khGrid">

        <?php foreach ($detailKeg as $dk):

            $kid = (int) ($dk['kegiatan_id'] ?? 0);

            $total = (int) ($dk['total'] ?? 0);

            $hadir = (int) ($dk['hadir'] ?? 0);

            $pctHadir = $total > 0 ? round(100 * $hadir / $total, 0) : 0;

            $santri = $dk['santri'] ?? [];

            $perlu = (int) ($dk['alpa'] ?? 0) + (int) ($dk['belum'] ?? 0);

            $preview = $previewNames(is_array($santri) ? $santri : []);

            $focus = $kegiatanId > 0 && $kegiatanId === $kid;
            $needsAttention = $perlu > 0;

        ?>

        <article class="kh-card<?= $focus ? ' is-focus' : '' ?><?= $needsAttention ? ' kh-card--warning' : '' ?>" id="keg-<?= $kid ?>" data-kegiatan-id="<?= $kid ?>">

            <div class="kh-card__head">

                <h2 class="kh-card__title"><?= htmlspecialchars((string) $dk['nama_kegiatan']) ?></h2>

                <div class="kh-card__meta"><?= $hadir ?> hadir dari <?= $total ?> santri · <strong><?= (int) $pctHadir ?>%</strong></div>

                <div class="kh-bar" role="img" aria-label="Distribusi presensi">

                    <?php foreach (['hadir' => 'hadir', 'izin' => 'izin', 'sakit' => 'sakit', 'alpa' => 'alpa', 'belum' => 'belum'] as $key => $cls):

                        $n = (int) ($dk[$key] ?? 0);

                        $w = $barPct($n, $total);

                        if ($w <= 0) {

                            continue;

                        }

                    ?>

                    <span class="kh-bar__seg kh-bar__seg--<?= $cls ?>" style="width:<?= $w ?>%" title="<?= ucfirst($key) ?> <?= $n ?>"></span>

                    <?php endforeach; ?>

                </div>

            </div>

            <div class="kh-stats">

                <div class="kh-stat kh-stat--hadir"><span class="kh-stat__n"><?= (int) $dk['hadir'] ?></span><span class="kh-stat__l">Hadir</span></div>

                <div class="kh-stat kh-stat--izin"><span class="kh-stat__n"><?= (int) $dk['izin'] ?></span><span class="kh-stat__l">Izin</span></div>

                <div class="kh-stat kh-stat--sakit"><span class="kh-stat__n"><?= (int) $dk['sakit'] ?></span><span class="kh-stat__l">Sakit</span></div>

                <div class="kh-stat kh-stat--alpa"><span class="kh-stat__n"><?= (int) $dk['alpa'] ?></span><span class="kh-stat__l">Alpa</span></div>

                <div class="kh-stat kh-stat--belum"><span class="kh-stat__n"><?= (int) $dk['belum'] ?></span><span class="kh-stat__l">Belum</span></div>

            </div>

            <?php if ($perlu > 0): ?>

            <div class="kh-card__alert" title="Perlu tindak lanjut">

                <i class="fa-solid fa-triangle-exclamation me-1"></i>

                <?= $perlu ?> perlu perhatian<?= $preview !== '' ? ': ' . htmlspecialchars($preview) : '' ?>

            </div>

            <?php else: ?>

            <div class="kh-card__alert kh-card__alert--ok">

                <i class="fa-solid fa-circle-check me-1"></i>Semua santri sudah tercatat hadir/izin/sakit

            </div>

            <?php endif; ?>

            <div class="kh-card__body">

                <button type="button" class="kh-detail-toggle" data-bs-toggle="collapse" data-bs-target="#kh-detail-<?= $kid ?>" aria-expanded="<?= $focus ? 'true' : 'false' ?>">

                    <i class="fa-solid fa-chevron-down me-1"></i> Daftar santri

                </button>

            </div>

            <div class="collapse<?= $focus ? ' show' : '' ?>" id="kh-detail-<?= $kid ?>">

                <div class="kh-detail-panel">

                    <div class="kh-tabs" role="tablist">

                        <button type="button" class="kh-tab is-active" data-kh-tab="perlu" data-kh-card="<?= $kid ?>">Perlu ditindak (<?= $perlu ?>)</button>

                        <button type="button" class="kh-tab" data-kh-tab="HADIR" data-kh-card="<?= $kid ?>">Hadir (<?= (int) $dk['hadir'] ?>)</button>

                        <button type="button" class="kh-tab" data-kh-tab="ALPA" data-kh-card="<?= $kid ?>">Alpa (<?= (int) $dk['alpa'] ?>)</button>

                        <button type="button" class="kh-tab" data-kh-tab="BELUM" data-kh-card="<?= $kid ?>">Belum (<?= (int) $dk['belum'] ?>)</button>

                        <button type="button" class="kh-tab" data-kh-tab="IZIN" data-kh-card="<?= $kid ?>">Izin</button>

                        <button type="button" class="kh-tab" data-kh-tab="SAKIT" data-kh-card="<?= $kid ?>">Sakit</button>

                    </div>

                    <?php

                    $lists = [

                        'perlu' => array_merge($santri['ALPA'] ?? [], $santri['BELUM'] ?? []),

                        'HADIR' => $santri['HADIR'] ?? [],

                        'ALPA' => $santri['ALPA'] ?? [],

                        'BELUM' => $santri['BELUM'] ?? [],

                        'IZIN' => $santri['IZIN'] ?? [],

                        'SAKIT' => $santri['SAKIT'] ?? [],

                    ];

                    foreach ($lists as $tabKey => $list):

                    ?>

                    <ul class="kh-list<?= $tabKey === 'perlu' ? '' : ' d-none' ?>" data-kh-list="<?= htmlspecialchars((string) $tabKey) ?>" data-kh-card="<?= $kid ?>"<?= $tabKey === 'perlu' && $list === [] ? ' data-empty="1"' : '' ?>>

                        <?php if ($list === []): ?>

                            <li class="text-muted"><?= $tabKey === 'perlu' ? 'Semua santri sudah tercatat hadir/izin/sakit.' : 'Tidak ada data.' ?></li>

                        <?php endif; ?>

                        <?php foreach ($list as $s): ?>

                        <li>

                            <span class="kh-list__name"><?= htmlspecialchars((string) ($s['nama_santri'] ?? '')) ?></span>

                            <span class="kh-list__sub"><?= htmlspecialchars((string) ($s['tingkatan'] ?? '')) ?><?= !empty($s['jam_presensi']) ? ' · ' . htmlspecialchars((string) $s['jam_presensi']) : '' ?></span>

                        </li>

                        <?php endforeach; ?>

                    </ul>

                    <?php endforeach; ?>

                </div>

            </div>

        </article>

        <?php endforeach; ?>

    </div>



    <?php endif; ?>

</div>



<script>

(function () {

    document.querySelectorAll('.kh-tabs').forEach(function (tabs) {

        tabs.addEventListener('click', function (e) {

            var btn = e.target.closest('.kh-tab');

            if (!btn) return;

            var cardId = btn.getAttribute('data-kh-card');

            var tab = btn.getAttribute('data-kh-tab');

            tabs.querySelectorAll('.kh-tab').forEach(function (t) { t.classList.toggle('is-active', t === btn); });

            document.querySelectorAll('.kh-list[data-kh-card="' + cardId + '"]').forEach(function (ul) {

                ul.classList.toggle('d-none', ul.getAttribute('data-kh-list') !== tab);

            });

        });

    });



    var toggleAll = document.getElementById('khToggleAll');

    if (toggleAll) {

        toggleAll.addEventListener('click', function () {

            var expanded = toggleAll.getAttribute('data-expanded') === '1';

            document.querySelectorAll('.kh-card .collapse').forEach(function (el) {

                if (expanded) {

                    bootstrap.Collapse.getOrCreateInstance(el, { toggle: false }).hide();

                } else {

                    bootstrap.Collapse.getOrCreateInstance(el, { toggle: false }).show();

                }

            });

            toggleAll.setAttribute('data-expanded', expanded ? '0' : '1');

            toggleAll.textContent = expanded ? 'Buka semua detail' : 'Tutup semua detail';

        });

    }



    if (location.hash && location.hash.indexOf('keg-') === 1) {

        var el = document.querySelector(location.hash);

        if (el) {

            var col = el.querySelector('.collapse');

            if (col) bootstrap.Collapse.getOrCreateInstance(col, { toggle: false }).show();

            el.scrollIntoView({ behavior: 'smooth', block: 'center' });

        }

    }

})();

</script>



<?php require_once __DIR__ . '/../includes/footer.php'; ?>

