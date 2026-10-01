<?php
/**
 * UAT: waktu presensi dari antrian offline (upload terlambat) memakai scan_client_at.
 * Jalankan: php scripts/_uat_offline_presensi_clock.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../helpers/presensi_scan_client.php';
require_once __DIR__ . '/../helpers/offline_sync_http.php';

$_SERVER['HTTP_X_PWA_OFFLINE_SYNC'] = '1';
$twoHoursAgo = gmdate('c', time() - 7200);
$clock = presensi_scan_resolve_clock(['scan_client_at' => $twoHoursAgo]);

if (empty($clock['from_client'])) {
    echo "FAIL delayed sync should honor scan_client_at (from_client=false)\n";
    exit(1);
}
if (!empty($clock['from_client_skew'])) {
    echo "FAIL delayed sync should not set from_client_skew\n";
    exit(1);
}
$expectedDate = date('Y-m-d', strtotime($twoHoursAgo));
$expectedJam = date('H:i:s', strtotime($twoHoursAgo));
if ($clock['tanggal'] !== $expectedDate || $clock['jam'] !== $expectedJam) {
    echo "FAIL clock mismatch got {$clock['tanggal']} {$clock['jam']} want {$expectedDate} {$expectedJam}\n";
    exit(1);
}
echo "OK delayed offline sync honors scan_client_at\n";

unset($_SERVER['HTTP_X_PWA_OFFLINE_SYNC']);
$liveSkew = presensi_scan_resolve_clock(['scan_client_at' => $twoHoursAgo]);
if (empty($liveSkew['from_client_skew'])) {
    echo "FAIL live POST without sync header should reject large skew\n";
    exit(1);
}
echo "OK live scan without sync header still applies skew limit\n";

$uuidClock = presensi_scan_resolve_clock([
    'scan_client_at' => $twoHoursAgo,
    'client_uuid' => '00000000-0000-4000-8000-000000000001',
]);
if (empty($uuidClock['from_client'])) {
    echo "FAIL client_uuid should enable delayed trust\n";
    exit(1);
}
echo "OK client_uuid enables delayed client clock\n";

echo "ALL OK\n";
exit(0);
