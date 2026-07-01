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
        'keuangan_cashless_setor',
        'keuangan_cashless_pin',
    ];
}

function user_permission_is_keuangan_key(string $key): bool
{
    return in_array($key, user_permission_keuangan_keys(), true);
}

/** @return list<string> */
function user_permission_pkpps_keys(): array
{
    return [
        'pkpps_dashboard',
        'pkpps_santri',
        'pkpps_jadwal',
        'pkpps_tugas',
        'pkpps_rekap',
        'pembimbing_pkpps',
    ];
}

function user_permission_is_pkpps_key(string $key): bool
{
    return in_array($key, user_permission_pkpps_keys(), true);
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
                'keuangan_cashless_setor' => 'Setor Cashless Koperasi',
                'keuangan_cashless_pin' => 'Rekap Saldo & PIN Cashless',
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
        'kajian' => [
            'label' => 'Kajian (Ta\'lim) — centang per submenu',
            'permissions' => [
                'jadwal' => 'Jadwal & kegiatan Ta\'lim',
                'jadwal_import' => 'Import jadwal Excel/CSV',
                'akademik_hafalan' => 'Setoran hafalan, bait, kalender, rapor',
                'akademik_setoran' => 'Input setoran scan (pembimbing/munawib)',
                'akademik_ikhtibar' => 'Tugas Ikhtibar (buat, nilai, rekap)',
                'pembimbing_dashboard' => 'Portal pembimbing — beranda & keaktivan kajian',
                'pembimbing_jadwal' => 'Jadwal kegiatan pembimbing',
            ],
        ],
        'jamaah' => [
            'label' => 'Jama\'ah — centang per submenu',
            'permissions' => [
                'jadwal_jamaah' => 'Kegiatan & presensi Jama\'ah',
            ],
        ],
        'pkpps_modul' => [
            'label' => 'PKPPS — centang per submenu',
            'permissions' => [
                'pkpps_dashboard' => 'Dashboard PKPPS (ringkasan)',
                'pkpps_santri' => 'Santri PKPPS — data & import',
                'pkpps_jadwal' => 'Jadwal PKPPS — kelola & import',
                'pkpps_tugas' => 'Tugas & soal PKPPS (admin)',
                'pkpps_rekap' => 'Rekap keaktivan PKPPS',
                'pembimbing_pkpps' => 'Portal pembimbing — santri & tugas PKPPS',
            ],
        ],
        'operasional' => [
            'label' => 'Operasional Harian (submenu)',
            'permissions' => [
                'presensi_scan' => 'Scan Presensi (santri & pembimbing)',
                'perizinan' => 'Perizinan — review & setujui',
                'perizinan_permohonan' => 'Perizinan — ajukan permohonan',
                'munawib' => 'Munawib — data pengganti pembimbing',
            ],
        ],
        'pembimbing' => [
            'label' => 'Pembimbing',
            'permissions' => [
                'pembimbing' => 'Data Pembimbing',
                'pembimbing_perizinan' => 'Izin Pembimbing',
                'rekap_pembimbing' => 'Payroll / Gaji Pembimbing',
            ],
        ],
        'rekap' => [
            'label' => 'Rekap & Poin (submenu)',
            'permissions' => [
                'rekap' => 'Rekap Presensi (pusat laporan)',
                'rekap_keaktifan_hari' => 'Keaktifan Santri Hari Ini',
                'rekap_keaktifan' => 'Rekap Keaktifan Santri (tahunan)',
                'rekap_telat' => 'Rekap Keterlambatan',
                'rekap_perizinan' => 'Rekap Perizinan Bulanan',
                'rekap_munawib' => 'Laporan Munawib',
                'poin_input' => 'Input Poin',
                'poin_rekap' => 'Rekap Poin',
            ],
        ],
        'pengaturan' => [
            'label' => 'Pengaturan Pondok',
            'permissions' => [
                'pengaturan' => 'Pengaturan umum (pondok, master data, kalender, dll.)',
                'poin_settings' => 'Peraturan Poin (legacy)',
                'settings_umum' => 'Settings Umum (legacy)',
                'settings_admin' => 'Kelola Akses User',
                'settings_portal_pembimbing' => 'Banner Portal Pembimbing (Kajian, PKPPS, Jama\'ah)',
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
        '/santri/edit.php' => 'santri_create',
        '/santri/kartu.php' => 'santri_index',
        '/santri/kartu_id.php' => 'santri_index',
        '/santri/kartu_sementara.php' => 'santri_index',
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
        '/presensi/rekap_tanpa_scan.php' => 'rekap_keaktifan',
        '/jadwal/index.php' => 'jadwal',
        '/jadwal/tambah.php' => 'jadwal',
        '/jadwal/kegiatan.php' => 'jadwal',
        '/jadwal/tambah_kegiatan.php' => 'jadwal',
        '/jadwal/import.php' => 'jadwal_import',
        '/pkpps/index.php' => 'pkpps_dashboard',
        '/pkpps/hub.php' => 'pkpps_dashboard',
        '/pkpps/santri.php' => 'pkpps_santri',
        '/pkpps/import_santri.php' => 'pkpps_santri',
        '/pkpps/jadwal.php' => 'pkpps_jadwal',
        '/pkpps/import.php' => 'pkpps_jadwal',
        '/pkpps/tugas/index.php' => 'pkpps_tugas',
        '/pkpps/tugas/buat.php' => 'pkpps_tugas',
        '/pkpps/tugas/nilai.php' => 'pkpps_tugas',
        '/pkpps/tugas/rekap.php' => 'pkpps_tugas',
        '/pkpps/rapor.php' => 'pkpps_tugas',
        '/pkpps/pengaturan_rapor.php' => 'pkpps_tugas',
        '/rekap/pkpps_keaktivan.php' => 'pkpps_rekap',
        '/rekap/pkpps_keaktifan_hari.php' => 'pkpps_rekap',
        '/jadwal/kegiatan.php' => 'jadwal_jamaah',
        '/jadwal/tambah_kegiatan.php' => 'jadwal_jamaah',
        '/settings/tingkatan.php' => 'pengaturan',
        '/settings/tingkatan.php#pkpps' => 'pengaturan',
        '/pembimbing/munawib.php' => 'munawib',
        '/rekap/hub.php' => 'rekap',
        '/rekap/keaktivan_sdm.php' => 'rekap',
        '/rekap/keaktifan_hari.php' => 'rekap_keaktifan_hari',
        '/rekap/munawib.php' => 'rekap_munawib',
        '/rekap/kegiatan_khusus.php' => 'rekap',
        '/rekap/perizinan.php' => 'rekap_perizinan',
        '/akademik/hafalan.php' => 'akademik_hafalan',
        '/akademik/setoran.php' => 'akademik_hafalan',
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
        '/pembimbing/nilai_manual.php' => 'akademik_ikhtibar',
        '/akademik/ikhtibar_rekap.php' => 'akademik_ikhtibar',
        '/akademik/ikhtibar.php' => 'akademik_ikhtibar',
        '/settings/ikhtibar_kriteria.php' => 'akademik_ikhtibar',
        '/settings/pusat.php' => 'pengaturan',
        '/settings/wa_otomatis.php' => 'pengaturan',
        '/settings/wa_gateway.php' => 'pengaturan',
        '/settings/wa_pesan.php' => 'pengaturan',
        '/settings/wa_laporan_kelas_kosong.php' => 'pengaturan',
        '/settings/pesantren.php' => 'pengaturan',
        '/settings/surat_cetak.php' => 'pengaturan',
        '/settings/peraturan.php' => 'pengaturan',
        '/settings/perizinan.php' => 'pengaturan',
        '/settings/alpa_notif.php' => 'pengaturan',
        '/settings/kelas_ruangan.php' => 'pengaturan',
        '/settings/kelas_keuangan.php' => 'pengaturan',
        '/settings/kelas_syahriyah.php' => 'pengaturan',
        '/settings/tarif_payroll.php' => 'pengaturan',
        '/settings/opsional_santri.php' => 'pengaturan',
        '/settings/kamar_ranjang.php' => 'pengaturan',
        '/settings/index.php' => 'pengaturan',
        '/settings/kalender.php' => 'pengaturan',
        '/settings/kalender_ta.php' => 'pengaturan',
        '/settings/hijri_mappings.php' => 'pengaturan',
        '/perizinan/index.php' => 'perizinan',
        '/perizinan/hub.php' => 'perizinan',
        '/perizinan/rekap_aktif.php' => 'perizinan',
        '/perizinan/izin_tetap.php' => 'perizinan',
        '/perizinan/izin_tetap_kegiatan.php' => 'perizinan',
        '/perizinan/surat_izin_tetap.php' => 'perizinan',
        '/perizinan/izin_rombongan.php' => 'perizinan',
        '/perizinan/surat_rombongan.php' => 'perizinan',
        '/perizinan/permohonan.php' => 'perizinan_permohonan',
        '/admin/surat_nomor.php' => 'perizinan',
        '/admin/rekap_surat_izin.php' => 'perizinan',
        '/admin/rekap_surat_sp.php' => 'perizinan',
        '/pembimbing/index.php' => 'pembimbing',
        '/pembimbing/kartu.php' => 'pembimbing',
        '/pembimbing/kartu_batch.php' => 'pembimbing',
        '/pembimbing/munawib_kartu.php' => 'munawib',
        '/pembimbing/dashboard.php' => 'pembimbing_dashboard',
        '/pembimbing/tugas_yayasan.php' => 'pembimbing_dashboard',
        '/pembimbing/pkpps_santri.php' => 'pembimbing_pkpps',
        '/pkpps/tugas/index.php' => 'pembimbing_pkpps',
        '/pkpps/tugas/buat.php' => 'pembimbing_pkpps',
        '/pkpps/tugas/nilai.php' => 'pembimbing_pkpps',
        '/pkpps/tugas/rekap.php' => 'pembimbing_pkpps',
        '/pembimbing/presensi.php' => 'presensi_scan',
        '/pembimbing/perizinan.php' => 'pembimbing_perizinan',
        '/rekap/santri_bagus.php' => 'rekap_keaktifan',
        '/yayasan/keaktifan_rekap.php' => 'rekap_keaktifan',
        '/pengasuh/dashboard.php' => 'rekap_keaktifan_hari',
        '/pengasuh/laporan_hari.php' => 'rekap_keaktifan_hari',
        '/pengasuh/perizinan.php' => 'rekap_keaktifan_hari',
        '/pengasuh/sdm_hari.php' => 'rekap_keaktifan_hari',
        '/pengasuh/nilai_keaktifan.php' => 'rekap_keaktifan',
        '/rekap/index.php' => 'rekap',
        '/rekap/presensi.php' => 'rekap',
        '/rekap/panduan.php' => 'rekap',
        '/rekap/alpa_santri.php' => 'rekap',
        '/rekap/izin_telat.php' => 'rekap_telat',
        '/rekap/pembimbing.php' => 'rekap_pembimbing',
        '/keuangan/gaji_pembimbing.php' => 'rekap_pembimbing',
        '/poin/input.php' => 'poin_input',
        '/poin/rekap.php' => 'poin_rekap',
        '/poin/settings.php' => 'pengaturan',
        '/settings/admin.php' => 'settings_admin',
        '/settings/presensi_data.php' => 'settings_admin',
        '/settings/push.php' => 'settings_admin',
        '/settings/portal_pembimbing.php' => 'settings_portal_pembimbing',
        '/admin/cek_update.php' => 'settings_admin',
        '/settings/profil.php' => 'dashboard',
        '/settings/akses_saya.php' => 'dashboard',
        '/catatan/index.php' => 'dashboard',
        '/catatan/edit.php' => 'dashboard',
        '/yayasan/timeline.php' => 'yayasan',
        '/yayasan/operasional.php' => 'yayasan',
        '/yayasan/pengawasan.php' => 'yayasan',
        '/yayasan/ringkasan.php' => 'yayasan',
        '/yayasan/ketertiban.php' => 'yayasan',
        '/yayasan/kesehatan.php' => 'yayasan',
        '/yayasan/keaktifan.php' => 'yayasan',
        '/yayasan/keaktifan_ranking.php' => 'yayasan',
        '/yayasan/keaktifan_kelas.php' => 'rekap_keaktifan_hari',
        '/yayasan/sdm_hari.php' => 'yayasan',
        '/yayasan/dashboard.php' => 'yayasan',
        '/yayasan/pengurus.php' => 'yayasan',
        '/yayasan/sdm.php' => 'yayasan',
        '/yayasan/scan_musyawarah.php' => 'yayasan',
        '/yayasan/kartu_sdm.php' => 'yayasan',
        '/yayasan/musyawarah_presensi.php' => 'yayasan',
        '/yayasan/musyawarah_hasil.php' => 'yayasan',
        '/yayasan/rapat.php' => 'yayasan',
        '/yayasan/notulen.php' => 'yayasan',
        '/yayasan/notulen_cetak.php' => 'yayasan',
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
        '/keuangan/neraca-perbaikan.php' => $laporan,
        '/keuangan/arus-kas.php' => $laporan,
        '/keuangan/rekap-kas-bulan.php' => $laporan,
        '/pembayaran/rekap_pos.php' => $laporan,
        '/pembayaran/laporan.php' => $laporan,
        '/pembayaran/laporan_kopsa_per_santri.php' => $laporan,
        '/pembayaran/laporan_pkpps_syahriyah.php' => $laporan,
        '/pembayaran/kartu_syahriyah_santri.php' => $laporan,
        '/pembayaran/laporan_muadalah_tambahan.php' => $laporan,
        '/keuangan/pembayaran.php' => $transaksi,
        '/keuangan/tagihan_wali.php' => $transaksi,
        '/keuangan/transaksi.php' => $transaksi,
        '/keuangan/kas.php' => $transaksi,
        '/pembayaran/index.php' => $transaksi,
        '/pembayaran/tagihan_syahriyah.php' => $transaksi,
        '/pembayaran/riwayat.php' => $transaksi,
        '/pembayaran/riwayat_edit.php' => $transaksi,
        '/pembayaran/riwayat_audit.php' => $transaksi,
        '/keuangan/pemasukan.php' => $transaksi,
        '/keuangan/pengeluaran.php' => $transaksi,
        '/keuangan/riwayat_pemasukan.php' => $transaksi,
        '/keuangan/riwayat_pengeluaran.php' => $transaksi,
        '/keuangan/riwayat_pembayaran.php' => $transaksi,
        '/keuangan/potongan_syahriyah.php' => 'keuangan_potongan',
        '/keuangan/talangan.php' => 'keuangan_talangan',
        '/keuangan/inventaris.php' => 'keuangan_inventaris',
        '/keuangan/pengaturan.php' => 'keuangan_pengaturan_modul',
        '/keuangan/panduan.php' => 'keuangan_pengaturan_modul',
        '/keuangan/cashless_scan.php' => 'keuangan_cashless_scan',
        '/keuangan/cashless.php' => 'keuangan_cashless_scan',
        '/keuangan/cashless_laporan.php' => 'keuangan_cashless_laporan',
        '/keuangan/cashless_setor.php' => 'keuangan_cashless_setor',
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
 * Permission key alternatif yang juga boleh membuka path (selain key utama di path map).
 *
 * @return list<string>
 */
function user_permission_alt_keys_for_path(string $requestPath): array
{
    if (!function_exists('app_normalize_request_path')) {
        require_once __DIR__ . '/app_path.php';
    }
    $path = app_normalize_request_path($requestPath);

    return match ($path) {
        '/presensi/rekap_tanpa_scan.php' => [
            'presensi_scan',
            'rekap',
            'rekap_keaktifan',
            'rekap_keaktifan_hari',
            'pkpps_dashboard',
            'jadwal',
        ],
        '/yayasan/keaktifan_kelas.php' => [
            'yayasan',
            'rekap_keaktifan_hari',
            'rekap',
        ],
        '/yayasan/operasional.php' => [
            'rekap',
            'rekap_keaktifan',
            'rekap_keaktifan_hari',
        ],
        '/pengasuh/perizinan.php' => [
            'perizinan',
            'perizinan_permohonan',
            'rekap_keaktifan',
            'rekap',
        ],
        '/pengasuh/dashboard.php' => [
            'rekap_keaktifan',
            'rekap',
        ],
        '/pengasuh/laporan_hari.php' => [
            'rekap_keaktifan',
            'rekap',
        ],
        default => [],
    };
}

/**
 * @param array<string, int> $allowedMap
 */
function user_permission_allowed_for_path(string $requestPath, string $primaryKey, array $allowedMap): bool
{
    if ($primaryKey !== '' && isset($allowedMap[$primaryKey])) {
        return true;
    }
    foreach (user_permission_alt_keys_for_path($requestPath) as $altKey) {
        if ($altKey !== $primaryKey && isset($allowedMap[$altKey])) {
            return true;
        }
    }

    return false;
}

/** @return list<string> */
function user_permission_rekap_bundle_keys(): array
{
    return [
        'rekap',
        'rekap_keaktifan_hari',
        'rekap_keaktifan',
        'rekap_telat',
        'rekap_perizinan',
        'rekap_munawib',
        'rekap_pembimbing',
    ];
}

/**
 * Perluas peta izin: legacy / grup bundle (keuangan, pusat rekap, dll.).
 *
 * @param array<string, int> $map
 * @return array<string, int>
 */
function user_permission_expand_allowed_map(array $map): array
{
    if (isset($map['keuangan'])) {
        foreach (user_permission_keuangan_keys() as $k) {
            $map[$k] = 1;
        }
    }
    if (isset($map['rekap_hub'])) {
        $map['rekap'] = 1;
        unset($map['rekap_hub']);
    }
    if (isset($map['rekap'])) {
        foreach (user_permission_rekap_bundle_keys() as $k) {
            $map[$k] = 1;
        }
    }
    if (isset($map['perizinan'])) {
        foreach (['perizinan_permohonan'] as $k) {
            $map[$k] = 1;
        }
    }
    if (isset($map['jadwal'])) {
        $map['jadwal_import'] = 1;
        $map['jadwal_jamaah'] = 1;
        foreach (user_permission_pkpps_keys() as $k) {
            $map[$k] = 1;
        }
    }
    if (isset($map['jadwal_jamaah'])) {
        $map['jadwal'] = 1;
    }
    if (isset($map['pkpps_dashboard'])) {
        foreach (user_permission_pkpps_keys() as $k) {
            $map[$k] = 1;
        }
    }
    foreach (user_permission_pkpps_keys() as $pkKey) {
        if (isset($map[$pkKey])) {
            $map['pkpps_dashboard'] = 1;
            break;
        }
    }
    if (isset($map['akademik_hafalan'])) {
        $map['akademik_setoran'] = 1;
    }
    if (isset($map['settings_admin'])) {
        $map['settings_portal_pembimbing'] = 1;
    }

    return $map;
}

/**
 * Submenu (path) yang dilindungi tiap permission_key — untuk form admin.
 *
 * @return list<array{path:string,label:string}>
 */
function user_permission_submenus_for_key(string $permissionKey): array
{
    static $index = null;
    if (!is_array($index)) {
        $index = [];
        $pack = require __DIR__ . '/../includes/menu_data.php';
        $menuItems = is_array($pack['menuItems'] ?? null) ? $pack['menuItems'] : [];
        foreach (user_permission_path_map() as $path => $key) {
            if ($key === '') {
                continue;
            }
            $label = trim((string) ($menuItems[$path] ?? ''));
            if ($label === '') {
                $label = ltrim(str_replace(['.php', '_'], ['', ' '], basename(parse_url($path, PHP_URL_PATH) ?: $path)), '/');
            }
            $index[$key][] = ['path' => $path, 'label' => $label];
        }
        foreach ($index as $key => $rows) {
            usort($rows, static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));
            $index[$key] = $rows;
        }
    }

    return $index[$permissionKey] ?? [];
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
            'keuangan_cashless_setor',
        ],
        'keuangan_laporan_saja' => ['keuangan_laporan'],
        'pkpps_semua' => user_permission_pkpps_keys(),
        'kajian_semua' => [
            'jadwal',
            'jadwal_import',
            'akademik_hafalan',
            'akademik_setoran',
            'akademik_ikhtibar',
            'pembimbing_dashboard',
            'pembimbing_jadwal',
        ],
        'jamaah_semua' => ['jadwal_jamaah'],
        default => [],
    };
}

/** Migrasi: pecah izin PKPPS dari `jadwal` / `pkpps_dashboard` granular. */
function migrate_pkpps_permissions_split(PDO $pdo): void
{
    if (!function_exists('table_exists')) {
        require_once __DIR__ . '/app.php';
    }
    if (!table_exists($pdo, 'user_access_permissions') || !table_exists($pdo, 'app_settings')) {
        return;
    }
    if (app_setting($pdo, 'acl_pkpps_split_v1', '') === '1') {
        return;
    }

    $legacyKeys = ['jadwal', 'pkpps_dashboard'];
    $st = $pdo->prepare('SELECT DISTINCT user_id FROM user_access_permissions WHERE permission_key = :k');
    $userIds = [];
    foreach ($legacyKeys as $legacyKey) {
        $st->execute(['k' => $legacyKey]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $uid) {
            $userIds[(int) $uid] = (int) $uid;
        }
    }

    $ins = $pdo->prepare('INSERT IGNORE INTO user_access_permissions (user_id, permission_key) VALUES (:uid, :key)');
    foreach ($userIds as $uid) {
        if ($uid <= 0) {
            continue;
        }
        foreach (user_permission_pkpps_keys() as $key) {
            $ins->execute(['uid' => $uid, 'key' => $key]);
        }
    }

    save_setting($pdo, 'acl_pkpps_split_v1', '1');
    if (function_exists('app_acl_session_cache_clear')) {
        app_acl_session_cache_clear(0);
    }
}

function user_acl_configured_setting_key(int $userId): string
{
    return 'acl_user_configured_v1_' . max(0, $userId);
}

/** Super admin sudah pernah menyimpan hak akses user ini (termasuk kosong). */
function user_acl_is_explicitly_configured(PDO $pdo, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    if (!function_exists('app_setting')) {
        require_once __DIR__ . '/app.php';
    }

    return app_setting($pdo, user_acl_configured_setting_key($userId), '') === '1';
}

function user_acl_mark_configured(PDO $pdo, int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    if (!function_exists('save_setting')) {
        require_once __DIR__ . '/app.php';
    }
    save_setting($pdo, user_acl_configured_setting_key($userId), '1');
    user_acl_bump_revision($pdo, $userId);
    if (function_exists('app_acl_session_cache_clear')) {
        app_acl_session_cache_clear($userId);
    }
}

/** Revisi ACL per user — invalidasi cache sesi saat super admin mengubah hak akses. */
function user_acl_revision(PDO $pdo, int $userId): string
{
    if ($userId <= 0) {
        return '0';
    }
    if (!function_exists('app_setting')) {
        require_once __DIR__ . '/app.php';
    }

    return (string) app_setting($pdo, 'acl_user_rev_' . $userId, '0');
}

function user_acl_bump_revision(PDO $pdo, int $userId): void
{
    if ($userId <= 0) {
        return;
    }
    if (!function_exists('save_setting')) {
        require_once __DIR__ . '/app.php';
    }
    save_setting($pdo, 'acl_user_rev_' . $userId, (string) time());
}

/** Tandai user yang sudah punya baris ACL (sebelum fitur configured) agar tidak kena izin bawaan. */
function user_acl_ensure_legacy_configured(PDO $pdo, int $userId): void
{
    if ($userId <= 0 || user_acl_is_explicitly_configured($pdo, $userId)) {
        return;
    }
    if (!function_exists('table_exists')) {
        require_once __DIR__ . '/app.php';
    }
    if (!table_exists($pdo, 'user_access_permissions')) {
        return;
    }
    $st = $pdo->prepare('SELECT COUNT(*) FROM user_access_permissions WHERE user_id = :uid');
    $st->execute(['uid' => $userId]);
    if ((int) $st->fetchColumn() > 0) {
        user_acl_mark_configured($pdo, $userId);
    }
}

/** Izin bawaan saat akun belum punya baris ACL (mis. pengurus lama sebelum migrasi). */
function user_permission_default_keys_for_role(string $role): array
{
    $role = strtolower(trim($role));
    if ($role === 'petugas_absensi') {
        return [
            'dashboard',
            'presensi_scan',
            'perizinan_permohonan',
        ];
    }
    if ($role === 'pengurus') {
        $keys = array_keys(user_permission_flat_options());

        return array_values(array_filter(
            $keys,
            static fn(string $k): bool => !in_array($k, ['settings_admin'], true)
        ));
    }
    if ($role === 'admin') {
        return user_permission_default_keys_for_role('pengurus');
    }

    return ['dashboard'];
}

/** Sisipkan izin bawaan ke DB bila user belum punya satupun permission_key. */
function user_permission_ensure_role_defaults(PDO $pdo, int $userId, string $role): void
{
    if ($userId <= 0) {
        return;
    }
    if (!function_exists('table_exists')) {
        require_once __DIR__ . '/app.php';
    }
    if (!table_exists($pdo, 'user_access_permissions')) {
        return;
    }

    user_acl_ensure_legacy_configured($pdo, $userId);
    if (user_acl_is_explicitly_configured($pdo, $userId)) {
        return;
    }

    $role = strtolower(trim($role));
    if ($role === 'pembimbing') {
        if (!function_exists('login_pembimbing_ensure_acl')) {
            require_once __DIR__ . '/login_pembimbing.php';
        }
        login_pembimbing_ensure_acl($pdo, $userId);

        return;
    }
    if (!in_array($role, ['pengurus', 'petugas_absensi', 'admin'], true)) {
        return;
    }

    $st = $pdo->prepare('SELECT COUNT(*) FROM user_access_permissions WHERE user_id = :uid');
    $st->execute(['uid' => $userId]);
    if ((int) $st->fetchColumn() > 0) {
        return;
    }

    $keys = user_permission_default_keys_for_role($role);
    if ($keys === []) {
        return;
    }

    $ins = $pdo->prepare('INSERT IGNORE INTO user_access_permissions (user_id, permission_key) VALUES (:uid, :pk)');
    foreach ($keys as $key) {
        $ins->execute(['uid' => $userId, 'pk' => $key]);
    }
    if (function_exists('app_acl_session_cache_clear')) {
        app_acl_session_cache_clear($userId);
    }
}

/**
 * Ringkasan hak akses untuk halaman Profil / Akses Saya (read-only).
 *
 * @return array{
 *   user_id:int,
 *   is_super_admin:bool,
 *   role:string,
 *   full_access:bool,
 *   full_access_note:string,
 *   explicitly_configured:bool,
 *   allowed_keys:list<string>,
 *   groups:list<array{group_id:string,label:string,items:list<array{key:string,label:string}>}>,
 *   menu_preview:list<array{path:string,label:string}>
 * }
 */
function user_permission_access_summary(PDO $pdo): array
{
    if (!function_exists('is_super_admin')) {
        require_once __DIR__ . '/../includes/auth.php';
    }
    if (!function_exists('app_menu_pack')) {
        require_once __DIR__ . '/app.php';
    }

    $userId = (int) ($_SESSION['user']['id'] ?? 0);
    $isSuper = is_super_admin();
    $role = strtolower(trim((string) ($_SESSION['user']['role'] ?? '')));
    user_acl_ensure_legacy_configured($pdo, $userId);
    $explicit = user_acl_is_explicitly_configured($pdo, $userId);
    $flat = user_permission_flat_options();

    $fullAccess = false;
    $fullAccessNote = '';
    if ($isSuper) {
        $fullAccess = true;
        $fullAccessNote = 'Anda login sebagai Super Admin — akses penuh ke seluruh fitur aplikasi.';
    } else {
        $allowedMap = get_allowed_permission_key_map($pdo);
        if ($allowedMap === null) {
            $fullAccess = true;
            $fullAccessNote = $role === 'admin'
                ? 'Peran admin — akses penuh (belum dibatasi oleh super admin).'
                : 'Akun ini belum dibatasi hak akses per fitur.';
        }
    }

    $allowedKeys = $fullAccess
        ? array_keys($flat)
        : array_keys(get_allowed_permission_key_map($pdo) ?? []);
    $allowedKeys = array_values(array_unique(array_filter(
        $allowedKeys,
        static fn(string $k): bool => $k !== '' && isset($flat[$k])
    )));
    sort($allowedKeys);

    $allowedSet = array_fill_keys($allowedKeys, true);
    $groups = [];
    foreach (user_permission_groups() as $groupId => $group) {
        $items = [];
        foreach ($group['permissions'] as $key => $label) {
            if (!isset($allowedSet[$key])) {
                continue;
            }
            $items[] = ['key' => $key, 'label' => (string) $label];
        }
        if ($items !== []) {
            $groups[] = [
                'group_id' => (string) $groupId,
                'label' => (string) ($group['label'] ?? $groupId),
                'items' => $items,
            ];
        }
    }

    $menuPack = app_menu_pack($pdo);
    $menuPreview = [];
    foreach ($menuPack['menuItems'] as $path => $label) {
        if (!is_string($path) || $path === '' || !is_string($label)) {
            continue;
        }
        if (in_array($path, ['/dashboard.php', '/settings/profil.php', '/settings/akses_saya.php', '/catatan/index.php', '/catatan/edit.php'], true)) {
            continue;
        }
        $menuPreview[] = ['path' => $path, 'label' => trim($label)];
    }
    usort($menuPreview, static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));

    if (!$fullAccess && $explicit) {
        $fullAccessNote = 'Hak akses diatur oleh super admin. Hanya fitur di bawah yang boleh Anda gunakan.';
    } elseif (!$fullAccess && !$explicit && $role === 'petugas_absensi') {
        $fullAccessNote = 'Hak akses mengikuti peran petugas absensi (bawaan sistem).';
    } elseif (!$fullAccess && !$explicit && in_array($role, ['pengurus', 'petugas_absensi'], true)) {
        $fullAccessNote = 'Hak akses mengikuti peran ' . $role . ' (bawaan sistem, belum diatur khusus oleh super admin).';
    }

    return [
        'user_id' => $userId,
        'is_super_admin' => $isSuper,
        'role' => $role,
        'full_access' => $fullAccess,
        'full_access_note' => $fullAccessNote,
        'explicitly_configured' => $explicit,
        'allowed_keys' => $allowedKeys,
        'groups' => $groups,
        'menu_preview' => $menuPreview,
    ];
}
