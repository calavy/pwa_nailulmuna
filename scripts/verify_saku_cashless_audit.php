<?php

declare(strict_types=1);

/**
 * Verifikasi audit: pembayaran Saku ↔ saldo cashless.
 * Jalankan: php scripts/verify_saku_cashless_audit.php
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
        SELECT COUNT(*)
        FROM keuangan_pembayaran_detail d
        INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
        LEFT JOIN cashless_transactions ct ON ct.ref_pembayaran_id = p.id AND UPPER(ct.jenis) = 'TOPUP'
        WHERE LOWER(TRIM(d.pos_slug)) = 'saku' AND d.nominal > 0 AND ct.id IS NULL
    ")->fetchColumn();
}

$cashlessTotal = (int) (cashless_saku_total_real($pdo)['total'] ?? 0);
$selisihSaku = (int) ($analisis['selisih_saku_cashless'] ?? 0);

echo "=== Audit Saku → Cashless ===\n";
echo "Tanggal: {$asOf}\n";
echo "Total pembayaran pos Saku (detail): Rp " . number_format($pembayaranSaku, 0, ',', '.') . "\n";
echo "Total TOPUP cashless (dari pembayaran): Rp " . number_format($topupDariPembayaran, 0, ',', '.') . "\n";
echo "Saldo cashless seluruh santri (ledger): Rp " . number_format($cashlessTotal, 0, ',', '.') . "\n";
echo "Selisih saku vs cashless (neraca): Rp " . number_format($selisihSaku, 0, ',', '.') . "\n";
echo "Pembayaran saku tanpa TOPUP: {$orphanPembayaran}\n";
echo "Fungsi apply_cashless_saku: " . (function_exists('keuangan_pembayaran_apply_cashless_saku') ? 'OK' : 'MISSING') . "\n";
echo "Fungsi guard topup_exists: " . (function_exists('keuangan_pembayaran_cashless_topup_exists') ? 'OK' : 'MISSING') . "\n";

$ok = $orphanPembayaran === 0 && abs($selisihSaku) <= max(0, $cashlessTotal > 0 ? 1 : 0);
echo $ok ? "\nSTATUS: OK (tidak ada orphan, selisih neraca konsisten)\n" : "\nSTATUS: PERLU CEK (ada data tidak selaras)\n";
exit($ok ? 0 : 1);
