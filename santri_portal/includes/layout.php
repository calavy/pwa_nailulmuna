<?php

declare(strict_types=1);

require_once __DIR__ . '/../../helpers/app_path.php';
require_once __DIR__ . '/../../helpers/app.php';

/** @return list<array{href:string,icon:string,label:string,key:string}> */
function santri_portal_bottom_nav_items(): array
{
    return [
        ['href' => app_href('/santri_portal/index.php'), 'icon' => 'fa-house', 'label' => 'Beranda', 'key' => 'beranda'],
        ['href' => app_href('/santri_portal/tugas/index.php'), 'icon' => 'fa-list-check', 'label' => 'Tugas', 'key' => 'tugas'],
        ['href' => app_href('/santri_portal/keaktifan.php'), 'icon' => 'fa-star-half-stroke', 'label' => 'Aktif', 'key' => 'keaktifan'],
        ['href' => app_href('/santri_portal/riwayat.php'), 'icon' => 'fa-clock-rotate-left', 'label' => 'Riwayat', 'key' => 'riwayat'],
    ];
}

function santri_portal_layout_head(string $title, ?string $navActive = null): void
{
    $showNav = $navActive !== null && $navActive !== '';
    $flashOk = $showNav ? get_flash('success') : null;
    $flashErr = $showNav ? get_flash('error') : null;
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="format-detection" content="telephone=no">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#0f766e">
    <?= app_pwa_icon_link_tags() ?>
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"></noscript>
    <?php require __DIR__ . '/../../includes/partials/app_vendor_assets.php'; ?>
    <link href="<?= htmlspecialchars(app_asset_href('/assets/css/app.css')) ?>" rel="stylesheet">
    <link href="<?= htmlspecialchars(app_asset_href('/assets/css/wali-portal.css')) ?>" rel="stylesheet">
</head>
<body class="santri-portal py-3 py-md-4">
    <div class="container wali-shell px-3">
    <?php if ($showNav): ?>
        <?php if ($flashOk): ?>
            <div class="alert alert-success py-2 small mb-2 shadow-sm" role="status"><?= htmlspecialchars($flashOk) ?></div>
        <?php endif; ?>
        <?php if ($flashErr): ?>
            <div class="alert alert-danger py-2 small mb-2 shadow-sm" role="alert"><?= htmlspecialchars($flashErr) ?></div>
        <?php endif; ?>
        <nav class="wali-nav-scroll wali-nav-scroll--desktop-only mb-2" aria-label="Menu portal santri">
            <?php foreach (santri_portal_bottom_nav_items() as $item): ?>
                <a href="<?= htmlspecialchars($item['href']) ?>" class="btn btn-sm btn-outline-secondary <?= $navActive === $item['key'] ? 'active' : '' ?>"><?= htmlspecialchars($item['label']) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
    <?php
}

function santri_portal_layout_foot(?string $navActive = null): void
{
    $showBottomNav = $navActive !== null && $navActive !== '';
    if ($showBottomNav): ?>
    <nav class="wali-bottom-nav d-md-none" aria-label="Navigasi portal santri">
        <?php foreach (santri_portal_bottom_nav_items() as $item): ?>
            <a href="<?= htmlspecialchars($item['href']) ?>" class="<?= $navActive === $item['key'] ? 'active' : '' ?>">
                <i class="fa-solid <?= htmlspecialchars($item['icon']) ?>" aria-hidden="true"></i>
                <span><?= htmlspecialchars($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    </div>
    <?php require_once __DIR__ . '/../../helpers/app_vendor.php'; ?>
    <script src="<?= htmlspecialchars(app_vendor_bootstrap_js_href()) ?>" defer crossorigin="anonymous"></script>
    <script src="<?= htmlspecialchars(app_asset_href('/assets/js/theme-mode.js')) ?>" defer></script>
    <script src="<?= htmlspecialchars(app_asset_href('/assets/js/app-shell.js')) ?>" defer></script>
</body>
</html>
    <?php
}
