-- Import sekali jalan via phpMyAdmin
-- Target DB: pwa_nailulmuna

USE pwa_nailulmuna;

-- Pastikan tabel inti perizinan ada
CREATE TABLE IF NOT EXISTS perizinan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    santri_id INT NOT NULL,
    tanggal_mulai DATE NOT NULL,
    tanggal_selesai DATE NOT NULL,
    alasan TEXT NOT NULL,
    pemberi_izin VARCHAR(100) NOT NULL,
    penandatangan_pengasuh VARCHAR(100) NOT NULL,
    jenis_izin ENUM('SAKIT','KELUAR','PULANG') NOT NULL DEFAULT 'KELUAR',
    jam_mulai TIME NULL,
    jam_selesai TIME NULL,
    durasi_jam DECIMAL(5,2) NULL,
    status_izin ENUM('IZIN','KEMBALI') NOT NULL DEFAULT 'IZIN',
    waktu_kembali DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
);

-- Upgrade fitur E-Izin
ALTER TABLE perizinan
    ADD COLUMN IF NOT EXISTS approval_status ENUM('PENDING','DISETUJUI','DITOLAK') NOT NULL DEFAULT 'PENDING',
    ADD COLUMN IF NOT EXISTS approved_by INT NULL,
    ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS rejected_reason VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS qr_token VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS waktu_keluar DATETIME NULL,
    ADD COLUMN IF NOT EXISTS grace_menit INT NOT NULL DEFAULT 15,
    ADD COLUMN IF NOT EXISTS poin_pelanggaran INT NOT NULL DEFAULT 0;

-- Tabel E-Health
CREATE TABLE IF NOT EXISTS ehealth_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    santri_id INT NOT NULL,
    gejala TEXT NOT NULL,
    suhu_tubuh DECIMAL(4,1) NULL,
    tindakan TEXT NULL,
    status_kesehatan ENUM('RAWAT_PONDOK','DIRUJUK_RS','ISOLASI','SELESAI') NOT NULL DEFAULT 'RAWAT_PONDOK',
    notifikasi_wali TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
);

-- Pastikan tabel app_settings ada
CREATE TABLE IF NOT EXISTS app_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL
);

-- Setting default
INSERT INTO app_settings (setting_key, setting_value) VALUES
('batas_telat_menit', '15'),
('grace_period_menit', '15')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- Pastikan jadwal punya kolom pembimbing
CREATE TABLE IF NOT EXISTS kegiatan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_kegiatan VARCHAR(120) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS jadwal_kegiatan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kegiatan_id INT NOT NULL,
    tingkatan VARCHAR(50) NOT NULL,
    hari_ke TINYINT NOT NULL COMMENT '1=Senin ... 7=Minggu',
    jam_mulai TIME NOT NULL,
    jam_selesai TIME NOT NULL,
    FOREIGN KEY (kegiatan_id) REFERENCES kegiatan(id) ON DELETE CASCADE
);

ALTER TABLE jadwal_kegiatan
    ADD COLUMN IF NOT EXISTS pembimbing_id INT NULL;

