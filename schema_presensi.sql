CREATE TABLE IF NOT EXISTS app_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL
);

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
    tempat VARCHAR(255) NULL,
    FOREIGN KEY (kegiatan_id) REFERENCES kegiatan(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS presensi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    santri_id INT NOT NULL,
    kegiatan_id INT NULL,
    tanggal_presensi DATE NOT NULL,
    jam_presensi TIME NOT NULL,
    status_presensi ENUM('HADIR','ALPA','IZIN','SAKIT') NOT NULL DEFAULT 'HADIR',
    kalender_hijriyah VARCHAR(20) NULL,
    catatan VARCHAR(255) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
);

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
    approval_status ENUM('PENDING','DISETUJUI','DITOLAK') NOT NULL DEFAULT 'PENDING',
    approved_by INT NULL,
    approved_at DATETIME NULL,
    rejected_reason VARCHAR(255) NULL,
    qr_token VARCHAR(120) NULL,
    waktu_keluar DATETIME NULL,
    grace_menit INT NOT NULL DEFAULT 15,
    poin_pelanggaran INT NOT NULL DEFAULT 0,
    waktu_kembali DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS tingkatan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_tingkatan VARCHAR(80) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

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

CREATE TABLE IF NOT EXISTS wa_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    target_phone VARCHAR(30) NOT NULL,
    message TEXT NOT NULL,
    response_text TEXT NULL,
    is_success TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

ALTER TABLE santri
    ADD COLUMN IF NOT EXISTS no_wa_wali VARCHAR(30) NULL,
    ADD COLUMN IF NOT EXISTS is_aktif TINYINT(1) NOT NULL DEFAULT 1,
    ADD COLUMN IF NOT EXISTS nik VARCHAR(40) NULL,
    ADD COLUMN IF NOT EXISTS tempat_lahir_kab VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS tanggal_lahir VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS bulan_lahir VARCHAR(20) NULL,
    ADD COLUMN IF NOT EXISTS tahun_lahir VARCHAR(10) NULL,
    ADD COLUMN IF NOT EXISTS jumlah_saudara VARCHAR(10) NULL,
    ADD COLUMN IF NOT EXISTS anak_ke VARCHAR(10) NULL,
    ADD COLUMN IF NOT EXISTS hobi VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS cita_cita VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS dusun VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS rt_rw VARCHAR(30) NULL,
    ADD COLUMN IF NOT EXISTS desa_kelurahan VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS kecamatan VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS kabupaten VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS propinsi VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS nama_ayah VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS pekerjaan_ayah VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS no_kontak_ayah VARCHAR(30) NULL,
    ADD COLUMN IF NOT EXISTS nama_ibu VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS pekerjaan_ibu VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS no_kontak_ibu VARCHAR(30) NULL,
    ADD COLUMN IF NOT EXISTS nama_kafil VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS status_kafil VARCHAR(80) NULL,
    ADD COLUMN IF NOT EXISTS pekerjaan_kafil VARCHAR(120) NULL,
    ADD COLUMN IF NOT EXISTS no_kontak_kafil VARCHAR(30) NULL,
    ADD COLUMN IF NOT EXISTS pendidikan_diniyyah_terakhir TEXT NULL,
    ADD COLUMN IF NOT EXISTS pendidikan_formal_terakhir TEXT NULL,
    ADD COLUMN IF NOT EXISTS kitab_yang_pernah_dikaji TEXT NULL,
    ADD COLUMN IF NOT EXISTS keluhan_sakit TEXT NULL,
    ADD COLUMN IF NOT EXISTS pengobatan TEXT NULL,
    ADD COLUMN IF NOT EXISTS tanggal_masuk DATE NULL,
    ADD COLUMN IF NOT EXISTS alasan_mondok TEXT NULL,
    ADD COLUMN IF NOT EXISTS atas_keinginan TEXT NULL,
    ADD COLUMN IF NOT EXISTS mengapa_nailul TEXT NULL;

INSERT INTO app_settings (setting_key, setting_value) VALUES
('nama_ponpes', 'Nama Pondok Pesantren'),
('jenis_pendidikan', 'Pondok Pesantren / Pesantren Putra Putri'),
('alamat_ponpes', 'Alamat Pondok Pesantren'),
('nama_pengasuh', 'Nama Pengasuh'),
('logo_path', ''),
('logo_url', ''),
('wa_gateway_url', ''),
('wa_gateway_token', ''),
('wa_sender', ''),
('wa_pengurus', ''),
('jam_kirim_wa_auto', ''),
('batas_alpa_notif', '3'),
('batas_telat_menit', '15'),
('grace_period_menit', '15'),
('kategori_baik_max', '1'),
('kategori_sedang_max', '3')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
