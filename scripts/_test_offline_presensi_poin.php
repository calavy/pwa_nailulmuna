<?php
/**
 * Smoke test modul offline presensi & poin.
 * Jalankan: C:\xampp\php\php.exe scripts/_test_offline_presensi_poin.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/offline_sync_dedup.php';
require_once __DIR__ . '/../helpers/poin_offline.php';

offline_sync_ensure_log_table($pdo);
if (!table_exists($pdo, 'offline_sync_log')) {
    echo "FAIL offline_sync_log table missing\n";
    exit(1);
}
echo "OK offline_sync_log table exists\n";

$cmp = offline_sync_compare_time_hms('07:03:00', '07:05:00');
if ($cmp >= 0) {
    echo "FAIL time compare earlier should win\n";
    exit(1);
}
echo "OK offline_sync_compare_time_hms\n";

$decision = offline_sync_presensi_existing_decision(
    ['id' => 1, 'jam_presensi' => '07:10:00'],
    '07:05:00',
    true
);
if (($decision['action'] ?? '') !== 'replace') {
    echo "FAIL expected replace for earlier client scan\n";
    exit(1);
}
$decision2 = offline_sync_presensi_existing_decision(
    ['id' => 1, 'jam_presensi' => '07:03:00'],
    '07:05:00',
    true
);
if (($decision2['action'] ?? '') !== 'duplicate') {
    echo "FAIL expected duplicate for later client scan\n";
    exit(1);
}
if (stripos((string) ($decision2['message'] ?? ''), 'perangkat') === false) {
    echo "FAIL duplicate message should mention perangkat lain\n";
    exit(1);
}
echo "OK presensi conflict decision\n";

require_once __DIR__ . '/../helpers/pwa_offline.php';
$shell = pwa_module_shell_precache_relative_paths();
if (!in_array('/presensi/scan.php', $shell, true) || !in_array('/poin/input.php', $shell, true)) {
    echo "FAIL module shell precache missing scan/poin HTML\n";
    exit(1);
}
echo "OK SW module shell precache paths\n";

$pack = poin_offline_reference_pack($pdo);
if (empty($pack['ok']) || empty($pack['version'])) {
    echo "FAIL reference pack\n";
    exit(1);
}
echo "OK poin reference pack version=" . $pack['version'] . "\n";

$uuid = '11111111-1111-4111-8111-111111111111';
offline_sync_log_write($pdo, $uuid, 'poin_input', 1, 'accepted', 99, '2026-08-16 07:00:00');
$idem = offline_sync_idempotent_response($pdo, $uuid, 'poin_input');
if ($idem === null || ($idem['type'] ?? '') !== 'success') {
    echo "FAIL idempotent response\n";
    exit(1);
}
echo "OK idempotent log\n";

$pdo->prepare('DELETE FROM offline_sync_log WHERE client_uuid = :u')->execute(['u' => $uuid]);

echo "All offline smoke tests passed.\n";
