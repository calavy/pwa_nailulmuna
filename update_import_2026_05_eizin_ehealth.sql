-- Jalankan file ini lewat phpMyAdmin > Import
-- Database target: pwa_nailulmuna

USE pwa_nailulmuna;

-- 1) Upgrade tabel perizinan (approval + QR + check-out/check-in + pelanggaran)
ALTER TABLE perizinan
    ADD COLUMN IF NOT EXISTS approval_status ENUM('PENDING','DISETUJUI','DITOLAK') NOT NULL DEFAULT 'PENDING',
    ADD COLUMN IF NOT EXISTS approved_by INT NULL,
    ADD COLUMN IF NOT EXISTS approved_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS rejected_reason VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS qr_token VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS waktu_keluar DATETIME NULL,
    ADD COLUMN IF NOT EXISTS grace_menit INT NOT NULL DEFAULT 15,
    ADD COLUMN IF NOT EXISTS poin_pelanggaran INT NOT NULL DEFAULT 0;

-- 2) Tabel E-Health
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

-- 3) Pastikan jadwal punya kolom pembimbing
ALTER TABLE jadwal_kegiatan
    ADD COLUMN IF NOT EXISTS pembimbing_id INT NULL;

-- 4) Tambahan setting aplikasi
INSERT INTO app_settings (setting_key, setting_value) VALUES
('batas_telat_menit', '15'),
('grace_period_menit', '15')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

