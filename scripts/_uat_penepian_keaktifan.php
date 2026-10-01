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

if (!function_exists('santri_penepian_selesai_dari_scan_kartu')) {
    $bad('santri_penepian_selesai_dari_scan_kartu tidak ada');
} else {
    $ok('Helper santri_penepian_selesai_dari_scan_kartu ada');
}
if (!function_exists('santri_penepian_blocks_presensi')) {
    $bad('santri_penepian_blocks_presensi tidak ada');
} else {
    $ok('Helper santri_penepian_blocks_presensi ada');
}
if (!function_exists('santri_penepian_selesai_on_date')) {
    $bad('santri_penepian_selesai_on_date tidak ada');
} else {
    $ok('Helper santri_penepian_selesai_on_date ada');
}

$scanSrc = file_get_contents(__DIR__ . '/../helpers/presensi_scan_post.inc.php') ?: '';
if (strpos($scanSrc, 'santri_penepian_selesai_dari_scan_kartu') === false) {
    $bad('Scan presensi belum memanggil santri_penepian_selesai_dari_scan_kartu');
} else {
    $ok('Scan presensi menutup penepian dari scan kartu');
}
if (strpos($scanSrc, 'santri_penepian_blocks_presensi') === false) {
    $bad('Scan presensi belum cek santri_penepian_blocks_presensi');
} else {
    $ok('Scan presensi memakai blocks_presensi untuk blokir menepi');
}

$fakeSid = 999999991;
if (santri_penepian_blocks_presensi($pdo, $fakeSid, $today)) {
    $bad('blocks_presensi seharusnya false untuk santri tanpa penepian');
} else {
    $ok('blocks_presensi false bila tidak ada record');
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

$penepianPageSrc = $penepianPageSrc ?? (file_get_contents(__DIR__ . '/../perizinan/penepian_keaktifan.php') ?: '');
if (strpos($penepianPageSrc, 'name="tanggal_selesai"') !== false) {
    $bad('Form penepian masih memiliki input tanggal_selesai');
} else {
    $ok('Form penepian hanya tanggal mulai (tanpa selesai)');
}
if (strpos($penepianPageSrc, 'penepian-shortcut') !== false) {
    $bad('Pintasan +N hari selesai masih ada di form penepian');
} else {
    $ok('Pintasan rentang selesai dihapus dari form');
}
$helperSrc = file_get_contents(__DIR__ . '/../helpers/santri_penepian_keaktifan.php') ?: '';
if (strpos($helperSrc, 'santri_penepian_sql_covers_date') === false) {
    $bad('Helper santri_penepian_sql_covers_date belum ada');
} else {
    $ok('Query penepian mendukung tanggal_selesai NULL');
}
if (!function_exists('santri_penepian_open_end_date')) {
    $bad('santri_penepian_open_end_date tidak ada');
} else {
    $ok('Konstanta open-end penepian tersedia');
}

require_once __DIR__ . '/../helpers/santri_keluar.php';
if (!function_exists('santri_keuangan_ringkasan_exit') || !function_exists('mukimin_santri_id_by_nis')) {
    $bad('Helper santri_keuangan_ringkasan_exit / mukimin_santri_id_by_nis tidak ada');
} else {
    $ok('Helper ringkasan keuangan keluar terdaftar');
}
$cardSrc = file_get_contents(__DIR__ . '/../includes/partials/santri_keuangan_exit_card.php') ?: '';
if (strpos($cardSrc, 'Administrasi keluar') === false) {
    $bad('Partial santri_keuangan_exit_card belum ada');
} else {
    $ok('Partial kartu ringkasan keuangan keluar ada');
}
$detailSrc = file_get_contents(__DIR__ . '/../includes/partials/santri_keuangan_exit_detail.php') ?: '';
if (strpos($detailSrc, 'Terbayar') === false) {
    $bad('Partial santri_keuangan_exit_detail belum lengkap (Terbayar)');
} else {
    $ok('Partial detail kekurangan & sisa uang ada');
}
$verdictSrc = file_get_contents(__DIR__ . '/../includes/partials/santri_keuangan_exit_verdict_cards.php') ?: '';
if ($verdictSrc === '' || strpos($verdictSrc, 'Uang yang harus dibayar') === false || strpos($verdictSrc, 'dikembalikan') === false) {
    $bad('Partial santri_keuangan_exit_verdict_cards belum ada / belum lengkap');
} else {
    $ok('Kartu bayar vs kembalikan (verdict) ada');
}
$keluarHelperSrc = file_get_contents(__DIR__ . '/../helpers/santri_keluar.php') ?: '';
if (strpos($keluarHelperSrc, 'nominal_harus_dibayar') === false) {
    $bad('Helper ringkasan exit belum memuat nominal_harus_dibayar');
} else {
    $ok('Helper nominal harus dibayar / dikembalikan terdaftar');
}
if (strpos($cardSrc, 'santri_keuangan_exit_verdict_cards') === false) {
    $bad('Kartu ringkasan belum memuat partial verdict');
} else {
    $ok('Kartu ringkasan memuat verdict bayar/kembalikan');
}
if (strpos($keluarHelperSrc, 'santri_keuangan_ringkasan_exit($pdo, $sid, false)') === false) {
    $bad('Batch mukimin harus memanggil ringkasan exit tanpa detail (includeDetail=false)');
} else {
    $ok('Batch ringkasan keuangan mukimin tanpa baris detail');
}
$mukSrc = file_get_contents(__DIR__ . '/../santri/mukimin.php') ?: '';
if (strpos($mukSrc, 'mukiminKeuMap') === false) {
    $bad('mukimin.php belum memuat ringkasan keuangan batch');
} else {
    $ok('Data Mukimin menampilkan badge keuangan');
}

$pwaBrandSrc = file_get_contents(__DIR__ . '/../helpers/pwa_brand.php') ?: '';
if (strpos($pwaBrandSrc, 'pwa_brand_icon_circle_background_hex') === false || strpos($pwaBrandSrc, 'pwa_icon_white_circle_v3') === false) {
    $bad('Ikon PWA putih production (v3 / cache ver) belum di pwa_brand.php');
} else {
    $ok('Helper ikon PWA lingkaran putih + cache-bust terdaftar');
}
$appSrc = file_get_contents(__DIR__ . '/../helpers/app.php') ?: '';
if (strpos($appSrc, 'logo_ensure_pwa_icon_white_circle_v3') === false) {
    $bad('Migrasi pwa_icon_white_circle_v3 belum di-hook app.php');
} else {
    $ok('Migrasi splash putih + ikon v3 di app schema');
}
$regenScript = __DIR__ . '/regenerate_pwa_icons.php';
if (!is_file($regenScript)) {
    $bad('Script scripts/regenerate_pwa_icons.php belum ada');
} else {
    $ok('Script regenerate PWA production ada');
}

require_once __DIR__ . '/../helpers/rekap_keaktifan_hari.php';
if (!function_exists('rekap_keaktifan_hari_apply_penepian')) {
    $bad('rekap_keaktifan_hari_apply_penepian tidak ada');
} else {
    $ok('Helper rekap_keaktifan_hari_apply_penepian ada');
}
$detailMenepi = rekap_keaktifan_hari_detail_by_kegiatan([[
    'kegiatan_id' => 9001,
    'nama_kegiatan' => 'UAT',
    'status_hari_ini' => 'MENEPI',
    'nama_santri' => 'Santri UAT',
    'nis' => '000',
    'tingkatan' => 'TK',
    'jam_presensi' => null,
    'catatan' => '',
]]);
$alpaUat = (int) ($detailMenepi[0]['alpa'] ?? -1);
$menepiUat = (int) ($detailMenepi[0]['menepi'] ?? 0);
if ($alpaUat !== 0 || $menepiUat !== 1) {
    $bad('MENEPI harus menepi=1 alpa=0 di detail kegiatan (got menepi=' . $menepiUat . ' alpa=' . $alpaUat . ')');
} else {
    $ok('Status MENEPI tidak masuk hitungan alpa di rekap detail');
}
$khPanelSrc = file_get_contents(__DIR__ . '/../pengasuh/partials/dashboard_keaktivan_kategori_panel.php') ?: '';
if (strpos($khPanelSrc, 'MENEPI') === false || strpos($khPanelSrc, 'menepi') === false) {
    $bad('Panel keaktivan pengasuh belum menampilkan bucket Menepi');
} else {
    $ok('UI dashboard keaktivan memuat Menepi');
}

echo PHP_EOL . ($fail === 0 ? 'UAT penepian: LULUS (' . $fail . ' gagal)' : 'UAT penepian: GAGAL (' . $fail . ' cek)') . PHP_EOL;
exit($fail === 0 ? 0 : 1);
