<?php

declare(strict_types=1);

/**
 * Diagnostik keselarasan tagihan santri baru vs lama (bulan masuk & awal tahun).
 *
 * Usage: php scripts/_diag_tagihan_baru_lama.php [santri_id]
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_defs.php';
require_once __DIR__ . '/../helpers/keuangan_rekap.php';
require_once __DIR__ . '/../helpers/keuangan_rekap_tagihan_bulan.php';
require_once __DIR__ . '/../helpers/tagihan_santri_masuk.php';
require_once __DIR__ . '/../helpers/tagihan_bulanan.php';
require_once __DIR__ . '/../helpers/pondok_ta.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database tidak tersedia.\n");
    exit(1);
}

$filterSantriId = isset($argv[1]) ? (int) $argv[1] : 0;
$ta = pondok_tahun_ajaran_aktif($pdo);
$tm = (int) ($ta['mulai'] ?? 0);
$ts = (int) ($ta['selesai'] ?? 0);

echo "=== Diagnostik Tagihan Baru vs Lama ===\n";
echo 'TA aktif: ' . pondok_tahun_ajaran_label($pdo, $ta) . " ({$tm}/{$ts})\n\n";

$status = keuangan_tagihan_masuk_pengaturan_status($pdo);
echo "Pengaturan:\n";
echo '  Mulai bulan masuk: ' . ($status['mulai_masuk'] ? 'ON' : 'OFF') . "\n";
echo '  Bedakan awal tahun: ' . ($status['bedakan_awal_tahun'] ? 'ON' : 'OFF') . "\n";
echo '  Santri aktif tanpa tanggal masuk: ' . (int) $status['santri_tanpa_tanggal_masuk'] . "\n";
echo '  Siap (kedua ON + semua punya tanggal masuk): ' . ($status['siap'] ? 'YA' : 'TIDAK') . "\n\n";

if (!function_exists('santri_sql_aktif_only')) {
    require_once __DIR__ . '/../helpers/santri_operasional.php';
}
$aktifSql = santri_sql_aktif_only('s');
$sql = "SELECT s.id, s.nis, s.nama_santri, s.tanggal_masuk FROM santri s WHERE {$aktifSql}";
if ($filterSantriId > 0) {
    $sql .= ' AND s.id = ' . $filterSantriId;
}
$sql .= ' ORDER BY s.nama_santri ASC LIMIT 50';
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

if ($rows === []) {
    echo "Tidak ada santri aktif" . ($filterSantriId > 0 ? " dengan ID #{$filterSantriId}" : '') . ".\n";
    exit(0);
}

$biayaDefs = keuangan_biaya_definitions();
$baruCount = 0;
$lamaCount = 0;
$masukBulanDistribusi = [];

foreach ($rows as $s) {
    $sid = (int) ($s['id'] ?? 0);
    $tgl = trim((string) ($s['tanggal_masuk'] ?? ''));
    $baruDiTa = tagihan_santri_baru_di_ta($pdo, $sid, $tm, $tgl !== '' ? $tgl : null);
    $jenis = tagihan_santri_jenis_ta($pdo, $sid, $tm, $tgl !== '' ? $tgl : null);
    $bulanMulai = tagihan_santri_bulan_mulai($pdo, $sid, $tm, $ts, $tgl !== '' ? $tgl : null);

    if ($baruDiTa) {
        ++$baruCount;
    } else {
        ++$lamaCount;
    }
    $masukBulanDistribusi[$bulanMulai] = ($masukBulanDistribusi[$bulanMulai] ?? 0) + 1;

    echo "--- {$s['nis']} — {$s['nama_santri']} (ID #{$sid}) ---\n";
    echo "  Tanggal masuk: " . ($tgl !== '' ? $tgl : '(kosong)') . "\n";
    echo "  Baru di TA ini: " . ($baruDiTa ? 'ya' : 'tidak') . "\n";
    echo "  Jenis awal tahun: {$jenis}\n";
    echo "  Bulan mulai tagihan: {$bulanMulai}\n";

    $expBulan1 = 0;
    $expBulanMulai = 0;
    if ($bulanMulai >= 1 && $bulanMulai <= 12) {
        $bd1 = keuangan_tagihan_breakdown_for_santri($pdo, $sid, 'BULANAN', 1, $tm, $ts, $biayaDefs);
        $bdM = keuangan_tagihan_breakdown_for_santri($pdo, $sid, 'BULANAN', $bulanMulai, $tm, $ts, $biayaDefs);
        foreach ($bd1 as $info) {
            if (is_array($info)) {
                $expBulan1 += (int) ($info['expected'] ?? 0);
            }
        }
        foreach ($bdM as $info) {
            if (is_array($info)) {
                $expBulanMulai += (int) ($info['expected'] ?? 0);
            }
        }
    }
    echo "  Expected bulan 1 (total pos): Rp " . number_format($expBulan1, 0, ',', '.') . "\n";
    echo "  Expected bulan {$bulanMulai} (total pos): Rp " . number_format($expBulanMulai, 0, ',', '.') . "\n";

    if ($baruDiTa && $bulanMulai > 1 && $expBulan1 > 0) {
        echo "  [!] PERINGATAN: santri baru tapi bulan 1 expected > 0\n";
    }
    if ($baruDiTa && $bulanMulai > 1 && $expBulan1 === 0 && $expBulanMulai > 0) {
        echo "  [OK] Bulan sebelum masuk = 0, bulan mulai ada tagihan\n";
    }
    echo "\n";
}

echo "Ringkasan (max 50 santri):\n";
echo "  Santri baru di TA: {$baruCount}\n";
echo "  Santri lama / bukan baru TA: {$lamaCount}\n";
echo "  Distribusi bulan mulai: ";
ksort($masukBulanDistribusi);
$parts = [];
foreach ($masukBulanDistribusi as $b => $c) {
    $parts[] = "B{$b}={$c}";
}
echo implode(', ', $parts) . "\n\n";

if ($tm > 0 && table_exists($pdo, 'santri')) {
    $slots = pondok_bulan_slots_tahun_ajaran($pdo, $tm, $ts);
    $bulanList = [];
    foreach ($slots as $slot) {
        $b = (int) ($slot['bulan_tagihan'] ?? 0);
        if ($b >= 1 && $b <= 12) {
            $bulanList[$b] = true;
        }
    }
    $bulanList = array_map('intval', array_keys($bulanList));
    sort($bulanList);
    $allSantri = $pdo->query("SELECT id, tingkatan, kategori_kelas FROM santri WHERE {$aktifSql}")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $laporan = tagihan_laporan_12bulan_compute($pdo, $tm, $ts, $slots, $bulanList, $allSantri);
    echo "Laporan 12 bulan vs rekap tagihan (syahriyah aggregate):\n";
    foreach ($bulanList as $b) {
        $agg = keuangan_rekap_tagihan_agregat_pos($pdo, 'BULANAN', $b, $tm, $ts, $biayaDefs);
        $expLaporan = (int) ($laporan['expected_by_month'][$b] ?? 0);
        $expRekap = (int) ($agg['expected'] ?? 0);
        $match = $expLaporan === $expRekap ? 'OK' : 'SELISIH';
        echo "  Bulan {$b}: laporan Rp " . number_format($expLaporan, 0, ',', '.')
            . ' | rekap Rp ' . number_format($expRekap, 0, ',', '.')
            . " [{$match}]\n";
    }
}

echo "\nSelesai.\n";
