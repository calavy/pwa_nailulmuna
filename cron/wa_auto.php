<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/push_events.php';

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
app_run_deferred_maintenance($pdo, $sysUserId);

trigger_auto_wa_notifications($pdo);
trigger_auto_wa_tagihan_wali($pdo);
trigger_wa_mudabir_belum_hadir($pdo);
trigger_wa_kelas_kosong_bertahap($pdo);
trigger_push_tagihan_wali_from_cron($pdo);
trigger_push_daily_kiai($pdo);

$timestamp = date('Y-m-d H:i:s');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}
echo "OK wa_auto {$timestamp}\n";
