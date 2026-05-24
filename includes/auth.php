<?php

require_once __DIR__ . '/../config/session.php';

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
}

/** Label tampilan role pengguna (nilai di database tetap, mis. `kiai`). */
function user_role_label(string $role): string
{
    return match (strtolower(trim($role))) {
        'admin' => 'Admin',
        'pengurus' => 'Pengurus',
        'petugas_absensi' => 'Petugas Absensi',
        'kiai' => 'Pengasuh',
        default => $role !== '' ? ucfirst($role) : 'Pengurus',
    };
}

function require_login(): void
{
    if (!is_logged_in()) {
        set_flash('error', 'Silakan login terlebih dahulu.');
        require_once __DIR__ . '/../helpers/app_path.php';
        app_redirect('login.php');
    }
}

function auth_redirect_access_denied(): void
{
    require_once __DIR__ . '/../helpers/app_path.php';
    $role = (string) ($_SESSION['user']['role'] ?? '');
    if ($role === 'petugas_absensi') {
        app_redirect('presensi/scan.php');
    }

    global $pdo;
    if ($pdo instanceof PDO && function_exists('get_allowed_permission_key_map')) {
        $allowedMap = get_allowed_permission_key_map($pdo);
        if ($allowedMap === null) {
            app_redirect('dashboard.php');
        }
        if ($allowedMap === []) {
            unset($_SESSION['user']);
            set_flash('error', 'Akun belum memiliki hak akses. Hubungi admin super.');
            app_redirect('login.php');
        }
        if (!function_exists('user_permission_path_map')) {
            require_once __DIR__ . '/../helpers/user_permissions.php';
        }
        $requestPath = app_normalize_request_path((string) ($_SERVER['REQUEST_URI'] ?? ''));
        $fallbackPath = app_acl_first_allowed_path(user_permission_path_map(), $allowedMap);
        if ($fallbackPath !== null && !app_acl_request_paths_equal($requestPath, $fallbackPath)) {
            app_redirect_path($fallbackPath);
        }
    }

    unset($_SESSION['user']);
    set_flash('error', 'Anda tidak memiliki akses ke halaman ini.');
    app_redirect('login.php');
}

function require_roles(array $roles): void
{
    require_login();
    if (is_super_admin()) {
        return;
    }

    $role = (string) ($_SESSION['user']['role'] ?? 'admin');
    require_once __DIR__ . '/../helpers/app_path.php';
    $permissionKey = permission_key_for_request(app_normalize_request_path((string) ($_SERVER['REQUEST_URI'] ?? '')));
    if ($permissionKey !== null) {
        if (!user_has_current_page_permission()) {
            set_flash('error', 'Anda tidak memiliki akses ke halaman ini.');
            auth_redirect_access_denied();
        }
        return;
    }

    if (!in_array($role, $roles, true)) {
        set_flash('error', 'Anda tidak memiliki akses ke halaman ini.');
        auth_redirect_access_denied();
    }
}

function is_super_admin(): bool
{
    return (int) ($_SESSION['user']['is_super_admin'] ?? 0) === 1;
}

function require_super_admin(): void
{
    require_login();
    if (!is_super_admin()) {
        set_flash('error', 'Fitur ini hanya untuk admin super.');
        auth_redirect_access_denied();
    }
}

/** Koreksi/hapus riwayat pembayaran — role admin atau super admin (bukan pengurus biasa). */
function user_can_koreksi_pembayaran(): bool
{
    if (!isset($_SESSION['user'])) {
        return false;
    }
    if (is_super_admin()) {
        return true;
    }

    return strtolower((string) ($_SESSION['user']['role'] ?? '')) === 'admin';
}

function require_koreksi_pembayaran(): void
{
    require_login();
    if (!user_can_koreksi_pembayaran()) {
        set_flash('error', 'Koreksi pembayaran hanya untuk admin.');
        auth_redirect_access_denied();
    }
}

/** Lihat log audit operasional (pembayaran + jadwal, …) — hanya super admin. */
function user_can_lihat_audit_operasional(): bool
{
    return is_super_admin();
}

/** @deprecated Gunakan user_can_lihat_audit_operasional() */
function user_can_lihat_audit_pembayaran(): bool
{
    return user_can_lihat_audit_operasional();
}

function require_lihat_audit_operasional(): void
{
    require_login();
    if (!user_can_lihat_audit_operasional()) {
        set_flash('error', 'Log audit hanya untuk admin super.');
        auth_redirect_access_denied();
    }
}

/** Nilai keaktifan (Baik/Sedang/Buruk) — hanya super admin & pengasuh (role kiai). */
function user_can_view_keaktifan_nilai(): bool
{
    if (isset($_SESSION['wali']) || isset($_SESSION['mukimin'])) {
        return false;
    }
    if (isset($_SESSION['santri_portal'])) {
        return false;
    }
    if (!isset($_SESSION['user'])) {
        return false;
    }
    if (is_super_admin()) {
        return true;
    }

    return strtolower((string) ($_SESSION['user']['role'] ?? '')) === 'kiai';
}

/** Input / ubah nilai keaktifan — super admin & pengasuh (role kiai). */
function user_can_edit_keaktifan_nilai(): bool
{
    return user_can_view_keaktifan_nilai();
}

/** Lihat nilai keaktifan: pengasuh (semua) atau santri hanya data sendiri. */
function user_can_view_keaktifan_nilai_for_santri(int $santriId): bool
{
    if ($santriId <= 0) {
        return false;
    }
    if (isset($_SESSION['santri_portal']) && (int) ($_SESSION['santri_portal']['santri_id'] ?? 0) === $santriId) {
        return true;
    }

    return user_can_view_keaktifan_nilai();
}

function require_keaktifan_nilai_edit(): void
{
    require_login();
    if (!user_can_edit_keaktifan_nilai()) {
        set_flash('error', 'Penilaian keaktifan hanya untuk pengasuh pondok.');
        auth_redirect_access_denied();
    }
}

/** @deprecated Gunakan user_can_view_keaktifan_nilai() atau user_can_view_pelanggaran_riwayat(). */
function user_can_view_riwayat_sensitif(): bool
{
    return user_can_view_keaktifan_nilai();
}

/** Riwayat pelanggaran: staff pondok, wali anak tersebut, atau santri sendiri (portal). */
function user_can_view_pelanggaran_riwayat(int $santriId): bool
{
    if ($santriId <= 0) {
        return false;
    }
    if (isset($_SESSION['wali']) && (int) ($_SESSION['wali']['santri_id'] ?? 0) === $santriId) {
        return true;
    }
    if (isset($_SESSION['santri_portal']) && (int) ($_SESSION['santri_portal']['santri_id'] ?? 0) === $santriId) {
        return true;
    }
    if (!isset($_SESSION['user'])) {
        return false;
    }
    if (is_super_admin()) {
        return true;
    }
    $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));

    return in_array($role, ['admin', 'pengurus', 'kiai'], true);
}

function require_keaktifan_nilai_riwayat(): void
{
    require_login();
    if (!user_can_view_keaktifan_nilai()) {
        set_flash('error', 'Nilai keaktifan hanya untuk pengasuh / super admin.');
        auth_redirect_access_denied();
    }
}

/** @deprecated */
function require_riwayat_sensitif(): void
{
    require_keaktifan_nilai_riwayat();
}

function permission_key_for_request(string $requestPath): ?string
{
    if (!function_exists('user_permission_key_for_path')) {
        require_once __DIR__ . '/../helpers/user_permissions.php';
    }

    return user_permission_key_for_path($requestPath);
}

function user_has_current_page_permission(): bool
{
    if (is_super_admin()) {
        return true;
    }

    require_once __DIR__ . '/../helpers/app_path.php';
    $permissionKey = permission_key_for_request(app_normalize_request_path((string) ($_SERVER['REQUEST_URI'] ?? '')));
    if ($permissionKey === null) {
        return false;
    }

    $userId = (int) ($_SESSION['user']['id'] ?? 0);
    if ($userId <= 0) {
        $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));

        return $role === 'petugas_absensi' && $permissionKey === 'presensi_scan';
    }

    return user_can_access_permission_key($permissionKey);
}

function user_can_access_permission_key(string $permissionKey): bool
{
    if (is_super_admin()) {
        return true;
    }
    $userId = (int) ($_SESSION['user']['id'] ?? 0);
    if ($userId <= 0 || $permissionKey === '') {
        return false;
    }
    global $pdo;
    if (!$pdo instanceof PDO) {
        return false;
    }
    $allowed = get_allowed_permission_key_map($pdo);
    if ($allowed === null) {
        return true;
    }

    return isset($allowed[$permissionKey]);
}
