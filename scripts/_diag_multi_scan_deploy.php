<?php

declare(strict_types=1);

/**
 * Verifikasi deploy Multi Scan: asset ada, cache-bust ?v=mtime, fitur JS kamera.
 *
 *   php scripts/_diag_multi_scan_deploy.php
 *   php scripts/_diag_multi_scan_deploy.php https://pwa.nailulmuna.id
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app_path.php';
require_once __DIR__ . '/../helpers/pwa_offline.php';

$baseUrl = trim((string) ($argv[1] ?? ''));
$root = dirname(__DIR__);

$assets = [
    '/assets/js/login-scan-kegiatan.js',
    '/assets/js/presensi-scan-camera.js',
    '/assets/css/presensi-scan.css',
    '/assets/css/auth-portal.css',
];

echo "=== Multi Scan deploy check ===\n\n";

$allOk = true;
foreach ($assets as $rel) {
    $full = $root . $rel;
    $exists = is_file($full);
    $mtime = $exists ? date('Y-m-d H:i:s', (int) filemtime($full)) : '-';
    $ver = app_asset_version($rel);
    $href = app_asset_href($rel);
    echo "{$rel}\n";
    echo "  file     : " . ($exists ? 'OK' : 'MISSING') . " (mtime {$mtime})\n";
    echo "  app href : {$href}\n";
    if (!$exists) {
        $allOk = false;
    }
}

$loginJs = $root . '/assets/js/login-scan-kegiatan.js';
if (is_file($loginJs)) {
    $src = (string) file_get_contents($loginJs);
    $flags = [
        'getScanConfig' => str_contains($src, 'getScanConfig'),
        'confirmHits: 1' => str_contains($src, 'confirmHits: 1'),
        'deferStartOnMobile' => str_contains($src, 'deferStartOnMobile: true'),
        'login_scan_camera_id' => str_contains($src, 'login_scan_camera_id'),
        'loginScanQrBox' => str_contains($src, 'loginScanQrBox'),
    ];
    echo "\nlogin-scan-kegiatan.js features:\n";
    foreach ($flags as $label => $ok) {
        echo '  ' . $label . ': ' . ($ok ? 'OK' : 'MISSING') . "\n";
        if (!$ok) {
            $allOk = false;
        }
    }
}

$camJs = $root . '/assets/js/presensi-scan-camera.js';
if (is_file($camJs)) {
    $src = (string) file_get_contents($camJs);
    $ok = str_contains($src, 'deferStartOnMobile && self.startBtn');
    echo "\npresensi-scan-camera.js mobile gate: " . ($ok ? 'OK' : 'MISSING') . "\n";
    if (!$ok) {
        $allOk = false;
    }
}

echo "\nPWA cache version (SW): " . (function_exists('pwa_cache_version') ? pwa_cache_version() : '(n/a)') . "\n";

if ($baseUrl !== '') {
    $baseUrl = rtrim($baseUrl, '/');
    $loginScan = $baseUrl . '/login.php?scan=1';
    echo "\nRemote HTML: {$loginScan}\n";
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'header' => "User-Agent: PWA-MultiScan-Deploy/1\r\n"]]);
    $html = @file_get_contents($loginScan, false, $ctx);
    if ($html === false) {
        echo "  [WARN] Tidak bisa fetch (SSL/network).\n";
    } else {
        foreach (['login-scan-kegiatan.js', 'presensi-scan-camera.js'] as $needle) {
            $has = str_contains($html, $needle);
            $hasV = (bool) preg_match('/' . preg_quote($needle, '/') . '\?v=\d+/', $html)
                || (bool) preg_match('/' . preg_quote($needle, '/') . '&v=\d+/', $html);
            echo "  {$needle}: " . ($has ? 'linked' : 'NOT in HTML') . ', cache-bust v= ' . ($hasV ? 'yes' : 'NO') . "\n";
            if (!$has || !$hasV) {
                $allOk = false;
            }
        }
        echo '  btn-start-login-scan: ' . (str_contains($html, 'btn-start-login-scan') ? 'OK' : 'MISSING') . "\n";
    }
}

echo "\n" . ($allOk ? 'Result: OK — deploy siap / konsisten.' : 'Result: PERLU PERBAIKAN — lihat baris di atas.') . "\n";
echo "UAT HP: buka Multi Scan → ketuk Mulai scan kamera → preview → scan kartu (bip).\n";
