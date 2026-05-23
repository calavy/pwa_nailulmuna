<?php

declare(strict_types=1);

/** Kolom PIN portal santri (login mandiri, terpisah dari PIN wali). */
function ensure_santri_portal_pin_column(PDO $pdo): void
{
    ensure_santri_identity_columns($pdo);
}

/** Hash PIN cashless santri (jika akun ada). */
function santri_portal_cashless_pin_hash(PDO $pdo, int $santriId): string
{
    if ($santriId <= 0 || !table_exists($pdo, 'cashless_accounts')) {
        return '';
    }
    $st = $pdo->prepare('SELECT pin_hash FROM cashless_accounts WHERE santri_id = :id LIMIT 1');
    $st->execute(['id' => $santriId]);
    $hash = $st->fetchColumn();

    return is_string($hash) ? trim($hash) : '';
}

/**
 * Cek PIN: portal santri dulu, lalu PIN cashless (satu PIN untuk belanja & login).
 */
function santri_portal_verify_pin(PDO $pdo, int $santriId, string $pin, ?string $portalPinHash = null): bool
{
    if ($pin === '') {
        return false;
    }
    $portalHash = $portalPinHash;
    if ($portalHash === null) {
        ensure_santri_portal_pin_column($pdo);
        $st = $pdo->prepare('SELECT santri_portal_pin_hash FROM santri WHERE id = :id LIMIT 1');
        $st->execute(['id' => $santriId]);
        $portalHash = trim((string) ($st->fetchColumn() ?: ''));
    }
    if ($portalHash !== '' && password_verify($pin, $portalHash)) {
        return true;
    }
    $cashlessHash = santri_portal_cashless_pin_hash($pdo, $santriId);

    return $cashlessHash !== '' && password_verify($pin, $cashlessHash);
}

/** Apakah santri punya minimal satu PIN (portal atau cashless)? */
function santri_portal_has_login_pin(PDO $pdo, int $santriId, ?string $portalPinHash = null): bool
{
    $portalHash = $portalPinHash;
    if ($portalHash === null && $santriId > 0) {
        ensure_santri_portal_pin_column($pdo);
        $st = $pdo->prepare('SELECT santri_portal_pin_hash FROM santri WHERE id = :id LIMIT 1');
        $st->execute(['id' => $santriId]);
        $portalHash = trim((string) ($st->fetchColumn() ?: ''));
    }
    if ($portalHash !== '') {
        return true;
    }

    return santri_portal_cashless_pin_hash($pdo, $santriId) !== '';
}

/** Verifikasi PIN portal santri; mengembalikan baris santri aktif atau null. */
function santri_portal_verify_login(PDO $pdo, string $nis, string $pin): ?array
{
    ensure_santri_portal_pin_column($pdo);
    if ($nis === '' || $pin === '') {
        return null;
    }
    require_once __DIR__ . '/santri_operasional.php';
    $aktifSql = santri_sql_aktif_only('s');
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $sql = 'SELECT s.id, s.nis, s.' . $nameCol . ' AS nama_santri, s.santri_portal_pin_hash';
    if (column_exists($pdo, 'santri', 'is_aktif')) {
        $sql .= ', s.is_aktif';
    }
    $sql .= ' FROM santri s WHERE s.nis = :nis AND ' . $aktifSql . ' LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute(['nis' => $nis]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $santriId = (int) ($row['id'] ?? 0);
    $portalHash = trim((string) ($row['santri_portal_pin_hash'] ?? ''));
    if (!santri_portal_has_login_pin($pdo, $santriId, $portalHash)) {
        return null;
    }
    if (!santri_portal_verify_pin($pdo, $santriId, $pin, $portalHash)) {
        return null;
    }

    return $row;
}
