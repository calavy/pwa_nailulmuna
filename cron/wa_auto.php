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

trigger_auto_wa_notifications($pdo);
trigger_auto_wa_tagihan_wali($pdo);
trigger_push_tagihan_wali_from_cron($pdo);
trigger_push_daily_kiai($pdo);

$timestamp = date('Y-m-d H:i:s');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}
echo "OK wa_auto {$timestamp}\n";
