USE pwa_nailulmuna;

CREATE TABLE IF NOT EXISTS point_peringan (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kode VARCHAR(20) NOT NULL UNIQUE,
    nama VARCHAR(150) NOT NULL,
    efek_persen INT NOT NULL DEFAULT 0,
    urutan INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS point_pemberat (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kode VARCHAR(20) NOT NULL UNIQUE,
    nama VARCHAR(150) NOT NULL,
    efek_persen INT NOT NULL DEFAULT 0,
    urutan INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Kolom ledger: otomatis juga lewat ensure_point_modifier_schema() di PHP jika belum ada.
-- ALTER manual (abaikan error jika kolom sudah ada):
-- ALTER TABLE point_ledger ADD COLUMN point_base INT NULL AFTER point_delta;
-- ALTER TABLE point_ledger ADD COLUMN peringan_id INT NULL AFTER point_base;
-- ALTER TABLE point_ledger ADD COLUMN pemberat_id INT NULL AFTER peringan_id;

INSERT IGNORE INTO point_peringan (kode, nama, efek_persen, urutan) VALUES
('P01', 'Diajak — menerima langsung', 0, 10),
('P02', 'Diajak — di bawah tekanan relasional', -50, 20);

INSERT IGNORE INTO point_pemberat (kode, nama, efek_persen, urutan) VALUES
('M01', 'Mengajak / menginisiasi / menyediakan', 50, 10),
('M02', 'Pelanggaran berulang (kategori sama, 2× atau lebih)', 50, 20);

INSERT INTO app_settings (setting_key, setting_value) VALUES
('point_presensi_auto_sync', '0'),
('point_presensi_periode', 'bulan')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
