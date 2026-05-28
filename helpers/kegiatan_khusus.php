<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

function kegiatan_khusus_ensure_schema(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS kegiatan_khusus (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nama_kegiatan VARCHAR(160) NOT NULL,
            kategori_kegiatan VARCHAR(20) NOT NULL DEFAULT "TAALIM",
            tingkatan VARCHAR(120) NOT NULL DEFAULT "Semua Tingkatan",
            tanggal DATE NOT NULL,
            jam_mulai TIME NOT NULL,
            jam_selesai TIME NOT NULL,
            tempat VARCHAR(190) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_khusus_tanggal (tanggal),
            INDEX idx_khusus_aktif (is_active, tanggal)
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS presensi_kegiatan_khusus (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kegiatan_khusus_id INT NOT NULL,
            santri_id INT NOT NULL,
            tanggal DATE NOT NULL,
            jam TIME NOT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_khusus_santri_hari (kegiatan_khusus_id, santri_id, tanggal),
            CONSTRAINT fk_pk_khusus FOREIGN KEY (kegiatan_khusus_id) REFERENCES kegiatan_khusus(id) ON DELETE CASCADE
        )
    ');
}

function kegiatan_khusus_find_active_for_tingkatan(PDO $pdo, string $tanggal, string $jam, string $tingkatan): ?array
{
    kegiatan_khusus_ensure_schema($pdo);
    $modeLibur = akademik_libur_presensi_mode_aktif_di_tanggal($pdo, $tanggal);
    $kategoriFilter = $modeLibur !== null
        ? akademik_libur_presensi_filter_sql_by_mode($modeLibur, 'COALESCE(kategori_kegiatan, "TAALIM")')
        : '';
    $st = $pdo->prepare('
        SELECT *
        FROM kegiatan_khusus
        WHERE is_active = 1
          AND tanggal = :tgl
          AND :jam BETWEEN jam_mulai AND jam_selesai
          AND (tingkatan = "Semua Tingkatan" OR tingkatan = :tingkatan)
          ' . $kategoriFilter . '
        ORDER BY jam_mulai ASC, id ASC
        LIMIT 1
    ');
    $st->execute(['tgl' => $tanggal, 'jam' => $jam, 'tingkatan' => $tingkatan]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

