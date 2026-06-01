<?php

declare(strict_types=1);

require_once __DIR__ . '/pkpps.php';
require_once __DIR__ . '/santri_operasional.php';

const PEMBIMBING_PKPPS_LABEL_PREFIX = 'PKPPS · ';

function pembimbing_pkpps_label(string $namaTingkatan): string
{
    return PEMBIMBING_PKPPS_LABEL_PREFIX . trim($namaTingkatan);
}

function pembimbing_pkpps_is_label(string $label): bool
{
    return str_starts_with(trim($label), PEMBIMBING_PKPPS_LABEL_PREFIX);
}

function pembimbing_pkpps_id_from_label(string $label, PDO $pdo, int $pembimbingId): int
{
    if (!pembimbing_pkpps_is_label($label) || $pembimbingId <= 0) {
        return 0;
    }
    $nama = trim(substr(trim($label), strlen(PEMBIMBING_PKPPS_LABEL_PREFIX)));
    if ($nama === '') {
        return 0;
    }
    foreach (pembimbing_pkpps_tingkatan_map($pdo, $pembimbingId) as $id => $nm) {
        if (strcasecmp($nm, $nama) === 0) {
            return (int) $id;
        }
    }

    return 0;
}

/** @return array<int, string> map id => nama_tingkatan */
function pembimbing_pkpps_tingkatan_map(PDO $pdo, int $pembimbingId): array
{
    if ($pembimbingId <= 0 || !table_exists($pdo, 'pkpps_jadwal')) {
        return [];
    }
    pkpps_ensure_schema($pdo);
    $st = $pdo->prepare('
        SELECT DISTINCT t.id, t.nama_tingkatan
        FROM pkpps_jadwal j
        INNER JOIN pkpps_tingkatan t ON t.id = j.pkpps_tingkatan_id
        WHERE j.pembimbing_id = :pid AND j.is_aktif = 1 AND t.is_aktif = 1
        ORDER BY t.urutan ASC, t.nama_tingkatan ASC
    ');
    $st->execute(['pid' => $pembimbingId]);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $map[$id] = (string) ($row['nama_tingkatan'] ?? '');
        }
    }

    return $map;
}

/** @return list<int> */
function pembimbing_pkpps_tingkatan_ids(PDO $pdo, int $pembimbingId): array
{
    return array_keys(pembimbing_pkpps_tingkatan_map($pdo, $pembimbingId));
}

/** @return list<string> label untuk filter dashboard */
function pembimbing_pkpps_tingkatan_labels(PDO $pdo, int $pembimbingId): array
{
    $out = [];
    foreach (pembimbing_pkpps_tingkatan_map($pdo, $pembimbingId) as $nama) {
        if (trim($nama) !== '') {
            $out[] = pembimbing_pkpps_label($nama);
        }
    }

    return $out;
}

function pembimbing_pkpps_has_jadwal(PDO $pdo, int $pembimbingId): bool
{
    return pembimbing_pkpps_tingkatan_ids($pdo, $pembimbingId) !== [];
}

function pembimbing_pkpps_can_access_tingkatan(PDO $pdo, int $pembimbingId, int $tingkatanId): bool
{
    if ($pembimbingId <= 0 || $tingkatanId <= 0) {
        return false;
    }

    return isset(pembimbing_pkpps_tingkatan_map($pdo, $pembimbingId)[$tingkatanId]);
}

function pembimbing_pkpps_santri_in_scope(PDO $pdo, int $santriId, int $pembimbingId): bool
{
    if ($santriId <= 0 || $pembimbingId <= 0 || !table_exists($pdo, 'pkpps_santri')) {
        return false;
    }
    $allowed = pembimbing_pkpps_tingkatan_ids($pdo, $pembimbingId);
    if ($allowed === []) {
        return false;
    }
    $ph = implode(',', array_fill(0, count($allowed), '?'));
    $st = $pdo->prepare('
        SELECT 1 FROM pkpps_santri ps
        WHERE ps.santri_id = ? AND ps.is_aktif = 1 AND ps.pkpps_tingkatan_id IN (' . $ph . ')
        LIMIT 1
    ');
    $st->execute(array_merge([$santriId], $allowed));

    return (bool) $st->fetchColumn();
}

/**
 * @param list<int> $tingkatanIds kosong = semua tingkatan pembimbing
 * @return list<array<string, mixed>>
 */
function pembimbing_pkpps_santri_list(PDO $pdo, int $pembimbingId, array $tingkatanIds = [], int $limit = 500): array
{
    if ($pembimbingId <= 0 || !table_exists($pdo, 'pkpps_santri')) {
        return [];
    }
    $allowed = pembimbing_pkpps_tingkatan_ids($pdo, $pembimbingId);
    if ($allowed === []) {
        return [];
    }
    if ($tingkatanIds !== []) {
        $tingkatanIds = array_values(array_intersect($tingkatanIds, $allowed));
        if ($tingkatanIds === []) {
            return [];
        }
    } else {
        $tingkatanIds = $allowed;
    }
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $aktifSql = santri_sql_aktif_only('s');
    $ph = implode(',', array_fill(0, count($tingkatanIds), '?'));
    $limit = max(1, min(1000, $limit));
    $st = $pdo->prepare('
        SELECT ps.id AS pkpps_santri_id, ps.santri_id, ps.tahun_masehi, ps.is_aktif, ps.catatan,
               s.' . $nameCol . ' AS nama_santri, s.nis, s.qr, s.tingkatan AS tingkatan_kajian,
               t.id AS tingkatan_id, t.nama_tingkatan
        FROM pkpps_santri ps
        INNER JOIN santri s ON s.id = ps.santri_id AND ' . $aktifSql . '
        INNER JOIN pkpps_tingkatan t ON t.id = ps.pkpps_tingkatan_id
        WHERE ps.pkpps_tingkatan_id IN (' . $ph . ')
        ORDER BY t.urutan ASC, s.' . $nameCol . ' ASC
        LIMIT ' . $limit . '
    ');
    $st->execute($tingkatanIds);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$row) {
        $row['tingkatan'] = pembimbing_pkpps_label((string) ($row['nama_tingkatan'] ?? ''));
    }
    unset($row);

    return $rows;
}

/**
 * @param list<string> $labels label PKPPS · …
 * @return array<string, array{total:int,putra:int,putri:int}>
 */
function pembimbing_pkpps_jumlah_santri_map(PDO $pdo, array $labels): array
{
    if ($labels === [] || !table_exists($pdo, 'pkpps_santri')) {
        return [];
    }
    pkpps_ensure_schema($pdo);
    $ids = [];
    foreach ($labels as $lbl) {
        if (!pembimbing_pkpps_is_label($lbl)) {
            continue;
        }
        $nama = trim(substr(trim($lbl), strlen(PEMBIMBING_PKPPS_LABEL_PREFIX)));
        if ($nama === '') {
            continue;
        }
        $stT = $pdo->prepare('SELECT id FROM pkpps_tingkatan WHERE nama_tingkatan = :n LIMIT 1');
        $stT->execute(['n' => $nama]);
        $tid = (int) ($stT->fetchColumn() ?: 0);
        if ($tid > 0) {
            $ids[$tid] = $lbl;
        }
    }
    if ($ids === []) {
        return [];
    }
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $aktifSql = santri_sql_aktif_only('s');
    $hasJk = column_exists($pdo, 'santri', 'jenis_kelamin');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare('
        SELECT ps.pkpps_tingkatan_id AS tid,
               COUNT(*) AS total'
        . ($hasJk
            ? ', SUM(CASE WHEN TRIM(s.jenis_kelamin) = "Laki-laki" THEN 1 ELSE 0 END) AS putra'
            . ', SUM(CASE WHEN TRIM(s.jenis_kelamin) = "Perempuan" THEN 1 ELSE 0 END) AS putri'
            : ', 0 AS putra, 0 AS putri')
        . ' FROM pkpps_santri ps
        INNER JOIN santri s ON s.id = ps.santri_id AND ' . $aktifSql . '
        WHERE ps.is_aktif = 1 AND ps.pkpps_tingkatan_id IN (' . $ph . ')
        GROUP BY ps.pkpps_tingkatan_id
    ');
    $st->execute(array_keys($ids));
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $tid = (int) ($row['tid'] ?? 0);
        $lbl = $ids[$tid] ?? '';
        if ($lbl === '') {
            continue;
        }
        $out[$lbl] = [
            'total' => (int) ($row['total'] ?? 0),
            'putra' => (int) ($row['putra'] ?? 0),
            'putri' => (int) ($row['putri'] ?? 0),
        ];
    }
    foreach ($ids as $lbl) {
        if (!isset($out[$lbl])) {
            $out[$lbl] = ['total' => 0, 'putra' => 0, 'putri' => 0];
        }
    }

    return $out;
}

/** @return list<array{id:int,nama_kegiatan:string}> */
function pembimbing_pkpps_kegiatan_dari_jadwal(PDO $pdo, int $pembimbingId): array
{
    if ($pembimbingId <= 0 || !table_exists($pdo, 'pkpps_jadwal') || !table_exists($pdo, 'kegiatan')) {
        return [];
    }
    pkpps_ensure_schema($pdo);
    $st = $pdo->prepare('
        SELECT DISTINCT k.id, k.nama_kegiatan
        FROM pkpps_jadwal j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        WHERE j.pembimbing_id = :pid AND j.is_aktif = 1 AND COALESCE(k.is_active, 1) = 1
        ORDER BY k.nama_kegiatan ASC
    ');
    $st->execute(['pid' => $pembimbingId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $out[] = ['id' => $id, 'nama_kegiatan' => (string) ($row['nama_kegiatan'] ?? '')];
        }
    }

    return $out;
}

/**
 * @return array{ok:bool,message:string}
 */
function pembimbing_pkpps_santri_simpan(
    PDO $pdo,
    int $pembimbingId,
    int $pkppsSantriId,
    int $tingkatanId,
    ?int $tahunMasehi,
    string $catatan,
    int $isAktif
): array {
    if ($pkppsSantriId <= 0 || !pembimbing_pkpps_can_access_tingkatan($pdo, $pembimbingId, $tingkatanId)) {
        return ['ok' => false, 'message' => 'Data tidak valid atau di luar wewenang Anda.'];
    }
    $st = $pdo->prepare('SELECT id FROM pkpps_santri WHERE id = :id LIMIT 1');
    $st->execute(['id' => $pkppsSantriId]);
    if (!$st->fetchColumn()) {
        return ['ok' => false, 'message' => 'Santri PKPPS tidak ditemukan.'];
    }
    $pdo->prepare('
        UPDATE pkpps_santri
        SET pkpps_tingkatan_id = :tid, tahun_masehi = :th, catatan = :cat, is_aktif = :aktif
        WHERE id = :id
    ')->execute([
        'tid' => $tingkatanId,
        'th' => $tahunMasehi !== null && $tahunMasehi > 0 ? $tahunMasehi : null,
        'cat' => mb_substr(trim($catatan), 0, 255),
        'aktif' => $isAktif === 1 ? 1 : 0,
        'id' => $pkppsSantriId,
    ]);

    return ['ok' => true, 'message' => 'Data santri PKPPS diperbarui.'];
}
