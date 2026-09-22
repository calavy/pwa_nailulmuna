<?php

declare(strict_types=1);

/**
 * Verifikasi cron WA otomatis + push Google Sheet (tanpa buka app di browser).
 *
 *   php scripts/_diag_cron_wa_sheet.php
 *   php scripts/_diag_cron_wa_sheet.php --run-wa
 *   php scripts/_diag_cron_wa_sheet.php --run-sheet
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/wa_otomatis.php';
require_once __DIR__ . '/../helpers/laporan_snapshot.php';

date_default_timezone_set('Asia/Jakarta');

$runWa = in_array('--run-wa', $argv ?? [], true);
$runSheet = in_array('--run-sheet', $argv ?? [], true);

if ($runWa) {
    require_once __DIR__ . '/../cron/wa_auto.php';
    exit;
}
if ($runSheet) {
    require_once __DIR__ . '/../cron/laporan_snapshot.php';
    exit;
}

echo "=== Cron WA + Google Sheet (no browser required) ===\n\n";

$waLast = trim((string) app_setting($pdo, 'wa_auto_last_run_at', ''));
$waStale = wa_auto_cron_is_stale($pdo);
$waRecent = wa_auto_cron_recently_active($pdo);
$waAge = $waLast !== '' ? (time() - (int) strtotime($waLast)) : null;

echo "[WA otomatis]\n";
echo '  wa_auto_last_run_at      : ' . ($waLast !== '' ? $waLast : '(kosong)') . "\n";
echo '  cron age (sec)           : ' . ($waAge !== null ? (string) $waAge : '—') . "\n";
echo '  cron recently active     : ' . ($waRecent ? 'yes' : 'no') . ' (stale after ' . wa_auto_cron_stale_after_sec() . " sec)\n";
echo '  status                   : ' . ($waRecent ? 'OK' : ($waLast === '' ? 'NEVER' : 'STALE')) . "\n";

$fallback = trim((string) app_setting($pdo, 'wa_auto_web_fallback_enabled', '0')) === '1';
echo '  wa_auto_web_fallback     : ' . ($fallback ? '1 ON' : '0 off') . "\n";
if ($fallback && $waRecent) {
    echo "  [WARN] Fallback ON + cron aktif — risiko dobel. Cron HTTP/CLI atau buka WA Otomatis akan mematikan otomatis.\n";
}
if (!$waRecent) {
    echo "  [ACTION] Jadwalkan cron/wa_auto.php tiap 1–5 menit (CLI atau HTTP + wa_auto_cron_key).\n";
}

$sheet = laporan_snapshot_status($pdo);
echo "\n[Google Sheet snapshot]\n";
echo '  enabled                  : ' . (($sheet['enabled'] ?? false) ? 'yes' : 'no') . "\n";
echo '  jam push (WIB)           : ' . (string) ($sheet['jam'] ?? '00:00') . "\n";
echo '  send_time_ok             : ' . (($sheet['send_time_ok'] ?? false) ? 'yes' : 'no') . "\n";
echo '  last_date (sukses)       : ' . ((string) ($sheet['last_date'] ?? '') !== '' ? (string) $sheet['last_date'] : '(kosong)') . "\n";
echo '  last_run_at              : ' . ((string) ($sheet['last_run_at'] ?? '') !== '' ? (string) $sheet['last_run_at'] : '(kosong)') . "\n";
$sheetErr = trim((string) ($sheet['last_error'] ?? ''));
echo '  last_error               : ' . ($sheetErr !== '' ? $sheetErr : '(kosong)') . "\n";
if ($sheet['enabled'] ?? false) {
    echo '  cron recently active     : ' . (($sheet['cron_recently_active'] ?? false) ? 'yes' : 'no') . "\n";
    echo '  status                   : ' . (($sheet['cron_stale'] ?? true) ? 'STALE/cek' : 'OK') . "\n";
    if ($sheet['cron_stale'] ?? false) {
        echo "  [ACTION] Pastikan cron/laporan_snapshot.php jalan tiap menit; setelah jam push cek last_run_at & last_error.\n";
    }
} else {
    echo "  (otomatis mati — hanya manual di Pengaturan → Snapshot)\n";
}

echo "\n[Perf vs cron]\n";
echo '  Browsing lebih ringan (cache settings, lazy FCM) tidak mematikan cron — cron memuat config/database.php langsung.' . "\n";

echo "\nTes manual:\n";
echo "  php cron/wa_auto.php\n";
echo "  php cron/laporan_snapshot.php\n";
echo "Done.\n";
