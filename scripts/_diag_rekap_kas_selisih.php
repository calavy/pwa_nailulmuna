<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_rekap_kas_bulan.php';
require_once __DIR__ . '/../helpers/keuangan_rekonsiliasi.php';
require_once __DIR__ . '/../helpers/keuangan_aruskas.php';
require_once __DIR__ . '/../helpers/pondok_kalender.php';

keuangan_ensure_schema_deferred($pdo);

$periode = keuangan_periode_berjalan($pdo);
$tm = (int) $periode['mulai'];
$ts = (int) $periode['selesai'];
$rekap = keuangan_build_rekap_kas_bulanan($pdo, $tm, $ts);

$slots = pondok_bulan_slots_tahun_ajaran($pdo, $tm, $ts);
$slot1 = pondok_slot_dari_bulan_tagihan($slots, 1);
$taStart = trim((string) ($slot1['masehi_awal'] ?? ''));
if ($taStart === '') {
    $taStart = sprintf('%04d-07-01', $tm);
}

echo "=== Rekap Kas Selisih Diagnostic ===\n";
echo 'TA: ' . ($rekap['ta_label'] ?? '') . "\n";
echo 'TA mulai (masehi): ' . $taStart . "\n";
echo 'Saldo awal TA: ' . number_format((int) $rekap['saldo_awal_ta'], 0, ',', '.') . "\n";
echo 'Saldo akhir (hitung): ' . number_format((int) $rekap['saldo_akhir'], 0, ',', '.') . "\n";
echo 'Saldo fisik: ' . number_format((int) $rekap['saldo_akhir_fisik'], 0, ',', '.') . "\n";
echo 'Selisih (fisik - hitung): ' . number_format((int) $rekap['selisih_saldo'], 0, ',', '.') . "\n\n";

$tot = $rekap['total'] ?? [];
echo 'Total masuk TA: ' . number_format((int) ($tot['masuk_total'] ?? 0), 0, ',', '.') . "\n";
echo 'Total keluar TA: ' . number_format((int) ($tot['keluar'] ?? 0), 0, ',', '.') . "\n";
echo 'Bersih TA: ' . number_format((int) ($tot['bersih'] ?? 0), 0, ',', '.') . "\n\n";

echo "--- Transaksi tanpa akun kas (sejak awal TA) ---\n";
foreach (
    [
        'keuangan_pembayaran' => ['tanggal_bayar', 'total_nominal'],
        'keuangan_pemasukan' => ['tanggal', 'nominal'],
        'keuangan_pengeluaran' => ['tanggal', 'nominal'],
    ] as $table => [$dateCol, $nomCol]
) {
    if (!table_exists($pdo, $table) || !column_exists($pdo, $table, 'akun_id')) {
        continue;
    }
    $st = $pdo->prepare("
        SELECT COUNT(*) AS c, COALESCE(SUM({$nomCol}), 0) AS n
        FROM {$table}
        WHERE {$dateCol} >= :d AND (akun_id IS NULL OR akun_id <= 0)
    ");
    $st->execute(['d' => $taStart]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    echo $table . ': ' . (int) ($r['c'] ?? 0) . ' transaksi, Rp ' . number_format((int) round((float) ($r['n'] ?? 0)), 0, ',', '.') . "\n";

    $st2 = $pdo->prepare("
        SELECT id, {$dateCol} AS tgl, {$nomCol} AS nominal
        FROM {$table}
        WHERE {$dateCol} >= :d AND (akun_id IS NULL OR akun_id <= 0)
        ORDER BY {$dateCol} DESC, id DESC
        LIMIT 10
    ");
    $st2->execute(['d' => $taStart]);
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $row) {
        echo '  #' . (int) $row['id'] . ' ' . $row['tgl'] . ' Rp ' . number_format((int) round((float) $row['nominal']), 0, ',', '.') . "\n";
    }
}

echo "\n--- Gaji pembimbing ---\n";
if (table_exists($pdo, 'keuangan_gaji_pembimbing')) {
    $hasLink = column_exists($pdo, 'keuangan_gaji_pembimbing', 'pengeluaran_id');
    if ($hasLink) {
        $gajiLinked = (int) round((float) ($pdo->query('SELECT COALESCE(SUM(total_bayar),0) FROM keuangan_gaji_pembimbing WHERE pengeluaran_id IS NOT NULL AND pengeluaran_id > 0')->fetchColumn() ?: 0));
        $gajiOrphan = (int) round((float) ($pdo->query('SELECT COALESCE(SUM(total_bayar),0) FROM keuangan_gaji_pembimbing WHERE pengeluaran_id IS NULL OR pengeluaran_id <= 0')->fetchColumn() ?: 0));
        echo 'Gaji sudah punya baris pengeluaran (hanya di fisik via pengeluaran): Rp ' . number_format($gajiLinked, 0, ',', '.') . "\n";
        echo 'Gaji tanpa pengeluaran (mutasi hitung, fisik tidak): Rp ' . number_format($gajiOrphan, 0, ',', '.') . "\n";
    } else {
        $gajiAll = (int) round((float) ($pdo->query('SELECT COALESCE(SUM(total_bayar),0) FROM keuangan_gaji_pembimbing')->fetchColumn() ?: 0));
        echo 'Total gaji (mungkin double-count jika juga ada di pengeluaran): Rp ' . number_format($gajiAll, 0, ',', '.') . "\n";
    }
}

echo "\n--- Selisih per bulan tagihan ---\n";
foreach ($rekap['baris'] ?? [] as $row) {
    $sel = (int) ($row['selisih_saldo'] ?? 0);
    echo sprintf(
        "Bulan %d (%s): hitung Rp %s, fisik Rp %s, selisih Rp %s, masuk Rp %s, keluar Rp %s\n",
        (int) ($row['bulan'] ?? 0),
        (string) ($row['periode_teks'] ?? ''),
        number_format((int) ($row['saldo_akhir'] ?? 0), 0, ',', '.'),
        number_format((int) ($row['saldo_fisik'] ?? 0), 0, ',', '.'),
        number_format($sel, 0, ',', '.'),
        number_format((int) ($row['masuk_total'] ?? 0), 0, ',', '.'),
        number_format((int) ($row['keluar'] ?? 0), 0, ',', '.')
    );
}

echo "\n--- Saldo per akun kas (fisik) ---\n";
if (table_exists($pdo, 'keuangan_akun')) {
    require_once __DIR__ . '/../helpers/keuangan_pengaturan.php';
    foreach (keuangan_fetch_akun_all_with_saldo($pdo) as $ak) {
        $saldo = (int) ($ak['saldo_berjalan'] ?? 0);
        if ($saldo !== 0) {
            echo (string) ($ak['nama_akun'] ?? '') . ' (' . (string) ($ak['jenis_akun'] ?? '') . '): Rp ' . number_format($saldo, 0, ',', '.') . "\n";
        }
    }
}
