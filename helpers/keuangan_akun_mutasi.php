<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/**
 * Subquery agregat uang masuk per akun_id (pembayaran santri + pemasukan lain).
 */
function keuangan_sql_subquery_masuk_per_akun(PDO $pdo, string $endParam = ':as_of'): string
{
    $unions = [];
    if (table_exists($pdo, 'keuangan_pembayaran')) {
        $unions[] = "
            SELECT akun_id, total_nominal AS nominal
            FROM keuangan_pembayaran
            WHERE akun_id IS NOT NULL AND tanggal_bayar <= {$endParam}
        ";
    }
    if (table_exists($pdo, 'keuangan_pemasukan')) {
        $unions[] = "
            SELECT akun_id, nominal
            FROM keuangan_pemasukan
            WHERE akun_id IS NOT NULL AND tanggal <= {$endParam}
        ";
    }
    if ($unions === []) {
        return 'SELECT NULL AS akun_id, 0 AS total_masuk WHERE 1=0';
    }

    return '
        SELECT akun_id, SUM(nominal) AS total_masuk
        FROM (' . implode(' UNION ALL ', $unions) . ') u
        GROUP BY akun_id
    ';
}
