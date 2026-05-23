<?php

declare(strict_types=1);

/** @return list<string> */
function yayasan_jabatan_opsi(): array
{
    return [
        'Ketua Yayasan',
        'Wakil Ketua',
        'Sekretaris',
        'Bendahara',
        'Anggota',
        'Penasihat',
        'Lainnya',
    ];
}

/** @return list<string> */
function yayasan_jenis_rapat_opsi(): array
{
    return ['RUTIN', 'INSIDENTAL', 'LAIN'];
}

function yayasan_nama_by_jabatan(PDO $pdo, string $jabatan): string
{
    yayasan_ensure_tables($pdo);
    $stmt = $pdo->prepare('
        SELECT nama FROM yayasan_pengurus
        WHERE jabatan = :jabatan AND is_aktif = 1
        ORDER BY urutan ASC, id ASC
        LIMIT 1
    ');
    $stmt->execute(['jabatan' => $jabatan]);
    $nama = $stmt->fetchColumn();

    return is_string($nama) ? trim($nama) : '';
}

function yayasan_ensure_tables(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS yayasan_pengurus (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nama VARCHAR(120) NOT NULL,
            jabatan VARCHAR(80) NOT NULL DEFAULT "Anggota",
            no_wa VARCHAR(30) NULL,
            email VARCHAR(120) NULL,
            urutan INT NOT NULL DEFAULT 0,
            is_aktif TINYINT(1) NOT NULL DEFAULT 1,
            catatan TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_yayasan_pengurus_aktif (is_aktif),
            INDEX idx_yayasan_pengurus_urutan (urutan)
        )
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS yayasan_rapat (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nomor_rapat VARCHAR(40) NULL,
            judul VARCHAR(200) NOT NULL,
            tanggal_rapat DATE NOT NULL,
            waktu_mulai TIME NULL,
            waktu_selesai TIME NULL,
            lokasi VARCHAR(120) NULL,
            jenis ENUM("RUTIN","INSIDENTAL","LAIN") NOT NULL DEFAULT "RUTIN",
            status ENUM("DRAFT","SELESAI") NOT NULL DEFAULT "DRAFT",
            agenda_ringkas TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_yayasan_rapat_tanggal (tanggal_rapat),
            INDEX idx_yayasan_rapat_status (status)
        )
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS yayasan_notulen (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rapat_id INT NOT NULL,
            judul VARCHAR(200) NULL,
            isi LONGTEXT NULL,
            ringkasan TEXT NULL,
            keputusan TEXT NULL,
            tindak_lanjut TEXT NULL,
            hadir TEXT NULL,
            diinput_oleh INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_yayasan_notulen_rapat (rapat_id),
            CONSTRAINT fk_yayasan_notulen_rapat FOREIGN KEY (rapat_id) REFERENCES yayasan_rapat(id) ON DELETE CASCADE
        )
    ');
}

function yayasan_label_jenis_rapat(string $jenis): string
{
    return match (strtoupper($jenis)) {
        'RUTIN' => 'Rapat rutin',
        'INSIDENTAL' => 'Insidental',
        default => 'Lainnya',
    };
}

function yayasan_format_tanggal_rapat(string $tanggal, ?string $waktuMulai = null, ?string $waktuSelesai = null): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return $tanggal;
    }
    $ts = strtotime($tanggal);
    if ($ts === false) {
        return $tanggal;
    }
    $out = date('d M Y', $ts);
    $jam = [];
    if ($waktuMulai !== null && $waktuMulai !== '' && $waktuMulai !== '00:00:00') {
        $jam[] = substr($waktuMulai, 0, 5);
    }
    if ($waktuSelesai !== null && $waktuSelesai !== '' && $waktuSelesai !== '00:00:00') {
        $jam[] = substr($waktuSelesai, 0, 5);
    }
    if ($jam !== []) {
        $out .= ' · ' . implode('–', $jam);
    }

    return $out;
}
