<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/**
 * Subquery agregat uang masuk per akun_id (pembayaran santri non-saku + pemasukan lain).
 * Pos saku dikecualikan — masuk ke Kas Titipan Saku (1103), bukan kas pondok.
 */
function keuangan_sql_subquery_masuk_per_akun(PDO $pdo, string $endParam = ':as_of'): string
{
    $unions = [];
    if (table_exists($pdo, 'keuangan_pembayaran')) {
        if (table_exists($pdo, 'keuangan_pembayaran_detail')) {
            $unions[] = "
                SELECT p.akun_id,
                       GREATEST(0, p.total_nominal - COALESCE(sk.saku_nom, 0)) AS nominal
                FROM keuangan_pembayaran p
                LEFT JOIN (
                    SELECT pembayaran_id, SUM(nominal) AS saku_nom
                    FROM keuangan_pembayaran_detail
                    WHERE LOWER(TRIM(pos_slug)) = 'saku'
                    GROUP BY pembayaran_id
                ) sk ON sk.pembayaran_id = p.id
                WHERE p.akun_id IS NOT NULL AND p.tanggal_bayar <= {$endParam}
            ";
        } else {
            $unions[] = "
                SELECT akun_id, total_nominal AS nominal
                FROM keuangan_pembayaran
                WHERE akun_id IS NOT NULL AND tanggal_bayar <= {$endParam}
            ";
        }
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

/**
 * Saldo fisik Kas Titipan Saku di bendahara (di luar kas pondok).
 * = top-up saku − pengeluaran manual − debit yang sudah disetor ke koperasi.
 */
function keuangan_kas_titipan_saku_saldo(PDO $pdo, ?string $asOf = null): int
{
    $asOf = $asOf !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) ? $asOf : date('Y-m-d');
    $topup = 0;
    $keluar = 0;

    if (table_exists($pdo, 'keuangan_pembayaran') && table_exists($pdo, 'keuangan_pembayaran_detail')) {
        $st = $pdo->prepare("
            SELECT COALESCE(SUM(d.nominal), 0)
            FROM keuangan_pembayaran_detail d
            INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
            WHERE LOWER(TRIM(d.pos_slug)) = 'saku'
              AND p.tanggal_bayar <= :as_of
        ");
        $st->execute(['as_of' => $asOf]);
        $topup = (int) round((float) ($st->fetchColumn() ?: 0));
    } elseif (table_exists($pdo, 'cashless_transactions')) {
        $st = $pdo->prepare("
            SELECT COALESCE(SUM(nominal), 0) FROM cashless_transactions
            WHERE UPPER(jenis) = 'TOPUP' AND DATE(tanggal) <= :as_of
        ");
        $st->execute(['as_of' => $asOf]);
        $topup = (int) round((float) ($st->fetchColumn() ?: 0));
    }

    if (table_exists($pdo, 'cashless_transactions')) {
        $st = $pdo->prepare("
            SELECT COALESCE(SUM(nominal), 0) FROM cashless_transactions
            WHERE UPPER(jenis) = 'PENGELUARAN' AND DATE(tanggal) <= :as_of
        ");
        $st->execute(['as_of' => $asOf]);
        $keluar += (int) round((float) ($st->fetchColumn() ?: 0));

        $hasSetor = column_exists($pdo, 'cashless_transactions', 'setor_at');
        if ($hasSetor) {
            $st = $pdo->prepare("
                SELECT COALESCE(SUM(nominal), 0) FROM cashless_transactions
                WHERE UPPER(jenis) = 'DEBIT'
                  AND setor_at IS NOT NULL
                  AND DATE(setor_at) <= :as_of
            ");
            $st->execute(['as_of' => $asOf]);
            $keluar += (int) round((float) ($st->fetchColumn() ?: 0));
        }
    }

    return max(0, $topup - $keluar);
}
