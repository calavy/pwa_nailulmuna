<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/presensi_admin.php';
require_once __DIR__ . '/operasional_audit.php';
require_once __DIR__ . '/kegiatan_khusus.php';
require_once __DIR__ . '/munawib.php';

const PRESENSI_DATA_MODUL_AUDIT = 'presensi_data';

/** Jenis yang bisa dipilih saat hapus massal. */
function presensi_data_jenis_hapus_keys(): array
{
    return ['santri', 'pembimbing', 'munawib'];
}

/** @return array<string, array{label:string, table:string, date_col:string}> */
function presensi_data_jenis_map(): array
{
    return [
        'santri' => [
            'label' => 'Presensi santri',
            'table' => 'presensi',
            'date_col' => 'tanggal_presensi',
        ],
        'pembimbing' => [
            'label' => 'Presensi pembimbing',
            'table' => 'presensi_pembimbing',
            'date_col' => 'tanggal',
        ],
        'munawib' => [
            'label' => 'Presensi munawib',
            'table' => 'presensi_munawib',
            'date_col' => 'tanggal',
        ],
        'khusus' => [
            'label' => 'Presensi kegiatan khusus',
            'table' => 'presensi_kegiatan_khusus',
            'date_col' => 'tanggal',
        ],
    ];
}

/** @param list<string> $jenis
 * @return array{ok:bool, message:string, mulai:string, selesai:string}
 */
function presensi_data_parse_rentang(string $mulai, string $selesai): array
{
    $mulai = trim($mulai);
    $selesai = trim($selesai);
    if ($mulai === '' || $selesai === '') {
        return ['ok' => false, 'message' => 'Tanggal mulai dan selesai wajib diisi.', 'mulai' => '', 'selesai' => ''];
    }
    $tsMulai = strtotime($mulai);
    $tsSelesai = strtotime($selesai);
    if ($tsMulai === false || $tsSelesai === false) {
        return ['ok' => false, 'message' => 'Format tanggal tidak valid.', 'mulai' => '', 'selesai' => ''];
    }
    if ($tsMulai > $tsSelesai) {
        return ['ok' => false, 'message' => 'Tanggal mulai tidak boleh setelah tanggal selesai.', 'mulai' => '', 'selesai' => ''];
    }
    $spanDays = (int) floor(($tsSelesai - $tsMulai) / 86400) + 1;
    if ($spanDays > 366) {
        return ['ok' => false, 'message' => 'Rentang maksimal 366 hari per operasi.', 'mulai' => '', 'selesai' => ''];
    }

    return [
        'ok' => true,
        'message' => '',
        'mulai' => date('Y-m-d', $tsMulai),
        'selesai' => date('Y-m-d', $tsSelesai),
    ];
}

/** @param list<string> $jenis */
function presensi_data_normalize_jenis(array $jenis, ?array $allowedKeys = null): array
{
    $map = presensi_data_jenis_map();
    $out = [];
    foreach ($jenis as $j) {
        $key = trim((string) $j);
        if ($key === '' || !isset($map[$key])) {
            continue;
        }
        if ($allowedKeys !== null && !in_array($key, $allowedKeys, true)) {
            continue;
        }
        $out[$key] = $key;
    }

    return array_values($out);
}

/**
 * @param list<string> $jenis
 * @return array<string, int>
 */
function presensi_data_count_by_range(PDO $pdo, string $mulai, string $selesai, array $jenis): array
{
    $jenis = presensi_data_normalize_jenis($jenis);
    $map = presensi_data_jenis_map();
    $counts = [];
    foreach ($jenis as $key) {
        $def = $map[$key];
        $table = $def['table'];
        $dateCol = $def['date_col'];
        if (!table_exists($pdo, $table)) {
            $counts[$key] = 0;
            continue;
        }
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $dateCol . '` BETWEEN :mulai AND :selesai'
        );
        $st->execute(['mulai' => $mulai, 'selesai' => $selesai]);
        $counts[$key] = (int) $st->fetchColumn();
    }

    return $counts;
}

/**
 * @param list<string> $jenis
 * @return list<array<string, mixed>>
 */
function presensi_data_fetch_rows(PDO $pdo, string $mulai, string $selesai, array $jenis): array
{
    $jenis = presensi_data_normalize_jenis($jenis);
    $rows = [];
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';

    if (in_array('santri', $jenis, true) && table_exists($pdo, 'presensi')) {
        ensure_presensi_jadwal_column($pdo);
        $sql = '
            SELECT "santri" AS jenis, p.id, p.tanggal_presensi AS tanggal, p.jam_presensi AS jam,
                   s.nis, s.' . $nameCol . ' AS nama, s.tingkatan,
                   COALESCE(k.nama_kegiatan, "") AS kegiatan, p.status_presensi AS status, COALESCE(p.catatan, "") AS catatan
            FROM presensi p
            INNER JOIN santri s ON s.id = p.santri_id
            LEFT JOIN kegiatan k ON k.id = p.kegiatan_id
            WHERE p.tanggal_presensi BETWEEN :mulai AND :selesai
            ORDER BY p.tanggal_presensi ASC, p.jam_presensi ASC, p.id ASC
        ';
        $st = $pdo->prepare($sql);
        $st->execute(['mulai' => $mulai, 'selesai' => $selesai]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $rows[] = $r;
        }
    }

    if (in_array('pembimbing', $jenis, true) && table_exists($pdo, 'presensi_pembimbing')) {
        $hasKg = column_exists($pdo, 'presensi_pembimbing', 'kegiatan_id');
        $joinKg = $hasKg ? 'LEFT JOIN kegiatan k ON k.id = pp.kegiatan_id' : '';
        $kgSel = $hasKg ? 'COALESCE(k.nama_kegiatan, "") AS kegiatan,' : '"" AS kegiatan,';
        $sql = '
            SELECT "pembimbing" AS jenis, pp.id, pp.tanggal, pp.jam,
                   pb.nip AS nis, pb.nama_pembimbing AS nama, "" AS tingkatan,
                   ' . $kgSel . ' pp.jenis_scan AS status, "" AS catatan
            FROM presensi_pembimbing pp
            INNER JOIN pembimbing pb ON pb.id = pp.pembimbing_id
            ' . $joinKg . '
            WHERE pp.tanggal BETWEEN :mulai AND :selesai
            ORDER BY pp.tanggal ASC, pp.jam ASC, pp.id ASC
        ';
        $st = $pdo->prepare($sql);
        $st->execute(['mulai' => $mulai, 'selesai' => $selesai]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $rows[] = $r;
        }
    }

    if (in_array('khusus', $jenis, true) && table_exists($pdo, 'presensi_kegiatan_khusus')) {
        kegiatan_khusus_ensure_schema($pdo);
        $sql = '
            SELECT "khusus" AS jenis, pk.id, pk.tanggal, pk.jam,
                   s.nis, s.' . $nameCol . ' AS nama, s.tingkatan,
                   COALESCE(kk.nama_kegiatan, "") AS kegiatan, "HADIR" AS status, "" AS catatan
            FROM presensi_kegiatan_khusus pk
            INNER JOIN santri s ON s.id = pk.santri_id
            INNER JOIN kegiatan_khusus kk ON kk.id = pk.kegiatan_khusus_id
            WHERE pk.tanggal BETWEEN :mulai AND :selesai
            ORDER BY pk.tanggal ASC, pk.jam ASC, pk.id ASC
        ';
        $st = $pdo->prepare($sql);
        $st->execute(['mulai' => $mulai, 'selesai' => $selesai]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $rows[] = $r;
        }
    }

    if (in_array('munawib', $jenis, true) && table_exists($pdo, 'presensi_munawib')) {
        munawib_ensure_schema($pdo);
        $hasKg = column_exists($pdo, 'presensi_munawib', 'kegiatan_id');
        $joinKg = $hasKg ? 'LEFT JOIN kegiatan k ON k.id = pm.kegiatan_id' : '';
        $kgSel = $hasKg ? 'COALESCE(k.nama_kegiatan, "") AS kegiatan,' : '"" AS kegiatan,';
        $sql = '
            SELECT "munawib" AS jenis, pm.id, pm.tanggal, pm.jam,
                   m.nip AS nis, m.nama AS nama, "" AS tingkatan,
                   ' . $kgSel . ' "HADIR" AS status, "" AS catatan
            FROM presensi_munawib pm
            INNER JOIN munawib m ON m.id = pm.munawib_id
            ' . $joinKg . '
            WHERE pm.tanggal BETWEEN :mulai AND :selesai
            ORDER BY pm.tanggal ASC, pm.jam ASC, pm.id ASC
        ';
        $st = $pdo->prepare($sql);
        $st->execute(['mulai' => $mulai, 'selesai' => $selesai]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $rows[] = $r;
        }
    }

    return $rows;
}

/** @param list<string> $jenis */
function presensi_data_stream_csv(PDO $pdo, string $mulai, string $selesai, array $jenis): void
{
    $rows = presensi_data_fetch_rows($pdo, $mulai, $selesai, $jenis);
    $fn = sprintf('presensi_%s_%s_%d.csv', $mulai, $selesai, time());
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    $out = fopen('php://output', 'w');
    if ($out === false) {
        return;
    }
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Jenis', 'ID', 'Tanggal', 'Jam', 'NIS/NIP', 'Nama', 'Tingkatan', 'Kegiatan', 'Status', 'Catatan'], ';');
    foreach ($rows as $r) {
        fputcsv($out, [
            (string) ($r['jenis'] ?? ''),
            (string) ($r['id'] ?? ''),
            (string) ($r['tanggal'] ?? ''),
            substr((string) ($r['jam'] ?? ''), 0, 8),
            (string) ($r['nis'] ?? ''),
            (string) ($r['nama'] ?? ''),
            (string) ($r['tingkatan'] ?? ''),
            (string) ($r['kegiatan'] ?? ''),
            (string) ($r['status'] ?? ''),
            (string) ($r['catatan'] ?? ''),
        ], ';');
    }
    fclose($out);
}

/**
 * @param list<string> $jenis
 * @return array{ok:bool, message:string, deleted:array<string,int>}
 */
function presensi_data_delete_by_range(PDO $pdo, string $mulai, string $selesai, array $jenis, int $userId): array
{
    $jenis = presensi_data_normalize_jenis($jenis);
    if ($jenis === []) {
        return ['ok' => false, 'message' => 'Pilih minimal satu jenis presensi.', 'deleted' => []];
    }

    $deleted = [];
    $pdo->beginTransaction();
    try {
        if (in_array('santri', $jenis, true) && table_exists($pdo, 'presensi')) {
            ensure_presensi_jadwal_column($pdo);
            $stIds = $pdo->prepare('SELECT id FROM presensi WHERE tanggal_presensi BETWEEN :mulai AND :selesai');
            $stIds->execute(['mulai' => $mulai, 'selesai' => $selesai]);
            $ids = array_map('intval', $stIds->fetchAll(PDO::FETCH_COLUMN) ?: []);
            $deleted['santri'] = presensi_hapus_by_ids($pdo, $ids);
        }

        foreach (['pembimbing' => 'presensi_pembimbing', 'khusus' => 'presensi_kegiatan_khusus', 'munawib' => 'presensi_munawib'] as $key => $table) {
            if (!in_array($key, $jenis, true) || !table_exists($pdo, $table)) {
                continue;
            }
            $dateCol = $key === 'santri' ? 'tanggal_presensi' : 'tanggal';
            if ($key !== 'santri') {
                $st = $pdo->prepare(
                    'DELETE FROM `' . $table . '` WHERE `' . $dateCol . '` BETWEEN :mulai AND :selesai'
                );
                $st->execute(['mulai' => $mulai, 'selesai' => $selesai]);
                $deleted[$key] = $st->rowCount();
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal menghapus: ' . $e->getMessage(), 'deleted' => []];
    }

    $total = array_sum($deleted);
    operasional_audit_log(
        $pdo,
        PRESENSI_DATA_MODUL_AUDIT,
        'DELETE',
        0,
        ['mulai' => $mulai, 'selesai' => $selesai, 'jenis' => $jenis, 'deleted' => $deleted],
        null,
        $userId,
        'Hapus data presensi rentang ' . $mulai . ' s/d ' . $selesai . ' (' . $total . ' baris)'
    );

    return [
        'ok' => true,
        'message' => $total . ' baris presensi dihapus.',
        'deleted' => $deleted,
    ];
}
