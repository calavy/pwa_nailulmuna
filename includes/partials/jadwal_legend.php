<?php

declare(strict_types=1);

/**
 * Legenda singkat tampilan jadwal.
 *
 * @var string $jadwalDensity comfort|full
 * @var string $activeTab
 */
$jadwalDensity = ($jadwalDensity ?? 'comfort') === 'full' ? 'full' : 'comfort';
$activeTab = $activeTab ?? 'minggu';
$legendCollapsedDefault = $jadwalDensity === 'comfort' && in_array($activeTab, ['minggu', 'daftar'], true);
$legendCollapseId = 'jadwalLegendCollapse';
?>
<div class="jadwal-legend mb-3">
    <?php if ($legendCollapsedDefault): ?>
    <button class="btn btn-link btn-sm p-0 jadwal-legend__toggle text-decoration-none" type="button"
            data-bs-toggle="collapse" data-bs-target="#<?= htmlspecialchars($legendCollapseId) ?>"
            aria-expanded="false" aria-controls="<?= htmlspecialchars($legendCollapseId) ?>">
        <i class="fa-solid fa-circle-info me-1" aria-hidden="true"></i>Keterangan warna
    </button>
    <div class="collapse" id="<?= htmlspecialchars($legendCollapseId) ?>">
    <?php endif; ?>
    <div class="jadwal-legend__items<?= $legendCollapsedDefault ? ' mt-2' : '' ?>">
        <span class="jadwal-legend__item">
            <span class="jadwal-kat-dot jadwal-kat-dot--taalim" aria-hidden="true"></span>
            Ta'lim
        </span>
        <span class="jadwal-legend__item">
            <span class="jadwal-kat-dot jadwal-kat-dot--jamaah" aria-hidden="true"></span>
            Jama'ah
        </span>
        <span class="jadwal-legend__item">
            <span class="jadwal-kat-dot jadwal-kat-dot--extra" aria-hidden="true"></span>
            Extra (opsional)
        </span>
        <span class="jadwal-legend__item text-muted">
            <i class="fa-solid fa-clock me-1"></i>Format 24 jam
        </span>
        <span class="jadwal-legend__item text-muted d-none d-md-inline">
            <i class="fa-solid fa-repeat me-1"></i>Jadwal harian = tampil Senin–Minggu
        </span>
    </div>
    <?php if ($legendCollapsedDefault): ?>
    </div>
    <?php endif; ?>
</div>
