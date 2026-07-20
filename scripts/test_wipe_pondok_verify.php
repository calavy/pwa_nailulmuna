<?php

declare(strict_types=1);

/**
 * Verifikasi wipe keuangan pondok: saku & cashless tetap, syahriyah/pengeluaran hilang.
 * Semua perubahan di-rollback — aman dijalankan di database operasional.
 *
 * Usage: php scripts/test_wipe_pondok_verify.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/keuangan_pembayaran_admin.php';

ensure_keuangan_transaksi_tables($pdo);

/** @return array<string, int|float> */
function wipe_test_metrics(PDO $pdo): array
{
    $m = [
        'cashless_accounts' => 0,
        'cashless_saldo' => 0,
        'cashless_tx' => 0,
        'pengeluaran' => 0,
        'pemasukan' => 0,
        'pembayaran_all' => 0,
        'pembayaran_saku' => 0,
        'pembayaran_non_saku' => 0,
        'jurnal_cashless' => 0,
        'jurnal_pondok' => 0,
    ];

    if (table_exists($pdo, 'cashless_accounts')) {
        $m['cashless_accounts'] = (int) $pdo->query('SELECT COUNT(*) FROM cashless_accounts')->fetchColumn();
        $m['cashless_saldo'] = (int) round((float) $pdo->query('SELECT COALESCE(SUM(balance),0) FROM cashless_accounts')->fetchColumn());
    }
    if (table_exists($pdo, 'cashless_transactions')) {
        $m['cashless_tx'] = (int) $pdo->query('SELECT COUNT(*) FROM cashless_transactions')->fetchColumn();
    }
    if (table_exists($pdo, 'keuangan_pengeluaran')) {
        $m['pengeluaran'] = (int) $pdo->query('SELECT COUNT(*) FROM keuangan_pengeluaran')->fetchColumn();
    }
    if (table_exists($pdo, 'keuangan_pemasukan')) {
        $m['pemasukan'] = (int) $pdo->query('SELECT COUNT(*) FROM keuangan_pemasukan')->fetchColumn();
    }
    if (table_exists($pdo, 'keuangan_pembayaran')) {
        $m['pembayaran_all'] = (int) $pdo->query('SELECT COUNT(*) FROM keuangan_pembayaran')->fetchColumn();
    }
    if (table_exists($pdo, 'keuangan_pembayaran_detail')) {
        $m['pembayaran_saku'] = (int) $pdo->query("
            SELECT COUNT(DISTINCT pembayaran_id) FROM keuangan_pembayaran_detail
            WHERE LOWER(TRIM(pos_slug)) = 'saku' AND nominal > 0
        ")->fetchColumn();
        $m['pembayaran_non_saku'] = (int) $pdo->query("
            SELECT COUNT(DISTINCT pembayaran_id) FROM keuangan_pembayaran_detail
            WHERE LOWER(TRIM(pos_slug)) <> 'saku' AND nominal > 0
        ")->fetchColumn();
    }
    if (table_exists($pdo, 'akuntansi_jurnal_umum')) {
        $m['jurnal_cashless'] = (int) $pdo->query("
            SELECT COUNT(*) FROM akuntansi_jurnal_umum
            WHERE ref_type IN ('cashless_setor','cashless_debit','cashless_pengeluaran')
        ")->fetchColumn();
        $m['jurnal_pondok'] = (int) $pdo->query("
            SELECT COUNT(*) FROM akuntansi_jurnal_umum
            WHERE ref_type IN ('pengeluaran','pemasukan')
        ")->fetchColumn();
    }

    return $m;
}

$before = wipe_test_metrics($pdo);
$failures = [];

try {
    $pdo->beginTransaction();

    if (table_exists($pdo, 'akuntansi_jurnal_umum')) {
        $pdo->exec("DELETE FROM akuntansi_jurnal_umum WHERE ref_type IN ('pengeluaran','pemasukan')");
    }
    if (table_exists($pdo, 'keuangan_pengeluaran')) {
        $pdo->exec('DELETE FROM keuangan_pengeluaran');
    }
    if (table_exists($pdo, 'keuangan_pemasukan')) {
        $pdo->exec('DELETE FROM keuangan_pemasukan');
    }
    $pbCounts = keuangan_wipe_pondok_pembayaran($pdo);

    $after = wipe_test_metrics($pdo);

    if ($after['cashless_accounts'] !== $before['cashless_accounts']) {
        $failures[] = 'cashless_accounts berubah';
    }
    if ($after['cashless_saldo'] !== $before['cashless_saldo']) {
        $failures[] = 'saldo cashless berubah';
    }
    if ($after['cashless_tx'] !== $before['cashless_tx']) {
        $failures[] = 'cashless_transactions berubah';
    }
    if ($after['jurnal_cashless'] !== $before['jurnal_cashless']) {
        $failures[] = 'jurnal cashless berubah';
    }
    if ($after['pengeluaran'] !== 0) {
        $failures[] = 'pengeluaran belum 0';
    }
    if ($after['pemasukan'] !== 0) {
        $failures[] = 'pemasukan belum 0';
    }
    if ($after['jurnal_pondok'] !== 0) {
        $failures[] = 'jurnal pondok belum 0';
    }
    if ($after['pembayaran_non_saku'] !== 0) {
        $failures[] = 'masih ada pembayaran non-saku (' . $after['pembayaran_non_saku'] . ')';
    }
    if ($before['pembayaran_saku'] > 0 && $after['pembayaran_saku'] < $before['pembayaran_saku']) {
        $failures[] = 'pembayaran saku berkurang (' . $before['pembayaran_saku'] . ' -> ' . $after['pembayaran_saku'] . ')';
    }

    $pdo->rollBack();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "=== Wipe pondok verify (rolled back) ===\n";
echo 'Before: pengeluaran=' . $before['pengeluaran']
    . ', pemasukan=' . $before['pemasukan']
    . ', pembayaran_saku=' . $before['pembayaran_saku']
    . ', pembayaran_non_saku=' . $before['pembayaran_non_saku']
    . ', cashless_saldo=' . $before['cashless_saldo'] . "\n";
echo 'Pembayaran wipe counts: ' . json_encode($pbCounts ?? [], JSON_UNESCAPED_UNICODE) . "\n";

if ($failures === []) {
    echo "PASS — semua assertion terpenuhi.\n";
    exit(0);
}

echo "FAIL:\n";
foreach ($failures as $f) {
    echo "  - {$f}\n";
}
exit(1);
