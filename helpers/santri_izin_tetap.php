<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

function ensure_santri_izin_tetap_tables(PDO $pdo): void
{
    ensure_santri_identity_columns($pdo);
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS santri_izin_tetap (
            id INT AUTO_INCREMENT PRIMARY KEY,
            santri_id INT NOT NULL,
            judul VARCHAR(120) NOT NULL DEFAULT "Hidmah",
            jenis ENUM("HIDMAH","TUGAS") NOT NULL DEFAULT "HIDMAH",
            tanggal_mulai DATE NOT NULL,
            tanggal_selesai DATE NULL,
            is_aktif TINYINT(1) NOT NULL DEFAULT 1,
            tanpa_cetak TINYINT(1) NOT NULL DEFAULT 1,
            keterangan TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_izin_tetap_santri (santri_id),
            INDEX idx_izin_tetap_aktif (is_aktif)
        )
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS santri_izin_tetap_slot (
            id INT AUTO_INCREMENT PRIMARY KEY,
            izin_tetap_id INT NOT NULL,
            hari_ke TINYINT NOT NULL COMMENT "1=Senin ... 7=Minggu",
            jam_mulai TIME NOT NULL,
            jam_selesai TIME NOT NULL,
            INDEX idx_slot_izin (izin_tetap_id, hari_ke)
        )
    ');
    if (table_exists($pdo, 'santri_izin_tetap') && !column_exists($pdo, 'santri_izin_tetap', 'nomor_surat')) {
        try {
            $pdo->exec('ALTER TABLE santri_izin_tetap ADD COLUMN nomor_surat VARCHAR(180) NULL AFTER keterangan');
        } catch (PDOException $e) {
        }
    }
    if (table_exists($pdo, 'santri_izin_tetap') && !column_exists($pdo, 'santri_izin_tetap', 'penanggung_jawab')) {
        try {
            $pdo->exec('ALTER TABLE santri_izin_tetap ADD COLUMN penanggung_jawab VARCHAR(120) NULL AFTER nomor_surat');
        } catch (PDOException $e) {
        }
    }
}

/** @return array<int, string> */
function santri_izin_tetap_hari_map(): array
{
    return [
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu',
        7 => 'Minggu',
    ];
}

function santri_izin_tetap_jenis_label(string $jenis): string
{
    return match (strtoupper($jenis)) {
        'TUGAS' => 'Tugas',
        default => 'Hidmah',
    };
}

/**
 * @return list<array<string, mixed>>
 */
function santri_izin_tetap_list(PDO $pdo, string $q = '', bool $hanyaAktif = false): array
{
    ensure_santri_izin_tetap_tables($pdo);
    if (!table_exists($pdo, 'santri')) {
        return [];
    }

    $nameExpr = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $sql = "
        SELECT i.*, s.nis, {$nameExpr} AS nama_santri
        FROM santri_izin_tetap i
        INNER JOIN santri s ON s.id = i.santri_id
        WHERE 1=1
    ";
    if ($hanyaAktif) {
        $sql .= ' AND i.is_aktif = 1 ';
    }
    $sql .= ' ORDER BY i.is_aktif DESC, i.tanggal_mulai DESC, s.' . (column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama') . ' ASC';

    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($q === '') {
        return $rows;
    }
    $needle = strtolower($q);
    $out = [];
    foreach ($rows as $r) {
        $hay = strtolower((string) ($r['nama_santri'] ?? '') . ' ' . (string) ($r['nis'] ?? '') . ' ' . (string) ($r['judul'] ?? ''));
        if (str_contains($hay, $needle)) {
            $out[] = $r;
        }
    }

    return $out;
}

/**
 * @return list<array<string, mixed>>
 */
function santri_izin_tetap_slots(PDO $pdo, int $izinTetapId): array
{
    ensure_santri_izin_tetap_tables($pdo);
    if ($izinTetapId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare('
        SELECT id, izin_tetap_id, hari_ke, jam_mulai, jam_selesai
        FROM santri_izin_tetap_slot
        WHERE izin_tetap_id = :id
        ORDER BY hari_ke ASC, jam_mulai ASC
    ');
    $stmt->execute(['id' => $izinTetapId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array<string, mixed>|null
 */
function santri_izin_tetap_by_id(PDO $pdo, int $id): ?array
{
    ensure_santri_izin_tetap_tables($pdo);
    if ($id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM santri_izin_tetap WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/** Cek overlap dua rentang waktu (HH:MM:SS). */
function santri_izin_tetap_waktu_overlap(string $mulaiA, string $selesaiA, string $mulaiB, string $selesaiB): bool
{
    $a1 = strtotime('1970-01-01 ' . substr($mulaiA, 0, 8));
    $a2 = strtotime('1970-01-01 ' . substr($selesaiA, 0, 8));
    $b1 = strtotime('1970-01-01 ' . substr($mulaiB, 0, 8));
    $b2 = strtotime('1970-01-01 ' . substr($selesaiB, 0, 8));
    if ($a1 === false || $a2 === false || $b1 === false || $b2 === false) {
        return false;
    }

    return $a1 < $b2 && $b1 < $a2;
}

/**
 * Apakah santri punya izin tetap yang berlaku pada tanggal (dan opsional rentang jam kegiatan)?
 */
function santri_izin_tetap_berlaku(
    PDO $pdo,
    int $santriId,
    string $tanggal,
    ?string $jamMulaiKegiatan = null,
    ?string $jamSelesaiKegiatan = null
): bool {
    if ($santriId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return false;
    }
    ensure_santri_izin_tetap_tables($pdo);
    if (!table_exists($pdo, 'santri_izin_tetap')) {
        return false;
    }

    $hariKe = (int) date('N', strtotime($tanggal));
    $stmt = $pdo->prepare('
        SELECT i.id
        FROM santri_izin_tetap i
        INNER JOIN santri_izin_tetap_slot sl ON sl.izin_tetap_id = i.id
        WHERE i.santri_id = :sid
          AND i.is_aktif = 1
          AND i.tanggal_mulai <= :tgl
          AND (i.tanggal_selesai IS NULL OR i.tanggal_selesai >= :tgl)
          AND sl.hari_ke = :hari
    ');
    $stmt->execute([
        'sid' => $santriId,
        'tgl' => $tanggal,
        'hari' => $hariKe,
    ]);
    $izinIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    if ($izinIds === []) {
        return false;
    }

    if ($jamMulaiKegiatan === null && $jamSelesaiKegiatan === null) {
        return true;
    }

    $jamMulaiKegiatan = $jamMulaiKegiatan ?? '00:00:00';
    $jamSelesaiKegiatan = $jamSelesaiKegiatan ?? '23:59:59';

    foreach ($izinIds as $izinId) {
        foreach (santri_izin_tetap_slots($pdo, $izinId) as $slot) {
            if ((int) ($slot['hari_ke'] ?? 0) !== $hariKe) {
                continue;
            }
            if (santri_izin_tetap_waktu_overlap(
                (string) $slot['jam_mulai'],
                (string) $slot['jam_selesai'],
                $jamMulaiKegiatan,
                $jamSelesaiKegiatan
            )) {
                return true;
            }
        }
    }

    return false;
}

/**
 * ID santri yang izin tetapnya berlaku pada tanggal (untuk generate alpa / sinkron presensi).
 *
 * @return list<int>
 */
function santri_izin_tetap_santri_ids_pada_tanggal(
    PDO $pdo,
    string $tanggal,
    ?string $jamMulaiKegiatan = null,
    ?string $jamSelesaiKegiatan = null
): array {
    ensure_santri_izin_tetap_tables($pdo);
    if (!table_exists($pdo, 'santri') || !table_exists($pdo, 'santri_izin_tetap')) {
        return [];
    }

    $aktifSql = column_exists($pdo, 'santri', 'is_aktif') ? ' AND COALESCE(s.is_aktif, 1) = 1 ' : '';
    $rows = $pdo->query('SELECT s.id FROM santri s WHERE 1=1 ' . $aktifSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $sid = (int) ($r['id'] ?? 0);
        if ($sid > 0 && santri_izin_tetap_berlaku($pdo, $sid, $tanggal, $jamMulaiKegiatan, $jamSelesaiKegiatan)) {
            $out[] = $sid;
        }
    }

    return $out;
}

/**
 * @param array<int, array{hari_ke:int,jam_mulai:string,jam_selesai:string}> $slots
 * @return array{ok:bool,message:string,id?:int}
 */
function santri_izin_tetap_simpan(PDO $pdo, array $post, array $slots, int $userId): array
{
    ensure_santri_izin_tetap_tables($pdo);

    $id = (int) ($post['id'] ?? 0);
    $santriId = (int) ($post['santri_id'] ?? 0);
    $judul = trim((string) ($post['judul'] ?? 'Hidmah'));
    $jenis = strtoupper(trim((string) ($post['jenis'] ?? 'HIDMAH')));
    $tglMulai = trim((string) ($post['tanggal_mulai'] ?? ''));
    $tglSelesai = trim((string) ($post['tanggal_selesai'] ?? ''));
    $keterangan = trim((string) ($post['keterangan'] ?? ''));
    $penanggungJawab = trim((string) ($post['penanggung_jawab'] ?? ''));
    $isAktif = isset($post['is_aktif']) ? 1 : 0;

    if ($santriId <= 0) {
        return ['ok' => false, 'message' => 'Santri wajib dipilih.'];
    }
    if ($judul === '') {
        return ['ok' => false, 'message' => 'Judul/keterangan singkat wajib diisi.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglMulai)) {
        return ['ok' => false, 'message' => 'Tanggal mulai tidak valid.'];
    }
    if ($tglSelesai !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglSelesai)) {
        return ['ok' => false, 'message' => 'Tanggal selesai tidak valid.'];
    }
    if ($tglSelesai !== '' && $tglSelesai < $tglMulai) {
        return ['ok' => false, 'message' => 'Tanggal selesai harus setelah tanggal mulai.'];
    }
    if (!in_array($jenis, ['HIDMAH', 'TUGAS'], true)) {
        $jenis = 'HIDMAH';
    }
    if ($slots === []) {
        return ['ok' => false, 'message' => 'Minimal satu jadwal hari & jam wajib diisi.'];
    }

    $chk = $pdo->prepare('SELECT id FROM santri WHERE id = :id LIMIT 1');
    $chk->execute(['id' => $santriId]);
    if (!$chk->fetch()) {
        return ['ok' => false, 'message' => 'Santri tidak ditemukan.'];
    }

    if ($id > 0) {
        $upd = $pdo->prepare('
            UPDATE santri_izin_tetap
            SET santri_id = :sid, judul = :judul, jenis = :jenis,
                tanggal_mulai = :mulai, tanggal_selesai = :selesai,
                is_aktif = :aktif, keterangan = :ket, penanggung_jawab = :pj, updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ');
        $upd->execute([
            'sid' => $santriId,
            'judul' => $judul,
            'jenis' => $jenis,
            'mulai' => $tglMulai,
            'selesai' => $tglSelesai !== '' ? $tglSelesai : null,
            'aktif' => $isAktif,
            'ket' => $keterangan !== '' ? $keterangan : null,
            'pj' => $penanggungJawab !== '' ? $penanggungJawab : null,
            'id' => $id,
        ]);
        $pdo->prepare('DELETE FROM santri_izin_tetap_slot WHERE izin_tetap_id = :id')->execute(['id' => $id]);
    } else {
        $ins = $pdo->prepare('
            INSERT INTO santri_izin_tetap
                (santri_id, judul, jenis, tanggal_mulai, tanggal_selesai, is_aktif, tanpa_cetak, keterangan, penanggung_jawab, created_by)
            VALUES
                (:sid, :judul, :jenis, :mulai, :selesai, :aktif, 0, :ket, :pj, :uid)
        ');
        $ins->execute([
            'sid' => $santriId,
            'judul' => $judul,
            'jenis' => $jenis,
            'mulai' => $tglMulai,
            'selesai' => $tglSelesai !== '' ? $tglSelesai : null,
            'aktif' => $isAktif,
            'ket' => $keterangan !== '' ? $keterangan : null,
            'pj' => $penanggungJawab !== '' ? $penanggungJawab : null,
            'uid' => $userId > 0 ? $userId : null,
        ]);
        $id = (int) $pdo->lastInsertId();
    }

    $slotIns = $pdo->prepare('
        INSERT INTO santri_izin_tetap_slot (izin_tetap_id, hari_ke, jam_mulai, jam_selesai)
        VALUES (:iid, :hari, :jm, :js)
    ');
    foreach ($slots as $slot) {
        $hari = (int) ($slot['hari_ke'] ?? 0);
        $jm = trim((string) ($slot['jam_mulai'] ?? ''));
        $js = trim((string) ($slot['jam_selesai'] ?? ''));
        if ($hari < 1 || $hari > 7 || $jm === '' || $js === '') {
            continue;
        }
        if (strlen($jm) === 5) {
            $jm .= ':00';
        }
        if (strlen($js) === 5) {
            $js .= ':00';
        }
        if ($jm >= $js) {
            return ['ok' => false, 'message' => 'Jam selesai harus setelah jam mulai pada setiap baris jadwal.'];
        }
        $slotIns->execute([
            'iid' => $id,
            'hari' => $hari,
            'jm' => $jm,
            'js' => $js,
        ]);
    }

    return ['ok' => true, 'message' => 'Izin tetap disimpan. Surat dapat dicetak; presensi mengikuti jadwal ini.', 'id' => $id];
}

/**
 * @return array{ok:bool,message:string}
 */
function santri_izin_tetap_set_aktif(PDO $pdo, int $id, bool $aktif): array
{
    ensure_santri_izin_tetap_tables($pdo);
    if ($id <= 0) {
        return ['ok' => false, 'message' => 'Data tidak valid.'];
    }
    $pdo->prepare('UPDATE santri_izin_tetap SET is_aktif = :a, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
        ->execute(['a' => $aktif ? 1 : 0, 'id' => $id]);

    return [
        'ok' => true,
        'message' => $aktif ? 'Izin tetap diaktifkan kembali.' : 'Izin tetap dihentikan sementara.',
    ];
}

/**
 * @return array{ok:bool,message:string}
 */
function santri_izin_tetap_hapus(PDO $pdo, int $id): array
{
    ensure_santri_izin_tetap_tables($pdo);
    if ($id <= 0) {
        return ['ok' => false, 'message' => 'Data tidak valid.'];
    }
    $pdo->prepare('DELETE FROM santri_izin_tetap_slot WHERE izin_tetap_id = :id')->execute(['id' => $id]);
    $pdo->prepare('DELETE FROM santri_izin_tetap WHERE id = :id')->execute(['id' => $id]);

    return ['ok' => true, 'message' => 'Izin tetap dihapus.'];
}

/** Parse slot dari POST form (hari_ke[], jam_mulai[], jam_selesai[]). */
function santri_izin_tetap_slots_dari_post(array $post): array
{
    $hariList = $post['hari_ke'] ?? [];
    $mulaiList = $post['jam_mulai'] ?? [];
    $selesaiList = $post['jam_selesai'] ?? [];
    if (!is_array($hariList)) {
        return [];
    }
    $slots = [];
    foreach ($hariList as $i => $hariRaw) {
        $hari = (int) $hariRaw;
        $jm = trim((string) ($mulaiList[$i] ?? ''));
        $js = trim((string) ($selesaiList[$i] ?? ''));
        if ($hari < 1 || $hari > 7 || $jm === '' || $js === '') {
            continue;
        }
        $slots[] = ['hari_ke' => $hari, 'jam_mulai' => $jm, 'jam_selesai' => $js];
    }

    return $slots;
}

/** Ringkas jadwal slot untuk tampilan tabel. */
function santri_izin_tetap_slot_ringkas(PDO $pdo, int $izinTetapId): string
{
    $hariMap = santri_izin_tetap_hari_map();
    $parts = [];
    foreach (santri_izin_tetap_slots($pdo, $izinTetapId) as $sl) {
        $h = (int) ($sl['hari_ke'] ?? 0);
        $jm = substr((string) ($sl['jam_mulai'] ?? ''), 0, 5);
        $js = substr((string) ($sl['jam_selesai'] ?? ''), 0, 5);
        $parts[] = ($hariMap[$h] ?? '?') . ' ' . $jm . '–' . $js;
    }

    return $parts !== [] ? implode('; ', $parts) : '—';
}

/** Format jadwal slot untuk surat cetak (satu baris per hari). */
function santri_izin_tetap_slot_html(PDO $pdo, int $izinTetapId): string
{
    $hariMap = santri_izin_tetap_hari_map();
    $lines = [];
    foreach (santri_izin_tetap_slots($pdo, $izinTetapId) as $sl) {
        $h = (int) ($sl['hari_ke'] ?? 0);
        $jm = substr((string) ($sl['jam_mulai'] ?? ''), 0, 5);
        $js = substr((string) ($sl['jam_selesai'] ?? ''), 0, 5);
        $lines[] = ($hariMap[$h] ?? '?') . ', pukul ' . $jm . ' s.d. ' . $js . ' WIB';
    }

    return $lines !== [] ? implode('<br>', array_map('htmlspecialchars', $lines)) : '—';
}

/**
 * @return array<string, mixed>|null
 */
function santri_izin_tetap_for_print(PDO $pdo, int $id): ?array
{
    ensure_santri_izin_tetap_tables($pdo);
    if ($id <= 0 || !table_exists($pdo, 'santri')) {
        return null;
    }
    $tingExpr = column_exists($pdo, 'santri', 'tingkatan') ? 's.tingkatan' : "''";
    $nameExpr = column_exists($pdo, 'santri', 'nama_santri') ? 's.nama_santri' : 's.nama';
    $stmt = $pdo->prepare("
        SELECT i.*, s.nis, {$nameExpr} AS nama_santri, {$tingExpr} AS tingkatan
        FROM santri_izin_tetap i
        INNER JOIN santri s ON s.id = i.santri_id
        WHERE i.id = :id
        LIMIT 1
    ");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}
