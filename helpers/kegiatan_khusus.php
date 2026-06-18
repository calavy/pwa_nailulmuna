<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

function kegiatan_khusus_ensure_schema(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS kegiatan_khusus (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nama_kegiatan VARCHAR(160) NOT NULL,
            kategori_kegiatan VARCHAR(20) NOT NULL DEFAULT "TAALIM",
            tingkatan VARCHAR(120) NOT NULL DEFAULT "Semua Tingkatan",
            tanggal DATE NOT NULL,
            jam_mulai TIME NOT NULL,
            jam_selesai TIME NOT NULL,
            tempat VARCHAR(190) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_khusus_tanggal (tanggal),
            INDEX idx_khusus_aktif (is_active, tanggal)
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS presensi_kegiatan_khusus (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kegiatan_khusus_id INT NOT NULL,
            santri_id INT NOT NULL,
            tanggal DATE NOT NULL,
            jam TIME NOT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_khusus_santri_hari (kegiatan_khusus_id, santri_id, tanggal),
            CONSTRAINT fk_pk_khusus FOREIGN KEY (kegiatan_khusus_id) REFERENCES kegiatan_khusus(id) ON DELETE CASCADE
        )
    ');
}

/** @return list<string> */
function kegiatan_khusus_tingkatan_list(PDO $pdo): array
{
    $out = [];
    if (table_exists($pdo, 'tingkatan')) {
        $rows = $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY nama_tingkatan ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($rows as $r) {
            $t = trim((string) $r);
            if ($t !== '') {
                $out[$t] = $t;
            }
        }
    }
    if ($out === [] && table_exists($pdo, 'santri') && column_exists($pdo, 'santri', 'tingkatan')) {
        $rows = $pdo->query('
            SELECT DISTINCT tingkatan FROM santri
            WHERE tingkatan IS NOT NULL AND TRIM(tingkatan) != ""
            ORDER BY tingkatan ASC
        ')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($rows as $r) {
            $t = trim((string) $r);
            if ($t !== '') {
                $out[$t] = $t;
            }
        }
    }
    if ($out === [] && table_exists($pdo, 'jadwal_kegiatan')) {
        $rows = $pdo->query('
            SELECT DISTINCT tingkatan FROM jadwal_kegiatan
            WHERE tingkatan IS NOT NULL AND TRIM(tingkatan) != ""
            ORDER BY tingkatan ASC
        ')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($rows as $r) {
            $t = trim((string) $r);
            if ($t !== '') {
                $out[$t] = $t;
            }
        }
    }

    $list = array_values($out);
    sort($list, SORT_NATURAL | SORT_FLAG_CASE);
    array_unshift($list, 'Semua Tingkatan');

    return $list;
}

function kegiatan_khusus_tingkatan_dari_post(array $post): array
{
    $raw = $post['tingkatan'] ?? [];
    if (!is_array($raw)) {
        $single = trim((string) $raw);
        return $single !== '' ? [$single] : [];
    }
    $out = [];
    foreach ($raw as $v) {
        $t = trim((string) $v);
        if ($t !== '') {
            $out[$t] = $t;
        }
    }
    $tingkatan = array_values($out);
    if (in_array('Semua Tingkatan', $tingkatan, true)) {
        return ['Semua Tingkatan'];
    }

    return $tingkatan;
}

/**
 * @return array{ok:bool,message:string,count?:int}
 */
function kegiatan_khusus_tambah(PDO $pdo, array $post, int $userId): array
{
    kegiatan_khusus_ensure_schema($pdo);

    $nama = trim((string) ($post['nama_kegiatan'] ?? ''));
    $kategori = strtoupper(trim((string) ($post['kategori_kegiatan'] ?? 'TAALIM')));
    $tingkatanDipilih = kegiatan_khusus_tingkatan_dari_post($post);
    $tanggal = trim((string) ($post['tanggal'] ?? date('Y-m-d')));
    $jamMulai = trim((string) ($post['jam_mulai'] ?? ''));
    $jamSelesai = trim((string) ($post['jam_selesai'] ?? ''));
    $tempat = trim((string) ($post['tempat'] ?? ''));

    if (strlen($jamMulai) === 5) {
        $jamMulai .= ':00';
    }
    if (strlen($jamSelesai) === 5) {
        $jamSelesai .= ':00';
    }

    if (!in_array($kategori, ['JAMAAH', 'TAALIM'], true)) {
        $kategori = 'TAALIM';
    }
    if ($nama === '' || $tanggal === '' || $jamMulai === '' || $jamSelesai === '') {
        return ['ok' => false, 'message' => 'Nama, tanggal, jam mulai, dan jam selesai wajib diisi.'];
    }
    if ($tingkatanDipilih === []) {
        return ['ok' => false, 'message' => 'Pilih minimal satu tingkatan.'];
    }
    if ($jamSelesai <= $jamMulai) {
        return ['ok' => false, 'message' => 'Jam selesai harus setelah jam mulai.'];
    }

    $ins = $pdo->prepare('
        INSERT INTO kegiatan_khusus (nama_kegiatan, kategori_kegiatan, tingkatan, tanggal, jam_mulai, jam_selesai, tempat, created_by)
        VALUES (:n, :kat, :ting, :tgl, :jm, :js, :tp, :by)
    ');

    $inTransaction = false;
    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $inTransaction = true;
        }
        foreach ($tingkatanDipilih as $tingkatan) {
            $ins->execute([
                'n' => $nama,
                'kat' => $kategori,
                'ting' => $tingkatan,
                'tgl' => $tanggal,
                'jm' => $jamMulai,
                'js' => $jamSelesai,
                'tp' => $tempat !== '' ? $tempat : null,
                'by' => $userId > 0 ? $userId : null,
            ]);
        }
        if ($inTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($inTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal menyimpan: ' . $e->getMessage()];
    }

    $count = count($tingkatanDipilih);
    $message = $count > 1
        ? 'Kegiatan khusus ditambahkan untuk ' . $count . ' tingkatan.'
        : 'Kegiatan khusus berhasil ditambahkan.';

    return ['ok' => true, 'message' => $message, 'count' => $count];
}

function kegiatan_khusus_find_active_for_tingkatan(PDO $pdo, string $tanggal, string $jam, string $tingkatan): ?array
{
    kegiatan_khusus_ensure_schema($pdo);
    $modeLibur = akademik_libur_presensi_mode_aktif_di_tanggal($pdo, $tanggal);
    $kategoriFilter = $modeLibur !== null
        ? akademik_libur_presensi_filter_sql_by_mode($modeLibur, 'COALESCE(kategori_kegiatan, "TAALIM")')
        : '';
    $st = $pdo->prepare('
        SELECT *
        FROM kegiatan_khusus
        WHERE is_active = 1
          AND tanggal = :tgl
          AND :jam BETWEEN jam_mulai AND jam_selesai
          AND (tingkatan = "Semua Tingkatan" OR tingkatan = :tingkatan)
          ' . $kategoriFilter . '
        ORDER BY jam_mulai ASC, id ASC
        LIMIT 1
    ');
    $st->execute(['tgl' => $tanggal, 'jam' => $jam, 'tingkatan' => $tingkatan]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

