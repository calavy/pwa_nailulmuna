<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers/app_path.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/app.php';
require_once __DIR__ . '/helpers/pwa_brand.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$startUrl = app_public_url() . app_url('index.php');
$scope = app_public_url() . app_url('');
$pwaTheme = app_pwa_theme($pdo);
$namaApp = app_pwa_app_name($pdo);

echo json_encode([
    'id' => $scope,
    'name' => $namaApp,
    'short_name' => app_pwa_short_name($pdo),
    'description' => 'Portal digital pondok — santri, keuangan, presensi, dan akademik.',
    'start_url' => $startUrl,
    'scope' => $scope,
    'display' => 'standalone',
    'display_override' => ['standalone', 'minimal-ui'],
    'categories' => ['education', 'productivity'],
    'orientation' => 'any',
    'background_color' => $pwaTheme['background_color'],
    'theme_color' => $pwaTheme['theme_color'],
    'lang' => 'id',
    'dir' => 'ltr',
    'icons' => pwa_brand_manifest_icons($pdo),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
