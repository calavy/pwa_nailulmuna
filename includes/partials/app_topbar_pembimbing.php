<?php



declare(strict_types=1);



/**

 * Topbar portal pembimbing (mobile-first). Variabel dari includes/header.php:

 * $pageTitleHeader, $topbarBackHref, $topbarBackLabel, $appLogoHref, $appLogoInitial,

 * $appBrandTitle, $currentUser, $currentUserRow, $isPbPortalHome, $pbTopbarIdentity

 */

$pbTopbarHomeHref = app_href('/pembimbing/dashboard.php');

$pbIdentity = is_array($pbTopbarIdentity ?? null) ? $pbTopbarIdentity : [];

$pbIdentityName = trim((string) ($pbIdentity['name'] ?? $currentUser ?? 'Pembimbing'));

$pbIdentityTime = (string) ($pbIdentity['time'] ?? date('H:i:s'));

$pbIdentityDate = (string) ($pbIdentity['date'] ?? '');

$pbIdentityPasaran = trim((string) ($pbIdentity['pasaran'] ?? ''));

$pbIdentityHijri = trim((string) ($pbIdentity['hijri'] ?? ''));

$pbIdentityPresence = trim((string) ($pbIdentity['presence_label'] ?? 'Pembimbing · bertugas hari ini'));

$pbIsMunawibTopbar = !empty($pbIdentity['is_munawib_portal']);

$pbScanStatus = trim((string) ($pbIdentity['scan_status'] ?? 'none'));

$pbScanLabel = trim((string) ($pbIdentity['scan_label'] ?? ''));

if (!in_array($pbScanStatus, ['none', 'pending', 'done'], true)) {

    $pbScanStatus = 'none';

}



$pbTopbarActionsHtml = static function (bool $compactHome = false) use ($currentUser, $currentUserRow): void {

    if (!isset($_SESSION['user'])) {

        return;

    }

    ?>

    <div class="app-topbar-actions app-topbar-actions--pembimbing<?= $compactHome ? ' app-topbar-actions--pb-home-compact' : '' ?>" role="group" aria-label="Akun">

        <div class="dropdown app-topbar-profile-menu">

            <button type="button" class="app-topbar-pb-avatar dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="<?= htmlspecialchars($currentUser) ?>">

                <?= user_profil_render_avatar($currentUserRow, 'app-user-avatar--sm') ?>

            </button>

            <ul class="dropdown-menu dropdown-menu-end app-topbar-profile-dropdown shadow">

                <li>

                    <a class="dropdown-item" href="<?= htmlspecialchars(app_href('/settings/profil.php')) ?>">

                        <i class="fa-solid fa-user me-2 opacity-75" aria-hidden="true"></i> Profil saya

                    </a>

                </li>

                <li>

                    <a class="dropdown-item" href="<?= htmlspecialchars(app_href('/settings/akses_saya.php')) ?>">

                        <i class="fa-solid fa-shield-halved me-2 opacity-75" aria-hidden="true"></i> Hak akses saya

                    </a>

                </li>

                <li><hr class="dropdown-divider"></li>

                <li>

                    <button type="button" class="dropdown-item js-fcm-subscribe" id="btn-fcm-subscribe">

                        <i class="fa-regular fa-bell me-2 opacity-75" aria-hidden="true"></i> Aktifkan notifikasi

                    </button>

                </li>

            </ul>

        </div>

        <a class="app-topbar-pb-logout" href="<?= htmlspecialchars(app_href('/logout.php')) ?>" title="Keluar">

            <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>

            <span class="visually-hidden">Keluar</span>

        </a>

    </div>

    <?php

};

?>

<header class="app-topbar app-topbar--pembimbing">

    <div class="app-topbar-inner app-topbar-inner--pembimbing<?= !empty($isPbPortalHome) ? ' app-topbar-inner--pb-home' : '' ?>">

        <?php if (!empty($isPbPortalHome)): ?>

            <div class="app-topbar-pb-home-top">
                <span class="app-topbar-pb-context-pill">Dashboard Pembimbing</span>
                <div class="app-topbar-right app-topbar-right--pembimbing app-topbar-right--pb-home">
                    <?php $pbTopbarActionsHtml(true); ?>
                </div>
            </div>

            <div class="app-topbar-pb-home-main">

                <div class="app-topbar-pb-home-profile">

                    <a href="<?= htmlspecialchars($pbTopbarHomeHref) ?>" class="app-topbar-pb-brand app-topbar-pb-brand--home" title="<?= htmlspecialchars($appBrandTitle) ?>">

                        <?php if ($appLogoHref !== ''): ?>

                            <img src="<?= htmlspecialchars($appLogoHref) ?>" alt="" class="app-topbar-pb-brand__logo app-topbar-pb-brand__logo--home" decoding="async" fetchpriority="high" data-pondok-cache="1">

                        <?php else: ?>

                            <span class="app-topbar-pb-brand__mark app-topbar-pb-brand__mark--home" aria-hidden="true"><?= htmlspecialchars($appLogoInitial) ?></span>

                        <?php endif; ?>

                    </a>

                    <div class="app-topbar-pb-identity app-topbar-pb-identity--home" aria-live="polite">

                        <?php if (trim($appBrandTitle) !== ''): ?>

                            <p class="app-topbar-pb-pondok-kicker mb-0"><?= htmlspecialchars($appBrandTitle) ?></p>

                        <?php endif; ?>

                        <p class="app-topbar-pb-name app-topbar-pb-name--home mb-0"><?= htmlspecialchars($pbIdentityName) ?></p>

                        <?php if ($pbIsMunawibTopbar && $pbIdentityPresence !== ''): ?>

                            <p class="app-topbar-pb-presence mb-0"><?= htmlspecialchars($pbIdentityPresence) ?></p>

                        <?php elseif (!$pbIsMunawibTopbar && $pbScanLabel !== ''): ?>

                            <p class="app-topbar-pb-presence app-topbar-pb-presence--scan-<?= htmlspecialchars($pbScanStatus) ?> mb-0"><?= htmlspecialchars($pbScanLabel) ?></p>

                        <?php endif; ?>

                    </div>

                </div>

            </div>

            <div class="app-topbar-pb-dateline app-topbar-pb-dateline--home">

                <span class="app-topbar-pb-clock__time" id="dashboard-live-clock"><?= htmlspecialchars($pbIdentityTime) ?></span>

                <span class="app-topbar-pb-clock__date" id="dashboard-live-date"<?= $pbIdentityPasaran !== '' ? ' data-pasaran="' . htmlspecialchars($pbIdentityPasaran) . '"' : '' ?><?= $pbIdentityHijri !== '' ? ' data-hijri="' . htmlspecialchars($pbIdentityHijri) . '"' : '' ?>><?= htmlspecialchars($pbIdentityDate) ?></span>

            </div>

        <?php else: ?>

            <div class="app-topbar-left app-topbar-left--pembimbing">

                <?php if ($topbarBackHref !== ''): ?>

                    <a class="app-topbar-pb-back" href="<?= htmlspecialchars($topbarBackHref) ?>" title="<?= htmlspecialchars($topbarBackLabel) ?>">

                        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>

                        <span class="app-topbar-pb-back__label"><?= htmlspecialchars($topbarBackLabel) ?></span>

                    </a>

                <?php endif; ?>

            </div>

            <div class="app-topbar-center app-topbar-center--pembimbing">

                <div class="app-topbar-pb-identity" aria-live="polite">

                    <?php if (trim($pageTitleHeader) !== ''): ?>

                        <p class="app-topbar-pb-module-title mb-0"><?= htmlspecialchars($pageTitleHeader) ?></p>

                    <?php endif; ?>

                    <p class="app-topbar-pb-name mb-0"><?= htmlspecialchars($pbIdentityName) ?></p>

                    <?php if ($pbIsMunawibTopbar && $pbIdentityPresence !== ''): ?>

                        <p class="app-topbar-pb-presence mb-0"><?= htmlspecialchars($pbIdentityPresence) ?></p>

                    <?php elseif (!$pbIsMunawibTopbar && $pbScanLabel !== ''): ?>

                        <p class="app-topbar-pb-presence app-topbar-pb-presence--scan-<?= htmlspecialchars($pbScanStatus) ?> mb-0"><?= htmlspecialchars($pbScanLabel) ?></p>

                    <?php endif; ?>

                    <div class="app-topbar-pb-dateline">

                        <span class="app-topbar-pb-clock__time" id="dashboard-live-clock"><?= htmlspecialchars($pbIdentityTime) ?></span>

                        <span class="app-topbar-pb-clock__date" id="dashboard-live-date"<?= $pbIdentityPasaran !== '' ? ' data-pasaran="' . htmlspecialchars($pbIdentityPasaran) . '"' : '' ?><?= $pbIdentityHijri !== '' ? ' data-hijri="' . htmlspecialchars($pbIdentityHijri) . '"' : '' ?>><?= htmlspecialchars($pbIdentityDate) ?></span>

                    </div>

                </div>

            </div>

            <div class="app-topbar-right app-topbar-right--pembimbing">

                <?php $pbTopbarActionsHtml(); ?>

            </div>

        <?php endif; ?>

    </div>

</header>


