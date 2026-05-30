<?php

declare(strict_types=1);

/** ORDER BY numerik untuk kolom kode (NIP, NIS, dll). */
function entity_list_numeric_order_sql(string $columnExpr, string $tieBreaker = ''): string
{
    $base = "CAST({$columnExpr} AS UNSIGNED) ASC, LENGTH({$columnExpr}) ASC, {$columnExpr} ASC";
    $tie = trim($tieBreaker);

    return $tie !== '' ? $base . ', ' . $tie : $base;
}

/** Urutan daftar pembimbing: NIP lalu nama. $alias kosong jika query tanpa alias tabel. */
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
