CREATE DATABASE IF NOT EXISTS pwa_nailulmuna;
USE pwa_nailulmuna;

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
