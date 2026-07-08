-- Modul PKPPS: tingkatan, santri, jadwal
-- Tabel juga dibuat otomatis saat pertama kali membuka halaman PKPPS (helpers/pkpps.php).

CREATE TABLE IF NOT EXISTS pkpps_tingkatan (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    urutan SMALLINT UNSIGNED NOT NULL,
    kelas_keuangan_id INT UNSIGNED NULL,
    sub_level TINYINT UNSIGNED NOT NULL DEFAULT 0,
    nama_tingkatan VARCHAR(80) NOT NULL,
    is_aktif TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_pkpps_tingkatan_urutan (urutan),
    UNIQUE KEY uniq_pkpps_tingkatan_nama (nama_tingkatan),
    KEY idx_pkpps_kelas_keu (kelas_keuangan_id, sub_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE pkpps_tingkatan ADD COLUMN IF NOT EXISTS kelas_keuangan_id INT UNSIGNED NULL AFTER urutan;
ALTER TABLE pkpps_tingkatan ADD COLUMN IF NOT EXISTS sub_level TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER kelas_keuangan_id;

CREATE TABLE IF NOT EXISTS pkpps_santri (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    santri_id INT UNSIGNED NOT NULL,
    pkpps_tingkatan_id INT UNSIGNED NOT NULL,
    tahun_masehi SMALLINT UNSIGNED NULL,
    is_aktif TINYINT(1) NOT NULL DEFAULT 1,
    catatan VARCHAR(255) NOT NULL DEFAULT '',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_pkpps_santri (santri_id),
    KEY idx_pkpps_tingkatan (pkpps_tingkatan_id, is_aktif)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pkpps_jadwal (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pkpps_tingkatan_id INT UNSIGNED NOT NULL,
    kegiatan_id INT UNSIGNED NOT NULL,
    hari_ke TINYINT NOT NULL DEFAULT 0,
    jam_mulai TIME NOT NULL,
    jam_selesai TIME NOT NULL,
    pembimbing_id INT UNSIGNED NULL,
    tempat VARCHAR(255) NULL,
    is_aktif TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pkpps_jadwal_tingkatan (pkpps_tingkatan_id, hari_ke)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
