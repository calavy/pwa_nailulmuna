<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/** @return list<string> */
function pkpps_default_tingkatan_names(): array
{
    return [
        'PKPPS Tingkat 1',
        'PKPPS Tingkat 2',
        'PKPPS Tingkat 3',
        'PKPPS Tingkat 4',
        'PKPPS Tingkat 5',
        'PKPPS Tingkat 6',
    ];
}

function pkpps_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS pkpps_tingkatan (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            urutan SMALLINT UNSIGNED NOT NULL,
            kelas_keuangan_id INT UNSIGNED NULL,
            sub_level TINYINT UNSIGNED NOT NULL DEFAULT 0,
            nama_tingkatan VARCHAR(80) NOT NULL,
            is_aktif TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_pkpps_tingkatan_urutan (urutan),
            UNIQUE KEY uniq_pkpps_tingkatan_nama (nama_tingkatan),
            KEY idx_pkpps_kelas_keu (kelas_keuangan_id, sub_level)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
    try {
        $pdo->exec('ALTER TABLE pkpps_tingkatan MODIFY urutan SMALLINT UNSIGNED NOT NULL');
    } catch (Throwable $e) {
        // abaikan jika sudah SMALLINT
    }
    if (!column_exists($pdo, 'pkpps_tingkatan', 'kelas_keuangan_id')) {
        try {
            $pdo->exec('ALTER TABLE pkpps_tingkatan ADD COLUMN kelas_keuangan_id INT UNSIGNED NULL AFTER urutan');
        } catch (Throwable $e) {
        }
    }
    if (!column_exists($pdo, 'pkpps_tingkatan', 'sub_level')) {
        try {
            $pdo->exec('ALTER TABLE pkpps_tingkatan ADD COLUMN sub_level TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER kelas_keuangan_id');
        } catch (Throwable $e) {
        }
    }

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS pkpps_santri (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            santri_id INT UNSIGNED NOT NULL,
            pkpps_tingkatan_id INT UNSIGNED NOT NULL,
            tahun_masehi SMALLINT UNSIGNED NULL,
            is_aktif TINYINT(1) NOT NULL DEFAULT 1,
            catatan VARCHAR(255) NOT NULL DEFAULT \'\',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_pkpps_santri (santri_id),
            KEY idx_pkpps_tingkatan (pkpps_tingkatan_id, is_aktif)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS pkpps_jadwal (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            pkpps_tingkatan_id INT UNSIGNED NOT NULL,
            kegiatan_id INT UNSIGNED NOT NULL,
            hari_ke TINYINT NOT NULL DEFAULT 0,
            jam_mulai TIME NOT NULL,
            jam_selesai TIME NOT NULL,
            pembimbing_id INT UNSIGNED NULL,
            tempat VARCHAR(255) NULL,
            is_aktif TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_pkpps_jadwal_tingkatan (pkpps_tingkatan_id, hari_ke)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');

    $count = (int) $pdo->query('SELECT COUNT(*) FROM pkpps_tingkatan')->fetchColumn();
    if ($count === 0) {
        $ins = $pdo->prepare('INSERT INTO pkpps_tingkatan (urutan, nama_tingkatan, is_aktif) VALUES (:u, :n, 1)');
        foreach (pkpps_default_tingkatan_names() as $i => $nama) {
            $ins->execute(['u' => $i + 1, 'n' => $nama]);
        }
    }

    $done = true;
}

/** @return list<array<string, mixed>> */
function pkpps_tingkatan_list(PDO $pdo, bool $aktifOnly = false): array
{
    pkpps_ensure_schema($pdo);
    $sql = 'SELECT id, urutan, nama_tingkatan, is_aktif FROM pkpps_tingkatan';
    if ($aktifOnly) {
        $sql .= ' WHERE is_aktif = 1';
    }
    $sql .= ' ORDER BY urutan ASC';

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pkpps_tingkatan_by_id(PDO $pdo, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    pkpps_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT id, urutan, nama_tingkatan, is_aktif FROM pkpps_tingkatan WHERE id = :id LIMIT 1');
    $st->execute(['id' => $id]);

    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/** Nama tingkatan PKPPS untuk santri (kosong jika bukan anggota PKPPS). */
function pkpps_tingkatan_nama_for_santri(PDO $pdo, int $santriId): string
{
    if ($santriId <= 0 || !table_exists($pdo, 'pkpps_santri')) {
        return '';
    }
    pkpps_ensure_schema($pdo);
    $st = $pdo->prepare('
        SELECT t.nama_tingkatan
        FROM pkpps_santri ps
        INNER JOIN pkpps_tingkatan t ON t.id = ps.pkpps_tingkatan_id
        WHERE ps.santri_id = :sid AND ps.is_aktif = 1 AND t.is_aktif = 1
        LIMIT 1
    ');
    $st->execute(['sid' => $santriId]);

    return trim((string) ($st->fetchColumn() ?: ''));
}

/** ID tingkatan PKPPS aktif untuk santri (0 jika bukan anggota). */
function pkpps_tingkatan_id_for_santri(PDO $pdo, int $santriId): int
{
    if ($santriId <= 0 || !table_exists($pdo, 'pkpps_santri')) {
        return 0;
    }
    pkpps_ensure_schema($pdo);
    $st = $pdo->prepare('
        SELECT ps.pkpps_tingkatan_id
        FROM pkpps_santri ps
        INNER JOIN pkpps_tingkatan t ON t.id = ps.pkpps_tingkatan_id
        WHERE ps.santri_id = :sid AND ps.is_aktif = 1 AND t.is_aktif = 1
        LIMIT 1
    ');
    $st->execute(['sid' => $santriId]);

    return max(0, (int) ($st->fetchColumn() ?: 0));
}

/** Geser urutan sementara (unik per baris) sebelum assign urutan final 1..N. */
function pkpps_stage_urutan_temp(PDO $pdo): void
{
    $ids = $pdo->query('SELECT id FROM pkpps_tingkatan ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    if ($ids === []) {
        return;
    }
    $upd = $pdo->prepare('UPDATE pkpps_tingkatan SET urutan = :u WHERE id = :id');
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id <= 0) {
            continue;
        }
        // id + 10000 aman setelah urutan di-upgrade ke SMALLINT UNSIGNED
        $upd->execute(['u' => $id + 10000, 'id' => $id]);
    }
}

/**
 * Selaraskan tingkatan PKPPS dari master kelas keuangan aktif.
 * Setiap kelas keuangan aktif menghasilkan 3 sub-tingkatan (… 1, 2, 3).
 */
function pkpps_sync_from_kelas_keuangan(PDO $pdo, int $subLevels = 3): void
{
    pkpps_ensure_schema($pdo);
    if (!function_exists('kelas_keuangan_all_rows')) {
        require_once __DIR__ . '/app.php';
    }
    if (!table_exists($pdo, 'kelas_keuangan')) {
        return;
    }
    $subLevels = max(1, min(6, $subLevels));
    $kelasRows = array_values(array_filter(
        kelas_keuangan_all_rows($pdo),
        static fn (array $r): bool => (int) ($r['is_aktif'] ?? 0) === 1
    ));
    usort($kelasRows, static function (array $a, array $b): int {
        $ua = (int) ($a['urutan'] ?? 0);
        $ub = (int) ($b['urutan'] ?? 0);
        if ($ua !== $ub) {
            return $ua <=> $ub;
        }

        return strcmp((string) ($a['nama_tampilan'] ?? ''), (string) ($b['nama_tampilan'] ?? ''));
    });

    $urut = 0;
    $seenIds = [];
    $ins = $pdo->prepare('
        INSERT INTO pkpps_tingkatan (urutan, kelas_keuangan_id, sub_level, nama_tingkatan, is_aktif)
        VALUES (:u, :kid, :sl, :n, 1)
    ');
    $upd = $pdo->prepare('
        UPDATE pkpps_tingkatan
        SET urutan = :u, nama_tingkatan = :n, is_aktif = 1, kelas_keuangan_id = :kid, sub_level = :sl
        WHERE id = :id
    ');
    $findLinked = $pdo->prepare('
        SELECT id FROM pkpps_tingkatan
        WHERE kelas_keuangan_id = :kid AND sub_level = :sl
        LIMIT 1
    ');
    $findByName = $pdo->prepare('
        SELECT id FROM pkpps_tingkatan
        WHERE nama_tingkatan = :n
        LIMIT 1
    ');

    try {
        $pdo->beginTransaction();

        // Geser urutan lama agar tidak bentrok dengan UNIQUE(urutan) saat insert baru.
        pkpps_stage_urutan_temp($pdo);

        foreach ($kelasRows as $kr) {
            $kid = (int) ($kr['id'] ?? 0);
            if ($kid <= 0) {
                continue;
            }
            $baseNama = trim((string) ($kr['nama_tampilan'] ?? $kr['kode'] ?? ''));
            if ($baseNama === '') {
                continue;
            }
            for ($sl = 1; $sl <= $subLevels; $sl++) {
                $urut++;
                $nama = $baseNama . ' ' . $sl;
                $existingId = 0;

                $findLinked->execute(['kid' => $kid, 'sl' => $sl]);
                $existingId = (int) ($findLinked->fetchColumn() ?: 0);

                if ($existingId <= 0) {
                    $findByName->execute(['n' => $nama]);
                    $existingId = (int) ($findByName->fetchColumn() ?: 0);
                }

                if ($existingId > 0) {
                    $upd->execute(['u' => $urut, 'n' => $nama, 'kid' => $kid, 'sl' => $sl, 'id' => $existingId]);
                    $seenIds[$existingId] = $existingId;
                } else {
                    $ins->execute(['u' => $urut, 'kid' => $kid, 'sl' => $sl, 'n' => $nama]);
                    $newId = (int) $pdo->lastInsertId();
                    if ($newId > 0) {
                        $seenIds[$newId] = $newId;
                    }
                }
            }
        }

        if ($seenIds !== []) {
            $in = implode(',', array_map('intval', array_values($seenIds)));
            $pdo->exec('UPDATE pkpps_tingkatan SET is_aktif = 0 WHERE id NOT IN (' . $in . ')');
        } else {
            $pdo->exec('UPDATE pkpps_tingkatan SET is_aktif = 0');
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * @return array{ok:bool,message:string}
 */
function pkpps_tingkatan_update(PDO $pdo, int $id, string $nama, int $urutan, int $isAktif): array
{
    pkpps_ensure_schema($pdo);
    if ($id <= 0 || trim($nama) === '') {
        return ['ok' => false, 'message' => 'Data tidak valid.'];
    }
    $nama = mb_substr(trim($nama), 0, 120);
    $dup = $pdo->prepare('SELECT id FROM pkpps_tingkatan WHERE nama_tingkatan = :n AND id <> :id LIMIT 1');
    $dup->execute(['n' => $nama, 'id' => $id]);
    if ($dup->fetch()) {
        return ['ok' => false, 'message' => 'Nama tingkatan PKPPS sudah dipakai.'];
    }
    if ($urutan > 0) {
        $pdo->prepare('UPDATE pkpps_tingkatan SET urutan = :u, nama_tingkatan = :n, is_aktif = :a WHERE id = :id')
            ->execute(['u' => $urutan, 'n' => $nama, 'a' => $isAktif, 'id' => $id]);
    } else {
        $pdo->prepare('UPDATE pkpps_tingkatan SET nama_tingkatan = :n, is_aktif = :a WHERE id = :id')
            ->execute(['n' => $nama, 'a' => $isAktif, 'id' => $id]);
    }

    return ['ok' => true, 'message' => 'Tingkatan PKPPS diperbarui.'];
}

/**
 * @return array{ok:bool,message:string}
 */
function pkpps_tingkatan_delete(PDO $pdo, int $id): array
{
    pkpps_ensure_schema($pdo);
    if ($id <= 0) {
        return ['ok' => false, 'message' => 'ID tidak valid.'];
    }
    if (table_exists($pdo, 'pkpps_santri')) {
        $c = $pdo->prepare('SELECT COUNT(*) FROM pkpps_santri WHERE pkpps_tingkatan_id = :id');
        $c->execute(['id' => $id]);
        if ((int) $c->fetchColumn() > 0) {
            return ['ok' => false, 'message' => 'Tidak dapat dihapus: masih dipakai data santri PKPPS. Nonaktifkan saja.'];
        }
    }
    if (table_exists($pdo, 'pkpps_jadwal')) {
        $c = $pdo->prepare('SELECT COUNT(*) FROM pkpps_jadwal WHERE pkpps_tingkatan_id = :id');
        $c->execute(['id' => $id]);
        if ((int) $c->fetchColumn() > 0) {
            return ['ok' => false, 'message' => 'Tidak dapat dihapus: masih dipakai jadwal PKPPS.'];
        }
    }
    $pdo->prepare('DELETE FROM pkpps_tingkatan WHERE id = :id')->execute(['id' => $id]);

    return ['ok' => true, 'message' => 'Tingkatan PKPPS dihapus.'];
}
