<?php

declare(strict_types=1);

/**
 * Checklist hosting: settings bloat, WA cron/fallback, log tables.
 *
 *   php scripts/_diag_hosting_perf.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/wa_otomatis.php';
require_once __DIR__ . '/../helpers/laporan_snapshot.php';

echo "=== Hosting perf checklist ===\n\n";

$settingsCount = (int) ($pdo->query('SELECT COUNT(*) FROM app_settings')->fetchColumn() ?: 0);
$debounceLike = (int) ($pdo->query("
    SELECT COUNT(*) FROM app_settings
    WHERE setting_key LIKE 'wa_pb_scan_sent_%'
       OR setting_key LIKE 'wa_mw_scan_sent_%'
       OR setting_key LIKE 'wa_mudabir_missing_%'
       OR setting_key LIKE 'wa_kelas_kosong_%'
")->fetchColumn() ?: 0);

echo "app_settings total rows     : {$settingsCount}\n";
echo "app_settings debounce-like  : {$debounceLike}\n";
if ($settingsCount > 500) {
    echo "[WARN] Banyak baris app_settings — pertimbangkan cleanup + cache TTL (sudah di kode).\n";
}
if ($debounceLike > 200) {
    echo "[WARN] Banyak kunci debounce WA — pastikan cron WA berat jalan (wa_cleanup_old_debounce_keys).\n";
}

$fallback = trim((string) app_setting($pdo, 'wa_auto_web_fallback_enabled', '0')) === '1';
$cronRecent = function_exists('wa_auto_cron_recently_active') && wa_auto_cron_recently_active($pdo);
echo "\nwa_auto_web_fallback_enabled: " . ($fallback ? '1 (aktif)' : '0 (mati)') . "\n";
echo "wa cron recently active     : " . ($cronRecent ? 'yes' : 'no') . "\n";
if ($fallback && $cronRecent) {
    echo "[INFO] Fallback web + cron aktif — fallback sebaiknya dimatikan di WA Gateway.\n";
}
if ($fallback && !$cronRecent) {
    echo "[INFO] Fallback aktif karena cron belum terdeteksi — setup cron/wa_auto.php di hosting.\n";
}

echo 'wa_auto_last_run_at         : ' . trim((string) app_setting($pdo, 'wa_auto_last_run_at', '-')) . "\n";
echo 'wa_auto_scheduled_last_at   : ' . trim((string) app_setting($pdo, 'wa_auto_scheduled_last_at', '-')) . "\n";

$sheetStatus = laporan_snapshot_status($pdo);
echo "\nlaporan_snapshot_enabled    : " . (($sheetStatus['enabled'] ?? false) ? '1' : '0') . "\n";
echo 'laporan_snapshot_last_tick  : ' . trim((string) ($sheetStatus['last_cron_tick_at'] ?? '-')) . "\n";
echo 'laporan_snapshot_last_run_at: ' . trim((string) ($sheetStatus['last_run_at'] ?? '-')) . "\n";
echo 'laporan_snapshot_last_date  : ' . trim((string) ($sheetStatus['last_date'] ?? '-')) . "\n";
echo 'laporan_snapshot_last_error : ' . (trim((string) ($sheetStatus['last_error'] ?? '')) !== '' ? trim((string) $sheetStatus['last_error']) : '(kosong)') . "\n";
if ($sheetStatus['enabled'] ?? false) {
    echo 'sheet cron tick recent      : ' . (($sheetStatus['cron_tick_recent'] ?? false) ? 'yes' : 'no') . "\n";
    echo 'sheet cron OK               : ' . (($sheetStatus['cron_recently_active'] ?? false) ? 'yes' : 'no') . "\n";
    echo 'sheet cron stale            : ' . (($sheetStatus['cron_stale'] ?? false) ? 'yes' : 'no') . "\n";
}
echo "Detail cron: php scripts/_diag_cron_wa_sheet.php\n";

foreach (['wa_logs', 'push_logs', 'wa_dispatch_log'] as $tbl) {
    if (!table_exists($pdo, $tbl)) {
        echo "{$tbl}: (no table)\n";
        continue;
    }
    $n = (int) ($pdo->query('SELECT COUNT(*) FROM `' . preg_replace('/[^a-zA-Z0-9_]/', '', $tbl) . '`')->fetchColumn() ?: 0);
    echo "{$tbl} rows               : {$n}\n";
}

echo "\nPONDOK_SCHEMA_READY env     : " . (trim((string) getenv('PONDOK_SCHEMA_READY')) === '1' ? '1' : '(not set)') . "\n";
echo 'pondok_schema_deploy_ready  : ' . app_setting($pdo, 'pondok_schema_deploy_ready', '0') . "\n";
echo 'settings shared cache TTL   : ' . app_settings_shared_cache_ttl() . " sec\n";
echo "\nTTFB di browser: DevTools → Network → dashboard.php (document TTFB).\n";
echo "Done.\n";
