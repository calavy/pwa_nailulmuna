<?php

declare(strict_types=1);

/**
 * Checklist UAT Multi Scan (server-side + petunjuk manual HP).
 *
 *   php scripts/_uat_multi_scan_readiness.php
 */

echo "=== UAT Multi Scan readiness ===\n\n";

passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/_diag_multi_scan_deploy.php'), $code);

$codeMw = 0;
passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/_diag_multi_scan_munawib_setoran.php'), $codeMw);
if ($codeMw !== 0 && $code === 0) {
    $code = $codeMw;
}

echo "\n--- Manual UAT (HP yang dulu bermasalah) ---\n";
echo "1. Buka login.php?scan=1 (online, HTTPS).\n";
echo "2. Ketuk [Mulai scan kamera] — izin kamera → preview terisi (bukan hitam).\n";
echo "3. Scan kartu santri — feedback \"Kartu terbaca…\" + absensi/portal sesuai jadwal.\n";
echo "4. Scan kartu berbeda tanpa refresh — kartu kedua terbaca.\n";
echo "5. Bandingkan /presensi/scan.php di HP sama — keduanya harus baca QR.\n";
echo "6. Jika QR lambat ~8 detik — muncul \"Mode scan alternatif…\" lalu coba lagi.\n";
echo "7. Opsional: Flash / Ganti kamera / Super Fokus berfungsi.\n";
echo "8. Munawib penerima setoran (tanpa jadwal penugasan): scan sekali → masuk portal setoran hafalan.\n";
echo "\nCatat gejala jika gagal: hitam | tidak bip | bip tanpa absensi.\n";

exit($code !== 0 ? 1 : 0);
