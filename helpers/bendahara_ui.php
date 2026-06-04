<?php

declare(strict_types=1);

/**
 * Kartu navigasi modul Bendahara (dashboard).
 *
 * @return list<array{href:string,title:string,desc:string,icon:string,color:string}>
 */
function bendahara_nav_cards(): array
{
    return [
        [
            'href' => '/pembayaran/tagihan_syahriyah.php',
            'title' => 'Tagihan Bulanan',
            'desc' => 'Status lunas / belum per santri per bulan ajaran.',
            'icon' => 'fa-receipt',
            'color' => 'primary',
        ],
        [
            'href' => '/keuangan/pembayaran.php',
            'title' => 'Input pembayaran',
            'desc' => 'Penerimaan kas/bank per komponen biaya santri.',
            'icon' => 'fa-circle-plus',
            'color' => 'success',
        ],
        [
            'href' => '/keuangan/talangan.php',
            'title' => 'Dana talangan',
            'desc' => 'Pinjam antar-POS, saldo aktual vs tersedia, buku utang internal.',
            'icon' => 'fa-arrows-left-right',
            'color' => 'warning',
        ],
        [
            'href' => '/pembayaran/riwayat.php',
            'title' => 'Riwayat pembayaran',
            'desc' => 'Semua transaksi: kas, transfer, dan rincian POS.',
            'icon' => 'fa-clock-rotate-left',
            'color' => 'secondary',
        ],
        [
            'href' => '/pembayaran/rekap_pos.php',
            'title' => 'Rekap per POS',
            'desc' => 'Target vs terbayar per komponen (Syahriyah, Makan, dll.).',
            'icon' => 'fa-chart-pie',
            'color' => 'info',
        ],
        [
            'href' => '/pembayaran/laporan.php',
            'title' => 'Laporan Syahriyah',
            'desc' => 'Rekap terbayar per bulan dalam tahun ajaran.',
            'icon' => 'fa-chart-column',
            'color' => 'info',
        ],
        [
            'href' => '/pembayaran/laporan_alokasi_per_santri.php',
            'title' => 'Alokasi syahriyah per santri',
            'desc' => 'Pembagian pembayaran ke komponen alokasi (PKPPS → gaji guru).',
            'icon' => 'fa-sitemap',
            'color' => 'info',
        ],
        [
            'href' => '/pembayaran/laporan_pkpps_syahriyah.php',
            'title' => 'Laporan syahriyah PKPPS',
            'desc' => 'Tambahan syahriyah santri PKPPS per bulan.',
            'icon' => 'fa-file-invoice-dollar',
            'color' => 'info',
        ],
        [
            'href' => '/keuangan/potongan_syahriyah.php',
            'title' => 'Potongan syahriyah',
            'desc' => 'Potongan persen per santri (prestasi, kaka beradik, dll.).',
            'icon' => 'fa-percent',
            'color' => 'warning',
        ],
        [
            'href' => '/keuangan/pengaturan.php?bagian=syahriyah_makan',
            'title' => 'Pengaturan tarif',
            'desc' => 'Nominal syahriyah, makan, saku, dan komponen lain.',
            'icon' => 'fa-sliders',
            'color' => 'warning',
        ],
        [
            'href' => '/settings/kelas_keuangan.php',
            'title' => 'Kelas keuangan',
            'desc' => 'Kode kelas santri dan tier tarif (Muadalah/Wustho/Ulya).',
            'icon' => 'fa-layer-group',
            'color' => 'dark',
        ],
        [
            'href' => '/settings/opsional_santri.php',
            'title' => 'Opsional santri (Makan & Saku)',
            'desc' => 'Aktifkan/nonaktifkan Makan & Saku per santri, set nominal khusus.',
            'icon' => 'fa-utensils',
            'color' => 'info',
        ],
        [
            'href' => '/keuangan/index.php',
            'title' => 'Dashboard keuangan',
            'desc' => 'Neraca, arus kas, pemasukan lain, pengeluaran.',
            'icon' => 'fa-wallet',
            'color' => 'primary',
        ],
        [
            'href' => '/keuangan/cashless_scan.php',
            'title' => 'Top up cashless',
            'desc' => 'Isi saldo jajan santri via scan QR.',
            'icon' => 'fa-qrcode',
            'color' => 'secondary',
        ],
    ];
}

/** Ikon Font Awesome per halaman bendahara. */
function bendahara_page_icon(string $page): string
{
    return match ($page) {
        'tagihan' => 'fa-receipt',
        'riwayat' => 'fa-clock-rotate-left',
        'laporan' => 'fa-chart-column',
        'rekap_pos' => 'fa-chart-pie',
        'input' => 'fa-circle-plus',
        'hub' => 'fa-cash-register',
        default => 'fa-wallet',
    };
}
