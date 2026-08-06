<?php

declare(strict_types=1);

/**
 * Verifikasi kesiapan WA cashless real-time (tanpa kirim pesen).
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/wa_otomatis.php';
require_once __DIR__ . '/../helpers/cashless_wa.php';
require_once __DIR__ . '/../helpers/santri_wa.php';

$checks = [];
$checks['master_wa'] = wa_otomatis_should_run($pdo, 'general');
$checks['gateway_ok'] = wa_otomatis_gateway_error($pdo) === null;
$checks['transaksi_wa_enabled'] = cashless_wa_transaksi_sukses_enabled($pdo);
$checks['saldo_rendah_enabled'] = cashless_wa_saldo_rendah_enabled($pdo);

$sampleId = 0;
$samplePhone = '';
$st = $pdo->query('SELECT id FROM santri ORDER BY id ASC LIMIT 200');
foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $sid) {
    $phone = wa_otomatis_santri_wali_phone($pdo, (int) $sid);
    if ($phone !== '' && strlen($phone) >= 10) {
        $sampleId = (int) $sid;
        $samplePhone = $phone;
        break;
    }
}
$checks['sample_santri_id'] = $sampleId;
$checks['sample_wali_phone'] = $samplePhone !== '' ? $samplePhone : null;
$checks['ready_for_realtime'] = $checks['master_wa'] && $checks['gateway_ok']
    && $checks['transaksi_wa_enabled'] && $samplePhone !== '';

$trxCount = 0;
if (table_exists($pdo, 'cashless_transactions')) {
    $trxCount = (int) $pdo->query("SELECT COUNT(*) FROM cashless_transactions WHERE UPPER(jenis) = 'DEBIT'")->fetchColumn();
}
$checks['total_debit_transactions'] = $trxCount;

$cashlessLogs = 0;
if (table_exists($pdo, 'wa_dispatch_log')) {
    $cashlessLogs = (int) $pdo->query("SELECT COUNT(*) FROM wa_dispatch_log WHERE kind = 'cashless'")->fetchColumn();
}
$checks['cashless_dispatch_log_count'] = $cashlessLogs;

file_put_contents(__DIR__ . '/_diag_cashless_readiness.json', json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo ($checks['ready_for_realtime'] ? 'READY' : 'NOT_READY') . "\n";
