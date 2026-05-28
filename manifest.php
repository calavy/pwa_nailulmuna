<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers/app_path.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/app.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$startUrl = app_public_url() . app_url('index.php');
$scope = app_public_url() . app_url('');
$logoSrc = app_pondok_logo_src($pdo);
if ($logoSrc === '') {
    $logoSrc = '/assets/img/stempel-pondok.png';
}
$iconUrl = app_public_url() . app_url(ltrim($logoSrc, '/'));

$iconExt = strtolower(pathinfo(parse_url($iconUrl, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
$iconType = 'image/png';
if ($iconExt === 'jpg' || $iconExt === 'jpeg') {
    $iconType = 'image/jpeg';
} elseif ($iconExt === 'webp') {
    $iconType = 'image/webp';
}

echo json_encode([
    'id' => $scope,
    'name' => 'Nailul Muna App',
    'short_name' => 'Nailul Muna',
    'description' => 'Aplikasi manajemen santri, keuangan, dan presensi pondok.',
    'start_url' => $startUrl,
    'scope' => $scope,
    'display' => 'standalone',
    'categories' => ['education', 'productivity'],
    'orientation' => 'portrait-primary',
    'background_color' => '#f1f5f9',
    'theme_color' => '#0f766e',
    'lang' => 'id',
    'dir' => 'ltr',
    'icons' => [
        [
            'src' => $iconUrl,
            'sizes' => '192x192',
            'type' => $iconType,
            'purpose' => 'any',
        ],
        [
            'src' => $iconUrl,
            'sizes' => '512x512',
            'type' => $iconType,
            'purpose' => 'any',
        ],
        [
            'src' => $iconUrl,
            'sizes' => '512x512',
            'type' => $iconType,
            'purpose' => 'maskable',
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
