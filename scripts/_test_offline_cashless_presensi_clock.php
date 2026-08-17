<?php
/**
 * Smoke test waktu scan offline cashless + clock presensi.
 * Jalankan: C:\xampp\php\php.exe scripts/_test_offline_cashless_presensi_clock.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/presensi_scan_client.php';
require_once __DIR__ . '/../helpers/cashless_koperasi.php';

$pastIso = gmdate('c', time() - 3600);
$clock = presensi_scan_resolve_clock(['scan_client_at' => $pastIso]);
if (empty($clock['from_client'])) {
    echo "FAIL expected from_client for scan_client_at\n";
    exit(1);
}
$expectedDate = date('Y-m-d', strtotime($pastIso));
$expectedJam = date('H:i:s', strtotime($pastIso));
if ($clock['tanggal'] !== $expectedDate || $clock['jam'] !== $expectedJam) {
    echo "FAIL clock mismatch got {$clock['tanggal']} {$clock['jam']} expect {$expectedDate} {$expectedJam}\n";
    exit(1);
}
echo "OK presensi_scan_resolve_clock honors client time\n";

$tooOld = gmdate('c', time() - 86400 * 10);
$clockOld = presensi_scan_resolve_clock(['scan_client_at' => $tooOld]);
if (!empty($clockOld['from_client'])) {
    echo "FAIL old client time should fall back to server\n";
    exit(1);
}
echo "OK old scan_client_at falls back to server\n";

cashless_koperasi_ensure_schema($pdo);
if (!table_exists($pdo, 'cashless_transactions')) {
    echo "SKIP cashless_transactions missing\n";
    exit(0);
}

$santriId = (int) $pdo->query('SELECT id FROM santri LIMIT 1')->fetchColumn();
if ($santriId < 1) {
    echo "SKIP no santri for debit insert test\n";
    exit(0);
}

$token = 'test-offline-' . bin2hex(random_bytes(8));
$scanAt = date('Y-m-d H:i:s', time() - 7200);
$txId = cashless_koperasi_insert_debit(
    $pdo,
    $santriId,
    1000,
    'Smoke offline clock',
    0,
    1,
    $token,
    $scanAt
);
if ($txId < 1) {
    echo "FAIL insert_debit returned empty id\n";
    exit(1);
}

$row = $pdo->prepare('SELECT tanggal, client_token FROM cashless_transactions WHERE id = :id LIMIT 1');
$row->execute(['id' => $txId]);
$got = $row->fetch(PDO::FETCH_ASSOC);
if (!is_array($got)) {
    echo "FAIL debit row missing\n";
    exit(1);
}
$gotTs = strtotime((string) ($got['tanggal'] ?? ''));
$wantTs = strtotime($scanAt);
if ($gotTs === false || $wantTs === false || abs($gotTs - $wantTs) > 1) {
    echo "FAIL tanggal mismatch got {$got['tanggal']} want {$scanAt}\n";
    exit(1);
}
echo "OK cashless_koperasi_insert_debit stores scan time\n";

$again = cashless_koperasi_find_debit_by_client_token($pdo, $token);
if ($again === null || (int) ($again['id'] ?? 0) !== $txId) {
    echo "FAIL idempotent lookup by client_token\n";
    exit(1);
}
echo "OK client_token idempotent lookup\n";

// Bersihkan baris uji (opsional; abaikan gagal).
try {
    $pdo->prepare('DELETE FROM cashless_transactions WHERE id = :id AND client_token = :t')->execute([
        'id' => $txId,
        't' => $token,
    ]);
    if (function_exists('cashless_sync_account_balance')) {
        cashless_sync_account_balance($pdo, $santriId);
    }
} catch (Throwable $e) {
    // ignore cleanup
}

echo "ALL OK\n";
exit(0);
