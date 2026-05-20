<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/app_path.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$startUrl = app_public_url() . app_url('wali/index.php');
$scope = app_public_url() . app_url('wali/');

echo json_encode([
    'name' => 'Portal Wali Santri',
    'short_name' => 'Wali',
    'description' => 'Cek informasi santri dan tagihan bulanan pondok.',
    'start_url' => $startUrl,
    'scope' => $scope,
    'display' => 'standalone',
    'background_color' => '#f1f5f9',
    'theme_color' => '#0f766e',
    'lang' => 'id',
    'dir' => 'ltr',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
