<?php

declare(strict_types=1);

require_once __DIR__ . '/akademik.php';

function ensure_mukimin_portal_columns(PDO $pdo): void
{
    ensure_akademik_alumni_table($pdo);
    akademik_add_column($pdo, 'akademik_alumni', 'sektor', 'VARCHAR(120) NULL');
    akademik_add_column($pdo, 'akademik_alumni', 'portal_username', 'VARCHAR(60) NULL');
    akademik_add_column($pdo, 'akademik_alumni', 'portal_password_hash', 'VARCHAR(255) NULL');
    akademik_add_column($pdo, 'akademik_alumni', 'portal_aktif', 'TINYINT(1) NOT NULL DEFAULT 0');
    if (column_exists($pdo, 'akademik_alumni', 'portal_pin_hash') && column_exists($pdo, 'akademik_alumni', 'portal_password_hash')) {
        try {
            $pdo->exec('
                UPDATE akademik_alumni
                SET portal_password_hash = portal_pin_hash
                WHERE (portal_password_hash IS NULL OR portal_password_hash = "")
                  AND portal_pin_hash IS NOT NULL AND portal_pin_hash <> ""
            ');
        } catch (PDOException $e) {
            // abaikan
        }
    }
    try {
        $pdo->exec('CREATE UNIQUE INDEX uk_alumni_portal_username ON akademik_alumni (portal_username)');
    } catch (PDOException $e) {
        // sudah ada
    }
}

/** @return list<string> */
function mukimin_portal_sektor_suggest(): array
{
    return [
        'Dalam pesantren',
        'Luar negeri',
        'Dalam negeri (luar pesantren)',
        'Wirausaha / usaha',
        'PNS / ASN',
        'TNI / Polri',
        'Guru / tenaga pendidik',
        'Mahasiswa / kuliah',
        'Belum bekerja',
        'Lainnya',
    ];
}

function mukimin_portal_normalize_username(string $username): string
{
    return strtolower(trim($username));
}

/** @return array{ok:bool,message:string} */
function mukimin_portal_validate_username(string $username): array
{
    $u = mukimin_portal_normalize_username($username);
    if ($u === '') {
        return ['ok' => false, 'message' => 'Username wajib diisi.'];
    }
    if (strlen($u) < 3 || strlen($u) > 60) {
        return ['ok' => false, 'message' => 'Username harus 3–60 karakter.'];
    }
    if (!preg_match('/^[a-z0-9._-]+$/', $u)) {
        return ['ok' => false, 'message' => 'Username hanya huruf, angka, titik, strip, dan underscore.'];
    }

    return ['ok' => true, 'message' => ''];
}

/**
 * @return list<array<string, mixed>>
 */
function mukimin_portal_list_registered(PDO $pdo, string $q = '', int $limit = 500): array
{
    ensure_mukimin_portal_columns($pdo);

    $sql = '
        SELECT id, nis, nama, sektor, portal_username, portal_aktif,
               (portal_password_hash IS NOT NULL AND portal_password_hash <> "") AS punya_password,
               updated_at
        FROM akademik_alumni
        WHERE portal_aktif = 1
           OR (portal_username IS NOT NULL AND TRIM(portal_username) <> "")
    ';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (LOWER(nama) LIKE :q OR LOWER(nis) LIKE :q2 OR LOWER(COALESCE(portal_username, "")) LIKE :q3 OR LOWER(COALESCE(sektor, "")) LIKE :q4) ';
        $needle = '%' . strtolower($q) . '%';
        $params = ['q' => $needle, 'q2' => $needle, 'q3' => $needle, 'q4' => $needle];
    }
    $sql .= ' ORDER BY nama ASC LIMIT ' . (int) max(1, min(1000, $limit));

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Alumni belum punya akses portal (untuk pendaftaran).
 *
 * @return list<array<string, mixed>>
 */
function mukimin_portal_list_belum_terdaftar(PDO $pdo, string $q = '', int $limit = 80): array
{
    ensure_mukimin_portal_columns($pdo);

    $sql = '
        SELECT id, nis, nama, sektor
        FROM akademik_alumni
        WHERE COALESCE(portal_aktif, 0) = 0
          AND (portal_username IS NULL OR TRIM(portal_username) = "")
    ';
    $params = [];
    if ($q !== '') {
        $sql .= ' AND (LOWER(nama) LIKE :q OR LOWER(nis) LIKE :q2) ';
        $needle = '%' . strtolower($q) . '%';
        $params = ['q' => $needle, 'q2' => $needle];
    }
    $sql .= ' ORDER BY nama ASC LIMIT ' . (int) max(1, min(200, $limit));

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array{ok:bool,message:string}
 */
function mukimin_portal_simpan_akses(
    PDO $pdo,
    int $alumniId,
    string $username,
    string $password,
    string $sektor,
    bool $aktif = true,
    bool $passwordWajib = true
): array {
    ensure_mukimin_portal_columns($pdo);
    if ($alumniId <= 0) {
        return ['ok' => false, 'message' => 'Pilih alumni/mukimin terlebih dahulu.'];
    }

    $chk = $pdo->prepare('SELECT id, portal_username, portal_password_hash FROM akademik_alumni WHERE id = :id LIMIT 1');
    $chk->execute(['id' => $alumniId]);
    $row = $chk->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'message' => 'Data mukimin tidak ditemukan.'];
    }

    $valUser = mukimin_portal_validate_username($username);
    if (!$valUser['ok']) {
        return $valUser;
    }
    $userNorm = mukimin_portal_normalize_username($username);

    $dup = $pdo->prepare('
        SELECT id FROM akademik_alumni
        WHERE portal_username = :u AND id <> :id LIMIT 1
    ');
    $dup->execute(['u' => $userNorm, 'id' => $alumniId]);
    if ($dup->fetch()) {
        return ['ok' => false, 'message' => 'Username sudah dipakai alumni lain.'];
    }

    $hash = (string) ($row['portal_password_hash'] ?? '');
    if ($password !== '') {
        if (strlen($password) < 6) {
            return ['ok' => false, 'message' => 'Password minimal 6 karakter.'];
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
    } elseif ($passwordWajib && $hash === '') {
        return ['ok' => false, 'message' => 'Password wajib diisi untuk pendaftaran baru.'];
    }

    $sektorTrim = trim($sektor);
    $aktifInt = $aktif ? 1 : 0;

    $pdo->prepare('
        UPDATE akademik_alumni SET
            portal_username = :user,
            portal_password_hash = :hash,
            portal_aktif = :aktif,
            sektor = :sektor,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ')->execute([
        'user' => $userNorm,
        'hash' => $hash !== '' ? $hash : null,
        'aktif' => $aktifInt,
        'sektor' => $sektorTrim !== '' ? mb_substr($sektorTrim, 0, 120) : null,
        'id' => $alumniId,
    ]);

    return [
        'ok' => true,
        'message' => $aktifInt === 1
            ? 'Akses portal mukimin diaktifkan untuk username «' . $userNorm . '».'
            : 'Data akses disimpan (status nonaktif).',
    ];
}

/**
 * @return array{ok:bool,message:string}
 */
function mukimin_portal_set_aktif(PDO $pdo, int $alumniId, bool $aktif): array
{
    ensure_mukimin_portal_columns($pdo);
    if ($alumniId <= 0) {
        return ['ok' => false, 'message' => 'Data tidak valid.'];
    }
    if ($aktif) {
        $st = $pdo->prepare('
            SELECT portal_username, portal_password_hash FROM akademik_alumni WHERE id = :id LIMIT 1
        ');
        $st->execute(['id' => $alumniId]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r || trim((string) ($r['portal_username'] ?? '')) === '' || trim((string) ($r['portal_password_hash'] ?? '')) === '') {
            return ['ok' => false, 'message' => 'Lengkapi username dan password sebelum mengaktifkan.'];
        }
    }
    $pdo->prepare('UPDATE akademik_alumni SET portal_aktif = :a, updated_at = CURRENT_TIMESTAMP WHERE id = :id')
        ->execute(['a' => $aktif ? 1 : 0, 'id' => $alumniId]);

    return [
        'ok' => true,
        'message' => $aktif ? 'Akses portal diaktifkan.' : 'Akses portal dihentikan.',
    ];
}

/**
 * @return array{ok:bool,message:string}
 */
function mukimin_portal_cabut_akses(PDO $pdo, int $alumniId): array
{
    ensure_mukimin_portal_columns($pdo);
    if ($alumniId <= 0) {
        return ['ok' => false, 'message' => 'Data tidak valid.'];
    }
    $pdo->prepare('
        UPDATE akademik_alumni SET
            portal_username = NULL,
            portal_password_hash = NULL,
            portal_aktif = 0,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ')->execute(['id' => $alumniId]);

    return ['ok' => true, 'message' => 'Akses portal dicabut; alumni tidak bisa login lagi.'];
}

/** @return array<string, mixed>|null */
function mukimin_portal_authenticate(PDO $pdo, string $username, string $password): ?array
{
    ensure_mukimin_portal_columns($pdo);
    $userNorm = mukimin_portal_normalize_username($username);
    if ($userNorm === '' || $password === '') {
        return null;
    }

    $st = $pdo->prepare('
        SELECT id, nis, nama, sektor, portal_password_hash, portal_aktif
        FROM akademik_alumni
        WHERE portal_username = :u
        LIMIT 1
    ');
    $st->execute(['u' => $userNorm]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int) ($row['portal_aktif'] ?? 0) !== 1) {
        return null;
    }
    $hash = (string) ($row['portal_password_hash'] ?? '');
    if ($hash === '' || !password_verify($password, $hash)) {
        return null;
    }

    return $row;
}

/** @deprecated gunakan mukimin_portal_simpan_akses */
function mukimin_portal_set_pin(PDO $pdo, int $alumniId, string $pin): bool
{
    $r = mukimin_portal_simpan_akses($pdo, $alumniId, 'user' . $alumniId, $pin, '', true, true);

    return $r['ok'];
}

/** @deprecated */
function mukimin_portal_list_for_settings(PDO $pdo, int $limit = 500): array
{
    return mukimin_portal_list_registered($pdo, '', $limit);
}
