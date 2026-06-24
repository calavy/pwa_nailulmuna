<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/rekap_periode.php';
require_once __DIR__ . '/../helpers/hijri_kalender.php';
require_once __DIR__ . '/../helpers/rekap_keaktifan.php';
require_once __DIR__ . '/../helpers/rekap_keaktifan_hari.php';
require_once __DIR__ . '/../helpers/yayasan.php';

require_roles(['admin', 'pengurus']);

if (!table_exists($pdo, 'presensi')) {
    set_flash('error', 'Tabel presensi belum ada.');
    header('Location: ' . app_href('/dashboard.php'));
    exit;
}

yayasan_ensure_tables($pdo);

$periode = rekap_resolve_periode($pdo, $_GET);
$mode = $periode['mode'];
$month = $periode['month'];
$year = $periode['year'];
$startDate = $periode['start_date'];
$endDate = $periode['end_date'];
$periodeLabel = $periode['label'];
$hijriMonths = hijri_nama_bulan_list();

$kategoriRaw = trim((string) ($_GET['kategori'] ?? ''));
$kategori = rekap_keaktifan_hari_normalize_kategori($kategoriRaw !== '' ? $kategoriRaw : null);
$kategoriLabel = match ($kategori) {
    'JAMAAH' => 'Jamaah',
    'TAALIM' => 'Taalim',
    'PKPPS' => 'PKPPS',
    default => 'Semua kategori',
};

$goodMax = (int) app_setting($pdo, 'kategori_baik_max', '1');
$mediumMax = (int) app_setting($pdo, 'kategori_sedang_max', '3');

$forceRefresh = isset($_GET['refresh']) && (string) $_GET['refresh'] === '1';
$ranking = rekap_keaktifan_rank_tingkatan_for_periode(
    $pdo,
    $startDate,
    $endDate,
    $goodMax,
    $mediumMax,
    $forceRefresh,
    $kategori,
    $periode['kalender_hijriyah_key'] ?? null
);

$chartPayload = rekap_keaktifan_rank_tingkatan_chart_payload($ranking);
$chartUid = 'rankTg' . substr(md5($startDate . $endDate . ($kategori ?? '')), 0, 8);
$openDetail = trim((string) ($_GET['tingkatan'] ?? ''));

$buildQuery = static function (array $overrides = []) use ($mode, $month, $year, $kategoriRaw, $openDetail): string {
    $q = [
        'mode' => $mode,
        'month' => $month,
        'year' => $year,
    ];
    if ($kategoriRaw !== '') {
        $q['kategori'] = $kategoriRaw;
    }
    if ($openDetail !== '' && !array_key_exists('tingkatan', $overrides)) {
        $q['tingkatan'] = $openDetail;
    }
    foreach ($overrides as $k => $v) {
        if ($v === null || $v === '') {
            unset($q[$k]);
        } else {
            $q[$k] = $v;
        }
    }

    return app_href('/yayasan/keaktifan_ranking.php?' . http_build_query($q));
};

$rankBadgeClass = static function (int $rank): string {
    return match ($rank) {
        1 => 'rekap-rank-pos rekap-rank-pos--1',
        2 => 'rekap-rank-pos rekap-rank-pos--2',
        3 => 'rekap-rank-pos rekap-rank-pos--3',
        default => 'rekap-rank-pos',
    };
};

$pageTitle = 'Ranking Keaktifan per Tingkatan';
$pageStylesheets = [app_asset_href('/assets/css/yayasan-portal.css')];
require_once __DIR__ . '/../includes/header.php';
?>
<div class="yp-wrap yp-rank-page">
<div class="page-intro mb-3">
    <p class="page-intro-kicker mb-1">Yayasan · Keaktifan</p>
    <h1 class="h4 mb-1">Ranking keaktifan per tingkatan</h1>
    <p class="text-muted mb-0 small">
        Urutan <strong>#1 terbaik</strong> di atas. Klik kartu tingkatan untuk melihat <strong>ranking santri</strong> di dalamnya.
        <a href="<?= htmlspecialchars(app_href('/yayasan/keaktifan.php')) ?>">Keaktifan hari ini</a>
        ·
        <a href="<?= htmlspecialchars(app_href('/rekap/santri_bagus.php')) ?>">Rekap lengkap</a>
    </p>
    <?php if ($ranking !== []): ?>
    <div class="yp-rank-hero-stats">
        <span class="yp-rank-stat-chip"><i class="fa-solid fa-layer-group"></i> <?= count($ranking) ?> tingkatan</span>
        <span class="yp-rank-stat-chip"><i class="fa-solid fa-calendar"></i> <?= htmlspecialchars($periodeLabel) ?></span>
        <span class="yp-rank-stat-chip"><i class="fa-solid fa-filter"></i> <?= htmlspecialchars($kategoriLabel) ?></span>
    </div>
    <?php endif; ?>
</div>

<?php
$showRefresh = true;
$refreshHref = $buildQuery(['refresh' => '1']);
$rekapPeriodeExtraSlot = '
    <div class="col-md-2 col-6">
        <label class="form-label small mb-0">Kategori kegiatan</label>
        <select class="form-select form-select-sm" name="kategori">
            <option value=""' . ($kategori === null ? ' selected' : '') . '>Semua</option>
            <option value="JAMAAH"' . ($kategori === 'JAMAAH' ? ' selected' : '') . '>Jamaah</option>
            <option value="TAALIM"' . ($kategori === 'TAALIM' ? ' selected' : '') . '>Taalim</option>
            <option value="PKPPS"' . ($kategori === 'PKPPS' ? ' selected' : '') . '>PKPPS</option>
        </select>
    </div>';
require __DIR__ . '/../includes/partials/rekap_kalender_bulan_filter.php';
unset($rekapPeriodeExtraSlot);
?>

<?php if ($ranking !== []): ?>
<div class="card shadow-sm mb-4 rekap-rank-chart-card">
    <div class="card-header bg-white border-0 pb-0">
        <h2 class="h6 mb-1">Grafik ranking tingkatan</h2>
        <p class="small text-muted mb-0">
            <?= htmlspecialchars($periodeLabel) ?> · <?= htmlspecialchars($kategoriLabel) ?> · terbaik di atas
        </p>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-12 col-xl-6">
                <p class="small fw-semibold text-secondary mb-2">% Hadir per tingkatan</p>
                <div class="position-relative rekap-rank-chart-wrap">
                    <canvas id="chart<?= htmlspecialchars($chartUid) ?>Hadir" aria-label="Grafik persentase hadir per tingkatan"></canvas>
                </div>
            </div>
            <div class="col-12 col-xl-6">
                <p class="small fw-semibold text-secondary mb-2">Komposisi kategori santri (%)</p>
                <div class="position-relative rekap-rank-chart-wrap">
                    <canvas id="chart<?= htmlspecialchars($chartUid) ?>Stacked" aria-label="Grafik komposisi kategori santri per tingkatan"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($ranking !== [] && count($ranking) >= 3): ?>
<?php
$podiumSlots = [
    2 => ['class' => 'yp-rank-podium__item--2', 'medal' => '🥈'],
    1 => ['class' => 'yp-rank-podium__item--1', 'medal' => '🥇'],
    3 => ['class' => 'yp-rank-podium__item--3', 'medal' => '🥉'],
];
?>
<section class="yp-rank-podium mb-2" aria-label="Tiga tingkatan terbaik">
    <?php foreach ($podiumSlots as $podiumRank => $podiumMeta):
        $podiumRow = null;
        foreach ($ranking as $rIdx => $r) {
            if ((int) ($r['rank'] ?? 0) === $podiumRank) {
                $podiumRow = $r;
                $podiumIdx = $rIdx;
                break;
            }
        }
        if ($podiumRow === null) {
            continue;
        }
        $pTk = (string) ($podiumRow['tingkatan'] ?? '-');
    ?>
    <button type="button" class="yp-rank-podium__item <?= htmlspecialchars($podiumMeta['class']) ?>" data-rank-detail="<?= (int) $podiumIdx ?>" title="Lihat ranking santri <?= htmlspecialchars($pTk) ?>">
        <div class="yp-rank-podium__medal" aria-hidden="true"><?= $podiumMeta['medal'] ?></div>
        <div class="yp-rank-podium__tk"><?= htmlspecialchars($pTk) ?></div>
        <div class="yp-rank-podium__pct"><?= number_format((float) ($podiumRow['persen_hadir'] ?? 0), 1, ',', '.') ?>%</div>
        <div class="yp-rank-podium__sub"><?= (int) ($podiumRow['santri_count'] ?? 0) ?> santri · #<?= $podiumRank ?></div>
    </button>
    <?php endforeach; ?>
</section>
<?php endif; ?>

<div class="card shadow-sm border-0 mb-4 rekap-rank-card">
    <div class="card-header bg-white border-bottom-0 d-flex flex-wrap justify-content-between align-items-center gap-2 py-3">
        <div>
            <h2 class="h6 mb-0">Peringkat tingkatan</h2>
            <p class="small text-muted mb-0">Klik kartu untuk buka ranking santri · terbaik di atas</p>
        </div>
        <div class="d-flex flex-wrap gap-1">
            <span class="badge text-bg-success">#1 Terbaik</span>
            <span class="badge text-bg-secondary">↓ menurun</span>
            <span class="badge text-bg-danger">Terburuk</span>
        </div>
    </div>
    <div class="card-body">
        <?php if ($ranking === []): ?>
            <div class="yp-empty-inline text-center">Belum ada data pada periode dan kategori ini.</div>
        <?php else: ?>
        <div class="yp-rank-board">
            <?php
            $lastRank = count($ranking);
            foreach ($ranking as $idx => $row):
                $kat = $row['kategori'] ?? [];
                $tingkatanNama = (string) ($row['tingkatan'] ?? '-');
                $detailId = 'rank-detail-' . $idx;
                $isOpen = $openDetail !== '' && strcasecmp($openDetail, $tingkatanNama) === 0;
                $santriList = $row['santri_list'] ?? [];
                $rankNum = (int) ($row['rank'] ?? 0);
                $persenHadir = (float) ($row['persen_hadir'] ?? 0);
                $isWorst = $rankNum === $lastRank && $lastRank > 1;
                $santriLastRank = count($santriList);
            ?>
            <article class="yp-rank-tingkatan-wrap">
                <div
                    class="yp-rank-tingkatan rekap-rank-summary<?= $isOpen ? ' is-expanded' : '' ?><?= $isWorst ? ' yp-rank-tingkatan--worst' : '' ?>"
                    role="button"
                    tabindex="0"
                    data-rank-detail="<?= (int) $idx ?>"
                    data-tingkatan="<?= htmlspecialchars($tingkatanNama) ?>"
                    aria-expanded="<?= $isOpen ? 'true' : 'false' ?>"
                    aria-controls="<?= htmlspecialchars($detailId) ?>"
                >
                    <div class="yp-rank-tingkatan__main">
                        <span class="<?= htmlspecialchars($rankBadgeClass($rankNum)) ?>"><?= $rankNum ?></span>
                        <div class="min-w-0">
                            <h3 class="yp-rank-tingkatan__name">
                                <?= htmlspecialchars($tingkatanNama) ?>
                                <?php if ($rankNum === 1): ?>
                                    <span class="badge text-bg-success ms-1">Terbaik</span>
                                <?php elseif ($isWorst): ?>
                                    <span class="badge text-bg-danger ms-1">Terburuk</span>
                                <?php endif; ?>
                            </h3>
                            <div class="yp-rank-tingkatan__meta">
                                <?= (int) ($row['santri_count'] ?? 0) ?> santri
                                · Bagus <?= (int) ($kat['Bagus'] ?? 0) ?>
                                · Buruk <?= (int) ($kat['Buruk'] ?? 0) ?>
                            </div>
                        </div>
                    </div>
                    <div class="yp-rank-tingkatan__meter">
                        <div class="yp-rank-progress" aria-hidden="true">
                            <div class="yp-rank-progress__fill" style="width: <?= min(100, max(0, $persenHadir)) ?>%"></div>
                        </div>
                        <div class="yp-rank-tingkatan__pct"><?= number_format($persenHadir, 2, ',', '.') ?>% hadir</div>
                    </div>
                    <div class="yp-rank-tingkatan__counts">
                        <span class="yp-rank-count yp-rank-count--h">H <?= (int) ($row['hadir'] ?? 0) ?></span>
                        <span class="yp-rank-count">I <?= (int) ($row['izin'] ?? 0) ?></span>
                        <span class="yp-rank-count">S <?= (int) ($row['sakit'] ?? 0) ?></span>
                        <span class="yp-rank-count yp-rank-count--a">A <?= (int) ($row['alpa'] ?? 0) ?></span>
                    </div>
                    <i class="fa-solid fa-chevron-down yp-rank-tingkatan__chev rekap-rank-chevron" aria-hidden="true"></i>
                </div>
                <div class="rekap-rank-detail yp-rank-santri-panel<?= $isOpen ? ' is-open' : '' ?>" id="<?= htmlspecialchars($detailId) ?>">
                    <div class="rekap-rank-detail-inner">
                        <div class="yp-rank-santri-head">
                            <h4 class="yp-rank-santri-head__title">Ranking santri — <?= htmlspecialchars($tingkatanNama) ?></h4>
                            <span class="small text-muted"><?= htmlspecialchars($kategoriLabel) ?> · #1 terbaik → #<?= $santriLastRank ?> terburuk</span>
                        </div>
                        <?php if ($santriList === []): ?>
                            <div class="yp-empty-inline text-center">Tidak ada santri pada tingkatan ini.</div>
                        <?php else: ?>
                        <div class="yp-rank-santri-list">
                            <?php foreach ($santriList as $santri):
                                $sRank = (int) ($santri['rank'] ?? 0);
                                $sPersen = (float) ($santri['persen_hadir'] ?? 0);
                                $katS = (string) ($santri['kategori'] ?? '-');
                                $sRowClass = match (true) {
                                    $sRank === 1 => 'yp-rank-santri-row--top1',
                                    $sRank === 2 => 'yp-rank-santri-row--top2',
                                    $sRank === 3 => 'yp-rank-santri-row--top3',
                                    $sRank === $santriLastRank && $santriLastRank > 1 => 'yp-rank-santri-row--worst',
                                    default => '',
                                };
                            ?>
                            <div class="yp-rank-santri-row <?= htmlspecialchars($sRowClass) ?>">
                                <span class="yp-rank-santri-pos"><?= $sRank ?></span>
                                <div class="min-w-0">
                                    <div class="yp-rank-santri-name"><?= htmlspecialchars((string) ($santri['nama_santri'] ?? '-')) ?></div>
                                    <div class="yp-rank-santri-nis"><?= htmlspecialchars((string) ($santri['nis'] ?? '')) ?></div>
                                </div>
                                <div class="yp-rank-tingkatan__meter">
                                    <div class="yp-rank-progress" aria-hidden="true">
                                        <div class="yp-rank-progress__fill" style="width: <?= min(100, max(0, $sPersen)) ?>%"></div>
                                    </div>
                                    <div class="yp-rank-tingkatan__pct"><?= number_format($sPersen, 1, ',', '.') ?>%</div>
                                </div>
                                <div class="yp-rank-santri-mini">
                                    <span>H <strong><?= (int) ($santri['hadir'] ?? 0) ?></strong></span>
                                    <span>I <strong><?= (int) ($santri['izin'] ?? 0) ?></strong></span>
                                    <span>S <strong><?= (int) ($santri['sakit'] ?? 0) ?></strong></span>
                                    <span class="text-danger">A <strong><?= (int) ($santri['alpa'] ?? 0) ?></strong></span>
                                </div>
                                <span class="badge text-bg-<?= rekap_keaktifan_kategori_badge_class($katS) ?>"><?= htmlspecialchars($katS) ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>

<?php if ($ranking !== []): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script>
(function () {
    const labels = <?= json_encode($chartPayload['labels'], JSON_UNESCAPED_UNICODE) ?>;
    const persen = <?= json_encode($chartPayload['persen_hadir'], JSON_UNESCAPED_UNICODE) ?>;
    const barColors = <?= json_encode($chartPayload['bar_colors'], JSON_UNESCAPED_UNICODE) ?>;
    const stacked = <?= json_encode($chartPayload['stacked_datasets'], JSON_UNESCAPED_UNICODE) ?>;
    const uid = <?= json_encode($chartUid, JSON_UNESCAPED_UNICODE) ?>;

    if (typeof Chart === 'undefined' || !labels.length) {
        return;
    }

    const hadirEl = document.getElementById('chart' + uid + 'Hadir');
    if (hadirEl) {
        new Chart(hadirEl, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: '% Hadir',
                    data: persen,
                    backgroundColor: barColors,
                    borderRadius: 4,
                    barThickness: 18
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) { return ctx.parsed.x.toFixed(2) + '% hadir'; }
                        }
                    }
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        max: 100,
                        ticks: { callback: function (v) { return v + '%'; } }
                    },
                    y: {
                        ticks: { font: { size: 11 } }
                    }
                }
            }
        });
    }

    const stackedEl = document.getElementById('chart' + uid + 'Stacked');
    if (stackedEl) {
        new Chart(stackedEl, {
            type: 'bar',
            data: { labels: labels, datasets: stacked },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        stacked: true,
                        beginAtZero: true,
                        max: 100,
                        ticks: { callback: function (v) { return v + '%'; } }
                    },
                    y: { stacked: true, ticks: { font: { size: 11 } } }
                },
                plugins: {
                    legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } }
                }
            }
        });
    }
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
