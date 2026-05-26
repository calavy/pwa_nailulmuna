<?php

/**
 * Sistem TOKEN 1-pemakaian untuk membuka mode edit pembayaran.
 *
 * Konsep:
 *  - Super admin membuat token (random string + label opsional).
 *  - Admin/pengurus yang akan mengoreksi pembayaran "menukarkan" (redeem)
 *    token pada halaman riwayat_edit. Setelah redeem:
 *      • Token berstatus 'dipakai' dan terkunci ke session_id user tsb.
 *      • Session ditandai (`$_SESSION['pembayaran_edit_token']`) → user
 *        bisa mengedit BANYAK pembayaran sepanjang masih login.
 *  - Saat user logout (atau ganti session), token diset 'habis' dan tidak
 *    bisa dipakai ulang. Token adalah benar-benar SEKALI PAKAI.
 *  - Super admin TIDAK perlu token (bypass) — karena dialah yang menerbitkan.
 *
 * Status token:
 *  • aktif  → belum dipakai, masih bisa di-redeem
 *  • dipakai → sedang aktif pada session seseorang
 *  • habis  → sudah dikonsumsi (logout / session berganti / dihabiskan manual)
 *  • batal  → dicabut oleh super admin sebelum dipakai
 */

declare(strict_types=1);

const PEMBAYARAN_EDIT_TOKEN_SESSION_KEY = 'pembayaran_edit_token';

/**
 * Pastikan tabel pembayaran_edit_token sudah ada.
 */
function pembayaran_edit_token_ensure_schema(PDO $pdo): void
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    try {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS pembayaran_edit_token (
                id INT AUTO_INCREMENT PRIMARY KEY,
                token_plain VARCHAR(40) NOT NULL UNIQUE,
                label VARCHAR(160) NULL,
                created_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                expires_at DATETIME NULL,
                redeemed_by INT NULL,
                redeemed_at DATETIME NULL,
                session_id VARCHAR(128) NULL,
                consumed_at DATETIME NULL,
                status ENUM(\'aktif\',\'dipakai\',\'habis\',\'batal\') NOT NULL DEFAULT \'aktif\',
                catatan VARCHAR(255) NULL,
                KEY idx_pet_status (status),
                KEY idx_pet_session (session_id),
                KEY idx_pet_redeemed_by (redeemed_by)
            )
        ');
    } catch (PDOException $e) {
        // Diam — biarkan caller yang menampilkan error bila perlu.
    }
}

/**
 * Hasilkan string token acak ramah-ketik (16 karakter, huruf+angka,
 * tanpa karakter ambigu).
 */
function pembayaran_edit_token_acak_string(int $panjang = 16): string
{
    $alfabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alfabet) - 1;
    $out = '';
    for ($i = 0; $i < max($panjang, 8); $i++) {
        try {
            $idx = random_int(0, $max);
        } catch (Exception $e) {
            $idx = mt_rand(0, $max);
        }
        $out .= $alfabet[$idx];
        // Sisipkan tanda hubung tiap 4 char agar mudah dibaca: XXXX-XXXX-XXXX-XXXX
        if (($i + 1) % 4 === 0 && ($i + 1) < $panjang) {
            $out .= '-';
        }
    }
    return $out;
}

/**
 * Buat token baru. Hanya super admin yang boleh memanggil ini (caller
 * yang mengecek). Mengembalikan array ['id' => int, 'token' => string]
 * atau melempar exception saat gagal.
 *
 * @return array{id:int, token:string}
 */
function pembayaran_edit_token_buat(PDO $pdo, int $creatorId, ?string $label = null, ?string $expiresAt = null): array
{
    pembayaran_edit_token_ensure_schema($pdo);
    if ($creatorId <= 0) {
        throw new RuntimeException('Pembuat token tidak valid.');
    }

    // Coba sampai 5 kali kalau bentrok (sangat tidak mungkin).
    $attempt = 0;
    while ($attempt < 5) {
        $tokenPlain = pembayaran_edit_token_acak_string(16);
        try {
            $st = $pdo->prepare('INSERT INTO pembayaran_edit_token (token_plain, label, created_by, expires_at) VALUES (:t, :l, :cb, :ex)');
            $st->execute([
                't' => $tokenPlain,
                'l' => $label !== null && $label !== '' ? mb_substr($label, 0, 160) : null,
                'cb' => $creatorId,
                'ex' => $expiresAt !== null && $expiresAt !== '' ? $expiresAt : null,
            ]);
            return [
                'id' => (int) $pdo->lastInsertId(),
                'token' => $tokenPlain,
            ];
        } catch (PDOException $e) {
            $attempt++;
            // Bentrok unique → coba lagi.
            if (stripos($e->getMessage(), 'Duplicate') !== false) {
                continue;
            }
            throw $e;
        }
    }
    throw new RuntimeException('Gagal membuat token (bentrok terus-menerus).');
}

/**
 * Tukar token (redeem). Mengembalikan status & pesan.
 *
 * @return array{ok:bool, message:string, token?:array<string, mixed>}
 */
function pembayaran_edit_token_redeem(PDO $pdo, int $userId, string $tokenPlain): array
{
    pembayaran_edit_token_ensure_schema($pdo);
    $tokenPlain = strtoupper(trim($tokenPlain));
    if ($userId <= 0) {
        return ['ok' => false, 'message' => 'User tidak valid.'];
    }
    if ($tokenPlain === '') {
        return ['ok' => false, 'message' => 'Token kosong.'];
    }

    $st = $pdo->prepare('SELECT * FROM pembayaran_edit_token WHERE token_plain = :t LIMIT 1');
    $st->execute(['t' => $tokenPlain]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['ok' => false, 'message' => 'Token tidak ditemukan.'];
    }

    $status = (string) ($row['status'] ?? '');
    if ($status === 'habis') {
        return ['ok' => false, 'message' => 'Token sudah pernah dipakai dan tidak berlaku lagi.'];
    }
    if ($status === 'batal') {
        return ['ok' => false, 'message' => 'Token telah dibatalkan oleh super admin.'];
    }
    if ($status === 'dipakai') {
        // Sudah dipakai oleh seseorang. Tolak — token 1× pakai.
        return ['ok' => false, 'message' => 'Token sedang dipakai pada session lain. Minta token baru ke super admin.'];
    }

    // Cek expired.
    $expires = (string) ($row['expires_at'] ?? '');
    if ($expires !== '' && $expires !== '0000-00-00 00:00:00') {
        try {
            $expTs = new DateTimeImmutable($expires);
            if ($expTs < new DateTimeImmutable('now')) {
                // Tandai habis sekalian.
                $pdo->prepare("UPDATE pembayaran_edit_token SET status = 'habis', consumed_at = NOW(), catatan = CONCAT(COALESCE(catatan, ''), ' [auto: kedaluwarsa]') WHERE id = :id")
                    ->execute(['id' => (int) $row['id']]);
                return ['ok' => false, 'message' => 'Token sudah kedaluwarsa.'];
            }
        } catch (Exception $e) {
            // Abaikan parsing error.
        }
    }

    // Redeem!
    $sessionId = session_id() ?: '';
    $update = $pdo->prepare("UPDATE pembayaran_edit_token SET status = 'dipakai', redeemed_by = :u, redeemed_at = NOW(), session_id = :s WHERE id = :id AND status = 'aktif'");
    $update->execute(['u' => $userId, 's' => $sessionId, 'id' => (int) $row['id']]);
    if ($update->rowCount() <= 0) {
        return ['ok' => false, 'message' => 'Token gagal di-redeem (sudah dipakai paralel?).'];
    }

    // Set flag session.
    $_SESSION[PEMBAYARAN_EDIT_TOKEN_SESSION_KEY] = [
        'token_id' => (int) $row['id'],
        'token_plain' => $tokenPlain,
        'session_id' => $sessionId,
        'redeemed_at' => date('Y-m-d H:i:s'),
    ];

    return [
        'ok' => true,
        'message' => 'Mode edit terbuka. Token aktif sampai Anda logout.',
        'token' => [
            'id' => (int) $row['id'],
            'label' => (string) ($row['label'] ?? ''),
        ],
    ];
}

/**
 * Apakah session saat ini memegang token yang valid (untuk edit) ?
 */
function pembayaran_edit_token_session_aktif(PDO $pdo): bool
{
    if (!isset($_SESSION[PEMBAYARAN_EDIT_TOKEN_SESSION_KEY]) || !is_array($_SESSION[PEMBAYARAN_EDIT_TOKEN_SESSION_KEY])) {
        return false;
    }
    pembayaran_edit_token_ensure_schema($pdo);
    $tokId = (int) ($_SESSION[PEMBAYARAN_EDIT_TOKEN_SESSION_KEY]['token_id'] ?? 0);
    $sess = (string) ($_SESSION[PEMBAYARAN_EDIT_TOKEN_SESSION_KEY]['session_id'] ?? '');
    if ($tokId <= 0) {
        return false;
    }
    if ($sess !== '' && $sess !== (string) session_id()) {
        // Session berganti → invalid.
        unset($_SESSION[PEMBAYARAN_EDIT_TOKEN_SESSION_KEY]);
        return false;
    }

    try {
        $st = $pdo->prepare("SELECT status, session_id FROM pembayaran_edit_token WHERE id = :id LIMIT 1");
        $st->execute(['id' => $tokId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return false;
    }
    if (!$row) {
        unset($_SESSION[PEMBAYARAN_EDIT_TOKEN_SESSION_KEY]);
        return false;
    }
    if ((string) $row['status'] !== 'dipakai') {
        unset($_SESSION[PEMBAYARAN_EDIT_TOKEN_SESSION_KEY]);
        return false;
    }
    if ((string) $row['session_id'] !== (string) session_id()) {
        unset($_SESSION[PEMBAYARAN_EDIT_TOKEN_SESSION_KEY]);
        return false;
    }
    return true;
}

/**
 * Apakah user saat ini PERLU token untuk mengedit pembayaran?
 * Super admin bypass; pengguna lain perlu token.
 */
function pembayaran_edit_token_required_for_current_user(): bool
{
    if (function_exists('is_super_admin') && is_super_admin()) {
        return false;
    }
    return true;
}

/**
 * Apakah user saat ini boleh mengedit pembayaran sekarang?
 *  - Super admin: selalu boleh
 *  - Lainnya: hanya jika token session aktif
 */
function pembayaran_edit_token_user_boleh_edit(PDO $pdo): bool
{
    if (!pembayaran_edit_token_required_for_current_user()) {
        return true;
    }
    return pembayaran_edit_token_session_aktif($pdo);
}

/**
 * Konsumsi token aktif untuk session saat ini. Dipanggil di logout.php
 * sebelum session_destroy(). Setelah dipanggil, token jadi 'habis'.
 */
function pembayaran_edit_token_consume_session(PDO $pdo): int
{
    pembayaran_edit_token_ensure_schema($pdo);
    $sess = (string) session_id();
    if ($sess === '') {
        return 0;
    }
    try {
        $st = $pdo->prepare("UPDATE pembayaran_edit_token SET status = 'habis', consumed_at = NOW(), catatan = CONCAT(COALESCE(catatan, ''), ' [auto: logout]') WHERE session_id = :s AND status = 'dipakai'");
        $st->execute(['s' => $sess]);
        $n = $st->rowCount();
    } catch (PDOException $e) {
        $n = 0;
    }
    unset($_SESSION[PEMBAYARAN_EDIT_TOKEN_SESSION_KEY]);
    return (int) $n;
}

/**
 * Cabut/batalkan token (super admin).
 */
function pembayaran_edit_token_revoke(PDO $pdo, int $tokenId, int $byUserId): bool
{
    pembayaran_edit_token_ensure_schema($pdo);
    if ($tokenId <= 0) {
        return false;
    }
    try {
        $st = $pdo->prepare("UPDATE pembayaran_edit_token SET status = 'batal', consumed_at = NOW(), catatan = CONCAT(COALESCE(catatan, ''), ' [dibatalkan oleh user #', :u, ']') WHERE id = :id AND status IN ('aktif','dipakai')");
        $st->execute(['id' => $tokenId, 'u' => $byUserId]);
        return $st->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

/**
 * Daftar token untuk halaman super admin. Termasuk join ke users.
 *
 * @return list<array<string, mixed>>
 */
function pembayaran_edit_token_list(PDO $pdo, ?string $statusFilter = null, int $limit = 200): array
{
    pembayaran_edit_token_ensure_schema($pdo);
    $hasUsers = function_exists('table_exists') && table_exists($pdo, 'users');
    $joinU = $hasUsers ? 'LEFT JOIN users uc ON uc.id = t.created_by LEFT JOIN users ur ON ur.id = t.redeemed_by' : '';
    $namaCreator = $hasUsers ? 'uc.nama AS creator_nama, uc.username AS creator_username' : "'' AS creator_nama, '' AS creator_username";
    $namaRedeemer = $hasUsers ? 'ur.nama AS redeemer_nama, ur.username AS redeemer_username' : "'' AS redeemer_nama, '' AS redeemer_username";

    $where = '';
    $params = [];
    if ($statusFilter !== null && $statusFilter !== '' && in_array($statusFilter, ['aktif', 'dipakai', 'habis', 'batal'], true)) {
        $where = 'WHERE t.status = :s';
        $params['s'] = $statusFilter;
    }

    $sql = "SELECT t.*, {$namaCreator}, {$namaRedeemer}
            FROM pembayaran_edit_token t
            {$joinU}
            {$where}
            ORDER BY t.id DESC
            LIMIT " . (int) max(10, min(1000, $limit));
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Hitung ringkasan jumlah token per status — untuk badge/kartu mini.
 *
 * @return array<string, int>
 */
function pembayaran_edit_token_summary(PDO $pdo): array
{
    pembayaran_edit_token_ensure_schema($pdo);
    $out = ['aktif' => 0, 'dipakai' => 0, 'habis' => 0, 'batal' => 0];
    try {
        $rows = $pdo->query('SELECT status, COUNT(*) AS jml FROM pembayaran_edit_token GROUP BY status')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        return $out;
    }
    foreach ($rows as $r) {
        $s = (string) ($r['status'] ?? '');
        if (isset($out[$s])) {
            $out[$s] = (int) ($r['jml'] ?? 0);
        }
    }
    return $out;
}
