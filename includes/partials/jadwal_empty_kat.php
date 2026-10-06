<?php

declare(strict_types=1);

/** @var string $viewKat taalim|jamaah */
$viewKat = in_array(($viewKat ?? 'taalim'), ['taalim', 'jamaah'], true) ? ($viewKat ?? 'taalim') : 'taalim';
$katLabel = $viewKat === 'jamaah' ? "Jama'ah" : "Ta'lim";
$icon = $viewKat === 'jamaah' ? 'fa-mosque' : 'fa-book-open';
?>
<div class="jadwal-kat-empty text-center py-4">
    <div class="jadwal-kat-empty__ico mb-2 jadwal-kat-empty__ico--<?= htmlspecialchars($viewKat) ?>">
        <i class="fa-solid <?= htmlspecialchars($icon) ?>" aria-hidden="true"></i>
    </div>
    <p class="text-muted small mb-0">Belum ada jadwal <?= htmlspecialchars($katLabel) ?> untuk filter ini.</p>
</div>
