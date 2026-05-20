<?php

declare(strict_types=1);

/**
 * Master kamar & ranjang asrama + validasi satu ranjang per santri aktif.
 */

function asrama_format_no_ranjang_display(string $label, string $posisi): string
{
    $p = strtoupper(trim($posisi)) === 'BAWAH' ? 'Bawah' : 'Atas';

    return trim($label) . ' — ' . $p;
}

function ensure_asrama_kamar_ranjang_tables(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS asrama_kamar (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nama_kamar VARCHAR(120) NOT NULL,
            urutan INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_asrama_kamar_nama (nama_kamar)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS asrama_ranjang (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kamar_id INT NOT NULL,
            label VARCHAR(80) NOT NULL,
            posisi ENUM(\'ATAS\', \'BAWAH\') NOT NULL DEFAULT \'ATAS\',
            urutan INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_asrama_ranjang_kamar_label_posisi (kamar_id, label, posisi),
            CONSTRAINT fk_asrama_ranjang_kamar FOREIGN KEY (kamar_id) REFERENCES asrama_kamar(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');

    if (!column_exists($pdo, 'asrama_ranjang', 'posisi')) {
        try {
            $pdo->exec("ALTER TABLE asrama_ranjang ADD COLUMN posisi ENUM('ATAS','BAWAH') NOT NULL DEFAULT 'ATAS' AFTER label");
        } catch (Throwable $e) {
            // kolom mungkin sudah ada
        }
        foreach (['uq_asrama_ranjang_kamar_label', 'uq_asrama_ranjang_kamar_label_posisi'] as $idx) {
            try {
                $pdo->exec('ALTER TABLE asrama_ranjang DROP INDEX `' . $idx . '`');
            } catch (Throwable $e) {
                // index tidak ada
            }
        }
        try {
            $pdo->exec('ALTER TABLE asrama_ranjang ADD UNIQUE KEY uq_asrama_ranjang_kamar_label_posisi (kamar_id, label, posisi)');
        } catch (Throwable $e) {
            // sudah ada
        }
    }

    ensure_santri_asrama_ranjang_fk($pdo);
}

function ensure_santri_asrama_ranjang_fk(PDO $pdo): void
{
    if (!table_exists($pdo, 'santri') || !table_exists($pdo, 'asrama_ranjang') || !column_exists($pdo, 'santri', 'asrama_ranjang_id')) {
        return;
    }
    try {
        $pdo->exec('ALTER TABLE santri ADD CONSTRAINT fk_santri_asrama_ranjang FOREIGN KEY (asrama_ranjang_id) REFERENCES asrama_ranjang(id) ON DELETE SET NULL');
    } catch (Throwable $e) {
        // sudah ada atau tipe tidak kompatibel
    }
}

/**
 * @return array{nama_kamar:string,no_ranjang:string,asrama_ranjang_id:int}|null
 */
function asrama_resolve_ranjang_to_kamar_fields(PDO $pdo, int $ranjangId): ?array
{
    if ($ranjangId <= 0 || !table_exists($pdo, 'asrama_ranjang') || !table_exists($pdo, 'asrama_kamar')) {
        return null;
    }
    $st = $pdo->prepare('
        SELECT k.nama_kamar, r.label, r.posisi
        FROM asrama_ranjang r
        INNER JOIN asrama_kamar k ON k.id = r.kamar_id
        WHERE r.id = :id
        LIMIT 1
    ');
    $st->execute(['id' => $ranjangId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $posisi = (string) ($row['posisi'] ?? 'ATAS');
    $label = trim((string) ($row['label'] ?? ''));

    return [
        'nama_kamar' => trim((string) ($row['nama_kamar'] ?? '')),
        'no_ranjang' => asrama_format_no_ranjang_display($label, $posisi),
        'asrama_ranjang_id' => $ranjangId,
    ];
}

/**
 * Cocokkan data santri ke id ranjang: FK dulu, lalu teks tampilan.
 */
function asrama_match_ranjang_id(PDO $pdo, int $santriId, string $namaKamar, string $noRanjang): int
{
    if (table_exists($pdo, 'santri') && column_exists($pdo, 'santri', 'asrama_ranjang_id') && $santriId > 0) {
        $fk = $pdo->prepare('SELECT asrama_ranjang_id FROM santri WHERE id = :id LIMIT 1');
        $fk->execute(['id' => $santriId]);
        $rid = (int) ($fk->fetchColumn() ?: 0);
        if ($rid > 0) {
            return $rid;
        }
    }
    $nk = trim($namaKamar);
    $nr = trim($noRanjang);
    if ($nk === '' || $nr === '' || !table_exists($pdo, 'asrama_ranjang') || !table_exists($pdo, 'asrama_kamar')) {
        return 0;
    }
    if (!column_exists($pdo, 'asrama_ranjang', 'posisi')) {
        $st = $pdo->prepare('
            SELECT r.id
            FROM asrama_ranjang r
            INNER JOIN asrama_kamar k ON k.id = r.kamar_id
            WHERE k.nama_kamar = :nk AND r.label = :nr
            LIMIT 1
        ');
        $st->execute(['nk' => $nk, 'nr' => $nr]);

        return (int) ($st->fetchColumn() ?: 0);
    }
    $st = $pdo->prepare('
        SELECT r.id
        FROM asrama_ranjang r
        INNER JOIN asrama_kamar k ON k.id = r.kamar_id
        WHERE k.nama_kamar = :nk
          AND CONCAT(TRIM(r.label), \' — \', CASE r.posisi WHEN \'BAWAH\' THEN \'Bawah\' ELSE \'Atas\' END) = :nr
        LIMIT 1
    ');
    $st->execute(['nk' => $nk, 'nr' => $nr]);

    return (int) ($st->fetchColumn() ?: 0);
}

/**
 * Pesan error jika ranjang/kamar manual sudah dipakai santri aktif lain; null jika aman.
 */
function santri_validate_asrama_bed_unik(
    PDO $pdo,
    int $excludeSantriId,
    string $statusSantri,
    int $asramaRanjangId,
    string $namaKamar,
    string $noRanjang
): ?string {
    require_once __DIR__ . '/santri_status.php';
    if (!santri_status_is_di_pondok($statusSantri)) {
        return null;
    }
    if (!table_exists($pdo, 'santri')) {
        return null;
    }
    $nk = trim($namaKamar);
    $nr = trim($noRanjang);

    if ($asramaRanjangId > 0 && column_exists($pdo, 'santri', 'asrama_ranjang_id')) {
        $st = $pdo->prepare('
            SELECT s.nis, s.nama_santri FROM santri s
            WHERE s.id <> :excl
              AND COALESCE(s.is_aktif, 1) = 1
              AND UPPER(TRIM(COALESCE(NULLIF(s.status_santri, \'\'), \'AKTIF\'))) = \'AKTIF\'
              AND s.asrama_ranjang_id = :rid
            LIMIT 1
        ');
        $st->execute(['excl' => $excludeSantriId, 'rid' => $asramaRanjangId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return 'Ranjang master ini sudah dipakai santri aktif: ' . (string) ($row['nama_santri'] ?? '') . ' (NIS ' . (string) ($row['nis'] ?? '') . ').';
        }
    }

    if ($nk !== '' && $nr !== '') {
        $sql = '
            SELECT s.nis, s.nama_santri FROM santri s
            WHERE s.id <> :excl
              AND COALESCE(s.is_aktif, 1) = 1
              AND UPPER(TRIM(COALESCE(NULLIF(s.status_santri, \'\'), \'AKTIF\'))) = \'AKTIF\'
              AND TRIM(COALESCE(s.nama_kamar, \'\')) = :nk
              AND TRIM(COALESCE(s.no_ranjang, \'\')) = :nr
        ';
        if (column_exists($pdo, 'santri', 'asrama_ranjang_id')) {
            $sql .= ' AND (s.asrama_ranjang_id IS NULL OR s.asrama_ranjang_id = 0 OR s.asrama_ranjang_id <> :rid2)';
        }
        $st = $pdo->prepare($sql . ' LIMIT 1');
        $params = ['excl' => $excludeSantriId, 'nk' => $nk, 'nr' => $nr];
        if (column_exists($pdo, 'santri', 'asrama_ranjang_id')) {
            $params['rid2'] = $asramaRanjangId > 0 ? $asramaRanjangId : -1;
        }
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return 'Kombinasi kamar dan ranjang ini sudah dipakai santri aktif: ' . (string) ($row['nama_santri'] ?? '') . ' (NIS ' . (string) ($row['nis'] ?? '') . ').';
        }
    }

    return null;
}
