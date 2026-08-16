<?php

$target = '/menu/menu_hub.php?id=menu-grp-pengaturan';
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
if (str_contains($uri, '#peraturan')) {
    $target = '/settings/peraturan.php';
}
require_once __DIR__ . '/../helpers/app_path.php';
app_redirect_path($target);
