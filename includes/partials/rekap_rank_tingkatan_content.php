<?php

declare(strict_types=1);

/**
 * @var list<array<string, mixed>> $ranking
 * @var string $chartUid
 * @var string $periodeLabel
 * @var string $kategoriLabel
 * @var string $openDetail
 * @var callable(int): string $rankBadgeClass
 */
if ($ranking !== []): ?>
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
                $rankNum = (int) ($row['rank'] ?? 0);
                $persenHadir = (float) ($row['persen_hadir'] ?? 0);
                $isWorst = $rankNum === $lastRank && $lastRank > 1;
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
                                · Baik <?= (int) ($kat['Baik'] ?? 0) ?>
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
                        <span class="yp-rank-count">T <?= (int) ($row['telat'] ?? 0) ?></span>
                        <span class="yp-rank-count">I <?= (int) ($row['izin'] ?? 0) ?></span>
                        <span class="yp-rank-count">S <?= (int) ($row['sakit'] ?? 0) ?></span>
                        <span class="yp-rank-count yp-rank-count--a">A <?= (int) ($row['alpa'] ?? 0) ?></span>
                    </div>
                    <i class="fa-solid fa-chevron-down yp-rank-tingkatan__chev rekap-rank-chevron" aria-hidden="true"></i>
                </div>
                <div class="rekap-rank-detail yp-rank-santri-panel<?= $isOpen ? ' is-open' : '' ?>" id="<?= htmlspecialchars($detailId) ?>" data-lazy-rank-detail="1" data-loaded="0">
                    <div class="rekap-rank-detail-inner rekap-rank-detail-placeholder">
                        <p class="small text-muted text-center py-3 mb-0"><i class="fa-solid fa-spinner fa-spin me-1 d-none rekap-rank-detail-spinner"></i><span class="rekap-rank-detail-hint">Ketuk kartu untuk memuat ranking santri…</span></p>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
