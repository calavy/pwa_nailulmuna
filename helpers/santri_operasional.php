<?php

declare(strict_types=1);

require_once __DIR__ . '/santri_status.php';

/**
 * Filter SQL: santri aktif mondok (status AKTIF saja — untuk daftar santri aktif & presensi).
 */
function santri_sql_aktif_only(string $alias = 's'): string
{
    return 'UPPER(TRIM(COALESCE(NULLIF(' . $alias . '.status_santri, \'\'), \'AKTIF\'))) = \'AKTIF\'';
}

/** Filter SQL: masih di pondok (AKTIF atau Pengabdian/Khidmah). */
function santri_sql_di_pondok(string $alias = 's'): string
{
    return 'UPPER(TRIM(COALESCE(NULLIF(' . $alias . '.status_santri, \'\'), \'AKTIF\'))) IN (\'AKTIF\', \'KHIDMAH\')';
}

/**
 * Hapus data operasional (izin, presensi, catatan e-health terkait, poin dari presensi)
 * saat santri ditandai non aktif — tidak menghapus biodata / keuangan.
 */
function santri_hapus_data_operasional_nonaktif(PDO $pdo, int $santriId): void
{
    if ($santriId <= 0) {
        return;
    }

    if (table_exists($pdo, 'point_ledger')) {
        $pdo->prepare(
            'DELETE FROM point_ledger WHERE santri_id = :id AND (reference_presensi_id IS NOT NULL OR sumber_data LIKE :pref)'
        )->execute(['id' => $santriId, 'pref' => 'PRESENSI%']);
    }

    if (table_exists($pdo, 'presensi')) {
        $pdo->prepare('DELETE FROM presensi WHERE santri_id = :id')->execute(['id' => $santriId]);
    }

    if (table_exists($pdo, 'perizinan')) {
        $pdo->prepare('DELETE FROM perizinan WHERE santri_id = :id')->execute(['id' => $santriId]);
    }

    if (table_exists($pdo, 'ehealth_records')) {
        $pdo->prepare('DELETE FROM ehealth_records WHERE santri_id = :id')->execute(['id' => $santriId]);
    }
}
