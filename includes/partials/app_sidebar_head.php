<?php

declare(strict_types=1);

/**
 * Blok atas sidebar: identitas pondok (logo, almamater, alamat).
 *
 * @var string $appBrandTitle
 * @var string $appBrandTagline
 * @var string $appAlamatPonpes
 * @var string $appLogoSrc
 * @var string $appLogoInitial
 * @var bool $compact
 */
$compact = !empty($compact);
?>
<div class="app-sidebar-head<?= $compact ? ' app-sidebar-head--compact' : '' ?>">
    <div class="app-sidebar-pondok">
        <a href="<?= htmlspecialchars(app_href('/dashboard.php')) ?>" class="app-sidebar-pondok-link">
            <?php if ($appLogoSrc !== ''): ?>
                <div class="app-sidebar-pondok-logo-wrap">
                    <img src="<?= htmlspecialchars(app_href($appLogoSrc)) ?>" alt="Logo <?= htmlspecialchars($appBrandTitle) ?>" class="app-sidebar-pondok-logo" decoding="async">
                </div>
            <?php else: ?>
                <div class="app-sidebar-pondok-logo-wrap app-sidebar-pondok-logo-wrap--fallback" aria-hidden="true">
                    <span class="app-sidebar-pondok-logo-fallback"><?= htmlspecialchars($appLogoInitial) ?></span>
                </div>
            <?php endif; ?>
            <div class="app-sidebar-pondok-text min-w-0">
                <?php if ($appBrandTagline !== ''): ?>
                    <div class="app-sidebar-pondok-kicker"><?= htmlspecialchars($appBrandTagline) ?></div>
                <?php else: ?>
                    <div class="app-sidebar-pondok-kicker">Pesantren</div>
                <?php endif; ?>
                <div class="app-sidebar-pondok-title"><?= htmlspecialchars($appBrandTitle) ?></div>
                <?php if ($appAlamatPonpes !== ''): ?>
                    <p class="app-sidebar-pondok-alamat mb-0">
                        <i class="fa-solid fa-location-dot" aria-hidden="true"></i>
                        <span><?= htmlspecialchars($appAlamatPonpes) ?></span>
                    </p>
                <?php endif; ?>
            </div>
        </a>
    </div>
</div>
