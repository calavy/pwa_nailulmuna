<?php

declare(strict_types=1);

/** @var array<int, array<string, mixed>> $menuStructure */
/** @var array<string, string> $menuItems */
/** @var string $requestPath */
/** @var bool $isMunawibPortal */

require_once __DIR__ . '/../../helpers/pembimbing_dashboard_home_ux.php';

if (!function_exists('render_app_sidebar_nav')) {
    return;
}

$isMunawibPortal = !empty($isMunawibPortal);
$pbDashMenuStructure = $menuStructure;
$onPbDashHome = $requestPath === '/pembimbing/dashboard.php'
    || str_starts_with($requestPath, '/pembimbing/dashboard');
if ($onPbDashHome) {
    $pbDashMenuStructure = array_values(array_filter(
        $menuStructure,
        static function (array $node) use ($isMunawibPortal): bool {
            $id = (string) ($node['id'] ?? '');
            if ($id === 'menu-grp-pb-beranda') {
                return false;
            }
            if ($isMunawibPortal) {
                return in_array($id, ['menu-grp-pb-santri', 'menu-grp-pb-kegiatan'], true);
            }

            return true;
        }
    ));
}

if ($pbDashMenuStructure === []) {
    return;
}

$groupHints = [];
foreach ($pbDashMenuStructure as $node) {
    if (($node['type'] ?? '') !== 'group') {
        continue;
    }
    $gid = (string) ($node['id'] ?? '');
    $hint = pembimbing_dashboard_home_group_hint($gid);
    if ($hint !== '') {
        $groupHints[$gid] = $hint;
    }
}

?>
<div class="pb-dash-all-menu-panel__inner pb-dash-menu-groups">
    <p class="pb-dash-menu-groups__lead mb-2">Ketuk grup untuk melihat pilihan</p>
    <?php
    render_app_sidebar_nav($pbDashMenuStructure, $menuItems, $requestPath, [
        'mode' => 'accordion',
        'context' => 'dashboard',
        'collapse_default' => true,
        'group_hints' => $groupHints,
        'item_label_map' => pembimbing_dashboard_home_friendly_menu_labels(),
        'embed_aria_label' => 'Semua menu',
    ]);
    ?>
</div>
