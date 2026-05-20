<?php

$target = '/pwa_nailulmuna/settings/pesantren.php';
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
if (str_contains($uri, '#peraturan')) {
    $target = '/pwa_nailulmuna/settings/peraturan.php';
} elseif (str_contains($uri, '#master')) {
    $target = '/pwa_nailulmuna/menu/menu_hub.php?id=menu-grp-pengaturan';
}
header('Location: ' . $target, true, 302);
exit;
