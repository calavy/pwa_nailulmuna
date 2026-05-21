<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../includes/auth.php';

require_roles(['admin', 'pengurus', 'petugas_absensi']);

$menuPack = require __DIR__ . '/../includes/menu_data.php';
$menuItems = filter_menu_items_by_acl($pdo, $menuPack['menuItems'], $menuPack['permissionPathMap']);
$menuStructure = $menuPack['menuStructure'];

$hubId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_GET['id'] ?? ''));
$groupNode = null;
foreach ($menuStructure as $node) {
    if (($node['type'] ?? '') === 'group' && ($node['id'] ?? '') === $hubId) {
        $groupNode = $node;
        break;
    }
}
if (!is_array($groupNode)) {
    set_flash('error', 'Menu tidak ditemukan.');
    header('Location: ' . app_href('/dashboard.php'));
    exit;
}

$visiblePaths = menu_group_visible_paths($groupNode, $menuItems);
if ($visiblePaths === []) {
    set_flash('error', 'Anda tidak memiliki akses ke modul ini.');
    header('Location: ' . app_href('/dashboard.php'));
    exit;
}

$hubSections = [];
$sections = $groupNode['sections'] ?? null;
if (is_array($sections) && $sections !== []) {
    foreach ($sections as $sec) {
        $paths = array_values(array_filter(
            (array) ($sec['paths'] ?? []),
            static fn($p): bool => is_string($p) && isset($menuItems[$p])
        ));
        if ($paths !== []) {
            $hubSections[] = [
                'title' => trim((string) ($sec['title'] ?? '')),
                'paths' => $paths,
            ];
        }
    }
}
if ($hubSections === []) {
    $hubSections[] = ['title' => '', 'paths' => $visiblePaths];
}

$groupLabel = (string) ($groupNode['label'] ?? 'Modul');
$groupIcon = (string) ($groupNode['icon'] ?? 'fa-solid fa-layer-group');
$pageTitle = $groupLabel;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="menu-hub-hero card border-0 shadow-sm mb-4 text-white overflow-hidden">
    <div class="menu-hub-hero-bg"></div>
    <div class="card-body position-relative py-4 px-4">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="menu-hub-hero-icon" aria-hidden="true">
                <i class="<?= htmlspecialchars($groupIcon) ?>"></i>
            </div>
            <div>
                <p class="menu-hub-hero-kicker mb-1 text-white-50 small text-uppercase fw-bold">Submenu modul</p>
                <h1 class="h3 mb-1 fw-bold"><?= htmlspecialchars($groupLabel) ?></h1>
                <p class="mb-0 text-white-50 small">Pilih salah satu tautan di bawah untuk membuka halaman terkait.</p>
            </div>
        </div>
    </div>
</div>

<?php foreach ($hubSections as $sec): ?>
    <section class="menu-hub-section mb-4">
        <?php if ($sec['title'] !== ''): ?>
            <h2 class="menu-hub-section-title h6 text-uppercase text-muted fw-bold mb-3"><?= htmlspecialchars($sec['title']) ?></h2>
        <?php endif; ?>
        <div class="row g-3">
            <?php foreach ($sec['paths'] as $path): ?>
                <?php
                $tileIcon = menu_tile_icon_for_path($path);
                $tileLabel = (string) ($menuItems[$path] ?? $path);
                ?>
                <div class="col-12 col-sm-6 col-xl-4">
                    <a href="<?= htmlspecialchars(app_href($path)) ?>" class="menu-hub-tile card h-100 text-decoration-none shadow-sm border-0">
                        <div class="card-body d-flex align-items-start gap-3">
                            <span class="menu-hub-tile-icon" aria-hidden="true"><i class="<?= htmlspecialchars($tileIcon) ?>"></i></span>
                            <div class="min-w-0 flex-grow-1">
                                <div class="fw-bold text-dark menu-hub-tile-label"><?= htmlspecialchars($tileLabel) ?></div>
                                <div class="small text-muted mt-1">Buka halaman terkait</div>
                            </div>
                            <span class="menu-hub-tile-go text-muted" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span>
                        </div>
                    </a>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
<?php endforeach; ?>

<div class="d-flex flex-wrap gap-2 align-items-center">
    <a href="<?= htmlspecialchars(app_href('/dashboard.php')) ?>" class="btn btn-outline-secondary btn-sm rounded-pill">
        <i class="fa-solid fa-house me-1"></i> Dashboard
    </a>
    <span class="small text-muted">Atau gunakan menu modul di samping kiri.</span>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
