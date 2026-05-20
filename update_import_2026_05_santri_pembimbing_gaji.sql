-- Update skema: santri status keluar, kamar, izin tugas, gaji pembimbing

ALTER TABLE santri
    ADD COLUMN IF NOT EXISTS status_santri VARCHAR(20) NOT NULL DEFAULT 'AKTIF',
    ADD COLUMN IF NOT EXISTS alasan_keluar TEXT NULL,
    ADD COLUMN IF NOT EXISTS tanggal_keluar DATE NULL,
    ADD COLUMN IF NOT EXISTS nama_kamar VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS no_ranjang VARCHAR(30) NULL;

UPDATE santri
SET status_santri = IF(COALESCE(is_aktif, 1) = 1, 'AKTIF', 'NON_AKTIF')
WHERE status_santri IS NULL OR status_santri = '';

ALTER TABLE perizinan
    MODIFY COLUMN jenis_izin ENUM('SAKIT','KELUAR','TUGAS','PULANG') NOT NULL DEFAULT 'KELUAR';

UPDATE perizinan SET jenis_izin = 'TUGAS' WHERE jenis_izin = 'PULANG';

ALTER TABLE perizinan_pembimbing
    ADD COLUMN IF NOT EXISTS kegiatan_id INT NULL,
    MODIFY COLUMN jenis_izin ENUM('SAKIT','KELUAR','TUGAS','PULANG') NOT NULL DEFAULT 'KELUAR';

UPDATE perizinan_pembimbing SET jenis_izin = 'TUGAS' WHERE jenis_izin = 'PULANG';

CREATE TABLE IF NOT EXISTS keuangan_gaji_pembimbing (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pembimbing_id INT NOT NULL,
    periode_mode ENUM('MASEHI','HIJRIYAH') NOT NULL DEFAULT 'MASEHI',
    periode_label VARCHAR(30) NOT NULL,
    bulan TINYINT NOT NULL,
    tahun SMALLINT NOT NULL,
    total_jam DECIMAL(8,2) NOT NULL DEFAULT 0,
    tarif_per_jam DECIMAL(12,2) NOT NULL DEFAULT 0,
    total_bayar DECIMAL(12,2) NOT NULL DEFAULT 0,
    tanggal_bayar DATE NOT NULL,
    keterangan VARCHAR(255) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_gaji_pembimbing_periode (pembimbing_id, tahun, bulan)
);

ALTER TABLE keuangan_gaji_pembimbing
    ADD UNIQUE KEY uk_gaji_pembimbing_periode (pembimbing_id, periode_mode, tahun, bulan);
