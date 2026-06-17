<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/rekap_keaktifan_hari.php';
require_once __DIR__ . '/../helpers/rekap_keaktifan.php';
require_once __DIR__ . '/../helpers/yayasan.php';

require_roles(['admin', 'pengurus']);

yayasan_ensure_tables($pdo);

$tanggal = trim((string) ($_GET['tanggal'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    $tanggal = date('Y-m-d');
}
$tingkatan = trim((string) ($_GET['tingkatan'] ?? ''));
$kategori = rekap_keaktifan_hari_normalize_kategori($_GET['kategori'] ?? null);
$kegiatanId = (int) ($_GET['kegiatan_id'] ?? 0);

$tkFilter = $tingkatan !== '' ? $tingkatan : null;
$rows = rekap_keaktifan_hari_data($pdo, $tanggal, $tkFilter, $kategori);
$detailKeg = rekap_keaktifan_hari_detail_by_kegiatan($rows);
$ringkasan = rekap_keaktifan_hari_ringkasan_from_detail($detailKeg);
$totals = rekap_keaktifan_hari_totals($ringkasan);
$totalPerhatian = (int) ($totals['alpa'] ?? 0);
$kegiatanPerhatian = array_values(array_filter(
    $detailKeg,
    static fn (array $dk): bool => ((int) ($dk['alpa'] ?? 0)) > 0
));
$byTingkatan = rekap_keaktifan_hari_by_tingkatan($rows);
$sdm = rekap_keaktifan_hari_sdm($pdo, $tanggal);
$riwayatPembimbingMasuk = rekap_keaktifan_hari_riwayat_pembimbing_masuk($pdo, $tanggal);
$kegiatanKosong = rekap_keaktifan_hari_kegiatan_kosong($pdo, $tanggal, $tkFilter, $kategori);
$kegiatanTanpaScan = rekap_keaktifan_kegiatan_tanpa_scan_bulan($pdo, $tanggal, $tanggal, $tkFilter);

if ($kegiatanId > 0) {
    $detailKeg = array_values(array_filter(
        $detailKeg,
        static fn(array $d): bool => (int) ($d['kegiatan_id'] ?? 0) === $kegiatanId
    ));
}

$tingkatanList = [];
if (table_exists($pdo, 'santri')) {
    $tingkatanList = $pdo->query(
        'SELECT DISTINCT TRIM(tingkatan) AS t FROM santri WHERE tingkatan IS NOT NULL AND TRIM(tingkatan)<>"" ORDER BY t'
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];
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

    return app_href('/yayasan/keaktifan.php?' . http_build_query($q));
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

    return $more > 0 ? $txt . ' +' . $more : $txt;
};

$kategoriLabel = match ($kategori) {
    'JAMAAH' => "Jama'ah",
    'TAALIM' => "Ta'lim",
    default => 'Semua kategori',
};
$hubYayasan = '/menu/menu_hub.php?id=menu-grp-yayasan';
$pb = $sdm['pembimbing'] ?? ['masuk' => 0, 'total' => 0];
$mw = $sdm['munawib'] ?? ['masuk' => 0, 'total' => 0];
$pbPct = (int) $pb['total'] > 0 ? round(100 * (int) $pb['masuk'] / (int) $pb['total']) : 0;
$mwPct = (int) $mw['total'] > 0 ? round(100 * (int) $mw['masuk'] / (int) $mw['total']) : 0;

$pageTitle = 'Rekap Keaktifan Hari Ini';
$pageStylesheets = [app_asset_href('/assets/css/yayasan-portal.css'), app_asset_href('/assets/css/keaktifan-hari.css')];
$bodyClass = 'page-keaktifan-hari';
$labelKegiatan = static function (string $nama): string {
    $nama = trim($nama);

    return $nama === '' ? '' : mb_convert_case($nama, MB_CASE_TITLE, 'UTF-8');
};
require_once __DIR__ . '/../includes/header.php';
?>

<div class="yp-wrap kh-wrap">
    <div class="page-intro mb-3 d-flex flex-wrap justify-content-between gap-2">
        <div>
            <p class="page-intro-kicker mb-1"><a href="<?= htmlspecialchars(app_href($hubYayasan)) ?>">Yayasan</a></p>
            <h1 class="h4 mb-1 d-flex align-items-center flex-wrap gap-2">
                Rekap Keaktifan Hari Ini
                <button type="button" class="btn btn-link btn-sm p-0 kh-panduan-btn d-md-none" data-bs-toggle="modal" data-bs-target="#khPanduanModal" aria-label="Cara membaca halaman ini">
                    <i class="fa-solid fa-circle-info fa-lg"></i>
                </button>
            </h1>
            <p class="text-muted mb-0 small">Scan gerbang · Santri, Pembimbing, Munawib, Jama'ah & Ta'lim</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-self-start">
            <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars(app_href('/presensi/scan.php')) ?>">
                <i class="fa-solid fa-qrcode me-1"></i>Scan
            </a>
        </div>
    </div>

    <form class="row g-2 align-items-end mb-3 yp-filter-bar kh-filter-form kh-section" method="get">
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
                <option value="">Semua kelas</option>
                <?php foreach ($tingkatanList as $tk): ?>
                    <option value="<?= htmlspecialchars((string) $tk) ?>" <?= $tingkatan === (string) $tk ? 'selected' : '' ?>><?= htmlspecialchars((string) $tk) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-12 col-md-auto">
            <button type="submit" class="btn btn-primary btn-sm kh-filter-submit"><i class="fa-solid fa-filter me-1"></i>Terapkan</button>
        </div>
    </form>

    <?php
    $khHeroSantri = rekap_keaktifan_hari_santri_agregat($detailKeg);
    $khHeroStatItems = [
        ['key' => 'hadir', 'tab' => 'HADIR', 'label' => 'Hadir', 'n' => (int) $totals['hadir']],
        ['key' => 'izin', 'tab' => 'IZIN', 'label' => 'Izin', 'n' => (int) $totals['izin']],
        ['key' => 'sakit', 'tab' => 'SAKIT', 'label' => 'Sakit', 'n' => (int) $totals['sakit']],
        ['key' => 'alpa', 'tab' => 'ALPA', 'label' => 'Alpa', 'n' => (int) $totals['alpa']],
    ];
    ?>
    <div class="kh-hero kh-section" id="khHero">
        <div class="kh-hero__top">
            <div class="kh-hero__date"><?= htmlspecialchars($tglLabel) ?> · <?= htmlspecialchars($kategoriLabel) ?><?= $tingkatan !== '' ? ' · ' . htmlspecialchars($tingkatan) : '' ?></div>
            <div class="small text-muted"><?= count($detailKeg) ?> kegiatan · <?= (int) $totals['total'] ?> entri (santri × kegiatan)</div>
        </div>
        <div class="kh-totals">
            <?php foreach ($khHeroStatItems as $hi): ?>
            <button type="button"
                class="kh-total-pill kh-total-pill--<?= htmlspecialchars($hi['key']) ?> kh-total-pill--clickable"
                data-kh-stat-tab="<?= htmlspecialchars($hi['tab']) ?>"
                data-kh-stat-scope="hero"
                aria-expanded="false"
                aria-haspopup="true">
                <?php if ($hi['key'] === 'hadir'): ?>
                <div class="kh-total-pill__n"><?= $hi['n'] ?></div>
                <div class="kh-total-pill__pct"><?= number_format($totals['persen'], 1, ',', '.') ?>% hadir</div>
                <?php else: ?>
                <div class="kh-total-pill__n"><?= $hi['n'] ?></div>
                <?php endif; ?>
                <div class="kh-total-pill__l"><?= htmlspecialchars($hi['label']) ?></div>
            </button>
            <?php endforeach; ?>
        </div>
        <script type="application/json" class="kh-santri-data kh-santri-data--hero"><?= json_encode($khHeroSantri, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
        <div class="kh-stat-popup d-none" data-kh-stat-popup data-kh-stat-scope="hero" role="region" aria-live="polite"></div>
        <div class="kh-legend">
            <span class="l-hadir">Hadir</span>
            <span class="l-izin">Izin</span>
            <span class="l-sakit">Sakit</span>
            <span class="l-alpa">Alpa</span>
        </div>
    </div>

    <section class="mb-4">
        <h2 class="yp-section-title"><i class="fa-solid fa-users-rectangle me-2"></i>Keaktifan Kelas <span class="fw-normal text-muted">(Masuk/Total)</span></h2>
        <button type="button" class="btn yp-mobile-toggle mb-2" data-target="yp-detail-kelas" aria-expanded="false">
            <i class="fa-solid fa-list me-1"></i>Lihat detail
        </button>
        <div id="yp-detail-kelas" class="yp-mobile-detail">
        <?php if ($byTingkatan === []): ?>
            <div class="yp-empty-inline">Belum ada data kelas untuk filter ini.</div>
        <?php else: ?>
            <div class="yp-kelas-grid">
                <?php foreach ($byTingkatan as $tk): ?>
                    <?php
                    $full = (int) $tk['masuk'] === (int) $tk['total'] && (int) $tk['total'] > 0;
                    $kelasQs = ['tanggal' => $tanggal, 'tingkatan' => (string) ($tk['tingkatan'] ?? '')];
                    if ($kategori !== null) {
                        $kelasQs['kategori'] = $kategori;
                    }
                    $kelasHref = app_href('/yayasan/keaktifan_kelas.php?' . http_build_query($kelasQs));
                    ?>
                    <a class="yp-kelas-card text-decoration-none<?= $full ? ' yp-kelas-card--full' : '' ?>" href="<?= htmlspecialchars($kelasHref) ?>">
                        <div class="yp-kelas-card__head">
                            <div class="yp-kelas-card__tk">Kelas <?= htmlspecialchars((string) $tk['tingkatan']) ?></div>
                            <div class="yp-kelas-card__pct"><?= (int) round((float) ($tk['persen'] ?? 0)) ?>%</div>
                        </div>
                        <div class="yp-kelas-card__ratio"><strong><?= (int) $tk['masuk'] ?></strong>/<?= (int) $tk['total'] ?></div>
                        <div class="yp-kelas-card__sub">Santri hadir hari ini</div>
                        <div class="progress mt-2" style="height:6px">
                            <div class="progress-bar <?= $full ? 'bg-success' : 'bg-primary' ?>" style="width:<?= (float) $tk['persen'] ?>%"></div>
                        </div>
                        <?php if ($full): ?><div class="yp-kelas-card__badge"><i class="fa-solid fa-circle-check me-1"></i>Lengkap</div><?php else: ?><div class="yp-kelas-card__badge yp-kelas-card__badge--soft"><i class="fa-solid fa-chart-line me-1"></i>Perlu dipantau</div><?php endif; ?>
                        <div class="small text-primary mt-2"><i class="fa-solid fa-arrow-right me-1"></i>Lihat detail santri</div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </div>
    </section>

    <section class="mb-4">
        <h2 class="yp-section-title"><i class="fa-solid fa-user-tie me-2"></i>Pembimbing & Munawib <span class="fw-normal text-muted">(Masuk/Total)</span></h2>
        <button type="button" class="btn yp-mobile-toggle mb-2" data-target="yp-detail-sdm" aria-expanded="false">
            <i class="fa-solid fa-list me-1"></i>Lihat detail
        </button>
        <div id="yp-detail-sdm" class="yp-mobile-detail">
        <div class="row g-3">
            <div class="col-md-6">
                <a class="yp-sdm-card" href="<?= htmlspecialchars(app_href('/yayasan/sdm_hari.php?role=pembimbing&tanggal=' . urlencode($tanggal))) ?>">
                    <div class="yp-sdm-card__head">
                        <span class="yp-sdm-card__label">Pembimbing</span>
                        <span class="yp-sdm-card__pct"><?= $pbPct ?>%</span>
                    </div>
                    <div class="yp-sdm-card__ratio"><?= (int) $pb['masuk'] ?>/<?= (int) $pb['total'] ?></div>
                    <div class="progress mt-2" style="height:6px"><div class="progress-bar bg-teal" style="width:<?= $pbPct ?>%;background:#0f766e"></div></div>
                    <div class="yp-sdm-card__hint">Ketuk untuk lihat yang belum datang <i class="fa-solid fa-arrow-right ms-1"></i></div>
                </a>
            </div>
            <div class="col-md-6">
                <a class="yp-sdm-card" href="<?= htmlspecialchars(app_href('/yayasan/sdm_hari.php?role=munawib&tanggal=' . urlencode($tanggal))) ?>">
                    <div class="yp-sdm-card__head">
                        <span class="yp-sdm-card__label">Munawib</span>
                        <span class="yp-sdm-card__pct"><?= $mwPct ?>%</span>
                    </div>
                    <div class="yp-sdm-card__ratio"><?= (int) $mw['masuk'] ?>/<?= (int) $mw['total'] ?></div>
                    <div class="progress mt-2" style="height:6px"><div class="progress-bar" style="width:<?= $mwPct ?>%;background:#0891b2"></div></div>
                    <div class="yp-sdm-card__hint">Ketuk untuk lihat yang belum datang <i class="fa-solid fa-arrow-right ms-1"></i></div>
                </a>
            </div>
        </div>
        </div>
    </section>

    <section class="mb-4">
        <h2 class="yp-section-title"><i class="fa-solid fa-clipboard-user me-2"></i>Riwayat Pembimbing Masuk</h2>
        <button type="button" class="btn yp-mobile-toggle mb-2" data-target="yp-detail-riwayat" aria-expanded="false">
            <i class="fa-solid fa-list me-1"></i>Lihat detail
        </button>
        <div id="yp-detail-riwayat" class="yp-mobile-detail">
        <?php if ($riwayatPembimbingMasuk === []): ?>
            <div class="yp-empty-inline">Belum ada pembimbing yang scan masuk pada tanggal ini.</div>
        <?php else: ?>
            <div class="yp-riwayat-list">
                <?php foreach ($riwayatPembimbingMasuk as $rb): ?>
                    <article class="yp-riwayat-item">
                        <div class="yp-riwayat-item__main">
                            <div class="yp-riwayat-item__nama"><?= htmlspecialchars((string) ($rb['nama'] ?? '-')) ?></div>
                            <div class="yp-riwayat-item__meta">
                                <?= htmlspecialchars((string) ($rb['kegiatan'] ?? 'Kegiatan')) ?>
                                · <?= htmlspecialchars((string) (($rb['tingkatan'] ?? '') !== '' ? $rb['tingkatan'] : '-')) ?>
                                <?php if (!empty($rb['tempat'])): ?> · <?= htmlspecialchars((string) $rb['tempat']) ?><?php endif; ?>
                            </div>
                        </div>
                        <div class="yp-riwayat-item__time"><?= htmlspecialchars((string) ($rb['jam'] ?? '--:--')) ?></div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </div>
    </section>

    <section class="mb-4">
        <h2 class="yp-section-title"><i class="fa-solid fa-triangle-exclamation me-2"></i>Kegiatan Kosong / Waspada</h2>
        <button type="button" class="btn yp-mobile-toggle mb-2" data-target="yp-detail-kosong" aria-expanded="false">
            <i class="fa-solid fa-list me-1"></i>Lihat detail
        </button>
        <div id="yp-detail-kosong" class="yp-mobile-detail">
        <?php if ($kegiatanKosong === []): ?>
            <div class="yp-empty-inline">Tidak ada kegiatan kosong. Semua kegiatan memiliki progres kehadiran.</div>
        <?php else: ?>
            <div class="yp-kosong-grid">
                <?php foreach ($kegiatanKosong as $kk): ?>
                    <article class="yp-kosong-card">
                        <div class="yp-kosong-card__title"><?= htmlspecialchars((string) ($kk['nama_kegiatan'] ?? 'Kegiatan')) ?></div>
                        <div class="yp-kosong-card__meta">
                            <?= htmlspecialchars((string) ($kk['jam_mulai'] ?? '--:--')) ?>-<?= htmlspecialchars((string) ($kk['jam_selesai'] ?? '--:--')) ?>
                            · <?= htmlspecialchars((string) (($kk['tingkatan'] ?? '') !== '' ? $kk['tingkatan'] : '-')) ?>
                            <?php if (!empty($kk['tempat'])): ?> · <?= htmlspecialchars((string) $kk['tempat']) ?><?php endif; ?>
                        </div>
                        <div class="yp-kosong-card__pb">Pembimbing: <?= htmlspecialchars((string) (($kk['nama_pembimbing'] ?? '') !== '' ? $kk['nama_pembimbing'] : '-')) ?></div>
                        <div class="yp-kosong-card__stats">
                            Santri hadir <?= (int) ($kk['santri_hadir'] ?? 0) ?>/<?= (int) ($kk['santri_total'] ?? 0) ?>
                            · Pembimbing <?= !empty($kk['pembimbing_hadir']) ? 'Hadir' : 'Belum' ?>
                            · Munawib <?= !empty($kk['munawib_hadir']) ? 'Hadir' : 'Belum' ?>
                        </div>
                        <ul class="yp-kosong-card__reason">
                            <?php foreach ((array) ($kk['reasons'] ?? []) as $reason): ?>
                                <li><?= htmlspecialchars((string) $reason) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </div>
    </section>

    <section class="mb-4">
        <h2 class="yp-section-title"><i class="fa-solid fa-qrcode me-2"></i>Kegiatan tanpa scan hadir</h2>
        <button type="button" class="btn yp-mobile-toggle mb-2" data-target="yp-detail-tanpa-scan" aria-expanded="false">
            <i class="fa-solid fa-list me-1"></i>Lihat detail
        </button>
        <div id="yp-detail-tanpa-scan" class="yp-mobile-detail">
        <?php if ($kegiatanTanpaScan === []): ?>
            <div class="yp-empty-inline">Semua kegiatan terjadwal hari ini sudah memiliki scan hadir.</div>
        <?php else: ?>
            <div class="yp-kosong-grid">
                <?php foreach ($kegiatanTanpaScan as $kts): ?>
                    <article class="yp-kosong-card">
                        <div class="yp-kosong-card__title"><?= htmlspecialchars((string) ($kts['nama_kegiatan'] ?? 'Kegiatan')) ?></div>
                        <div class="yp-kosong-card__meta">
                            Tingkatan: <?= htmlspecialchars((string) ($kts['tingkatan_label'] ?? '-')) ?>
                            · Slot terjadwal: <?= (int) ($kts['slot_jadwal'] ?? 0) ?>
                        </div>
                        <div class="yp-kosong-card__stats text-warning">
                            Belum ada satupun santri yang scan <strong>hadir</strong> pada hari ini.
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </div>
    </section>

    <?php if ($detailKeg === []): ?>
        <div class="card shadow-sm border-0">
            <div class="card-body text-center text-muted py-5">
                <div class="display-6 mb-2 opacity-50"><i class="fa-regular fa-calendar-xmark"></i></div>
                <p class="mb-0 fw-semibold">Tidak ada kegiatan aktif untuk filter ini</p>
                <p class="small mb-0">Ubah kategori Jama'ah/Ta'lim, tanggal, atau tingkatan.</p>
            </div>
        </div>
    <?php else: ?>

    <section class="mb-2">
        <h2 class="yp-section-title"><i class="fa-solid fa-mosque me-2"></i>Shalat & Kegiatan</h2>
    </section>

    <?php
    $khShowHero = false;
    require __DIR__ . '/../includes/partials/keaktifan_hari_kegiatan_cards.php';
    ?>

    <?php endif; ?>
</div>

<script>
(function () {
    var mobileQuery = globalThis.matchMedia ? globalThis.matchMedia('(max-width: 767.98px)') : null;

    function syncMobileDetails() {
        var isMobile = mobileQuery ? mobileQuery.matches : (window.innerWidth <= 767);
        document.querySelectorAll('.yp-mobile-detail').forEach(function (box) {
            box.classList.toggle('is-open', !isMobile);
        });
        document.querySelectorAll('.yp-mobile-toggle').forEach(function (btn) {
            var targetId = btn.getAttribute('data-target') || '';
            var box = targetId ? document.getElementById(targetId) : null;
            if (!box) return;
            btn.classList.toggle('d-none', !isMobile);
            btn.setAttribute('aria-expanded', box.classList.contains('is-open') ? 'true' : 'false');
            btn.innerHTML = box.classList.contains('is-open')
                ? '<i class="fa-solid fa-eye-slash me-1"></i>Sembunyikan detail'
                : '<i class="fa-solid fa-list me-1"></i>Lihat detail';
        });
    }

    document.querySelectorAll('.yp-mobile-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var targetId = btn.getAttribute('data-target') || '';
            var box = targetId ? document.getElementById(targetId) : null;
            if (!box) return;
            var willOpen = !box.classList.contains('is-open');
            var isMobile = mobileQuery ? mobileQuery.matches : (window.innerWidth <= 767);
            if (isMobile && willOpen) {
                document.querySelectorAll('.yp-mobile-detail').forEach(function (otherBox) {
                    if (otherBox !== box) {
                        otherBox.classList.remove('is-open');
                    }
                });
            }
            box.classList.toggle('is-open', willOpen);
            btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            btn.innerHTML = willOpen
                ? '<i class="fa-solid fa-eye-slash me-1"></i>Sembunyikan detail'
                : '<i class="fa-solid fa-list me-1"></i>Lihat detail';
            if (isMobile && willOpen) {
                document.querySelectorAll('.yp-mobile-toggle').forEach(function (otherBtn) {
                    if (otherBtn === btn) return;
                    var otherTargetId = otherBtn.getAttribute('data-target') || '';
                    var otherBox = otherTargetId ? document.getElementById(otherTargetId) : null;
                    if (!otherBox) return;
                    otherBtn.setAttribute('aria-expanded', 'false');
                    otherBtn.innerHTML = '<i class="fa-solid fa-list me-1"></i>Lihat detail';
                });
            }
        });
    });
    syncMobileDetails();
    if (mobileQuery && typeof mobileQuery.addEventListener === 'function') {
        mobileQuery.addEventListener('change', syncMobileDetails);
    } else {
        window.addEventListener('resize', syncMobileDetails);
    }

})();
</script>

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
                <p class="mb-2"><span class="kh-panduan__item kh-panduan__item--alpa">Alpa</span> — tidak scan sampai jam kegiatan selesai (tanpa izin resmi).</p>
                <p class="mb-0">Geser tab kegiatan ke kiri/kanan. Ketuk <strong>Daftar santri</strong> pada kartu untuk melihat nama lengkap.</p>
            </div>
        </div>
    </div>
</div>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/keaktifan-hari.js')) ?>"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
