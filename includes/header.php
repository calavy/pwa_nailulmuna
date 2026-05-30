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
    app_ensure_schema_deferred($pdo);
    if (preg_match('#^/(keuangan|pembayaran)(/|$)#', $requestPath)) {
        if (!function_exists('keuangan_ensure_schema_deferred')) {
            require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
        }
        keuangan_ensure_schema_deferred($pdo);
    }
    enforce_route_acl_or_redirect($pdo, $requestPath, $permissionPathMap);
    unset($_SESSION['_acl_redirect_guard']);
    if (empty($_SESSION['acl_pengaturan_migrate_checked'])) {
        require_once __DIR__ . '/../helpers/pengaturan_acl.php';
        migrate_legacy_permissions_to_pengaturan($pdo);
        $_SESSION['acl_pengaturan_migrate_checked'] = 1;
    }
    if (empty($_SESSION['acl_keuangan_split_checked'])) {
        require_once __DIR__ . '/../helpers/user_permissions.php';
        migrate_keuangan_permissions_split($pdo);
        $_SESSION['acl_keuangan_split_checked'] = 1;
    }
    // Maintenance berat (presensi/poin/WA massal): hanya cron/wa_auto.php — bukan tiap navigasi web.
}

$appBrandTagline = '';
$appBrandTitle = 'A.P.I Nailul Muna';
$appLogoSrc = '';
$appLogoInitial = 'AP';
$appAlamatPonpes = '';
if (isset($_SESSION['user'])) {
    $brandCtx = app_header_brand_context($pdo, $appBrandTitle);
    $appBrandTitle = (string) ($brandCtx['title'] ?? $appBrandTitle);
    $appBrandTagline = (string) ($brandCtx['tagline'] ?? '');
    $appLogoSrc = (string) ($brandCtx['logo'] ?? '');
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

if (!function_exists('render_app_sidebar_nav')) {
    function render_app_sidebar_nav(array $structure, array $items, string $requestPath): void
    {
        echo '<nav class="app-sidebar-nav" aria-label="Menu utama">';
        echo '<div class="app-sidebar-nav-label">Menu modul</div>';
        foreach ($structure as $node) {
            $type = (string) ($node['type'] ?? 'item');
            if ($type === 'item') {
                $path = (string) ($node['path'] ?? '');
                if ($path === '' || !array_key_exists($path, $items)) {
                    continue;
                }
                $icon = (string) ($node['icon'] ?? 'fa-solid fa-circle');
                $active = str_contains($requestPath, $path);
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
                $href = '/menu/menu_hub.php?id=' . rawurlencode($gid);
                $active = menu_sidebar_group_is_active($node, $requestPath, $items);
                echo '<a class="app-side-nav-item app-side-nav-item--hub' . ($active ? ' active' : '') . '" href="' . htmlspecialchars(app_href($href)) . '">'
                    . '<span class="app-side-nav-ico" aria-hidden="true"><i class="' . htmlspecialchars($icon) . '"></i></span>'
                    . '<span class="app-side-nav-text">' . htmlspecialchars($label) . '</span>'
                    . '<span class="app-side-nav-chevron" aria-hidden="true"><i class="fa-solid fa-chevron-right"></i></span>'
                    . '</a>';
            }
        }
        echo '</nav>';
        echo '<div class="app-sidebar-nav-hint text-muted small">Grup membuka halaman berisi submenu. Item lain langsung ke halaman.</div>';
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
    <?php if ($appLogoSrc !== ''): ?>
    <meta name="pondok-pwa-logo" content="<?= htmlspecialchars(app_href($appLogoSrc)) ?>">
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
<body<?= isset($bodyClass) && trim((string) $bodyClass) !== '' ? ' class="' . htmlspecialchars(trim((string) $bodyClass)) . ' app-body-shell"' : ' class="app-body-shell"' ?>>
<div class="app-frame" id="app-frame">
    <aside class="app-sidebar app-sidebar--desktop d-none d-lg-flex" aria-label="Menu samping">
        <?php
        $compact = false;
        $sidebarHeadIdSuffix = 'desk';
        require __DIR__ . '/partials/app_sidebar_head.php';
        ?>
        <div class="app-sidebar-inner">
            <?php render_app_sidebar_nav($menuStructure, $menuItems, $requestPath); ?>
        </div>
    </aside>

    <div class="app-frame-main">
        <header class="app-topbar">
            <div class="app-topbar-inner">
                <div class="app-topbar-left">
                    <button class="btn btn-light btn-sm app-topbar-menu-btn d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-label="Buka menu">
                        <i class="fa-solid fa-bars" aria-hidden="true"></i>
                        <span class="d-none d-sm-inline ms-1">Menu</span>
                    </button>
                    <a href="<?= htmlspecialchars(app_href('/dashboard.php')) ?>" class="app-brand-link d-lg-none" title="<?= htmlspecialchars($appBrandTitle) ?>">
                        <?php if ($appLogoSrc !== ''): ?>
                            <span class="app-brand-mark app-brand-mark--logo">
                                <img src="<?= htmlspecialchars(app_href($appLogoSrc)) ?>" alt="Logo <?= htmlspecialchars($appBrandTitle) ?>" class="app-brand-logo" decoding="async" fetchpriority="high" data-pondok-cache="1">
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
                    <div class="app-topbar-page d-none d-lg-flex">
                        <span class="app-topbar-page-kicker">Halaman aktif</span>
                        <h1 class="app-topbar-page-title"><?= htmlspecialchars($pageTitleHeader) ?></h1>
                    </div>
                </div>
                <div class="app-topbar-center d-lg-none">
                    <span class="app-topbar-title-mobile"><?= htmlspecialchars($pageTitleHeader) ?></span>
                </div>
                <div class="app-topbar-right">
                    <?php if (isset($_SESSION['user'])): ?>
                        <span class="app-topbar-role badge rounded-pill d-none d-md-inline-flex"><?= htmlspecialchars($currentRoleLabel) ?></span>
                        <a class="app-topbar-user-pill d-none d-sm-inline-flex" href="<?= htmlspecialchars(app_href('/settings/profil.php')) ?>" title="Profil &amp; foto">
                            <?= user_profil_render_avatar($currentUserRow, 'app-user-avatar--sm') ?>
                            <span class="app-topbar-user-pill-text">
                                <span class="app-topbar-user-name"><?= htmlspecialchars($currentUser) ?></span>
                            </span>
                        </a>
                        <a class="app-topbar-user-pill d-inline-flex d-sm-none" href="<?= htmlspecialchars(app_href('/settings/profil.php')) ?>" title="<?= htmlspecialchars($currentUser) ?>">
                            <?= user_profil_render_avatar($currentUserRow, 'app-user-avatar--sm') ?>
                        </a>
                        <button type="button" class="btn btn-sm app-topbar-icon-btn" id="btn-fcm-subscribe" title="Aktifkan notifikasi push" aria-label="Notifikasi">
                            <i class="fa-solid fa-bell"></i>
                        </button>
                        <a class="btn btn-sm app-topbar-icon-btn app-topbar-logout" href="<?= htmlspecialchars(app_href('/logout.php')) ?>" title="Keluar">
                            <i class="fa-solid fa-right-from-bracket d-sm-none" aria-hidden="true"></i>
                            <span class="d-none d-sm-inline">Keluar</span>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </header>

        <div class="offcanvas offcanvas-start" tabindex="-1" id="mobileSidebar">
            <div class="offcanvas-header border-0 pb-0">
                <button type="button" class="btn-close ms-auto" data-bs-dismiss="offcanvas" aria-label="Tutup"></button>
            </div>
            <div class="offcanvas-body pt-2">
                <?php
                $compact = true;
                $sidebarHeadIdSuffix = 'mob';
                require __DIR__ . '/partials/app_sidebar_head.php';
                ?>
                <div class="app-sidebar-mobile">
                    <?php render_app_sidebar_nav($menuStructure, $menuItems, $requestPath); ?>
                </div>
            </div>
        </div>

        <div class="app-shell app-shell--wide" id="app-shell">
            <main class="app-content app-main">
    <?php if ($success = get_flash('success')): ?>
        <div class="alert alert-success app-flash mb-3" role="alert"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error = get_flash('error')): ?>
        <div class="alert alert-danger app-flash mb-3" role="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <script>
        (function () {
            try {
                localStorage.removeItem('sidebar-collapsed');
            } catch (e) {}
        })();
    </script>
