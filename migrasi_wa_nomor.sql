-- Migrasi: tabel buku nomor WhatsApp penerima notifikasi
-- Jalankan sekali di phpMyAdmin atau mysql CLI

CREATE TABLE IF NOT EXISTS wa_nomor (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(120) NOT NULL,
    no_wa VARCHAR(40) NOT NULL,
    peran VARCHAR(500) NOT NULL DEFAULT '',
    catatan TEXT NULL,
    is_aktif TINYINT(1) NOT NULL DEFAULT 1,
    urutan INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_wa_nomor_aktif (is_aktif),
    INDEX idx_wa_nomor_urutan (urutan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
