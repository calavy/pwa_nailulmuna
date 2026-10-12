<?php

declare(strict_types=1);

/**
 * Rekonsiliasi total tagihan vs jumlah baris bulanan + Awal Tahun (CLI, tanpa PII).
 * Usage: php scripts/_diag_rekap_tagihan_reconcile.php [tm] [ts] [bulan]
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_rekap_kas_bulan.php';
require_once __DIR__ . '/../helpers/keuangan_rekap_tagihan_bulan.php';

keuangan_ensure_schema_deferred($pdo);

$periode = keuangan_periode_berjalan($pdo);
$tm = isset($argv[1]) ? (int) $argv[1] : (int) $periode['mulai'];
$ts = isset($argv[2]) ? (int) $argv[2] : (int) $periode['selesai'];
$bulan = isset($argv[3]) ? (int) $argv[3] : (int) $periode['bulan'];

$rekap = keuangan_build_rekap_kas_bulanan($pdo, $tm, $ts, $bulan);
$tagihan = is_array($rekap['tagihan'] ?? null) ? $rekap['tagihan'] : [];
$tagTot = is_array($tagihan['total'] ?? null) ? $tagihan['total'] : [];
$tagAwal = is_array($tagihan['awal_tahun'] ?? null) ? $tagihan['awal_tahun'] : [];
$sub = keuangan_rekap_kas_tagihan_subtotals($rekap);

$fmt = static fn (int $n): string => number_format($n, 0, ',', '.');

echo "=== Rekonsiliasi Tagihan Rekap Kas ===\n";
echo 'TA: ' . ($rekap['ta_label'] ?? '') . " (tm={$tm}, ts={$ts})\n";
echo 'Sampai bulan tagihan: ' . (int) ($rekap['bulan_berjalan'] ?? 0) . "\n\n";

echo "--- Per bulan (BULANAN) ---\n";
foreach ($tagihan['baris'] ?? [] as $t) {
    echo sprintf(
        "Bulan %d: target Rp %s, terbayar Rp %s, sisa Rp %s\n",
        (int) ($t['bulan'] ?? 0),
        $fmt((int) ($t['expected'] ?? 0)),
        $fmt((int) ($t['paid'] ?? 0)),
        $fmt((int) ($t['sisa'] ?? 0))
    );
}

echo "\n--- Awal Tahun (AWAL_TAHUN, terpisah dari bulan) ---\n";
echo 'Target Rp ' . $fmt((int) ($tagAwal['expected'] ?? 0)) . "\n";
echo 'Terbayar Rp ' . $fmt((int) ($tagAwal['paid'] ?? 0)) . "\n";
echo 'Sisa Rp ' . $fmt((int) ($tagAwal['sisa'] ?? 0)) . "\n";

echo "\n--- Subtotal vs total ---\n";
echo 'Jumlah terbayar baris bulan (1..N): Rp ' . $fmt((int) ($sub['bulanan_paid'] ?? 0)) . "\n";
echo 'Terbayar Awal Tahun: Rp ' . $fmt((int) ($sub['awal_tahun_paid'] ?? 0)) . "\n";
echo 'Terbayar total TA (bulanan + AT): Rp ' . $fmt((int) ($sub['total_paid'] ?? 0)) . "\n";
echo 'Footer/kartu tagihan.total.paid: Rp ' . $fmt((int) ($tagTot['paid'] ?? 0)) . "\n";
$selPaid = (int) ($tagTot['paid'] ?? 0) - (int) ($sub['total_paid'] ?? 0);
echo 'Selisih (total - subtotal): Rp ' . $fmt($selPaid) . ($selPaid === 0 ? " OK\n" : " PERLU CEK\n");

echo "\nJumlah sisa baris bulan: Rp " . $fmt((int) ($sub['bulanan_sisa'] ?? 0)) . "\n";
echo 'Sisa Awal Tahun: Rp ' . $fmt((int) ($sub['awal_tahun_sisa'] ?? 0)) . "\n";
echo 'Sisa total TA: Rp ' . $fmt((int) ($sub['total_sisa'] ?? 0)) . "\n";
echo 'Footer tagihan.total.sisa: Rp ' . $fmt((int) ($tagTot['sisa'] ?? 0)) . "\n";
$selSisa = (int) ($tagTot['sisa'] ?? 0) - (int) ($sub['total_sisa'] ?? 0);
echo 'Selisih sisa (total - subtotal): Rp ' . $fmt($selSisa) . ($selSisa === 0 ? " OK\n" : " PERLU CEK\n");

echo "\n--- Hipotesis selisih rincian vs footer (sebelum baris AT di UI) ---\n";
echo 'Selisih footer paid - sum(baris bulan saja): Rp ' . $fmt((int) ($tagTot['paid'] ?? 0) - (int) ($sub['bulanan_paid'] ?? 0)) . "\n";
echo "(Jika ≈ terbayar Awal Tahun, penyebab = cakupan Awal Tahun tidak di baris bulan.)\n";

$sumBarisUiPaid = 0;
$sumBarisUiSisa = 0;
foreach ($rekap['baris'] ?? [] as $row) {
    $sumBarisUiPaid += (int) ($row['tagihan_terbayar'] ?? 0);
    $sumBarisUiSisa += (int) ($row['tagihan_sisa'] ?? 0);
}
echo "\n--- Setelah baris Awal Tahun di UI ---\n";
echo 'Jumlah kolom Terbayar semua baris tabel: Rp ' . $fmt($sumBarisUiPaid) . "\n";
echo 'Footer tagihan.total.paid: Rp ' . $fmt((int) ($tagTot['paid'] ?? 0)) . "\n";
$uiSel = (int) ($tagTot['paid'] ?? 0) - $sumBarisUiPaid;
echo 'Selisih footer - jumlah baris UI: Rp ' . $fmt($uiSel) . ($uiSel === 0 ? " OK\n" : " PERLU CEK\n");

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
$cacheKeys = 0;
if (isset($_SESSION['keuangan_rekap_pos_cache_v1']) && is_array($_SESSION['keuangan_rekap_pos_cache_v1'])) {
    $cacheKeys = count($_SESSION['keuangan_rekap_pos_cache_v1']);
}
echo "\nCache rekap POS (sesi CLI): {$cacheKeys} entri\n";
