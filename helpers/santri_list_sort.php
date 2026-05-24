<?php

declare(strict_types=1);

/** Kolom nama di tabel santri: `nama_santri` atau `nama` (legacy). */
function santri_list_nama_db_column(?PDO $pdo = null): string
{
    static $cachedCol = null;
    if ($pdo === null) {
        global $pdo;
    }
    if (!($pdo instanceof PDO)) {
        return $cachedCol ?? 'nama_santri';
    }
    if ($cachedCol !== null) {
        return $cachedCol;
    }
    if (!function_exists('table_exists')) {
        require_once __DIR__ . '/app.php';
    }
    if (!table_exists($pdo, 'santri')) {
        return 'nama_santri';
    }
    $cachedCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';

    return $cachedCol;
}

/** SELECT … AS nama_santri untuk konsistensi baris hasil. */
function santri_list_select_nama_sql(?PDO $pdo, string $alias = 's', string $as = 'nama_santri'): string
{
    $col = santri_list_nama_db_column($pdo);
    $ref = santri_list_sort_col($alias, $col);

    return $as !== '' && $as !== $col ? "{$ref} AS {$as}" : $ref;
}

/** @return list<string> */
function santri_list_sort_modes(): array
{
    return ['nama', 'nis', 'tingkatan'];
}

function santri_list_sort_label(string $mode): string
{
    return match ($mode) {
        'nis' => 'NIS',
        'tingkatan' => 'Tingkatan',
        default => 'Nama',
    };
}

/**
 * Mode urutan daftar santri (sesi). $requested dari ?santri_sort=nama|nis|tingkatan
 */
function santri_list_sort_mode(?string $requested = null): string
{
    static $resolved = null;
    if ($resolved !== null && ($requested === null || $requested === '')) {
        return $resolved;
    }

    $req = strtolower(trim((string) $requested));
    if ($req !== '' && in_array($req, santri_list_sort_modes(), true)) {
        $_SESSION['santri_list_sort_v1'] = $req;
        $resolved = $req;

        return $resolved;
    }

    $stored = strtolower(trim((string) ($_SESSION['santri_list_sort_v1'] ?? 'nama')));
    $resolved = in_array($stored, santri_list_sort_modes(), true) ? $stored : 'nama';

    return $resolved;
}

function santri_list_sort_col(string $alias, string $column): string
{
    return $alias !== '' ? $alias . '.' . $column : $column;
}

/** Ekspresi SQL urutan tingkatan (Muadalah → Wustho → Ulya → lainnya). */
function santri_list_tingkatan_order_expr(string $alias = 's'): string
{
    $t = 'UPPER(TRIM(COALESCE(' . santri_list_sort_col($alias, 'tingkatan') . ", '')))";

    return "CASE
        WHEN {$t} LIKE '%MUAD%' OR {$t} LIKE '%MTS%' OR {$t} LIKE '%SMP%' OR {$t} = 'M' THEN 1
        WHEN {$t} LIKE '%WUST%' OR {$t} LIKE '%WUSTO%' OR {$t} = 'W' THEN 2
        WHEN {$t} LIKE '%ULY%' OR {$t} LIKE '%ALIYAH%' OR {$t} LIKE '%SMA%' OR {$t} LIKE '%SMK%' OR {$t} = 'U' THEN 3
        ELSE 9
    END";
}

/** Klausa ORDER BY untuk query daftar santri (tanpa kata ORDER BY). */
function santri_list_order_sql(string $alias = 's', ?PDO $pdo = null): string
{
    $mode = santri_list_sort_mode();
    $nis = santri_list_sort_col($alias, 'nis');
    $nama = santri_list_sort_col($alias, santri_list_nama_db_column($pdo));
    $nisOrder = "CAST({$nis} AS UNSIGNED) ASC, LENGTH({$nis}) ASC, {$nis} ASC";

    return match ($mode) {
        'nis' => "{$nisOrder}, {$nama} ASC",
        'tingkatan' => santri_list_tingkatan_order_expr($alias) . " ASC, {$nama} ASC, {$nisOrder}",
        default => "{$nama} ASC, {$nisOrder}",
    };
}

/** Nama kolom santri pada baris hasil query (nama_santri atau nama). */
function santri_list_row_nama(array $row): string
{
    return trim((string) ($row['nama_santri'] ?? $row['nama'] ?? ''));
}

function santri_list_row_nis(array $row): string
{
    return trim((string) ($row['nis'] ?? ''));
}

function santri_list_row_tingkatan(array $row): string
{
    return trim((string) ($row['tingkatan'] ?? ''));
}

function santri_list_tingkatan_rank(string $tingkatan): int
{
    $t = strtoupper($tingkatan);
    if ($t === '' || $t === 'M' || str_contains($t, 'MUAD') || str_contains($t, 'MTS') || str_contains($t, 'SMP')) {
        return $t === '' ? 99 : 1;
    }
    if ($t === 'W' || str_contains($t, 'WUST')) {
        return 2;
    }
    if ($t === 'U' || str_contains($t, 'ULY') || str_contains($t, 'ALIYAH') || str_contains($t, 'SMA') || str_contains($t, 'SMK')) {
        return 3;
    }

    return 9;
}

function santri_list_compare_rows(array $a, array $b, ?string $mode = null): int
{
    $mode ??= santri_list_sort_mode();

    if ($mode === 'nis') {
        $na = santri_list_row_nis($a);
        $nb = santri_list_row_nis($b);
        $ia = (int) preg_replace('/\D/', '', $na);
        $ib = (int) preg_replace('/\D/', '', $nb);
        if ($ia !== $ib) {
            return $ia <=> $ib;
        }
        $cmp = strcmp($na, $nb);
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcasecmp(santri_list_row_nama($a), santri_list_row_nama($b));
    }

    if ($mode === 'tingkatan') {
        $ra = santri_list_tingkatan_rank(santri_list_row_tingkatan($a));
        $rb = santri_list_tingkatan_rank(santri_list_row_tingkatan($b));
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }
        $cmp = strcasecmp(santri_list_row_tingkatan($a), santri_list_row_tingkatan($b));
        if ($cmp !== 0) {
            return $cmp;
        }
        $cmp = strcasecmp(santri_list_row_nama($a), santri_list_row_nama($b));
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp(santri_list_row_nis($a), santri_list_row_nis($b));
    }

    $cmp = strcasecmp(santri_list_row_nama($a), santri_list_row_nama($b));
    if ($cmp !== 0) {
        return $cmp;
    }

    $na = santri_list_row_nis($a);
    $nb = santri_list_row_nis($b);

    return ((int) preg_replace('/\D/', '', $na)) <=> ((int) preg_replace('/\D/', '', $nb))
        ?: strcmp($na, $nb);
}

/**
 * Urutkan array baris santri di memori (setelah filter PHP).
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function santri_list_sort_rows(array $rows, ?string $mode = null): array
{
    if ($rows === []) {
        return $rows;
    }
    usort($rows, static fn(array $a, array $b): int => santri_list_compare_rows($a, $b, $mode));

    return $rows;
}

/**
 * Urutan dengan metrik utama (mis. poin DESC) bila mode=nama; NIS/tingkatan mengganti urutan utama.
 */
function santri_list_order_sql_with_primary(string $alias, string $primaryOrderSql, ?PDO $pdo = null): string
{
    $mode = santri_list_sort_mode();
    if ($mode === 'nis' || $mode === 'tingkatan') {
        return santri_list_order_sql($alias, $pdo);
    }

    return trim($primaryOrderSql) . ', ' . santri_list_order_sql($alias, $pdo);
}

/** Query string untuk tautan ubah urutan (pertahankan GET lain). */
function santri_list_sort_query(string $mode, array $preserve = []): string
{
    $params = $preserve;
    $params['santri_sort'] = $mode;

    return http_build_query($params);
}
