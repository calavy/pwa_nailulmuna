<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/backup_mingguan.php';

date_default_timezone_set('Asia/Jakarta');

ensure_pondok_settings_defaults($pdo);

$configuredKey = trim((string) app_setting($pdo, 'backup_mingguan_cron_key', ''));
$providedKey = trim((string) ($_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? ''));
$isCli = PHP_SAPI === 'cli';

if (!$isCli && $configuredKey !== '' && !hash_equals($configuredKey, $providedKey)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$result = backup_mingguan_run($pdo, false);
$timestamp = date('Y-m-d H:i:s');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}
$mode = (string) ($result['mode'] ?? 'idle');
$note = trim((string) ($result['note'] ?? ''));
$line = ($result['ok'] ? 'OK' : 'FAIL') . ' backup_mingguan ' . $timestamp . ' ' . $mode . ($note !== '' ? ' ' . $note : '');
if (!$result['ok'] && !$isCli) {
    http_response_code(500);
}
echo $line . "\n";
