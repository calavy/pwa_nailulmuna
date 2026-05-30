<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/pwa_brand.php';

$size = (int) ($_GET['size'] ?? 192);
if (!in_array($size, [180, 192, 512], true)) {
    $size = 192;
}
$maskable = isset($_GET['maskable']) && (string) $_GET['maskable'] === '1';

$theme = app_pwa_theme($pdo);
$bg = (string) ($theme['background_color'] ?? '#0d9488');
$scale = $maskable ? 0.72 : 0.84;

$relStored = pwa_brand_icon_relative_path($pdo, $size, $maskable);
if ($relStored !== '') {
    $full = dirname(__DIR__, 2) . '/' . ltrim($relStored, '/');
    if (is_file($full)) {
        header('Content-Type: image/png');
        header('Cache-Control: public, max-age=86400');
        readfile($full);
        exit;
    }
}

$logoPath = trim((string) app_setting($pdo, 'logo_path', ''));
$source = $logoPath !== ''
    ? dirname(__DIR__, 2) . '/' . ltrim(str_replace(['\\', '..'], ['/', ''], $logoPath), '/')
    : dirname(__DIR__, 2) . '/assets/img/stempel-pondok.png';

$png = pwa_brand_render_square_png($source, $size, $bg, $scale, $maskable);
if ($png === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Ikon tidak tersedia';
    exit;
}

header('Content-Type: image/png');
header('Cache-Control: public, max-age=3600');
echo $png;
