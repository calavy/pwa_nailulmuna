<?php

declare(strict_types=1);

/** @var string $homeUrl */
/** @var int $tahun */
/** @var string $tingkatanFilter */
/** @var array<string,int> $kategoriRingkas */
/** @var array{hadir:int,alpa:int} $statPresensi */
/** @var string $keaktifanView */
/** @var list<array<string,mixed>> $rekapPerKegiatan */
/** @var array<string,list<array<string,mixed>>> $keaktivanByTingkatan */
/** @var list<array<string,mixed>> $keaktivanRows */
/** @var list<string> $semuaTingkatanList */
?>
<section class="pb-keaktivan-page" aria-label="Keaktivan santri">
    <div class="pb-keaktivan-page__top">
        <h1 class="pb-keaktivan-page__title">Keaktivan Santri</h1>
        <p class="pb-keaktivan-page__sub text-muted mb-0">
            Rekap keaktifan berdasarkan presensi tahun <?= (int) $tahun ?>
            <?php if ($tingkatanFilter !== ''): ?>
                · tingkatan <?= htmlspecialchars($tingkatanFilter) ?>
            <?php elseif ($semuaTingkatanList !== []): ?>
                · <?= count($semuaTingkatanList) ?> tingkatan diasuh
            <?php endif; ?>
        </p>
        <form method="get" class="row g-2 align-items-end pb-keaktivan-page__filter">
            <?php if ($tingkatanFilter !== ''): ?><input type="hidden" name="tingkatan" value="<?= htmlspecialchars($tingkatanFilter) ?>"><?php endif; ?>
            <input type="hidden" name="view" value="keaktivan">
            <input type="hidden" name="mode" value="ringkas">
            <input type="hidden" name="keaktifan_view" value="<?= htmlspecialchars($keaktifanView) ?>">
            <div class="col-auto">
                <label class="form-label small mb-0" for="pb-keaktivan-tahun">Tahun</label>
                <input id="pb-keaktivan-tahun" type="number" name="tahun" class="form-control form-control-sm" min="2000" max="2100" value="<?= (int) $tahun ?>" style="width:6rem">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-outline-secondary">Terapkan</button>
            </div>
        </form>
    </div>

    <div class="pb-keaktivan-page__kpi pb-keaktifan-kpi" role="list" aria-label="Ringkasan kategori">
        <div class="pb-keaktifan-kpi__card pb-keaktifan-kpi__card--bagus" role="listitem">
            <div class="pb-keaktifan-kpi__label">Bagus</div>
            <div class="pb-keaktifan-kpi__value"><?= (int) $kategoriRingkas['bagus'] ?></div>
        </div>
        <div class="pb-keaktifan-kpi__card pb-keaktifan-kpi__card--sedang" role="listitem">
            <div class="pb-keaktifan-kpi__label">Sedang</div>
            <div class="pb-keaktifan-kpi__value"><?= (int) $kategoriRingkas['sedang'] ?></div>
        </div>
        <div class="pb-keaktifan-kpi__card pb-keaktifan-kpi__card--buruk" role="listitem">
            <div class="pb-keaktifan-kpi__label">Buruk</div>
            <div class="pb-keaktifan-kpi__value"><?= (int) $kategoriRingkas['buruk'] ?></div>
        </div>
        <div class="pb-keaktifan-kpi__card pb-keaktifan-kpi__card--alpa" role="listitem">
            <div class="pb-keaktifan-kpi__label">Alpa hari ini</div>
            <div class="pb-keaktifan-kpi__value"><?= (int) $statPresensi['alpa'] ?></div>
        </div>
    </div>

    <div class="card border-0 shadow-sm pb-keaktivan-page__rekap">
        <div class="card-body p-3 p-md-4">
            <?php
            $rekapPanelClass = 'pb-dash-rekap-keaktivan--page';
            $rekapFormMode = 'ringkas';
            $rekapDashView = 'keaktivan';
            require __DIR__ . '/rekap_keaktivan_inline.php';
            ?>
        </div>
    </div>
</section>
