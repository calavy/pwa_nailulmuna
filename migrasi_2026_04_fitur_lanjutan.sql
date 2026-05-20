-- Jalankan di phpMyAdmin pada database yang dipakai aplikasi (sama dengan $dbName di config/database.php).
-- Urutan disarankan: schema.sql dulu (agar tabel santri ada), lalu file ini atau schema_presensi.sql.
-- Tanpa tabel santri, blok CREATE perizinan di bawah akan gagal (foreign key).

CREATE TABLE IF NOT EXISTS tingkatan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_tingkatan VARCHAR(80) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Versi perizinan minimal (sebelum kolom jam/jenis); ALTER di bawah menambahkan kolom jika DB lama.
CREATE TABLE IF NOT EXISTS perizinan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    santri_id INT NOT NULL,
    tanggal_mulai DATE NOT NULL,
    tanggal_selesai DATE NOT NULL,
    alasan TEXT NOT NULL,
    pemberi_izin VARCHAR(100) NOT NULL,
    penandatangan_pengasuh VARCHAR(100) NOT NULL,
    status_izin ENUM('IZIN','KEMBALI') NOT NULL DEFAULT 'IZIN',
    waktu_kembali DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
);

-- Satu ADD COLUMN per ALTER (lebih aman di MySQL/MariaDB lama).
-- Jika error "Duplicate column": kolom sudah ada (mis. dari schema_presensi.sql), lewati baris itu.
ALTER TABLE perizinan ADD COLUMN IF NOT EXISTS jenis_izin ENUM('SAKIT','KELUAR','PULANG') NOT NULL DEFAULT 'KELUAR';
ALTER TABLE perizinan ADD COLUMN IF NOT EXISTS jam_mulai TIME NULL;
ALTER TABLE perizinan ADD COLUMN IF NOT EXISTS jam_selesai TIME NULL;
ALTER TABLE perizinan ADD COLUMN IF NOT EXISTS durasi_jam DECIMAL(5,2) NULL;

CREATE TABLE IF NOT EXISTS pembimbing (
    id INT AUTO_INCREMENT PRIMARY KEY,
    qr VARCHAR(120) NULL,
    nip VARCHAR(40) NOT NULL UNIQUE,
    nama_pembimbing VARCHAR(120) NOT NULL,
    no_wa VARCHAR(30) NULL,
    is_aktif TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS presensi_pembimbing (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pembimbing_id INT NOT NULL,
    tanggal DATE NOT NULL,
    jam TIME NOT NULL,
    jenis_scan ENUM('DATANG','PULANG') NOT NULL DEFAULT 'DATANG',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pembimbing_id) REFERENCES pembimbing(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS perizinan_pembimbing (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pembimbing_id INT NOT NULL,
    jenis_izin ENUM('SAKIT','KELUAR','PULANG') NOT NULL DEFAULT 'KELUAR',
    tanggal_mulai DATE NOT NULL,
    tanggal_selesai DATE NOT NULL,
    jam_mulai TIME NULL,
    jam_selesai TIME NULL,
    durasi_jam DECIMAL(5,2) NULL,
    alasan TEXT NOT NULL,
    status_izin ENUM('IZIN','KEMBALI') NOT NULL DEFAULT 'IZIN',
    waktu_kembali DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pembimbing_id) REFERENCES pembimbing(id) ON DELETE CASCADE
);

ALTER TABLE jadwal_kegiatan
    ADD COLUMN IF NOT EXISTS pembimbing_id INT NULL;

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS role ENUM('admin','pengurus','petugas_absensi') NOT NULL DEFAULT 'pengurus';

INSERT INTO app_settings (setting_key, setting_value)
VALUES ('jenis_pendidikan', 'Pondok Pesantren / Pesantren Putra Putri')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

DELETE FROM app_settings WHERE setting_key = 'nama_ketertiban';
