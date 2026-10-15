<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_impor_ekspor.php';

function santri_nis_canonical_key(string $raw): string
{
    return keuangan_impor_ekspor_normalize_nis(trim($raw));
}

function santri_nis_canonical_display(string $raw): string
{
    $key = santri_nis_canonical_key($raw);

    return $key !== '' ? $key : trim($raw);
}

/**
 * @return array{id:int,nis:string,nama_santri:string,is_aktif:int}|null
 */
function santri_nis_find_conflict(PDO $pdo, string $rawNis, int $excludeSantriId = 0): ?array
{
    if (!table_exists($pdo, 'santri')) {
        return null;
    }
    $key = santri_nis_canonical_key($rawNis);
    if ($key === '') {
        return null;
    }

    $rows = $pdo->query('SELECT id, nis, nama_santri, COALESCE(is_aktif, 1) AS is_aktif FROM santri')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || ($excludeSantriId > 0 && $id === $excludeSantriId)) {
            continue;
        }
        $rowKey = santri_nis_canonical_key((string) ($row['nis'] ?? ''));
        if ($rowKey !== '' && $rowKey === $key) {
            return [
                'id' => $id,
                'nis' => (string) ($row['nis'] ?? ''),
                'nama_santri' => (string) ($row['nama_santri'] ?? ''),
                'is_aktif' => (int) ($row['is_aktif'] ?? 1),
            ];
        }
    }

    if (ctype_digit($key)) {
        $st = $pdo->prepare(
            "SELECT id, nis, nama_santri, COALESCE(is_aktif, 1) AS is_aktif FROM santri
             WHERE id <> :ex AND (
                (nis REGEXP '^[0-9]+(\\\\.[0-9]+)?$' AND CAST(nis AS DECIMAL(20, 4)) = :num)
             )
             LIMIT 1"
        );
        $st->execute(['ex' => max(0, $excludeSantriId), 'num' => (float) $key]);
        $hit = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($hit)) {
            return [
                'id' => (int) ($hit['id'] ?? 0),
                'nis' => (string) ($hit['nis'] ?? ''),
                'nama_santri' => (string) ($hit['nama_santri'] ?? ''),
                'is_aktif' => (int) ($hit['is_aktif'] ?? 1),
            ];
        }
    }

    return null;
}

/**
 * @return array<string, list<array{id:int,nis:string,nama_santri:string,is_aktif:int}>>
 */
function santri_nis_duplicate_groups(PDO $pdo): array
{
    if (!table_exists($pdo, 'santri')) {
        return [];
    }
    $rows = $pdo->query('SELECT id, nis, nama_santri, COALESCE(is_aktif, 1) AS is_aktif FROM santri ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byKey = [];
    foreach ($rows as $row) {
        $key = santri_nis_canonical_key((string) ($row['nis'] ?? ''));
        if ($key === '') {
            continue;
        }
        $byKey[$key][] = [
            'id' => (int) ($row['id'] ?? 0),
            'nis' => (string) ($row['nis'] ?? ''),
            'nama_santri' => (string) ($row['nama_santri'] ?? ''),
            'is_aktif' => (int) ($row['is_aktif'] ?? 1),
        ];
    }
    $out = [];
    foreach ($byKey as $key => $group) {
        if (count($group) >= 2) {
            $out[$key] = $group;
        }
    }

    return $out;
}

/** @return array<int, true> santri_id yang termasuk grup duplikat NIS */
function santri_nis_duplicate_id_map(PDO $pdo): array
{
    $map = [];
    foreach (santri_nis_duplicate_groups($pdo) as $group) {
        foreach ($group as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $map[$id] = true;
            }
        }
    }

    return $map;
}

function santri_nis_validate_unique(PDO $pdo, string $rawNis, int $excludeId = 0): ?string
{
    $key = santri_nis_canonical_key($rawNis);
    if ($key === '') {
        return 'NIS wajib diisi.';
    }
    $conflict = santri_nis_find_conflict($pdo, $rawNis, $excludeId);
    if ($conflict === null) {
        return null;
    }
    $nama = trim((string) ($conflict['nama_santri'] ?? '-'));
    if ($nama === '') {
        $nama = '-';
    }

    return 'NIS sudah dipakai santri ' . $nama
        . ' (ID ' . (int) ($conflict['id'] ?? 0)
        . ', NIS tercatat: ' . (string) ($conflict['nis'] ?? $key)
        . '). Perbaiki data induk, jangan buat baris santri baru.';
}

/**
 * Konflik NIS di tabel akademik_alumni (mukimin).
 *
 * @return array{id:int,nis:string,nama:string}|null
 */
function santri_nis_find_conflict_alumni(PDO $pdo, string $rawNis, int $excludeAlumniId = 0): ?array
{
    if (!table_exists($pdo, 'akademik_alumni')) {
        return null;
    }
    $key = santri_nis_canonical_key($rawNis);
    if ($key === '') {
        return null;
    }
    $rows = $pdo->query('SELECT id, nis, nama FROM akademik_alumni')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0 || ($excludeAlumniId > 0 && $id === $excludeAlumniId)) {
            continue;
        }
        if (santri_nis_canonical_key((string) ($row['nis'] ?? '')) === $key) {
            return [
                'id' => $id,
                'nis' => (string) ($row['nis'] ?? ''),
                'nama' => (string) ($row['nama'] ?? ''),
            ];
        }
    }

    return null;
}

function santri_nis_validate_unique_alumni(PDO $pdo, string $rawNis, int $excludeAlumniId = 0): ?string
{
    $key = santri_nis_canonical_key($rawNis);
    if ($key === '') {
        return 'NIS wajib diisi.';
    }
    $conflict = santri_nis_find_conflict_alumni($pdo, $rawNis, $excludeAlumniId);
    if ($conflict !== null) {
        return 'NIS sudah dipakai mukimin ' . (string) ($conflict['nama'] ?? '-')
            . ' (ID ' . (int) ($conflict['id'] ?? 0) . ').';
    }

    return null;
}
