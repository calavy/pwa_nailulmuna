<?php

declare(strict_types=1);

/**
 * Ukur waktu load bundle dashboard pengasuh (cache miss vs hit).
 *
 * Usage:
 *   php scripts/_diag_pengasuh_dashboard_timing.php
 *   php scripts/_diag_pengasuh_dashboard_timing.php refresh
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/pengasuh_dashboard.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$_SESSION['user'] = $_SESSION['user'] ?? ['id' => 1, 'nama' => 'Diag'];

$today = date('Y-m-d');
$nowTime = date('H:i:s');
$userId = (int) ($_SESSION['user']['id'] ?? 0);
$cacheKey = 'pg_dash_v1_' . $today . '_' . $userId;

if (($argv[1] ?? '') === 'refresh') {
    unset($_SESSION[$cacheKey]);
    echo "Cache cleared for {$cacheKey}\n";
}

unset($_SESSION[$cacheKey]);
$t0 = microtime(true);
$miss = pengasuh_dashboard_page_bundle($pdo, $today, $nowTime, true);
$t1 = microtime(true);
$hit = pengasuh_dashboard_page_bundle($pdo, $today, $nowTime, true);
$t2 = microtime(true);

$msMiss = (int) round(($t1 - $t0) * 1000);
$msHit = (int) round(($t2 - $t1) * 1000);

echo 'Bundle miss (no cache): ' . $msMiss . " ms\n";
echo 'Bundle hit (session):   ' . $msHit . " ms\n";
echo 'cache_hit flag: ' . (($hit['cache_hit'] ?? false) ? 'yes' : 'no') . "\n";
echo 'kegiatan aktif: ' . count($miss['kegiatanAktif'] ?? []) . "\n";

exit($msHit < $msMiss ? 0 : 0);
