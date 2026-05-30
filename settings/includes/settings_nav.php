<?php

require_once __DIR__ . '/../../helpers/app.php';

/** @var string|null $settingsNavActive */
$settingsNavActive = isset($settingsNavActive) ? (string) $settingsNavActive : '';
$settingsNavItems = settings_pengaturan_nav_items(isset($pdo) && $pdo instanceof PDO ? $pdo : null);
if ($settingsNavItems === []) {
    return;
}

$grouped = [];
$groupOrder = [];
foreach ($settingsNavItems as $item) {
    $group = trim((string) ($item['group'] ?? 'Lainnya'));
    if ($group === '') {
        $group = 'Lainnya';
    }
    if (!isset($grouped[$group])) {
        $grouped[$group] = [];
        $groupOrder[] = $group;
    }
    $grouped[$group][] = $item;
}
$navId = 'settings-subnav-' . substr(md5($settingsNavActive), 0, 8);
?>
<nav class="settings-subnav-compact mt-4 pt-3 border-top" aria-label="Submenu pengaturan">
    <button class="settings-subnav-compact__toggle btn btn-sm btn-outline-secondary w-100 d-flex align-items-center justify-content-between gap-2"
            type="button"
            data-bs-toggle="collapse"
            data-bs-target="#<?= htmlspecialchars($navId) ?>"
            aria-expanded="false"
            aria-controls="<?= htmlspecialchars($navId) ?>">
        <span><i class="fa-solid fa-sliders me-1"></i> Menu pengaturan</span>
        <span class="small text-muted"><?= count($settingsNavItems) ?> halaman</span>
        <i class="fa-solid fa-chevron-down settings-subnav-compact__chevron" aria-hidden="true"></i>
    </button>
    <div class="collapse" id="<?= htmlspecialchars($navId) ?>">
        <div class="settings-subnav-compact__grid pt-2">
            <?php foreach ($groupOrder as $groupLabel): ?>
                <p class="settings-subnav-compact__group small text-muted mb-1 mt-2"><?= htmlspecialchars($groupLabel) ?></p>
                <?php foreach ($grouped[$groupLabel] as $item): ?>
                    <?php
                    $path = (string) ($item['path'] ?? '');
                    $active = $settingsNavActive !== '' && $path === $settingsNavActive;
                    $tileIcon = menu_tile_icon_for_path($path);
                    $tileLabel = (string) ($item['label'] ?? '');
                    ?>
                    <a href="<?= htmlspecialchars(app_href($path)) ?>"
                       class="settings-subnav-compact__link<?= $active ? ' is-active' : '' ?>"
                       <?= $active ? ' aria-current="page"' : '' ?>>
                        <i class="<?= htmlspecialchars($tileIcon) ?>" aria-hidden="true"></i>
                        <span><?= htmlspecialchars($tileLabel) ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    </div>
</nav>
