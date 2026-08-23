<?php

declare(strict_types=1);

/**
 * Daftar ranking santri dalam satu tingkatan.
 *
 * @var list<array<string, mixed>> $santriList
 * @var string $tingkatanNama
 * @var string $kategoriLabel
 */
$santriList = $santriList ?? [];
$tingkatanNama = (string) ($tingkatanNama ?? '-');
$kategoriLabel = (string) ($kategoriLabel ?? '');
$santriLastRank = count($santriList);
?>
<div class="rekap-rank-detail-inner">
    <div class="yp-rank-santri-head">
        <h4 class="yp-rank-santri-head__title">Ranking santri — <?= htmlspecialchars($tingkatanNama) ?></h4>
        <span class="small text-muted"><?= htmlspecialchars($kategoriLabel) ?><?= $kategoriLabel !== '' ? ' · ' : '' ?>#1 terbaik → #<?= $santriLastRank ?> terburuk</span>
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
                <span>T <strong><?= (int) ($santri['telat'] ?? 0) ?></strong></span>
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
