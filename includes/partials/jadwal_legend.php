<?php

declare(strict_types=1);

/**
 * Legenda singkat tampilan jadwal.
 *
 * @var string $jadwalDensity comfort|full
 * @var string $activeTab
 * @var string $viewKat taalim|jamaah
 */
$jadwalDensity = ($jadwalDensity ?? 'comfort') === 'full' ? 'full' : 'comfort';
$activeTab = $activeTab ?? 'minggu';
$viewKat = in_array(($viewKat ?? 'taalim'), ['taalim', 'jamaah'], true) ? ($viewKat ?? 'taalim') : 'taalim';
$legendCollapsedDefault = $jadwalDensity === 'comfort' && in_array($activeTab, ['minggu', 'daftar'], true);
$legendCollapseId = 'jadwalLegendCollapse';
$katLabel = $viewKat === 'jamaah' ? "Jama'ah" : "Ta'lim";
$dotClass = $viewKat === 'jamaah' ? 'jadwal-kat-dot--jamaah' : 'jadwal-kat-dot--taalim';
?>
<div class="jadwal-legend mb-3">
    <?php if ($legendCollapsedDefault): ?>
    <button class="btn btn-link btn-sm p-0 jadwal-legend__toggle text-decoration-none" type="button"
            data-bs-toggle="collapse" data-bs-target="#<?= htmlspecialchars($legendCollapseId) ?>"
            aria-expanded="false" aria-controls="<?= htmlspecialchars($legendCollapseId) ?>">
        <i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>Keterangan
    </button>
    <div class="collapse" id="<?= htmlspecialchars($legendCollapseId) ?>">
    <?php endif; ?>
    <div class="jadwal-legend__items<?= $legendCollapsedDefault ? ' mt-2' : '' ?>">
        <span class="jadwal-legend__item">
            <span class="jadwal-kat-dot <?= htmlspecialchars($dotClass) ?>" aria-hidden="true"></span>
            <?= htmlspecialchars($katLabel) ?>
        </span>
        <span class="jadwal-legend__item text-muted">
            <i class="fa-solid fa-hand-pointer me-1"></i>Ketuk grup untuk melihat slot
        </span>
        <span class="jadwal-legend__item text-muted">
            <i class="fa-solid fa-clock me-1"></i>Format 24 jam
        </span>
        <?php if ($viewKat === 'jamaah'): ?>
        <span class="jadwal-legend__item text-muted d-none d-md-inline">
            <i class="fa-solid fa-repeat me-1"></i>Jadwal harian = tampil Senin–Minggu
        </span>
        <?php endif; ?>
    </div>
    <?php if ($legendCollapsedDefault): ?>
    </div>
    <?php endif; ?>
</div>
