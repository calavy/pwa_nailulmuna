<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/wa_otomatis.php';

date_default_timezone_set('Asia/Jakarta');

ensure_pondok_settings_defaults($pdo);

$configuredKey = trim((string) app_setting($pdo, 'wa_auto_cron_key', ''));
$providedKey = trim((string) ($_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? ''));
$isCli = PHP_SAPI === 'cli';

if (!$isCli && $configuredKey !== '' && !hash_equals($configuredKey, $providedKey)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$tick = wa_auto_run_tick($pdo);

if (!$isCli) {
    wa_auto_disable_web_fallback($pdo, 'hosting_cron_http');
}

$timestamp = date('Y-m-d H:i:s');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}
$mode = ($tick['heavy'] ?? false) ? 'heavy' : (($tick['light'] ?? false) ? 'light' : 'idle');
echo 'OK wa_auto ' . $timestamp . ' ' . $mode . "\n";
