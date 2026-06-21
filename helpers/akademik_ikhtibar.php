<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/santri_list_sort.php';

/** Kuota jumlah soal PG & esai. */
function ikhtibar_kuota_pg(): array
{
    return [5, 10, 15, 20, 25, 30];
}

function ikhtibar_kuota_esai(): array
{
    return [5, 10, 15];
}

function ensure_akademik_ikhtibar_tables(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!empty($_SESSION['ikhtibar_schema_ready_v1'])) {
        return;
    }

    $tablesReady = table_exists($pdo, 'ikhtibar_tugas')
        && table_exists($pdo, 'ikhtibar_soal')
        && table_exists($pdo, 'ikhtibar_sesi')
        && table_exists($pdo, 'ikhtibar_jawaban');
    if ($tablesReady) {
        if (app_setting($pdo, 'ikhtibar_schema_ready_v1', '') !== '1') {
            save_setting($pdo, 'ikhtibar_schema_ready_v1', '1');
        }
        $_SESSION['ikhtibar_schema_ready_v1'] = 1;

        return;
    }

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS ikhtibar_tugas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            judul VARCHAR(200) NOT NULL,
            tanggal DATE NOT NULL,
            hari_ke TINYINT NULL COMMENT "1=Senin..7=Minggu",
            durasi_menit INT NOT NULL DEFAULT 60,
            pakai_token TINYINT(1) NOT NULL DEFAULT 0,
            token_hash VARCHAR(255) NULL,
            token_plain VARCHAR(20) NULL,
            status ENUM("draft","published","closed") NOT NULL DEFAULT "draft",
            jumlah_pg INT NOT NULL DEFAULT 0,
            jumlah_esai INT NOT NULL DEFAULT 0,
            filter_tingkatan VARCHAR(80) NULL,
            catatan TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_ikhtibar_tugas_tgl (tanggal, status),
            INDEX idx_ikhtibar_tugas_user (created_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS ikhtibar_soal (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tugas_id INT NOT NULL,
            jenis ENUM("PG","ESAI") NOT NULL,
            nomor INT NOT NULL,
            teks_soal TEXT NOT NULL,
            opsi_a VARCHAR(500) NULL,
            opsi_b VARCHAR(500) NULL,
            opsi_c VARCHAR(500) NULL,
            opsi_d VARCHAR(500) NULL,
            opsi_e VARCHAR(500) NULL,
            kunci_jawaban VARCHAR(500) NULL,
            INDEX idx_ikhtibar_soal_tugas (tugas_id, jenis, nomor)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS ikhtibar_sesi (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tugas_id INT NOT NULL,
            santri_id INT NOT NULL,
            urutan_soal_json TEXT NULL,
            waktu_mulai DATETIME NULL,
            waktu_selesai DATETIME NULL,
            durasi_menit INT NOT NULL DEFAULT 0,
            status ENUM("menunggu","berjalan","selesai","habis_waktu") NOT NULL DEFAULT "menunggu",
            skor_pg DECIMAL(6,2) NULL,
            skor_esai DECIMAL(6,2) NULL,
            nilai_total DECIMAL(6,2) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_ikhtibar_sesi (tugas_id, santri_id),
            INDEX idx_ikhtibar_sesi_santri (santri_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS ikhtibar_jawaban (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sesi_id INT NOT NULL,
            soal_id INT NOT NULL,
            jawaban_santri TEXT NULL,
            benar TINYINT(1) NULL,
            nilai_esai DECIMAL(6,2) NULL,
            catatan_pembimbing VARCHAR(500) NULL,
            UNIQUE KEY uk_ikhtibar_jawab (sesi_id, soal_id),
            INDEX idx_ikhtibar_jawab_sesi (sesi_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');

    if (function_exists('akademik_add_column')) {
        require_once __DIR__ . '/akademik.php';
        akademik_add_column($pdo, 'ikhtibar_tugas', 'kegiatan_id', 'INT NULL');
        akademik_add_column($pdo, 'ikhtibar_tugas', 'jadwal_kegiatan_id', 'INT NULL');
        akademik_add_column($pdo, 'ikhtibar_tugas', 'mapel_label', 'VARCHAR(200) NULL');
        akademik_add_column($pdo, 'ikhtibar_tugas', 'tanggal_selesai', 'DATE NULL');
        akademik_add_column($pdo, 'ikhtibar_tugas', 'sumber', 'VARCHAR(20) NOT NULL DEFAULT "IKHTIBAR"');
        akademik_add_column($pdo, 'ikhtibar_tugas', 'pkpps_jadwal_id', 'INT NULL');
        akademik_add_column($pdo, 'ikhtibar_tugas', 'pkpps_tingkatan_id', 'INT NULL');
    }
    require_once __DIR__ . '/ikhtibar_kriteria.php';
    ikhtibar_kriteria_ensure_schema($pdo);
    save_setting($pdo, 'ikhtibar_schema_ready_v1', '1');
    $_SESSION['ikhtibar_schema_ready_v1'] = 1;
}

/** ID baris pembimbing (SDM) dari akun login users (cocokkan NIP = username). */
function ikhtibar_pembimbing_sdm_id_dari_user(PDO $pdo, int $userId): int
{
    if ($userId <= 0 || !table_exists($pdo, 'users') || !table_exists($pdo, 'pembimbing')) {
        return 0;
    }
    $st = $pdo->prepare('SELECT username FROM users WHERE id = :id LIMIT 1');
    $st->execute(['id' => $userId]);
    $nip = trim((string) ($st->fetchColumn() ?: ''));
    if ($nip === '') {
        return 0;
    }
    $st2 = $pdo->prepare('SELECT id FROM pembimbing WHERE nip = :nip AND COALESCE(is_aktif, 1) = 1 LIMIT 1');
    $st2->execute(['nip' => $nip]);
    $pid = (int) ($st2->fetchColumn() ?: 0);

    return $pid > 0 ? $pid : 0;
}

/**
 * Opsi jadwal (kelas/mapel) untuk form tugas pembimbing.
 *
 * @return list<array{id:int,kegiatan_id:int,label:string,mapel_label:string}>
 */
function ikhtibar_jadwal_options(PDO $pdo, int $userId): array
{
    if (!table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan')) {
        return [];
    }
    $pdo->exec('ALTER TABLE jadwal_kegiatan ADD COLUMN IF NOT EXISTS pembimbing_id INT NULL');

    $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
    $bolehSemua = is_super_admin() || in_array($role, ['admin', 'pengurus'], true);
    $pembimbingSdmId = $bolehSemua ? 0 : ikhtibar_pembimbing_sdm_id_dari_user($pdo, $userId);

    $sql = '
        SELECT j.id, j.tingkatan, j.hari_ke, j.jam_mulai, j.jam_selesai, k.id AS kegiatan_id, k.nama_kegiatan
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
    ';
    $params = [];
    if ($pembimbingSdmId > 0) {
        $sql .= ' WHERE j.pembimbing_id = :pid';
        $params['pid'] = $pembimbingSdmId;
    }
    $sql .= ' ORDER BY k.nama_kegiatan ASC, j.tingkatan ASC, j.hari_ke ASC, j.jam_mulai ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $kg = trim((string) ($r['nama_kegiatan'] ?? ''));
        $tk = trim((string) ($r['tingkatan'] ?? ''));
        $hari = ikhtibar_hari_label((int) ($r['hari_ke'] ?? 0));
        $jam = substr((string) ($r['jam_mulai'] ?? ''), 0, 5);
        $mapelLabel = $kg . ($tk !== '' ? ' — ' . $tk : '');
        $label = $mapelLabel . ' (' . $hari . ($jam !== '' ? ' ' . $jam : '') . ')';
        $out[] = [
            'id' => (int) $r['id'],
            'kegiatan_id' => (int) $r['kegiatan_id'],
            'label' => $label,
            'mapel_label' => $mapelLabel,
        ];
    }

    return $out;
}

/**
 * Opsi jadwal PKPPS untuk form tugas pembimbing.
 *
 * @return list<array{id:int,pkpps_tingkatan_id:int,kegiatan_id:int,label:string,mapel_label:string}>
 */
function ikhtibar_pkpps_jadwal_options(PDO $pdo, int $userId): array
{
    if (!table_exists($pdo, 'pkpps_jadwal') || !table_exists($pdo, 'pkpps_tingkatan') || !table_exists($pdo, 'kegiatan')) {
        return [];
    }
    require_once __DIR__ . '/pkpps.php';
    pkpps_ensure_schema($pdo);

    $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
    $bolehSemua = is_super_admin() || in_array($role, ['admin', 'pengurus'], true);
    $pembimbingSdmId = $bolehSemua ? 0 : ikhtibar_pembimbing_sdm_id_dari_user($pdo, $userId);

    $sql = '
        SELECT j.id, j.pkpps_tingkatan_id, j.hari_ke, j.jam_mulai, j.jam_selesai, j.tempat,
               t.nama_tingkatan, k.id AS kegiatan_id, k.nama_kegiatan
        FROM pkpps_jadwal j
        INNER JOIN pkpps_tingkatan t ON t.id = j.pkpps_tingkatan_id
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        WHERE j.is_aktif = 1 AND t.is_aktif = 1
    ';
    $params = [];
    if ($pembimbingSdmId > 0) {
        $sql .= ' AND j.pembimbing_id = :pid';
        $params['pid'] = $pembimbingSdmId;
    }
    $sql .= ' ORDER BY t.urutan ASC, t.nama_tingkatan ASC, j.hari_ke ASC, j.jam_mulai ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $tk = trim((string) ($r['nama_tingkatan'] ?? ''));
        $kg = trim((string) ($r['nama_kegiatan'] ?? ''));
        $hari = ikhtibar_hari_label((int) ($r['hari_ke'] ?? 0));
        $jam = substr((string) ($r['jam_mulai'] ?? ''), 0, 5);
        $mapelLabel = 'PKPPS · ' . $tk . ($kg !== '' ? ' — ' . $kg : '');
        $label = $mapelLabel . ' (' . $hari . ($jam !== '' ? ' ' . $jam : '') . ')';
        $out[] = [
            'id' => (int) $r['id'],
            'pkpps_tingkatan_id' => (int) ($r['pkpps_tingkatan_id'] ?? 0),
            'kegiatan_id' => (int) ($r['kegiatan_id'] ?? 0),
            'label' => $label,
            'mapel_label' => $mapelLabel,
        ];
    }

    return $out;
}

/** @return array{kegiatan_id:?int,pkpps_jadwal_id:?int,pkpps_tingkatan_id:?int,mapel_label:?string}|null */
function ikhtibar_resolve_pkpps_dari_post(PDO $pdo, array $post, int $userId): ?array
{
    $jadwalId = (int) ($post['pkpps_jadwal_id'] ?? 0);
    if ($jadwalId <= 0) {
        return null;
    }
    require_once __DIR__ . '/pkpps.php';
    pkpps_ensure_schema($pdo);
    $st = $pdo->prepare('
        SELECT j.id, j.kegiatan_id, j.pkpps_tingkatan_id, j.pembimbing_id, t.nama_tingkatan, k.nama_kegiatan
        FROM pkpps_jadwal j
        INNER JOIN pkpps_tingkatan t ON t.id = j.pkpps_tingkatan_id
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        WHERE j.id = :id AND j.is_aktif = 1
        LIMIT 1
    ');
    $st->execute(['id' => $jadwalId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
    $bolehSemua = is_super_admin() || in_array($role, ['admin', 'pengurus'], true);
    if (!$bolehSemua) {
        $pid = ikhtibar_pembimbing_sdm_id_dari_user($pdo, $userId);
        $rowPid = (int) ($row['pembimbing_id'] ?? 0);
        if ($pid > 0 && $rowPid > 0 && $rowPid !== $pid) {
            return null;
        }
    }
    $tk = trim((string) ($row['nama_tingkatan'] ?? ''));
    $kg = trim((string) ($row['nama_kegiatan'] ?? ''));

    return [
        'kegiatan_id' => (int) ($row['kegiatan_id'] ?? 0) ?: null,
        'pkpps_jadwal_id' => $jadwalId,
        'pkpps_tingkatan_id' => (int) ($row['pkpps_tingkatan_id'] ?? 0) ?: null,
        'mapel_label' => 'PKPPS · ' . $tk . ($kg !== '' ? ' — ' . $kg : '') ?: null,
    ];
}

function ikhtibar_santri_pkpps_aktif(PDO $pdo, int $santriId, int $pkppsTingkatanId): bool
{
    if ($santriId <= 0 || $pkppsTingkatanId <= 0 || !table_exists($pdo, 'pkpps_santri')) {
        return false;
    }
    require_once __DIR__ . '/pkpps.php';
    pkpps_ensure_schema($pdo);
    $st = $pdo->prepare('
        SELECT 1 FROM pkpps_santri
        WHERE santri_id = :sid AND pkpps_tingkatan_id = :tid AND is_aktif = 1
        LIMIT 1
    ');
    $st->execute(['sid' => $santriId, 'tid' => $pkppsTingkatanId]);

    return (bool) $st->fetchColumn();
}

/** @return array{kegiatan_id:?int,jadwal_kegiatan_id:?int,mapel_label:?string}|null */
function ikhtibar_resolve_mapel_dari_post(PDO $pdo, array $post, int $userId): ?array
{
    $jadwalId = (int) ($post['jadwal_kegiatan_id'] ?? 0);
    if ($jadwalId <= 0) {
        return null;
    }
    $pdo->exec('ALTER TABLE jadwal_kegiatan ADD COLUMN IF NOT EXISTS pembimbing_id INT NULL');
    $st = $pdo->prepare('
        SELECT j.id, j.kegiatan_id, j.tingkatan, j.pembimbing_id, k.nama_kegiatan
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        WHERE j.id = :id LIMIT 1
    ');
    $st->execute(['id' => $jadwalId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
    $bolehSemua = is_super_admin() || in_array($role, ['admin', 'pengurus'], true);
    if (!$bolehSemua) {
        $pid = ikhtibar_pembimbing_sdm_id_dari_user($pdo, $userId);
        $rowPid = (int) ($row['pembimbing_id'] ?? 0);
        if ($pid > 0 && $rowPid > 0 && $rowPid !== $pid) {
            return null;
        }
    }
    $kg = trim((string) ($row['nama_kegiatan'] ?? ''));
    $tk = trim((string) ($row['tingkatan'] ?? ''));

    return [
        'kegiatan_id' => (int) ($row['kegiatan_id'] ?? 0) ?: null,
        'jadwal_kegiatan_id' => $jadwalId,
        'mapel_label' => $kg . ($tk !== '' ? ' — ' . $tk : '') ?: null,
    ];
}

function ikhtibar_user_matches_pembimbing_nip(PDO $pdo): bool
{
    if (!isset($_SESSION['user']) || !table_exists($pdo, 'pembimbing')) {
        return false;
    }
    $nip = trim((string) ($_SESSION['user']['username'] ?? ''));
    if ($nip === '') {
        return false;
    }
    $aktif = column_exists($pdo, 'pembimbing', 'is_aktif')
        ? ' AND COALESCE(is_aktif, 1) = 1'
        : '';
    $st = $pdo->prepare('SELECT 1 FROM pembimbing WHERE TRIM(nip) = :nip' . $aktif . ' LIMIT 1');
    $st->execute(['nip' => $nip]);

    return (bool) $st->fetchColumn();
}

function ikhtibar_require_pembimbing_access(): void
{
    require_once __DIR__ . '/../includes/auth.php';
    require_login();
    require_once __DIR__ . '/munawib_portal.php';
    munawib_portal_guard_halaman();
    if (is_super_admin()) {
        return;
    }
    $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
    if (in_array($role, ['admin', 'pengurus', 'pembimbing'], true)) {
        return;
    }
    global $pdo;
    if ($pdo instanceof PDO && ikhtibar_user_matches_pembimbing_nip($pdo)) {
        return;
    }
    require_once __DIR__ . '/../helpers/app_path.php';
    if (user_has_current_page_permission()) {
        return;
    }
    set_flash('error', 'Anda tidak memiliki akses modul Tugas Ikhtibar.');
    auth_redirect_access_denied();
}

function ikhtibar_generate_token(int $length = 6): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, strlen($chars) - 1)];
    }

    return $out;
}

function ikhtibar_set_token(PDO $pdo, int $tugasId, ?string $plain = null): string
{
    ensure_akademik_ikhtibar_tables($pdo);
    $plain = $plain !== null && $plain !== '' ? strtoupper(preg_replace('/[^A-Z0-9]/', '', $plain) ?? '') : ikhtibar_generate_token();
    if ($plain === '') {
        $plain = ikhtibar_generate_token();
    }
    $hash = password_hash($plain, PASSWORD_DEFAULT);
    $pdo->prepare('UPDATE ikhtibar_tugas SET pakai_token = 1, token_hash = :h, token_plain = :p, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
        ->execute(['h' => $hash, 'p' => $plain, 'id' => $tugasId]);

    return $plain;
}

function ikhtibar_verify_token(PDO $pdo, int $tugasId, string $input): bool
{
    $stmt = $pdo->prepare('SELECT pakai_token, token_hash FROM ikhtibar_tugas WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $tugasId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int) ($row['pakai_token'] ?? 0) !== 1) {
        return true;
    }
    $hash = (string) ($row['token_hash'] ?? '');
    if ($hash === '') {
        return false;
    }
    $input = strtoupper(trim($input));

    return password_verify($input, $hash) || hash_equals($hash, $input);
}

/**
 * @return array<string, mixed>|null
 */
function ikhtibar_tugas_by_id(PDO $pdo, int $id): ?array
{
    ensure_akademik_ikhtibar_tables($pdo);
    if ($id <= 0) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM ikhtibar_tugas WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @return list<array<string,mixed>>
 */
function ikhtibar_soal_by_tugas(PDO $pdo, int $tugasId): array
{
    ensure_akademik_ikhtibar_tables($pdo);
    $stmt = $pdo->prepare('SELECT * FROM ikhtibar_soal WHERE tugas_id = :id ORDER BY jenis ASC, nomor ASC');
    $stmt->execute(['id' => $tugasId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array<string,mixed>>
 */
function ikhtibar_tugas_list_pembimbing(PDO $pdo, int $userId, ?string $sumber = null): array
{
    ensure_akademik_ikhtibar_tables($pdo);
    $sql = 'SELECT t.*, 
            (SELECT COUNT(*) FROM ikhtibar_sesi s WHERE s.tugas_id = t.id AND s.status = "selesai") AS jumlah_selesai
            FROM ikhtibar_tugas t WHERE 1=1';
    $params = [];
    if ($sumber !== null) {
        $sql .= ' AND COALESCE(t.sumber, "IKHTIBAR") = :sumber';
        $params['sumber'] = strtoupper($sumber);
    }
    if ($userId > 0 && !is_super_admin()) {
        $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
        if (!in_array($role, ['admin', 'pengurus'], true)) {
            $sql .= ' AND t.created_by = :uid';
            $params['uid'] = $userId;
        }
    }
    $sql .= ' ORDER BY t.tanggal DESC, t.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array<string,mixed>>
 */
function ikhtibar_tugas_tersedia_santri(PDO $pdo, int $santriId, string $tingkatan, string $sumber = 'IKHTIBAR'): array
{
    ensure_akademik_ikhtibar_tables($pdo);
    $today = date('Y-m-d');
    $sumber = strtoupper($sumber);
    $stmt = $pdo->prepare('
        SELECT t.* FROM ikhtibar_tugas t
        WHERE t.status = "published"
          AND COALESCE(t.sumber, "IKHTIBAR") = :sumber
          AND t.tanggal <= :today
          AND (t.filter_tingkatan IS NULL OR t.filter_tingkatan = "" OR t.filter_tingkatan = :tingkat)
        ORDER BY t.tanggal DESC, t.id DESC
    ');
    $stmt->execute(['sumber' => $sumber, 'today' => $today, 'tingkat' => $tingkatan]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $t) {
        if ($sumber === 'PKPPS') {
            $ptid = (int) ($t['pkpps_tingkatan_id'] ?? 0);
            if ($ptid <= 0 || !ikhtibar_santri_pkpps_aktif($pdo, $santriId, $ptid)) {
                continue;
            }
        }
        $tid = (int) $t['id'];
        $soal = ikhtibar_soal_by_tugas($pdo, $tid);
        if ($soal === []) {
            continue;
        }
        $sesi = ikhtibar_sesi_get($pdo, $tid, $santriId);
        $t['sesi_status'] = $sesi['status'] ?? 'menunggu';
        $t['sesi_id'] = (int) ($sesi['id'] ?? 0);
        $out[] = $t;
    }

    return $out;
}

/**
 * @return array<string,mixed>|null
 */
function ikhtibar_sesi_get(PDO $pdo, int $tugasId, int $santriId): ?array
{
    ensure_akademik_ikhtibar_tables($pdo);
    $stmt = $pdo->prepare('SELECT * FROM ikhtibar_sesi WHERE tugas_id = :t AND santri_id = :s LIMIT 1');
    $stmt->execute(['t' => $tugasId, 's' => $santriId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/** Acak urutan soal per santri. */
function ikhtibar_shuffle_soal_ids(PDO $pdo, int $tugasId): array
{
    $ids = [];
    foreach (ikhtibar_soal_by_tugas($pdo, $tugasId) as $s) {
        $ids[] = (int) $s['id'];
    }
    shuffle($ids);

    return $ids;
}

function ikhtibar_mulai_sesi(PDO $pdo, int $tugasId, int $santriId): array
{
    ensure_akademik_ikhtibar_tables($pdo);
    $tugas = ikhtibar_tugas_by_id($pdo, $tugasId);
    if (!$tugas || (string) ($tugas['status'] ?? '') !== 'published') {
        return ['ok' => false, 'message' => 'Tugas tidak tersedia.'];
    }
    $sesi = ikhtibar_sesi_get($pdo, $tugasId, $santriId);
    if ($sesi && (string) ($sesi['status'] ?? '') === 'selesai') {
        return ['ok' => false, 'message' => 'Anda sudah menyelesaikan tugas ini.'];
    }
    if ($sesi && (string) ($sesi['status'] ?? '') === 'berjalan' && !empty($sesi['waktu_mulai'])) {
        return ['ok' => true, 'message' => 'Sesi sudah berjalan.', 'sesi_id' => (int) $sesi['id']];
    }
    $urutan = ikhtibar_shuffle_soal_ids($pdo, $tugasId);
    $durasi = max(0, (int) ($tugas['durasi_menit'] ?? 60));
    if ($sesi) {
        $pdo->prepare('
            UPDATE ikhtibar_sesi SET urutan_soal_json = :u, waktu_mulai = NOW(), status = "berjalan", durasi_menit = :d
            WHERE id = :id
        ')->execute([
            'u' => json_encode($urutan, JSON_THROW_ON_ERROR),
            'd' => $durasi,
            'id' => (int) $sesi['id'],
        ]);
        $sesiId = (int) $sesi['id'];
    } else {
        $pdo->prepare('
            INSERT INTO ikhtibar_sesi (tugas_id, santri_id, urutan_soal_json, waktu_mulai, durasi_menit, status)
            VALUES (:t, :s, :u, NOW(), :d, "berjalan")
        ')->execute([
            't' => $tugasId,
            's' => $santriId,
            'u' => json_encode($urutan, JSON_THROW_ON_ERROR),
            'd' => $durasi,
        ]);
        $sesiId = (int) $pdo->lastInsertId();
    }

    return ['ok' => true, 'message' => 'Tugas dimulai.', 'sesi_id' => $sesiId, 'durasi_menit' => $durasi];
}

/**
 * @return list<array<string,mixed>>
 */
function ikhtibar_soal_urut_sesi(PDO $pdo, array $sesi): array
{
    $urutan = json_decode((string) ($sesi['urutan_soal_json'] ?? '[]'), true);
    if (!is_array($urutan) || $urutan === []) {
        return ikhtibar_soal_by_tugas($pdo, (int) ($sesi['tugas_id'] ?? 0));
    }
    $map = [];
    foreach (ikhtibar_soal_by_tugas($pdo, (int) ($sesi['tugas_id'] ?? 0)) as $s) {
        $map[(int) $s['id']] = $s;
    }
    $out = [];
    foreach ($urutan as $sid) {
        $sid = (int) $sid;
        if (isset($map[$sid])) {
            $out[] = $map[$sid];
        }
    }

    return $out;
}

function ikhtibar_simpan_jawaban(PDO $pdo, int $sesiId, int $soalId, string $jawaban): void
{
    $pdo->prepare('
        INSERT INTO ikhtibar_jawaban (sesi_id, soal_id, jawaban_santri)
        VALUES (:sesi, :soal, :jawab)
        ON DUPLICATE KEY UPDATE jawaban_santri = VALUES(jawaban_santri)
    ')->execute(['sesi' => $sesiId, 'soal' => $soalId, 'jawab' => $jawaban]);
}

function ikhtibar_selesai_sesi(PDO $pdo, int $sesiId): array
{
    $stmt = $pdo->prepare('SELECT s.*, t.jumlah_pg FROM ikhtibar_sesi s INNER JOIN ikhtibar_tugas t ON t.id = s.tugas_id WHERE s.id = :id LIMIT 1');
    $stmt->execute(['id' => $sesiId]);
    $sesi = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$sesi) {
        return ['ok' => false, 'message' => 'Sesi tidak ditemukan.'];
    }
    $soalList = ikhtibar_soal_urut_sesi($pdo, $sesi);
    $benar = 0;
    $totalPg = 0;
    foreach ($soalList as $soal) {
        if ((string) ($soal['jenis'] ?? '') !== 'PG') {
            continue;
        }
        $totalPg++;
        $jStmt = $pdo->prepare('SELECT jawaban_santri FROM ikhtibar_jawaban WHERE sesi_id = :s AND soal_id = :q LIMIT 1');
        $jStmt->execute(['s' => $sesiId, 'q' => (int) $soal['id']]);
        $jawab = strtoupper(trim((string) ($jStmt->fetchColumn() ?: '')));
        $kunci = strtoupper(trim((string) ($soal['kunci_jawaban'] ?? '')));
        $isBenar = $jawab !== '' && $kunci !== '' && $jawab === $kunci;
        if ($isBenar) {
            $benar++;
        }
        $pdo->prepare('
            INSERT INTO ikhtibar_jawaban (sesi_id, soal_id, jawaban_santri, benar)
            VALUES (:sesi, :soal, :j, :b)
            ON DUPLICATE KEY UPDATE jawaban_santri = VALUES(jawaban_santri), benar = VALUES(benar)
        ')->execute([
            'sesi' => $sesiId,
            'soal' => (int) $soal['id'],
            'j' => $jawab,
            'b' => $isBenar ? 1 : 0,
        ]);
    }
    $skorPg = $totalPg > 0 ? round(100 * $benar / $totalPg, 2) : null;

    require_once __DIR__ . '/ikhtibar_kriteria.php';
    foreach ($soalList as $soal) {
        if ((string) ($soal['jenis'] ?? '') !== 'ESAI') {
            continue;
        }
        $jStmt = $pdo->prepare('SELECT jawaban_santri FROM ikhtibar_jawaban WHERE sesi_id = :s AND soal_id = :q LIMIT 1');
        $jStmt->execute(['s' => $sesiId, 'q' => (int) $soal['id']]);
        $jawabEsai = trim((string) ($jStmt->fetchColumn() ?: ''));
        if ($jawabEsai !== '') {
            ikhtibar_terapkan_nilai_esai_otomatis($pdo, $sesiId, (int) $soal['id'], $jawabEsai);
        }
    }

    $pdo->prepare('
        UPDATE ikhtibar_sesi SET status = "selesai", waktu_selesai = NOW(), skor_pg = :pg WHERE id = :id
    ')->execute(['pg' => $skorPg, 'id' => $sesiId]);
    ikhtibar_sesi_recalculate_totals($pdo, $sesiId);

    $sesiFresh = $pdo->prepare('SELECT skor_pg, skor_esai, nilai_total FROM ikhtibar_sesi WHERE id = :id LIMIT 1');
    $sesiFresh->execute(['id' => $sesiId]);
    $sf = $sesiFresh->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'ok' => true,
        'message' => 'Tugas selesai.',
        'skor_pg' => $skorPg,
        'skor_esai' => $sf['skor_esai'] ?? null,
        'nilai_total' => $sf['nilai_total'] ?? null,
        'sesi_id' => $sesiId,
    ];
}

/**
 * Simpan tugas + soal dari POST form pembimbing.
 *
 * @return array{ok:bool,message:string,id?:int}
 */
function ikhtibar_simpan_tugas_dari_post(PDO $pdo, array $post, array $files, int $userId): array
{
    ensure_akademik_ikhtibar_tables($pdo);

    $id = (int) ($post['id'] ?? 0);
    $judul = trim((string) ($post['judul'] ?? ''));
    $tanggal = trim((string) ($post['tanggal'] ?? ''));
    $tanggalSelesai = trim((string) ($post['tanggal_selesai'] ?? $tanggal));
    $jumlahPg = (int) ($post['jumlah_pg'] ?? 0);
    $jumlahEsai = (int) ($post['jumlah_esai'] ?? 0);
    $tanpaTertulis = $jumlahEsai === 0;
    $durasi = $tanpaTertulis
        ? 0
        : max(5, min(300, (int) ($post['durasi_menit'] ?? 60)));
    $pakaiToken = isset($post['pakai_token']) ? 1 : 0;
    $filterTingkat = trim((string) ($post['filter_tingkatan'] ?? ''));
    $catatan = trim((string) ($post['catatan'] ?? ''));
    $publish = isset($post['publish']);
    $hariKe = (int) ($post['hari_ke'] ?? 0);
    if ($hariKe < 1 || $hariKe > 7) {
        $hariKe = (int) date('N', strtotime($tanggal ?: 'today'));
    }

    if ($judul === '') {
        return ['ok' => false, 'message' => 'Judul tugas wajib diisi.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return ['ok' => false, 'message' => 'Tanggal tidak valid.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalSelesai)) {
        $tanggalSelesai = $tanggal;
    }
    if ($tanggalSelesai < $tanggal) {
        [$tanggal, $tanggalSelesai] = [$tanggalSelesai, $tanggal];
    }
    if ($tanpaTertulis) {
        $tanggalSelesai = $tanggalSelesai !== $tanggal ? $tanggalSelesai : $tanggal;
    }
    if (!in_array($jumlahPg, ikhtibar_kuota_pg(), true)) {
        $jumlahPg = 0;
    }
    if (!in_array($jumlahEsai, ikhtibar_kuota_esai(), true)) {
        $jumlahEsai = 0;
    }
    if ($jumlahPg + $jumlahEsai < 1) {
        return ['ok' => false, 'message' => 'Pilih minimal satu jenis soal (PG atau Esai).'];
    }

    $mapel = ikhtibar_resolve_mapel_dari_post($pdo, $post, $userId);
    $jadwalPost = (int) ($post['jadwal_kegiatan_id'] ?? 0);
    $sumber = strtoupper(trim((string) ($post['sumber'] ?? 'IKHTIBAR')));
    if (!in_array($sumber, ['IKHTIBAR', 'PKPPS'], true)) {
        $sumber = 'IKHTIBAR';
    }
    $pkppsMeta = null;
    if ($sumber === 'PKPPS') {
        $pkppsMeta = ikhtibar_resolve_pkpps_dari_post($pdo, $post, $userId);
        $pkppsPost = (int) ($post['pkpps_jadwal_id'] ?? 0);
        if ($pkppsPost > 0 && $pkppsMeta === null) {
            return ['ok' => false, 'message' => 'Jadwal PKPPS tidak valid atau bukan jadwal Anda.'];
        }
        $mapel = $pkppsMeta;
        $jadwalPost = 0;
    } else {
        if ($jadwalPost > 0 && $mapel === null) {
            return ['ok' => false, 'message' => 'Kelas/mapel tidak valid atau bukan jadwal Anda.'];
        }
    }
    $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
    $wajibMapel = !is_super_admin() && !in_array($role, ['admin', 'pengurus'], true);
    if ($wajibMapel) {
        if ($sumber === 'PKPPS' && (int) ($post['pkpps_jadwal_id'] ?? 0) <= 0) {
            return ['ok' => false, 'message' => 'Pilih jadwal PKPPS yang Anda ampu.'];
        }
        if ($sumber === 'IKHTIBAR' && $jadwalPost <= 0) {
            return ['ok' => false, 'message' => 'Pilih kelas/mapel dari jadwal yang Anda ampu.'];
        }
    }

    $status = $publish ? 'published' : 'draft';
    if ($id > 0) {
        $pdo->prepare('
            UPDATE ikhtibar_tugas SET judul=:j, tanggal=:t, tanggal_selesai=:ts, hari_ke=:h, durasi_menit=:d, pakai_token=:pt,
                jumlah_pg=:jpg, jumlah_esai=:je, filter_tingkatan=:ft, catatan=:c, status=:st, sumber=:sum,
                kegiatan_id=:kid, jadwal_kegiatan_id=:jid, pkpps_jadwal_id=:pjid, pkpps_tingkatan_id=:ptid, mapel_label=:ml, updated_at=NOW()
            WHERE id=:id
        ')->execute([
            'j' => $judul, 't' => $tanggal, 'ts' => $tanggalSelesai, 'h' => $hariKe, 'd' => $durasi, 'pt' => $pakaiToken,
            'jpg' => $jumlahPg, 'je' => $jumlahEsai, 'ft' => $filterTingkat !== '' ? $filterTingkat : null,
            'c' => $catatan !== '' ? $catatan : null, 'st' => $status, 'sum' => $sumber, 'id' => $id,
            'kid' => $mapel['kegiatan_id'] ?? null,
            'jid' => $sumber === 'IKHTIBAR' ? ($mapel['jadwal_kegiatan_id'] ?? null) : null,
            'pjid' => $sumber === 'PKPPS' ? ($mapel['pkpps_jadwal_id'] ?? null) : null,
            'ptid' => $sumber === 'PKPPS' ? ($mapel['pkpps_tingkatan_id'] ?? null) : null,
            'ml' => $mapel['mapel_label'] ?? null,
        ]);
        $pdo->prepare('DELETE FROM ikhtibar_soal WHERE tugas_id = :id')->execute(['id' => $id]);
        $tugasId = $id;
    } else {
        $pdo->prepare('
            INSERT INTO ikhtibar_tugas (judul, tanggal, tanggal_selesai, hari_ke, durasi_menit, pakai_token, jumlah_pg, jumlah_esai,
                filter_tingkatan, catatan, status, created_by, sumber, kegiatan_id, jadwal_kegiatan_id, pkpps_jadwal_id, pkpps_tingkatan_id, mapel_label)
            VALUES (:j,:t,:ts,:h,:d,:pt,:jpg,:je,:ft,:c,:st,:uid,:sum,:kid,:jid,:pjid,:ptid,:ml)
        ')->execute([
            'j' => $judul, 't' => $tanggal, 'ts' => $tanggalSelesai, 'h' => $hariKe, 'd' => $durasi, 'pt' => $pakaiToken,
            'jpg' => $jumlahPg, 'je' => $jumlahEsai, 'ft' => $filterTingkat !== '' ? $filterTingkat : null,
            'c' => $catatan !== '' ? $catatan : null, 'st' => $status, 'uid' => $userId > 0 ? $userId : null, 'sum' => $sumber,
            'kid' => $mapel['kegiatan_id'] ?? null,
            'jid' => $sumber === 'IKHTIBAR' ? ($mapel['jadwal_kegiatan_id'] ?? null) : null,
            'pjid' => $sumber === 'PKPPS' ? ($mapel['pkpps_jadwal_id'] ?? null) : null,
            'ptid' => $sumber === 'PKPPS' ? ($mapel['pkpps_tingkatan_id'] ?? null) : null,
            'ml' => $mapel['mapel_label'] ?? null,
        ]);
        $tugasId = (int) $pdo->lastInsertId();
    }

    if ($pakaiToken === 1) {
        ikhtibar_set_token($pdo, $tugasId);
    }

    $ins = $pdo->prepare('
        INSERT INTO ikhtibar_soal (tugas_id, jenis, nomor, teks_soal, opsi_a, opsi_b, opsi_c, opsi_d, opsi_e, kunci_jawaban, bobot_nilai)
        VALUES (:tid,:jenis,:nom,:teks,:a,:b,:c,:d,:e,:kunci,:bobot)
    ');

    for ($i = 1; $i <= $jumlahPg; $i++) {
        $teks = trim((string) ($post['pg_teks'][$i] ?? ''));
        if ($teks === '') {
            continue;
        }
        $ins->execute([
            'tid' => $tugasId, 'jenis' => 'PG', 'nom' => $i, 'teks' => $teks,
            'a' => trim((string) ($post['pg_a'][$i] ?? '')) ?: null,
            'b' => trim((string) ($post['pg_b'][$i] ?? '')) ?: null,
            'c' => trim((string) ($post['pg_c'][$i] ?? '')) ?: null,
            'd' => trim((string) ($post['pg_d'][$i] ?? '')) ?: null,
            'e' => trim((string) ($post['pg_e'][$i] ?? '')) ?: null,
            'kunci' => strtoupper(trim((string) ($post['pg_kunci'][$i] ?? ''))) ?: null,
            'bobot' => 100,
        ]);
    }
    for ($i = 1; $i <= $jumlahEsai; $i++) {
        $teks = trim((string) ($post['esai_teks'][$i] ?? ''));
        if ($teks === '') {
            continue;
        }
        $ins->execute([
            'tid' => $tugasId, 'jenis' => 'ESAI', 'nom' => $i, 'teks' => $teks,
            'a' => null, 'b' => null, 'c' => null, 'd' => null, 'e' => null,
            'kunci' => trim((string) ($post['esai_kunci'][$i] ?? '')) ?: null,
            'bobot' => max(1, min(100, (float) ($post['esai_bobot'][$i] ?? 100))),
        ]);
    }

    require_once __DIR__ . '/ikhtibar_docx.php';
    if (isset($files['import_docx']) && (int) ($files['import_docx']['error'] ?? 1) === UPLOAD_ERR_OK) {
        $tmp = (string) $files['import_docx']['tmp_name'];
        $text = ikhtibar_docx_extract_text($tmp);
        if ($text !== '' && $jumlahPg > 0) {
            $parsed = ikhtibar_parse_teks_soal_pg($text, $jumlahPg);
            foreach ($parsed as $idx => $p) {
                $nom = $idx + 1;
                if ($nom > $jumlahPg) {
                    break;
                }
                $pdo->prepare('UPDATE ikhtibar_soal SET teks_soal=:t, opsi_a=:a, opsi_b=:b, opsi_c=:c, opsi_d=:d, kunci_jawaban=:k WHERE tugas_id=:tid AND jenis="PG" AND nomor=:n')
                    ->execute([
                        't' => $p['teks'], 'a' => $p['opsi']['A'] ?? null, 'b' => $p['opsi']['B'] ?? null,
                        'c' => $p['opsi']['C'] ?? null, 'd' => $p['opsi']['D'] ?? null, 'k' => $p['kunci'] ?: null,
                        'tid' => $tugasId, 'n' => $nom,
                    ]);
            }
        }
    }

    $ocrText = trim((string) ($post['ocr_teks_import'] ?? ''));
    if ($ocrText !== '' && $jumlahPg > 0) {
        $parsed = ikhtibar_parse_teks_soal_pg($ocrText, $jumlahPg);
        foreach ($parsed as $idx => $p) {
            $nom = $idx + 1;
            if ($nom > $jumlahPg) {
                break;
            }
            $pdo->prepare('UPDATE ikhtibar_soal SET teks_soal=:t, kunci_jawaban=:k WHERE tugas_id=:tid AND jenis="PG" AND nomor=:n')
                ->execute(['t' => $p['teks'], 'k' => $p['kunci'] ?: null, 'tid' => $tugasId, 'n' => $nom]);
        }
    }

    $cnt = $pdo->prepare('SELECT COUNT(*) FROM ikhtibar_soal WHERE tugas_id = :id');
    $cnt->execute(['id' => $tugasId]);
    if ((int) $cnt->fetchColumn() < 1) {
        return ['ok' => false, 'message' => 'Minimal satu soal harus diisi (teks soal tidak boleh kosong).', 'id' => $tugasId];
    }

    return ['ok' => true, 'message' => $publish ? 'Tugas dipublikasikan.' : 'Tugas disimpan sebagai draf.', 'id' => $tugasId];
}

/**
 * @return list<array<string,mixed>>
 */
function ikhtibar_laporan_nilai(PDO $pdo, int $tugasId): array
{
    ensure_akademik_ikhtibar_tables($pdo);
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $stmt = $pdo->prepare("
        SELECT s.id AS sesi_id, s.status, s.skor_pg, s.skor_esai, s.nilai_total, s.waktu_mulai, s.waktu_selesai,
               st.nis, st.{$nameCol} AS nama_santri, st.tingkatan
        FROM ikhtibar_sesi s
        INNER JOIN santri st ON st.id = s.santri_id
        WHERE s.tugas_id = :tid
        ORDER BY ' . santri_list_order_sql('st') . '
    ");
    $stmt->execute(['tid' => $tugasId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function ikhtibar_nilai_esai_manual(PDO $pdo, int $sesiId, int $soalId, float $nilai, string $catatan): void
{
    $pdo->prepare('
        INSERT INTO ikhtibar_jawaban (sesi_id, soal_id, nilai_esai, catatan_pembimbing, benar)
        VALUES (:s,:q,:n,:c, NULL)
        ON DUPLICATE KEY UPDATE nilai_esai = VALUES(nilai_esai), catatan_pembimbing = VALUES(catatan_pembimbing)
    ')->execute(['s' => $sesiId, 'q' => $soalId, 'n' => $nilai, 'c' => $catatan !== '' ? $catatan : null]);

    ikhtibar_sesi_recalculate_totals($pdo, $sesiId);
}

/** Bobot PG vs esai menurut jumlah soal di tugas. @return array{pg:float,esai:float} */
function ikhtibar_bobot_komponen(int $jumlahPg, int $jumlahEsai): array
{
    $total = max(1, $jumlahPg + $jumlahEsai);

    return [
        'pg' => $jumlahPg > 0 ? $jumlahPg / $total : 0.0,
        'esai' => $jumlahEsai > 0 ? $jumlahEsai / $total : 0.0,
    ];
}

function ikhtibar_hitung_nilai_total(?float $skorPg, ?float $skorEsai, int $jumlahPg, int $jumlahEsai): ?float
{
    $bobot = ikhtibar_bobot_komponen($jumlahPg, $jumlahEsai);
    $adaPg = $jumlahPg > 0 && $skorPg !== null;
    $adaEsai = $jumlahEsai > 0 && $skorEsai !== null;
    if ($adaPg && $adaEsai) {
        return round($skorPg * $bobot['pg'] + $skorEsai * $bobot['esai'], 2);
    }
    if ($adaPg) {
        return round($skorPg, 2);
    }
    if ($adaEsai) {
        return round($skorEsai, 2);
    }

    return null;
}

/** @return array{label:string,class:string,icon:string} */
function ikhtibar_predikat_nilai(?float $nilai): array
{
    if ($nilai === null) {
        return ['label' => 'Menunggu', 'class' => 'secondary', 'icon' => 'fa-clock'];
    }
    if ($nilai >= 90) {
        return ['label' => 'Sangat Baik', 'class' => 'success', 'icon' => 'fa-star'];
    }
    if ($nilai >= 80) {
        return ['label' => 'Baik', 'class' => 'primary', 'icon' => 'fa-thumbs-up'];
    }
    if ($nilai >= 70) {
        return ['label' => 'Cukup', 'class' => 'info', 'icon' => 'fa-check'];
    }
    if ($nilai >= 60) {
        return ['label' => 'Perlu Perbaikan', 'class' => 'warning', 'icon' => 'fa-arrow-trend-up'];
    }

    return ['label' => 'Kurang', 'class' => 'danger', 'icon' => 'fa-circle-exclamation'];
}

function ikhtibar_count_esai_belum_dinilai(PDO $pdo, int $sesiId): int
{
    $st = $pdo->prepare('
        SELECT COUNT(*) FROM ikhtibar_soal so
        INNER JOIN ikhtibar_jawaban j ON j.soal_id = so.id AND j.sesi_id = :s
        WHERE so.jenis = "ESAI" AND j.nilai_esai IS NULL
    ');
    $st->execute(['s' => $sesiId]);

    return (int) ($st->fetchColumn() ?: 0);
}

function ikhtibar_sesi_recalculate_totals(PDO $pdo, int $sesiId): void
{
    ensure_akademik_ikhtibar_tables($pdo);
    $st = $pdo->prepare('
        SELECT s.skor_pg, s.skor_esai, t.jumlah_pg, t.jumlah_esai
        FROM ikhtibar_sesi s
        INNER JOIN ikhtibar_tugas t ON t.id = s.tugas_id
        WHERE s.id = :id LIMIT 1
    ');
    $st->execute(['id' => $sesiId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return;
    }

    $cnt = $pdo->prepare('
        SELECT AVG(j.nilai_esai) FROM ikhtibar_jawaban j
        INNER JOIN ikhtibar_soal so ON so.id = j.soal_id
        WHERE j.sesi_id = :s AND so.jenis = "ESAI" AND j.nilai_esai IS NOT NULL
    ');
    $cnt->execute(['s' => $sesiId]);
    $avgRaw = $cnt->fetchColumn();
    $jumlahEsai = (int) ($row['jumlah_esai'] ?? 0);
    $belum = ikhtibar_count_esai_belum_dinilai($pdo, $sesiId);
    $avgEsai = null;
    if ($jumlahEsai > 0 && $belum === 0 && $avgRaw !== false) {
        $avgEsai = round((float) $avgRaw, 2);
    } elseif ($jumlahEsai > 0 && (int) $row['skor_esai'] !== null && $belum > 0) {
        $avgEsai = null;
    } elseif ($avgRaw !== false && $avgRaw !== null) {
        $avgEsai = round((float) $avgRaw, 2);
    }

    $skorPg = $row['skor_pg'] !== null ? (float) $row['skor_pg'] : null;
    $nilaiTotal = ikhtibar_hitung_nilai_total(
        $skorPg,
        $avgEsai,
        (int) ($row['jumlah_pg'] ?? 0),
        $jumlahEsai
    );
    if ($jumlahEsai > 0 && $belum > 0) {
        $nilaiTotal = $skorPg !== null && (int) ($row['jumlah_pg'] ?? 0) > 0
            ? ikhtibar_hitung_nilai_total($skorPg, null, (int) $row['jumlah_pg'], 0)
            : null;
    }

    $pdo->prepare('UPDATE ikhtibar_sesi SET skor_esai = :e, nilai_total = :t WHERE id = :id')
        ->execute(['e' => $avgEsai, 't' => $nilaiTotal, 'id' => $sesiId]);
}

/**
 * Riwayat tugas & nilai satu santri (selesai + sedang berjalan).
 *
 * @return list<array<string,mixed>>
 */
function ikhtibar_riwayat_hasil_santri(PDO $pdo, int $santriId, ?string $sumber = null): array
{
    ensure_akademik_ikhtibar_tables($pdo);
    $sql = '
        SELECT s.id AS sesi_id, s.status AS sesi_status, s.skor_pg, s.skor_esai, s.nilai_total,
               s.waktu_mulai, s.waktu_selesai,
               t.id AS tugas_id, t.judul, t.tanggal, t.hari_ke, t.mapel_label, t.jumlah_pg, t.jumlah_esai, t.durasi_menit
        FROM ikhtibar_sesi s
        INNER JOIN ikhtibar_tugas t ON t.id = s.tugas_id
        WHERE s.santri_id = :sid';
    $params = ['sid' => $santriId];
    if ($sumber !== null) {
        $sql .= ' AND COALESCE(t.sumber, "IKHTIBAR") = :sumber';
        $params['sumber'] = strtoupper($sumber);
    }
    $sql .= ' ORDER BY COALESCE(s.waktu_selesai, s.waktu_mulai, t.tanggal) DESC, t.id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) {
        $sesiId = (int) ($r['sesi_id'] ?? 0);
        $r['esai_pending'] = ikhtibar_count_esai_belum_dinilai($pdo, $sesiId);
        $pred = ikhtibar_predikat_nilai($r['nilai_total'] !== null ? (float) $r['nilai_total'] : null);
        $r['predikat'] = $pred['label'];
        $r['predikat_class'] = $pred['class'];
    }
    unset($r);

    return $rows;
}

/**
 * Detail hasil satu sesi (untuk santri / pembimbing).
 *
 * @return array<string,mixed>|null
 */
function ikhtibar_hasil_detail_santri(PDO $pdo, int $sesiId, int $santriId): ?array
{
    ensure_akademik_ikhtibar_tables($pdo);
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $st = $pdo->prepare("
        SELECT s.*, t.judul, t.tanggal, t.hari_ke, t.mapel_label, t.jumlah_pg, t.jumlah_esai, t.durasi_menit,
               st.nis, st.{$nameCol} AS nama_santri
        FROM ikhtibar_sesi s
        INNER JOIN ikhtibar_tugas t ON t.id = s.tugas_id
        INNER JOIN santri st ON st.id = s.santri_id
        WHERE s.id = :sid AND s.santri_id = :uid
        LIMIT 1
    ");
    $st->execute(['sid' => $sesiId, 'uid' => $santriId]);
    $sesi = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sesi) {
        return null;
    }

    ikhtibar_sesi_recalculate_totals($pdo, $sesiId);
    $st->execute(['sid' => $sesiId, 'uid' => $santriId]);
    $sesi = $st->fetch(PDO::FETCH_ASSOC);
    if (!$sesi) {
        return null;
    }

    $jStmt = $pdo->prepare('
        SELECT j.*, so.jenis, so.nomor, so.teks_soal, so.opsi_a, so.opsi_b, so.opsi_c, so.opsi_d, so.opsi_e, so.kunci_jawaban, so.bobot_nilai
        FROM ikhtibar_jawaban j
        INNER JOIN ikhtibar_soal so ON so.id = j.soal_id
        WHERE j.sesi_id = :s
        ORDER BY so.jenis ASC, so.nomor ASC
    ');
    $jStmt->execute(['s' => $sesiId]);
    $jawaban = $jStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $pgBenar = 0;
    $pgTotal = 0;
    foreach ($jawaban as $j) {
        if ((string) ($j['jenis'] ?? '') !== 'PG') {
            continue;
        }
        $pgTotal++;
        if ((int) ($j['benar'] ?? 0) === 1) {
            $pgBenar++;
        }
    }

    $pred = ikhtibar_predikat_nilai($sesi['nilai_total'] !== null ? (float) $sesi['nilai_total'] : null);
    $bobot = ikhtibar_bobot_komponen((int) ($sesi['jumlah_pg'] ?? 0), (int) ($sesi['jumlah_esai'] ?? 0));

    return [
        'sesi' => $sesi,
        'jawaban' => $jawaban,
        'pg_benar' => $pgBenar,
        'pg_total' => $pgTotal,
        'esai_pending' => ikhtibar_count_esai_belum_dinilai($pdo, $sesiId),
        'predikat' => $pred,
        'bobot' => $bobot,
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function ikhtibar_laporan_nilai_enriched(PDO $pdo, int $tugasId): array
{
    $tugas = ikhtibar_tugas_by_id($pdo, $tugasId);
    if (!$tugas) {
        return [];
    }
    $rows = ikhtibar_laporan_nilai($pdo, $tugasId);
    foreach ($rows as &$r) {
        $sesiId = (int) ($r['sesi_id'] ?? 0);
        if ($sesiId > 0) {
            ikhtibar_sesi_recalculate_totals($pdo, $sesiId);
        }
    }
    unset($r);

    $rows = ikhtibar_laporan_nilai($pdo, $tugasId);
    foreach ($rows as &$r) {
        $sesiId = (int) ($r['sesi_id'] ?? 0);
        $r['esai_pending'] = ikhtibar_count_esai_belum_dinilai($pdo, $sesiId);
        $nt = $r['nilai_total'] !== null ? (float) $r['nilai_total'] : null;
        if ($nt === null && $r['skor_pg'] !== null && (int) ($tugas['jumlah_esai'] ?? 0) === 0) {
            $nt = (float) $r['skor_pg'];
        }
        $pred = ikhtibar_predikat_nilai($nt);
        $r['predikat'] = $pred['label'];
        $r['predikat_class'] = $pred['class'];
        $r['predikat_icon'] = $pred['icon'];
    }
    unset($r);

    return $rows;
}

/**
 * Rekap semua tugas untuk pembimbing/admin.
 *
 * @return list<array<string,mixed>>
 */
function ikhtibar_rekap_tugas_pembimbing(PDO $pdo, int $userId, ?string $sumber = null): array
{
    $list = ikhtibar_tugas_list_pembimbing($pdo, $userId, $sumber);
    foreach ($list as &$t) {
        $tid = (int) ($t['id'] ?? 0);
        $st = $pdo->prepare('
            SELECT COUNT(*) AS total_sesi,
                   SUM(CASE WHEN status = "selesai" THEN 1 ELSE 0 END) AS selesai,
                   AVG(nilai_total) AS rata_nilai
            FROM ikhtibar_sesi WHERE tugas_id = :tid
        ');
        $st->execute(['tid' => $tid]);
        $agg = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $t['total_peserta'] = (int) ($agg['total_sesi'] ?? 0);
        $t['jumlah_selesai'] = (int) ($agg['selesai'] ?? 0);
        $t['rata_nilai'] = $agg['rata_nilai'] !== null ? round((float) $agg['rata_nilai'], 1) : null;

        $pendingSt = $pdo->prepare('
            SELECT COUNT(DISTINCT s.id) FROM ikhtibar_sesi s
            INNER JOIN ikhtibar_jawaban j ON j.sesi_id = s.id
            INNER JOIN ikhtibar_soal so ON so.id = j.soal_id
            WHERE s.tugas_id = :tid AND s.status = "selesai" AND so.jenis = "ESAI" AND j.nilai_esai IS NULL
        ');
        $pendingSt->execute(['tid' => $tid]);
        $t['esai_belum_koreksi'] = (int) ($pendingSt->fetchColumn() ?: 0);
    }
    unset($t);

    return $list;
}

/** CSV nilai satu tugas (UTF-8 BOM). */
function ikhtibar_export_nilai_csv(PDO $pdo, int $tugasId): string
{
    $tugas = ikhtibar_tugas_by_id($pdo, $tugasId);
    $rows = ikhtibar_laporan_nilai_enriched($pdo, $tugasId);
    $out = fopen('php://temp', 'r+');
    if ($out === false) {
        return '';
    }
    fputcsv($out, ['Judul', (string) ($tugas['judul'] ?? '')]);
    fputcsv($out, ['Tanggal', (string) ($tugas['tanggal'] ?? '')]);
    fputcsv($out, []);
    fputcsv($out, ['NIS', 'Nama', 'Tingkatan', 'Status', 'Skor PG %', 'Skor Esai', 'Nilai Total', 'Predikat']);
    foreach ($rows as $r) {
        fputcsv($out, [
            (string) ($r['nis'] ?? ''),
            (string) ($r['nama_santri'] ?? ''),
            (string) ($r['tingkatan'] ?? ''),
            (string) ($r['status'] ?? ''),
            $r['skor_pg'] !== null ? (string) $r['skor_pg'] : '',
            $r['skor_esai'] !== null ? (string) $r['skor_esai'] : '',
            $r['nilai_total'] !== null ? (string) $r['nilai_total'] : '',
            (string) ($r['predikat'] ?? ''),
        ]);
    }
    rewind($out);
    $csv = stream_get_contents($out);
    fclose($out);

    return "\xEF\xBB\xBF" . ($csv !== false ? $csv : '');
}

function ikhtibar_hari_label(int $hariKe): string
{
    $map = [1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu'];

    return $map[$hariKe] ?? '-';
}
