<?php

require_once __DIR__ . '/../../helpers/app.php';

/** @var string|null $settingsNavActive */
$settingsNavActive = isset($settingsNavActive) ? (string) $settingsNavActive : '';
$settingsNavItems = settings_pengaturan_nav_items(isset($pdo) && $pdo instanceof PDO ? $pdo : null);
if ($settingsNavItems === []) {
    return;
}
?>
<section class="settings-subnav-hub menu-hub-section my-4 py-3" aria-label="Submenu pengaturan">
    <p class="text-center text-muted small text-uppercase fw-bold mb-3 settings-subnav-hub-title">Menu pengaturan</p>
    <div class="row g-3 justify-content-center">
        <?php foreach ($settingsNavItems as $item): ?>
            <?php
            $path = (string) ($item['path'] ?? '');
            $active = $settingsNavActive !== '' && $path === $settingsNavActive;
            $tileIcon = menu_tile_icon_for_path($path);
            $tileLabel = (string) ($item['label'] ?? '');
            ?>
            <div class="col-6 col-sm-5 col-md-4 col-lg-3">
                <a href="<?= htmlspecialchars($path) ?>" class="menu-hub-tile card h-100 text-decoration-none shadow-sm border-0<?= $active ? ' menu-hub-tile--active' : '' ?>">
                    <div class="card-body d-flex align-items-start gap-3 p-3">
                        <span class="menu-hub-tile-icon" aria-hidden="true"><i class="<?= htmlspecialchars($tileIcon) ?>"></i></span>
                        <div class="min-w-0 flex-grow-1">
                            <div class="fw-bold text-dark menu-hub-tile-label"><?= htmlspecialchars($tileLabel) ?></div>
                        </div>
                        <?php if (!$active): ?>
                            <span class="menu-hub-tile-go text-muted" aria-hidden="true"><i class="fa-solid fa-arrow-right"></i></span>
                        <?php endif; ?>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
</section>
