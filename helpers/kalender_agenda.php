<?php

declare(strict_types=1);

function ensure_akademik_agenda_table(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS akademik_agenda (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tanggal DATE NOT NULL,
            jam_mulai TIME NULL,
            jam_selesai TIME NULL,
            judul VARCHAR(200) NOT NULL,
            jenis ENUM("acara","tugas") NOT NULL DEFAULT "acara",
            catatan TEXT NULL,
            untuk_peran VARCHAR(80) NULL COMMENT "admin,pengurus,pembimbing,semua",
            selesai TINYINT(1) NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_agenda_tanggal (tanggal),
            INDEX idx_agenda_jenis (jenis)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
}

/** @return list<array<string, mixed>> */
function akademik_agenda_for_range(PDO $pdo, string $start, string $end): array
{
    ensure_akademik_agenda_table($pdo);
    $st = $pdo->prepare('
        SELECT * FROM akademik_agenda
        WHERE tanggal BETWEEN :s AND :e
        ORDER BY tanggal ASC, COALESCE(jam_mulai, "00:00:00") ASC, id ASC
    ');
    $st->execute(['s' => $start, 'e' => $end]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<array<string, mixed>> */
function akademik_agenda_for_date(PDO $pdo, string $date): array
{
    return akademik_agenda_for_range($pdo, $date, $date);
}
