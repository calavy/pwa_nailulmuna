<?php

declare(strict_types=1);

/** @var string $labelUser */
/** @var bool $pbSudahHadir */
/** @var string $pbDashHijriLabel */
/** @var string $pbDashPasaran */
/** @var bool $isMunawibPortal */
/** @var array<string,mixed> $pbBannerCfg */
/** @var string $pbBannerVariant */
/** @var int $totalSantri */
/** @var int $jumlahTingkatanHome */
/** @var bool $pbDashHasPkpps */
/** @var list<array<string,mixed>> $kegiatanAktifPresensi */

$pbBannerCfg = is_array($pbBannerCfg ?? null) ? $pbBannerCfg : pembimbing_portal_banner_defaults('default');
$pbBannerVariant = strtolower(trim((string) ($pbBannerVariant ?? 'default')));
$pattern = in_array((string) ($pbBannerCfg['pattern'] ?? 'dots'), ['dots', 'grid', 'rays', 'waves'], true)
    ? (string) $pbBannerCfg['pattern'] : 'dots';
$icon = trim((string) ($pbBannerCfg['icon'] ?? 'fa-chalkboard-user'));
if ($icon !== '' && !str_contains($icon, 'fa-')) {
    $icon = 'fa-solid fa-' . ltrim($icon, '-');
}
$hasLive = !empty($kegiatanAktifPresensi);
$cssVars = pembimbing_portal_banner_css_vars($pbBannerCfg);
$displayName = trim((string) ($pembimbingNama ?? $labelUser ?? ''));
if ($displayName === '') {
    $displayName = 'Pembimbing';
}
$pbBannerNip = trim((string) ($pbBannerNip ?? ''));
if ($pbBannerNip === '' && isset($pembimbingInfo) && is_array($pembimbingInfo)) {
    $pbBannerNip = trim((string) ($pembimbingInfo['nip'] ?? ''));
}
if ($pbBannerNip === '' && !empty($isMunawibPortal)) {
    $pbBannerNip = trim((string) ($_SESSION['user']['username'] ?? ''));
}
?>
<div class="pb-portal-banner pb-portal-banner--<?= htmlspecialchars($pbBannerVariant) ?> pb-portal-banner--pattern-<?= htmlspecialchars($pattern) ?><?= $hasLive ? ' pb-portal-banner--live' : '' ?>"
     style="<?= htmlspecialchars($cssVars) ?>">
    <div class="pb-portal-banner__decor" aria-hidden="true"></div>
    <div class="pb-portal-banner__inner">
        <div class="pb-portal-banner__head">
            <?php if (($appLogoHref ?? '') !== ''): ?>
                <div class="pb-portal-banner__logo-wrap" aria-hidden="true">
                    <img src="<?= htmlspecialchars((string) $appLogoHref) ?>" alt="" class="pb-portal-banner__logo" decoding="async" data-pondok-cache="1">
                </div>
            <?php else: ?>
                <div class="pb-portal-banner__icon-wrap" aria-hidden="true">
                    <i class="<?= htmlspecialchars($icon) ?>"></i>
                </div>
            <?php endif; ?>
            <div class="pb-portal-banner__identity">
                <div class="pb-portal-banner__title-row">
                    <h1 class="pb-portal-banner__title mb-0"><?= htmlspecialchars($displayName) ?></h1>
                    <?php if (!$isMunawibPortal): ?>
                        <span class="pb-portal-banner__badge <?= $pbSudahHadir ? 'is-hadir' : 'is-wait' ?>">
                            <i class="fa-solid <?= $pbSudahHadir ? 'fa-circle-check' : 'fa-clock' ?> me-1"></i>
                            <?= $pbSudahHadir ? 'Hadir' : 'Belum scan' ?>
                        </span>
                    <?php endif; ?>
                </div>
                <?php if ($pbBannerNip !== ''): ?>
                    <p class="pb-portal-banner__nip mb-0">NIP <?= htmlspecialchars($pbBannerNip) ?></p>
                <?php endif; ?>
            </div>
            <div class="pb-portal-banner__clock" aria-live="polite">
                <div class="pb-portal-banner__clock-time" id="dashboard-live-clock">--:--:--</div>
                <div class="pb-portal-banner__clock-date" id="dashboard-live-date"<?= ($pbDashPasaran ?? '') !== '' ? ' data-pasaran="' . htmlspecialchars((string) $pbDashPasaran) . '"' : '' ?><?= ($pbDashHijriClock ?? '') !== '' ? ' data-hijri="' . htmlspecialchars((string) $pbDashHijriClock) . '"' : '' ?>>—</div>
            </div>
        </div>
        <?php if (!$isMunawibPortal && ((int) ($totalSantri ?? 0) > 0 || (int) ($jumlahTingkatanHome ?? 0) > 0)): ?>
            <div class="pb-portal-banner__chips">
                <?php if ((int) ($jumlahTingkatanHome ?? 0) > 0): ?>
                    <span class="pb-portal-banner__chip"><i class="fa-solid fa-layer-group me-1"></i><?= (int) $jumlahTingkatanHome ?> tingkatan</span>
                <?php endif; ?>
                <?php if ((int) ($totalSantri ?? 0) > 0): ?>
                    <span class="pb-portal-banner__chip"><i class="fa-solid fa-user-graduate me-1"></i><?= (int) $totalSantri ?> santri</span>
                <?php endif; ?>
                <?php if (!empty($pbDashHasPkpps)): ?>
                    <span class="pb-portal-banner__chip pb-portal-banner__chip--pkpps"><i class="fa-solid fa-book-open me-1"></i>PKPPS</span>
                <?php endif; ?>
                <?php if ($hasLive): ?>
                    <span class="pb-portal-banner__chip pb-portal-banner__chip--live"><i class="fa-solid fa-circle me-1"></i>Kegiatan berlangsung</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
