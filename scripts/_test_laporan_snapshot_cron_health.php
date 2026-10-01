<?php

declare(strict_types=1);

/**
 * Smoke test kesehatan cron snapshot Google Sheet.
 * Exit 0 = OK, exit 1 = masalah konfigurasi/cron.
 *
 *   php scripts/_test_laporan_snapshot_cron_health.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/laporan_snapshot.php';

ensure_pondok_settings_defaults($pdo);

$checks = [];
$issues = [];
$recommendations = [];

$enabled = laporan_snapshot_enabled($pdo);
$checks['snapshot_enabled'] = $enabled;
if (!$enabled) {
    $issues[] = 'Snapshot otomatis nonaktif (laporan_snapshot_enabled=0)';
    $recommendations[] = 'Aktifkan snapshot di Pengaturan → Snapshot Laporan jika cron diperlukan.';
}

$lastTick = trim((string) app_setting($pdo, 'laporan_snapshot_last_cron_tick_at', ''));
$checks['cron_tick_set'] = $lastTick !== '';
if ($enabled && $lastTick === '') {
    $issues[] = 'Belum pernah menerima tick cron (laporan_snapshot_last_cron_tick_at kosong)';
}

$tickRecent = laporan_snapshot_cron_tick_recent($pdo);
$checks['cron_tick_recent'] = $tickRecent;
if ($enabled && !$tickRecent) {
    $ageLabel = laporan_snapshot_cron_tick_age_label($pdo);
    $issues[] = 'Tick cron basi (' . $ageLabel . '; ambang '
        . (int) (laporan_snapshot_cron_stale_after_sec() / 60) . ' menit)';
    $recommendations[] = 'Pasang crontab tiap menit: curl URL di Pengaturan → Perintah cron (dengan key jika diset).';
}

$cronActive = laporan_snapshot_cron_recently_active($pdo);
$checks['cron_recently_active'] = $cronActive;
if ($enabled && !$cronActive) {
    $issues[] = 'Cron snapshot tidak dianggap aktif (tick basi dan belum push sukses hari ini)';
}

$doHttpProbe = $enabled && (getenv('PWA_SNAPSHOT_CRON_PROBE_HTTP') === '1'
    || trim((string) getenv('APP_PUBLIC_URL')) !== '');
if ($doHttpProbe) {
    $httpProbe = laporan_snapshot_probe_http_cron($pdo);
    $checks['http_probe_ok'] = (bool) ($httpProbe['ok'] ?? false);
    if (!$httpProbe['ok']) {
        $issues[] = 'HTTP probe gagal: ' . trim((string) ($httpProbe['error'] ?? 'unknown'))
            . ' (HTTP ' . (int) ($httpProbe['http_code'] ?? 0) . ')';
        if ((int) ($httpProbe['http_code'] ?? 0) === 403) {
            $recommendations[] = 'Perbaiki Cron key HTTP di pengaturan dan di crontab hosting.';
        }
    }
}

echo "LAPORAN_SNAPSHOT_CRON_HEALTH\n";
foreach ($checks as $key => $val) {
    echo $key . ': ' . ($val ? 'OK' : 'FAIL') . "\n";
}
echo 'last_cron_tick_at: ' . ($lastTick !== '' ? $lastTick : '-') . "\n";
echo 'tick_age: ' . laporan_snapshot_cron_tick_age_label($pdo) . "\n";

if ($issues !== []) {
    echo "\nIssues:\n";
    foreach ($issues as $i) {
        echo ' - ' . $i . "\n";
    }
}
if ($recommendations !== []) {
    echo "\nRecommendations:\n";
    foreach (array_unique($recommendations) as $r) {
        echo ' - ' . $r . "\n";
    }
}

if (!$enabled) {
    exit(0);
}

exit($issues === [] ? 0 : 1);
