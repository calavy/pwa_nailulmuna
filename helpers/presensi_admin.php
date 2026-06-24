<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

function ensure_presensi_jadwal_column(PDO $pdo): void
{
    if (!empty($_SESSION['presensi_jadwal_col_ready_v2'])) {
        return;
    }
    if (!table_exists($pdo, 'presensi')) {
        $_SESSION['presensi_jadwal_col_ready_v2'] = 1;

        return;
    }
    try {
        $pdo->exec('ALTER TABLE presensi ADD COLUMN IF NOT EXISTS jadwal_kegiatan_id INT NULL AFTER kegiatan_id');
        $pdo->exec('ALTER TABLE presensi ADD COLUMN IF NOT EXISTS pkpps_jadwal_id INT UNSIGNED NULL AFTER jadwal_kegiatan_id');
        $pdo->exec('ALTER TABLE presensi ADD INDEX IF NOT EXISTS idx_presensi_jadwal (jadwal_kegiatan_id)');
        $pdo->exec('ALTER TABLE presensi ADD INDEX IF NOT EXISTS idx_presensi_pkpps_jadwal (pkpps_jadwal_id)');
        $pdo->exec('ALTER TABLE presensi ADD INDEX IF NOT EXISTS idx_presensi_tanggal_santri (tanggal_presensi, santri_id)');
        $pdo->exec('ALTER TABLE presensi ADD INDEX IF NOT EXISTS idx_presensi_kegiatan_tanggal (kegiatan_id, tanggal_presensi)');
    } catch (PDOException $e) {
        // MariaDB versi lama mungkin tidak mendukung IF NOT EXISTS pada INDEX
        try {
            $pdo->exec('ALTER TABLE presensi ADD COLUMN IF NOT EXISTS jadwal_kegiatan_id INT NULL AFTER kegiatan_id');
            $pdo->exec('ALTER TABLE presensi ADD COLUMN IF NOT EXISTS pkpps_jadwal_id INT UNSIGNED NULL AFTER jadwal_kegiatan_id');
        } catch (PDOException $e2) {
        }
    }
    ensure_presensi_indexes($pdo);
    $_SESSION['presensi_jadwal_col_ready_v2'] = 1;
}

/** Index lookup sync/rekap: (santri, tanggal, kegiatan). */
function ensure_presensi_indexes(PDO $pdo): void
{
    if (!table_exists($pdo, 'presensi')) {
        return;
    }
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec('ALTER TABLE presensi ADD INDEX IF NOT EXISTS idx_presensi_santri_tgl_keg (santri_id, tanggal_presensi, kegiatan_id)');
    } catch (PDOException $e) {
        try {
            $pdo->exec('ALTER TABLE presensi ADD INDEX idx_presensi_santri_tgl_keg (santri_id, tanggal_presensi, kegiatan_id)');
        } catch (PDOException $e2) {
            $msg = $e2->getMessage();
            if (stripos($msg, 'Duplicate key') === false && strpos($msg, '1061') === false) {
                // index sudah ada atau versi DB tidak mendukung — abaikan
            }
        }
    }
}

/** Admin atau super admin boleh hapus presensi bermasalah. */
function user_can_hapus_presensi_admin(): bool
{
    if (!isset($_SESSION['user'])) {
        return false;
    }
    if (is_super_admin()) {
        return true;
    }

    return strtolower((string) ($_SESSION['user']['role'] ?? '')) === 'admin';
}

/**
 * Hapus presensi + poin otomatis terkait.
 *
 * @param list<int> $presensiIds
 */
function presensi_hapus_by_ids(PDO $pdo, array $presensiIds): int
{
    $presensiIds = array_values(array_unique(array_filter(array_map('intval', $presensiIds), static fn(int $id): bool => $id > 0)));
    if ($presensiIds === [] || !table_exists($pdo, 'presensi')) {
        return 0;
    }

    ensure_presensi_jadwal_column($pdo);
    $placeholders = implode(',', array_fill(0, count($presensiIds), '?'));

    if (table_exists($pdo, 'point_ledger')) {
        $pdo->prepare('DELETE FROM point_ledger WHERE reference_presensi_id IN (' . $placeholders . ')')->execute($presensiIds);
    }

    $st = $pdo->prepare('DELETE FROM presensi WHERE id IN (' . $placeholders . ')');
    $st->execute($presensiIds);

    return $st->rowCount();
}

/** Hapus semua presensi yang terikat jadwal yang dihapus. */
function presensi_hapus_untuk_jadwal(PDO $pdo, int $jadwalId): int
{
    if ($jadwalId <= 0 || !table_exists($pdo, 'presensi') || !table_exists($pdo, 'jadwal_kegiatan')) {
        return 0;
    }

    ensure_presensi_jadwal_column($pdo);

    $st = $pdo->prepare('SELECT id FROM presensi WHERE jadwal_kegiatan_id = :jid');
    $st->execute(['jid' => $jadwalId]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);

    return presensi_hapus_by_ids($pdo, $ids);
}

/**
 * Presensi HADIR/ALPA/IZIN/SAKIT tanpa nama kegiatan (NULL / kegiatan tidak ada).
 *
 * @return list<array<string, mixed>>
 */
function presensi_list_tanpa_kegiatan(PDO $pdo, int $limit = 200): array
{
    if (!table_exists($pdo, 'presensi') || !table_exists($pdo, 'santri')) {
        return [];
    }

    ensure_presensi_jadwal_column($pdo);
    $limit = max(10, min(500, $limit));
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';

    $sql = '
        SELECT p.id, p.santri_id, p.kegiatan_id, p.jadwal_kegiatan_id, p.tanggal_presensi, p.jam_presensi,
               p.status_presensi, p.catatan, s.nis, s.' . $nameCol . ' AS nama_santri, s.tingkatan,
               k.nama_kegiatan
        FROM presensi p
        INNER JOIN santri s ON s.id = p.santri_id
        LEFT JOIN kegiatan k ON k.id = p.kegiatan_id
        WHERE p.status_presensi IN ("HADIR","ALPA")
          AND (
                p.kegiatan_id IS NULL
                OR k.id IS NULL
                OR TRIM(COALESCE(k.nama_kegiatan, "")) = ""
          )
        ORDER BY p.tanggal_presensi DESC, p.id DESC
        LIMIT ' . $limit;

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Skema tabel scan pembimbing — sekali per sesi, bukan tiap buka kamera. */
function presensi_scan_ensure_schema_deferred(PDO $pdo): void
{
    if (!empty($_SESSION['presensi_scan_schema_ready_v1'])) {
        return;
    }
    if (!table_exists($pdo, 'pembimbing')) {
        $_SESSION['presensi_scan_schema_ready_v1'] = 1;

        return;
    }
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS presensi_pembimbing (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pembimbing_id INT NOT NULL,
            kegiatan_id INT NULL,
            tanggal DATE NOT NULL,
            jam TIME NOT NULL,
            jenis_scan ENUM("DATANG","PULANG") NOT NULL DEFAULT "DATANG",
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (pembimbing_id) REFERENCES pembimbing(id) ON DELETE CASCADE
        )
    ');
    try {
        $pdo->exec('ALTER TABLE presensi_pembimbing ADD COLUMN IF NOT EXISTS kegiatan_id INT NULL AFTER pembimbing_id');
    } catch (PDOException $e) {
        // abaikan
    }
    ensure_jadwal_kegiatan_tempat($pdo);
    $_SESSION['presensi_scan_schema_ready_v1'] = 1;
}

/** Konteks jadwal scan — cache singkat agar reload halaman tidak memukul DB berulang. */
function presensi_scan_jadwal_context_cached(PDO $pdo, bool $forceRefresh = false): array
{
    require_once __DIR__ . '/presensi_scan_jadwal.php';
    $cacheKey = 'presensi_scan_jadwal_ctx_v1';
    $tsKey = 'presensi_scan_jadwal_ctx_ts_v1';
    $now = time();
    if (
        !$forceRefresh
        && isset($_SESSION[$cacheKey], $_SESSION[$tsKey])
        && is_array($_SESSION[$cacheKey])
        && ($now - (int) $_SESSION[$tsKey]) < 20
    ) {
        return $_SESSION[$cacheKey];
    }
    $ctx = presensi_scan_jadwal_context($pdo);
    $_SESSION[$cacheKey] = $ctx;
    $_SESSION[$tsKey] = $now;

    return $ctx;
}

function presensi_scan_jadwal_context_invalidate(): void
{
    unset(
        $_SESSION['presensi_scan_jadwal_ctx_v1'],
        $_SESSION['presensi_scan_jadwal_ctx_ts_v1']
    );
}
