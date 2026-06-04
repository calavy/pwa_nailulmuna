<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/push_events.php';
require_once __DIR__ . '/../helpers/wa_pembimbing_scan.php';

date_default_timezone_set('Asia/Jakarta');

$configuredKey = trim((string) app_setting($pdo, 'wa_auto_cron_key', ''));
$providedKey = trim((string) ($_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? ''));
$isCli = PHP_SAPI === 'cli';

if (!$isCli && $configuredKey !== '' && !hash_equals($configuredKey, $providedKey)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$sysUserId = (int) ($pdo->query('SELECT id FROM users WHERE COALESCE(is_super_admin,0)=1 ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 0);
if ($sysUserId <= 0) {
    $sysUserId = (int) ($pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn() ?: 1);
}

$now = time();
save_setting($pdo, 'wa_auto_last_run_at', date('Y-m-d H:i:s'));

// Ringan — setiap tick (jendela waktu sempit)
trigger_wa_pembimbing_belum_scan($pdo);
trigger_wa_mudabir_belum_hadir($pdo);

$heavyInterval = max(300, (int) app_setting($pdo, 'wa_auto_heavy_interval_sec', '300'));
$lastHeavy = (int) app_setting($pdo, 'wa_auto_heavy_last_at', '0');
$runHeavy = $lastHeavy <= 0 || ($now - $lastHeavy) >= $heavyInterval;

if ($runHeavy) {
    app_run_deferred_maintenance($pdo, $sysUserId);
    trigger_push_tagihan_wali_from_cron($pdo);
    trigger_push_daily_kiai($pdo);

    $cleanupLast = trim((string) app_setting($pdo, 'wa_debounce_cleanup_last_date', ''));
    if ($cleanupLast !== date('Y-m-d')) {
        $removed = wa_cleanup_old_debounce_keys($pdo, 30);
        save_setting($pdo, 'wa_debounce_cleanup_last_date', date('Y-m-d'));
        if ($removed > 0) {
            save_setting($pdo, 'wa_debounce_cleanup_last_count', (string) $removed);
        }
    }

    save_setting($pdo, 'wa_auto_heavy_last_at', (string) $now);
    save_setting($pdo, 'wa_auto_last_heavy_at', date('Y-m-d H:i:s'));
}

$timestamp = date('Y-m-d H:i:s');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}
echo 'OK wa_auto ' . $timestamp . ($runHeavy ? ' heavy' : ' light') . "\n";
