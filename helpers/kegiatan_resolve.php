<?php

declare(strict_types=1);

require_once __DIR__ . '/kegiatan_kategori.php';

/**
 * Cari kegiatan by nama exact match, atau buat baru jika belum ada.
 *
 * @return array{id:int, created:bool, kategori:string, nama:string}
 */
function kegiatan_resolve_or_create(PDO $pdo, string $nama, string $kategori = 'TAALIM', bool $updateKategoriOnExisting = true): array
{
    ensure_kegiatan_kategori_column($pdo);
    $nama = trim($nama);
    $kategori = kegiatan_kategori_normalize($kategori);
    if ($nama === '') {
        return ['id' => 0, 'created' => false, 'kategori' => $kategori, 'nama' => ''];
    }

    $st = $pdo->prepare('SELECT id, COALESCE(kategori_kegiatan, "TAALIM") AS kategori_kegiatan FROM kegiatan WHERE nama_kegiatan = :n LIMIT 1');
    $st->execute(['n' => $nama]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        $id = (int) ($row['id'] ?? 0);
        $existingKat = kegiatan_kategori_normalize((string) ($row['kategori_kegiatan'] ?? 'TAALIM'));
        if ($updateKategoriOnExisting && $id > 0 && $existingKat !== $kategori) {
            $pdo->prepare('UPDATE kegiatan SET kategori_kegiatan = :kat WHERE id = :id')
                ->execute(['kat' => $kategori, 'id' => $id]);
            $existingKat = $kategori;
        }

        return ['id' => $id, 'created' => false, 'kategori' => $existingKat, 'nama' => $nama];
    }

    $pdo->prepare('INSERT INTO kegiatan (nama_kegiatan, kategori_kegiatan, is_active) VALUES (:n, :kat, 1)')
        ->execute(['n' => $nama, 'kat' => $kategori]);
    $newId = (int) $pdo->lastInsertId();

    return ['id' => $newId, 'created' => true, 'kategori' => $kategori, 'nama' => $nama];
}

/**
 * Resolve kegiatan PKPPS/import dengan kategori bebas (mis. PKPPS).
 *
 * @return array{id:int, created:bool, kategori:string, nama:string}
 */
function kegiatan_resolve_or_create_raw_kategori(PDO $pdo, string $nama, string $kategori, bool $updateKategoriOnExisting = true): array
{
    ensure_kegiatan_kategori_column($pdo);
    $nama = trim($nama);
    $kategori = trim($kategori) !== '' ? trim($kategori) : 'TAALIM';
    if ($nama === '') {
        return ['id' => 0, 'created' => false, 'kategori' => $kategori, 'nama' => ''];
    }

    $st = $pdo->prepare('SELECT id, COALESCE(kategori_kegiatan, "TAALIM") AS kategori_kegiatan FROM kegiatan WHERE nama_kegiatan = :n LIMIT 1');
    $st->execute(['n' => $nama]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        $id = (int) ($row['id'] ?? 0);
        $existingKat = (string) ($row['kategori_kegiatan'] ?? 'TAALIM');
        if ($updateKategoriOnExisting && $id > 0 && $existingKat !== $kategori) {
            $pdo->prepare('UPDATE kegiatan SET kategori_kegiatan = :kat WHERE id = :id')
                ->execute(['kat' => $kategori, 'id' => $id]);
            $existingKat = $kategori;
        }

        return ['id' => $id, 'created' => false, 'kategori' => $existingKat, 'nama' => $nama];
    }

    $pdo->prepare('INSERT INTO kegiatan (nama_kegiatan, kategori_kegiatan, is_active) VALUES (:n, :kat, 1)')
        ->execute(['n' => $nama, 'kat' => $kategori]);
    $newId = (int) $pdo->lastInsertId();

    return ['id' => $newId, 'created' => true, 'kategori' => $kategori, 'nama' => $nama];
}

/**
 * Ambil kegiatan_id dari POST: prioritaskan nama_kegiatan, fallback kegiatan_id.
 *
 * @param array<string,mixed> $post
 * @return array{id:int, created:bool, error:?string}
 */
function kegiatan_id_from_request(PDO $pdo, array $post, ?int $fallbackId = null): array
{
    $nama = trim((string) ($post['nama_kegiatan'] ?? ''));
    if ($nama !== '') {
        $kat = kegiatan_kategori_normalize((string) ($post['kategori_kegiatan'] ?? 'TAALIM'));
        $res = kegiatan_resolve_or_create($pdo, $nama, $kat, false);
        if ($res['id'] <= 0) {
            return ['id' => 0, 'created' => false, 'error' => 'Nama kegiatan wajib diisi.'];
        }

        return ['id' => $res['id'], 'created' => $res['created'], 'error' => null];
    }

    $id = (int) ($post['kegiatan_id'] ?? 0);
    if ($id <= 0 && $fallbackId !== null && $fallbackId > 0) {
        $id = $fallbackId;
    }
    if ($id <= 0) {
        return ['id' => 0, 'created' => false, 'error' => 'Nama kegiatan wajib diisi.'];
    }

    return ['id' => $id, 'created' => false, 'error' => null];
}

/**
 * @param list<array<string,mixed>> $kegiatanRows
 * @return array<string, string> nama => kategori
 */
function kegiatan_nama_kategori_map(array $kegiatanRows): array
{
    $map = [];
    foreach ($kegiatanRows as $row) {
        $nama = trim((string) ($row['nama_kegiatan'] ?? ''));
        if ($nama === '') {
            continue;
        }
        $map[$nama] = kegiatan_kategori_normalize((string) ($row['kategori_kegiatan'] ?? 'TAALIM'));
    }

    return $map;
}
