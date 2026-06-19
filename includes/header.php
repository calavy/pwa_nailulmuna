<?php

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/datetime_display.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
require_once __DIR__ . '/../helpers/user_profil.php';
require_once __DIR__ . '/auth.php';
$currentUser = $_SESSION['user']['nama'] ?? 'Guest';
$currentRole = $_SESSION['user']['role'] ?? 'admin';
$currentUserRow = [
    'nama' => $currentUser,
    'foto_profil' => trim((string) ($_SESSION['user']['foto_profil'] ?? '')),
    'jenis_kelamin' => user_profil_normalize_jenis_kelamin($_SESSION['user']['jenis_kelamin'] ?? null),
];
if (isset($_SESSION['user']['id']) && isset($pdo) && $pdo instanceof PDO && table_exists($pdo, 'users')) {
    $uidHeader = (int) $_SESSION['user']['id'];
    $profilLoadedKey = 'user_profil_loaded_' . $uidHeader;
    if (empty($_SESSION[$profilLoadedKey])) {
        user_profil_ensure_schema($pdo);
        $stHeaderUser = $pdo->prepare('SELECT nama, foto_profil, jenis_kelamin FROM users WHERE id = :id LIMIT 1');
        $stHeaderUser->execute(['id' => $uidHeader]);
        $rowHeaderUser = $stHeaderUser->fetch(PDO::FETCH_ASSOC);
        if (is_array($rowHeaderUser)) {
            $currentUser = (string) ($rowHeaderUser['nama'] ?? $currentUser);
            $jkHeader = user_profil_normalize_jenis_kelamin($rowHeaderUser['jenis_kelamin'] ?? null);
            $currentUserRow = [
                'nama' => $currentUser,
                'foto_profil' => trim((string) ($rowHeaderUser['foto_profil'] ?? '')),
                'jenis_kelamin' => $jkHeader,
            ];
            $_SESSION['user']['foto_profil'] = $currentUserRow['foto_profil'];
            $_SESSION['user']['jenis_kelamin'] = $jkHeader;
            $_SESSION['user']['nama'] = $currentUser;
        }
        $_SESSION[$profilLoadedKey] = 1;
    }
}
if (isset($_SESSION['user']) && (($_SESSION['user']['username'] ?? '') === 'admin') && !isset($_SESSION['user']['is_super_admin'])) {
    $_SESSION['user']['is_super_admin'] = 1;
}
$requestPath = app_normalize_request_path((string) ($_SERVER['REQUEST_URI'] ?? ''));
$menuPack = app_menu_pack($pdo);
$menuItems = $menuPack['menuItems'];
$menuStructure = $menuPack['menuStructure'];
$permissionPathMap = $menuPack['permissionPathMap'];
if (isset($_SESSION['user'])) {
    if (isset($_GET['santri_sort'])) {
        require_once __DIR__ . '/../helpers/santri_list_sort.php';
        santri_list_sort_mode((string) $_GET['santri_sort']);
    }
    require_once __DIR__ . '/../helpers/app_cache.php';
    app_performance_cache_prune_expired();
    try {
        app_ensure_schema_deferred($pdo);
    } catch (Throwable $e) {
        error_log('[header schema] ' . $e->getMessage());
    }
    if (preg_match('#^/(keuangan|pembayaran)(/|$)#', $requestPath)) {
        if (!function_exists('keuangan_ensure_schema_deferred')) {
            require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
        }
        keuangan_ensure_schema_deferred($pdo);
    }
    enforce_route_acl_or_redirect($pdo, $requestPath, $permissionPathMap);
    unset($_SESSION['_acl_redirect_guard']);
    if (empty($_SESSION['acl_pengaturan_migrate_checked'])) {
        try {
            require_once __DIR__ . '/../helpers/pengaturan_acl.php';
            migrate_legacy_permissions_to_pengaturan($pdo);
        } catch (Throwable $e) {
            error_log('[acl migrate pengaturan] ' . $e->getMessage());
        }
        $_SESSION['acl_pengaturan_migrate_checked'] = 1;
    }
    if (empty($_SESSION['acl_keuangan_split_checked'])) {
        try {
            require_once __DIR__ . '/../helpers/user_permissions.php';
            migrate_keuangan_permissions_split($pdo);
        } catch (Throwable $e) {
            error_log('[acl migrate keuangan] ' . $e->getMessage());
        }
        $_SESSION['acl_keuangan_split_checked'] = 1;
    }
    try {
        require_once __DIR__ . '/../helpers/wa_otomatis.php';
        wa_auto_web_fallback_tick($pdo);
    } catch (Throwable $e) {
        error_log('[wa_auto_web_fallback_tick] ' . $e->getMessage());
    }
}

$appBrandTagline = '';
$appBrandTitle = 'A.P.I Nailul Muna';
$appLogoSrc = '';
$appLogoHref = '';
$appLogoInitial = 'AP';
$appAlamatPonpes = '';
if (isset($_SESSION['user']) && isset($pdo) && $pdo instanceof PDO) {
    $brandCtx = app_header_brand_context($pdo, $appBrandTitle);
    $appBrandTitle = (string) ($brandCtx['title'] ?? $appBrandTitle);
    $appBrandTagline = (string) ($brandCtx['tagline'] ?? '');
    $appLogoSrc = (string) ($brandCtx['logo'] ?? '');
    $appLogoHref = (string) ($brandCtx['logo_href'] ?? '');
    if ($appLogoHref === '' && $appLogoSrc !== '') {
        $appLogoHref = app_pondok_logo_href($pdo, false);
    }
    if ($appLogoHref === '') {
        $appLogoHref = app_pondok_logo_href($pdo);
    }
    $appLogoInitial = (string) ($brandCtx['initials'] ?? 'AP');
    $appAlamatPonpes = (string) ($brandCtx['alamat'] ?? '');
}
$roleLabels = [
    'admin' => 'Administrator',
    'pengurus' => 'Pengurus',
    'pembimbing' => 'Pembimbing',
    'kiai' => 'Pengasuh',
    'guru' => 'Guru',
    'keuangan' => 'Keuangan',
];
$currentRoleLabel = $roleLabels[$currentRole] ?? user_role_label((string) $currentRole);
$pageTitleHeader = trim((string) ($pageTitle ?? 'Dashboard'));

if (isset($_SESSION['user']) && !isset($loadPushFcm)) {
    $loadPushFcm = true;
}

$topbarBackHref = '';
$topbarBackLabel = 'Kembali';
if (isset($_SESSION['user'])) {
    $isPembimbingRole = strtolower((string) $currentRole) === 'pembimbing' && !is_super_admin();
    $isPengasuhRole = strtolower((string) $currentRole) === 'kiai';
    $homeDash = $isPembimbingRole
        ? app_href('/pembimbing/dashboard.php')
        : ($isPengasuhRole
            ? app_href('/pengasuh/dashboard.php')
            : app_href('/dashboard.php'));
    $pbDashViewParam = strtolower(trim((string) ($_GET['view'] ?? 'home')));
    $isPembimbingDashHome = $isPembimbingRole
        && $requestPath === '/pembimbing/dashboard.php'
        && $pbDashViewParam === 'home';
    $isHome = $requestPath === '/dashboard.php'
        || $requestPath === '/pengasuh/dashboard.php'
        || $isPembimbingDashHome
        || ($requestPath === '/pembimbing/' && $isPembimbingRole);
    if (!$isHome) {
        if ($isPembimbingRole) {
            $topbarBackHref = app_href('/pembimbing/dashboard.php');
            $topbarBackLabel = 'Kembali ke dashboard';
        } elseif (preg_match('#^/pembimbing/#', $requestPath)) {
            $topbarBackHref = app_href('/pembimbing/dashboard.php');
            $topbarBackLabel = 'Kembali ke dashboard';
        } else {
            $topbarBackHref = $homeDash;
        }
    }
}

$hideAppSidebar = (bool) ($hideAppSidebar ?? false);
if (!$hideAppSidebar && strtolower((string) $currentRole) === 'pembimbing' && !is_super_admin()) {
    $hideAppSidebar = true;
}
$bodyClassExtra = $hideAppSidebar ? ' app-body-shell--no-sidebar' : '';

if (!function_exists('render_app_sidebar_nav')) {
    function render_app_sidebar_nav(array $structure, array $items, string $requestPath, array $options = []): void
    {
        $mode = (string) ($options['mode'] ?? 'hub');
        $isAccordion = $mode === 'accordion';
        echo '<nav class="app-sidebar-nav' . ($isAccordion ? ' app-sidebar-nav--accordion' : '') . '" aria-label="Menu utama">';
        if ($isAccordion) {
            echo '<div class="app-sidebar-nav-label">Menu modul</div>';
        } else {
            echo '<div class="app-sidebar-nav-label app-sidebar-nav-label--toggle" data-app-sidebar-toggle="hide" role="button" tabindex="0" title="Sembunyikan menu" aria-label="Sembunyikan menu samping">';
            echo '<span class="app-sidebar-nav-label__text">Menu modul</span>';
            echo '<span class="app-sidebar-nav-label__arrow" aria-hidden="true"><i class="fa-solid fa-chevron-left"></i></span>';
            echo '</div>';
        }
        foreach ($structure as $node) {
            $type = (string) ($node['type'] ?? 'item');
            if ($type === 'item') {
                $path = (string) ($node['path'] ?? '');
                if ($path === '' || !array_key_exists($path, $items)) {
                    continue;
                }
                $icon = (string) ($node['icon'] ?? 'fa-solid fa-circle');
                $pathBase = function_exists('app_menu_acl_normalize_path_base')
                    ? app_menu_acl_normalize_path_base($path)
                    : $path;
                $active = str_contains($requestPath, $pathBase);
                echo '<a class="app-side-nav-item' . ($active ? ' active' : '') . '" href="' . htmlspecialchars(app_href($path)) . '">'
                    . '<span class="app-side-nav-ico" aria-hidden="true"><i class="' . htmlspecialchars($icon) . '"></i></span>'
                    . '<span class="app-side-nav-text">' . htmlspecialchars((string) $items[$path]) . '</span>'
                    . '</a>';
                continue;
            }
            if ($type === 'group') {
                $gid = (string) ($node['id'] ?? '');
                $visible = menu_group_visible_paths($node, $items);
                if ($visible === [] || $gid === '') {
                    continue;
                }
                $icon = (string) ($node['icon'] ?? 'fa-solid fa-layer-group');
                $label = (string) ($node['label'] ?? 'Grup');
                $expandInline = !empty($node['expand']);
                $groupActive = menu_sidebar_group_is_active($node, $requestPath, $items);

                if ($isAccordion || $expandInline) {
                    $sections = menu_group_visible_sections($node, $items);
                    if ($sections === []) {
                        continue;
                    }
                    echo '<details class="app-side-nav-accordion' . ($groupActive ? ' is-active' : '') . '"' . ($groupActive ? ' open' : '') . '>';
                    echo '<summary class="app-side-nav-accordion__summary">';
                    echo '<span class="app-side-nav-ico" aria-hidden="true"><i class="' . htmlspecialchars($icon) . '"></i></span>';
                    echo '<span class="app-side-nav-text">' . htmlspecialchars($label) . '</span>';
                    echo '<span class="app-side-nav-chevron" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span>';
                    echo '</summary>';
                    echo '<div class="app-side-nav-accordion__body">';
                    foreach ($sections as $sec) {
                        if ($sec['title'] !== '') {
                            echo '<div class="app-side-nav-section-title">' . htmlspecialchars($sec['title']) . '</div>';
                        }
                        foreach ($sec['paths'] as $cp) {
                            if (!array_key_exists($cp, $items)) {
                                continue;
                            }
                            $pathBase = function_exists('app_menu_acl_normalize_path_base')
                                ? app_menu_acl_normalize_path_base($cp)
                                : $cp;
                            $active = str_contains($requestPath, $pathBase);
                            echo '<a class="app-side-nav-item app-side-nav-item--child' . ($active ? ' active' : '') . '" href="' . htmlspecialchars(app_href($cp)) . '">'
                                . '<span class="app-side-nav-ico" aria-hidden="true"><i class="fa-solid fa-angle-right"></i></span>'
                                . '<span class="app-side-nav-text">' . htmlspecialchars((string) $items[$cp]) . '</span>'
                                . '</a>';
                        }
                    }
                    echo '</div></details>';
                    continue;
                }

                $href = '/menu/menu_hub.php?id=' . rawurlencode($gid);
                echo '<a class="app-side-nav-item app-side-nav-item--hub' . ($groupActive ? ' active' : '') . '" href="' . htmlspecialchars(app_href($href)) . '">'
                    . '<span class="app-side-nav-ico" aria-hidden="true"><i class="' . htmlspecialchars($icon) . '"></i></span>'
                    . '<span class="app-side-nav-text">' . htmlspecialchars($label) . '</span>'
                    . '<span class="app-side-nav-chevron" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>'
                    . '</a>';
            }
        }
        echo '</nav>';
        if (!$isAccordion) {
            $hasHubOnly = false;
            foreach ($structure as $sn) {
                if (($sn['type'] ?? '') === 'group' && empty($sn['expand'])) {
                    $hasHubOnly = true;
                    break;
                }
            }
            if ($hasHubOnly) {
                echo '<div class="app-sidebar-nav-hint text-muted small d-none d-lg-block">Grup membuka halaman berisi submenu. Item lain langsung ke halaman.</div>';
            }
        }
    }
}

?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="format-detection" content="telephone=no">
    <?php $pwaThemeHeader = app_pwa_theme(isset($pdo) && $pdo instanceof PDO ? $pdo : null); ?>
    <meta name="theme-color" content="<?= htmlspecialchars((string) ($pwaThemeHeader['theme_color'] ?? '#0f766e')) ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars(app_pwa_short_name(isset($pdo) && $pdo instanceof PDO ? $pdo : null)) ?>">
    <link rel="manifest" href="<?= htmlspecialchars(app_href('/manifest.php')) ?>">
    <?= app_pwa_icon_link_tags(isset($pdo) && $pdo instanceof PDO ? $pdo : null) ?>
    <title><?= htmlspecialchars($pageTitle ?? 'Manajemen Santri') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet"></noscript>
    <?php require __DIR__ . '/partials/app_vendor_assets.php'; ?>
    <link href="<?= htmlspecialchars(app_asset_href('/assets/css/app.css')) ?>" rel="stylesheet">
    <script>
        (function () {
            try {
                if (localStorage.getItem('app-sidebar-hidden') === '1') {
                    document.documentElement.classList.add('app-sidebar-hidden-boot');
                }
            } catch (e) {}
        })();
    </script>
    <link rel="preload" href="<?= htmlspecialchars(app_asset_href('/assets/css/app.css')) ?>" as="style">
    <?php if (!empty($pageStylesheets) && is_array($pageStylesheets)): ?>
        <?php foreach ($pageStylesheets as $pageStylesheetHref): ?>
    <link href="<?= htmlspecialchars((string) $pageStylesheetHref) ?>" rel="stylesheet">
        <?php endforeach; ?>
    <?php endif; ?>
    <?php if (keuangan_should_load_typography_css(isset($bodyClass) ? (string) $bodyClass : null, $requestPath)): ?>
    <link href="<?= htmlspecialchars(app_asset_href('/assets/css/keuangan.css')) ?>" rel="stylesheet">
    <?php endif; ?>
    <?php
    $pwaLogoFallbackHref = app_href(app_pwa_default_icon_src());
    $pwaAvatarFallbackHref = user_profil_default_avatar_href($currentUserRow['jenis_kelamin'] ?? null);
    ?>
    <meta name="pondok-pwa-logo-fallback" content="<?= htmlspecialchars($pwaLogoFallbackHref) ?>">
    <meta name="pondok-pwa-avatar-fallback" content="<?= htmlspecialchars($pwaAvatarFallbackHref) ?>">
    <?php if ($appLogoHref !== ''): ?>
    <meta name="pondok-pwa-logo" content="<?= htmlspecialchars($appLogoHref) ?>">
    <?php endif; ?>
    <?php if (trim((string) ($currentUserRow['foto_profil'] ?? '')) !== ''): ?>
    <meta name="pondok-pwa-avatar" content="<?= htmlspecialchars(user_profil_url((string) $currentUserRow['foto_profil'])) ?>">
    <?php endif; ?>
    <script>
        (function () {
            try {
                var m = localStorage.getItem('theme-mode') === 'dark' ? 'dark' : 'light';
                var d = document.documentElement;
                d.setAttribute('data-theme', m);
                d.style.colorScheme = m;
                d.style.backgroundColor = m === 'dark' ? '#0f172a' : '#eef5ff';
            } catch (e) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
    </script>
    <script>
        (function () {
            function pad2(n) {
                return String(n).padStart(2, '0');
            }

            function normalizeTime24(raw) {
                const value = String(raw || '').trim();
                if (value === '') return '';

                // Already 24-hour with optional seconds.
                const m24 = value.match(/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/);
                if (m24) {
                    const h = Math.max(0, Math.min(23, parseInt(m24[1], 10) || 0));
                    const m = Math.max(0, Math.min(59, parseInt(m24[2], 10) || 0));
                    return pad2(h) + ':' + pad2(m);
                }

                // Convert "7:05 PM" / "07:05am" -> "19:05".
                const m12 = value.match(/^(\d{1,2}):(\d{2})\s*([AaPp][Mm])$/);
                if (!m12) return value;
                let h = parseInt(m12[1], 10) || 0;
                const m = Math.max(0, Math.min(59, parseInt(m12[2], 10) || 0));
                const ap = m12[3].toLowerCase();
                if (ap === 'pm' && h < 12) h += 12;
                if (ap === 'am' && h === 12) h = 0;
                h = Math.max(0, Math.min(23, h));
                return pad2(h) + ':' + pad2(m);
            }

            function force24HourInputs() {
                const inputs = document.querySelectorAll('input[type="time"]');
                inputs.forEach(function (input) {
                    if (!input.getAttribute('step')) {
                        input.setAttribute('step', '60');
                    }
                    input.placeholder = 'HH:MM';
                    input.addEventListener('change', function () {
                        input.value = normalizeTime24(input.value);
                    });
                    input.addEventListener('blur', function () {
                        input.value = normalizeTime24(input.value);
                    });
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', force24HourInputs);
            } else {
                force24HourInputs();
            }
        })();
    </script>
</head>
<body<?= isset($bodyClass) && trim((string) $bodyClass) !== '' ? ' class="' . htmlspecialchars(trim((string) $bodyClass)) . ' app-body-shell' . $bodyClassExtra . '"' : ' class="app-body-shell' . $bodyClassExtra . '"' ?>>
<div class="app-frame" id="app-frame">
    <?php if (!$hideAppSidebar): ?>
    <aside class="app-sidebar app-sidebar--desktop d-none d-lg-flex" aria-label="Menu samping">
        <?php
        $compact = false;
        $sidebarHeadIdSuffix = 'desk';
        require __DIR__ . '/partials/app_sidebar_head.php';
        ?>
        <div class="app-sidebar-inner">
            <?php render_app_sidebar_nav($menuStructure, $menuItems, $requestPath, ['mode' => 'hub']); ?>
        </div>
    </aside>
    <?php endif; ?>

    <div class="app-frame-main">
        <header class="app-topbar">
            <div class="app-topbar-inner">
                <div class="app-topbar-left">
                    <?php if (!$hideAppSidebar): ?>
                    <button type="button" class="app-topbar-menu-btn app-topbar-menu-btn--mobile d-lg-none" id="appMenuBtnMobile" aria-label="Buka menu" aria-expanded="false" aria-controls="mobileSidebar" title="Menu">
                        <i class="fa-solid fa-bars" aria-hidden="true"></i>
                        <span class="app-topbar-menu-btn__label">Menu</span>
                    </button>
                    <button type="button" class="app-topbar-menu-btn app-topbar-menu-btn--desktop d-none" id="appMenuBtnDesktop" data-app-sidebar-toggle="show" aria-label="Tampilkan menu samping" title="Tampilkan menu">
                        <span class="app-topbar-menu-arrow" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>
                    </button>
                    <?php endif; ?>
                    <a href="<?= htmlspecialchars($hideAppSidebar ? app_href('/pembimbing/dashboard.php') : app_href('/dashboard.php')) ?>" class="app-brand-link<?= $hideAppSidebar ? '' : ' d-lg-none' ?>" title="<?= htmlspecialchars($appBrandTitle) ?>">
                        <?php if ($appLogoHref !== ''): ?>
                            <span class="app-brand-mark app-brand-mark--logo">
                                <img src="<?= htmlspecialchars($appLogoHref) ?>" alt="Logo <?= htmlspecialchars($appBrandTitle) ?>" class="app-brand-logo" decoding="async" fetchpriority="high" data-pondok-cache="1">
                            </span>
                        <?php else: ?>
                            <span class="app-brand-mark app-brand-mark--fallback" aria-hidden="true"><?= htmlspecialchars($appLogoInitial) ?></span>
                        <?php endif; ?>
                        <span class="app-brand-text">
                            <?php if ($appBrandTagline !== ''): ?>
                                <span class="app-brand-tagline app-brand-kicker"><?= htmlspecialchars($appBrandTagline) ?></span>
                            <?php endif; ?>
                            <span class="app-brand-title"><?= htmlspecialchars($appBrandTitle) ?></span>
                        </span>
                    </a>
                    <div class="app-topbar-page<?= $hideAppSidebar ? ' d-none' : ' d-none d-lg-flex' ?>">
                        <span class="app-topbar-page-kicker">Halaman aktif</span>
                        <h1 class="app-topbar-page-title"><?= htmlspecialchars($pageTitleHeader) ?></h1>
                    </div>
                </div>
                <div class="app-topbar-center d-lg-none">
                    <span class="app-topbar-title-mobile"><?= htmlspecialchars($pageTitleHeader) ?></span>
                </div>
                <div class="app-topbar-right">
                    <?php if (isset($_SESSION['user'])): ?>
                    <div class="app-topbar-actions" role="group" aria-label="Akun">
                        <?php if ($topbarBackHref !== ''): ?>
                        <a class="app-topbar-action-btn app-topbar-back" href="<?= htmlspecialchars($topbarBackHref) ?>" title="<?= htmlspecialchars($topbarBackLabel) ?>">
                            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i>
                            <span class="app-topbar-action-btn__label"><?= htmlspecialchars($topbarBackLabel) ?></span>
                        </a>
                        <?php endif; ?>
                        <span class="app-topbar-role badge rounded-pill d-none d-lg-inline-flex"><?= htmlspecialchars($currentRoleLabel) ?></span>
                        <div class="dropdown app-topbar-profile-menu">
                            <button type="button" class="app-topbar-user-pill dropdown-toggle d-none d-sm-inline-flex" data-bs-toggle="dropdown" aria-expanded="false" title="Profil &amp; pengaturan">
                                <?= user_profil_render_avatar($currentUserRow, 'app-user-avatar--sm') ?>
                                <span class="app-topbar-user-pill-text">
                                    <span class="app-topbar-user-name"><?= htmlspecialchars($currentUser) ?></span>
                                </span>
                            </button>
                            <button type="button" class="app-topbar-user-pill dropdown-toggle d-inline-flex d-sm-none" data-bs-toggle="dropdown" aria-expanded="false" title="<?= htmlspecialchars($currentUser) ?>">
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
                        <a class="app-topbar-action-btn app-topbar-logout" href="<?= htmlspecialchars(app_href('/logout.php')) ?>" title="Keluar">
                            <i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i>
                            <span class="app-topbar-action-btn__label d-none d-md-inline">Keluar</span>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <?php if (!$hideAppSidebar): ?>
        <div class="offcanvas offcanvas-start app-mobile-sidebar" tabindex="-1" id="mobileSidebar" aria-labelledby="mobileSidebarLabel">
            <div class="offcanvas-header app-mobile-sidebar__header">
                <h2 class="offcanvas-title h6 mb-0" id="mobileSidebarLabel">Navigasi</h2>
                <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Tutup menu"></button>
            </div>
            <div class="offcanvas-body app-mobile-sidebar__body">
                <?php
                $compact = true;
                $sidebarHeadIdSuffix = 'mob';
                require __DIR__ . '/partials/app_sidebar_head.php';
                ?>
                <div class="app-sidebar-mobile">
                    <?php render_app_sidebar_nav($menuStructure, $menuItems, $requestPath, ['mode' => 'accordion']); ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="app-shell app-shell--wide" id="app-shell">
            <main class="app-content app-main">
    <?php if ($success = get_flash('success')): ?>
        <div class="alert alert-success app-flash mb-3" role="alert"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error = get_flash('error')): ?>
        <div class="alert alert-danger app-flash mb-3" role="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php
    if (isset($_SESSION['user'])) {
        require_once __DIR__ . '/../helpers/app_hub.php';
        app_hub_render_tabs_for_path($pdo, $requestPath, $permissionPathMap);
    }
    ?>
