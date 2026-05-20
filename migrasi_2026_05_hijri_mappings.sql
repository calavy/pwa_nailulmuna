-- Kalender Hijriyah kustom (database-driven, hasil sidang isbat / koreksi admin).
-- Jalankan sekali di phpMyAdmin pada database pwa_nailulmuna.

CREATE TABLE IF NOT EXISTS hijri_mappings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nama_bulan VARCHAR(40) NOT NULL COMMENT 'Contoh: Ramadan, Syawal',
    tahun_hijriah SMALLINT UNSIGNED NOT NULL COMMENT 'Contoh: 1447',
    tanggal_masehi_awal_bulan DATE NOT NULL COMMENT 'Tanggal 1 Masehi untuk bulan Hijriyah ini',
    total_hari TINYINT UNSIGNED NOT NULL DEFAULT 30 COMMENT '29 atau 30 hari',
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_hijri_tahun_bulan (tahun_hijriah, nama_bulan),
    KEY idx_hijri_awal_masehi (tanggal_masehi_awal_bulan),
    KEY idx_hijri_tahun (tahun_hijriah)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
