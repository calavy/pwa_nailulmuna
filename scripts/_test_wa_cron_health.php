<?php

declare(strict_types=1);

/**
 * Smoke test kesehatan cron WA otomatis (app-wide: ALPA, tagihan, cashless, poin, dll.).
 * Exit 0 = OK, exit 1 = ada masalah konfigurasi/cron.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/wa_otomatis.php';
require_once __DIR__ . '/../helpers/alpa_tier.php';

$checks = [];
$issues = [];

$gwErr = wa_otomatis_gateway_error($pdo);
$checks['gateway_ready'] = $gwErr === null;
if ($gwErr !== null) {
    $issues[] = 'Gateway: ' . $gwErr;
}

$masterOn = wa_otomatis_should_run($pdo, 'general');
$checks['master_wa_active'] = $masterOn;
if (!$masterOn) {
    $issues[] = 'Master WA otomatis nonaktif';
}

$cronLast = trim((string) app_setting($pdo, 'wa_auto_last_run_at', ''));
$checks['cron_ever_ran'] = $cronLast !== '';
if ($cronLast === '') {
    $issues[] = 'Cron belum pernah jalan (wa_auto_last_run_at kosong)';
}

$cronRecent = wa_auto_cron_recently_active($pdo);
$checks['cron_not_stale'] = $cronRecent;
if (!$cronRecent && $cronLast !== '') {
    $issues[] = 'Cron stale (>10 menit sejak tick terakhir)';
}

$scheduledLast = trim((string) app_setting($pdo, 'wa_auto_scheduled_last_at', ''));
$checks['scheduled_job_ran'] = $scheduledLast !== '';
if ($scheduledLast === '') {
    $issues[] = 'Job terjadwal belum pernah jalan (wa_auto_scheduled_last_at kosong)';
}

$scheduledRaw = trim((string) app_setting($pdo, 'wa_auto_scheduled_last_result', ''));
$scheduledResult = $scheduledRaw !== '' ? json_decode($scheduledRaw, true) : null;
if (!is_array($scheduledResult)) {
    $scheduledResult = null;
}
$checks['scheduled_last_result'] = $scheduledResult;

$jobSummary = [];
if (is_array($scheduledResult['jobs'] ?? null)) {
    foreach ($scheduledResult['jobs'] as $jobKey => $jobRow) {
        if (!is_array($jobRow)) {
            continue;
        }
        $jobSummary[$jobKey] = [
            'ran' => !empty($jobRow['ran']),
            'note' => trim((string) ($jobRow['note'] ?? '')),
        ];
    }
}
$checks['scheduled_jobs'] = $jobSummary;

require_once __DIR__ . '/../helpers/datetime_display.php';
$jamKirim = trim((string) app_setting($pdo, 'jam_kirim_wa_auto', ''));
$jamLewat = $jamKirim === '' || app_jam_sudah_lewat($jamKirim);
$checks['alpa_send_time'] = [
    'jam' => $jamKirim !== '' ? $jamKirim : '(kosong = cek terus)',
    'sudah_lewat_hari_ini' => $jamLewat,
];

$alpaLastRaw = trim((string) app_setting($pdo, 'wa_auto_alpa_last_result', ''));
$alpaLast = $alpaLastRaw !== '' ? json_decode($alpaLastRaw, true) : null;
if (!is_array($alpaLast)) {
    $alpaLast = null;
}
$checks['alpa_last_result'] = $alpaLast;

$tagihanStats = json_decode((string) app_setting($pdo, 'wa_tagihan_last_run_stats', ''), true);
$checks['tagihan_last_stats'] = is_array($tagihanStats) ? $tagihanStats : null;

$allCriticalOk = $checks['gateway_ready']
    && $checks['master_wa_active']
    && $checks['cron_ever_ran']
    && $checks['cron_not_stale']
    && $checks['scheduled_job_ran'];

echo ($allCriticalOk ? 'OK' : 'ISSUES') . "\n";
echo 'gateway=' . ($checks['gateway_ready'] ? 'ready' : 'error') . "\n";
echo 'master_wa=' . ($checks['master_wa_active'] ? 'on' : 'off') . "\n";
echo 'cron_last=' . ($cronLast !== '' ? $cronLast : '-') . "\n";
echo 'cron_recent=' . ($cronRecent ? 'yes' : 'no') . "\n";
echo 'scheduled_last=' . ($scheduledLast !== '' ? $scheduledLast : '-') . "\n";
echo 'jam_kirim_alpa=' . (is_array($checks['alpa_send_time']) ? (string) ($checks['alpa_send_time']['jam'] ?? '') : '-') . "\n";

if ($jobSummary !== []) {
    echo "scheduled_jobs:\n";
    foreach ($jobSummary as $jobKey => $jobMeta) {
        $ran = !empty($jobMeta['ran']) ? 'yes' : 'no';
        $note = (string) ($jobMeta['note'] ?? '');
        echo '  ' . $jobKey . ': ran=' . $ran;
        if ($note !== '') {
            echo ' note=' . $note;
        }
        echo "\n";
    }
}

if ($alpaLast !== null) {
    echo 'alpa_last_sent=' . (int) ($alpaLast['sent'] ?? 0);
    if (isset($alpaLast['sent_putra']) || isset($alpaLast['sent_putri'])) {
        echo ' putra=' . (int) ($alpaLast['sent_putra'] ?? 0) . ' putri=' . (int) ($alpaLast['sent_putri'] ?? 0);
    }
    echo ' note=' . (string) ($alpaLast['note'] ?? '') . ' at=' . (string) ($alpaLast['at'] ?? '') . "\n";
} else {
    echo "alpa_last_sent=-\n";
}

if (is_array($tagihanStats) && ((int) ($tagihanStats['sent'] ?? 0) > 0 || (int) ($tagihanStats['failed'] ?? 0) > 0)) {
    echo 'tagihan_last=' . (int) ($tagihanStats['sent'] ?? 0) . ' sent, '
        . (int) ($tagihanStats['failed'] ?? 0) . " failed\n";
}

if ($issues !== []) {
    echo "issues:\n";
    foreach ($issues as $issue) {
        echo '  - ' . $issue . "\n";
    }
}

echo json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit($allCriticalOk ? 0 : 1);
