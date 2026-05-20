USE pwa_nailulmuna;

CREATE TABLE IF NOT EXISTS point_followups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    santri_id INT NOT NULL,
    periode_bulan TINYINT NOT NULL,
    periode_tahun SMALLINT NOT NULL,
    total_poin INT NOT NULL DEFAULT 0,
    tindakan VARCHAR(120) NOT NULL,
    durasi_keterangan VARCHAR(120) NULL,
    keterangan TEXT NULL,
    status_tindak ENUM('BELUM','PROSES','SELESAI') NOT NULL DEFAULT 'BELUM',
    bukti_tindak TEXT NULL,
    handled_by_user_id INT NULL,
    handled_by_nama VARCHAR(120) NOT NULL,
    tanggal_tindak DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_followup_periode (periode_tahun, periode_bulan),
    INDEX idx_followup_santri (santri_id),
    CONSTRAINT fk_point_followups_santri FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
);

ALTER TABLE point_followups
    ADD COLUMN IF NOT EXISTS status_tindak ENUM('BELUM','PROSES','SELESAI') NOT NULL DEFAULT 'BELUM',
    ADD COLUMN IF NOT EXISTS bukti_tindak TEXT NULL;
