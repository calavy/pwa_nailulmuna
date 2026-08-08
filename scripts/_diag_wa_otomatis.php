<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/wa_otomatis.php';
require_once __DIR__ . '/../helpers/cashless_wa.php';
require_once __DIR__ . '/../helpers/alpa_tier.php';

wa_dispatch_ensure_schema($pdo);

$out = [];
$keys = [
    'wa_otomatis_master_enabled', 'wa_gateway_token', 'wa_gateway_url',
    'wa_auto_last_run_at', 'wa_auto_last_gateway_ok', 'wa_auto_last_gateway_error',
    'wa_auto_scheduled_last_at', 'wa_auto_alpa_last_result',
    'wa_auto_web_fallback_enabled', 'wa_dispatch_strict_mode', 'wa_auto_cron_key',
    'wa_auto_fallback_auto_disabled_at', 'wa_auto_fallback_auto_disabled_reason',
    'wa_auto_light_last_at', 'wa_auto_heavy_last_at', 'wa_auto_last_heavy_at',
    'cashless_transaksi_wa_enabled', 'cashless_saldo_rendah_wa_enabled',
    'cashless_laporan_harian_wa_enabled', 'cashless_laporan_harian_wa_jam',
    'cashless_laporan_harian_wa_targets', 'cashless_laporan_harian_last_sent_at',
    'cashless_laporan_harian_last_error', 'wa_pengurus', 'jam_kirim_wa_auto', 'batas_alpa_notif',
];
foreach ($keys as $k) {
    $v = app_setting($pdo, $k, '');
    if ($k === 'wa_gateway_token' && $v !== '') {
        $v = '[SET len=' . strlen((string) $v) . ']';
    }
    if ($k === 'wa_auto_cron_key' && $v !== '') {
        $v = '[SET len=' . strlen((string) $v) . ']';
    }
    $out['settings'][$k] = $v;
}

$out['gateway_error'] = wa_otomatis_gateway_error($pdo);
$out['should_run_general'] = wa_otomatis_should_run($pdo, 'general');
$cronTs = strtotime((string) app_setting($pdo, 'wa_auto_last_run_at', ''));
$out['cron_stale'] = wa_auto_cron_is_stale($pdo);
$out['cron_age_sec'] = $cronTs !== false ? (time() - $cronTs) : null;
$out['cron_recently_active'] = wa_auto_cron_recently_active($pdo);
$out['fallback_enabled'] = trim((string) app_setting($pdo, 'wa_auto_web_fallback_enabled', '0')) === '1';
$out['dispatch_strict_mode'] = wa_dispatch_strict_enabled($pdo);
$out['dispatch_table_exists'] = function_exists('table_exists') && table_exists($pdo, 'wa_dispatch_log');
$out['double_send_risk'] = $out['cron_recently_active'] && $out['fallback_enabled'];
$out['alpa_last'] = json_decode((string) app_setting($pdo, 'wa_auto_alpa_last_result', ''), true);
$out['alpa_tiers'] = alpa_tier_list($pdo, true);
$out['cashless_status'] = cashless_wa_laporan_status_hari_ini($pdo);
$out['recent_dispatch'] = wa_dispatch_recent_rows($pdo, 15);
$out['recent_wa_duplicates'] = wa_logs_recent_duplicates($pdo, 24, 15);

$out['recommendations'] = [];
if ($out['cron_stale']) {
    $out['recommendations'][] = 'Cron belum jalan atau stale (>10 menit). Aktifkan crontab hosting curl ke cron/wa_auto.php.';
}
if ($out['double_send_risk']) {
    $out['recommendations'][] = 'RISIKO DOBEL: cron aktif + fallback web masih ON. Nonaktifkan fallback di tab Gateway.';
}
if (!$out['dispatch_strict_mode']) {
    $out['recommendations'][] = 'Dedup ledger nonaktif (wa_dispatch_strict_mode=0). Aktifkan "Cegah duplikat WA otomatis".';
}
if (!$out['dispatch_table_exists']) {
    $out['recommendations'][] = 'Tabel wa_dispatch_log belum ada — dedup tidak efektif.';
}
if ($out['recent_wa_duplicates'] !== []) {
    $out['recommendations'][] = 'Ditemukan ' . count($out['recent_wa_duplicates']) . ' kemungkinan duplikat di wa_logs 24 jam terakhir.';
}
if ($out['recommendations'] === []) {
    $out['recommendations'][] = 'Konfigurasi terlihat OK. Pantau wa_logs jika masih ada keluhan dobel.';
}

$recentCashless = [];
foreach ($out['recent_dispatch'] as $row) {
    if (($row['kind'] ?? '') === 'cashless') {
        $recentCashless[] = $row;
    }
}
$out['recent_cashless_logs'] = array_slice($recentCashless, 0, 10);

$outFile = __DIR__ . '/_diag_wa_otomatis_result.json';
file_put_contents($outFile, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "OK written to {$outFile}\n";
echo 'cron_stale=' . ($out['cron_stale'] ? 'yes' : 'no') . "\n";
echo 'double_send_risk=' . ($out['double_send_risk'] ? 'yes' : 'no') . "\n";
if ($out['recommendations'] !== []) {
    echo "recommendations:\n";
    foreach ($out['recommendations'] as $rec) {
        echo '  - ' . $rec . "\n";
    }
}
