<?php

declare(strict_types=1);

require_once __DIR__ . '/../../helpers/app_path.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../partials/portal_hub_tabs.php';

/** @return list<array{href:string,icon:string,label:string,key:string}> */
function wali_bottom_nav_items(): array
{
    return [
        ['href' => app_href('/wali/index.php'), 'icon' => 'fa-house', 'label' => 'Beranda', 'key' => 'beranda'],
        ['href' => app_href('/wali/keuangan.php'), 'icon' => 'fa-wallet', 'label' => 'Keuangan', 'key' => 'keuangan'],
        ['href' => app_href('/wali/keaktifan.php'), 'icon' => 'fa-calendar-check', 'label' => 'Keaktivan', 'key' => 'keaktifan'],
        ['href' => app_href('/wali/izin.php'), 'icon' => 'fa-person-walking-arrow-right', 'label' => 'Izin', 'key' => 'izin'],
    ];
}

/** Menu tambahan — sheet "Lainnya" (mobile) & desktop scroll. */
function wali_more_nav_items(): array
{
    return [
        ['href' => app_href('/wali/riwayat.php'), 'icon' => 'fa-clock-rotate-left', 'label' => 'Riwayat santri', 'key' => 'riwayat'],
        ['href' => app_href('/wali/akademik.php'), 'icon' => 'fa-graduation-cap', 'label' => 'Akademik', 'key' => 'akademik'],
    ];
}

function wali_nav_resolve_active(?string $navActive): ?string
{
    if ($navActive === null || $navActive === '') {
        return $navActive;
    }
    if (in_array($navActive, ['tagihan', 'tagihan_lain', 'pembayaran', 'ringkasan', 'bayar'], true)) {
        return 'keuangan';
    }
    if (in_array($navActive, ['rapor', 'hafalan'], true)) {
        return 'akademik';
    }

    return $navActive;
}

function wali_nav_more_active(?string $navActive): bool
{
    $resolved = wali_nav_resolve_active($navActive);
    $moreKeys = array_column(wali_more_nav_items(), 'key');

    return in_array($resolved, $moreKeys, true);
}

/** @deprecated Gunakan wali_more_nav_items() */
function wali_extra_nav_items(): array
{
    return array_values(array_filter(wali_more_nav_items(), static fn(array $item): bool => in_array($item['key'], ['riwayat', 'akademik'], true)));
}

function wali_layout_head(string $title, bool $withManifest = true, ?string $navActive = null, array $loginBrand = []): void
{
    $showNav = $navActive !== null && $navActive !== '';
    $isLogin = !$showNav;
    $waliFlashOk = $showNav ? get_flash('success') : null;
    $waliFlashErr = $showNav ? get_flash('error') : null;
    $showLoginHero = $isLogin && $loginBrand !== [];
    $bodyClass = 'wali-portal py-3 py-md-4' . ($isLogin ? ' wali-portal--login' : '');
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="format-detection" content="telephone=no">
    <meta name="color-scheme" content="light">
    <?php if ($withManifest): ?>
        <link rel="manifest" href="<?= htmlspecialchars(app_href('/wali/manifest.php')) ?>">
        <meta name="theme-color" content="#0f766e">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="Portal Wali">
    <?php endif; ?>
    <?= app_pwa_icon_link_tags() ?>
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"></noscript>
    <?php require __DIR__ . '/../../includes/partials/app_vendor_assets.php'; ?>
    <link href="<?= htmlspecialchars(app_asset_href('/assets/css/app.css')) ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(app_asset_href('/assets/css/pwa-ui.css')) ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(app_asset_href('/assets/css/wali-portal.css')) ?>" rel="stylesheet">
    <?= pondok_ui_theme_head_html(isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO ? $GLOBALS['pdo'] : null) ?>
</head>
<body class="<?= htmlspecialchars($bodyClass) ?>">
    <div class="container wali-shell px-3">
    <?php if ($showLoginHero): ?>
        <?php
        $lbLogo = trim((string) ($loginBrand['logo_url'] ?? ''));
        $lbKicker = trim((string) ($loginBrand['kicker'] ?? ''));
        $lbNama = trim((string) ($loginBrand['nama_ponpes'] ?? ''));
        $lbWelcome = trim((string) ($loginBrand['welcome_line'] ?? ''));
        $lbHeadline = trim((string) ($loginBrand['headline'] ?? 'Portal Wali Santri'));
        $lbSubheadline = trim((string) ($loginBrand['subheadline'] ?? 'Lihat tagihan, presensi, dan perkembangan anak Anda.'));
        $letters = preg_replace('/[^A-Za-z]/u', '', $lbNama);
        $ini = strtoupper(substr($letters !== '' ? $letters : 'PW', 0, 2));
        ?>
        <div class="wali-login-hero">
            <?php if ($lbLogo !== ''): ?>
                <img class="wali-login-logo" src="<?= htmlspecialchars($lbLogo) ?>" alt="Logo pesantren" decoding="async">
            <?php elseif ($lbNama !== '' || $lbWelcome !== ''): ?>
                <div class="wali-login-initial" aria-hidden="true"><?= htmlspecialchars($ini) ?></div>
            <?php endif; ?>
            <?php if ($lbKicker !== ''): ?>
                <div class="wali-login-kicker"><?= htmlspecialchars($lbKicker) ?></div>
            <?php endif; ?>
            <?php if ($lbNama !== ''): ?>
                <div class="wali-login-ponpes"><?= htmlspecialchars($lbNama) ?></div>
            <?php endif; ?>
            <?php if ($lbWelcome !== ''): ?>
                <p class="wali-login-welcome"><?= htmlspecialchars($lbWelcome) ?></p>
            <?php else: ?>
                <h1 class="wali-login-title"><?= htmlspecialchars($lbHeadline) ?></h1>
            <?php endif; ?>
            <?php if ($lbSubheadline !== ''): ?>
                <p class="text-muted small mb-0 mt-2 px-1"><?= htmlspecialchars($lbSubheadline) ?></p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($showNav): ?>
        <?php if ($waliFlashOk): ?>
            <div class="alert alert-success py-2 small mb-2 shadow-sm" role="status"><?= htmlspecialchars($waliFlashOk) ?></div>
        <?php endif; ?>
        <?php if ($waliFlashErr): ?>
            <div class="alert alert-danger py-2 small mb-2 shadow-sm" role="alert"><?= htmlspecialchars($waliFlashErr) ?></div>
        <?php endif; ?>
        <nav class="wali-nav-scroll wali-nav-scroll--desktop-only mb-2" role="navigation" aria-label="Menu portal wali">
            <?php
            $navResolved = wali_nav_resolve_active($navActive);
            foreach (wali_bottom_nav_items() as $item):
            ?>
                <a href="<?= htmlspecialchars($item['href']) ?>" class="btn btn-sm btn-outline-secondary <?= $navResolved === $item['key'] ? 'active' : '' ?>"><?= htmlspecialchars($item['label']) ?></a>
            <?php endforeach; ?>
            <button type="button" class="btn btn-sm btn-outline-secondary <?= wali_nav_more_active($navActive) ? 'active' : '' ?>" data-bs-toggle="offcanvas" data-bs-target="#waliMoreNav" aria-controls="waliMoreNav">
                <i class="fa-solid fa-ellipsis me-1" aria-hidden="true"></i>Lainnya
            </button>
            <button type="button" class="btn btn-sm btn-outline-success" id="btn-fcm-subscribe" title="Aktifkan notifikasi push"><i class="fa-solid fa-bell"></i></button>
        </nav>
        <?php
        $waliAnakRows = $GLOBALS['waliAnakRows'] ?? [];
        $waliSantriId = (int) ($GLOBALS['waliSantriId'] ?? 0);
        $waliSwitcherLayout = (string) ($GLOBALS['waliSwitcherLayout'] ?? 'strip');
        $waliSwitcherRedirect = $GLOBALS['waliSwitcherRedirect'] ?? null;
        if (is_array($waliAnakRows) && count($waliAnakRows) > 1) {
            require __DIR__ . '/../partials/anak_switcher.php';
        }
        ?>
    <?php endif; ?>
    <?php
}

function wali_layout_foot(bool $registerServiceWorker = false, ?string $navActive = null): void
{
    $showBottomNav = $navActive !== null && $navActive !== '';
    $navResolved = wali_nav_resolve_active($navActive);
    $moreActive = wali_nav_more_active($navActive);
    if ($showBottomNav): ?>
    <nav class="wali-bottom-nav d-md-none" aria-label="Navigasi utama portal wali">
        <?php foreach (wali_bottom_nav_items() as $item): ?>
            <a href="<?= htmlspecialchars($item['href']) ?>" class="<?= $navResolved === $item['key'] ? 'active' : '' ?>">
                <i class="fa-solid <?= htmlspecialchars($item['icon']) ?>" aria-hidden="true"></i>
                <span><?= htmlspecialchars($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
        <button type="button" class="<?= $moreActive ? 'active' : '' ?>" data-bs-toggle="offcanvas" data-bs-target="#waliMoreNav" aria-controls="waliMoreNav">
            <i class="fa-solid fa-ellipsis" aria-hidden="true"></i>
            <span>Lainnya</span>
        </button>
    </nav>
    <div class="offcanvas offcanvas-bottom wali-more-offcanvas" tabindex="-1" id="waliMoreNav" aria-labelledby="waliMoreNavLabel">
        <div class="offcanvas-header border-bottom py-2">
            <h2 class="offcanvas-title h6 mb-0" id="waliMoreNavLabel">Menu lainnya</h2>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Tutup"></button>
        </div>
        <div class="offcanvas-body pt-2">
            <div class="list-group list-group-flush">
                <?php foreach (wali_more_nav_items() as $item): ?>
                    <a href="<?= htmlspecialchars($item['href']) ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-2 py-3<?= wali_nav_resolve_active($navActive) === $item['key'] ? ' active' : '' ?>">
                        <i class="fa-solid <?= htmlspecialchars($item['icon']) ?> text-muted" aria-hidden="true"></i>
                        <span><?= htmlspecialchars($item['label']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    </div>
    <?php require_once __DIR__ . '/../../helpers/app_vendor.php'; ?>
    <script src="<?= htmlspecialchars(app_vendor_bootstrap_js_href()) ?>" defer crossorigin="anonymous"></script>
    <script>
        window.PONDOK_APP_BASE = <?= json_encode(app_base_path(), JSON_UNESCAPED_SLASHES) ?>;
        window.PONDOK_PWA_SCOPE = <?= json_encode(rtrim(app_base_path(), '/') . '/wali/') ?>;
        window.PONDOK_PWA_SW = <?= json_encode(app_href('/wali/sw.php')) ?>;
    </script>
    <script src="<?= htmlspecialchars(app_asset_href('/assets/js/theme-mode.js')) ?>" defer></script>
    <script src="<?= htmlspecialchars(app_asset_href('/assets/js/pwa-register.js')) ?>" defer></script>
    <script src="<?= htmlspecialchars(app_asset_href('/assets/js/app-shell.js')) ?>" defer></script>
    <?php if (wali_nav_resolve_active($navActive) === 'izin'): ?>
    <script src="<?= htmlspecialchars(app_asset_href('/assets/js/perizinan-submit-once.js')) ?>" defer></script>
    <?php endif; ?>
    <?php if ($registerServiceWorker): ?>
    <?php require_once __DIR__ . '/../../includes/partials/push_fcm_bootstrap.php'; ?>
    <?php endif; ?>
</body>
</html>
    <?php
}
