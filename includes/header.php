<?php

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
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
    <meta name="theme-color" content="#0f766e">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?= htmlspecialchars($pageTitle ?? 'Manajemen Santri') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="<?= htmlspecialchars(app_href('/assets/css/app.css')) ?>" rel="stylesheet">
    <link rel="preload" href="<?= htmlspecialchars(app_href('/assets/css/app.css')) ?>" as="style">
    <?php if (!empty($pageStylesheets) && is_array($pageStylesheets)): ?>
        <?php foreach ($pageStylesheets as $pageStylesheetHref): ?>
    <link href="<?= htmlspecialchars((string) $pageStylesheetHref) ?>" rel="stylesheet">
        <?php endforeach; ?>
    <?php endif; ?>
    <?php if (keuangan_should_load_typography_css(isset($bodyClass) ? (string) $bodyClass : null, $requestPath)): ?>
    <link href="<?= htmlspecialchars(app_href('/assets/css/keuangan.css')) ?>" rel="stylesheet">
    <?php endif; ?>
    <script>
        (function () {
            const saved = localStorage.getItem('theme-mode');
            const mode = saved === 'dark' ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', mode);
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
                                <img src="<?= htmlspecialchars(app_href($appLogoSrc)) ?>" alt="Logo <?= htmlspecialchars($appBrandTitle) ?>" class="app-brand-logo" decoding="async" fetchpriority="high">
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
