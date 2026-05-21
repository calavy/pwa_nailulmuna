<?php

$target = '/settings/pesantren.php';
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
if (str_contains($uri, '#peraturan')) {
    $target = '/settings/peraturan.php';
} elseif (str_contains($uri, '#master')) {
    $target = '/menu/menu_hub.php?id=menu-grp-pengaturan';
}
require_once __DIR__ . '/../helpers/app_path.php';
app_redirect_path($target);
