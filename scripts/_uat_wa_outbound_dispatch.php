<?php

declare(strict_types=1);

/**
 * UAT backend global outbound dispatch (tanpa kirim gateway nyata bila token kosong).
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/wa_otomatis.php';
require_once __DIR__ . '/../helpers/wa_outbound_dispatch.php';

ensure_pondok_settings_defaults($pdo);
wa_outbound_ensure_schema($pdo);

$results = [];
$pass = 0;
$fail = 0;

function uat_assert(string $name, bool $ok, string $detail = ''): void
{
    global $results, $pass, $fail;
    $results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
    if ($ok) {
        $pass++;
    } else {
        $fail++;
    }
}

uat_assert('schema_queue', table_exists($pdo, 'wa_outbound_queue'), 'wa_outbound_queue');
uat_assert('schema_opt_out', table_exists($pdo, 'wa_opt_out'), 'wa_opt_out');

$p10 = wa_outbound_priority_for_kind('izin', ['dedup_key' => 'izin:1:pengasuh:submit']);
uat_assert('priority_izin_pengasuh', $p10 === 10, 'got ' . $p10);

$p30 = wa_outbound_priority_for_kind('tagihan', []);
uat_assert('priority_tagihan', $p30 === 30, 'got ' . $p30);

$p50 = wa_outbound_priority_for_kind('poin', ['dedup_key' => 'poin:2025:tier:1:santri:1']);
uat_assert('priority_poin', $p50 === 50, 'got ' . $p50);

uat_assert('classify_invalid', wa_outbound_classify_error('Nomor / ID grup WA tidak valid.', 400) === 'invalid_target', '');
uat_assert('classify_retry', wa_outbound_classify_error('curl timeout', 0) === 'retryable', '');
uat_assert('backoff_1', wa_outbound_backoff_seconds(1) === 60, '');
uat_assert('backoff_2', wa_outbound_backoff_seconds(2) === 120, '');
uat_assert('backoff_3', wa_outbound_backoff_seconds(3) === 240, '');

$gwErr = wa_otomatis_gateway_error($pdo);
$testPhone = '6281234567890';
$dedup = 'uat:outbound:' . date('YmdHis') . ':' . random_int(1000, 9999);

$enq = wa_outbound_enqueue($pdo, $testPhone, 'UAT enqueue test', [
    'kind' => 'general',
    'dedup_key' => $dedup,
    'defer_dispatch' => true,
]);
uat_assert('enqueue_ok', ($enq['ok'] ?? false) && !empty($enq['queue_id']), json_encode($enq, JSON_UNESCAPED_UNICODE));

$dup = wa_outbound_enqueue($pdo, $testPhone, 'UAT duplicate', [
    'kind' => 'general',
    'dedup_key' => $dedup,
]);
uat_assert('enqueue_duplicate_queue', !empty($dup['queued']) || !empty($dup['skipped']), json_encode($dup, JSON_UNESCAPED_UNICODE));

try {
    $pdo->prepare('INSERT INTO wa_opt_out (target_phone, category, status, note) VALUES (:p, "general", "opt_out", "uat")
        ON DUPLICATE KEY UPDATE status = "opt_out"')->execute(['p' => $testPhone]);
    $opt = wa_outbound_is_opted_out($pdo, $testPhone, 'general');
    uat_assert('opt_out_block', $opt === true, '');
    $pdo->prepare('DELETE FROM wa_opt_out WHERE target_phone = :p AND note = "uat"')->execute(['p' => $testPhone]);
} catch (Throwable $e) {
    uat_assert('opt_out_block', false, $e->getMessage());
}

$drain = wa_outbound_drain($pdo, 1);
uat_assert('drain_runs', is_array($drain) && array_key_exists('sent', $drain), json_encode($drain, JSON_UNESCAPED_UNICODE));

$immediate = wa_otomatis_send($pdo, $testPhone, 'UAT immediate', [
    'dispatch_immediate' => true,
    'skip_dedup' => true,
]);
uat_assert(
    'dispatch_immediate_path',
    $gwErr !== null ? !($immediate['success'] ?? false) : is_bool($immediate['success'] ?? null),
    $gwErr !== null ? 'gateway_not_configured_expected_fail' : (string) ($immediate['error'] ?? 'ok')
);

try {
    $pdo->prepare('DELETE FROM wa_outbound_queue WHERE dedup_key = :d')->execute(['d' => wa_dispatch_normalize_key($dedup)]);
} catch (Throwable $e) {
    // ignore
}

$snap = wa_outbound_queue_snapshot($pdo);
uat_assert('snapshot', ($snap['enabled'] ?? false) && ($snap['table_exists'] ?? false), '');

$outFile = __DIR__ . '/_uat_wa_outbound_dispatch_result.json';
file_put_contents($outFile, json_encode([
    'pass' => $pass,
    'fail' => $fail,
    'results' => $results,
    'snapshot' => $snap,
    'gateway_error' => $gwErr,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "UAT outbound dispatch: pass={$pass} fail={$fail}\n";
echo "Written {$outFile}\n";
foreach ($results as $r) {
    echo ($r['ok'] ? '[OK] ' : '[FAIL] ') . $r['name'];
    if ($r['detail'] !== '') {
        echo ' — ' . $r['detail'];
    }
    echo "\n";
}

exit($fail > 0 ? 1 : 0);
