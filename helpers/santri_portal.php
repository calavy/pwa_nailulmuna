<?php

declare(strict_types=1);

/** Kolom PIN portal santri (login mandiri, terpisah dari PIN wali). */
function ensure_santri_portal_pin_column(PDO $pdo): void
{
    ensure_santri_identity_columns($pdo);
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
    $hash = trim((string) ($row['santri_portal_pin_hash'] ?? ''));
    if ($hash === '' || !password_verify($pin, $hash)) {
        return null;
    }

    return $row;
}
