-- =============================================================================
-- MIGRASI TERBARU (jalankan di phpMyAdmin)
-- =============================================================================
-- Database: pilih dulu database pondok Anda (mis. pwa_nailulmuna atau nama di hosting).
-- Cara: phpMyAdmin → pilih database → tab "SQL" → tempel isi bagian "PERUBAHAN"
--        di bawah ini → Go.
--
-- Aturan:
-- - Setiap fitur baru yang BUTUH perubahan DB, tambahkan blok di bagian bawah
--   dengan tanggal & judul singkat.
-- - Usahakan perintah idempotent (IF NOT EXISTS / cek kolom) agar aman dijalankan ulang.
-- - Jika tidak ada perubahan DB baru, biarkan hanya komentar "Tidak ada migrasi".
-- =============================================================================

-- -----------------------------------------------------------------------------
-- PERUBAHAN (tambahkan blok baru di PALING BAWAH)
-- -----------------------------------------------------------------------------

-- (contoh format — jangan dihapus jika belum ada migrasi nyata)
-- -- 2026-05-12 | Contoh tambah kolom
-- -- ALTER TABLE santri ADD COLUMN IF NOT EXISTS contoh_kolom VARCHAR(20) NULL;

-- Tidak ada migrasi database yang wajib dijalankan untuk modul Bendahara
-- (tagihan Syahriyah memakai tabel keuangan yang sudah ada).

-- 2026-05-12 | Dashboard pengasuh — grafik santri, ringkas saldo, perizinan mendesak
-- Tidak ada perintah SQL baru. Memakai tabel: santri, perizinan (opsional kolom
-- approval_status), cashless_accounts, keuangan_akun — pastikan sudah ada dari
-- impor_lengkap_pwa_nailulmuna.sql / migrasi sebelumnya. Jika approval_status belum
-- ada, jalankan file: update_import_2026_05_eizin_ehealth.sql (sekali).

-- -----------------------------------------------------------------------------
-- 2026-05-12 | Portal wali santri — kolom PIN portal (hash)
-- Jalankan sekali di phpMyAdmin (pilih database pondok Anda dulu).
-- Catatan: membuka halaman Edit Santri / Wali login dengan PHP juga bisa
-- menambahkan kolom ini otomatis (ALTER IF NOT EXISTS). Blok ini untuk server
-- yang hanya di-update lewat SQL.
-- -----------------------------------------------------------------------------
ALTER TABLE santri ADD COLUMN IF NOT EXISTS wali_portal_pin_hash VARCHAR(255) NULL;

-- -----------------------------------------------------------------------------
-- 2026-05-12 | Akademik — setoran hafalan (portal wali + input mudaris)
-- -----------------------------------------------------------------------------
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

-- -----------------------------------------------------------------------------
-- 2026-05-12 | Akademik — rapor (portal wali + input pengurus)
-- -----------------------------------------------------------------------------
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

-- -----------------------------------------------------------------------------
-- 2026-05-12 | Master tingkatan — ubah nama dari aplikasi
-- Tidak ada perintah SQL baru. Halaman settings/tingkatan.php memperbarui baris
-- tingkatan lalu menyamakan teks di santri.tingkatan dan jadwal_kegiatan.tingkatan
-- yang memakai nama lama (presisi sama persis dengan nilai lama di master).
-- -----------------------------------------------------------------------------

-- -----------------------------------------------------------------------------
-- 2026-05-12 | Kelas keuangan — kode dapat diubah & alur nama/kode
-- Tidak ada perintah SQL baru (tabel kelas_keuangan sudah di impor_lengkap).
-- Aplikasi: settings/kelas_keuangan.php menyinkronkan santri.kategori_kelas
-- saat kode diubah; normalisasi input menerima kode atau nama tampilan (tepat).
-- Form pembayaran (keuangan tab b): override kelas + opsi simpan ke profil santri.
-- -----------------------------------------------------------------------------

-- -----------------------------------------------------------------------------
-- 2026-05-12 | Profil wali (wali_santri): nama, WA, alamat, opsional user_id
-- -----------------------------------------------------------------------------
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

-- -----------------------------------------------------------------------------
-- 2026-05-12 | Wali: nomor_id; santri.wali_santri_id (banyak santri → satu wali)
-- -----------------------------------------------------------------------------
ALTER TABLE wali_santri ADD COLUMN IF NOT EXISTS nomor_id VARCHAR(40) NULL;
ALTER TABLE santri ADD COLUMN IF NOT EXISTS wali_santri_id INT NULL;
CREATE INDEX IF NOT EXISTS idx_santri_wali_santri ON santri (wali_santri_id);
-- Index unik (boleh banyak NULL); jika gagal karena sudah ada, abaikan.
-- CREATE UNIQUE INDEX uk_wali_santri_nomor_id ON wali_santri (nomor_id);
UPDATE wali_santri SET nomor_id = CONCAT('WS-', LPAD(id, 6, '0')) WHERE nomor_id IS NULL OR TRIM(COALESCE(nomor_id, '')) = '';

-- -----------------------------------------------------------------------------
-- 2026-05-12 | Penomoran surat (izin / SP) + kolom nomor surat perizinan
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS surat_nomor_seq (
    jenis_kode VARCHAR(40) NOT NULL,
    tahun INT NOT NULL,
    seq_terakhir INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (jenis_kode, tahun)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS surat_nomor_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    jenis_kode VARCHAR(40) NOT NULL,
    ref_key VARCHAR(160) NOT NULL,
    nomor_surat VARCHAR(180) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_surat_nomor_cache (jenis_kode, ref_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE perizinan ADD COLUMN IF NOT EXISTS nomor_surat VARCHAR(180) NULL;

-- -----------------------------------------------------------------------------
-- 2026-05-18 | Akademik — data alumni (NIS, alamat, tahun masuk/keluar)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS akademik_alumni (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nis VARCHAR(32) NOT NULL,
    nama VARCHAR(200) NOT NULL,
    dusun VARCHAR(120) NULL,
    rt_rw VARCHAR(20) NULL,
    desa_kelurahan VARCHAR(120) NULL,
    kecamatan VARCHAR(120) NULL,
    kabupaten VARCHAR(120) NULL,
    propinsi VARCHAR(120) NULL,
    th_masuk SMALLINT UNSIGNED NULL,
    th_keluar SMALLINT UNSIGNED NULL,
    keterangan TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_akademik_alumni_nis (nis),
    INDEX idx_akademik_alumni_nama (nama)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2026-05-18 | Alumni — urutan baris sesuai import Excel
ALTER TABLE akademik_alumni ADD COLUMN IF NOT EXISTS urutan INT UNSIGNED NULL;
UPDATE akademik_alumni SET urutan = id WHERE urutan IS NULL;

-- -----------------------------------------------------------------------------
-- 2026-05-19 | Riwayat santri jangka panjang — tingkatan per TA, hidmah, arsip
-- Tabel juga dibuat otomatis saat membuka halaman Riwayat / Input hidmah.
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS santri_riwayat_tingkatan (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    santri_id INT NOT NULL,
    tahun_ajaran_mulai SMALLINT NOT NULL,
    tahun_ajaran_selesai SMALLINT NOT NULL,
    tingkatan VARCHAR(80) NOT NULL,
    kategori_kelas VARCHAR(80) NULL,
    catatan VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_santri_ta (santri_id, tahun_ajaran_mulai, tahun_ajaran_selesai),
    INDEX idx_srt_santri (santri_id),
    CONSTRAINT fk_srt_santri FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS santri_riwayat_hidmah (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    santri_id INT NOT NULL,
    jenis_peran ENUM('HIDMAH','PENGURUS_SANTRI','PEMBANTU_USAHA') NOT NULL DEFAULT 'HIDMAH',
    nama_hidmah VARCHAR(200) NOT NULL,
    tahun_ajaran_mulai SMALLINT NOT NULL,
    tahun_ajaran_selesai SMALLINT NULL,
    tanggal_mulai DATE NULL,
    tanggal_selesai DATE NULL,
    keterangan TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_srh_santri (santri_id, tahun_ajaran_mulai),
    CONSTRAINT fk_srh_santri FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS santri_riwayat_asrama (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    santri_id INT NOT NULL,
    gedung VARCHAR(120) NOT NULL DEFAULT 'Asrama',
    nama_kamar VARCHAR(120) NOT NULL,
    no_ranjang VARCHAR(80) NULL,
    tahun_ajaran_mulai SMALLINT NULL,
    tahun_ajaran_selesai SMALLINT NULL,
    catatan VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_sra_santri (santri_id, tahun_ajaran_mulai),
    CONSTRAINT fk_sra_santri FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS santri_riwayat_domisili (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    santri_id INT NOT NULL,
    jenis_domisili ENUM('MENGAJI','KHIDMAH') NOT NULL DEFAULT 'MENGAJI',
    gedung VARCHAR(120) NOT NULL DEFAULT 'Asrama',
    nama_kamar VARCHAR(120) NOT NULL,
    no_ranjang VARCHAR(80) NULL,
    tahun_ajaran_mulai SMALLINT NULL,
    tahun_ajaran_selesai SMALLINT NULL,
    tanggal_mulai DATE NULL,
    tanggal_selesai DATE NULL,
    catatan VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_srd_santri_jenis (santri_id, jenis_domisili, tahun_ajaran_mulai),
    CONSTRAINT fk_srd_santri FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Kolom wali_kelas & status_akademik: ditambahkan otomatis lewat ensure_santri_riwayat_tables()

-- -----------------------------------------------------------------------------
-- 2026-05-19 | Status santri terpusat: AKTIF, NONAKTIF, KHIDMAH
-- -----------------------------------------------------------------------------
ALTER TABLE santri MODIFY COLUMN status_santri VARCHAR(20) NOT NULL DEFAULT 'AKTIF';
UPDATE santri SET status_santri = 'NONAKTIF', is_aktif = 0
WHERE UPPER(REPLACE(TRIM(COALESCE(status_santri, '')), ' ', '_')) IN ('NON_AKTIF','NONAKTIF','BOYONG','ALUMNI','MUQIM','KELUAR');
UPDATE santri SET status_santri = 'KHIDMAH', is_aktif = 1
WHERE UPPER(REPLACE(TRIM(COALESCE(status_santri, '')), ' ', '_')) IN ('PENGABDIAN','KHIDMAH');
UPDATE santri SET status_santri = 'AKTIF', is_aktif = 1
WHERE UPPER(TRIM(COALESCE(status_santri, ''))) = 'AKTIF' OR TRIM(COALESCE(status_santri, '')) = '';

-- -----------------------------------------------------------------------------
-- 2026-05-19 | Portal santri — PIN login mandiri (riwayat domisili & pelanggaran)
-- -----------------------------------------------------------------------------
ALTER TABLE santri ADD COLUMN IF NOT EXISTS santri_portal_pin_hash VARCHAR(255) NULL;

