<?php

declare(strict_types=1);

/**
 * UAT pembatalan antrean WA (tanpa kirim ke gateway).
 *
 * Usage: php scripts/_uat_wa_outbound_cancel.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/wa_otomatis.php';
require_once __DIR__ . '/../helpers/wa_outbound_dispatch.php';

$fails = 0;
function uat_check(string $name, bool $ok, string $info = ''): void
{
    global $fails;
    if (!$ok) {
        $fails++;
    }
    echo ($ok ? '[OK]   ' : '[FAIL] ') . $name . ($info !== '' ? ' — ' . $info : '') . "\n";
}

wa_outbound_ensure_schema($pdo);
$statusType = (string) ($pdo->query("SHOW COLUMNS FROM wa_outbound_queue LIKE 'status'")->fetch(PDO::FETCH_ASSOC)['Type'] ?? '');
uat_check('enum_cancelled', str_contains($statusType, "'cancelled'"), $statusType);
uat_check('col_cancelled_at', column_exists($pdo, 'wa_outbound_queue', 'cancelled_at'));
uat_check('col_cancelled_by', column_exists($pdo, 'wa_outbound_queue', 'cancelled_by'));

$suffix = date('YmdHis') . '_' . mt_rand(1000, 9999);
$keyA = 'uat_cancel:' . $suffix . ':a';
$keyB = 'uat_cancel:' . $suffix . ':b';
$target = '6281200000001';

$enqA = wa_outbound_enqueue($pdo, $target, 'UAT cancel A', ['kind' => 'general', 'dedup_key' => $keyA]);
$enqB = wa_outbound_enqueue($pdo, $target, 'UAT cancel B', ['kind' => 'general', 'dedup_key' => $keyB]);
$idA = (int) ($enqA['queue_id'] ?? 0);
$idB = (int) ($enqB['queue_id'] ?? 0);
uat_check('enqueue_a', $idA > 0, json_encode($enqA));
uat_check('enqueue_b', $idB > 0, json_encode($enqB));

$pdo->prepare('UPDATE wa_outbound_queue SET status = "sending" WHERE id = :id')->execute(['id' => $idB]);
$n = wa_outbound_cancel_rows($pdo, [$idA, $idB, 0, 'x'], 1);
uat_check('cancel_only_pending', $n === 1, 'cancelled=' . $n);

$rowA = $pdo->query('SELECT status, cancelled_at, cancelled_by FROM wa_outbound_queue WHERE id = ' . $idA)->fetch(PDO::FETCH_ASSOC);
uat_check('row_a_cancelled', ($rowA['status'] ?? '') === 'cancelled' && !empty($rowA['cancelled_at']) && (int) $rowA['cancelled_by'] === 1, json_encode($rowA));
$rowB = $pdo->query('SELECT status FROM wa_outbound_queue WHERE id = ' . $idB)->fetch(PDO::FETCH_ASSOC);
uat_check('row_b_sending_untouched', ($rowB['status'] ?? '') === 'sending');

$re = wa_outbound_enqueue($pdo, $target, 'UAT cancel A lagi', ['kind' => 'general', 'dedup_key' => $keyA]);
uat_check('reenqueue_blocked', !empty($re['skipped']) && ($re['skipped_reason'] ?? '') === 'cancelled', json_encode($re));

$viaSend = wa_otomatis_send($pdo, $target, 'UAT cancel A via send', ['kind' => 'general', 'dedup_key' => $keyA]);
uat_check('send_queue_path_skipped', !empty($viaSend['skipped']) && ($viaSend['skipped_reason'] ?? '') === 'cancelled' && !empty($viaSend['success']), json_encode($viaSend));

$viaDirect = wa_otomatis_send($pdo, $target, 'UAT cancel A direct', ['kind' => 'general', 'dedup_key' => $keyA, 'dispatch_immediate' => true]);
uat_check('send_direct_skipped', ($viaDirect['skipped_reason'] ?? '') === 'cancelled' && (int) ($viaDirect['attempts'] ?? -1) === 0, json_encode($viaDirect));

$activeA = (int) $pdo->query('SELECT COUNT(*) FROM wa_outbound_queue WHERE dedup_key = ' . $pdo->quote(wa_dispatch_normalize_key($keyA)) . ' AND status IN ("pending","retry_wait","sending")')->fetchColumn();
uat_check('no_active_row_for_a', $activeA === 0, 'active=' . $activeA);

$snap = wa_outbound_queue_snapshot($pdo);
uat_check('snapshot_counts_cancelled', (int) ($snap['counts']['cancelled'] ?? 0) >= 1);

$pdo->prepare('DELETE FROM wa_outbound_queue WHERE dedup_key IN (:a, :b)')->execute([
    'a' => wa_dispatch_normalize_key($keyA),
    'b' => wa_dispatch_normalize_key($keyB),
]);

echo $fails === 0 ? "\nSEMUA OK\n" : "\n{$fails} GAGAL\n";
exit($fails === 0 ? 0 : 1);
