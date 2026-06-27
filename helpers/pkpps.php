<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/datetime_display.php';
require_once __DIR__ . '/akademik.php';

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

function pkpps_ensure_schema(PDO $pdo, bool $force = false): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!$force && !empty($_SESSION['pkpps_schema_ready_v1'])) {
        return;
    }

    $tablesReady = table_exists($pdo, 'pkpps_tingkatan')
        && table_exists($pdo, 'pkpps_santri')
        && table_exists($pdo, 'pkpps_jadwal');
    if (!$force && $tablesReady) {
        if (app_setting($pdo, 'pkpps_schema_ready_v1', '') !== '1') {
            save_setting($pdo, 'pkpps_schema_ready_v1', '1');
        }
        $_SESSION['pkpps_schema_ready_v1'] = 1;

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

    pkpps_sync_kegiatan_kategori($pdo);
    save_setting($pdo, 'pkpps_schema_ready_v1', '1');
    $_SESSION['pkpps_schema_ready_v1'] = 1;
}

/** Set kategori PKPPS pada kegiatan yang dipakai jadwal PKPPS. */
function pkpps_sync_kegiatan_kategori(PDO $pdo): void
{
    if (!table_exists($pdo, 'pkpps_jadwal') || !table_exists($pdo, 'kegiatan')) {
        return;
    }
    ensure_kegiatan_kategori_column($pdo);
    try {
        $pdo->exec('
            UPDATE kegiatan k
            INNER JOIN pkpps_jadwal j ON j.kegiatan_id = k.id
            SET k.kategori_kegiatan = "PKPPS"
            WHERE UPPER(COALESCE(k.kategori_kegiatan, "TAALIM")) <> "PKPPS"
        ');
    } catch (PDOException $e) {
        // abaikan
    }
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

/**
 * Santri aktif pusat yang belum PKPPS — untuk tambah massal.
 *
 * @return list<array{id:int,nama_santri:string,nis:string,tingkatan:string}>
 */
function pkpps_santri_bulk_candidates(PDO $pdo, string $tingkatanKajian = '', string $q = '', int $limit = 2000): array
{
    if (!table_exists($pdo, 'santri')) {
        return [];
    }
    require_once __DIR__ . '/santri_operasional.php';
    ensure_santri_identity_columns($pdo);
    $namaCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $aktifSql = santri_sql_aktif_only('s');
    $sql = '
        SELECT s.id, s.' . $namaCol . ' AS nama_santri, s.nis, s.tingkatan
        FROM santri s
        WHERE ' . $aktifSql . '
          AND s.id NOT IN (SELECT santri_id FROM pkpps_santri)
    ';
    $params = [];
    if ($tingkatanKajian !== '') {
        $sql .= ' AND TRIM(s.tingkatan) = :tk';
        $params['tk'] = $tingkatanKajian;
    }
    if ($q !== '') {
        $sql .= ' AND (s.' . $namaCol . ' LIKE :q OR s.nis LIKE :q OR s.qr LIKE :q)';
        $params['q'] = '%' . $q . '%';
    }
    $sql .= ' ORDER BY s.' . $namaCol . ' ASC LIMIT ' . max(1, min(5000, $limit));
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<int> */
function pkpps_santri_bulk_candidate_ids(PDO $pdo, string $tingkatanKajian = '', string $q = '', int $limit = 2000): array
{
    $ids = [];
    foreach (pkpps_santri_bulk_candidates($pdo, $tingkatanKajian, $q, $limit) as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }
    }

    return $ids;
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
    $kelasRows = kelas_keuangan_all_rows($pdo);
    usort($kelasRows, static function (array $a, array $b): int {
        $ua = (int) ($a['urutan'] ?? 0);
        $ub = (int) ($b['urutan'] ?? 0);
        if ($ua !== $ub) {
            return $ua <=> $ub;
        }

        return strcmp((string) ($a['nama_tampilan'] ?? ''), (string) ($b['nama_tampilan'] ?? ''));
    });

    $urut = 0;
    $seenLinkedIds = [];
    $validKelasIds = [];
    $ins = $pdo->prepare('
        INSERT INTO pkpps_tingkatan (urutan, kelas_keuangan_id, sub_level, nama_tingkatan, is_aktif)
        VALUES (:u, :kid, :sl, :n, :a)
    ');
    $upd = $pdo->prepare('
        UPDATE pkpps_tingkatan
        SET urutan = :u, nama_tingkatan = :n, kelas_keuangan_id = :kid, sub_level = :sl
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
            $kodeKk = strtoupper(trim((string) ($kr['kode'] ?? '')));
            if (function_exists('kelas_keuangan_is_sublevel_kode') && kelas_keuangan_is_sublevel_kode($kodeKk)) {
                continue;
            }
            $validKelasIds[$kid] = $kid;
            $baseNama = trim((string) ($kr['nama_tampilan'] ?? $kr['kode'] ?? ''));
            if ($baseNama === '') {
                continue;
            }
            $kelasAktif = (int) ($kr['is_aktif'] ?? 0) === 1 ? 1 : 0;
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
                    $upd->execute([
                        'u' => $urut,
                        'n' => $nama,
                        'kid' => $kid,
                        'sl' => $sl,
                        'id' => $existingId,
                    ]);
                    $seenLinkedIds[$existingId] = $existingId;
                } else {
                    $ins->execute([
                        'u' => $urut,
                        'kid' => $kid,
                        'sl' => $sl,
                        'n' => $nama,
                        'a' => $kelasAktif,
                    ]);
                    $newId = (int) $pdo->lastInsertId();
                    if ($newId > 0) {
                        $seenLinkedIds[$newId] = $newId;
                    }
                }
            }
        }

        // Nonaktifkan hanya baris yang terhubung ke kelas yang sudah dihapus.
        // Tingkatan manual (tanpa kelas_keuangan_id) dan pilihan aktif/nonaktif manual tidak disentuh.
        if ($validKelasIds !== []) {
            $inKelas = implode(',', array_map('intval', array_values($validKelasIds)));
            $pdo->exec(
                'UPDATE pkpps_tingkatan SET is_aktif = 0
                 WHERE kelas_keuangan_id IS NOT NULL AND kelas_keuangan_id NOT IN (' . $inKelas . ')'
            );
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
function pkpps_tingkatan_create(PDO $pdo, string $nama, int $urutan = 0, int $isAktif = 1): array
{
    pkpps_ensure_schema($pdo);
    $nama = mb_substr(trim($nama), 0, 120);
    if ($nama === '') {
        return ['ok' => false, 'message' => 'Nama tingkatan PKPPS wajib diisi.'];
    }
    $dup = $pdo->prepare('SELECT id FROM pkpps_tingkatan WHERE nama_tingkatan = :n LIMIT 1');
    $dup->execute(['n' => $nama]);
    if ($dup->fetch()) {
        return ['ok' => false, 'message' => 'Nama tingkatan PKPPS sudah ada.'];
    }
    if ($urutan <= 0) {
        $urutan = (int) $pdo->query('SELECT COALESCE(MAX(urutan), 0) + 1 FROM pkpps_tingkatan')->fetchColumn();
    }
    $pdo->prepare('INSERT INTO pkpps_tingkatan (nama_tingkatan, urutan, is_aktif) VALUES (:n, :u, :a)')
        ->execute(['n' => $nama, 'u' => $urutan, 'a' => $isAktif === 1 ? 1 : 0]);

    return ['ok' => true, 'message' => 'Tingkatan PKPPS ditambahkan.'];
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

/** Resolve kegiatan aktif PKPPS untuk santri pada tanggal & jam tertentu (mirip activity_for_tingkatan). */
function activity_for_pkpps_santri(PDO $pdo, int $santriId, string $date, string $time): ?array
{
    if ($santriId <= 0 || !table_exists($pdo, 'pkpps_jadwal') || !table_exists($pdo, 'kegiatan')) {
        return null;
    }
    $tingkatId = pkpps_tingkatan_id_for_santri($pdo, $santriId);
    if ($tingkatId <= 0) {
        return null;
    }
    ensure_kegiatan_kategori_column($pdo);
    $modeLibur = akademik_libur_presensi_mode_aktif_di_tanggal($pdo, $date);
    $kategoriFilter = $modeLibur !== null
        ? akademik_libur_presensi_filter_sql_by_mode($modeLibur, 'COALESCE(k.kategori_kegiatan, "TAALIM")')
        : '';
    $day = date('N', strtotime($date));
    $st = $pdo->prepare('
        SELECT k.id, k.nama_kegiatan, COALESCE(k.kategori_kegiatan, "TAALIM") AS kategori_kegiatan,
               j.id AS pkpps_jadwal_id, j.jam_mulai, j.jam_selesai, j.tempat,
               t.nama_tingkatan AS pkpps_tingkatan
        FROM pkpps_jadwal j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        INNER JOIN pkpps_tingkatan t ON t.id = j.pkpps_tingkatan_id
        WHERE j.pkpps_tingkatan_id = :tid
          AND j.is_aktif = 1
          AND (j.hari_ke = 0 OR j.hari_ke = :hari_ke)
          AND :jam_now BETWEEN j.jam_mulai AND j.jam_selesai
          AND k.is_active = 1
          ' . $kategoriFilter . '
        ORDER BY j.jam_mulai ASC
        LIMIT 1
    ');
    $st->execute([
        'tid' => $tingkatId,
        'hari_ke' => $day,
        'jam_now' => $time,
    ]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    $row['jadwal_kegiatan_id'] = null;
    $row['sumber'] = 'pkpps';

    return $row;
}

require_once __DIR__ . '/jadwal_jamaah_pembimbing.php';

/** Jadwal aktif pembimbing dari pkpps_jadwal atau jadwal_kegiatan pada jam sekarang. */
function jadwal_aktif_for_pembimbing(PDO $pdo, int $pembimbingId, string $date, string $time): ?array
{
    if ($pembimbingId <= 0) {
        return null;
    }
    ensure_kegiatan_kategori_column($pdo);
    ensure_jadwal_kegiatan_tempat($pdo);
    $modeLiburAktif = akademik_libur_presensi_mode_aktif_di_tanggal($pdo, $date);
    $kategoriFilterSql = $modeLiburAktif !== null
        ? akademik_libur_presensi_filter_sql_by_mode($modeLiburAktif, 'COALESCE(k.kategori_kegiatan, "TAALIM")')
        : '';
    $hariKe = (int) date('N', strtotime($date));

    if (table_exists($pdo, 'jadwal_kegiatan')) {
        $st = $pdo->prepare('
            SELECT j.kegiatan_id, k.nama_kegiatan, COALESCE(k.kategori_kegiatan, "TAALIM") AS kategori_kegiatan, j.tempat, "kajian" AS sumber
            FROM jadwal_kegiatan j
            INNER JOIN kegiatan k ON k.id = j.kegiatan_id
            WHERE j.pembimbing_id = :pembimbing_id
              AND COALESCE(k.kategori_kegiatan, "TAALIM") != "JAMAAH"
              AND (j.hari_ke = 0 OR j.hari_ke = :hari_ke)
              AND :jam_now BETWEEN j.jam_mulai AND j.jam_selesai
              AND k.is_active = 1
              ' . $kategoriFilterSql . '
            ORDER BY j.jam_mulai ASC
            LIMIT 1
        ');
        $st->execute([
            'pembimbing_id' => $pembimbingId,
            'hari_ke' => $hariKe,
            'jam_now' => $time,
        ]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }
    }

    if (table_exists($pdo, 'pkpps_jadwal')) {
        pkpps_ensure_schema($pdo);
        $stP = $pdo->prepare('
            SELECT j.kegiatan_id, k.nama_kegiatan, COALESCE(k.kategori_kegiatan, "TAALIM") AS kategori_kegiatan, j.tempat, "pkpps" AS sumber
            FROM pkpps_jadwal j
            INNER JOIN kegiatan k ON k.id = j.kegiatan_id
            WHERE j.pembimbing_id = :pembimbing_id
              AND j.is_aktif = 1
              AND (j.hari_ke = 0 OR j.hari_ke = :hari_ke)
              AND :jam_now BETWEEN j.jam_mulai AND j.jam_selesai
              AND k.is_active = 1
              ' . $kategoriFilterSql . '
            ORDER BY j.jam_mulai ASC
            LIMIT 1
        ');
        $stP->execute([
            'pembimbing_id' => $pembimbingId,
            'hari_ke' => $hariKe,
            'jam_now' => $time,
        ]);
        $rowP = $stP->fetch(PDO::FETCH_ASSOC);

        return is_array($rowP) ? $rowP : null;
    }

    return null;
}

/** @return list<array<string, mixed>> baris jadwal PKPPS hari ini untuk timer presensi. */
function pkpps_jadwal_slots_for_presensi_scan(PDO $pdo, string $tanggal, int $hariKe, ?string $modeLiburAktif): array
{
    unset($tanggal);
    if (!table_exists($pdo, 'pkpps_jadwal') || !table_exists($pdo, 'kegiatan')) {
        return [];
    }
    pkpps_ensure_schema($pdo);
    ensure_kegiatan_kategori_column($pdo);
    $kategoriFilterSql = $modeLiburAktif !== null
        ? akademik_libur_presensi_filter_sql_by_mode($modeLiburAktif, 'COALESCE(k.kategori_kegiatan, "TAALIM")')
        : '';
    $st = $pdo->prepare('
        SELECT k.id AS kegiatan_id, k.nama_kegiatan, j.jam_mulai, j.jam_selesai, j.tempat,
               CONCAT("PKPPS: ", t.nama_tingkatan) AS tingkatan
        FROM pkpps_jadwal j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        INNER JOIN pkpps_tingkatan t ON t.id = j.pkpps_tingkatan_id
        WHERE j.is_aktif = 1
          AND (j.hari_ke = 0 OR j.hari_ke = :hari_ke)
          AND k.is_active = 1
          ' . $kategoriFilterSql . '
        ORDER BY j.jam_mulai ASC, t.urutan ASC
    ');
    $st->execute(['hari_ke' => $hariKe]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function pkpps_dashboard_stats(PDO $pdo): array
{
    pkpps_ensure_schema($pdo);
    $stats = [
        'santri_aktif' => 0,
        'tingkatan_aktif' => 0,
        'jadwal_aktif' => 0,
        'pembimbing_jadwal' => 0,
    ];
    if (!table_exists($pdo, 'pkpps_santri')) {
        return $stats;
    }
    $row = $pdo->query('
        SELECT
            (SELECT COUNT(*) FROM pkpps_santri WHERE is_aktif = 1) AS santri_aktif,
            (SELECT COUNT(*) FROM pkpps_tingkatan WHERE is_aktif = 1) AS tingkatan_aktif,
            (SELECT COUNT(*) FROM pkpps_jadwal WHERE is_aktif = 1) AS jadwal_aktif,
            (SELECT COUNT(DISTINCT pembimbing_id) FROM pkpps_jadwal WHERE is_aktif = 1 AND pembimbing_id IS NOT NULL AND pembimbing_id > 0) AS pembimbing_jadwal
    ')->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        foreach ($stats as $k => $_) {
            $stats[$k] = (int) ($row[$k] ?? 0);
        }
    }

    return $stats;
}

/**
 * Rekap keaktivan santri PKPPS per tahun (agregat SQL — tanpa finalisasi massal).
 *
 * @return list<array<string, mixed>>
 */
function pkpps_rekap_keaktivan_santri_tahun(PDO $pdo, int $tahun, bool $useCache = true): array
{
    require_once __DIR__ . '/santri_operasional.php';
    require_once __DIR__ . '/rekap_keaktifan.php';

    $cacheKey = 'pkpps_keaktivan_tahun_' . $tahun;
    if ($useCache && isset($_SESSION[$cacheKey]) && is_array($_SESSION[$cacheKey])) {
        $cached = $_SESSION[$cacheKey];
        if (isset($cached['ts'], $cached['data']) && is_array($cached['data']) && (time() - (int) $cached['ts']) < 120) {
            return $cached['data'];
        }
    }

    pkpps_ensure_schema($pdo);
    if (!table_exists($pdo, 'pkpps_santri') || !table_exists($pdo, 'presensi')) {
        return [];
    }

    $today = date('Y-m-d');
    $dari = sprintf('%04d-01-01', $tahun);
    $sampai = sprintf('%04d-12-31', $tahun);
    if ($sampai > $today) {
        $sampai = $today;
    }
    $clamped = rekap_keaktifan_clamp_periode($pdo, $dari, $sampai);
    if ($clamped === null) {
        return [];
    }
    [$dari, $sampai] = $clamped;

    $goodMax = (int) app_setting($pdo, 'kategori_baik_max', '1');
    $mediumMax = (int) app_setting($pdo, 'kategori_sedang_max', '3');
    $aktifSql = santri_sql_aktif_only('s');
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $pkppsPresensiSql = table_exists($pdo, 'pkpps_jadwal')
        ? '(p.pkpps_jadwal_id IS NOT NULL OR p.kegiatan_id IN (SELECT DISTINCT kegiatan_id FROM pkpps_jadwal WHERE is_aktif = 1))'
        : '1=0';

    $st = $pdo->prepare('
        SELECT
            s.id AS santri_id,
            s.nis,
            s.' . $nameCol . ' AS nama_santri,
            t.nama_tingkatan AS pkpps_tingkatan,
            COALESCE(SUM(CASE WHEN p.status_presensi = "HADIR" THEN 1 ELSE 0 END), 0) AS hadir,
            COALESCE(SUM(CASE WHEN p.status_presensi = "IZIN" THEN 1 ELSE 0 END), 0) AS izin,
            COALESCE(SUM(CASE WHEN p.status_presensi = "SAKIT" THEN 1 ELSE 0 END), 0) AS sakit,
            COALESCE(SUM(CASE WHEN p.status_presensi = "ALPA" THEN 1 ELSE 0 END), 0) AS alpa,
            COALESCE(COUNT(p.id), 0) AS total
        FROM pkpps_santri ps
        INNER JOIN santri s ON s.id = ps.santri_id AND ' . $aktifSql . '
        INNER JOIN pkpps_tingkatan t ON t.id = ps.pkpps_tingkatan_id AND t.is_aktif = 1
        LEFT JOIN presensi p ON p.santri_id = s.id
            AND p.tanggal_presensi BETWEEN :dari AND :sampai
            AND ' . $pkppsPresensiSql . '
        WHERE ps.is_aktif = 1
        GROUP BY s.id, s.nis, s.' . $nameCol . ', t.nama_tingkatan, t.urutan
        ORDER BY t.urutan ASC, s.' . $nameCol . ' ASC
    ');
    $st->execute(['dari' => $dari, 'sampai' => $sampai]);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $total = (int) ($r['total'] ?? 0);
        $hadir = (int) ($r['hadir'] ?? 0);
        $alpa = (int) ($r['alpa'] ?? 0);
        $r['kategori'] = $total > 0 ? santri_category($alpa, $goodMax, $mediumMax) : '—';
        $r['persen'] = $total > 0 ? round($hadir / $total * 100, 1) : 0;
        $rows[] = $r;
    }

    if ($useCache) {
        $_SESSION[$cacheKey] = ['ts' => time(), 'data' => $rows];
    }

    return $rows;
}

/**
 * Rekap keaktivan PKPPS 7 hari terakhir (santri & pembimbing).
 *
 * @return array{
 *   dari:string,
 *   sampai:string,
 *   label:string,
 *   totals: array{hadir:int,izin:int,sakit:int,alpa:int,total:int},
 *   per_tingkatan: list<array{nama_tingkatan:string,hadir:int,izin:int,sakit:int,alpa:int,total:int}>,
 *   per_hari: list<array{tanggal:string,label:string,hadir:int,izin:int,sakit:int,alpa:int,total:int}>,
 *   pembimbing: array{total_hadir:int,pembimbing_hadir:int,rows:list<array<string,mixed>>}
 * }
 */
function pkpps_dashboard_keaktivan_minggu(PDO $pdo, ?string $sampai = null, bool $finalizePresensi = false): array
{
    require_once __DIR__ . '/santri_operasional.php';
    require_once __DIR__ . '/presensi_jadwal.php';
    require_once __DIR__ . '/rekap_pkpps_keaktifan_hari.php';

    $emptyTotals = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'total' => 0];
    $today = date('Y-m-d');
    $sampai = $sampai ?? $today;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) {
        $sampai = $today;
    }
    if ($sampai > $today) {
        $sampai = $today;
    }
    $dari = date('Y-m-d', strtotime($sampai . ' -6 days') ?: time());

    $label = app_format_tanggal_id($dari) . ' – ' . app_format_tanggal_id($sampai);

    $out = [
        'dari' => $dari,
        'sampai' => $sampai,
        'label' => $label,
        'totals' => $emptyTotals,
        'per_tingkatan' => [],
        'per_hari' => [],
        'pembimbing' => ['total_hadir' => 0, 'pembimbing_hadir' => 0, 'rows' => []],
    ];

    if ($dari > $sampai) {
        return $out;
    }

    $auditUserId = (int) ($_SESSION['user']['id'] ?? 1);
    if ($finalizePresensi) {
        presensi_finalize_date_range($pdo, $dari, $sampai, $auditUserId > 0 ? $auditUserId : 1);
    }
    ensure_presensi_pkpps_column($pdo);

    if (table_exists($pdo, 'pkpps_santri') && table_exists($pdo, 'presensi') && table_exists($pdo, 'pkpps_tingkatan')) {
        $aktifSql = santri_sql_aktif_only('s');
        $st = $pdo->prepare('
            SELECT
                t.nama_tingkatan,
                t.urutan,
                COALESCE(SUM(CASE WHEN p.status_presensi = "HADIR" THEN 1 ELSE 0 END), 0) AS hadir,
                COALESCE(SUM(CASE WHEN p.status_presensi = "IZIN" THEN 1 ELSE 0 END), 0) AS izin,
                COALESCE(SUM(CASE WHEN p.status_presensi = "SAKIT" THEN 1 ELSE 0 END), 0) AS sakit,
                COALESCE(SUM(CASE WHEN p.status_presensi = "ALPA" THEN 1 ELSE 0 END), 0) AS alpa,
                COALESCE(COUNT(p.id), 0) AS total
            FROM pkpps_santri ps
            INNER JOIN santri s ON s.id = ps.santri_id AND ' . $aktifSql . '
            INNER JOIN pkpps_tingkatan t ON t.id = ps.pkpps_tingkatan_id AND t.is_aktif = 1
            LEFT JOIN presensi p ON p.santri_id = s.id
                AND p.tanggal_presensi BETWEEN :dari AND :sampai
            WHERE ps.is_aktif = 1
            GROUP BY t.id, t.nama_tingkatan, t.urutan
            ORDER BY t.urutan ASC, t.nama_tingkatan ASC
        ');
        $st->execute(['dari' => $dari, 'sampai' => $sampai]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $row = [
                'nama_tingkatan' => (string) ($r['nama_tingkatan'] ?? ''),
                'hadir' => (int) ($r['hadir'] ?? 0),
                'izin' => (int) ($r['izin'] ?? 0),
                'sakit' => (int) ($r['sakit'] ?? 0),
                'alpa' => (int) ($r['alpa'] ?? 0),
                'total' => (int) ($r['total'] ?? 0),
            ];
            $out['per_tingkatan'][] = $row;
            foreach (['hadir', 'izin', 'sakit', 'alpa', 'total'] as $k) {
                $out['totals'][$k] += $row[$k];
            }
        }

        $stHari = $pdo->prepare('
            SELECT
                p.tanggal_presensi AS tanggal,
                COALESCE(SUM(CASE WHEN p.status_presensi = "HADIR" THEN 1 ELSE 0 END), 0) AS hadir,
                COALESCE(SUM(CASE WHEN p.status_presensi = "IZIN" THEN 1 ELSE 0 END), 0) AS izin,
                COALESCE(SUM(CASE WHEN p.status_presensi = "SAKIT" THEN 1 ELSE 0 END), 0) AS sakit,
                COALESCE(SUM(CASE WHEN p.status_presensi = "ALPA" THEN 1 ELSE 0 END), 0) AS alpa,
                COALESCE(COUNT(p.id), 0) AS total
            FROM presensi p
            INNER JOIN pkpps_santri ps ON ps.santri_id = p.santri_id AND ps.is_aktif = 1
            INNER JOIN santri s ON s.id = ps.santri_id AND ' . $aktifSql . '
            WHERE p.tanggal_presensi BETWEEN :dari AND :sampai
            GROUP BY p.tanggal_presensi
            ORDER BY p.tanggal_presensi ASC
        ');
        $stHari->execute(['dari' => $dari, 'sampai' => $sampai]);
        $byDay = [];
        foreach ($stHari->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $tgl = (string) ($r['tanggal'] ?? '');
            if ($tgl === '') {
                continue;
            }
            $byDay[$tgl] = [
                'tanggal' => $tgl,
                'label' => app_format_tanggal_id($tgl),
                'hadir' => (int) ($r['hadir'] ?? 0),
                'izin' => (int) ($r['izin'] ?? 0),
                'sakit' => (int) ($r['sakit'] ?? 0),
                'alpa' => (int) ($r['alpa'] ?? 0),
                'total' => (int) ($r['total'] ?? 0),
            ];
        }
        for ($ts = strtotime($dari) ?: time(); $ts <= (strtotime($sampai) ?: time()); $ts += 86400) {
            $tgl = date('Y-m-d', $ts);
            $out['per_hari'][] = $byDay[$tgl] ?? [
                'tanggal' => $tgl,
                'label' => app_format_tanggal_id($tgl),
                'hadir' => 0,
                'izin' => 0,
                'sakit' => 0,
                'alpa' => 0,
                'total' => 0,
            ];
        }
    }

    if (table_exists($pdo, 'pkpps_jadwal') && table_exists($pdo, 'presensi_pembimbing')) {
        require_once __DIR__ . '/entity_list_sort.php';
        $stPb = $pdo->prepare('
            SELECT
                b.id,
                b.nama_pembimbing,
                COUNT(pp.id) AS total_hadir,
                COUNT(DISTINCT pp.tanggal) AS hari_hadir
            FROM pembimbing b
            INNER JOIN pkpps_jadwal j ON j.pembimbing_id = b.id AND j.is_aktif = 1
            LEFT JOIN presensi_pembimbing pp
              ON pp.pembimbing_id = b.id
             AND pp.tanggal BETWEEN :dari AND :sampai
            GROUP BY b.id, b.nama_pembimbing
            HAVING total_hadir > 0
            ORDER BY total_hadir DESC, ' . pembimbing_list_order_sql('b') . '
            LIMIT 8
        ');
        $stPb->execute(['dari' => $dari, 'sampai' => $sampai]);
        $pbRows = $stPb->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $totalHadir = 0;
        foreach ($pbRows as $r) {
            $totalHadir += (int) ($r['total_hadir'] ?? 0);
        }
        $out['pembimbing'] = [
            'total_hadir' => $totalHadir,
            'pembimbing_hadir' => count($pbRows),
            'rows' => $pbRows,
        ];
    }

    return $out;
}

/** Cache ringkas keaktivan minggu agar dashboard PKPPS cepat dibuka. */
function pkpps_dashboard_keaktivan_minggu_cached(PDO $pdo, int $ttlSeconds = 180): array
{
    $today = date('Y-m-d');
    $cacheKey = 'pkpps_dash_minggu_' . $today;
    $cached = $_SESSION[$cacheKey] ?? null;
    if (is_array($cached) && isset($cached['ts'], $cached['data']) && (time() - (int) $cached['ts']) < $ttlSeconds) {
        return $cached['data'];
    }
    $data = pkpps_dashboard_keaktivan_minggu($pdo, $today, false);
    $_SESSION[$cacheKey] = ['ts' => time(), 'data' => $data];

    return $data;
}
