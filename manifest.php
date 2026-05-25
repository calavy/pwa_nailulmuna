<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers/app_path.php';

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$startUrl = app_public_url() . app_url('index.php');
$scope = app_public_url() . app_url('');

echo json_encode([
    'name' => 'Manajemen Pondok',
    'short_name' => 'Pondok',
    'description' => 'Aplikasi manajemen santri, keuangan, dan presensi pondok.',
    'start_url' => $startUrl,
    'scope' => $scope,
    'display' => 'standalone',
    'background_color' => '#f1f5f9',
    'theme_color' => '#0f766e',
    'lang' => 'id',
    'dir' => 'ltr',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
