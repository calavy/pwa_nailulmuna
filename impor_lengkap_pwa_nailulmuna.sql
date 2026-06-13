-- =============================================================================
-- impor_lengkap_pwa_nailulmuna.sql
-- Satu berkas untuk phpMyAdmin: tab SQL (tempel) atau tab Impor.
-- Menyesuaikan struktur DB dengan proyek ini; database: pwa_nailulmuna
-- =============================================================================
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS u700125577_pwanailulmuna;
USE u700125577_pwanailulmuna;

-- --------------------------------------------------------------------------
-- Bagian 1: inti (setara schema.sql)
-- --------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS kelas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_kelas VARCHAR(100) NOT NULL
);

CREATE TABLE IF NOT EXISTS kamar (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_kamar VARCHAR(100) NOT NULL,
    kapasitas INT NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS santri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nis VARCHAR(30) NOT NULL UNIQUE,
    nama VARCHAR(100) NOT NULL,
    jenis_kelamin ENUM('Laki-laki', 'Perempuan') NOT NULL,
    alamat TEXT NULL,
    kelas_id INT NULL,
    kamar_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_santri_kelas FOREIGN KEY (kelas_id) REFERENCES kelas(id) ON DELETE SET NULL,
    CONSTRAINT fk_santri_kamar FOREIGN KEY (kamar_id) REFERENCES kamar(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS pembayaran (
    id INT AUTO_INCREMENT PRIMARY KEY,
    santri_id INT NOT NULL,
    tanggal_bayar DATE NOT NULL,
    jumlah DECIMAL(12,2) NOT NULL DEFAULT 0,
    keterangan TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_pembayaran_santri FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
);

INSERT INTO users (nama, username, password)
VALUES ('Administrator', 'admin', '$2y$10$jzZ6umhp4l6iCeoCCDzl3.Ov.TgavAMyvEAlpuOBtvvNHi/3gN5Dq')
ON DUPLICATE KEY UPDATE username = VALUES(username);

INSERT INTO kelas (nama_kelas)
VALUES ('Kelas 7A'), ('Kelas 8A'), ('Kelas 9A');

INSERT INTO kamar (nama_kamar, kapasitas)
VALUES ('Kamar A1', 10), ('Kamar A2', 8), ('Kamar B1', 12);

-- --------------------------------------------------------------------------
-- Bagian 2: presensi & perizinan (setara schema_presensi.sql)
-- --------------------------------------------------------------------------
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

ALTER TABLE santri ADD COLUMN IF NOT EXISTS no_wa_wali VARCHAR(30) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS is_aktif TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS nik VARCHAR(40) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS tempat_lahir_kab VARCHAR(120) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS tanggal_lahir VARCHAR(20) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS bulan_lahir VARCHAR(20) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS tahun_lahir VARCHAR(10) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS jumlah_saudara VARCHAR(10) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS anak_ke VARCHAR(10) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS hobi VARCHAR(120) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS cita_cita VARCHAR(120) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS dusun VARCHAR(120) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS rt_rw VARCHAR(30) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS desa_kelurahan VARCHAR(120) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS kecamatan VARCHAR(120) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS kabupaten VARCHAR(120) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS propinsi VARCHAR(120) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS nama_ayah VARCHAR(120) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS pekerjaan_ayah VARCHAR(120) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS no_kontak_ayah VARCHAR(30) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS nama_ibu VARCHAR(120) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS pekerjaan_ibu VARCHAR(120) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS no_kontak_ibu VARCHAR(30) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS nama_kafil VARCHAR(120) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS status_kafil VARCHAR(80) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS pekerjaan_kafil VARCHAR(120) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS no_kontak_kafil VARCHAR(30) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS pendidikan_diniyyah_terakhir TEXT NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS pendidikan_formal_terakhir TEXT NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS kitab_yang_pernah_dikaji TEXT NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS keluhan_sakit TEXT NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS pengobatan TEXT NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS tanggal_masuk DATE NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS alasan_mondok TEXT NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS atas_keinginan TEXT NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS mengapa_nailul TEXT NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS wali_portal_pin_hash VARCHAR(255) NULL;

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

-- --------------------------------------------------------------------------
-- Bagian 3: penyesuaian migrasi (jadwal, users, E-Health, poin, superadmin)
-- --------------------------------------------------------------------------
ALTER TABLE jadwal_kegiatan ADD COLUMN IF NOT EXISTS pembimbing_id INT NULL;
ALTER TABLE users ADD COLUMN IF NOT EXISTS role ENUM('admin','pengurus','petugas_absensi') NOT NULL DEFAULT 'pengurus';

UPDATE users SET role = 'admin' WHERE username = 'admin';

DELETE FROM app_settings WHERE setting_key = 'nama_ketertiban';

INSERT INTO app_settings (setting_key, setting_value)
VALUES ('jenis_pendidikan', 'Pondok Pesantren / Pesantren Putra Putri')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

INSERT INTO app_settings (setting_key, setting_value) VALUES
('batas_telat_menit', '15'),
('grace_period_menit', '15')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

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

CREATE TABLE IF NOT EXISTS point_rules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kode_rule VARCHAR(40) NOT NULL UNIQUE,
    kategori VARCHAR(80) NOT NULL,
    nama_rule VARCHAR(150) NOT NULL,
    bobot_poin INT NOT NULL DEFAULT 0,
    contoh_pelanggaran TEXT NULL,
    urutan INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS point_sanctions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ambang_poin INT NOT NULL,
    tindakan TEXT NOT NULL,
    urutan INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS point_ledger (
    id INT AUTO_INCREMENT PRIMARY KEY,
    santri_id INT NOT NULL,
    tanggal DATE NOT NULL,
    jenis_perubahan ENUM('PLUS','MINUS') NOT NULL DEFAULT 'PLUS',
    point_delta INT NOT NULL,
    rule_id INT NULL,
    sumber_data VARCHAR(40) NOT NULL DEFAULT 'MANUAL',
    reference_presensi_id INT NULL,
    keterangan TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_point_source_ref (sumber_data, reference_presensi_id),
    INDEX idx_point_santri_tanggal (santri_id, tanggal),
    CONSTRAINT fk_point_ledger_santri FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE,
    CONSTRAINT fk_point_ledger_rule FOREIGN KEY (rule_id) REFERENCES point_rules(id) ON DELETE SET NULL
);

INSERT INTO point_rules (kode_rule, kategori, nama_rule, bobot_poin, contoh_pelanggaran, urutan)
SELECT v.kode_rule, v.kategori, v.nama_rule, v.bobot_poin, v.contoh_pelanggaran, v.urutan
FROM (
    SELECT 'A_SANGAT_BERAT' AS kode_rule, 'A. Sangat Berat' AS kategori, 'Pelanggaran sangat berat' AS nama_rule, 25 AS bobot_poin, 'Percintaan, Pencurian, Perkelahian, Perjudian, Narkoba/Miras, Asusila.' AS contoh_pelanggaran, 10 AS urutan
    UNION ALL SELECT 'B_BERAT_15', 'B. Berat', 'Pelanggaran berat', 15, 'Membawa HP/Elektronik tanpa izin, kendaraan tanpa izin, ghosob, masuk asrama lawan jenis.', 20
    UNION ALL SELECT 'B_BERAT_10', 'B. Berat', 'Pelanggaran berat level 2', 10, 'Bolos ngaji/belajar/mujahadah, merusak fasilitas, kata kasar, tidur saat kegiatan sama.', 30
    UNION ALL SELECT 'C_SEDANG_5', 'C. Sedang', 'Pelanggaran sedang', 5, 'Keluar tanpa izin, ngiras/ngendong, bermain catur/kartu, meminjam dipan.', 40
    UNION ALL SELECT 'C_SEDANG_3', 'C. Sedang', 'Pelanggaran sedang level 2', 3, 'Tidak piket, gaduh, tidur saat kegiatan.', 50
    UNION ALL SELECT 'D_RINGAN_1', 'D. Ringan', 'Pelanggaran ringan', 1, 'Peci non-hitam, lengan pendek saat sholat, rambut/model tidak lazim, geland/kalung, sampah.', 60
) v
WHERE NOT EXISTS (SELECT 1 FROM point_rules pr WHERE pr.kode_rule = v.kode_rule);

INSERT INTO point_sanctions (ambang_poin, tindakan, urutan)
SELECT v.ambang_poin, v.tindakan, v.urutan
FROM (
    SELECT 10 AS ambang_poin, 'Pilihan: Membaca Al-Quran 2 juz, Mujahadah 1 jam, atau 1 jam bersih-bersih.' AS tindakan, 10 AS urutan
    UNION ALL SELECT 25, 'Wajib gundul (putra)/kerudung disiplin (putri). Pilihan: berdiri 2 jam, baca Yasin 2 jam, Mujahadah 2 jam, atau 2 jam bersih-bersih.', 20
    UNION ALL SELECT 50, 'Surat Peringatan 1 (SP1). Wajib gundul/kerudung disiplin. Pilihan: baca Yasin 3 jam, Al-Quran 5 juz, Mujahadah 3 jam, atau 3 jam bersih-bersih.', 30
    UNION ALL SELECT 75, 'Surat Peringatan 2 (SP2) dan pemanggilan orang tua. Wajib gundul/kerudung disiplin. Pilihan: baca Yasin 4 jam, Al-Quran 7 juz, Mujahadah 4 jam, atau 4 jam bersih-bersih.', 40
    UNION ALL SELECT 100, 'Sanksi final: dikeluarkan dari pesantren. Wajib gundul/kerudung disiplin hingga dijemput. Pilihan: baca Yasin 5 jam, Al-Quran 9 juz, Mujahadah 5 jam, atau 5 jam bersih-bersih.', 50
) v
WHERE NOT EXISTS (
    SELECT 1 FROM point_sanctions ps
    WHERE ps.ambang_poin = v.ambang_poin
);

INSERT INTO app_settings (setting_key, setting_value) VALUES
('point_auto_alpa', '5'),
('point_auto_telat', '1')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

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

ALTER TABLE point_followups ADD COLUMN IF NOT EXISTS status_tindak ENUM('BELUM','PROSES','SELESAI') NOT NULL DEFAULT 'BELUM';
ALTER TABLE point_followups ADD COLUMN IF NOT EXISTS bukti_tindak TEXT NULL;

ALTER TABLE users ADD COLUMN IF NOT EXISTS is_super_admin TINYINT(1) NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS user_access_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    permission_key VARCHAR(80) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_permission (user_id, permission_key),
    CONSTRAINT fk_user_access_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS wali_santri (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama VARCHAR(120) NOT NULL,
    no_wa VARCHAR(40) NULL,
    alamat TEXT NULL,
    user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_wali_santri_user (user_id),
    CONSTRAINT fk_wali_santri_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

ALTER TABLE wali_santri ADD COLUMN IF NOT EXISTS nomor_id VARCHAR(40) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS wali_santri_id INT NULL;
CREATE INDEX IF NOT EXISTS idx_santri_wali_santri ON santri (wali_santri_id);
UPDATE wali_santri SET nomor_id = CONCAT('WS-', LPAD(id, 6, '0')) WHERE nomor_id IS NULL OR TRIM(COALESCE(nomor_id, '')) = '';

UPDATE users
SET is_super_admin = 1
WHERE username = 'admin';

-- --------------------------------------------------------------------------
-- Bagian 4: modul keuangan + cashless (versi terbaru)
-- --------------------------------------------------------------------------
ALTER TABLE santri ADD COLUMN IF NOT EXISTS nama_santri VARCHAR(100) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS tingkatan VARCHAR(80) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS qr VARCHAR(120) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS kategori_kelas VARCHAR(80) NULL;

UPDATE santri
SET nama_santri = nama
WHERE (nama_santri IS NULL OR nama_santri = '')
  AND nama IS NOT NULL
  AND nama <> '';

CREATE TABLE IF NOT EXISTS kelas_keuangan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kode VARCHAR(40) NOT NULL,
    nama_tampilan VARCHAR(120) NOT NULL,
    tarif_keuangan_tier VARCHAR(20) NOT NULL DEFAULT 'wustho',
    urutan INT NOT NULL DEFAULT 0,
    is_aktif TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_kelas_keuangan_kode (kode)
);

INSERT IGNORE INTO kelas_keuangan (kode, nama_tampilan, tarif_keuangan_tier, urutan, is_aktif) VALUES
('MUAD',  'Muadalah', 'muadalah', 1, 1),
('WUSTO', 'Wustho',   'wustho',   2, 1),
('ULYA',  'Ulya',     'ulya',     3, 1),
('WUSTO1', 'Wustho 1', 'wustho', 21, 1),
('WUSTO2', 'Wustho 2', 'wustho', 22, 1),
('WUSTO3', 'Wustho 3', 'wustho', 23, 1),
('ULYA1',  'Ulya 1',   'ulya',   31, 1),
('ULYA2',  'Ulya 2',   'ulya',   32, 1),
('ULYA3',  'Ulya 3',   'ulya',   33, 1);

CREATE TABLE IF NOT EXISTS keuangan_pembayaran (
    id INT AUTO_INCREMENT PRIMARY KEY,
    santri_id INT NOT NULL,
    jenis_periode ENUM('BULANAN','AWAL_TAHUN') NOT NULL DEFAULT 'BULANAN',
    tahun_ajaran_mulai INT NOT NULL,
    tahun_ajaran_selesai INT NOT NULL,
    bulan_tagihan TINYINT NULL,
    tanggal_bayar DATE NOT NULL,
    total_nominal DECIMAL(12,2) NOT NULL DEFAULT 0,
    keterangan VARCHAR(255) NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    metode_bayar ENUM('KAS','TRANSFER') NOT NULL DEFAULT 'KAS',
    akun_id INT NULL,
    no_referensi VARCHAR(100) NULL,
    INDEX idx_kp_santri (santri_id),
    INDEX idx_kp_tanggal (tanggal_bayar)
);

CREATE TABLE IF NOT EXISTS keuangan_pembayaran_detail (
    id INT AUTO_INCREMENT PRIMARY KEY,
    pembayaran_id INT NOT NULL,
    pos_slug VARCHAR(80) NOT NULL,
    pos_nama VARCHAR(120) NOT NULL,
    nominal DECIMAL(12,2) NOT NULL DEFAULT 0,
    INDEX idx_kpd_pembayaran (pembayaran_id),
    CONSTRAINT fk_kpd_pembayaran FOREIGN KEY (pembayaran_id) REFERENCES keuangan_pembayaran(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS keuangan_akun (
    id INT AUTO_INCREMENT PRIMARY KEY,
    jenis_akun ENUM('KAS','BANK','E-WALLET') NOT NULL DEFAULT 'KAS',
    nama_akun VARCHAR(120) NOT NULL,
    nama_bank VARCHAR(120) NULL,
    no_rekening VARCHAR(80) NULL,
    atas_nama VARCHAR(120) NULL,
    opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS keuangan_alokasi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nama_komponen VARCHAR(120) NOT NULL,
    kategori VARCHAR(80) NOT NULL,
    persen DECIMAL(6,2) NOT NULL DEFAULT 0,
    urutan INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS keuangan_pengeluaran (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tanggal DATE NOT NULL,
    penanggung_jawab VARCHAR(120) NOT NULL,
    pos VARCHAR(120) NOT NULL,
    alokasi_nama VARCHAR(120) NULL,
    metode_keluar ENUM('KAS','TRANSFER') NOT NULL DEFAULT 'KAS',
    akun_id INT NULL,
    no_bukti VARCHAR(120) NULL,
    nominal DECIMAL(12,2) NOT NULL DEFAULT 0,
    keterangan TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_kpeng_tanggal (tanggal)
);

CREATE TABLE IF NOT EXISTS keuangan_pemasukan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tanggal DATE NOT NULL,
    sumber VARCHAR(120) NOT NULL,
    dari_pihak VARCHAR(150) NULL,
    metode_bayar ENUM('KAS','TRANSFER') NOT NULL DEFAULT 'KAS',
    akun_id INT NULL,
    no_bukti VARCHAR(120) NULL,
    nominal DECIMAL(12,2) NOT NULL DEFAULT 0,
    keterangan TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_keu_pemasukan_tanggal (tanggal),
    INDEX idx_keu_pemasukan_akun (akun_id)
);

CREATE TABLE IF NOT EXISTS cashless_accounts (
    santri_id INT PRIMARY KEY,
    pin_hash VARCHAR(255) NULL,
    balance DECIMAL(12,2) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cashless_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    santri_id INT NOT NULL,
    tanggal DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    jenis ENUM('TOPUP','DEBIT') NOT NULL,
    nominal DECIMAL(12,2) NOT NULL DEFAULT 0,
    keterangan VARCHAR(255) NULL,
    ref_pembayaran_id INT NULL,
    created_by INT NULL,
    INDEX idx_ctx_santri_tanggal (santri_id, tanggal)
);

CREATE TABLE IF NOT EXISTS cashless_nominal_qr_map (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kode_qr VARCHAR(120) NOT NULL,
    nominal INT NOT NULL DEFAULT 0,
    keterangan VARCHAR(160) NULL,
    is_aktif TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_cashless_nominal_qr_kode (kode_qr)
);

CREATE TABLE IF NOT EXISTS akademik_hafalan_setoran (
    id INT AUTO_INCREMENT PRIMARY KEY,
    santri_id INT NOT NULL,
    tanggal_setoran DATE NOT NULL,
    target_hafalan VARCHAR(255) NOT NULL,
    juz_halaman VARCHAR(120) NULL,
    nilai_skor TINYINT UNSIGNED NULL,
    predikat VARCHAR(40) NULL,
    catatan TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ahs_santri (santri_id),
    INDEX idx_ahs_tgl (tanggal_setoran),
    CONSTRAINT fk_ahs_santri FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS akademik_rapor (
    id INT AUTO_INCREMENT PRIMARY KEY,
    santri_id INT NOT NULL,
    judul_periode VARCHAR(160) NOT NULL,
    tanggal_terbit DATE NOT NULL,
    narasi TEXT NULL,
    predikat_akhlak VARCHAR(100) NULL,
    catatan_pondok TEXT NULL,
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ar_santri (santri_id),
    INDEX idx_ar_terbit (tanggal_terbit),
    CONSTRAINT fk_ar_santri FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
);

INSERT INTO app_settings (setting_key, setting_value) VALUES
('cashless_daily_limit', '10000'),
('cashless_scan_uang_enabled', '1'),
('cashless_scan_uang_voice', '1'),
('cashless_scan_uang_max_nominal', '200000'),
('wa_tagihan_auto_enabled', '0'),
('wa_tagihan_calendar', 'HIJRIYAH'),
('wa_tagihan_day', '5')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

CREATE TABLE IF NOT EXISTS akuntansi_aset_tetap (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kode_inventaris VARCHAR(50) NULL,
    nama_aset VARCHAR(180) NOT NULL,
    kategori_aset VARCHAR(120) NOT NULL,
    lokasi VARCHAR(150) NULL,
    tanggal_perolehan DATE NOT NULL,
    harga_perolehan DECIMAL(14,2) NOT NULL DEFAULT 0,
    nilai_residu DECIMAL(14,2) NOT NULL DEFAULT 0,
    umur_manfaat_bulan INT NOT NULL DEFAULT 12,
    akumulasi_penyusutan DECIMAL(14,2) NOT NULL DEFAULT 0,
    last_penyusutan_periode VARCHAR(7) NULL,
    keterangan TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_aset_kategori (kategori_aset),
    INDEX idx_aset_perolehan (tanggal_perolehan)
);

CREATE TABLE IF NOT EXISTS akuntansi_jurnal_penyesuaian (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tanggal DATE NOT NULL,
    kode_akun VARCHAR(30) NOT NULL,
    nama_akun VARCHAR(150) NOT NULL,
    debit DECIMAL(14,2) NOT NULL DEFAULT 0,
    kredit DECIMAL(14,2) NOT NULL DEFAULT 0,
    keterangan TEXT NULL,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- =============================================================================
-- Selesai. Login default: username admin (password sesuai hash di INSERT users).
-- Jika MySQL lama tidak mengenal "ADD COLUMN IF NOT EXISTS", upgrade MariaDB/MySQL
-- atau hapus frasa IF NOT EXISTS pada baris ALTER yang gagal, lalu jalankan ulang.
-- =============================================================================
