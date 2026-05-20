<?php

declare(strict_types=1);

function ensure_kelas_ruangan_table(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS kelas_ruangan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nama_ruangan VARCHAR(120) NOT NULL,
            urutan INT NOT NULL DEFAULT 0,
            keterangan VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_kelas_ruangan_nama (nama_ruangan)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
}

/** @return list<array{id:int,nama_ruangan:string,urutan:int,keterangan:?string}> */
function kelas_ruangan_list_all(PDO $pdo): array
{
    ensure_kelas_ruangan_table($pdo);

    return $pdo->query('SELECT id, nama_ruangan, urutan, keterangan FROM kelas_ruangan ORDER BY urutan ASC, nama_ruangan ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function kelas_ruangan_nama_by_id(PDO $pdo, int $id): string
{
    if ($id <= 0) {
        return '';
    }
    ensure_kelas_ruangan_table($pdo);
    $st = $pdo->prepare('SELECT nama_ruangan FROM kelas_ruangan WHERE id = :id LIMIT 1');
    $st->execute(['id' => $id]);
    $v = $st->fetchColumn();

    return $v !== false ? trim((string) $v) : '';
}
