<?php

declare(strict_types=1);

require_once __DIR__ . '/akademik.php';
require_once __DIR__ . '/app.php';

/** Schema minimal untuk halaman data / penugasan penerima setoran (tanpa migrasi hafalan/bait). */
function ensure_akademik_setoran_penerima_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    $ready = true;

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS akademik_setoran_munawib_tingkatan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            munawib_id INT NOT NULL,
            tingkatan VARCHAR(80) NOT NULL,
            UNIQUE KEY uniq_mst (munawib_id, tingkatan),
            INDEX idx_mst_munawib (munawib_id)
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS akademik_setoran_pembimbing_tingkatan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pembimbing_id INT NOT NULL,
            tingkatan VARCHAR(80) NOT NULL,
            UNIQUE KEY uniq_pst (pembimbing_id, tingkatan),
            INDEX idx_pst_pembimbing (pembimbing_id)
        )
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS akademik_penerima_setoran (
            id INT AUTO_INCREMENT PRIMARY KEY,
            peran ENUM("pembimbing","munawib") NOT NULL,
            ref_id INT NOT NULL,
            is_aktif TINYINT(1) NOT NULL DEFAULT 1,
            catatan VARCHAR(255) NULL,
            ditugaskan_pada DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_penerima (peran, ref_id),
            INDEX idx_penerima_aktif (is_aktif, peran),
            INDEX idx_penerima_peran_ref (peran, ref_id, is_aktif)
        )
    ');

    if (app_setting($pdo, 'akademik_penerima_setoran_backfill_v1') !== '1') {
        akademik_setoran_penerima_backfill($pdo);
        save_setting($pdo, 'akademik_penerima_setoran_backfill_v1', '1');
    }
}

function ensure_akademik_setoran_extended_schema(PDO $pdo): void
{
    static $extendedReady = false;
    if ($extendedReady) {
        return;
    }
    $extendedReady = true;

    ensure_akademik_setoran_penerima_schema($pdo);
    ensure_akademik_hafalan_setoran_table($pdo);
    ensure_akademik_bait_kitab_table($pdo);

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS akademik_bait_kitab_tingkatan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bait_kitab_id INT UNSIGNED NOT NULL,
            tingkatan VARCHAR(80) NOT NULL,
            UNIQUE KEY uniq_bkt (bait_kitab_id, tingkatan),
            INDEX idx_bkt_tingkatan (tingkatan)
        )
    ');

    akademik_add_column($pdo, 'akademik_hafalan_setoran', 'jenis_entri', "VARCHAR(12) NOT NULL DEFAULT 'HARIAN'");
    akademik_add_column($pdo, 'akademik_hafalan_setoran', 'pembimbing_id', 'INT NULL');
    akademik_add_column($pdo, 'akademik_hafalan_setoran', 'munawib_id', 'INT NULL');
}

function akademik_setoran_require_access(): void
{
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/munawib_portal.php';
    require_once __DIR__ . '/login_pembimbing.php';
    require_login();
    if (!akademik_setoran_is_portal_script() && !akademik_setoran_is_api_script()) {
        munawib_portal_guard_halaman();
    }
    if (is_super_admin()) {
        return;
    }
    global $pdo;
    $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
    if ($role === 'pembimbing' && $pdo instanceof PDO) {
        $uid = (int) ($_SESSION['user']['id'] ?? 0);
        if ($uid > 0) {
            login_pembimbing_ensure_acl($pdo, $uid);
        }
    }
    if (in_array($role, ['admin', 'pengurus', 'pembimbing', 'petugas_absensi'], true)) {
        return;
    }
    if (munawib_is_portal_session()) {
        return;
    }
    if (function_exists('user_has_current_page_permission') && user_has_current_page_permission()) {
        return;
    }
    set_flash('error', 'Anda tidak memiliki akses modul setoran hafalan.');
    auth_redirect_access_denied();
}

/** @return array{id:int,nis:string,nama_santri:string,tingkatan:string}|null */
function akademik_setoran_resolve_santri_qr(PDO $pdo, string $code): ?array
{
    $code = trim($code);
    if ($code === '') {
        return null;
    }
    ensure_santri_identity_columns($pdo);
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $sql = 'SELECT id, nis, ' . $nameCol . ' AS nama_santri, tingkatan FROM santri WHERE qr = :c OR nis = :c';
    if (column_exists($pdo, 'santri', 'is_aktif')) {
        $sql .= ' AND COALESCE(is_aktif, 1) = 1';
    }
    $sql .= ' LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute(['c' => $code]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/** @return list<string> */
function akademik_setoran_semua_tingkatan(PDO $pdo): array
{
    if (table_exists($pdo, 'tingkatan')) {
        $rows = $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY nama_tingkatan ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];

        return array_values(array_filter(array_map('strval', $rows)));
    }
    $st = $pdo->query('SELECT DISTINCT TRIM(tingkatan) FROM santri WHERE TRIM(COALESCE(tingkatan, "")) <> "" ORDER BY tingkatan ASC');

    return array_values(array_filter(array_map('strval', $st ? ($st->fetchAll(PDO::FETCH_COLUMN) ?: []) : [])));
}

/** @return list<int> */
function akademik_setoran_bait_ids_for_tingkatan(PDO $pdo, string $tingkatan): array
{
    ensure_akademik_setoran_extended_schema($pdo);
    $tingkatan = trim($tingkatan);
    if ($tingkatan === '') {
        return [];
    }
    $st = $pdo->prepare('
        SELECT DISTINCT k.id
        FROM akademik_bait_kitab k
        INNER JOIN akademik_bait_kitab_tingkatan t ON t.bait_kitab_id = k.id
        WHERE k.is_aktif = 1 AND t.tingkatan = :tk
        ORDER BY k.urutan ASC, k.nama_kitab ASC
    ');
    $st->execute(['tk' => $tingkatan]);
    $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($ids !== []) {
        return $ids;
    }
    $fallback = $pdo->query('SELECT id FROM akademik_bait_kitab WHERE is_aktif = 1 ORDER BY urutan ASC, nama_kitab ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];

    return array_map('intval', $fallback);
}

/**
 * @return list<array<string, mixed>>
 */
function akademik_setoran_kitab_rows_for_tingkatan(PDO $pdo, string $tingkatan): array
{
    $ids = akademik_setoran_bait_ids_for_tingkatan($pdo, $tingkatan);
    if ($ids === []) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare('SELECT id, nama_kitab, jumlah_baris, target_baris_per_hari FROM akademik_bait_kitab WHERE id IN (' . $ph . ') ORDER BY urutan ASC, nama_kitab ASC');
    $st->execute($ids);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function akademik_setoran_sync_bait_tingkatan(PDO $pdo, int $kitabId, array $tingkatanList): void
{
    ensure_akademik_setoran_extended_schema($pdo);
    if ($kitabId <= 0) {
        return;
    }
    $pdo->prepare('DELETE FROM akademik_bait_kitab_tingkatan WHERE bait_kitab_id = :id')->execute(['id' => $kitabId]);
    $ins = $pdo->prepare('INSERT INTO akademik_bait_kitab_tingkatan (bait_kitab_id, tingkatan) VALUES (:kid, :tk)');
    foreach ($tingkatanList as $tk) {
        $tk = trim((string) $tk);
        if ($tk === '') {
            continue;
        }
        $ins->execute(['kid' => $kitabId, 'tk' => mb_substr($tk, 0, 80)]);
    }
}

/** @return list<string> */
function akademik_setoran_bait_tingkatan_list(PDO $pdo, int $kitabId): array
{
    ensure_akademik_setoran_extended_schema($pdo);
    if ($kitabId <= 0) {
        return [];
    }
    $st = $pdo->prepare('SELECT tingkatan FROM akademik_bait_kitab_tingkatan WHERE bait_kitab_id = :id ORDER BY tingkatan ASC');
    $st->execute(['id' => $kitabId]);

    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

/** @return list<string> */
function akademik_setoran_munawib_tingkatan_list(PDO $pdo, int $munawibId): array
{
    ensure_akademik_setoran_extended_schema($pdo);
    if ($munawibId <= 0 || !table_exists($pdo, 'munawib')) {
        return [];
    }
    $st = $pdo->prepare('SELECT tingkatan FROM akademik_setoran_munawib_tingkatan WHERE munawib_id = :id ORDER BY tingkatan ASC');
    $st->execute(['id' => $munawibId]);

    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function akademik_setoran_sync_munawib_tingkatan(PDO $pdo, int $munawibId, array $tingkatanList): void
{
    ensure_akademik_setoran_extended_schema($pdo);
    if ($munawibId <= 0) {
        return;
    }
    $pdo->prepare('DELETE FROM akademik_setoran_munawib_tingkatan WHERE munawib_id = :id')->execute(['id' => $munawibId]);
    $ins = $pdo->prepare('INSERT INTO akademik_setoran_munawib_tingkatan (munawib_id, tingkatan) VALUES (:mid, :tk)');
    foreach ($tingkatanList as $tk) {
        $tk = trim((string) $tk);
        if ($tk === '') {
            continue;
        }
        $ins->execute(['mid' => $munawibId, 'tk' => mb_substr($tk, 0, 80)]);
    }
    if ($tingkatanList !== []) {
        akademik_setoran_penerima_upsert($pdo, 'munawib', $munawibId, true);
    }
}

/** @return list<string> */
function akademik_setoran_pembimbing_tingkatan_list(PDO $pdo, int $pembimbingId): array
{
    ensure_akademik_setoran_extended_schema($pdo);
    if ($pembimbingId <= 0 || !table_exists($pdo, 'pembimbing')) {
        return [];
    }
    $st = $pdo->prepare('SELECT tingkatan FROM akademik_setoran_pembimbing_tingkatan WHERE pembimbing_id = :id ORDER BY tingkatan ASC');
    $st->execute(['id' => $pembimbingId]);

    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
}

function akademik_setoran_sync_pembimbing_tingkatan(PDO $pdo, int $pembimbingId, array $tingkatanList): void
{
    ensure_akademik_setoran_penerima_schema($pdo);
    if ($pembimbingId <= 0) {
        return;
    }
    $pdo->prepare('DELETE FROM akademik_setoran_pembimbing_tingkatan WHERE pembimbing_id = :id')->execute(['id' => $pembimbingId]);
    $ins = $pdo->prepare('INSERT INTO akademik_setoran_pembimbing_tingkatan (pembimbing_id, tingkatan) VALUES (:pid, :tk)');
    foreach ($tingkatanList as $tk) {
        $tk = trim((string) $tk);
        if ($tk === '') {
            continue;
        }
        $ins->execute(['pid' => $pembimbingId, 'tk' => mb_substr($tk, 0, 80)]);
    }
    if ($tingkatanList !== []) {
        akademik_setoran_penerima_upsert($pdo, 'pembimbing', $pembimbingId, true);
    }
}

/** Sinkronkan registry penerima dari penugasan tingkatan yang sudah ada. */
function akademik_setoran_penerima_backfill(PDO $pdo): void
{
    if (!table_exists($pdo, 'akademik_penerima_setoran')) {
        return;
    }
    $pbIds = $pdo->query('SELECT DISTINCT pembimbing_id FROM akademik_setoran_pembimbing_tingkatan')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($pbIds as $id) {
        $rid = (int) $id;
        if ($rid > 0) {
            akademik_setoran_penerima_upsert($pdo, 'pembimbing', $rid, true);
        }
    }
    $mwIds = $pdo->query('SELECT DISTINCT munawib_id FROM akademik_setoran_munawib_tingkatan')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($mwIds as $id) {
        $rid = (int) $id;
        if ($rid > 0) {
            akademik_setoran_penerima_upsert($pdo, 'munawib', $rid, true);
        }
    }
}

function akademik_setoran_penerima_upsert(PDO $pdo, string $peran, int $refId, bool $aktif = true): void
{
    ensure_akademik_setoran_penerima_schema($pdo);
    if ($refId <= 0 || !in_array($peran, ['pembimbing', 'munawib'], true)) {
        return;
    }
    $st = $pdo->prepare('
        INSERT INTO akademik_penerima_setoran (peran, ref_id, is_aktif, ditugaskan_pada)
        VALUES (:peran, :ref, :aktif, NOW())
        ON DUPLICATE KEY UPDATE is_aktif = VALUES(is_aktif)
    ');
    $st->execute(['peran' => $peran, 'ref' => $refId, 'aktif' => $aktif ? 1 : 0]);
}

function akademik_setoran_penerima_set_aktif(PDO $pdo, string $peran, int $refId, bool $aktif): void
{
    ensure_akademik_setoran_penerima_schema($pdo);
    if ($refId <= 0 || !in_array($peran, ['pembimbing', 'munawib'], true)) {
        return;
    }
    $chk = $pdo->prepare('SELECT id FROM akademik_penerima_setoran WHERE peran = :peran AND ref_id = :ref LIMIT 1');
    $chk->execute(['peran' => $peran, 'ref' => $refId]);
    if ($chk->fetchColumn()) {
        $pdo->prepare('UPDATE akademik_penerima_setoran SET is_aktif = :a WHERE peran = :peran AND ref_id = :ref')
            ->execute(['a' => $aktif ? 1 : 0, 'peran' => $peran, 'ref' => $refId]);
    } else {
        akademik_setoran_penerima_upsert($pdo, $peran, $refId, $aktif);
    }
}

function akademik_setoran_penerima_is_aktif(PDO $pdo, string $peran, int $refId): bool
{
    ensure_akademik_setoran_penerima_schema($pdo);
    if ($refId <= 0 || !in_array($peran, ['pembimbing', 'munawib'], true)) {
        return false;
    }
    $st = $pdo->prepare('SELECT is_aktif FROM akademik_penerima_setoran WHERE peran = :peran AND ref_id = :ref LIMIT 1');
    $st->execute(['peran' => $peran, 'ref' => $refId]);
    $v = $st->fetchColumn();

    return $v !== false && (int) $v === 1;
}

function akademik_setoran_penerima_hapus(PDO $pdo, string $peran, int $refId): void
{
    ensure_akademik_setoran_penerima_schema($pdo);
    if ($refId <= 0 || !in_array($peran, ['pembimbing', 'munawib'], true)) {
        return;
    }
    $pdo->prepare('DELETE FROM akademik_penerima_setoran WHERE peran = :peran AND ref_id = :ref')
        ->execute(['peran' => $peran, 'ref' => $refId]);
    if ($peran === 'pembimbing') {
        $pdo->prepare('DELETE FROM akademik_setoran_pembimbing_tingkatan WHERE pembimbing_id = :id')->execute(['id' => $refId]);
    } else {
        $pdo->prepare('DELETE FROM akademik_setoran_munawib_tingkatan WHERE munawib_id = :id')->execute(['id' => $refId]);
    }
}

/**
 * Hitung santri aktif per tingkatan kajian (satu query agregat).
 *
 * @param list<string> $tingkatanList
 * @return array<string, int>
 */
function akademik_setoran_santri_count_map_kajian(PDO $pdo, array $tingkatanList): array
{
    if ($tingkatanList === [] || !table_exists($pdo, 'santri')) {
        return [];
    }
    require_once __DIR__ . '/pembimbing_dashboard.php';
    require_once __DIR__ . '/santri_operasional.php';
    require_once __DIR__ . '/pembimbing_pkpps.php';

    $kajian = [];
    foreach ($tingkatanList as $tk) {
        $tk = trim((string) $tk);
        if ($tk !== '' && !pembimbing_pkpps_is_label($tk)) {
            $kajian[] = $tk;
        }
    }
    if ($kajian === []) {
        return [];
    }

    [$inSql, $params] = pembimbing_dashboard_in_clause($kajian, 'tk');
    if ($inSql === 'NULL') {
        return [];
    }
    $aktifSql = santri_sql_aktif_only('s');
    $st = $pdo->prepare('
        SELECT TRIM(s.tingkatan) AS tingkatan, COUNT(*) AS jumlah
        FROM santri s
        WHERE ' . $aktifSql . ' AND TRIM(s.tingkatan) IN (' . $inSql . ')
        GROUP BY TRIM(s.tingkatan)
    ');
    $st->execute($params);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $tk = trim((string) ($row['tingkatan'] ?? ''));
        if ($tk !== '') {
            $map[$tk] = (int) ($row['jumlah'] ?? 0);
        }
    }

    return $map;
}

/**
 * @param list<string> $tingkatanList
 */
function akademik_setoran_penerima_jumlah_santri(PDO $pdo, array $tingkatanList, ?int $pembimbingId, array $kajianCountMap): int
{
    if ($tingkatanList === []) {
        return 0;
    }
    require_once __DIR__ . '/pembimbing_pkpps.php';

    $total = 0;
    $pkppsLabels = [];
    foreach ($tingkatanList as $tk) {
        $tk = trim((string) $tk);
        if ($tk === '') {
            continue;
        }
        if (pembimbing_pkpps_is_label($tk)) {
            $pkppsLabels[] = $tk;
        } else {
            $total += (int) ($kajianCountMap[$tk] ?? 0);
        }
    }

    if ($pkppsLabels !== [] && $pembimbingId !== null && $pembimbingId > 0) {
        $pkppsIds = [];
        foreach ($pkppsLabels as $lbl) {
            $tid = pembimbing_pkpps_id_from_label($lbl, $pdo, $pembimbingId);
            if ($tid > 0) {
                $pkppsIds[] = $tid;
            }
        }
        if ($pkppsIds !== []) {
            require_once __DIR__ . '/pembimbing_dashboard.php';
            $total += count(pembimbing_pkpps_santri_list($pdo, $pembimbingId, $pkppsIds, 2000));
        }
    }

    return $total;
}

/**
 * ID pembimbing/munawib yang aktif sebagai penerima setoran (tanpa hitung santri).
 *
 * @return array{pembimbing: array<int, true>, munawib: array<int, true>}
 */
function akademik_setoran_penerima_aktif_id_map(PDO $pdo): array
{
    ensure_akademik_setoran_penerima_schema($pdo);
    $pb = [];
    $mw = [];
    $st = $pdo->query('SELECT peran, ref_id FROM akademik_penerima_setoran WHERE is_aktif = 1');
    foreach ($st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $row) {
        $id = (int) ($row['ref_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        if (($row['peran'] ?? '') === 'pembimbing') {
            $pb[$id] = true;
        } elseif (($row['peran'] ?? '') === 'munawib') {
            $mw[$id] = true;
        }
    }

    return ['pembimbing' => $pb, 'munawib' => $mw];
}

/**
 * @param list<string>|null $peranFilter
 * @return list<array<string, mixed>>
 */
function akademik_setoran_penerima_list(PDO $pdo, ?string $peranFilter = null, bool $withSantriCount = true): array
{
    ensure_akademik_setoran_penerima_schema($pdo);

    $out = [];
    $rawRows = [];

    if ($peranFilter === null || $peranFilter === 'pembimbing') {
        if (table_exists($pdo, 'pembimbing')) {
            $rawRows = array_merge($rawRows, $pdo->query('
                SELECT "pembimbing" AS peran, p.id AS ref_id, p.nama_pembimbing AS nama, p.nip,
                    ps.is_aktif AS penerima_aktif, ps.catatan, ps.ditugaskan_pada,
                    GROUP_CONCAT(DISTINCT pst.tingkatan ORDER BY pst.tingkatan SEPARATOR "|") AS tingkatan_csv
                FROM pembimbing p
                INNER JOIN akademik_penerima_setoran ps ON ps.peran = "pembimbing" AND ps.ref_id = p.id
                LEFT JOIN akademik_setoran_pembimbing_tingkatan pst ON pst.pembimbing_id = p.id
                WHERE COALESCE(p.is_aktif, 1) = 1
                GROUP BY p.id, p.nama_pembimbing, p.nip, ps.is_aktif, ps.catatan, ps.ditugaskan_pada
                ORDER BY p.nama_pembimbing ASC
            ')->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }
    }

    if ($peranFilter === null || $peranFilter === 'munawib') {
        if (table_exists($pdo, 'munawib')) {
            $rawRows = array_merge($rawRows, $pdo->query('
                SELECT "munawib" AS peran, m.id AS ref_id, m.nama, m.nip,
                    ps.is_aktif AS penerima_aktif, ps.catatan, ps.ditugaskan_pada,
                    GROUP_CONCAT(DISTINCT mst.tingkatan ORDER BY mst.tingkatan SEPARATOR "|") AS tingkatan_csv
                FROM munawib m
                INNER JOIN akademik_penerima_setoran ps ON ps.peran = "munawib" AND ps.ref_id = m.id
                LEFT JOIN akademik_setoran_munawib_tingkatan mst ON mst.munawib_id = m.id
                WHERE COALESCE(m.is_aktif, 1) = 1
                GROUP BY m.id, m.nama, m.nip, ps.is_aktif, ps.catatan, ps.ditugaskan_pada
                ORDER BY m.nama ASC
            ')->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }
    }

    $allTingkatan = [];
    $parsed = [];
    foreach ($rawRows as $r) {
        $tkList = array_values(array_filter(explode('|', (string) ($r['tingkatan_csv'] ?? ''))));
        foreach ($tkList as $tk) {
            $allTingkatan[$tk] = true;
        }
        $parsed[] = ['row' => $r, 'tingkatan' => $tkList];
    }

    $kajianCountMap = $withSantriCount
        ? akademik_setoran_santri_count_map_kajian($pdo, array_keys($allTingkatan))
        : [];

    foreach ($parsed as $item) {
        $r = $item['row'];
        $tkList = $item['tingkatan'];
        $peran = (string) ($r['peran'] ?? '');
        $refId = (int) ($r['ref_id'] ?? 0);
        $jumlahSantri = 0;
        if ($withSantriCount && $tkList !== []) {
            $pbId = $peran === 'pembimbing' ? $refId : null;
            $jumlahSantri = akademik_setoran_penerima_jumlah_santri($pdo, $tkList, $pbId, $kajianCountMap);
        }
        $out[] = [
            'peran' => $peran,
            'ref_id' => $refId,
            'nama' => (string) ($r['nama'] ?? ''),
            'nip' => (string) ($r['nip'] ?? ''),
            'is_aktif' => (int) ($r['penerima_aktif'] ?? 0) === 1,
            'tingkatan' => $tkList,
            'jumlah_santri' => $jumlahSantri,
            'catatan' => (string) ($r['catatan'] ?? ''),
            'ditugaskan_pada' => (string) ($r['ditugaskan_pada'] ?? ''),
            'siap_terima' => $tkList !== [] && (int) ($r['penerima_aktif'] ?? 0) === 1,
        ];
    }

    return $out;
}

/**
 * SDM aktif yang belum ditugaskan sebagai penerima setoran.
 *
 * @return array{pembimbing:list<array<string,mixed>>,munawib:list<array<string,mixed>>}
 */
function akademik_setoran_penerima_kandidat(PDO $pdo): array
{
    ensure_akademik_setoran_penerima_schema($pdo);

    $pembimbing = $pdo->query('
        SELECT p.id, p.nama_pembimbing AS nama, p.nip
        FROM pembimbing p
        LEFT JOIN akademik_penerima_setoran ps
            ON ps.peran = "pembimbing" AND ps.ref_id = p.id AND ps.is_aktif = 1
        WHERE COALESCE(p.is_aktif, 1) = 1 AND ps.id IS NULL
        ORDER BY p.nama_pembimbing ASC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $munawib = $pdo->query('
        SELECT m.id, m.nama, m.nip
        FROM munawib m
        LEFT JOIN akademik_penerima_setoran ps
            ON ps.peran = "munawib" AND ps.ref_id = m.id AND ps.is_aktif = 1
        WHERE COALESCE(m.is_aktif, 1) = 1 AND ps.id IS NULL
        ORDER BY m.nama ASC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return ['pembimbing' => $pembimbing, 'munawib' => $munawib];
}

/** Halaman portal setoran (tidak memerlukan konteks jadwal kegiatan). */
function akademik_setoran_portal_script_names(): array
{
    return [
        'setoran_dashboard.php',
        'setoran.php',
        'setoran_perolehan.php',
        'setoran_keaktivan.php',
    ];
}

function akademik_setoran_is_portal_script(): bool
{
    return in_array(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), akademik_setoran_portal_script_names(), true);
}

/** API setoran (tanpa konteks jadwal munawib). */
function akademik_setoran_api_script_names(): array
{
    return [
        'santri_scan.php',
    ];
}

function akademik_setoran_is_api_script(): bool
{
    return in_array(basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')), akademik_setoran_api_script_names(), true);
}

function akademik_setoran_resolve_pembimbing_id(PDO $pdo): int
{
    require_once __DIR__ . '/munawib_portal.php';
    if (munawib_is_portal_session()) {
        return 0;
    }

    if (!table_exists($pdo, 'pembimbing')) {
        return 0;
    }
    $aktifSql = column_exists($pdo, 'pembimbing', 'is_aktif') ? ' AND COALESCE(is_aktif, 1) = 1' : '';

    $sessionPb = (int) ($_SESSION['setoran_pembimbing_id'] ?? 0);
    if ($sessionPb > 0) {
        $stS = $pdo->prepare('SELECT id FROM pembimbing WHERE id = :id' . $aktifSql . ' LIMIT 1');
        $stS->execute(['id' => $sessionPb]);
        if ((int) ($stS->fetchColumn() ?: 0) > 0) {
            return $sessionPb;
        }
    }

    $uid = (int) ($_SESSION['user']['id'] ?? 0);
    if ($uid > 0 && table_exists($pdo, 'users')) {
        require_once __DIR__ . '/pembimbing_dashboard.php';
        $pb = pembimbing_dashboard_current_pembimbing($pdo, $uid);
        if (is_array($pb) && empty($pb['munawib_mode']) && (int) ($pb['id'] ?? 0) > 0) {
            return (int) $pb['id'];
        }
    }

    $nip = trim((string) ($_SESSION['user']['username'] ?? ''));
    if ($nip !== '') {
        $st = $pdo->prepare('SELECT id FROM pembimbing WHERE TRIM(nip) = :nip' . $aktifSql . ' LIMIT 1');
        $st->execute(['nip' => $nip]);
        $id = (int) ($st->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
        if ($uid > 0) {
            $stQr = $pdo->prepare('
                SELECT p.id FROM pembimbing p
                INNER JOIN users u ON u.id = :uid AND TRIM(u.username) = TRIM(p.nip)
                WHERE 1=1' . $aktifSql . ' LIMIT 1
            ');
            $stQr->execute(['uid' => $uid]);
            $id = (int) ($stQr->fetchColumn() ?: 0);
            if ($id > 0) {
                return $id;
            }
        }
        $st2 = $pdo->prepare('SELECT id FROM pembimbing WHERE (TRIM(qr) = :c OR TRIM(nip) = :c)' . $aktifSql . ' LIMIT 1');
        $st2->execute(['c' => $nip]);
        $id = (int) ($st2->fetchColumn() ?: 0);
        if ($id > 0) {
            return $id;
        }
    }

    return 0;
}

/** Simpan ID pembimbing hasil scan/login agar portal setoran konsisten dengan kartu QR. */
function akademik_setoran_session_set_pembimbing_id(int $pembimbingId): void
{
    if ($pembimbingId > 0) {
        $_SESSION['setoran_pembimbing_id'] = $pembimbingId;
    } else {
        unset($_SESSION['setoran_pembimbing_id']);
    }
}

function akademik_setoran_portal_denial_message(array $portalAccess): string
{
    $reason = (string) ($portalAccess['reason'] ?? '');
    $refId = (int) ($portalAccess['ref_id'] ?? 0);

    return match ($reason) {
        'pembimbing_tidak_dikenali' => 'Akun login tidak terhubung ke data pembimbing. Pastikan username login = NIP di data pembimbing, atau masuk lewat scan kartu QR.',
        'pembimbing_belum_ditugaskan' => $refId > 0
            ? 'Anda (pembimbing #' . $refId . ') belum terdaftar aktif sebagai penerima setoran. Pengurus: Kajian → Penerima setoran.'
            : 'Anda belum terdaftar sebagai penerima setoran aktif. Pengurus: Kajian → Penerima setoran.',
        'munawib_belum_ditugaskan' => $refId > 0
            ? 'Anda (munawib #' . $refId . ') belum terdaftar aktif sebagai penerima setoran. Pengurus: Kajian → Penerima setoran.'
            : 'Anda belum terdaftar sebagai penerima setoran aktif. Pengurus: Kajian → Penerima setoran.',
        default => 'Akses portal setoran belum aktif. Hubungi pengurus.',
    };
}

/**
 * Tingkatan yang boleh diterima setoran untuk satu petugas.
 *
 * @return list<string>
 */
function akademik_setoran_penerima_tingkatan_for(PDO $pdo, string $peran, int $refId): array
{
    if ($refId <= 0 || !in_array($peran, ['pembimbing', 'munawib'], true)) {
        return [];
    }
    ensure_akademik_setoran_penerima_schema($pdo);

    $tingkatan = $peran === 'pembimbing'
        ? akademik_setoran_pembimbing_tingkatan_list($pdo, $refId)
        : akademik_setoran_munawib_tingkatan_list($pdo, $refId);

    if (!akademik_setoran_penerima_is_aktif($pdo, $peran, $refId)) {
        return [];
    }

    return $tingkatan;
}

/** @return array{ok:bool,reason:string,peran:string,ref_id:int} */
function akademik_setoran_portal_access_status(PDO $pdo): array
{
    require_once __DIR__ . '/munawib_portal.php';

    $munawibId = munawib_session_id();
    if ($munawibId > 0) {
        if (akademik_setoran_penerima_is_aktif($pdo, 'munawib', $munawibId)) {
            return ['ok' => true, 'reason' => '', 'peran' => 'munawib', 'ref_id' => $munawibId];
        }

        return ['ok' => false, 'reason' => 'munawib_belum_ditugaskan', 'peran' => 'munawib', 'ref_id' => $munawibId];
    }

    $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
    if (in_array($role, ['admin', 'pengurus', 'petugas_absensi'], true) || is_super_admin()) {
        return ['ok' => true, 'reason' => '', 'peran' => '', 'ref_id' => 0];
    }

    $pembimbingId = akademik_setoran_resolve_pembimbing_id($pdo);
    if ($pembimbingId <= 0) {
        return ['ok' => false, 'reason' => 'pembimbing_tidak_dikenali', 'peran' => 'pembimbing', 'ref_id' => 0];
    }

    if (akademik_setoran_penerima_is_aktif($pdo, 'pembimbing', $pembimbingId)) {
        return ['ok' => true, 'reason' => '', 'peran' => 'pembimbing', 'ref_id' => $pembimbingId];
    }

    return ['ok' => false, 'reason' => 'pembimbing_belum_ditugaskan', 'peran' => 'pembimbing', 'ref_id' => $pembimbingId];
}

/** Pesan peringatan non-blokir (mis. tingkatan belum diatur). */
function akademik_setoran_portal_setup_warning(PDO $pdo, array $portalAccess): string
{
    if (($portalAccess['peran'] ?? '') === '') {
        return '';
    }
    $peran = (string) $portalAccess['peran'];
    $refId = (int) ($portalAccess['ref_id'] ?? 0);
    if ($refId <= 0) {
        return '';
    }
    $tk = akademik_setoran_penerima_tingkatan_for($pdo, $peran, $refId);
    if ($tk === []) {
        return 'Tingkatan penerima setoran belum diatur. Pengurus: Kajian → Penerima setoran → tab Tingkatan. Anda tetap bisa membuka portal; scan santri aktif setelah tingkatan diset.';
    }

    return '';
}

/**
 * @return array{pembimbing_id:int,munawib_id:int,tingkatan_allowed:list<string>}
 */
function akademik_setoran_petugas_context(PDO $pdo): array
{
    require_once __DIR__ . '/munawib_portal.php';

    $munawibId = munawib_session_id();
    $pembimbingId = 0;
    $allowed = [];

    if ($munawibId > 0) {
        if (akademik_setoran_penerima_is_aktif($pdo, 'munawib', $munawibId)) {
            $allowed = akademik_setoran_penerima_tingkatan_for($pdo, 'munawib', $munawibId);
        }
        if ($allowed === []) {
            $allowed = array_map('strval', $_SESSION['munawib_tingkatan'] ?? []);
        }
    } else {
        $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
        if (in_array($role, ['admin', 'pengurus', 'petugas_absensi'], true) || is_super_admin()) {
            $allowed = akademik_setoran_semua_tingkatan($pdo);
        } else {
            $pembimbingId = akademik_setoran_resolve_pembimbing_id($pdo);
            if ($pembimbingId > 0 && akademik_setoran_penerima_is_aktif($pdo, 'pembimbing', $pembimbingId)) {
                $allowed = akademik_setoran_penerima_tingkatan_for($pdo, 'pembimbing', $pembimbingId);
            }
        }
    }

    return [
        'pembimbing_id' => $pembimbingId,
        'munawib_id' => $munawibId,
        'tingkatan_allowed' => $allowed,
    ];
}

function akademik_setoran_can_terima_santri(PDO $pdo, array $santri, array $ctx): bool
{
    $allowed = $ctx['tingkatan_allowed'] ?? [];
    if ($allowed === []) {
        $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
        return in_array($role, ['admin', 'pengurus'], true) || is_super_admin();
    }
    $tk = trim((string) ($santri['tingkatan'] ?? ''));

    return in_array($tk, $allowed, true);
}

function akademik_setoran_perolehan_bait(PDO $pdo, int $santriId, int $kitabId): int
{
    ensure_akademik_setoran_extended_schema($pdo);
    if ($santriId <= 0 || $kitabId <= 0) {
        return 0;
    }
    $st = $pdo->prepare('
        SELECT COALESCE(SUM(baris_setor), 0)
        FROM akademik_hafalan_setoran
        WHERE santri_id = :sid AND bait_kitab_id = :kid AND kategori_setoran = "BAIT"
    ');
    $st->execute(['sid' => $santriId, 'kid' => $kitabId]);

    return (int) $st->fetchColumn();
}

function akademik_setoran_last_baris(PDO $pdo, int $santriId, int $kitabId): int
{
    ensure_akademik_setoran_extended_schema($pdo);
    if ($santriId <= 0 || $kitabId <= 0) {
        return 0;
    }
    $st = $pdo->prepare('
        SELECT baris_setor FROM akademik_hafalan_setoran
        WHERE santri_id = :sid AND bait_kitab_id = :kid AND kategori_setoran = "BAIT" AND baris_setor > 0
        ORDER BY tanggal_setoran DESC, id DESC LIMIT 1
    ');
    $st->execute(['sid' => $santriId, 'kid' => $kitabId]);
    $v = $st->fetchColumn();

    return $v !== false ? (int) $v : 0;
}

function akademik_setoran_sudah_hari_ini(PDO $pdo, int $santriId, string $tanggal): bool
{
    ensure_akademik_setoran_extended_schema($pdo);
    $st = $pdo->prepare('SELECT id FROM akademik_hafalan_setoran WHERE santri_id = :sid AND tanggal_setoran = :tgl LIMIT 1');
    $st->execute(['sid' => $santriId, 'tgl' => $tanggal]);

    return (bool) $st->fetchColumn();
}

function akademik_setoran_izin_atau_sakit(PDO $pdo, int $santriId, string $tanggal): bool
{
    if (!table_exists($pdo, 'presensi')) {
        return false;
    }
    $st = $pdo->prepare('
        SELECT 1 FROM presensi
        WHERE santri_id = :sid AND tanggal_presensi = :tgl AND status_presensi IN ("IZIN", "SAKIT")
        LIMIT 1
    ');
    $st->execute(['sid' => $santriId, 'tgl' => $tanggal]);
    if ($st->fetchColumn()) {
        return true;
    }
    if (!table_exists($pdo, 'perizinan')) {
        return false;
    }
    $st2 = $pdo->prepare('
        SELECT 1 FROM perizinan
        WHERE santri_id = :sid AND :tgl BETWEEN tanggal_mulai AND tanggal_selesai
          AND approval_status IN ("DISETUJUI", "PENDING")
          AND jenis_izin IN ("SAKIT", "KELUAR", "PULANG")
        LIMIT 1
    ');
    $st2->execute(['sid' => $santriId, 'tgl' => $tanggal]);

    return (bool) $st2->fetchColumn();
}

/**
 * @param array<string, mixed> $input
 * @return array{ok:bool,message:string,id?:int}
 */
function akademik_setoran_simpan(PDO $pdo, array $input, array $ctx): array
{
    ensure_akademik_setoran_extended_schema($pdo);

    $sid = (int) ($input['santri_id'] ?? 0);
    $tanggal = trim((string) ($input['tanggal_setoran'] ?? date('Y-m-d')));
    $kategori = 'BAIT';
    $baitId = (int) ($input['bait_kitab_id'] ?? 0);
    $barisSetor = max(0, (int) ($input['baris_setor'] ?? 0));
    $jenisEntri = strtoupper(trim((string) ($input['jenis_entri'] ?? 'HARIAN')));
    if (!in_array($jenisEntri, ['TIKROR', 'HARIAN'], true)) {
        $jenisEntri = 'HARIAN';
    }
    $catatan = trim((string) ($input['catatan'] ?? ''));

    if ($sid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return ['ok' => false, 'message' => 'Santri dan tanggal wajib valid.'];
    }
    if ($baitId <= 0) {
        return ['ok' => false, 'message' => 'Pilih kitab bait.'];
    }
    if ($barisSetor <= 0) {
        return ['ok' => false, 'message' => 'Jumlah baris setoran harus lebih dari 0.'];
    }

    $chk = $pdo->prepare('SELECT id, tingkatan, nama_santri FROM santri WHERE id = :id LIMIT 1');
    if (!column_exists($pdo, 'santri', 'nama_santri')) {
        $chk = $pdo->prepare('SELECT id, tingkatan, nama AS nama_santri FROM santri WHERE id = :id LIMIT 1');
    }
    $chk->execute(['id' => $sid]);
    $santri = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$santri) {
        return ['ok' => false, 'message' => 'Santri tidak ditemukan.'];
    }
    if (!akademik_setoran_can_terima_santri($pdo, $santri, $ctx)) {
        return ['ok' => false, 'message' => 'Santri di luar tingkatan yang Anda terima setoran.'];
    }

    $bk = $pdo->prepare('SELECT nama_kitab FROM akademik_bait_kitab WHERE id = :id AND is_aktif = 1 LIMIT 1');
    $bk->execute(['id' => $baitId]);
    $brow = $bk->fetch(PDO::FETCH_ASSOC);
    if (!$brow) {
        return ['ok' => false, 'message' => 'Kitab bait tidak valid.'];
    }

    $liburInfo = akademik_libur_info($pdo, $tanggal, 'setoran');
    if ($liburInfo !== null && akademik_blokir_setoran_libur($pdo)) {
        return ['ok' => false, 'message' => 'Tanggal libur setoran: ' . $liburInfo['nama']];
    }

    if (akademik_setoran_sudah_hari_ini($pdo, $sid, $tanggal)) {
        return ['ok' => false, 'message' => 'Setoran santri untuk tanggal ini sudah tercatat.'];
    }

    $target = 'Bait: ' . (string) $brow['nama_kitab'] . ' (' . $barisSetor . ' baris · ' . strtolower($jenisEntri) . ')';
    $hijri = akademik_hijri_tanggal_penuh($pdo, $tanggal);
    $pbId = (int) ($ctx['pembimbing_id'] ?? 0) ?: null;
    $mwId = (int) ($ctx['munawib_id'] ?? 0) ?: null;

    $ins = $pdo->prepare('
        INSERT INTO akademik_hafalan_setoran (
            santri_id, tanggal_setoran, kategori_setoran, bait_kitab_id, baris_setor,
            kalender_hijriyah, target_hafalan, jenis_entri, pembimbing_id, munawib_id,
            catatan, created_by
        ) VALUES (
            :sid, :tgl, :kat, :bid, :baris, :hij, :tgt, :je, :pb, :mw, :cat, :uid
        )
    ');
    $ins->execute([
        'sid' => $sid,
        'tgl' => $tanggal,
        'kat' => $kategori,
        'bid' => $baitId,
        'baris' => $barisSetor,
        'hij' => $hijri,
        'tgt' => mb_substr($target, 0, 255),
        'je' => $jenisEntri,
        'pb' => $pbId,
        'mw' => $mwId,
        'cat' => $catatan !== '' ? $catatan : null,
        'uid' => (int) ($_SESSION['user']['id'] ?? 0) ?: null,
    ]);

    return ['ok' => true, 'message' => 'Setoran ' . (string) $santri['nama_santri'] . ' tersimpan.', 'id' => (int) $pdo->lastInsertId()];
}

/**
 * @param list<string> $tingkatanFilter
 * @return list<array<string, mixed>>
 */
function akademik_setoran_rekap_kehadiran(PDO $pdo, string $mulai, string $selesai, array $tingkatanFilter): array
{
    ensure_akademik_setoran_extended_schema($pdo);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $mulai) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selesai)) {
        return [];
    }

    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $sql = 'SELECT id, nis, ' . $nameCol . ' AS nama_santri, tingkatan FROM santri WHERE 1=1';
    if (column_exists($pdo, 'santri', 'is_aktif')) {
        $sql .= ' AND COALESCE(is_aktif, 1) = 1';
    }
    $params = [];
    if ($tingkatanFilter !== []) {
        $ph = implode(',', array_fill(0, count($tingkatanFilter), '?'));
        $sql .= ' AND tingkatan IN (' . $ph . ')';
        $params = $tingkatanFilter;
    }
    $sql .= ' ORDER BY tingkatan ASC, ' . $nameCol . ' ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $santriRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    $tsMulai = strtotime($mulai);
    $tsSelesai = strtotime($selesai);
    if ($tsMulai === false || $tsSelesai === false) {
        return [];
    }

    for ($ts = $tsMulai; $ts <= $tsSelesai; $ts += 86400) {
        $tgl = date('Y-m-d', $ts);
        if (akademik_libur_info($pdo, $tgl, 'setoran') !== null && akademik_blokir_setoran_libur($pdo)) {
            continue;
        }
        foreach ($santriRows as $sr) {
            $sid = (int) ($sr['id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $status = 'ALPA';
            if (akademik_setoran_sudah_hari_ini($pdo, $sid, $tgl)) {
                $status = 'SETOR';
            } elseif (akademik_setoran_izin_atau_sakit($pdo, $sid, $tgl)) {
                $status = 'IZIN';
            }
            $key = $sid . '|' . $tgl;
            $out[$key] = [
                'santri_id' => $sid,
                'nis' => (string) ($sr['nis'] ?? ''),
                'nama_santri' => (string) ($sr['nama_santri'] ?? ''),
                'tingkatan' => (string) ($sr['tingkatan'] ?? ''),
                'tanggal' => $tgl,
                'status' => $status,
            ];
        }
    }

    return array_values($out);
}

/**
 * @param list<string> $tingkatanFilter
 * @return list<array<string, mixed>>
 */
function akademik_setoran_rekap_perolehan(PDO $pdo, string $mulai, string $selesai, array $tingkatanFilter): array
{
    ensure_akademik_setoran_extended_schema($pdo);
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';

    $whereTk = '';
    $execParams = [$mulai, $selesai];
    if ($tingkatanFilter !== []) {
        $ph = implode(',', array_fill(0, count($tingkatanFilter), '?'));
        $whereTk = ' AND s.tingkatan IN (' . $ph . ')';
        $execParams = array_merge($tingkatanFilter, [$mulai, $selesai]);
    }

    $sql = '
        SELECT s.id AS santri_id, s.nis, s.' . $nameCol . ' AS nama_santri, s.tingkatan,
               k.id AS kitab_id, k.nama_kitab,
               COALESCE(SUM(h.baris_setor), 0) AS total_baris,
               COUNT(h.id) AS jumlah_setoran
        FROM akademik_hafalan_setoran h
        INNER JOIN santri s ON s.id = h.santri_id
        LEFT JOIN akademik_bait_kitab k ON k.id = h.bait_kitab_id
        WHERE h.kategori_setoran = "BAIT"
          ' . $whereTk . '
          AND h.tanggal_setoran BETWEEN ? AND ?
        GROUP BY s.id, k.id
        ORDER BY s.tingkatan ASC, s.' . $nameCol . ' ASC, k.nama_kitab ASC
    ';
    $st = $pdo->prepare($sql);
    $st->execute($execParams);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function akademik_setoran_hari_wajib_count(PDO $pdo, string $mulai, string $selesai): int
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $mulai) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selesai)) {
        return 0;
    }
    $tsMulai = strtotime($mulai);
    $tsSelesai = strtotime($selesai);
    if ($tsMulai === false || $tsSelesai === false) {
        return 0;
    }
    $count = 0;
    for ($ts = $tsMulai; $ts <= $tsSelesai; $ts += 86400) {
        $tgl = date('Y-m-d', $ts);
        if (akademik_libur_info($pdo, $tgl, 'setoran') !== null && akademik_blokir_setoran_libur($pdo)) {
            continue;
        }
        $count++;
    }

    return $count;
}

/** @return 'SETOR'|'IZIN'|'BELUM'|'LIBUR' */
function akademik_setoran_status_today(PDO $pdo, int $santriId, string $tanggal): string
{
    if ($santriId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return 'BELUM';
    }
    if (akademik_libur_info($pdo, $tanggal, 'setoran') !== null && akademik_blokir_setoran_libur($pdo)) {
        return 'LIBUR';
    }
    if (akademik_setoran_sudah_hari_ini($pdo, $santriId, $tanggal)) {
        return 'SETOR';
    }
    if (akademik_setoran_izin_atau_sakit($pdo, $santriId, $tanggal)) {
        return 'IZIN';
    }

    return 'BELUM';
}

/**
 * @return array{wajib:int,setor:int,izin:int,alpa:int,persen_setor:float}
 */
function akademik_setoran_statistik_santri_periode(PDO $pdo, int $santriId, string $mulai, string $selesai): array
{
    $setor = $izin = $alpa = 0;
    if ($santriId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $mulai) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selesai)) {
        return ['wajib' => 0, 'setor' => 0, 'izin' => 0, 'alpa' => 0, 'persen_setor' => 0.0];
    }
    $tsMulai = strtotime($mulai);
    $tsSelesai = strtotime($selesai);
    if ($tsMulai === false || $tsSelesai === false) {
        return ['wajib' => 0, 'setor' => 0, 'izin' => 0, 'alpa' => 0, 'persen_setor' => 0.0];
    }
    for ($ts = $tsMulai; $ts <= $tsSelesai; $ts += 86400) {
        $tgl = date('Y-m-d', $ts);
        if (akademik_libur_info($pdo, $tgl, 'setoran') !== null && akademik_blokir_setoran_libur($pdo)) {
            continue;
        }
        if (akademik_setoran_sudah_hari_ini($pdo, $santriId, $tgl)) {
            $setor++;
        } elseif (akademik_setoran_izin_atau_sakit($pdo, $santriId, $tgl)) {
            $izin++;
        } else {
            $alpa++;
        }
    }
    $wajib = $setor + $izin + $alpa;
    $persen = $wajib > 0 ? round($setor / $wajib * 100, 1) : 0.0;

    return [
        'wajib' => $wajib,
        'setor' => $setor,
        'izin' => $izin,
        'alpa' => $alpa,
        'persen_setor' => $persen,
    ];
}

function akademik_setoran_is_lancar(array $stats, float $threshold = 80.0): bool
{
    $wajib = (int) ($stats['wajib'] ?? 0);
    if ($wajib === 0) {
        return true;
    }

    return (float) ($stats['persen_setor'] ?? 0) >= $threshold;
}

/**
 * @return list<array<string, mixed>>
 */
function akademik_setoran_santri_list_for_ctx(PDO $pdo, array $ctx, string $tanggal = ''): array
{
    require_once __DIR__ . '/pembimbing_dashboard.php';
    $tingkatanFilter = $ctx['tingkatan_allowed'] ?? [];
    if ($tingkatanFilter === []) {
        return [];
    }
    if ($tanggal === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        $tanggal = date('Y-m-d');
    }
    $map = pembimbing_dashboard_santri_list_map($pdo, $tingkatanFilter, 500, (int) ($ctx['pembimbing_id'] ?? 0) ?: null);
    $out = [];
    foreach ($map as $tk => $rows) {
        foreach ($rows as $row) {
            $sid = (int) ($row['id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $status = akademik_setoran_status_today($pdo, $sid, $tanggal);
            $out[] = [
                'id' => $sid,
                'nis' => (string) ($row['nis'] ?? ''),
                'nama_santri' => (string) ($row['nama_santri'] ?? ''),
                'tingkatan' => (string) ($row['tingkatan'] ?? $tk),
                'status_hari_ini' => $status,
            ];
        }
    }

    return $out;
}

/**
 * @param list<string> $tingkatanFilter
 * @return list<array<string, mixed>>
 */
function akademik_setoran_rekap_perolehan_dengan_lancar(PDO $pdo, string $mulai, string $selesai, array $tingkatanFilter): array
{
    $perolehanRows = akademik_setoran_rekap_perolehan($pdo, $mulai, $selesai, $tingkatanFilter);
    $bySantri = [];
    foreach ($perolehanRows as $pr) {
        $sid = (int) ($pr['santri_id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        if (!isset($bySantri[$sid])) {
            $stats = akademik_setoran_statistik_santri_periode($pdo, $sid, $mulai, $selesai);
            $bySantri[$sid] = [
                'santri_id' => $sid,
                'nis' => (string) ($pr['nis'] ?? ''),
                'nama_santri' => (string) ($pr['nama_santri'] ?? ''),
                'tingkatan' => (string) ($pr['tingkatan'] ?? ''),
                'total_baris' => 0,
                'jumlah_setoran' => 0,
                'kitab' => [],
                'stats' => $stats,
                'lancar' => akademik_setoran_is_lancar($stats),
            ];
        }
        $baris = (int) ($pr['total_baris'] ?? 0);
        $freq = (int) ($pr['jumlah_setoran'] ?? 0);
        $bySantri[$sid]['total_baris'] += $baris;
        $bySantri[$sid]['jumlah_setoran'] += $freq;
        $bySantri[$sid]['kitab'][] = [
            'nama_kitab' => (string) ($pr['nama_kitab'] ?? '—'),
            'total_baris' => $baris,
            'jumlah_setoran' => $freq,
        ];
    }

    if ($bySantri === [] && $tingkatanFilter !== []) {
        $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
        $ph = implode(',', array_fill(0, count($tingkatanFilter), '?'));
        $sql = 'SELECT id, nis, ' . $nameCol . ' AS nama_santri, tingkatan FROM santri WHERE tingkatan IN (' . $ph . ')';
        if (column_exists($pdo, 'santri', 'is_aktif')) {
            $sql .= ' AND COALESCE(is_aktif, 1) = 1';
        }
        $sql .= ' ORDER BY tingkatan ASC, ' . $nameCol . ' ASC';
        $st = $pdo->prepare($sql);
        $st->execute($tingkatanFilter);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $sr) {
            $sid = (int) ($sr['id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $stats = akademik_setoran_statistik_santri_periode($pdo, $sid, $mulai, $selesai);
            $bySantri[$sid] = [
                'santri_id' => $sid,
                'nis' => (string) ($sr['nis'] ?? ''),
                'nama_santri' => (string) ($sr['nama_santri'] ?? ''),
                'tingkatan' => (string) ($sr['tingkatan'] ?? ''),
                'total_baris' => 0,
                'jumlah_setoran' => 0,
                'kitab' => [],
                'stats' => $stats,
                'lancar' => akademik_setoran_is_lancar($stats),
            ];
        }
    }

    $out = array_values($bySantri);
    usort($out, static function (array $a, array $b): int {
        $c = strcmp((string) ($a['tingkatan'] ?? ''), (string) ($b['tingkatan'] ?? ''));
        if ($c !== 0) {
            return $c;
        }

        return strcmp((string) ($a['nama_santri'] ?? ''), (string) ($b['nama_santri'] ?? ''));
    });

    return $out;
}

/**
 * Keaktivan berdasarkan presensi setoran (bukan kegiatan jadwal).
 *
 * @param list<string> $tingkatanFilter
 * @return list<array<string, mixed>>
 */
function akademik_setoran_keaktivan_tahun(PDO $pdo, array $ctx, int $tahun): array
{
    require_once __DIR__ . '/pembimbing_dashboard.php';
    $tingkatanFilter = $ctx['tingkatan_allowed'] ?? [];
    if ($tingkatanFilter === [] || !table_exists($pdo, 'santri')) {
        return [];
    }
    if ($tahun < 2000 || $tahun > 2100) {
        $tahun = (int) date('Y');
    }
    [$mulai, $selesai] = pembimbing_dashboard_tahun_presensi_bounds($tahun);
    if ($mulai > date('Y-m-d')) {
        $selesai = $mulai;
    } elseif ($selesai > date('Y-m-d')) {
        $selesai = date('Y-m-d');
    }

    $goodMax = (int) app_setting($pdo, 'kategori_baik_max', '1');
    $mediumMax = (int) app_setting($pdo, 'kategori_sedang_max', '3');
    $santriList = akademik_setoran_santri_list_for_ctx($pdo, $ctx, date('Y-m-d'));
    $out = [];
    foreach ($santriList as $sr) {
        $sid = (int) ($sr['id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $stats = akademik_setoran_statistik_santri_periode($pdo, $sid, $mulai, $selesai);
        $alpa = (int) ($stats['alpa'] ?? 0);
        $kategori = santri_category($alpa, $goodMax, $mediumMax);
        $label = match ($kategori) {
            'bagus' => 'Bagus',
            'sedang' => 'Sedang',
            'buruk' => 'Buruk',
            default => $stats['wajib'] > 0 ? 'Belum dinilai' : 'Belum ada data',
        };
        $out[] = [
            'santri_id' => $sid,
            'nis' => (string) ($sr['nis'] ?? ''),
            'nama_santri' => (string) ($sr['nama_santri'] ?? ''),
            'tingkatan' => (string) ($sr['tingkatan'] ?? ''),
            'setor' => (int) ($stats['setor'] ?? 0),
            'izin' => (int) ($stats['izin'] ?? 0),
            'alpa' => $alpa,
            'wajib' => (int) ($stats['wajib'] ?? 0),
            'persen_setor' => (float) ($stats['persen_setor'] ?? 0),
            'kategori' => $kategori,
            'label' => $label,
            'lancar' => akademik_setoran_is_lancar($stats),
        ];
    }

    return $out;
}

/**
 * Rekap agregat per nama kitab bait.
 *
 * @param list<string> $tingkatanFilter kosong = semua tingkatan
 * @return list<array<string, mixed>>
 */
function akademik_setoran_rekap_per_kitab(PDO $pdo, string $mulai, string $selesai, array $tingkatanFilter): array
{
    ensure_akademik_setoran_extended_schema($pdo);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $mulai) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selesai)) {
        return [];
    }

    $whereTk = '';
    $params = [$mulai, $selesai];
    if ($tingkatanFilter !== []) {
        $ph = implode(',', array_fill(0, count($tingkatanFilter), '?'));
        $whereTk = ' AND s.tingkatan IN (' . $ph . ')';
        $params = array_merge($tingkatanFilter, [$mulai, $selesai]);
    }

    $sql = '
        SELECT k.id AS kitab_id, k.nama_kitab, k.jumlah_baris,
               COUNT(DISTINCT h.santri_id) AS jumlah_santri,
               COALESCE(SUM(h.baris_setor), 0) AS total_baris,
               COUNT(h.id) AS frekuensi_setor
        FROM akademik_hafalan_setoran h
        INNER JOIN akademik_bait_kitab k ON k.id = h.bait_kitab_id
        INNER JOIN santri s ON s.id = h.santri_id
        WHERE h.kategori_setoran = "BAIT"
          ' . $whereTk . '
          AND h.tanggal_setoran BETWEEN ? AND ?
        GROUP BY k.id, k.nama_kitab, k.jumlah_baris
        ORDER BY k.urutan ASC, k.nama_kitab ASC
    ';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $detailWhereTk = $tingkatanFilter !== []
        ? ' AND s.tingkatan IN (' . implode(',', array_fill(0, count($tingkatanFilter), '?')) . ')'
        : '';
    $detailSql = '
        SELECT s.id AS santri_id, s.nis, s.' . $nameCol . ' AS nama_santri, s.tingkatan,
               COALESCE(SUM(h.baris_setor), 0) AS total_baris,
               COUNT(h.id) AS frekuensi_setor
        FROM akademik_hafalan_setoran h
        INNER JOIN santri s ON s.id = h.santri_id
        WHERE h.kategori_setoran = "BAIT"
          AND h.bait_kitab_id = :kid
          AND h.tanggal_setoran BETWEEN :mulai AND :selesai
          ' . $detailWhereTk . '
        GROUP BY s.id
        ORDER BY s.tingkatan ASC, s.' . $nameCol . ' ASC
    ';
    $detailSt = $pdo->prepare($detailSql);

    foreach ($rows as &$row) {
        $kid = (int) ($row['kitab_id'] ?? 0);
        $detailParams = ['kid' => $kid, 'mulai' => $mulai, 'selesai' => $selesai];
        if ($tingkatanFilter !== []) {
            $detailParams = array_merge($detailParams, $tingkatanFilter);
        }
        $detailSt->execute($detailParams);
        $row['santri'] = $detailSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    unset($row);

    return $rows;
}
