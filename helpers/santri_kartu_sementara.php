<?php

declare(strict_types=1);

require_once __DIR__ . '/santri_operasional.php';

function santri_kartu_sementara_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (!table_exists($pdo, 'santri')) {
        return;
    }
    try {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS santri_kartu_sementara (
                id INT AUTO_INCREMENT PRIMARY KEY,
                santri_id INT NOT NULL,
                kode_qr VARCHAR(64) NOT NULL,
                is_aktif TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                created_by INT NULL,
                revoked_at DATETIME NULL,
                revoked_by INT NULL,
                catatan VARCHAR(255) NULL,
                UNIQUE KEY uk_santri_kartu_tmp_kode (kode_qr),
                INDEX idx_santri_kartu_tmp_santri (santri_id, is_aktif)
            )
        ');
    } catch (PDOException $e) {
        /* abaikan jika sudah ada dengan skema berbeda */
    }
}

/**
 * Resolver terpusat: QR utama, NIS, ST-{id}, atau kartu sementara aktif.
 *
 * @return array<string, mixed>|null baris santri (+ kunci _kartu_sementara jika dari QR temp)
 */
function santri_resolve_by_scan_code(PDO $pdo, string $code): ?array
{
    $code = trim($code);
    if ($code === '') {
        return null;
    }

    require_once __DIR__ . '/santri_kartu.php';
    santri_kartu_sementara_ensure_schema($pdo);

    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $aktifSql = column_exists($pdo, 'santri', 'is_aktif') ? ' AND COALESCE(s.is_aktif, 1) = 1' : '';

    $st = $pdo->prepare('
        SELECT s.*
        FROM santri s
        WHERE (s.qr = :c OR s.nis = :c)' . $aktifSql . '
        LIMIT 1
    ');
    $st->execute(['c' => $code]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        return $row;
    }

    if (preg_match('/^ST-(\d+)$/i', $code, $m)) {
        $stId = $pdo->prepare('SELECT * FROM santri s WHERE s.id = :id' . $aktifSql . ' LIMIT 1');
        $stId->execute(['id' => (int) $m[1]]);
        $row = $stId->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            return $row;
        }
    }

    if (table_exists($pdo, 'santri_kartu_sementara')) {
        $stTmp = $pdo->prepare('
            SELECT s.*, t.id AS _kartu_tmp_id, t.kode_qr AS _kartu_tmp_kode
            FROM santri_kartu_sementara t
            INNER JOIN santri s ON s.id = t.santri_id
            WHERE t.kode_qr = :c AND t.is_aktif = 1' . str_replace('s.', 's.', $aktifSql) . '
            LIMIT 1
        ');
        $stTmp->execute(['c' => $code]);
        $row = $stTmp->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $row['_kartu_sementara'] = true;

            return $row;
        }
    }

    return null;
}

function santri_kartu_sementara_generate_kode(PDO $pdo): string
{
    for ($i = 0; $i < 12; $i++) {
        $kode = 'STT-' . strtoupper(bin2hex(random_bytes(4)));
        $st = $pdo->prepare('SELECT 1 FROM santri_kartu_sementara WHERE kode_qr = :k LIMIT 1');
        $st->execute(['k' => $kode]);
        if (!$st->fetchColumn()) {
            $chk = $pdo->prepare('SELECT 1 FROM santri WHERE qr = :k OR nis = :k LIMIT 1');
            $chk->execute(['k' => $kode]);
            if (!$chk->fetchColumn()) {
                return $kode;
            }
        }
    }

    return 'STT-' . strtoupper(uniqid('', false));
}

/** Nonaktifkan semua kartu sementara aktif santri. */
function santri_kartu_sementara_revoke_all(PDO $pdo, int $santriId, int $userId): int
{
    santri_kartu_sementara_ensure_schema($pdo);
    $st = $pdo->prepare('
        UPDATE santri_kartu_sementara
        SET is_aktif = 0, revoked_at = NOW(), revoked_by = :uid
        WHERE santri_id = :sid AND is_aktif = 1
    ');
    $st->execute(['sid' => $santriId, 'uid' => $userId]);

    return $st->rowCount();
}

/**
 * @return array{ok:bool,message:string,id?:int,kode_qr?:string}
 */
function santri_kartu_sementara_issue(PDO $pdo, int $santriId, int $userId, string $catatan = ''): array
{
    santri_kartu_sementara_ensure_schema($pdo);
    if ($santriId <= 0) {
        return ['ok' => false, 'message' => 'Santri tidak valid.'];
    }
    $chk = $pdo->prepare('SELECT id FROM santri WHERE id = :id LIMIT 1');
    $chk->execute(['id' => $santriId]);
    if (!$chk->fetchColumn()) {
        return ['ok' => false, 'message' => 'Santri tidak ditemukan.'];
    }

    santri_kartu_sementara_revoke_all($pdo, $santriId, $userId);
    $kode = santri_kartu_sementara_generate_kode($pdo);
    $st = $pdo->prepare('
        INSERT INTO santri_kartu_sementara (santri_id, kode_qr, is_aktif, created_by, catatan)
        VALUES (:sid, :kode, 1, :uid, :cat)
    ');
    $st->execute([
        'sid' => $santriId,
        'kode' => $kode,
        'uid' => $userId > 0 ? $userId : null,
        'cat' => $catatan !== '' ? $catatan : null,
    ]);

    return [
        'ok' => true,
        'message' => 'Kartu sementara diterbitkan. QR: ' . $kode,
        'id' => (int) $pdo->lastInsertId(),
        'kode_qr' => $kode,
    ];
}

/**
 * @return array{ok:bool,message:string}
 */
function santri_kartu_sementara_revoke_one(PDO $pdo, int $tmpId, int $userId): array
{
    santri_kartu_sementara_ensure_schema($pdo);
    $st = $pdo->prepare('
        UPDATE santri_kartu_sementara
        SET is_aktif = 0, revoked_at = NOW(), revoked_by = :uid
        WHERE id = :id AND is_aktif = 1
    ');
    $st->execute(['id' => $tmpId, 'uid' => $userId]);

    return $st->rowCount() > 0
        ? ['ok' => true, 'message' => 'Kartu sementara dinonaktifkan.']
        : ['ok' => false, 'message' => 'Kartu sementara tidak ditemukan atau sudah nonaktif.'];
}

/** @return list<array<string, mixed>> */
function santri_kartu_sementara_list(PDO $pdo, int $santriId, int $limit = 10): array
{
    santri_kartu_sementara_ensure_schema($pdo);
    $st = $pdo->prepare('
        SELECT id, kode_qr, is_aktif, created_at, revoked_at, catatan
        FROM santri_kartu_sementara
        WHERE santri_id = :sid
        ORDER BY id DESC
        LIMIT ' . max(1, min(50, $limit))
    );
    $st->execute(['sid' => $santriId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array<string, mixed>|null */
function santri_kartu_sementara_get_active(PDO $pdo, int $santriId): ?array
{
    santri_kartu_sementara_ensure_schema($pdo);
    $st = $pdo->prepare('
        SELECT * FROM santri_kartu_sementara
        WHERE santri_id = :sid AND is_aktif = 1
        ORDER BY id DESC
        LIMIT 1
    ');
    $st->execute(['sid' => $santriId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}
