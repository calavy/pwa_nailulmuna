<?php

declare(strict_types=1);

/**
 * Izin default akun pembimbing setelah login.
 *
 * Cakupan modul-pembimbing (semua kecuali gaji/payroll):
 *  - Dashboard pembimbing
 *  - Izin pembimbing (ajukan & lihat izin sendiri)
 *  - Tugas Ikhtibar (buat, nilai, rekap)
 *  - Lihat jadwal kegiatan tingkatannya
 *  - Setoran hafalan (kalau pembimbing menerima setoran kitab/hafalan)
 *  - Rekap keaktifan santri tingkatannya
 *
 * Yang dikecualikan secara eksplisit (tidak pernah diberikan otomatis):
 *  - `rekap_pembimbing` (payroll / gaji)
 *  - modul keuangan, pengaturan, yayasan, ACL admin
 */
/**
 * Buat password acak ramah-ketik untuk akun pembimbing.
 *
 * Default 6 karakter, huruf+angka, tanpa karakter ambigu (0/o/O/1/l/I).
 * Dipakai sebagai default ketika admin tidak mengisi password manual —
 * lebih aman daripada memakai NIP (karena NIP sudah jadi USER login).
 */
function login_pembimbing_buat_password_acak(int $panjang = 6): string
{
    $alfabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    $max = strlen($alfabet) - 1;
    $out = '';
    for ($i = 0; $i < max($panjang, 4); $i++) {
        try {
            $idx = random_int(0, $max);
        } catch (Exception $e) {
            $idx = mt_rand(0, $max);
        }
        $out .= $alfabet[$idx];
    }
    return $out;
}

function login_pembimbing_default_acl_keys(): array
{
    return [
        'pembimbing_dashboard',
        'pembimbing_perizinan',
        'pembimbing_jadwal',
        'akademik_ikhtibar',
    ];
}

/**
 * Pastikan kolom users.password_plain ada — dipakai admin untuk melihat
 * password yang ia setel sendiri (mirip pola ikhtibar_tugas.token_plain).
 *
 * Hanya diisi saat admin membuat/mereset password. Dikosongkan otomatis bila
 * user mengubah password sendiri lewat halaman profil.
 */
function login_pembimbing_ensure_password_plain_column(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    if (isset($_SESSION['users_pwd_plain_v1'])) {
        $done = true;
        return;
    }
    try {
        if (function_exists('table_exists') && table_exists($pdo, 'users')) {
            if (function_exists('column_exists')) {
                if (!column_exists($pdo, 'users', 'password_plain')) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN password_plain VARCHAR(255) NULL");
                }
            } else {
                @$pdo->exec("ALTER TABLE users ADD COLUMN password_plain VARCHAR(255) NULL");
            }
            $_SESSION['users_pwd_plain_v1'] = 1;
        }
    } catch (Throwable $e) {
        // abaikan — coba lagi navigasi berikutnya
    }
    $done = true;
}

/**
 * Set password user oleh admin: simpan hash + plain (untuk visibility admin).
 * Aman dipanggil meski kolom plain belum ada — fallback hanya update hash.
 */
function login_pembimbing_set_password_by_admin(PDO $pdo, int $userId, string $plainPassword): void
{
    if ($userId <= 0 || $plainPassword === '') {
        return;
    }
    login_pembimbing_ensure_password_plain_column($pdo);
    $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
    try {
        $pdo->prepare('UPDATE users SET password = :h, password_plain = :p WHERE id = :id')->execute([
            'h' => $hash,
            'p' => $plainPassword,
            'id' => $userId,
        ]);
    } catch (Throwable $e) {
        // Fallback: kolom plain belum ada → simpan hash saja.
        $pdo->prepare('UPDATE users SET password = :h WHERE id = :id')->execute([
            'h' => $hash,
            'id' => $userId,
        ]);
    }
}

/**
 * Hapus password_plain (dipanggil saat user mengubah password sendiri agar
 * password tidak terlihat oleh admin — privasi user).
 */
function login_pembimbing_forget_password_plain(PDO $pdo, int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    try {
        $pdo->prepare('UPDATE users SET password_plain = NULL WHERE id = :id')->execute([
            'id' => $userId,
        ]);
    } catch (Throwable $e) {
        // kolom belum ada → no-op
    }
}

function login_pembimbing_ensure_acl(PDO $pdo, int $userId): void
{
    if ($userId <= 0 || !table_exists($pdo, 'user_access_permissions')) {
        return;
    }
    $keys = login_pembimbing_default_acl_keys();

    // STRICT: hapus seluruh permission yang TIDAK termasuk set default
    // pembimbing. Ini menjamin role pembimbing benar-benar terisolasi —
    // misal akun yang sebelumnya pernah pengurus dan punya keuangan_laporan
    // tidak akan bocor ke menu pembimbing.
    try {
        if ($keys !== []) {
            $placeholders = implode(',', array_fill(0, count($keys), '?'));
            $stmt = $pdo->prepare(
                'DELETE FROM user_access_permissions WHERE user_id = ? AND permission_key NOT IN (' . $placeholders . ')'
            );
            $stmt->execute(array_merge([$userId], $keys));
        }
    } catch (PDOException $e) { /* abaikan */ }

    // Sisipkan key default yang belum ada.
    $st = $pdo->prepare(
        'INSERT IGNORE INTO user_access_permissions (user_id, permission_key) VALUES (:uid, :pk)'
    );
    foreach ($keys as $key) {
        $st->execute(['uid' => $userId, 'pk' => $key]);
    }

    // Refresh cache ACL & menu di session agar perubahan langsung terasa.
    foreach (array_keys($_SESSION) as $sk) {
        if (is_string($sk) && (str_starts_with($sk, 'acl_map_v2_') || str_starts_with($sk, 'menu_items_acl_'))) {
            unset($_SESSION[$sk]);
        }
    }
    if (function_exists('app_menu_pack_invalidate')) {
        app_menu_pack_invalidate();
    }
}
