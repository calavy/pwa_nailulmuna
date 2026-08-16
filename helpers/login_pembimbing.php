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
 *  - Scan presensi santri & pembimbing
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
/** Pastikan ENUM role users memuat pembimbing (supaya akun login bisa dibuat). */
function pembimbing_users_role_enum_ready(PDO $pdo): bool
{
    if (!function_exists('table_exists') || !table_exists($pdo, 'users')) {
        return false;
    }
    try {
        $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','pengurus','petugas_absensi','pembimbing','kiai','petugas_koperasi') NOT NULL DEFAULT 'pengurus'");

        return true;
    } catch (PDOException $e) {
        try {
            $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
            $type = strtolower((string) ($col['Type'] ?? ''));

            return str_contains($type, 'pembimbing');
        } catch (PDOException $e2) {
            return false;
        }
    }
}

/**
 * Tambah pembimbing baru + opsional akun login (transaksi atomik).
 *
 * @param array{qr?:string,nip?:string,nama?:string,wa?:string} $data
 * @return array{ok:bool,message:string}
 */
function pembimbing_create_with_account(
    PDO $pdo,
    array $data,
    string $passwordRaw = '',
    bool $createAccount = true
): array {
    $qr = trim((string) ($data['qr'] ?? ''));
    $nip = trim((string) ($data['nip'] ?? ''));
    $nama = trim((string) ($data['nama'] ?? ''));
    $wa = trim((string) ($data['wa'] ?? ''));
    if ($nip === '' || $nama === '') {
        return ['ok' => false, 'message' => 'NIP dan nama pengurus wajib diisi.'];
    }
    if ($qr === '') {
        $qr = $nip;
    }
    $waDb = $wa !== '' ? $wa : null;
    $userNama = function_exists('mb_substr') ? mb_substr($nama, 0, 100) : substr($nama, 0, 100);

    if ($createAccount && table_exists($pdo, 'users')) {
        if (!pembimbing_users_role_enum_ready($pdo)) {
            return [
                'ok' => false,
                'message' => 'Role login "pembimbing" belum tersedia di tabel users. Hubungi admin untuk memperbarui skema database.',
            ];
        }
        login_pembimbing_ensure_password_plain_column($pdo);
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('INSERT INTO pembimbing (qr, nip, nama_pembimbing, no_wa) VALUES (:qr, :nip, :nama, :wa)');
        $stmt->execute(['qr' => $qr, 'nip' => $nip, 'nama' => $nama, 'wa' => $waDb]);

        $flashMsg = 'Data pengurus ditambahkan. Kelas yang dikaji akan otomatis terisi setelah pembimbing dimasukkan ke jadwal.';

        if ($createAccount && table_exists($pdo, 'users')) {
            $checkUser = $pdo->prepare('SELECT id FROM users WHERE TRIM(username) = :u LIMIT 1');
            $checkUser->execute(['u' => $nip]);
            if (!$checkUser->fetch()) {
                $pwd = $passwordRaw !== '' ? $passwordRaw : login_pembimbing_buat_password_acak();
                $insertU = $pdo->prepare(
                    "INSERT INTO users (nama, username, password, role) VALUES (:nama, :username, :pwd, 'pembimbing')"
                );
                $insertU->execute([
                    'nama' => $userNama,
                    'username' => $nip,
                    'pwd' => password_hash($pwd, PASSWORD_DEFAULT),
                ]);
                $newUid = (int) $pdo->lastInsertId();
                if ($newUid > 0) {
                    login_pembimbing_set_password_by_admin($pdo, $newUid, $pwd);
                    login_pembimbing_ensure_acl($pdo, $newUid);
                }
                $flashMsg .= ' Akun login dibuat — USER: ' . $nip . ' · PASS: ' . $pwd;
            } else {
                $flashMsg .= ' (Akun login dengan username "' . $nip . '" sudah ada — tidak ditimpa.)';
            }
        }

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        return ['ok' => true, 'message' => $flashMsg];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $msg = $e->getMessage();
        if (stripos($msg, 'Duplicate') !== false) {
            return ['ok' => false, 'message' => 'NIP "' . $nip . '" sudah terdaftar.'];
        }

        return ['ok' => false, 'message' => 'Gagal menyimpan: ' . $msg];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal menyimpan: ' . $e->getMessage()];
    }
}

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
        'pembimbing_pkpps',
        'akademik_ikhtibar',
        'akademik_setoran',
        'presensi_scan',
    ];
}

/** @return ''|'setoran' */
function login_pembimbing_sanitize_dest(?string $dest): string
{
    $d = strtolower(trim((string) $dest));
    return $d === 'setoran' ? 'setoran' : '';
}

function login_pembimbing_post_login_path(string $dest = ''): string
{
    if (login_pembimbing_sanitize_dest($dest) === 'setoran') {
        return 'pembimbing/setoran_dashboard.php';
    }
    return 'pembimbing/dashboard.php';
}

/**
 * Guard halaman self-service portal pembimbing (sama pola dengan dashboard).
 * Role pembimbing/admin/pengurus (+ role tambahan opsional) tidak perlu ACL granular.
 */
function pembimbing_portal_require_access(array $extraRoles = []): void
{
    require_once __DIR__ . '/../includes/auth.php';
    require_login();
    require_once __DIR__ . '/munawib_portal.php';
    munawib_portal_guard_halaman();
    if (is_super_admin()) {
        return;
    }
    $allowed = array_merge(['admin', 'pengurus', 'pembimbing'], $extraRoles);
    $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
    if (in_array($role, $allowed, true)) {
        return;
    }
    global $pdo;
    if ($pdo instanceof PDO) {
        require_once __DIR__ . '/akademik_ikhtibar.php';
        if (ikhtibar_user_matches_pembimbing_nip($pdo)) {
            return;
        }
    }
    if (user_has_current_page_permission()) {
        return;
    }
    set_flash('error', 'Anda tidak memiliki akses ke halaman ini.');
    auth_redirect_access_denied();
}

function login_pembimbing_setoran_entry_url(): string
{
    return login_pembimbing_setoran_entry_meta(null)['href'];
}

/**
 * Tautan & label tombol masuk portal penerima setoran (scan atau langsung jika sudah aktif).
 *
 * @return array{href:string,title:string,desc:string,icon:string}
 */
function login_pembimbing_setoran_entry_meta(?PDO $pdo = null): array
{
    require_once __DIR__ . '/app_path.php';

    $meta = [
        'href' => app_href('/login.php?dest=setoran'),
        'title' => 'Input setoran hafalan',
        'desc' => 'Masuk untuk input setoran',
        'icon' => 'fa-book-quran',
    ];

    if ($pdo instanceof PDO) {
        require_once __DIR__ . '/akademik_setoran.php';
        $portalSt = akademik_setoran_portal_access_status($pdo);
        if (!empty($portalSt['ok'])) {
            $meta['href'] = app_href('/pembimbing/setoran_dashboard.php');
            $meta['title'] = 'Portal setoran hafalan';
            $meta['desc'] = 'Scan santri · perolehan · keaktivan';
            $meta['icon'] = 'fa-book-quran';
        }
    }

    return $meta;
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

    $_SESSION['pembimbing_acl_healed_v5_' . $userId] = 1;
}
