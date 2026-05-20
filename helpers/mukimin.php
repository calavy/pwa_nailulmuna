<?php

declare(strict_types=1);

require_once __DIR__ . '/akademik.php';
require_once __DIR__ . '/santri_keluar.php';

function mukimin_count(PDO $pdo): int
{
    ensure_akademik_alumni_table($pdo);

    return (int) ($pdo->query('SELECT COUNT(*) FROM akademik_alumni')->fetchColumn() ?: 0);
}

/** @return list<string> */
function mukimin_distinct_keterangan(PDO $pdo): array
{
    ensure_akademik_alumni_table($pdo);
    $rows = $pdo->query(
        'SELECT DISTINCT keterangan AS v FROM akademik_alumni
         WHERE keterangan IS NOT NULL AND TRIM(keterangan) <> \'\'
         ORDER BY v ASC
         LIMIT 200'
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];

    return array_values(array_filter(array_map(static fn($v): string => trim((string) $v), $rows)));
}

function mukimin_parse_year_from_date(?string $date): ?int
{
    $date = trim((string) $date);
    if ($date !== '' && preg_match('/^(\d{4})/', $date, $m)) {
        return (int) $m[1];
    }

    return null;
}

/** @param array<string, mixed> $santri Row tabel santri */
function mukimin_build_keterangan_from_santri(array $santri): string
{
    $parts = [];
    require_once __DIR__ . '/santri_status.php';
    $st = santri_status_from_row($santri);
    $parts[] = santri_status_label($st);
    $kat = trim((string) ($santri['keluar_kategori'] ?? ''));
    if (santri_status_is_nonaktif($st) && $kat !== '') {
        $parts[] = keluar_kategori_label($kat);
    }
    $alasan = trim((string) ($santri['alasan_keluar'] ?? ''));
    if ($alasan !== '') {
        $parts[] = $alasan;
    }
    $tgl = trim((string) ($santri['tanggal_keluar'] ?? ''));
    if ($tgl !== '') {
        $parts[] = 'Tgl keluar: ' . $tgl;
    }

    return implode(' · ', $parts);
}

/**
 * Salin / perbarui data santri non aktif ke arsip mukimin (tabel akademik_alumni).
 *
 * @return int ID baris mukimin
 */
function mukimin_sync_from_santri(PDO $pdo, int $santriId): int
{
    ensure_akademik_alumni_table($pdo);
    $st = $pdo->prepare('SELECT * FROM santri WHERE id = :id LIMIT 1');
    $st->execute(['id' => $santriId]);
    $s = $st->fetch(PDO::FETCH_ASSOC);
    if (!$s) {
        return 0;
    }

    $nis = trim((string) ($s['nis'] ?? ''));
    $nama = trim((string) ($s['nama_santri'] ?? ''));
    if ($nis === '' || $nama === '') {
        return 0;
    }

    $keterangan = mukimin_build_keterangan_from_santri($s);
    $thMasuk = mukimin_parse_year_from_date(trim((string) ($s['tanggal_masuk'] ?? '')));
    $thKeluar = mukimin_parse_year_from_date(trim((string) ($s['tanggal_keluar'] ?? '')));

    $find = $pdo->prepare('SELECT id, keterangan FROM akademik_alumni WHERE nis = :nis LIMIT 1');
    $find->execute(['nis' => $nis]);
    $existing = $find->fetch(PDO::FETCH_ASSOC);

    $params = [
        'nis' => mb_substr($nis, 0, 32),
        'nama' => mb_substr($nama, 0, 200),
        'dusun' => mb_substr(trim((string) ($s['dusun'] ?? '')), 0, 120) ?: null,
        'rt_rw' => mb_substr(trim((string) ($s['rt_rw'] ?? '')), 0, 20) ?: null,
        'desa_kelurahan' => mb_substr(trim((string) ($s['desa_kelurahan'] ?? '')), 0, 120) ?: null,
        'kecamatan' => mb_substr(trim((string) ($s['kecamatan'] ?? '')), 0, 120) ?: null,
        'kabupaten' => mb_substr(trim((string) ($s['kabupaten'] ?? '')), 0, 120) ?: null,
        'propinsi' => mb_substr(trim((string) ($s['propinsi'] ?? '')), 0, 120) ?: null,
        'th_masuk' => $thMasuk,
        'th_keluar' => $thKeluar,
        'keterangan' => $keterangan !== '' ? $keterangan : null,
    ];

    if ($existing) {
        $existingKet = trim((string) ($existing['keterangan'] ?? ''));
        if ($existingKet !== '' && $keterangan !== '' && $existingKet !== $keterangan) {
            $params['keterangan'] = $existingKet;
        } elseif ($existingKet !== '' && $keterangan === '') {
            $params['keterangan'] = $existingKet;
        }
        $params['id'] = (int) $existing['id'];
        $pdo->prepare('
            UPDATE akademik_alumni SET
                nis = :nis, nama = :nama, dusun = :dusun, rt_rw = :rt_rw,
                desa_kelurahan = :desa_kelurahan, kecamatan = :kecamatan,
                kabupaten = :kabupaten, propinsi = :propinsi,
                th_masuk = :th_masuk, th_keluar = :th_keluar, keterangan = :keterangan
            WHERE id = :id
        ')->execute($params);

        return (int) $existing['id'];
    }

    $params['urutan'] = alumni_next_urutan($pdo);
    $pdo->prepare('
        INSERT INTO akademik_alumni (urutan, nis, nama, dusun, rt_rw, desa_kelurahan, kecamatan, kabupaten, propinsi, th_masuk, th_keluar, keterangan)
        VALUES (:urutan, :nis, :nama, :dusun, :rt_rw, :desa_kelurahan, :kecamatan, :kabupaten, :propinsi, :th_masuk, :th_keluar, :keterangan)
    ')->execute($params);

    return (int) $pdo->lastInsertId();
}
