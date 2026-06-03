<?php

declare(strict_types=1);

/** @var string $setoranNavActive home|scan|perolehan|keaktivan */

$setoranNavActive = $setoranNavActive ?? 'home';
?>
<nav class="pb-dash-menu-cards pb-dash-menu-cards--setoran-only" aria-label="Menu portal setoran">
    <a href="<?= htmlspecialchars(app_href('/pembimbing/setoran.php')) ?>"
       class="pb-dash-menu-card pb-dash-menu-card--scan<?= $setoranNavActive === 'scan' ? ' is-active' : '' ?>">
        <span class="pb-dash-menu-card__icon" aria-hidden="true"><i class="fa-solid fa-qrcode"></i></span>
        <span class="pb-dash-menu-card__label">Scan setoran</span>
    </a>
    <a href="<?= htmlspecialchars(app_href('/pembimbing/setoran_keaktivan.php')) ?>"
       class="pb-dash-menu-card pb-dash-menu-card--keaktifan<?= $setoranNavActive === 'keaktivan' ? ' is-active' : '' ?>">
        <span class="pb-dash-menu-card__icon" aria-hidden="true"><i class="fa-solid fa-chart-line"></i></span>
        <span class="pb-dash-menu-card__label pb-dash-menu-card__label--wrap">Keaktivan setoran</span>
    </a>
    <a href="<?= htmlspecialchars(app_href('/pembimbing/setoran_perolehan.php')) ?>"
       class="pb-dash-menu-card pb-dash-menu-card--setoran<?= $setoranNavActive === 'perolehan' ? ' is-active' : '' ?>">
        <span class="pb-dash-menu-card__icon" aria-hidden="true"><i class="fa-solid fa-book-open"></i></span>
        <span class="pb-dash-menu-card__label pb-dash-menu-card__label--wrap">Perolehan setoran</span>
    </a>
</nav>
