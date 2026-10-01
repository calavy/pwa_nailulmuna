<?php
/**
 * UAT aturan rentang munawib: ≤3 hari auto, >3 hari pengasuh.
 * Jalankan: php scripts/_uat_munawib_rentang_pengasuh.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../helpers/pembimbing_perubahan_jadwal.php';

$today = date('Y-m-d');
$plus2 = date('Y-m-d', strtotime('+2 days'));

$c3 = pb_munawib_rentang_hari_count($today, $plus2);
if ($c3 !== 3) {
    echo "FAIL dayCount 3-day range expected 3 got {$c3}\n";
    exit(1);
}
echo "OK dayCount 3 hari\n";

if (pb_munawib_pengajuan_perlu_pengasuh($today, $plus2)) {
    echo "FAIL 3 hari should not need pengasuh\n";
    exit(1);
}
echo "OK 3 hari auto\n";

$plus3 = date('Y-m-d', strtotime('+3 days'));
$c4 = pb_munawib_rentang_hari_count($today, $plus3);
if ($c4 !== 4) {
    echo "FAIL dayCount 4-day range expected 4 got {$c4}\n";
    exit(1);
}
echo "OK dayCount 4 hari\n";

if (!pb_munawib_pengajuan_perlu_pengasuh($today, $plus3)) {
    echo "FAIL 4 hari should need pengasuh\n";
    exit(1);
}
echo "OK 4 hari pengasuh\n";

$mulai = '2026-10-01';
$maxSel = pb_munawib_tanggal_selesai_maks($mulai);
if ($maxSel !== '2026-10-14') {
    echo "FAIL selesai_maks expected 2026-10-14 got {$maxSel}\n";
    exit(1);
}
echo "OK selesai_maks 14 hari inklusif\n";

$ok14 = pb_munawib_cek_rentang_kalender($mulai, '2026-10-14');
if (empty($ok14['ok'])) {
    echo "FAIL 14-day range should be ok\n";
    exit(1);
}
$fail15 = pb_munawib_cek_rentang_kalender($mulai, '2026-10-15');
if (!empty($fail15['ok'])) {
    echo "FAIL 15-day range should fail\n";
    exit(1);
}
echo "OK cek_rentang_kalender 14 vs 15\n";

echo "ALL OK\n";
exit(0);
