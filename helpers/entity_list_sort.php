<?php

declare(strict_types=1);

/** ORDER BY numerik untuk kolom kode (NIP, NIS, dll). */
function entity_list_numeric_order_sql(string $columnExpr, string $tieBreaker = ''): string
{
    $base = "CAST({$columnExpr} AS UNSIGNED) ASC, LENGTH({$columnExpr}) ASC, {$columnExpr} ASC";
    $tie = trim($tieBreaker);

    return $tie !== '' ? $base . ', ' . $tie : $base;
}

/** Nilai urut numerik nomor induk (NIP) untuk usort. */
function entity_nip_sort_value(string $nip): int
{
    $nip = trim($nip);
    if ($nip === '') {
        return PHP_INT_MAX;
    }
    if (ctype_digit($nip)) {
        return (int) $nip;
    }
    if (preg_match('/(\d+)/', $nip, $m)) {
        return (int) $m[1];
    }

    return PHP_INT_MAX - 1;
}

/**
 * Urutkan baris pembimbing menurut nomor induk (NIP).
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function entity_sort_pembimbing_rows(array $rows, string $nipKey = 'nip', string $namaKey = 'nama_pembimbing'): array
{
    usort(
        $rows,
        static function (array $a, array $b) use ($nipKey, $namaKey): int {
            $nipA = (string) ($a[$nipKey] ?? '');
            $nipB = (string) ($b[$nipKey] ?? '');
            $cmp = entity_nip_sort_value($nipA) <=> entity_nip_sort_value($nipB);
            if ($cmp !== 0) {
                return $cmp;
            }
            $lenCmp = strlen($nipA) <=> strlen($nipB);
            if ($lenCmp !== 0) {
                return $lenCmp;
            }
            $nipCmp = strcmp($nipA, $nipB);
            if ($nipCmp !== 0) {
                return $nipCmp;
            }

            return strcmp((string) ($a[$namaKey] ?? ''), (string) ($b[$namaKey] ?? ''));
        }
    );

    return $rows;
}

/**
 * Urutkan baris munawib: pembimbing induk (NIP) lalu nomor induk munawib.
 *
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function entity_sort_munawib_rows(
    array $rows,
    string $nipKey = 'nip',
    string $namaKey = 'nama',
    string $indukNipKey = 'pembimbing_induk_nip'
): array {
    usort(
        $rows,
        static function (array $a, array $b) use ($nipKey, $namaKey, $indukNipKey): int {
            $indukA = (string) ($a[$indukNipKey] ?? '');
            $indukB = (string) ($b[$indukNipKey] ?? '');
            $cmpInduk = entity_nip_sort_value($indukA) <=> entity_nip_sort_value($indukB);
            if ($cmpInduk !== 0) {
                return $cmpInduk;
            }
            $nipA = (string) ($a[$nipKey] ?? '');
            $nipB = (string) ($b[$nipKey] ?? '');
            $cmp = entity_nip_sort_value($nipA) <=> entity_nip_sort_value($nipB);
            if ($cmp !== 0) {
                return $cmp;
            }
            $lenCmp = strlen($nipA) <=> strlen($nipB);
            if ($lenCmp !== 0) {
                return $lenCmp;
            }
            $nipCmp = strcmp($nipA, $nipB);
            if ($nipCmp !== 0) {
                return $nipCmp;
            }

            return strcmp((string) ($a[$namaKey] ?? ''), (string) ($b[$namaKey] ?? ''));
        }
    );

    return $rows;
}

/**
 * Subquery SQL: NIP pembimbing induk aktif (penugasan munawib hari ini).
 */
function munawib_pembimbing_induk_nip_subquery_sql(string $munawibIdExpr = 'm.id', string $tanggalExpr = 'CURDATE()'): string
{
    return '(
        SELECT MIN(CAST(pb.nip AS UNSIGNED))
        FROM munawib_penugasan mp
        INNER JOIN pembimbing pb ON pb.id = mp.pembimbing_id
        WHERE mp.munawib_id = ' . $munawibIdExpr . '
          AND mp.status = "AKTIF"
          AND mp.pembimbing_id IS NOT NULL
          AND ' . $tanggalExpr . ' BETWEEN mp.tanggal_mulai AND mp.tanggal_selesai
    )';
}

/** Urutan munawib: pembimbing induk (NIP) lalu nomor induk munawib. */
function munawib_list_order_by_induk_sql(string $alias = 'm', ?string $tanggalYmd = null): string
{
    $tanggalExpr = $tanggalYmd !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalYmd)
        ? "'" . $tanggalYmd . "'"
        : 'CURDATE()';
    $idExpr = $alias !== '' ? $alias . '.id' : 'id';
    $indukSub = munawib_pembimbing_induk_nip_subquery_sql($idExpr, $tanggalExpr);
    $ownOrder = munawib_list_order_sql($alias);

    return "COALESCE({$indukSub}, 999999999) ASC, {$ownOrder}";
}

/** Urutan daftar pembimbing: nomor induk (NIP) lalu nama. $alias kosong jika query tanpa alias tabel. */
function pembimbing_list_order_sql(string $alias = ''): string
{
    $nip = $alias !== '' ? $alias . '.nip' : 'nip';
    $nama = $alias !== '' ? $alias . '.nama_pembimbing' : 'nama_pembimbing';
    global $pdo;
    if ($pdo instanceof PDO && function_exists('column_exists') && table_exists($pdo, 'pembimbing') && column_exists($pdo, 'pembimbing', 'nip')) {
        return entity_list_numeric_order_sql($nip, "{$nama} ASC");
    }

    return "{$nama} ASC";
}

/** Urutan daftar munawib (jika ada kolom nip/nomor, fallback nama). */
function munawib_list_order_sql(string $alias = 'm'): string
{
    global $pdo;
    if ($pdo instanceof PDO && table_exists($pdo, 'munawib') && column_exists($pdo, 'munawib', 'nip')) {
        $nip = $alias !== '' ? $alias . '.nip' : 'nip';
        $nama = $alias !== '' ? $alias . '.nama' : 'nama';

        return entity_list_numeric_order_sql($nip, "{$nama} ASC");
    }
    $nama = $alias !== '' ? $alias . '.nama' : 'nama';

    return "{$nama} ASC";
}

/** Urutan mukimin / pengurus internal bila pakai tabel users. */
function user_list_order_sql(string $alias = 'u'): string
{
    $username = $alias !== '' ? $alias . '.username' : 'username';
    $nama = $alias !== '' ? $alias . '.nama' : 'nama';
    if (!function_exists('column_exists')) {
        return "{$nama} ASC, {$username} ASC";
    }
    global $pdo;
    if ($pdo instanceof PDO && table_exists($pdo, 'users') && column_exists($pdo, 'users', 'nama')) {
        return "{$nama} ASC, {$username} ASC";
    }

    return "{$username} ASC";
}
