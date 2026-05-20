<?php

require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_typography.php';
$currentUser = $_SESSION['user']['nama'] ?? 'Guest';
$currentRole = $_SESSION['user']['role'] ?? 'admin';
if (isset($_SESSION['user']) && (($_SESSION['user']['username'] ?? '') === 'admin') && !isset($_SESSION['user']['is_super_admin'])) {
    $_SESSION['user']['is_super_admin'] = 1;
}
$requestPath = $_SERVER['REQUEST_URI'] ?? '';
$menuPack = require __DIR__ . '/menu_data.php';
$menuItems = filter_menu_items_by_acl($pdo, $menuPack['menuItems'], $menuPack['permissionPathMap']);
$menuStructure = $menuPack['menuStructure'];
$permissionPathMap = $menuPack['permissionPathMap'];
if (isset($_SESSION['user']) && table_exists($pdo, 'users')) {
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_super_admin TINYINT(1) NOT NULL DEFAULT 0");
}
if (isset($_SESSION['user']) && table_exists($pdo, 'users') && !table_exists($pdo, 'user_access_permissions')) {
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS user_access_permissions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            permission_key VARCHAR(80) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_permission (user_id, permission_key),
            CONSTRAINT fk_user_access_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )
    ');
}
if (isset($_SESSION['user'])) {
    enforce_route_acl_or_redirect($pdo, $requestPath, $permissionPathMap);
    require_once __DIR__ . '/../helpers/pengaturan_acl.php';
    migrate_legacy_permissions_to_pengaturan($pdo);
}

if (isset($_SESSION['user']) && table_exists($pdo, 'jadwal_kegiatan')) {
    ensure_jadwal_kegiatan_tempat($pdo);
}
if (isset($_SESSION['user']) && table_exists($pdo, 'jadwal_kegiatan') && table_exists($pdo, 'kegiatan')) {
    sync_presence_for_active_schedules(
        $pdo,
        date('Y-m-d'),
        date('H:i:s'),
        (int) ($_SESSION['user']['id'] ?? 1)
    );
}
if (isset($_SESSION['user'])) {
    ensure_point_tables($pdo);
    sync_points_from_presensi($pdo, (int) ($_SESSION['user']['id'] ?? 1));
    trigger_auto_wa_notifications($pdo);
    trigger_auto_wa_tagihan_wali($pdo);
}

$appBrandTagline = '';
$appBrandTitle = 'A.P.I Nailul Muna';
if (isset($_SESSION['user'])) {
    $appBrandTagline = trim((string) app_setting($pdo, 'jenis_pendidikan', ''));
    $appBrandTitle = app_brand_nama_ponpes($pdo, $appBrandTitle);
}

if (!function_exists('render_app_sidebar_nav')) {
    function render_app_sidebar_nav(array $structure, array $items, string $requestPath): void
    {
        $hubBase = app_url('menu/menu_hub.php');
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
                echo '<a class="app-side-nav-item' . ($active ? ' active' : '') . '" href="' . htmlspecialchars($path) . '">'
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
                $href = $hubBase . '?id=' . rawurlencode($gid);
                $active = menu_sidebar_group_is_active($node, $requestPath, $items);
                echo '<a class="app-side-nav-item app-side-nav-item--hub' . ($active ? ' active' : '') . '" href="' . htmlspecialchars($href) . '">'
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'Manajemen Santri') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" rel="stylesheet">
    <link href="/assets/css/app.css" rel="stylesheet">
    <?php if (!empty($pageStylesheets) && is_array($pageStylesheets)): ?>
        <?php foreach ($pageStylesheets as $pageStylesheetHref): ?>
    <link href="<?= htmlspecialchars((string) $pageStylesheetHref) ?>" rel="stylesheet">
        <?php endforeach; ?>
    <?php endif; ?>
    <?php if (keuangan_should_load_typography_css(isset($bodyClass) ? (string) $bodyClass : null, $requestPath)): ?>
    <link href="/assets/css/keuangan.css" rel="stylesheet">
    <?php endif; ?>
    <script>
        (function () {
            const saved = localStorage.getItem('theme-mode');
            const mode = saved === 'dark' ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', mode);
        })();
    </script>
</head>
<body<?= isset($bodyClass) && trim((string) $bodyClass) !== '' ? ' class="' . htmlspecialchars(trim((string) $bodyClass)) . '"' : '' ?>>
<nav class="navbar navbar-dark app-topbar mb-3">
    <div class="container-fluid px-3 px-md-4">
        <div class="app-topbar-left">
            <button class="btn btn-light btn-sm d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-label="Buka menu">
                <span class="app-topbar-icon" aria-hidden="true">&#9776;</span>
                <span class="d-none d-sm-inline ms-1">Menu</span>
            </button>
            <button class="btn btn-outline-light btn-sm d-none d-lg-inline-flex" type="button" id="sidebar-toggle-btn" aria-label="Sembunyikan menu samping">
                <span class="app-topbar-icon" aria-hidden="true">&#9776;</span>
                <span class="ms-2">Menu samping</span>
            </button>
            <a href="/dashboard.php" class="app-brand-link">
                <span class="app-brand-title"><?= htmlspecialchars($appBrandTitle) ?></span>
                <?php if ($appBrandTagline !== ''): ?>
                    <span class="app-brand-tagline"><?= htmlspecialchars($appBrandTagline) ?></span>
                <?php endif; ?>
            </a>
            <span class="app-topbar-title d-none d-md-inline-flex"><?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></span>
        </div>
        <div class="app-topbar-right">
            <span class="app-topbar-user d-none d-sm-inline-flex" title="<?= htmlspecialchars($currentUser) ?>"><?= htmlspecialchars($currentUser) ?></span>
            <?php if (isset($_SESSION['user'])): ?>
                <button type="button" class="btn btn-sm btn-outline-light" id="btn-fcm-subscribe" title="Aktifkan notifikasi push"><i class="fa-solid fa-bell"></i></button>
                <a class="btn btn-sm btn-outline-light" href="/logout.php">Keluar</a>
            <?php endif; ?>
        </div>
    </div>
</nav>
<div class="app-shell px-2 px-md-3 pb-5" id="app-shell">
    <aside class="app-sidebar d-none d-lg-block">
        <div class="app-sidebar-inner">
            <?php render_app_sidebar_nav($menuStructure, $menuItems, $requestPath); ?>
        </div>
    </aside>
    <div class="offcanvas offcanvas-start" tabindex="-1" id="mobileSidebar">
        <div class="offcanvas-header">
            <h5 class="offcanvas-title">Menu Aplikasi</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas"></button>
        </div>
        <div class="offcanvas-body">
            <div class="d-grid gap-1 app-sidebar-mobile">
                <?php render_app_sidebar_nav($menuStructure, $menuItems, $requestPath); ?>
            </div>
        </div>
    </div>
    <main class="app-content app-main">
    <?php if ($success = get_flash('success')): ?>
        <div class="alert alert-success app-flash mb-3" role="alert"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    <?php if ($error = get_flash('error')): ?>
        <div class="alert alert-danger app-flash mb-3" role="alert"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <script>
        (function () {
            const shell = document.getElementById('app-shell');
            const btn = document.getElementById('sidebar-toggle-btn');
            if (!shell || !btn) return;
            const key = 'sidebar-collapsed';
            const saved = localStorage.getItem(key);
            if (saved === '1') {
                shell.classList.add('sidebar-collapsed');
                const span = btn.querySelector('span.ms-2');
                if (span) span.textContent = 'Tampilkan menu';
            }
            btn.addEventListener('click', function () {
                shell.classList.toggle('sidebar-collapsed');
                const collapsed = shell.classList.contains('sidebar-collapsed');
                localStorage.setItem(key, collapsed ? '1' : '0');
                const span = btn.querySelector('span.ms-2');
                if (span) span.textContent = collapsed ? 'Tampilkan menu' : 'Menu samping';
            });
        })();
    </script>
