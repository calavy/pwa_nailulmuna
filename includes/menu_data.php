<?php

/**
 * Definisi menu aplikasi (label, struktur, pemetaan izin halaman).
 * Disertakan oleh header dan halaman hub menu.
 */
require_once __DIR__ . '/../helpers/user_permissions.php';

$__currentRoleForMenu = strtolower((string) ($_SESSION['user']['role'] ?? ''));
$__isSuperAdminForMenu = (int) ($_SESSION['user']['is_super_admin'] ?? 0) === 1;

// Struktur menu khusus untuk role pembimbing — sederhana, hanya berisi
// modul yang relevan dengan tugas pembimbing (dashboard, tugas/penilaian,
// izin pembimbing). Tidak menampilkan keuangan, gaji, yayasan, dll.
if ($__currentRoleForMenu === 'pembimbing' && !$__isSuperAdminForMenu) {
    return [
        'menuItems' => [
            '/pembimbing/dashboard.php' => 'Dashboard Pembimbing',
            '/presensi/scan.php' => 'Scan Satu Pintu',
            '/pembimbing/tugas/index.php' => 'Daftar Tugas Ikhtibar',
            '/pembimbing/tugas/buat.php' => 'Buat Tugas / Soal',
            '/pembimbing/tugas/nilai.php' => 'Penilaian Tugas',
            '/pembimbing/tugas/rekap.php' => 'Rekap Nilai Ikhtibar',
            '/pembimbing/nilai_manual.php' => 'Nilai Manual',
            '/akademik/ikhtibar_rekap.php' => 'Rekap Tugas Ikhtibar',
            '/pembimbing/perizinan.php' => 'Izin Pembimbing',
            '/settings/profil.php' => 'Profil & Password',
        ],
        'menuStructure' => [
            ['type' => 'item', 'path' => '/pembimbing/dashboard.php', 'icon' => 'fa-solid fa-house'],
            ['type' => 'item', 'path' => '/presensi/scan.php', 'icon' => 'fa-solid fa-qrcode'],
            ['type' => 'group', 'id' => 'menu-grp-pb-tugas', 'label' => 'Tugas & Penilaian', 'icon' => 'fa-solid fa-list-check', 'sections' => [
                ['title' => 'Soal & Tugas', 'paths' => [
                    '/pembimbing/tugas/buat.php',
                    '/pembimbing/tugas/index.php',
                ]],
                ['title' => 'Penilaian & Rekap', 'paths' => [
                    '/pembimbing/tugas/nilai.php',
                    '/pembimbing/nilai_manual.php',
                    '/pembimbing/tugas/rekap.php',
                    '/akademik/ikhtibar_rekap.php',
                ]],
            ]],
            ['type' => 'group', 'id' => 'menu-grp-pb-izin', 'label' => 'Perizinan', 'icon' => 'fa-solid fa-person-walking-arrow-right', 'sections' => [
                ['title' => 'Izin Pembimbing', 'paths' => [
                    '/pembimbing/perizinan.php',
                ]],
            ]],
            ['type' => 'group', 'id' => 'menu-grp-pb-akun', 'label' => 'Akun Saya', 'icon' => 'fa-solid fa-user-gear', 'sections' => [
                ['title' => 'Profil & Password', 'paths' => [
                    '/settings/profil.php',
                ]],
            ]],
        ],
        'pengaturanNav' => [],
        'permissionPathMap' => user_permission_path_map(),
    ];
}

return [
    'menuItems' => [
        '/dashboard.php' => 'Dashboard',
        '/santri/semua_jati.php' => 'Data induk santri',
        '/santri/riwayat.php' => 'Riwayat santri',
        '/santri/tingkatan_ta.php' => 'Tingkatan per TA',
        '/santri/hidmah.php' => 'Input Hidmah',
        '/santri/index.php' => 'Data Santri',
        '/santri/create.php' => 'Tambah santri',
        '/santri/keluar.php' => 'Administrasi keluar',
        '/data/wali.php' => 'Wali Santri',
        '/pembimbing/index.php' => 'Data Pembimbing',
        '/pembimbing/dashboard.php' => 'Dashboard Pembimbing',
        '/pembimbing/perizinan.php' => 'Izin pembimbing',
        '/presensi/scan.php' => 'Scan Satu Pintu',
        '/presensi/kegiatan_khusus.php' => 'Kegiatan Khusus (Sekali Pakai)',
        '/jadwal/index.php' => 'Jadwal',
        '/jadwal/import.php' => 'Import Jadwal',
        '/rekap/hub.php' => 'Pusat Rekap',
        '/rekap/keaktivan_sdm.php' => 'Keaktivan SDM (Pembimbing & Munawib)',
        '/rekap/keaktifan_hari.php' => 'Keaktifan Hari Ini',
        '/rekap/munawib.php' => 'Laporan Munawib',
        '/rekap/kegiatan_khusus.php' => 'Rekap Kegiatan Khusus',
        '/pembimbing/munawib.php' => 'Data Munawib',
        '/akademik/hafalan.php' => 'Setoran Hafalan',
        '/akademik/bait_kitab.php' => 'Pengaturan Bait',
        '/akademik/kalender.php' => 'Kalender Akademik',
        '/settings/kalender.php' => 'Pengaturan Kalender',
        '/settings/hijri_mappings.php' => 'Kalender Hijriyah & Masehi',
        '/akademik/rapor.php' => 'Rapor Akademik',
        '/pembimbing/tugas/index.php' => 'Tugas Ikhtibar (Pembimbing)',
        '/pembimbing/tugas/buat.php' => 'Buat Tugas Ikhtibar',
        '/pembimbing/tugas/nilai.php' => 'Penilaian Tugas Ikhtibar',
        '/pembimbing/tugas/rekap.php' => 'Rekap Nilai Ikhtibar',
        '/pembimbing/nilai_manual.php' => 'Nilai Manual Pembimbing',
        '/akademik/ikhtibar_rekap.php' => 'Rekap Tugas Ikhtibar',
        '/santri/mukimin.php' => 'Data Mukimin',
        '/settings/akses_mukimin.php' => 'Akses Portal Mukimin',
        '/settings/pesantren.php' => 'Pesantren',
        '/settings/wa_gateway.php' => 'WA Gateway',
        '/settings/wa_otomatis.php' => 'Pusat WA Otomatis',
        '/settings/wa_laporan_kelas_kosong.php' => 'Laporan WA Kelas Kosong',
        '/settings/peraturan.php' => 'Peraturan Poin',
        '/settings/alpa_notif.php' => 'Notifikasi Alpa Bertahap',
        '/settings/tingkatan.php' => 'Master Tingkatan',
        '/settings/kamar_ranjang.php' => 'Kamar & Ranjang',
        '/settings/kelas_ruangan.php' => 'Ruangan Kelas',
        '/perizinan/permohonan.php' => 'Pengajuan Izin',
        '/perizinan/index.php' => 'Persetujuan Izin',
        '/perizinan/izin_tetap.php' => 'Izin Tetap Hidmah',
        '/admin/surat_nomor.php' => 'Nomor Surat',
        '/admin/cek_update.php' => 'Cek Update Sistem',
        '/admin/rekap_surat_izin.php' => 'Rekap Surat Izin',
        '/admin/rekap_surat_sp.php' => 'Rekap Surat SP',
        '/rekap/santri_bagus.php' => 'Rekap Keaktifan Santri',
        '/pengasuh/nilai_keaktifan.php' => 'Nilai Keaktifan Santri',
        '/rekap/index.php' => 'Rekap Presensi',
        '/poin/input.php' => 'Input Poin',
        '/poin/rekap.php' => 'Rekap Poin',
        '/poin/settings.php' => 'Peraturan poin',
        '/keuangan/index.php' => 'Dashboard Keuangan',
        '/keuangan/neraca.php' => 'Neraca',
        '/keuangan/arus-kas.php' => 'Arus Kas',
        '/keuangan/pembayaran.php' => 'Input Pembayaran',
        '/keuangan/pemasukan.php' => 'Pemasukan Lain',
        '/keuangan/pengeluaran.php' => 'Input Pengeluaran',
        '/keuangan/talangan.php' => 'Dana Talangan',
        '/keuangan/potongan_syahriyah.php' => 'Potongan Syahriyah',
        '/keuangan/inventaris.php' => 'Inventaris Aset',
        '/keuangan/pengaturan.php' => 'Pengaturan Keuangan',
        '/keuangan/cashless_scan.php' => 'Top Up Cashless',
        '/keuangan/cashless_laporan.php' => 'Laporan Cashless Koperasi',
        '/keuangan/cashless_pin.php' => 'Cashless & Uang Saku',
        '/pembayaran/tagihan_syahriyah.php' => 'Tagihan Bulanan',
        '/pembayaran/riwayat.php' => 'Riwayat Pembayaran',
        '/pembayaran/laporan.php' => 'Laporan Syahriyah',
        '/pembayaran/rekap_pos.php' => 'Rekap per POS',
        '/rekap/pembimbing.php' => 'Payroll Pembimbing',
        '/settings/tarif_payroll.php' => 'Master Tarif Payroll',
        '/settings/kelas_keuangan.php' => 'Kelas Keuangan',
        '/settings/opsional_santri.php' => 'Opsional Santri (Makan & Saku)',
        '/rekap/izin_telat.php' => 'Rekap Telat',
        '/settings/push.php' => 'Push FCM',
        '/settings/admin.php' => 'Kelola Akses User',
        '/yayasan/pengurus.php' => 'Pengurus',
        '/yayasan/rapat.php' => 'Rapat',
        '/yayasan/notulen.php' => 'Notulen',
        '/yayasan/executive.php' => 'Executive Summary',
        '/settings/profil.php' => 'Profil & Password',
    ],
    'menuStructure' => [
        ['type' => 'item', 'path' => '/dashboard.php', 'icon' => 'fa-solid fa-house'],
        ['type' => 'group', 'id' => 'menu-grp-sdm', 'label' => 'Manajemen SDM', 'icon' => 'fa-solid fa-address-card', 'sections' => [
            ['title' => 'Santri', 'paths' => [
                '/santri/index.php',
                '/santri/hidmah.php',
                '/santri/tingkatan_ta.php',
            ]],
            ['title' => 'Wali Santri', 'paths' => [
                '/data/wali.php',
                '/settings/akses_mukimin.php',
            ]],
            ['title' => 'Pembimbing', 'paths' => [
                '/pembimbing/dashboard.php',
                '/pembimbing/index.php',
                '/pembimbing/perizinan.php',
            ]],
            ['title' => 'Munawib', 'paths' => [
                '/pembimbing/munawib.php',
                '/rekap/munawib.php',
            ]],
        ]],
        ['type' => 'group', 'id' => 'menu-grp-keuangan', 'label' => 'Keuangan', 'icon' => 'fa-solid fa-wallet', 'sections' => [
            ['title' => 'Laporan', 'paths' => [
                '/keuangan/index.php',
                '/keuangan/neraca.php',
                '/keuangan/arus-kas.php',
                '/pembayaran/rekap_pos.php',
                '/pembayaran/laporan.php',
            ]],
            ['title' => 'Transaksi', 'paths' => [
                '/keuangan/pembayaran.php',
                '/pembayaran/tagihan_syahriyah.php',
                '/pembayaran/riwayat.php',
                '/keuangan/pemasukan.php',
                '/keuangan/pengeluaran.php',
                '/keuangan/potongan_syahriyah.php',
                '/keuangan/cashless_scan.php',
                '/keuangan/cashless_laporan.php',
                '/keuangan/talangan.php',
            ]],
            ['title' => 'Insentif & Gaji', 'paths' => [
                '/rekap/pembimbing.php',
            ]],
            ['title' => 'Pengaturan', 'paths' => [
                '/keuangan/pengaturan.php',
                '/settings/opsional_santri.php',
                '/keuangan/cashless_pin.php',
                '/keuangan/inventaris.php',
            ]],
        ]],
        ['type' => 'group', 'id' => 'menu-grp-perizinan', 'label' => 'Perizinan', 'icon' => 'fa-solid fa-person-walking-arrow-right', 'sections' => [
            ['title' => 'Izin Santri', 'paths' => [
                '/perizinan/permohonan.php',
                '/perizinan/index.php',
                '/perizinan/izin_tetap.php',
            ]],
            ['title' => 'Surat & Rekap', 'paths' => [
                '/admin/surat_nomor.php',
                '/admin/rekap_surat_izin.php',
                '/admin/rekap_surat_sp.php',
            ]],
        ]],
        ['type' => 'group', 'id' => 'menu-grp-kajian', 'label' => 'Kajian', 'icon' => 'fa-solid fa-book-open', 'sections' => [
            ['title' => 'Jadwal & Presensi', 'paths' => [
                '/jadwal/index.php',
                '/jadwal/import.php',
                '/presensi/scan.php',
                '/presensi/kegiatan_khusus.php',
            ]],
            ['title' => 'Pusat Rekap', 'paths' => [
                '/rekap/hub.php',
                '/rekap/keaktivan_sdm.php',
                '/rekap/index.php',
                '/rekap/keaktifan_hari.php',
                '/rekap/santri_bagus.php',
                '/rekap/izin_telat.php',
                '/rekap/kegiatan_khusus.php',
            ]],
            ['title' => 'Akademik', 'paths' => [
                '/akademik/kalender.php',
                '/akademik/bait_kitab.php',
                '/akademik/hafalan.php',
                '/akademik/rapor.php',
                '/pembimbing/tugas/index.php',
                '/pembimbing/tugas/rekap.php',
                '/pembimbing/nilai_manual.php',
                '/akademik/ikhtibar_rekap.php',
            ]],
            ['title' => 'Poin & Keaktifan', 'paths' => [
                '/poin/input.php',
                '/poin/rekap.php',
                '/pengasuh/nilai_keaktifan.php',
            ]],
        ]],
        ['type' => 'group', 'id' => 'menu-grp-yayasan', 'label' => 'Yayasan', 'icon' => 'fa-solid fa-building-columns', 'sections' => [
            ['title' => 'Struktural & Rapat', 'paths' => [
                '/yayasan/pengurus.php',
                '/yayasan/rapat.php',
                '/yayasan/notulen.php',
            ]],
            ['title' => 'Eksekutif', 'paths' => [
                '/yayasan/executive.php',
            ]],
        ]],
        ['type' => 'group', 'id' => 'menu-grp-pengaturan', 'label' => 'Pengaturan', 'icon' => 'fa-solid fa-sliders', 'sections' => [
            ['title' => 'Umum', 'paths' => [
                '/settings/pesantren.php',
                '/settings/peraturan.php',
            ]],
            ['title' => 'WhatsApp Otomatis', 'paths' => [
                '/settings/wa_otomatis.php',
            ]],
            ['title' => 'Master Data', 'paths' => [
                '/settings/hijri_mappings.php',
                '/settings/tingkatan.php',
                '/settings/kelas_keuangan.php',
                '/settings/tarif_payroll.php',
                '/settings/opsional_santri.php',
                '/settings/kelas_ruangan.php',
                '/settings/kamar_ranjang.php',
            ]],
            ['title' => 'Akses & Sistem', 'paths' => [
                '/settings/admin.php',
                '/admin/cek_update.php',
            ]],
        ]],
    ],
    'pengaturanNav' => [
        ['path' => '/settings/wa_otomatis.php', 'label' => 'Pusat WA Otomatis', 'icon' => 'fa-solid fa-comments'],
        ['path' => '/settings/peraturan.php', 'label' => 'Peraturan Poin', 'icon' => 'fa-solid fa-scale-balanced'],
        ['path' => '/settings/kalender.php', 'label' => 'Pengaturan Kalender', 'icon' => 'fa-solid fa-calendar-days'],
        ['path' => '/settings/tingkatan.php', 'label' => 'Master Tingkatan', 'icon' => 'fa-solid fa-layer-group'],
        ['path' => '/settings/kelas_keuangan.php', 'label' => 'Kelas Keuangan', 'icon' => 'fa-solid fa-coins'],
        ['path' => '/settings/tarif_payroll.php', 'label' => 'Master Tarif Payroll Pembimbing', 'icon' => 'fa-solid fa-sack-dollar'],
        ['path' => '/settings/opsional_santri.php', 'label' => 'Opsional Santri (Makan & Saku)', 'icon' => 'fa-solid fa-utensils'],
        ['path' => '/settings/kelas_ruangan.php', 'label' => 'Ruangan Kelas', 'icon' => 'fa-solid fa-door-open'],
        ['path' => '/settings/kamar_ranjang.php', 'label' => 'Kamar & Ranjang', 'icon' => 'fa-solid fa-bed'],
        ['path' => '/settings/admin.php', 'label' => 'Kelola Akses User', 'icon' => 'fa-solid fa-user-shield'],
        ['path' => '/admin/cek_update.php', 'label' => 'Cek Update Sistem', 'icon' => 'fa-solid fa-arrows-rotate'],
    ],
    'permissionPathMap' => user_permission_path_map(),
];
