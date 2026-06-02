<?php

declare(strict_types=1);

/**
 * Blok logo + nama pesantren di hero dashboard.
 *
 * @var string $brandTitle
 * @var string $brandKicker
 * @var string $brandLogoHref
 * @var string $brandLogoInitial
 */
$brandTitle = trim((string) ($brandTitle ?? ''));
$brandKicker = trim((string) ($brandKicker ?? ''));
$brandLogoHref = trim((string) ($brandLogoHref ?? ''));
$brandLogoInitial = trim((string) ($brandLogoInitial ?? 'AP')) ?: 'AP';
if ($brandTitle === '' && $brandLogoHref === '') {
    return;
}
?>
<div class="dash-hero-brand dash-hero-brand--top mb-3">
    <?php if ($brandLogoHref !== ''): ?>
        <div class="dash-hero-logo-wrap">
            <img
                src="<?= htmlspecialchars($brandLogoHref) ?>"
                alt="Logo <?= htmlspecialchars($brandTitle !== '' ? $brandTitle : 'Pesantren') ?>"
                class="dash-hero-logo"
                decoding="async"
                fetchpriority="high"
                data-pondok-cache="1"
            >
        </div>
    <?php else: ?>
        <div class="dash-hero-logo-wrap dash-hero-logo-wrap--placeholder" aria-hidden="true">
            <span class="dash-hero-logo-fallback"><?= htmlspecialchars($brandLogoInitial) ?></span>
        </div>
    <?php endif; ?>
    <div class="dash-hero-brand-text min-w-0">
        <?php if ($brandKicker !== ''): ?>
            <div class="dash-hero-pesantren-kicker text-white-50"><?= htmlspecialchars($brandKicker) ?></div>
        <?php endif; ?>
        <?php if ($brandTitle !== ''): ?>
            <div class="dash-hero-pesantren text-white"><?= htmlspecialchars($brandTitle) ?></div>
        <?php endif; ?>
    </div>
</div>
