<?php

declare(strict_types=1);

/**
 * Layout ringkas portal petugas koperasi cashless (tanpa sidebar admin).
 *
 * @param array{title:string,koperasi_nama:string,active?:'scan'|'laporan'|'hub'} $ctx
 */
function koperasi_portal_layout_begin(array $ctx): void
{
    $title = htmlspecialchars((string) ($ctx['title'] ?? 'Koperasi'));
    $kopNama = htmlspecialchars((string) ($ctx['koperasi_nama'] ?? 'Koperasi'));
    $active = (string) ($ctx['active'] ?? '');
    require_once __DIR__ . '/../helpers/app_path.php';
    require_once __DIR__ . '/../helpers/app.php';
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0f766e">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars(app_pwa_app_name()) ?>">
    <title><?= $title ?></title>
    <link rel="manifest" href="<?= htmlspecialchars(app_href('/manifest.php')) ?>">
    <?= app_pwa_icon_link_tags() ?>
    <meta name="pondok-pwa-logo-fallback" content="<?= htmlspecialchars(app_href(app_pwa_default_icon_src())) ?>">
    <meta name="pondok-pwa-logo" content="<?= htmlspecialchars(app_pwa_icon_href()) ?>">
    <?php require __DIR__ . '/partials/app_vendor_assets.php'; ?>
    <link href="<?= htmlspecialchars(app_asset_href('/assets/css/offline-sync.css')) ?>" rel="stylesheet">
    <style>
        body { background: #f1f5f9; min-height: 100dvh; }
        .koperasi-topbar {
            background: linear-gradient(135deg, #0f766e 0%, #0891b2 100%);
            color: #fff;
            padding: .75rem 1rem calc(.75rem + env(safe-area-inset-top, 0px));
        }
        .koperasi-nav .nav-link {
            color: rgba(255,255,255,.85);
            border-radius: .5rem;
            padding: .35rem .75rem;
            font-size: .875rem;
        }
        .koperasi-nav .nav-link.active, .koperasi-nav .nav-link:hover {
            background: rgba(255,255,255,.18);
            color: #fff;
        }
        .koperasi-topbar-logo {
            width: 36px;
            height: 36px;
            object-fit: contain;
            border-radius: 8px;
            background: rgba(255,255,255,.92);
            padding: 2px;
            flex-shrink: 0;
        }
    </style>
</head>
<body>
<header class="koperasi-topbar">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <div class="d-flex align-items-center gap-2 min-w-0">
            <img src="<?= htmlspecialchars(app_pwa_icon_href()) ?>" alt="" class="koperasi-topbar-logo" width="36" height="36" decoding="async" data-pondok-cache="1">
            <div class="fw-semibold min-w-0"><i class="fa-solid fa-store me-1"></i><?= $kopNama ?></div>
        </div>
        <a href="<?= htmlspecialchars(app_href('/koperasi/logout.php')) ?>" class="btn btn-sm btn-light">Keluar</a>
    </div>
    <nav class="koperasi-nav">
        <ul class="nav gap-1">
            <li class="nav-item">
                <a class="nav-link<?= $active === 'scan' ? ' active' : '' ?>" href="<?= htmlspecialchars(app_href('/koperasi/scan.php')) ?>"><i class="fa-solid fa-qrcode me-1"></i> Scan</a>
            </li>
            <li class="nav-item">
                <a class="nav-link<?= $active === 'laporan' ? ' active' : '' ?>" href="<?= htmlspecialchars(app_href('/koperasi/laporan.php')) ?>"><i class="fa-solid fa-chart-column me-1"></i> Laporan</a>
            </li>
        </ul>
    </nav>
</header>
<main class="container-fluid py-3 px-3 px-md-4" style="max-width:1200px;margin:0 auto;">
    <?php
}

function koperasi_portal_layout_end(): void
{
    ?>
</main>
<?php require_once __DIR__ . '/../helpers/app_vendor.php'; ?>
<script src="<?= htmlspecialchars(app_vendor_bootstrap_js_href()) ?>" crossorigin="anonymous"></script>
<script>window.PONDOK_APP_BASE = <?= json_encode(app_base_path(), JSON_UNESCAPED_SLASHES) ?>;</script>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/theme-mode.js')) ?>" defer></script>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/pwa-media-cache.js')) ?>" defer></script>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/offline-sync.js')) ?>" defer></script>
<script src="<?= htmlspecialchars(app_asset_href('/assets/js/pwa-register.js')) ?>" defer></script>
</body>
</html>
    <?php
}
