<?php

declare(strict_types=1);

require_once __DIR__ . '/../../helpers/app_path.php';

/** @return list<array{href:string,icon:string,label:string,key:string}> */
function wali_bottom_nav_items(): array
{
    return [
        ['href' => app_href('/wali/index.php'), 'icon' => 'fa-house', 'label' => 'Beranda', 'key' => 'beranda'],
        ['href' => app_href('/wali/keuangan.php'), 'icon' => 'fa-wallet', 'label' => 'Keuangan', 'key' => 'keuangan'],
        ['href' => app_href('/wali/pembayaran.php'), 'icon' => 'fa-receipt', 'label' => 'Riwayat Keuangan', 'key' => 'pembayaran'],
        ['href' => app_href('/wali/tagihan.php'), 'icon' => 'fa-file-invoice', 'label' => 'Tagihan', 'key' => 'tagihan'],
        ['href' => app_href('/wali/keaktifan.php'), 'icon' => 'fa-calendar-check', 'label' => 'Aktif', 'key' => 'keaktifan'],
        ['href' => app_href('/wali/izin.php'), 'icon' => 'fa-person-walking-arrow-right', 'label' => 'Izin', 'key' => 'izin'],
    ];
}

/** Menu tambahan (desktop) — riwayat non-keuangan. */
function wali_extra_nav_items(): array
{
    return [
        ['href' => app_href('/wali/riwayat.php'), 'label' => 'Riwayat Santri', 'key' => 'riwayat'],
        ['href' => app_href('/wali/rapor.php'), 'label' => 'Rapor', 'key' => 'rapor'],
    ];
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
    <meta name="color-scheme" content="light">
    <?php if ($withManifest): ?>
        <link rel="manifest" href="<?= htmlspecialchars(app_href('/wali/manifest.php')) ?>">
        <meta name="theme-color" content="#0f766e">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="Portal Wali">
    <?php endif; ?>
    <title><?= htmlspecialchars($title) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet" crossorigin="anonymous">
    <link href="<?= htmlspecialchars(app_href('/assets/css/wali-portal.css')) ?>" rel="stylesheet">
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
            <?php foreach (wali_bottom_nav_items() as $item): ?>
                <a href="<?= htmlspecialchars($item['href']) ?>" class="btn btn-sm btn-outline-secondary <?= $navActive === $item['key'] ? 'active' : '' ?>"><?= htmlspecialchars($item['label']) ?></a>
            <?php endforeach; ?>
            <?php foreach (wali_extra_nav_items() as $item): ?>
                <a href="<?= htmlspecialchars($item['href']) ?>" class="btn btn-sm btn-outline-secondary <?= $navActive === $item['key'] ? 'active' : '' ?>"><?= htmlspecialchars($item['label']) ?></a>
            <?php endforeach; ?>
            <button type="button" class="btn btn-sm btn-outline-success" id="btn-fcm-subscribe" title="Aktifkan notifikasi push"><i class="fa-solid fa-bell"></i></button>
        </nav>
        <?php if (isset($waliAnakRows) && count($waliAnakRows) > 1): ?>
            <?php require __DIR__ . '/../partials/anak_switcher.php'; ?>
        <?php endif; ?>
    <?php endif; ?>
    <?php
}

function wali_layout_foot(bool $registerServiceWorker = false, ?string $navActive = null): void
{
    $showBottomNav = $navActive !== null && $navActive !== '';
    if ($showBottomNav): ?>
    <nav class="wali-bottom-nav d-md-none" aria-label="Navigasi utama portal wali">
        <?php foreach (wali_bottom_nav_items() as $item): ?>
            <a href="<?= htmlspecialchars($item['href']) ?>" class="<?= $navActive === $item['key'] ? 'active' : '' ?>">
                <i class="fa-solid <?= htmlspecialchars($item['icon']) ?>" aria-hidden="true"></i>
                <span><?= htmlspecialchars($item['label']) ?></span>
            </a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" crossorigin="anonymous"></script>
    <?php if ($registerServiceWorker): ?>
    <?php require_once __DIR__ . '/../../includes/partials/push_fcm_bootstrap.php'; ?>
    <?php endif; ?>
</body>
</html>
    <?php
}
