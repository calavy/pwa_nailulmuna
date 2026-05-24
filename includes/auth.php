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
    $pathMap = [
        '/dashboard.php' => 'dashboard',
        '/santri/index.php' => 'santri_index',
        '/santri/export_excel.php' => 'santri_index',
        '/santri/mukimin.php' => 'santri_index',
        '/santri/mukimin_export.php' => 'santri_index',
        '/santri/mukimin_import.php' => 'santri_import',
        '/mukimin/login.php' => 'santri_index',
        '/mukimin/index.php' => 'santri_index',
        '/mukimin/unduh.php' => 'santri_index',
        '/mukimin/logout.php' => 'santri_index',
        '/santri/muqim_boyong.php' => 'santri_index',
        '/santri/keluar.php' => 'santri_index',
        '/santri/keluar_kekurangan_print.php' => 'santri_index',
        '/santri/surat_keluar.php' => 'santri_index',
        '/santri/surat_tanggungan.php' => 'santri_index',
        '/santri/semua_jati.php' => 'santri_index',
        '/santri/riwayat.php' => 'santri_index',
        '/santri/hidmah.php' => 'santri_index',
        '/santri/nonaktif_cepat.php' => 'santri_index',
        '/santri/alumni.php' => 'santri_index',
        '/santri/alumni_export.php' => 'santri_index',
        '/santri/alumni_import.php' => 'santri_import',
        '/santri/create.php' => 'santri_create',
        '/santri/edit.php' => 'santri_index',
        '/santri/import.php' => 'santri_import',
        '/pembimbing/edit.php' => 'pembimbing',
        '/jadwal/edit.php' => 'jadwal',
        '/perizinan/edit.php' => 'perizinan',
        '/data/wali.php' => 'santri_index',
        '/settings/akses_mukimin.php' => 'santri_index',
        '/presensi/scan.php' => 'presensi_scan',
        '/jadwal/index.php' => 'jadwal',
        '/jadwal/tambah.php' => 'jadwal',
        '/jadwal/tambah_kegiatan.php' => 'jadwal',
        '/akademik/hafalan.php' => 'akademik_hafalan',
        '/akademik/bait_kitab.php' => 'akademik_hafalan',
        '/akademik/kalender.php' => 'akademik_hafalan',
        '/akademik/rapor.php' => 'akademik_hafalan',
        '/akademik/rapor_lihat.php' => 'akademik_hafalan',
        '/akademik/rapor_cetak.php' => 'akademik_hafalan',
        '/pembimbing/tugas/index.php' => 'akademik_ikhtibar',
        '/pembimbing/tugas/buat.php' => 'akademik_ikhtibar',
        '/pembimbing/tugas/nilai.php' => 'akademik_ikhtibar',
        '/pembimbing/tugas/rekap.php' => 'akademik_ikhtibar',
        '/akademik/ikhtibar_rekap.php' => 'akademik_ikhtibar',
        '/settings/pusat.php' => 'pengaturan',
        '/settings/pesantren.php' => 'pengaturan',
        '/settings/peraturan.php' => 'pengaturan',
        '/settings/kelas_ruangan.php' => 'pengaturan',
        '/settings/kelas_keuangan.php' => 'pengaturan',
        '/settings/tingkatan.php' => 'pengaturan',
        '/settings/kamar_ranjang.php' => 'pengaturan',
        '/settings/index.php' => 'pengaturan',
        '/settings/kalender.php' => 'pengaturan',
        '/settings/hijri_mappings.php' => 'pengaturan',
        '/perizinan/index.php' => 'perizinan',
        '/perizinan/izin_tetap.php' => 'perizinan',
        '/perizinan/kembali.php' => 'perizinan_scan',
        '/perizinan/permohonan.php' => 'perizinan_permohonan',
        '/admin/surat_nomor.php' => 'perizinan',
        '/admin/rekap_surat_izin.php' => 'perizinan',
        '/admin/rekap_surat_sp.php' => 'perizinan',
        '/pembimbing/index.php' => 'pembimbing',
        '/pembimbing/presensi.php' => 'presensi_scan',
        '/pembimbing/perizinan.php' => 'pembimbing_perizinan',
        '/rekap/santri_bagus.php' => 'rekap_keaktifan',
        '/rekap/index.php' => 'rekap',
        '/rekap/izin_telat.php' => 'rekap_telat',
        '/rekap/pembimbing.php' => 'rekap_pembimbing',
        '/poin/input.php' => 'poin_input',
        '/poin/rekap.php' => 'poin_rekap',
        '/poin/settings.php' => 'pengaturan',
        '/keuangan/index.php' => 'keuangan',
        '/keuangan/cashless_scan.php' => 'keuangan',
        '/keuangan/cashless_laporan.php' => 'keuangan',
        '/keuangan/cashless_pin.php' => 'pengaturan',
        '/pembayaran/index.php' => 'keuangan',
        '/pembayaran/tagihan_syahriyah.php' => 'keuangan',
        '/pembayaran/riwayat.php' => 'keuangan',
        '/pembayaran/laporan.php' => 'keuangan',
        '/pembayaran/rekap_pos.php' => 'keuangan',
        '/settings/admin.php' => 'settings_admin',
        '/yayasan/pengurus.php' => 'yayasan',
        '/yayasan/rapat.php' => 'yayasan',
        '/yayasan/notulen.php' => 'yayasan',
        '/yayasan/executive.php' => 'yayasan',
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
