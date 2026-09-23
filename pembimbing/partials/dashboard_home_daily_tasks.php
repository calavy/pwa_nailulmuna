<?php

declare(strict_types=1);

/** @var array<string, string> $menuItems */
/** @var array<int, array<string, mixed>> $menuStructure */
/** @var string $requestPath */
/** @var string $keaktivanUrl */
/** @var bool $isMunawibPortal */

require_once __DIR__ . '/../../helpers/pembimbing_dashboard_home_ux.php';

$menuItems = is_array($menuItems ?? null) ? $menuItems : [];
$menuStructure = is_array($menuStructure ?? null) ? $menuStructure : [];
$requestPath = (string) ($requestPath ?? '');
$isMunawibPortal = !empty($isMunawibPortal);
$keaktivanUrl = (string) ($keaktivanUrl ?? app_href('/pembimbing/dashboard.php?view=keaktivan'));
$tasks = pembimbing_dashboard_home_daily_tasks($menuItems, $keaktivanUrl, $isMunawibPortal);

$showAllMenu = function_exists('render_app_sidebar_nav') && $menuStructure !== [];
if ($tasks === [] && !$showAllMenu) {
    return;
}

?>
<section class="pb-dash-daily-tasks" aria-labelledby="pb-dash-daily-tasks-title">
    <h2 class="pb-dash-daily-tasks__title h6 mb-0" id="pb-dash-daily-tasks-title">Tugas hari ini</h2>
    <div class="pb-dash-daily-tasks__grid" role="group" aria-label="Tugas hari ini">
        <?php foreach ($tasks as $task): ?>
            <a href="<?= htmlspecialchars((string) $task['href']) ?>"
               class="pb-dash-daily-task <?= htmlspecialchars((string) $task['class']) ?>">
                <span class="pb-dash-daily-task__icon" aria-hidden="true"><i class="<?= htmlspecialchars((string) $task['icon']) ?>"></i></span>
                <span class="pb-dash-daily-task__label"><?= htmlspecialchars((string) $task['label']) ?></span>
            </a>
        <?php endforeach; ?>
        <?php if ($showAllMenu): ?>
        <button type="button"
                class="pb-dash-daily-task pb-dash-daily-task--menu js-pb-toggle-all-menu"
                aria-expanded="false"
                aria-controls="pb-dash-all-menu-panel">
            <span class="pb-dash-daily-task__icon" aria-hidden="true"><i class="fa-solid fa-bars"></i></span>
            <span class="pb-dash-daily-task__label">Semua menu</span>
        </button>
        <?php endif; ?>
    </div>
    <?php if ($showAllMenu): ?>
    <div id="pb-dash-all-menu-panel" class="pb-dash-all-menu-panel" hidden>
        <?php require __DIR__ . '/dashboard_home_menu_nav.php'; ?>
    </div>
    <?php endif; ?>
</section>
