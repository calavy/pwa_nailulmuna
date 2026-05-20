<?php

$target = '/settings/pesantren.php';
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
if (str_contains($uri, '#peraturan')) {
    $target = '/settings/peraturan.php';
} elseif (str_contains($uri, '#master')) {
    $target = '/menu/menu_hub.php?id=menu-grp-pengaturan';
}
header('Location: ' . $target, true, 302);
exit;
