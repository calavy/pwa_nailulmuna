<?php

declare(strict_types=1);

/**
 * Verifikasi audit: pembayaran Saku ↔ saldo cashless.
 * Jalankan: php scripts/verify_saku_cashless_audit.php
 * Opsi: --santri="Nama"  --top=20
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_pembayaran_admin.php';
require_once __DIR__ . '/../helpers/keuangan_neraca.php';
require_once __DIR__ . '/../helpers/keuangan_neraca_perbaikan.php';
require_once __DIR__ . '/../helpers/cashless_koperasi.php';

keuangan_ensure_schema_deferred($pdo);
cashless_koperasi_ensure_schema($pdo);

$santriFilter = null;
$top = 20;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--santri=')) {
        $santriFilter = substr($arg, 9);
        if (($santriFilter[0] ?? '') === '"' && str_ends_with($santriFilter, '"')) {
            $santriFilter = substr($santriFilter, 1, -1);
        }
    } elseif (str_starts_with($arg, '--top=')) {
        $top = max(1, min(500, (int) substr($arg, 6)));
    }
}

$asOf = date('Y-m-d');
$neraca = keuangan_build_neraca($pdo, $asOf);
$analisis = keuangan_neraca_analisis_selisih($pdo, $neraca);

$pembayaranSaku = 0;
$topupDariPembayaran = 0;
$orphanPembayaran = 0;

if (table_exists($pdo, 'keuangan_pembayaran_detail')) {
    $pembayaranSaku = (int) $pdo->query("
        SELECT COALESCE(SUM(d.nominal), 0)
        FROM keuangan_pembayaran_detail d
        INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
        WHERE LOWER(TRIM(d.pos_slug)) = 'saku' AND d.nominal > 0
    ")->fetchColumn();
}

if (table_exists($pdo, 'cashless_transactions')) {
    $topupDariPembayaran = (int) $pdo->query("
        SELECT COALESCE(SUM(nominal), 0)
        FROM cashless_transactions
        WHERE UPPER(jenis) = 'TOPUP' AND ref_pembayaran_id IS NOT NULL AND ref_pembayaran_id > 0
    ")->fetchColumn();

    $orphanPembayaran = (int) $pdo->query("
        SELECT COUNT(DISTINCT p.id)
        FROM keuangan_pembayaran_detail d
        INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
        LEFT JOIN cashless_transactions ct ON ct.ref_pembayaran_id = p.id AND UPPER(ct.jenis) = 'TOPUP'
        WHERE LOWER(TRIM(d.pos_slug)) = 'saku' AND d.nominal > 0 AND ct.id IS NULL
    ")->fetchColumn();
}

$cashlessTotal = (int) (cashless_saku_total_real($pdo)['total'] ?? 0);
$selisihSaku = (int) ($analisis['selisih_saku_cashless'] ?? 0);
$mismatchSantri = keuangan_saku_cashless_audit_per_santri($pdo, $santriFilter, true, 5000);

echo "=== Audit Saku → Cashless ===\n";
echo "Tanggal: {$asOf}\n";
echo "Total pembayaran pos Saku (detail): Rp " . number_format($pembayaranSaku, 0, ',', '.') . "\n";
echo "Total TOPUP cashless (dari pembayaran): Rp " . number_format($topupDariPembayaran, 0, ',', '.') . "\n";
echo "Saldo cashless seluruh santri (ledger): Rp " . number_format($cashlessTotal, 0, ',', '.') . "\n";
echo "Selisih saku vs cashless (neraca): Rp " . number_format($selisihSaku, 0, ',', '.') . "\n";
echo "Pembayaran saku tanpa TOPUP: {$orphanPembayaran}\n";
echo "Santri tidak selaras (bayar vs top-up): " . count($mismatchSantri) . "\n";
echo "Fungsi apply_cashless_saku: " . (function_exists('keuangan_pembayaran_apply_cashless_saku') ? 'OK' : 'MISSING') . "\n";
echo "Fungsi guard topup_exists: " . (function_exists('keuangan_pembayaran_cashless_topup_exists') ? 'OK' : 'MISSING') . "\n";

if ($mismatchSantri !== []) {
    echo "\n--- Santri tidak selaras";
    if ($santriFilter !== null && trim($santriFilter) !== '') {
        echo ' (filter: ' . $santriFilter . ')';
    }
    echo " (max {$top}) ---\n";
    echo str_pad('Santri', 32) . str_pad('Bayar', 8, ' ', STR_PAD_LEFT)
        . str_pad('Top-up', 8, ' ', STR_PAD_LEFT)
        . str_pad('Total saku', 16, ' ', STR_PAD_LEFT)
        . str_pad('Total top-up', 16, ' ', STR_PAD_LEFT)
        . str_pad('Selisih', 14, ' ', STR_PAD_LEFT) . "\n";
    $shown = 0;
    foreach ($mismatchSantri as $row) {
        if ($shown >= $top) {
            break;
        }
        $nama = (string) ($row['nama_santri'] ?? '');
        if (function_exists('mb_strimwidth')) {
            $nama = mb_strimwidth($nama, 0, 30, '…', 'UTF-8');
        } elseif (strlen($nama) > 30) {
            $nama = substr($nama, 0, 27) . '...';
        }
        echo str_pad($nama, 32)
            . str_pad((string) (int) ($row['jumlah_pembayaran_saku'] ?? 0), 8, ' ', STR_PAD_LEFT)
            . str_pad((string) (int) ($row['jumlah_topup_terkait'] ?? 0), 8, ' ', STR_PAD_LEFT)
            . str_pad(number_format((int) ($row['total_nominal_saku'] ?? 0), 0, ',', '.'), 16, ' ', STR_PAD_LEFT)
            . str_pad(number_format((int) ($row['total_topup_terkait'] ?? 0), 0, ',', '.'), 16, ' ', STR_PAD_LEFT)
            . str_pad(number_format((int) ($row['selisih'] ?? 0), 0, ',', '.'), 14, ' ', STR_PAD_LEFT) . "\n";
        $shown++;
    }
    if (count($mismatchSantri) > $top) {
        echo '... dan ' . (count($mismatchSantri) - $top) . " santri lainnya.\n";
    }
}

$ok = $orphanPembayaran === 0 && $mismatchSantri === [] && abs($selisihSaku) <= max(0, $cashlessTotal > 0 ? 1 : 0);
echo $ok ? "\nSTATUS: OK (tidak ada orphan, santri selaras, selisih neraca konsisten)\n" : "\nSTATUS: PERLU CEK (ada data tidak selaras)\n";
exit($ok ? 0 : 1);
