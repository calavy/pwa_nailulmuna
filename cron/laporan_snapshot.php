<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/laporan_snapshot.php';

date_default_timezone_set('Asia/Jakarta');

ensure_pondok_settings_defaults($pdo);

$configuredKey = trim((string) app_setting($pdo, 'laporan_snapshot_cron_key', ''));
$providedKey = trim((string) ($_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? ''));
$isCli = PHP_SAPI === 'cli';

if (!$isCli && $configuredKey !== '' && !hash_equals($configuredKey, $providedKey)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$tick = laporan_snapshot_run_tick($pdo);

$timestamp = date('Y-m-d H:i:s');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}
$mode = (string) ($tick['mode'] ?? 'idle');
$note = trim((string) ($tick['note'] ?? ''));
echo 'OK laporan_snapshot ' . $timestamp . ' ' . $mode . ($note !== '' ? ' ' . $note : '') . "\n";
