<?php

declare(strict_types=1);

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

echo json_encode([
    'name' => 'Portal Wali Santri',
    'short_name' => 'Wali',
    'description' => 'Cek informasi santri dan tagihan bulanan pondok.',
    'start_url' => '/pwa_nailulmuna/wali/index.php',
    'scope' => '/pwa_nailulmuna/wali/',
    'display' => 'standalone',
    'background_color' => '#f1f5f9',
    'theme_color' => '#0f766e',
    'lang' => 'id',
    'dir' => 'ltr',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
