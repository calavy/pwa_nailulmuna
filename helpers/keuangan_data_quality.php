<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_tanggal_validasi.php';
require_once __DIR__ . '/keuangan_ta_context.php';
require_once __DIR__ . '/tagihan_santri_eligible.php';
require_once __DIR__ . '/keuangan_impor_ekspor.php';

/**
 * @return list<array<string, mixed>>
 */
function keuangan_data_quality_bad_dates(PDO $pdo, int $limit = 500): array
{
    $out = [];
    $bounds = keuangan_tanggal_ta_bounds($pdo);
    $tables = [
        ['keuangan_pemasukan', 'tanggal', 'pemasukan'],
        ['keuangan_pengeluaran', 'tanggal', 'pengeluaran'],
        ['keuangan_pembayaran', 'tanggal_bayar', 'pembayaran'],
    ];
    foreach ($tables as [$table, $col, $kind]) {
        if (!table_exists($pdo, $table)) {
            continue;
        }
        $st = $pdo->query("SELECT id, {$col} AS tanggal FROM {$table} ORDER BY id ASC");
        foreach ($st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
            $tgl = (string) ($row['tanggal'] ?? '');
            $bad = keuangan_tanggal_normalize_ymd($tgl) === null
                || !keuangan_tanggal_in_ta_window($pdo, $tgl);
            if (!$bad && keuangan_tanggal_normalize_ymd($tgl) !== null) {
                continue;
            }
            $out[] = [
                'kind' => $kind,
                'table' => $table,
                'id' => (int) ($row['id'] ?? 0),
                'tanggal' => $tgl,
                'reason' => keuangan_tanggal_normalize_ymd($tgl) === null ? 'format/tahun' : 'luar_TA',
                'bounds_min' => $bounds['min'],
                'bounds_max' => $bounds['max'],
            ];
            if (count($out) >= $limit) {
                return $out;
            }
        }
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function keuangan_data_quality_santri_duplicates(PDO $pdo): array
{
    if (!table_exists($pdo, 'santri')) {
        return [];
    }
    $sql = 'SELECT id, nis, nama_santri, COALESCE(is_aktif, 1) AS is_aktif FROM santri';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byKey = [];
    foreach ($rows as $r) {
        $nis = trim((string) ($r['nis'] ?? ''));
        $key = keuangan_impor_ekspor_normalize_nis($nis);
        if ($key === '') {
            continue;
        }
        $byKey[$key][] = $r;
    }
    $out = [];
    foreach ($rows as $r) {
        $nis = trim((string) ($r['nis'] ?? ''));
        if (tagihan_santri_nis_shadow($nis)) {
            $out[] = [
                'type' => 'nis_shadow',
                'id' => (int) $r['id'],
                'nis' => $nis,
                'nama' => (string) ($r['nama_santri'] ?? ''),
                'is_aktif' => (int) ($r['is_aktif'] ?? 1),
            ];
        }
    }
    foreach ($byKey as $key => $group) {
        if (count($group) < 2) {
            continue;
        }
        $aktif = array_filter($group, static fn (array $g): bool => (int) ($g['is_aktif'] ?? 1) === 1);
        if (count($aktif) < 2) {
            continue;
        }
        foreach ($aktif as $g) {
            $out[] = [
                'type' => 'nis_duplikat_aktif',
                'id' => (int) $g['id'],
                'nis' => (string) ($g['nis'] ?? ''),
                'nis_key' => $key,
                'nama' => (string) ($g['nama_santri'] ?? ''),
                'is_aktif' => 1,
            ];
        }
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function keuangan_data_quality_pengeluaran_tanpa_bukti(PDO $pdo, int $limit = 500): array
{
    if (!table_exists($pdo, 'keuangan_pengeluaran') || !column_exists($pdo, 'keuangan_pengeluaran', 'no_bukti')) {
        return [];
    }
    $metCol = column_exists($pdo, 'keuangan_pengeluaran', 'metode_keluar') ? 'metode_keluar' : "'KAS' AS metode_keluar";
    $st = $pdo->query("
        SELECT id, tanggal, {$metCol}, TRIM(COALESCE(no_bukti, '')) AS no_bukti
        FROM keuangan_pengeluaran
        WHERE no_bukti IS NULL OR TRIM(no_bukti) = ''
        ORDER BY id ASC
        LIMIT " . (int) $limit);
    $out = [];
    foreach ($st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'tanggal' => (string) ($row['tanggal'] ?? ''),
            'metode_keluar' => strtoupper(trim((string) ($row['metode_keluar'] ?? 'KAS'))),
        ];
    }

    return $out;
}

/**
 * Flag POS/keterangan mismatch (tidak auto-perbaiki).
 *
 * @return list<array<string, mixed>>
 */
function keuangan_data_quality_pos_outliers(PDO $pdo, int $limit = 100): array
{
    if (!table_exists($pdo, 'keuangan_pengeluaran')) {
        return [];
    }
    $cols = 'id, tanggal, pos, alokasi_nama, keterangan, nominal';
    $st = $pdo->query("SELECT {$cols} FROM keuangan_pengeluaran ORDER BY id DESC LIMIT 2000");
    $out = [];
    foreach ($st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
        $hay = strtolower(
            (string) ($row['keterangan'] ?? '') . ' '
            . (string) ($row['pos'] ?? '') . ' '
            . (string) ($row['alokasi_nama'] ?? '')
        );
        $flag = null;
        if (str_contains($hay, 'carry') || str_contains($hay, 'mobil')) {
            if (str_contains(strtolower((string) ($row['pos'] ?? '')), 'inventaris')) {
                $flag = 'mobil_vs_inventaris';
            }
        }
        if ($flag === null && str_contains($hay, 'taujihat')) {
            $posL = strtolower((string) ($row['pos'] ?? ''));
            if (str_contains($posL, 'pendaftaran') || str_contains($posL, 'administrasi')) {
                $flag = 'taujihat_vs_pendaftaran';
            }
        }
        if ($flag !== null) {
            $out[] = [
                'flag' => $flag,
                'id' => (int) ($row['id'] ?? 0),
                'tanggal' => (string) ($row['tanggal'] ?? ''),
                'pos' => (string) ($row['pos'] ?? ''),
                'alokasi_nama' => (string) ($row['alokasi_nama'] ?? ''),
                'keterangan' => mb_substr((string) ($row['keterangan'] ?? ''), 0, 120),
                'nominal' => (int) ($row['nominal'] ?? 0),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
    }

    return $out;
}
