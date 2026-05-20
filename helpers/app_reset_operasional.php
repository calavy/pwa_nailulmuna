<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/**
 * Kosongkan data transaksi operasional (pembayaran, pengeluaran, izin, presensi).
 * Data santri, pengaturan, akun kas, dan potongan syahriyah TIDAK dihapus.
 *
 * @return array{ok:bool,message:string,deleted:array<string,int>}
 */
function app_kosongkan_data_operasional(PDO $pdo): array
{
    $deleted = [];
    $errors = [];

    try {
        if (table_exists($pdo, 'point_ledger')) {
            $deleted['point_ledger_presensi_izin'] = (int) $pdo->exec('
                DELETE FROM point_ledger
                WHERE reference_presensi_id IS NOT NULL
                   OR UPPER(COALESCE(sumber_data, "")) LIKE "PRESENSI%"
                   OR UPPER(COALESCE(sumber_data, "")) LIKE "PERIZINAN%"
            ');
        }

        if (table_exists($pdo, 'keuangan_pembayaran_detail')) {
            $deleted['keuangan_pembayaran_detail'] = (int) $pdo->exec('DELETE FROM keuangan_pembayaran_detail');
        }

        if (table_exists($pdo, 'cashless_transactions')) {
            $deleted['cashless_transactions'] = (int) $pdo->exec('DELETE FROM cashless_transactions');
        }

        if (table_exists($pdo, 'keuangan_pembayaran')) {
            $deleted['keuangan_pembayaran'] = (int) $pdo->exec('DELETE FROM keuangan_pembayaran');
        }

        if (table_exists($pdo, 'cashless_accounts')) {
            $deleted['cashless_accounts_reset'] = (int) $pdo->exec('UPDATE cashless_accounts SET balance = 0');
        }

        if (table_exists($pdo, 'keuangan_pengeluaran')) {
            $deleted['keuangan_pengeluaran'] = (int) $pdo->exec('DELETE FROM keuangan_pengeluaran');
        }

        if (table_exists($pdo, 'akuntansi_jurnal_umum')) {
            $deleted['akuntansi_jurnal_umum_keuangan'] = (int) $pdo->exec('
                DELETE FROM akuntansi_jurnal_umum
                WHERE ref_type IN ("pembayaran", "pengeluaran")
            ');
        }

        if (table_exists($pdo, 'presensi')) {
            $deleted['presensi'] = (int) $pdo->exec('DELETE FROM presensi');
        }

        if (table_exists($pdo, 'presensi_pembimbing')) {
            $deleted['presensi_pembimbing'] = (int) $pdo->exec('DELETE FROM presensi_pembimbing');
        }

        if (table_exists($pdo, 'perizinan')) {
            $deleted['perizinan'] = (int) $pdo->exec('DELETE FROM perizinan');
        }

        if (table_exists($pdo, 'ehealth_records')) {
            $deleted['ehealth_records'] = (int) $pdo->exec('DELETE FROM ehealth_records');
        }

        $resetAuto = [
            'keuangan_pembayaran',
            'keuangan_pembayaran_detail',
            'keuangan_pengeluaran',
            'cashless_transactions',
            'presensi',
            'presensi_pembimbing',
            'perizinan',
            'ehealth_records',
            'akuntansi_jurnal_umum',
        ];
        foreach ($resetAuto as $tbl) {
            if (table_exists($pdo, $tbl)) {
                try {
                    $pdo->exec('ALTER TABLE `' . str_replace('`', '``', $tbl) . '` AUTO_INCREMENT = 1');
                } catch (PDOException $e) {
                    // abaikan jika tidak ada kolom AI
                }
            }
        }

        return [
            'ok' => true,
            'message' => 'Data operasional dikosongkan. Data santri dan pengaturan tetap ada.',
            'deleted' => $deleted,
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'message' => 'Gagal mengosongkan data: ' . $e->getMessage(),
            'deleted' => $deleted,
            'errors' => $errors,
        ];
    }
}
