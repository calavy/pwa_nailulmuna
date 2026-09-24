<?php

declare(strict_types=1);

/**
 * UAT ringkas: penepian keaktifan (schema, helper, sync hook terdaftar).
 *
 * Usage: php scripts/_uat_penepian_keaktifan.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/santri_penepian_keaktifan.php';
require_once __DIR__ . '/../includes/auth.php';

$fail = 0;
$ok = static function (string $msg) use (&$fail): void {
    echo '[OK] ' . $msg . PHP_EOL;
};
$bad = static function (string $msg) use (&$fail): void {
    echo '[FAIL] ' . $msg . PHP_EOL;
    $fail++;
};

santri_penepian_keaktifan_ensure_schema($pdo);
if (!table_exists($pdo, 'santri_penepian_keaktifan')) {
    $bad('Tabel santri_penepian_keaktifan tidak ada setelah ensure_schema');
} else {
    $ok('Tabel santri_penepian_keaktifan ada');
}

$today = date('Y-m-d');
$map = santri_penepian_map_for_date($pdo, $today);
if (!is_array($map)) {
    $bad('santri_penepian_map_for_date bukan array');
} else {
    $ok('santri_penepian_map_for_date mengembalikan array (' . count($map) . ' santri hari ini)');
}

$src = file_get_contents(__DIR__ . '/../helpers/app.php') ?: '';
if (strpos($src, 'santri_penepian_map_for_date') === false || strpos($src, 'penepianMap') === false) {
    $bad('sync_daily_presence_for_tingkatan_impl belum memakai penepianMap');
} else {
    $ok('Hook sync presensi memuat penepianMap');
}

$scanSrc = file_get_contents(__DIR__ . '/../helpers/presensi_scan_post.inc.php') ?: '';
if (strpos($scanSrc, 'santri_penepian_is_active') === false) {
    $bad('Scan presensi belum cek santri_penepian_is_active');
} else {
    $ok('Scan presensi menolak hitung saat menepi');
}

if (!is_file(__DIR__ . '/../perizinan/penepian_keaktifan.php')) {
    $bad('Halaman perizinan/penepian_keaktifan.php tidak ada');
} else {
    $ok('UI penepian_keaktifan.php ada');
}

require_once __DIR__ . '/../helpers/pengasuh_dashboard.php';
require_once __DIR__ . '/../helpers/pengasuh_laporan_hari.php';
if (!function_exists('santri_penepian_list_aktif_on_date')) {
    $bad('santri_penepian_list_aktif_on_date tidak ada');
} else {
    $ok('Helper santri_penepian_list_aktif_on_date ada');
}
if (!function_exists('pengasuh_dashboard_santri_penepian_hari_ini')) {
    $bad('pengasuh_dashboard_santri_penepian_hari_ini tidak ada');
} else {
    pengasuh_dashboard_santri_penepian_hari_ini($pdo, $today, 5);
    $ok('pengasuh_dashboard_santri_penepian_hari_ini dapat dipanggil');
}
if (!function_exists('pengasuh_laporan_hari_penepian')) {
    $bad('pengasuh_laporan_hari_penepian tidak ada');
} else {
    pengasuh_laporan_hari_penepian($pdo, $today);
    $ok('pengasuh_laporan_hari_penepian dapat dipanggil');
}
$dashSrc = file_get_contents(__DIR__ . '/../pengasuh/dashboard.php') ?: '';
if (strpos($dashSrc, 'pg-dash-absensi-row') === false) {
    $bad('Dashboard pengasuh belum punya row pg-dash-absensi-row (izin + menepi sejajar)');
} else {
    $ok('Layout izin & menepi berdampingan (pg-dash-absensi-row)');
}
if (strpos($dashSrc, 'pg-dash-penepian') === false) {
    $bad('Dashboard pengasuh belum punya panel pg-dash-penepian');
} else {
    $ok('Dashboard pengasuh memuat panel menepi');
}
if (strpos($dashSrc, 'id="pg-dash-penepian"') === false) {
    $bad('Panel menepi (pg-dash-penepian) tidak ditemukan');
} else {
    $ok('Panel menepi selalu dirender');
}
if (strpos($dashSrc, 'pgDashIzinDetail') !== false || strpos($dashSrc, 'pgDashPenepianDetail') !== false) {
    $bad('Dashboard masih memakai collapse inline izin/menepi — harus navigasi ke halaman daftar');
} else {
    $ok('Tidak ada collapse inline izin/menepi di dashboard');
}
if (strpos($dashSrc, 'pg-dash-izin-list') !== false || strpos($dashSrc, 'pg-dash-penepian-list') !== false) {
    $bad('Daftar izin/menepi masih di dashboard — harus hanya di halaman tujuan');
} else {
    $ok('Dashboard hanya ringkas; daftar penuh di halaman lain');
}
if (strpos($dashSrc, '/pengasuh/perizinan.php') === false) {
    $bad('Kartu izin belum mengarah ke pengasuh/perizinan.php');
} else {
    $ok('Kartu izin mengarah ke halaman persetujuan');
}
if (strpos($dashSrc, '/pengasuh/penepian.php') === false) {
    $bad('Kartu menepi belum mengarah ke pengasuh/penepian.php');
} else {
    $ok('Kartu menepi mengarah ke halaman daftar menepi');
}
if (!is_file(__DIR__ . '/../pengasuh/penepian.php')) {
    $bad('Halaman pengasuh/penepian.php belum ada');
} else {
    $ok('Halaman pengasuh/penepian.php ada');
}
if (!function_exists('santri_penepian_hari_berjalan')) {
    $bad('santri_penepian_hari_berjalan tidak ada');
} else {
    $h = santri_penepian_hari_berjalan($today, $today);
    if ($h !== 1) {
        $bad('santri_penepian_hari_berjalan harus 1 jika mulai = acuan (got ' . $h . ')');
    } else {
        $ok('santri_penepian_hari_berjalan menghitung hari inklusif');
    }
}
if (strpos($dashSrc, 'pg-dash-absensi-toggle') === false || strpos($dashSrc, 'fa-chevron-right') === false) {
    $bad('Link navigasi pg-dash-absensi-toggle / chevron kanan tidak ada');
} else {
    $ok('Kartu navigasi dengan chevron kanan ada');
}
if (strpos($dashSrc, 'perizinan_pengasuh_pending_count') === false) {
    $bad('Dashboard belum memakai perizinan_pengasuh_pending_count untuk badge izin');
} else {
    $ok('Badge izin memakai pending count ringan');
}
$partialSrc = file_get_contents(__DIR__ . '/../includes/partials/laporan_hari_pelengkap.php') ?: '';
if (strpos($partialSrc, 'laporan-penepian') === false) {
    $bad('Laporan hari belum punya section laporan-penepian');
} else {
    $ok('Partial laporan hari memuat section menepi');
}

if (!function_exists('user_can_manage_santri_penepian') || !function_exists('require_manage_santri_penepian')) {
    $bad('Helper ACL user_can_manage_santri_penepian / require_manage_santri_penepian tidak ada');
} else {
    $ok('Helper ACL penepian (super admin + pengasuh) terdaftar');
}
$penepianPageSrc = file_get_contents(__DIR__ . '/../perizinan/penepian_keaktifan.php') ?: '';
if (strpos($penepianPageSrc, 'require_manage_santri_penepian') === false) {
    $bad('penepian_keaktifan.php harus memakai require_manage_santri_penepian');
} elseif (preg_match('/require_roles\s*\(\s*\[[^\]]*pengurus/s', $penepianPageSrc)) {
    $bad('penepian_keaktifan.php masih require_roles pengurus/petugas');
} else {
    $ok('Halaman CRUD penepian gated super admin + pengasuh');
}
$editSrc = file_get_contents(__DIR__ . '/../santri/edit.php') ?: '';
if (strpos($editSrc, 'user_can_manage_santri_penepian') === false) {
    $bad('santri/edit.php belum guard tombol penepian dengan user_can_manage_santri_penepian');
} else {
    $ok('Pintasan edit santri hanya untuk yang boleh kelola penepian');
}
$hubSrc = file_get_contents(__DIR__ . '/../helpers/app_hub.php') ?: '';
if (strpos($hubSrc, '/perizinan/penepian_keaktifan.php') === false
    || strpos($hubSrc, 'user_can_manage_santri_penepian') === false) {
    $bad('app_hub belum filter tab penepian keaktifan by user_can_manage_santri_penepian');
} else {
    $ok('Tab hub penepian hanya untuk super admin / pengasuh');
}
$permSrc = file_get_contents(__DIR__ . '/../helpers/user_permissions.php') ?: '';
if (strpos($permSrc, "'/perizinan/penepian_keaktifan.php' =>") === false
    || strpos($permSrc, 'rekap_keaktifan_hari') === false) {
    $bad('user_permissions belum alt key rekap_keaktifan_hari untuk penepian_keaktifan');
} else {
    $ok('ACL path penepian mendukung akses pengasuh (rekap_keaktifan_hari)');
}

echo PHP_EOL . ($fail === 0 ? 'UAT penepian: LULUS (' . $fail . ' gagal)' : 'UAT penepian: GAGAL (' . $fail . ' cek)') . PHP_EOL;
exit($fail === 0 ? 0 : 1);
