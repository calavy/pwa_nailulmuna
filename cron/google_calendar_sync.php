<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/google_calendar_sync.php';

date_default_timezone_set('Asia/Jakarta');

ensure_pondok_settings_defaults($pdo);

$configuredKey = trim((string) app_setting($pdo, 'google_calendar_cron_key', ''));
$providedKey = trim((string) ($_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? ''));
$isCli = PHP_SAPI === 'cli';

if (!$isCli && $configuredKey !== '' && !hash_equals($configuredKey, $providedKey)) {
    http_response_code(403);
    echo "Forbidden\n";
    exit;
}

$full = isset($_GET['full']) && (string) $_GET['full'] === '1';
$result = google_calendar_sync_run($pdo, $full);

$timestamp = date('Y-m-d H:i:s');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}
if ($result['ok']) {
    echo 'OK google_calendar_sync ' . $timestamp . ' pulled=' . (int) $result['pulled'] . "\n";
} else {
    echo 'ERR google_calendar_sync ' . $timestamp . ' ' . (string) $result['error'] . "\n";
}
