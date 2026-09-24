<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/santri_operasional.php';
require_once __DIR__ . '/santri_status.php';

function santri_penepian_keaktifan_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (!function_exists('table_exists')) {
        return;
    }
    try {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS santri_penepian_keaktifan (
                id INT AUTO_INCREMENT PRIMARY KEY,
                santri_id INT NOT NULL,
                tanggal_mulai DATE NOT NULL,
                tanggal_selesai DATE NOT NULL,
                alasan VARCHAR(500) NOT NULL,
                catatan_internal TEXT NULL,
                is_aktif TINYINT(1) NOT NULL DEFAULT 1,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_penepian_santri (santri_id, is_aktif),
                INDEX idx_penepian_rentang (tanggal_mulai, tanggal_selesai)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    } catch (Throwable $e) {
        error_log('[santri_penepian_keaktifan] ensure_schema: ' . $e->getMessage());
    }
}

function santri_penepian_normalize_date(string $date): ?string
{
    $date = trim($date);
    if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        return null;
    }

    return $date;
}

/** Jumlah hari menepi hingga tanggal acuan (inklusif: hari pertama = 1). */
function santri_penepian_hari_berjalan(string $tanggalMulai, string $tanggalAcuan): int
{
    $mulai = santri_penepian_normalize_date($tanggalMulai);
    $acuan = santri_penepian_normalize_date($tanggalAcuan);
    if ($mulai === null || $acuan === null) {
        return 0;
    }
    try {
        $dMulai = new DateTimeImmutable($mulai);
        $dAcuan = new DateTimeImmutable($acuan);
    } catch (Throwable) {
        return 0;
    }
    if ($dAcuan < $dMulai) {
        return 0;
    }

    return (int) $dMulai->diff($dAcuan)->days + 1;
}

/** @return array<int, true> */
function santri_penepian_map_for_date(PDO $pdo, string $tanggal): array
{
    santri_penepian_keaktifan_ensure_schema($pdo);
    $tanggal = santri_penepian_normalize_date($tanggal);
    if ($tanggal === null || !table_exists($pdo, 'santri_penepian_keaktifan')) {
        return [];
    }

    $st = $pdo->prepare('
        SELECT DISTINCT santri_id
        FROM santri_penepian_keaktifan
        WHERE is_aktif = 1
          AND tanggal_mulai <= :tgl
          AND tanggal_selesai >= :tgl
    ');
    $st->execute(['tgl' => $tanggal]);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $sid) {
        $sid = (int) $sid;
        if ($sid > 0) {
            $map[$sid] = true;
        }
    }

    return $map;
}

function santri_penepian_is_active(PDO $pdo, int $santriId, string $tanggal): bool
{
    if ($santriId <= 0) {
        return false;
    }
    $map = santri_penepian_map_for_date($pdo, $tanggal);

    return !empty($map[$santriId]);
}

/**
 * Santri dengan penepian aktif yang mencakup tanggal (mulai ≤ tgl ≤ selesai).
 *
 * @param list<string>|null $tingkatanList null = seluruh pondok; array kosong = tidak ada baris
 * @return list<array<string, mixed>>
 */
function santri_penepian_list_aktif_on_date(
    PDO $pdo,
    string $tanggal,
    ?array $tingkatanList = null,
    int $limit = 50
): array {
    santri_penepian_keaktifan_ensure_schema($pdo);
    $tanggal = santri_penepian_normalize_date($tanggal);
    if ($tanggal === null || !table_exists($pdo, 'santri_penepian_keaktifan') || !table_exists($pdo, 'santri')) {
        return [];
    }

    require_once __DIR__ . '/santri_operasional.php';
    $limit = max(1, min(200, $limit));
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 's.nama_santri' : 's.nama';
    $aktifSql = santri_sql_aktif_only('s');
    $params = ['today' => $tanggal];
    $tingkatanSql = '';
    if ($tingkatanList !== null) {
        $tingkatanList = array_values(array_filter(array_map(static fn ($t) => trim((string) $t), $tingkatanList)));
        if ($tingkatanList === []) {
            return [];
        }
        $ph = [];
        foreach ($tingkatanList as $i => $tk) {
            $key = 'tk' . $i;
            $ph[] = ':' . $key;
            $params[$key] = $tk;
        }
        $tingkatanSql = ' AND s.tingkatan IN (' . implode(',', $ph) . ')';
    }

    $st = $pdo->prepare('
        SELECT p.id, p.tanggal_mulai, p.tanggal_selesai, p.alasan,
               s.id AS santri_id, s.nis, ' . $nameCol . ' AS nama_santri, s.tingkatan
        FROM santri_penepian_keaktifan p
        INNER JOIN santri s ON s.id = p.santri_id AND ' . $aktifSql . '
        WHERE p.is_aktif = 1
          AND p.tanggal_mulai <= :today
          AND p.tanggal_selesai >= :today' . $tingkatanSql . '
        ORDER BY s.tingkatan ASC, ' . $nameCol . ' ASC
        LIMIT ' . $limit . '
    ');
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array<string, mixed>>
 */
function santri_penepian_list(
    PDO $pdo,
    string $filter = 'aktif',
    ?string $tingkatan = null,
    int $limit = 100
): array {
    santri_penepian_keaktifan_ensure_schema($pdo);
    if (!table_exists($pdo, 'santri_penepian_keaktifan') || !table_exists($pdo, 'santri')) {
        return [];
    }

    $today = date('Y-m-d');
    $limit = max(1, min(200, $limit));
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 's.nama_santri' : 's.nama';
    $where = '1=1';
    $params = [];
    if ($filter === 'aktif') {
        $where .= ' AND p.is_aktif = 1 AND p.tanggal_selesai >= :today';
        $params['today'] = $today;
    } elseif ($filter === 'selesai') {
        $where .= ' AND p.is_aktif = 1 AND p.tanggal_selesai < :today';
        $params['today'] = $today;
    } elseif ($filter === 'batal') {
        $where .= ' AND p.is_aktif = 0';
    }
    if ($tingkatan !== null && trim($tingkatan) !== '') {
        $where .= ' AND s.tingkatan = :tg';
        $params['tg'] = trim($tingkatan);
    }

    $sql = '
        SELECT p.*, s.nis, ' . $nameCol . ' AS nama_santri, s.tingkatan, s.status_santri
        FROM santri_penepian_keaktifan p
        INNER JOIN santri s ON s.id = p.santri_id
        WHERE ' . $where . '
        ORDER BY p.tanggal_mulai DESC, p.id DESC
        LIMIT ' . $limit;
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function santri_penepian_status_label(array $row, ?string $today = null): string
{
    $today = $today ?? date('Y-m-d');
    if ((int) ($row['is_aktif'] ?? 1) !== 1) {
        return 'Dibatalkan';
    }
    $mulai = (string) ($row['tanggal_mulai'] ?? '');
    $selesai = (string) ($row['tanggal_selesai'] ?? '');
    if ($selesai !== '' && $selesai < $today) {
        return 'Selesai';
    }
    if ($mulai !== '' && $mulai > $today) {
        return 'Akan datang';
    }

    return 'Aktif';
}

/**
 * @return array{ok:bool,message:string,id?:int,warn_izin?:string}
 */
function santri_penepian_simpan(
    PDO $pdo,
    int $santriId,
    string $tanggalMulai,
    string $tanggalSelesai,
    string $alasan,
    string $catatanInternal = '',
    int $createdBy = 0
): array {
    santri_penepian_keaktifan_ensure_schema($pdo);
    $tanggalMulai = santri_penepian_normalize_date($tanggalMulai);
    $tanggalSelesai = santri_penepian_normalize_date($tanggalSelesai);
    if ($santriId <= 0 || $tanggalMulai === null || $tanggalSelesai === null) {
        return ['ok' => false, 'message' => 'Data tidak lengkap atau tanggal tidak valid.'];
    }
    if ($tanggalSelesai < $tanggalMulai) {
        return ['ok' => false, 'message' => 'Tanggal selesai harus sama atau setelah tanggal mulai.'];
    }
    $alasan = trim($alasan);
    if (mb_strlen($alasan) < 10) {
        return ['ok' => false, 'message' => 'Alasan minimal 10 karakter.'];
    }
    if (mb_strlen($alasan) > 500) {
        return ['ok' => false, 'message' => 'Alasan maksimal 500 karakter.'];
    }

    $stS = $pdo->prepare('SELECT id, status_santri FROM santri WHERE id = :id LIMIT 1');
    $stS->execute(['id' => $santriId]);
    $sRow = $stS->fetch(PDO::FETCH_ASSOC);
    if (!is_array($sRow)) {
        return ['ok' => false, 'message' => 'Santri tidak ditemukan.'];
    }
    if (santri_status_from_row($sRow) !== santri_status_const_aktif()) {
        return ['ok' => false, 'message' => 'Penepian hanya untuk santri berstatus AKTIF.'];
    }

    $overlap = santri_penepian_find_overlap($pdo, $santriId, $tanggalMulai, $tanggalSelesai, 0);
    if ($overlap !== null) {
        return ['ok' => false, 'message' => 'Sudah ada penepian aktif yang bentrok rentang tanggal. Perpanjang record #' . (int) $overlap['id'] . ' atau batalkan dulu.'];
    }

    $warnIzin = santri_penepian_warn_izin_overlap($pdo, $santriId, $tanggalMulai, $tanggalSelesai);

    $st = $pdo->prepare('
        INSERT INTO santri_penepian_keaktifan
            (santri_id, tanggal_mulai, tanggal_selesai, alasan, catatan_internal, is_aktif, created_by)
        VALUES
            (:sid, :t1, :t2, :alasan, :cat, 1, :uid)
    ');
    $st->execute([
        'sid' => $santriId,
        't1' => $tanggalMulai,
        't2' => $tanggalSelesai,
        'alasan' => $alasan,
        'cat' => trim($catatanInternal) !== '' ? trim($catatanInternal) : null,
        'uid' => $createdBy > 0 ? $createdBy : null,
    ]);

    $out = ['ok' => true, 'message' => 'Penepian keaktifan tersimpan.', 'id' => (int) $pdo->lastInsertId()];
    if ($warnIzin !== '') {
        $out['warn_izin'] = $warnIzin;
        $out['message'] .= ' Catatan: ' . $warnIzin;
    }

    return $out;
}

/**
 * @return array<string, mixed>|null
 */
function santri_penepian_find_overlap(
    PDO $pdo,
    int $santriId,
    string $tanggalMulai,
    string $tanggalSelesai,
    int $excludeId = 0
): ?array {
    santri_penepian_keaktifan_ensure_schema($pdo);
    if (!table_exists($pdo, 'santri_penepian_keaktifan')) {
        return null;
    }
    $sql = '
        SELECT id, tanggal_mulai, tanggal_selesai
        FROM santri_penepian_keaktifan
        WHERE santri_id = :sid
          AND is_aktif = 1
          AND tanggal_mulai <= :t2
          AND tanggal_selesai >= :t1
    ';
    if ($excludeId > 0) {
        $sql .= ' AND id <> :xid';
    }
    $sql .= ' LIMIT 1';
    $st = $pdo->prepare($sql);
    $params = ['sid' => $santriId, 't1' => $tanggalMulai, 't2' => $tanggalSelesai];
    if ($excludeId > 0) {
        $params['xid'] = $excludeId;
    }
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function santri_penepian_warn_izin_overlap(PDO $pdo, int $santriId, string $tanggalMulai, string $tanggalSelesai): string
{
    if (!table_exists($pdo, 'perizinan')) {
        return '';
    }
    $approval = column_exists($pdo, 'perizinan', 'approval_status')
        ? ' AND (approval_status = "DISETUJUI" OR approval_status IS NULL)'
        : '';
    $st = $pdo->prepare('
        SELECT id, jenis_izin, tanggal_mulai, tanggal_selesai
        FROM perizinan
        WHERE santri_id = :sid
          AND status_izin = "IZIN"
          AND waktu_kembali IS NULL
          AND tanggal_mulai <= :t2
          AND tanggal_selesai >= :t1' . $approval . '
        LIMIT 1
    ');
    $st->execute(['sid' => $santriId, 't1' => $tanggalMulai, 't2' => $tanggalSelesai]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return '';
    }

    return 'Santri punya izin resmi #' . (int) $row['id'] . ' (' . (string) ($row['jenis_izin'] ?? '') . ') di rentang yang sama — pastikan hanya satu jalur yang dipakai.';
}

/**
 * @return array{ok:bool,message:string}
 */
function santri_penepian_update(
    PDO $pdo,
    int $id,
    string $tanggalMulai,
    string $tanggalSelesai,
    string $alasan,
    string $catatanInternal = ''
): array {
    santri_penepian_keaktifan_ensure_schema($pdo);
    $tanggalMulai = santri_penepian_normalize_date($tanggalMulai);
    $tanggalSelesai = santri_penepian_normalize_date($tanggalSelesai);
    if ($id <= 0 || $tanggalMulai === null || $tanggalSelesai === null) {
        return ['ok' => false, 'message' => 'Data tidak valid.'];
    }
    if ($tanggalSelesai < $tanggalMulai) {
        return ['ok' => false, 'message' => 'Tanggal selesai harus sama atau setelah tanggal mulai.'];
    }
    $alasan = trim($alasan);
    if (mb_strlen($alasan) < 10) {
        return ['ok' => false, 'message' => 'Alasan minimal 10 karakter.'];
    }

    $st = $pdo->prepare('SELECT santri_id, is_aktif FROM santri_penepian_keaktifan WHERE id = :id LIMIT 1');
    $st->execute(['id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || (int) ($row['is_aktif'] ?? 0) !== 1) {
        return ['ok' => false, 'message' => 'Record tidak ditemukan atau sudah dibatalkan.'];
    }
    $sid = (int) ($row['santri_id'] ?? 0);
    $overlap = santri_penepian_find_overlap($pdo, $sid, $tanggalMulai, $tanggalSelesai, $id);
    if ($overlap !== null) {
        return ['ok' => false, 'message' => 'Rentang bentrok dengan penepian aktif lain.'];
    }

    $pdo->prepare('
        UPDATE santri_penepian_keaktifan
        SET tanggal_mulai = :t1, tanggal_selesai = :t2, alasan = :alasan, catatan_internal = :cat
        WHERE id = :id
    ')->execute([
        't1' => $tanggalMulai,
        't2' => $tanggalSelesai,
        'alasan' => $alasan,
        'cat' => trim($catatanInternal) !== '' ? trim($catatanInternal) : null,
        'id' => $id,
    ]);

    return ['ok' => true, 'message' => 'Penepian diperbarui.'];
}

/**
 * @return array{ok:bool,message:string}
 */
function santri_penepian_batalkan(PDO $pdo, int $id): array
{
    santri_penepian_keaktifan_ensure_schema($pdo);
    if ($id <= 0) {
        return ['ok' => false, 'message' => 'ID tidak valid.'];
    }
    $st = $pdo->prepare('UPDATE santri_penepian_keaktifan SET is_aktif = 0 WHERE id = :id AND is_aktif = 1');
    $st->execute(['id' => $id]);
    if ($st->rowCount() === 0) {
        return ['ok' => false, 'message' => 'Record tidak ditemukan atau sudah dibatalkan.'];
    }

    return ['ok' => true, 'message' => 'Penepian dibatalkan.'];
}

/**
 * @return array{ok:bool,message:string}
 */
function santri_penepian_selesai_hari_ini(PDO $pdo, int $id): array
{
    santri_penepian_keaktifan_ensure_schema($pdo);
    $today = date('Y-m-d');
    if ($id <= 0) {
        return ['ok' => false, 'message' => 'ID tidak valid.'];
    }
    $st = $pdo->prepare('
        UPDATE santri_penepian_keaktifan
        SET tanggal_selesai = :today
        WHERE id = :id AND is_aktif = 1 AND tanggal_mulai <= :today
    ');
    $st->execute(['id' => $id, 'today' => $today]);
    if ($st->rowCount() === 0) {
        return ['ok' => false, 'message' => 'Tidak dapat menutup penepian (belum mulai atau sudah nonaktif).'];
    }

    return ['ok' => true, 'message' => 'Penepian diakhiri hari ini (' . $today . ').'];
}
