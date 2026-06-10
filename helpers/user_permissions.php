<?php

declare(strict_types=1);

/**
 * Definisi hak akses per fitur / submenu (sumber tunggal untuk menu & admin).
 */

/** @return list<string> */
function user_permission_keuangan_keys(): array
{
    return [
        'keuangan_laporan',
        'keuangan_transaksi',
        'keuangan_potongan',
        'keuangan_talangan',
        'keuangan_inventaris',
        'keuangan_pengaturan_modul',
        'keuangan_cashless_scan',
        'keuangan_cashless_laporan',
        'keuangan_cashless_pin',
    ];
}

function user_permission_is_keuangan_key(string $key): bool
{
    return in_array($key, user_permission_keuangan_keys(), true);
}

/**
 * Grup untuk form admin (urutan tampilan).
 *
 * @return array<string, array{label:string, permissions:array<string, string>}>
 */
function user_permission_groups(): array
{
    return [
        'umum' => [
            'label' => 'Umum',
            'permissions' => [
                'dashboard' => 'Dashboard',
            ],
        ],
        'keuangan' => [
            'label' => 'Keuangan (submenu — centang per bagian)',
            'permissions' => [
                'keuangan_laporan' => 'Laporan — dashboard, neraca, arus kas, rekap POS, laporan syahriyah',
                'keuangan_transaksi' => 'Transaksi — input bayar, tagihan, riwayat, pemasukan, pengeluaran',
                'keuangan_potongan' => 'Potongan Syahriyah',
                'keuangan_talangan' => 'Dana Talangan',
                'keuangan_inventaris' => 'Inventaris Aset',
                'keuangan_pengaturan_modul' => 'Pengaturan modul keuangan (tarif, akun, alokasi)',
                'keuangan_cashless_scan' => 'Top Up / Scan Cashless',
                'keuangan_cashless_laporan' => 'Laporan Cashless Koperasi',
                'keuangan_cashless_pin' => 'Cashless & Uang Saku (PIN santri)',
            ],
        ],
        'santri' => [
            'label' => 'Santri & Data',
            'permissions' => [
                'santri_index' => 'Santri Aktif',
                'santri_create' => 'Tambah / Edit Santri',
                'santri_import' => 'Import Santri',
            ],
        ],
        'operasional' => [
            'label' => 'Operasional Harian (submenu)',
            'permissions' => [
                'presensi_scan' => 'Scan Presensi (santri & pembimbing)',
                'jadwal' => 'Jadwal — lihat & kelola',
                'jadwal_import' => 'Jadwal — import Excel/CSV',
                'perizinan' => 'Perizinan — review & setujui',
                'perizinan_permohonan' => 'Perizinan — ajukan permohonan',
                'perizinan_scan' => 'Perizinan — scan keluar/kembali',
                'munawib' => 'Munawib — data pengganti pembimbing',
            ],
        ],
        'pembimbing' => [
            'label' => 'Pembimbing',
            'permissions' => [
                'pembimbing' => 'Data Pembimbing',
                'pembimbing_dashboard' => 'Dashboard Pembimbing (santri & keaktifan per tingkatan)',
                'pembimbing_perizinan' => 'Izin Pembimbing',
                'pembimbing_jadwal' => 'Jadwal Kegiatan Pembimbing',
                'rekap_pembimbing' => 'Payroll / Gaji Pembimbing',
            ],
        ],
        'rekap' => [
            'label' => 'Rekap & Poin (submenu)',
            'permissions' => [
                'rekap_hub' => 'Pusat Rekap (semua laporan)',
                'rekap' => 'Rekap Presensi',
                'rekap_keaktifan_hari' => 'Keaktifan Santri Hari Ini',
                'rekap_keaktifan' => 'Rekap Keaktifan Santri (tahunan)',
                'rekap_telat' => 'Rekap Keterlambatan',
                'rekap_perizinan' => 'Rekap Perizinan Bulanan',
                'rekap_munawib' => 'Laporan Munawib',
                'rekap_pembimbing' => 'Payroll / Gaji Pembimbing',
                'poin_input' => 'Input Poin',
                'poin_rekap' => 'Rekap Poin',
            ],
        ],
        'akademik' => [
            'label' => 'Akademik',
            'permissions' => [
                'akademik_hafalan' => 'Setoran hafalan, bait, kalender, rapor',
                'akademik_setoran' => 'Input setoran scan (pembimbing/munawib)',
                'akademik_ikhtibar' => 'Tugas Ikhtibar (buat, nilai, rekap)',
            ],
        ],
        'pengaturan' => [
            'label' => 'Pengaturan Pondok',
            'permissions' => [
                'pengaturan' => 'Pengaturan umum (pondok, master data, kalender, dll.)',
                'poin_settings' => 'Peraturan Poin (legacy)',
                'settings_umum' => 'Settings Umum (legacy)',
                'settings_admin' => 'Kelola Akses User',
            ],
        ],
        'yayasan' => [
            'label' => 'Yayasan',
            'permissions' => [
                'yayasan' => 'Pengurus, rapat, notulen, executive',
            ],
        ],
    ];
}

/** @return array<string, string> */
function user_permission_flat_options(): array
{
    $flat = [];
    foreach (user_permission_groups() as $group) {
        foreach ($group['permissions'] as $key => $label) {
            $flat[$key] = $label;
        }
    }

    return $flat;
}

/** @return array<string, string> path => permission_key */
function user_permission_path_map_base(): array
{
    return [
        '/dashboard.php' => 'dashboard',
        '/santri/index.php' => 'santri_index',
        '/santri/mukimin.php' => 'santri_index',
        '/santri/mukimin_export.php' => 'santri_index',
        '/santri/mukimin_import.php' => 'santri_import',
        '/santri/keluar.php' => 'santri_index',
        '/santri/keluar_kekurangan_print.php' => 'santri_index',
        '/santri/surat_keluar.php' => 'santri_index',
        '/santri/surat_tanggungan.php' => 'santri_index',
        '/santri/semua_jati.php' => 'santri_index',
        '/santri/riwayat.php' => 'santri_index',
        '/santri/tingkatan_ta.php' => 'santri_index',
        '/santri/hidmah.php' => 'santri_index',
        '/santri/nonaktif_cepat.php' => 'santri_index',
        '/santri/export_excel.php' => 'santri_index',
        '/santri/alumni.php' => 'santri_index',
        '/santri/alumni_export.php' => 'santri_index',
        '/santri/alumni_import.php' => 'santri_import',
        '/santri/muqim_boyong.php' => 'santri_index',
        '/santri/create.php' => 'santri_create',
        '/santri/edit.php' => 'santri_index',
        '/santri/kartu.php' => 'santri_index',
        '/santri/kartu_qr.php' => 'santri_index',
        '/santri/kartu_batch.php' => 'santri_index',
        '/santri/import.php' => 'santri_import',
        '/pembimbing/edit.php' => 'pembimbing',
        '/jadwal/edit.php' => 'jadwal',
        '/perizinan/edit.php' => 'perizinan',
        '/data/wali.php' => 'santri_index',
        '/settings/akses_mukimin.php' => 'santri_index',
        '/presensi/scan.php' => 'presensi_scan',
        '/presensi/kegiatan_khusus.php' => 'presensi_scan',
        '/jadwal/index.php' => 'jadwal',
        '/jadwal/tambah.php' => 'jadwal',
        '/jadwal/kegiatan.php' => 'jadwal',
        '/jadwal/tambah_kegiatan.php' => 'jadwal',
        '/jadwal/import.php' => 'jadwal_import',
        '/pkpps/index.php' => 'jadwal',
        '/pkpps/santri.php' => 'jadwal',
        '/pkpps/import_santri.php' => 'jadwal_import',
        '/pkpps/jadwal.php' => 'jadwal',
        '/pkpps/import.php' => 'jadwal_import',
        '/rekap/pkpps_keaktivan.php' => 'jadwal',
        '/settings/tingkatan.php' => 'pengaturan',
        '/settings/tingkatan.php#pkpps' => 'pengaturan',
        '/jadwal/edit.php' => 'jadwal',
        '/pembimbing/munawib.php' => 'munawib',
        '/rekap/hub.php' => 'rekap_hub',
        '/rekap/keaktivan_sdm.php' => 'rekap_hub',
        '/rekap/keaktifan_hari.php' => 'rekap_keaktifan_hari',
        '/rekap/munawib.php' => 'rekap_munawib',
        '/rekap/kegiatan_khusus.php' => 'rekap_hub',
        '/rekap/perizinan.php' => 'rekap_perizinan',
        '/akademik/hafalan.php' => 'akademik_hafalan',
        '/akademik/bait_kitab.php' => 'akademik_hafalan',
        '/akademik/setoran_dashboard.php' => 'akademik_hafalan',
        '/akademik/setoran_rekap.php' => 'akademik_setoran',
        '/akademik/setoran_penerima.php' => 'akademik_hafalan',
        '/akademik/setoran_petugas.php' => 'akademik_hafalan',
        '/pembimbing/setoran.php' => 'akademik_setoran',
        '/pembimbing/setoran_dashboard.php' => 'akademik_setoran',
        '/pembimbing/setoran_perolehan.php' => 'akademik_setoran',
        '/pembimbing/setoran_keaktivan.php' => 'akademik_setoran',
        '/api/setoran/santri_scan.php' => 'akademik_setoran',
        '/akademik/setoran_rekap_kitab.php' => 'akademik_setoran',
        '/akademik/kalender.php' => 'akademik_hafalan',
        '/akademik/rapor.php' => 'akademik_hafalan',
        '/akademik/rapor_lihat.php' => 'akademik_hafalan',
        '/akademik/rapor_cetak.php' => 'akademik_hafalan',
        '/akademik/skbt.php' => 'akademik_hafalan',
        '/akademik/skbt_cetak.php' => 'akademik_hafalan',
        '/pembimbing/tugas/index.php' => 'akademik_ikhtibar',
        '/pembimbing/tugas/buat.php' => 'akademik_ikhtibar',
        '/pembimbing/tugas/nilai.php' => 'akademik_ikhtibar',
        '/pembimbing/tugas/rekap.php' => 'akademik_ikhtibar',
        '/akademik/ikhtibar_rekap.php' => 'akademik_ikhtibar',
        '/settings/pusat.php' => 'pengaturan',
        '/settings/wa_otomatis.php' => 'pengaturan',
        '/settings/wa_gateway.php' => 'pengaturan',
        '/settings/wa_pesan.php' => 'pengaturan',
        '/settings/wa_laporan_kelas_kosong.php' => 'pengaturan',
        '/settings/pesantren.php' => 'pengaturan',
        '/settings/peraturan.php' => 'pengaturan',
        '/settings/perizinan.php' => 'pengaturan',
        '/settings/alpa_notif.php' => 'pengaturan',
        '/settings/kelas_ruangan.php' => 'pengaturan',
        '/settings/kelas_keuangan.php' => 'pengaturan',
        '/settings/kelas_syahriyah.php' => 'pengaturan',
        '/settings/tarif_payroll.php' => 'pengaturan',
        '/settings/opsional_santri.php' => 'pengaturan',
        '/settings/tingkatan.php' => 'pengaturan',
        '/settings/kamar_ranjang.php' => 'pengaturan',
        '/settings/index.php' => 'pengaturan',
        '/settings/kalender.php' => 'pengaturan',
        '/settings/push.php' => 'pengaturan',
        '/settings/hijri_mappings.php' => 'pengaturan',
        '/perizinan/index.php' => 'perizinan',
        '/perizinan/rekap_aktif.php' => 'perizinan',
        '/perizinan/izin_tetap.php' => 'perizinan',
        '/perizinan/kembali.php' => 'perizinan_scan',
        '/perizinan/permohonan.php' => 'perizinan_permohonan',
        '/admin/surat_nomor.php' => 'perizinan',
        '/admin/rekap_surat_izin.php' => 'perizinan',
        '/admin/rekap_surat_sp.php' => 'perizinan',
        '/pembimbing/index.php' => 'pembimbing',
        '/pembimbing/kartu.php' => 'pembimbing',
        '/pembimbing/kartu_batch.php' => 'pembimbing',
        '/pembimbing/munawib_kartu.php' => 'munawib',
        '/pembimbing/dashboard.php' => 'pembimbing_dashboard',
        '/pembimbing/pkpps_santri.php' => 'pembimbing_pkpps',
        '/pembimbing/presensi.php' => 'presensi_scan',
        '/pembimbing/perizinan.php' => 'pembimbing_perizinan',
        '/rekap/santri_bagus.php' => 'rekap_keaktifan',
        '/pengasuh/dashboard.php' => 'rekap_keaktifan_hari',
        '/pengasuh/laporan_hari.php' => 'rekap_keaktifan_hari',
        '/pengasuh/sdm_hari.php' => 'rekap_keaktifan_hari',
        '/pengasuh/nilai_keaktifan.php' => 'rekap_keaktifan',
        '/rekap/index.php' => 'rekap',
        '/rekap/panduan.php' => 'rekap',
        '/rekap/izin_telat.php' => 'rekap_telat',
        '/rekap/pembimbing.php' => 'rekap_pembimbing',
        '/poin/input.php' => 'poin_input',
        '/poin/rekap.php' => 'poin_rekap',
        '/poin/settings.php' => 'pengaturan',
        '/settings/admin.php' => 'settings_admin',
        '/admin/cek_update.php' => 'settings_admin',
        '/settings/push.php' => 'settings_admin',
        '/yayasan/timeline.php' => 'yayasan',
        '/yayasan/operasional.php' => 'yayasan',
        '/yayasan/pengawasan.php' => 'yayasan',
        '/yayasan/ringkasan.php' => 'yayasan',
        '/yayasan/ketertiban.php' => 'yayasan',
        '/yayasan/keaktifan.php' => 'yayasan',
        '/yayasan/sdm_hari.php' => 'yayasan',
        '/yayasan/dashboard.php' => 'yayasan',
        '/yayasan/pengurus.php' => 'yayasan',
        '/yayasan/rapat.php' => 'yayasan',
        '/yayasan/notulen.php' => 'yayasan',
        '/yayasan/executive.php' => 'yayasan',
    ];
}

/** @return array<string, string> */
function user_permission_path_map_keuangan(): array
{
    $laporan = 'keuangan_laporan';
    $transaksi = 'keuangan_transaksi';

    $map = [
        '/keuangan/index.php' => $laporan,
        '/keuangan/neraca.php' => $laporan,
        '/keuangan/arus-kas.php' => $laporan,
        '/pembayaran/rekap_pos.php' => $laporan,
        '/pembayaran/laporan.php' => $laporan,
        '/pembayaran/laporan_kopsa_per_santri.php' => $laporan,
        '/pembayaran/laporan_pkpps_syahriyah.php' => $laporan,
        '/pembayaran/kartu_syahriyah_santri.php' => $laporan,
        '/pembayaran/laporan_muadalah_tambahan.php' => $laporan,
        '/keuangan/pembayaran.php' => $transaksi,
        '/pembayaran/index.php' => $transaksi,
        '/pembayaran/tagihan_syahriyah.php' => $transaksi,
        '/pembayaran/riwayat.php' => $transaksi,
        '/pembayaran/riwayat_edit.php' => $transaksi,
        '/pembayaran/riwayat_audit.php' => $transaksi,
        '/keuangan/pemasukan.php' => $transaksi,
        '/keuangan/pengeluaran.php' => $transaksi,
        '/keuangan/riwayat_pengeluaran.php' => $transaksi,
        '/keuangan/potongan_syahriyah.php' => 'keuangan_potongan',
        '/keuangan/talangan.php' => 'keuangan_talangan',
        '/keuangan/inventaris.php' => 'keuangan_inventaris',
        '/keuangan/pengaturan.php' => 'keuangan_pengaturan_modul',
        '/keuangan/panduan.php' => 'keuangan_pengaturan_modul',
        '/keuangan/cashless_scan.php' => 'keuangan_cashless_scan',
        '/keuangan/cashless_laporan.php' => 'keuangan_cashless_laporan',
        '/keuangan/cashless_pin.php' => 'keuangan_cashless_pin',
    ];

    foreach (['a', 'b', 'c', 'e', 'f', 'g', 'h', 'i', 'j', 'k'] as $tab) {
        $map['/keuangan/index.php?tab=' . $tab] = $laporan;
    }

    return $map;
}

/** @return array<string, string> */
function user_permission_path_map(): array
{
    return array_merge(user_permission_path_map_base(), user_permission_path_map_keuangan());
}

function user_permission_key_for_path(string $requestPath): ?string
{
    $map = user_permission_path_map();
    $matchedKey = null;
    $matchedLen = 0;
    foreach ($map as $path => $permissionKey) {
        if ($path === '' || !str_contains($requestPath, $path)) {
            continue;
        }
        $len = strlen($path);
        if ($len >= $matchedLen) {
            $matchedLen = $len;
            $matchedKey = $permissionKey;
        }
    }

    return $matchedKey;
}

/**
 * Perluas peta izin: legacy `keuangan` = semua submenu keuangan.
 *
 * @param array<string, int> $map
 * @return array<string, int>
 */
function user_permission_expand_allowed_map(array $map): array
{
    if (!isset($map['keuangan'])) {
        return $map;
    }
    foreach (user_permission_keuangan_keys() as $k) {
        $map[$k] = 1;
    }

    return $map;
}

/** Migrasi sekali: pecah izin `keuangan` menjadi submenu. */
function migrate_keuangan_permissions_split(PDO $pdo): void
{
    if (!function_exists('table_exists')) {
        require_once __DIR__ . '/app.php';
    }
    if (!table_exists($pdo, 'user_access_permissions') || !table_exists($pdo, 'app_settings')) {
        return;
    }
    if (app_setting($pdo, 'acl_keuangan_split_v1', '') === '1') {
        return;
    }

    $stmt = $pdo->query("SELECT DISTINCT user_id FROM user_access_permissions WHERE permission_key = 'keuangan'");
    $userIds = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    $ins = $pdo->prepare('INSERT IGNORE INTO user_access_permissions (user_id, permission_key) VALUES (:uid, :key)');
    foreach ($userIds as $uid) {
        if ($uid <= 0) {
            continue;
        }
        foreach (user_permission_keuangan_keys() as $key) {
            $ins->execute(['uid' => $uid, 'key' => $key]);
        }
        $pdo->prepare("DELETE FROM user_access_permissions WHERE user_id = :uid AND permission_key = 'keuangan'")
            ->execute(['uid' => $uid]);
    }

    save_setting($pdo, 'acl_keuangan_split_v1', '1');
    if (function_exists('app_acl_session_cache_clear')) {
        app_acl_session_cache_clear(0);
    }
}

/** Preset centang di form admin (hanya UI; disimpan sebagai permission_key biasa). */
function user_permission_preset_keys(string $presetId): array
{
    return match ($presetId) {
        'keuangan_semua' => user_permission_keuangan_keys(),
        'keuangan_operasional' => [
            'keuangan_laporan',
            'keuangan_transaksi',
            'keuangan_potongan',
            'keuangan_cashless_scan',
            'keuangan_cashless_laporan',
        ],
        'keuangan_laporan_saja' => ['keuangan_laporan'],
        default => [],
    };
}
