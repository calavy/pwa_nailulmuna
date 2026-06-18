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
        'pembimbing' => 'Pembimbing',
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
    auth_refresh_user_session_from_db();
    auth_staff_acl_self_heal();
    auth_pembimbing_acl_self_heal();
}

/** Sinkronkan role & super-admin dari DB (sekali per sesi). */
function auth_refresh_user_session_from_db(): void
{
    if (!isset($_SESSION['user'])) {
        return;
    }
    $uid = (int) ($_SESSION['user']['id'] ?? 0);
    if ($uid <= 0) {
        return;
    }
    $marker = 'auth_user_db_sync_v1_' . $uid;
    if (!empty($_SESSION[$marker])) {
        return;
    }
    global $pdo;
    if (!($pdo instanceof PDO) || !function_exists('table_exists') || !table_exists($pdo, 'users')) {
        return;
    }
    $st = $pdo->prepare('SELECT nama, username, role, is_super_admin, foto_profil FROM users WHERE id = :id LIMIT 1');
    $st->execute(['id' => $uid]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return;
    }
    $_SESSION['user']['nama'] = (string) ($row['nama'] ?? $_SESSION['user']['nama'] ?? '');
    $_SESSION['user']['username'] = (string) ($row['username'] ?? $_SESSION['user']['username'] ?? '');
    $_SESSION['user']['role'] = (string) ($row['role'] ?? $_SESSION['user']['role'] ?? 'pengurus');
    $isSuper = (int) ($row['is_super_admin'] ?? 0) === 1;
    if (($_SESSION['user']['username'] ?? '') === 'admin') {
        $isSuper = true;
    }
    $_SESSION['user']['is_super_admin'] = $isSuper ? 1 : 0;
    $_SESSION['user']['foto_profil'] = trim((string) ($row['foto_profil'] ?? $_SESSION['user']['foto_profil'] ?? ''));
    $_SESSION[$marker] = 1;
}

/**
 * Pastikan pengurus/admin/petugas punya izin bawaan bila belum pernah diatur super admin.
 */
function auth_staff_acl_self_heal(): void
{
    if (!isset($_SESSION['user'])) {
        return;
    }
    if (is_super_admin()) {
        return;
    }
    $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
    if (!in_array($role, ['admin', 'pengurus', 'petugas_absensi'], true)) {
        return;
    }
    $uid = (int) ($_SESSION['user']['id'] ?? 0);
    if ($uid <= 0) {
        return;
    }
    $marker = 'staff_acl_healed_v1_' . $uid;
    if (!empty($_SESSION[$marker])) {
        return;
    }
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return;
    }
    if (!function_exists('user_permission_ensure_role_defaults')) {
        require_once __DIR__ . '/../helpers/user_permissions.php';
    }
    if (function_exists('user_permission_ensure_role_defaults')) {
        user_permission_ensure_role_defaults($pdo, $uid, $role);
    }
    if (function_exists('app_acl_session_cache_clear')) {
        app_acl_session_cache_clear($uid);
    }
    $_SESSION[$marker] = 1;
}

/** Verifikasi password login (bcrypt + legacy plain/md5). */
function auth_verify_user_password(string $password, string $storedHash): bool
{
    if ($storedHash === '' || $password === '') {
        return false;
    }
    if (password_verify($password, $storedHash)) {
        return true;
    }
    if (hash_equals($storedHash, $password)) {
        return true;
    }
    if (strlen($storedHash) === 32 && ctype_xdigit($storedHash) && hash_equals(md5($password), $storedHash)) {
        return true;
    }

    return false;
}

/**
 * Pastikan ACL akun ber-role 'pembimbing' selalu memuat whitelist standar
 * (dashboard, perizinan, ikhtibar, scan presensi). Dijalankan sekali per
 * sesi via marker — supaya pembimbing yang login SEBELUM perubahan ACL
 * baru tetap mendapatkan permission yang benar tanpa harus logout-login.
 */
function auth_pembimbing_acl_self_heal(): void
{
    if (!isset($_SESSION['user'])) {
        return;
    }
    $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
    if ($role !== 'pembimbing') {
        return;
    }
    $uid = (int) ($_SESSION['user']['id'] ?? 0);
    if ($uid <= 0) {
        return;
    }
    $marker = 'pembimbing_acl_healed_v5_' . $uid;
    if (!empty($_SESSION[$marker])) {
        return;
    }
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return;
    }
    if (!function_exists('login_pembimbing_ensure_acl')) {
        require_once __DIR__ . '/../helpers/login_pembimbing.php';
    }
    if (function_exists('login_pembimbing_ensure_acl')) {
        login_pembimbing_ensure_acl($pdo, $uid);
        $_SESSION[$marker] = 1;
    }
}

function auth_redirect_access_denied(): void
{
    require_once __DIR__ . '/../helpers/app_path.php';
    $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
    if ($role === 'petugas_absensi') {
        app_redirect('presensi/scan.php');
    }
    if ($role === 'kiai') {
        $requestPath = app_normalize_request_path((string) ($_SERVER['REQUEST_URI'] ?? ''));
        if (!app_acl_request_paths_equal($requestPath, '/pengasuh/dashboard.php')
            && !app_acl_request_paths_equal($requestPath, '/pengasuh/laporan_hari.php')
            && !app_acl_request_paths_equal($requestPath, '/pengasuh/nilai_keaktifan.php')
            && !app_acl_request_paths_equal($requestPath, '/pengasuh/sdm_hari.php')
            && !app_acl_request_paths_equal($requestPath, '/pengasuh/perizinan.php')) {
            app_redirect('pengasuh/dashboard.php');
        }
    }
    if ($role === 'pembimbing') {
        $requestPath = app_normalize_request_path((string) ($_SERVER['REQUEST_URI'] ?? ''));
        if (
            str_contains($requestPath, '/pembimbing/setoran')
            || str_contains($requestPath, '/api/setoran/')
            || str_contains($requestPath, '/akademik/setoran_rekap')
            || app_acl_request_paths_equal($requestPath, '/pembimbing/dashboard.php')
            || app_acl_request_paths_equal($requestPath, '/pembimbing/perizinan.php')
            || app_acl_request_paths_equal($requestPath, '/pembimbing/presensi.php')
            || str_contains($requestPath, '/pembimbing/nilai_manual')
            || app_acl_request_paths_equal($requestPath, '/pembimbing/pkpps_santri.php')
        ) {
            return;
        }
        app_redirect('pembimbing/dashboard.php');
    }

    global $pdo;
    if (!function_exists('get_allowed_permission_key_map')) {
        require_once __DIR__ . '/../helpers/app.php';
    }
    if ($pdo instanceof PDO && function_exists('get_allowed_permission_key_map')) {
        $allowedMap = get_allowed_permission_key_map($pdo);
        if ($allowedMap === null) {
            app_redirect('dashboard.php');
        }
        if ($allowedMap === []) {
            unset($_SESSION['_acl_redirect_guard'], $_SESSION['user']);
            set_flash('error', 'Akun belum memiliki hak akses. Hubungi admin super.');
            app_redirect('login.php');
        }
        if (!function_exists('user_permission_path_map')) {
            require_once __DIR__ . '/../helpers/user_permissions.php';
        }
        $requestPath = app_normalize_request_path((string) ($_SERVER['REQUEST_URI'] ?? ''));
        $fallbackPath = app_acl_first_allowed_path(user_permission_path_map(), $allowedMap, $requestPath);
        if ($fallbackPath !== null && app_acl_safe_redirect($fallbackPath, $requestPath)) {
            return;
        }
        if (is_array($allowedMap) && isset($allowedMap['dashboard'])) {
            app_redirect('dashboard.php');
        }
    }

    unset($_SESSION['_acl_redirect_guard'], $_SESSION['user']);
    set_flash('error', 'Anda tidak memiliki akses ke halaman ini.');
    app_redirect('login.php');
}

function require_roles(array $roles): void
{
    require_login();
    if (is_super_admin()) {
        return;
    }

    $role = strtolower((string) ($_SESSION['user']['role'] ?? 'admin'));
    if ($role === 'kiai' && !in_array('kiai', $roles, true)) {
        $roles[] = 'kiai';
    }
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
        if (function_exists('munawib_is_portal_session') && munawib_is_portal_session()) {
            return in_array($permissionKey, ['akademik_setoran', 'pembimbing_dashboard'], true);
        }
        $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));

        return $role === 'petugas_absensi' && $permissionKey === 'presensi_scan';
    }

    if (user_can_access_permission_key($permissionKey)) {
        return true;
    }

    $path = app_normalize_request_path((string) ($_SERVER['REQUEST_URI'] ?? ''));
    if ($path === '/presensi/rekap_tanpa_scan.php') {
        foreach (['rekap_keaktifan', 'rekap', 'rekap_hub', 'presensi_scan'] as $altKey) {
            if ($altKey !== $permissionKey && user_can_access_permission_key($altKey)) {
                return true;
            }
        }
    }

    // Pembimbing dengan izin jadwal sendiri boleh buka halaman modul jadwal (Kajian).
    if ($permissionKey === 'jadwal' && user_can_access_permission_key('pembimbing_jadwal')) {
        require_once __DIR__ . '/../helpers/jadwal_pembimbing.php';
        $path = app_normalize_request_path((string) ($_SERVER['REQUEST_URI'] ?? ''));

        return in_array($path, jadwal_pembimbing_self_service_paths(), true);
    }

    return false;
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
    if (!function_exists('get_allowed_permission_key_map')) {
        require_once __DIR__ . '/../helpers/app.php';
    }
    if (!function_exists('get_allowed_permission_key_map')) {
        return false;
    }
    $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
    if ($role === 'kiai' && $permissionKey === 'perizinan_permohonan') {
        return false;
    }

    $allowed = get_allowed_permission_key_map($pdo);
    if ($allowed === null) {
        return true;
    }

    return isset($allowed[$permissionKey]);
}
