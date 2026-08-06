<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/wa_otomatis.php';
require_once __DIR__ . '/../helpers/cashless_wa.php';
require_once __DIR__ . '/../helpers/alpa_tier.php';

$out = [];
$keys = [
    'wa_otomatis_master_enabled', 'wa_gateway_token', 'wa_gateway_url',
    'wa_auto_last_run_at', 'wa_auto_last_gateway_ok', 'wa_auto_last_gateway_error',
    'wa_auto_scheduled_last_at', 'wa_auto_alpa_last_result',
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
    $out['settings'][$k] = $v;
}

$out['gateway_error'] = wa_otomatis_gateway_error($pdo);
$out['should_run_general'] = wa_otomatis_should_run($pdo, 'general');
$cronTs = strtotime((string) app_setting($pdo, 'wa_auto_last_run_at', ''));
$out['cron_stale'] = ($cronTs === false || (time() - $cronTs) > 600);
$out['cron_age_sec'] = $cronTs !== false ? (time() - $cronTs) : null;
$out['alpa_last'] = json_decode((string) app_setting($pdo, 'wa_auto_alpa_last_result', ''), true);
$out['alpa_tiers'] = alpa_tier_list($pdo, true);
$out['cashless_status'] = cashless_wa_laporan_status_hari_ini($pdo);
$out['recent_dispatch'] = wa_dispatch_recent_rows($pdo, 15);

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
