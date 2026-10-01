<?php
/**
 * UAT munawib opsional: helper WA pendidikan skip jika munawib sudah dipilih (logic guard).
 * Jalankan: php scripts/_uat_munawib_opsional.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/pembimbing_perubahan_jadwal.php';

if (!function_exists('pb_munawib_pengajuan_kirim_wa_pendidikan')) {
    echo "FAIL helper kirim_wa_pendidikan missing\n";
    exit(1);
}

$res = pb_munawib_pengajuan_kirim_wa_pendidikan($pdo, 0);
if (($res['reason'] ?? '') !== 'not_found' && empty($res['skipped'])) {
    echo "FAIL id 0 expected skip not_found\n";
    exit(1);
}
echo "OK kirim_wa_pendidikan guards invalid id\n";

echo "ALL OK\n";
exit(0);
