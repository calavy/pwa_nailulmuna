<?php

require_once __DIR__ . '/../config/session.php';

function is_logged_in(): bool
{
    return isset($_SESSION['user']);
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
    app_redirect('dashboard.php');
}

function require_roles(array $roles): void
{
    require_login();
    if (is_super_admin()) {
        return;
    }

    $role = (string) ($_SESSION['user']['role'] ?? 'admin');
    $permissionKey = permission_key_for_request((string) ($_SERVER['REQUEST_URI'] ?? ''));
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

/** Nilai keaktifan (Baik/Sedang/Buruk) — hanya super admin & pengasuh (role kiai). */
function user_can_view_keaktifan_nilai(): bool
{
    if (isset($_SESSION['wali']) || isset($_SESSION['santri_portal']) || isset($_SESSION['mukimin'])) {
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
    $pathMap = [
        '/pwa_nailulmuna/dashboard.php' => 'dashboard',
        '/pwa_nailulmuna/santri/index.php' => 'santri_index',
        '/pwa_nailulmuna/santri/export_excel.php' => 'santri_index',
        '/pwa_nailulmuna/santri/mukimin.php' => 'santri_index',
        '/pwa_nailulmuna/santri/mukimin_export.php' => 'santri_index',
        '/pwa_nailulmuna/santri/mukimin_import.php' => 'santri_import',
        '/pwa_nailulmuna/mukimin/login.php' => 'santri_index',
        '/pwa_nailulmuna/mukimin/index.php' => 'santri_index',
        '/pwa_nailulmuna/mukimin/unduh.php' => 'santri_index',
        '/pwa_nailulmuna/mukimin/logout.php' => 'santri_index',
        '/pwa_nailulmuna/santri/muqim_boyong.php' => 'santri_index',
        '/pwa_nailulmuna/santri/keluar.php' => 'santri_index',
        '/pwa_nailulmuna/santri/keluar_kekurangan_print.php' => 'santri_index',
        '/pwa_nailulmuna/santri/surat_keluar.php' => 'santri_index',
        '/pwa_nailulmuna/santri/surat_tanggungan.php' => 'santri_index',
        '/pwa_nailulmuna/santri/semua_jati.php' => 'santri_index',
        '/pwa_nailulmuna/santri/riwayat.php' => 'santri_index',
        '/pwa_nailulmuna/santri/hidmah.php' => 'santri_index',
        '/pwa_nailulmuna/santri/nonaktif_cepat.php' => 'santri_index',
        '/pwa_nailulmuna/santri/alumni.php' => 'santri_index',
        '/pwa_nailulmuna/santri/alumni_export.php' => 'santri_index',
        '/pwa_nailulmuna/santri/alumni_import.php' => 'santri_import',
        '/pwa_nailulmuna/santri/create.php' => 'santri_create',
        '/pwa_nailulmuna/santri/edit.php' => 'santri_index',
        '/pwa_nailulmuna/santri/import.php' => 'santri_import',
        '/pwa_nailulmuna/pembimbing/edit.php' => 'pembimbing',
        '/pwa_nailulmuna/jadwal/edit.php' => 'jadwal',
        '/pwa_nailulmuna/perizinan/edit.php' => 'perizinan',
        '/pwa_nailulmuna/data/wali.php' => 'santri_index',
        '/pwa_nailulmuna/settings/akses_mukimin.php' => 'santri_index',
        '/pwa_nailulmuna/presensi/scan.php' => 'presensi_scan',
        '/pwa_nailulmuna/jadwal/index.php' => 'jadwal',
        '/pwa_nailulmuna/akademik/hafalan.php' => 'akademik_hafalan',
        '/pwa_nailulmuna/akademik/bait_kitab.php' => 'akademik_hafalan',
        '/pwa_nailulmuna/akademik/kalender.php' => 'akademik_hafalan',
        '/pwa_nailulmuna/akademik/rapor.php' => 'akademik_hafalan',
        '/pwa_nailulmuna/settings/pusat.php' => 'pengaturan',
        '/pwa_nailulmuna/settings/pesantren.php' => 'pengaturan',
        '/pwa_nailulmuna/settings/peraturan.php' => 'pengaturan',
        '/pwa_nailulmuna/settings/kelas_ruangan.php' => 'pengaturan',
        '/pwa_nailulmuna/settings/kelas_keuangan.php' => 'pengaturan',
        '/pwa_nailulmuna/settings/tingkatan.php' => 'pengaturan',
        '/pwa_nailulmuna/settings/kamar_ranjang.php' => 'pengaturan',
        '/pwa_nailulmuna/settings/index.php' => 'pengaturan',
        '/pwa_nailulmuna/settings/kalender.php' => 'pengaturan',
        '/pwa_nailulmuna/settings/hijri_mappings.php' => 'pengaturan',
        '/pwa_nailulmuna/perizinan/index.php' => 'perizinan',
        '/pwa_nailulmuna/perizinan/izin_tetap.php' => 'perizinan',
        '/pwa_nailulmuna/perizinan/kembali.php' => 'perizinan_scan',
        '/pwa_nailulmuna/perizinan/permohonan.php' => 'perizinan_permohonan',
        '/pwa_nailulmuna/admin/surat_nomor.php' => 'perizinan',
        '/pwa_nailulmuna/admin/rekap_surat_izin.php' => 'perizinan',
        '/pwa_nailulmuna/admin/rekap_surat_sp.php' => 'perizinan',
        '/pwa_nailulmuna/pembimbing/index.php' => 'pembimbing',
        '/pwa_nailulmuna/pembimbing/presensi.php' => 'presensi_scan',
        '/pwa_nailulmuna/pembimbing/perizinan.php' => 'pembimbing_perizinan',
        '/pwa_nailulmuna/rekap/santri_bagus.php' => 'rekap_keaktifan',
        '/pwa_nailulmuna/rekap/index.php' => 'rekap',
        '/pwa_nailulmuna/rekap/izin_telat.php' => 'rekap_telat',
        '/pwa_nailulmuna/rekap/pembimbing.php' => 'rekap_pembimbing',
        '/pwa_nailulmuna/poin/input.php' => 'poin_input',
        '/pwa_nailulmuna/poin/rekap.php' => 'poin_rekap',
        '/pwa_nailulmuna/poin/settings.php' => 'pengaturan',
        '/pwa_nailulmuna/keuangan/index.php' => 'keuangan',
        '/pwa_nailulmuna/keuangan/cashless_scan.php' => 'keuangan',
        '/pwa_nailulmuna/keuangan/cashless_pin.php' => 'pengaturan',
        '/pwa_nailulmuna/pembayaran/index.php' => 'keuangan',
        '/pwa_nailulmuna/pembayaran/tagihan_syahriyah.php' => 'keuangan',
        '/pwa_nailulmuna/pembayaran/riwayat.php' => 'keuangan',
        '/pwa_nailulmuna/pembayaran/laporan.php' => 'keuangan',
        '/pwa_nailulmuna/pembayaran/rekap_pos.php' => 'keuangan',
        '/pwa_nailulmuna/settings/admin.php' => 'settings_admin',
    ];

    foreach ($pathMap as $path => $permissionKey) {
        if (str_contains($requestPath, $path)) {
            return $permissionKey;
        }
    }

    return null;
}

function user_has_current_page_permission(): bool
{
    if (is_super_admin()) {
        return true;
    }

    $permissionKey = permission_key_for_request((string) ($_SERVER['REQUEST_URI'] ?? ''));
    if ($permissionKey === null) {
        return false;
    }

    $userId = (int) ($_SESSION['user']['id'] ?? 0);
    if ($userId <= 0) {
        $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));

        return $role === 'petugas_absensi' && $permissionKey === 'presensi_scan';
    }

    global $pdo;
    if (!$pdo instanceof PDO) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('SELECT 1 FROM user_access_permissions WHERE user_id = :user_id AND permission_key = :permission_key LIMIT 1');
        $stmt->execute([
            'user_id' => $userId,
            'permission_key' => $permissionKey,
        ]);

        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
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
    try {
        $stmt = $pdo->prepare('SELECT 1 FROM user_access_permissions WHERE user_id = :user_id AND permission_key = :permission_key LIMIT 1');
        $stmt->execute([
            'user_id' => $userId,
            'permission_key' => $permissionKey,
        ]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
