<?php

declare(strict_types=1);

/**
 * Regenerasi ikon PWA (lingkaran putih) + splash manifest putih — untuk production setelah deploy.
 *
 * Usage: php scripts/regenerate_pwa_icons.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pwa_brand.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Koneksi database gagal.\n");
    exit(1);
}

if (!function_exists('logo_ensure_pwa_icon_white_circle_v3')) {
    fwrite(STDERR, "Helper logo_ensure_pwa_icon_white_circle_v3 tidak ada.\n");
    exit(1);
}

logo_ensure_pwa_icon_white_circle_v3($pdo, true);

$bg = trim((string) app_setting($pdo, 'pwa_background_color', ''));
$ver = trim((string) app_setting($pdo, 'pwa_icons_cache_ver', ''));
$flag = trim((string) app_setting($pdo, 'pwa_icon_white_circle_v3', ''));

echo "Selesai.\n";
echo "  pwa_background_color: " . ($bg !== '' ? $bg : '(kosong)') . "\n";
echo "  pwa_icons_cache_ver: " . ($ver !== '' ? $ver : '(kosong)') . "\n";
echo "  pwa_icon_white_circle_v3: " . ($flag !== '' ? $flag : '(kosong)') . "\n";
echo "Cek manifest.php dan pasang ulang PWA di perangkat.\n";

exit(0);
