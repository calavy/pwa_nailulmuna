USE pwa_nailulmuna;

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

CREATE TABLE IF NOT EXISTS app_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT NULL
);

INSERT INTO app_settings (setting_key, setting_value) VALUES
('point_auto_alpa', '5'),
('point_auto_telat', '1')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
