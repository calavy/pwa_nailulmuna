<?php
/**
 * UAT batas pengajuan perizinan pembimbing (hari kalender, bukan 72 jam).
 * Jalankan: php scripts/_uat_pb_perizinan_batas_hari.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../helpers/pembimbing_perubahan_jadwal.php';

$jam = '08:00:00';
$plus2 = date('Y-m-d', strtotime('+2 days'));
$plus3 = date('Y-m-d', strtotime('+3 days'));

$fail2 = pb_jadwal_cek_batas_pengajuan($plus2, $jam);
if (!empty($fail2['ok'])) {
    echo "FAIL H+2 should not allow pengajuan (pindah)\n";
    exit(1);
}
echo "OK H+2 blocked\n";

$ok3 = pb_jadwal_cek_batas_pengajuan($plus3, $jam);
if (empty($ok3['ok'])) {
    echo "FAIL H+3 should allow pengajuan got: " . ($ok3['pesan'] ?? '') . "\n";
    exit(1);
}
echo "OK H+3 allowed\n";

$today = date('Y-m-d');
$failToday = pb_jadwal_cek_batas_pengajuan($today, $jam);
if (!empty($failToday['ok'])) {
    echo "FAIL today should be blocked\n";
    exit(1);
}
echo "OK today blocked\n";

echo "ALL OK\n";
exit(0);
