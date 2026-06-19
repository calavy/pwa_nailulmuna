<?php

declare(strict_types=1);

require_once __DIR__ . '/rekap_periode.php';
require_once __DIR__ . '/presensi_jadwal.php';
require_once __DIR__ . '/rekap_keaktifan.php';
require_once __DIR__ . '/hijri_kalender.php';

/**
 * Data rekap keaktifan bulanan untuk dashboard yayasan.
 *
 * @param array<string, mixed> $get gunakan key: mode, month, year, tingkatan
 * @return array<string, mixed>
 */
function yayasan_keaktifan_bulan_pack(PDO $pdo, array $get): array
{
    $empty = [
        'ready' => false,
        'mode' => 'hijriyah',
        'month' => 1,
        'year' => 1400,
        'start_date' => '',
        'end_date' => '',
        'periode_label' => '',
        'tingkatan' => '',
        'hijri_months' => hijri_nama_bulan_list(),
        'good_max' => 1,
        'medium_max' => 3,
        'total_santri' => 0,
        'total_hadir' => 0,
        'total_alpa' => 0,
        'rata_hadir' => 0.0,
        'tingkatan_persen' => [],
        'tingkatan_chart' => ['labels' => [], 'datasets' => [], 'stacked_datasets' => []],
        'kegiatan_tanpa_scan' => [],
        'santri_tanpa_scan' => [],
        'tingkatan_list' => [],
        'show_chart' => false,
    ];

    if (!table_exists($pdo, 'presensi')) {
        return $empty;
    }

    $periodeGet = [
        'mode' => (string) ($get['mode'] ?? 'hijriyah'),
        'month' => $get['month'] ?? null,
        'year' => $get['year'] ?? null,
    ];
    $periode = rekap_resolve_periode($pdo, $periodeGet);
    $mode = (string) $periode['mode'];
    $month = (int) $periode['month'];
    $year = (int) $periode['year'];
    $startDate = (string) $periode['start_date'];
    $endDate = (string) $periode['end_date'];
    $periodeLabel = (string) $periode['label'];
    $tingkatan = trim((string) ($get['tingkatan'] ?? ''));

    $goodMax = (int) app_setting($pdo, 'kategori_baik_max', '1');
    $mediumMax = (int) app_setting($pdo, 'kategori_sedang_max', '3');

    $rawRows = presensi_fetch_rows_rekap($pdo, $startDate, $endDate, 0);
    if ($tingkatan !== '') {
        $rawRows = array_values(array_filter($rawRows, static function (array $row) use ($tingkatan): bool {
            return strtolower((string) ($row['tingkatan'] ?? '')) === strtolower($tingkatan);
        }));
    }

    $ranked = rekap_keaktifan_build_per_santri($rawRows, $goodMax, $mediumMax);
    $chartRanked = $ranked;
    $byTingkatanChart = rekap_keaktifan_build_per_tingkatan($chartRanked);
    $tingkatanPersen = rekap_keaktifan_kategori_persen_per_tingkatan($byTingkatanChart);
    $tingkatanChart = rekap_keaktifan_chart_tingkatan_kategori($tingkatanPersen);

    $kegiatanTanpaScan = rekap_keaktifan_kegiatan_tanpa_scan_bulan(
        $pdo,
        $startDate,
        $endDate,
        $tingkatan !== '' ? $tingkatan : null,
        0
    );
    $santriTanpaScan = rekap_keaktifan_santri_tanpa_scan_bulan(
        $pdo,
        $startDate,
        $endDate,
        $tingkatan !== '' ? $tingkatan : null
    );

    $totalHadir = array_sum(array_column($ranked, 'hadir'));
    $totalAlpa = array_sum(array_column($ranked, 'alpa'));
    $totalPresensi = array_sum(array_column($ranked, 'total'));
    $rataHadir = $totalPresensi > 0 ? round(($totalHadir / $totalPresensi) * 100, 2) : 0.0;

    $tingkatanList = [];
    if (table_exists($pdo, 'tingkatan')) {
        $tingkatanList = $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY nama_tingkatan ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    return [
        'ready' => true,
        'mode' => $mode,
        'month' => $month,
        'year' => $year,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'periode_label' => $periodeLabel,
        'tingkatan' => $tingkatan,
        'hijri_months' => hijri_nama_bulan_list(),
        'good_max' => $goodMax,
        'medium_max' => $mediumMax,
        'total_santri' => count($ranked),
        'total_hadir' => $totalHadir,
        'total_alpa' => $totalAlpa,
        'rata_hadir' => $rataHadir,
        'tingkatan_persen' => $tingkatanPersen,
        'tingkatan_chart' => $tingkatanChart,
        'kegiatan_tanpa_scan' => $kegiatanTanpaScan,
        'santri_tanpa_scan' => $santriTanpaScan,
        'tingkatan_list' => $tingkatanList,
        'show_chart' => $tingkatanPersen !== [],
    ];
}

/**
 * @param array<string, mixed> $kb
 * @return list<string>
 */
function yayasan_keaktifan_bulan_saran(array $kb): array
{
    $saran = [];
    $kgKosong = count((array) ($kb['kegiatan_tanpa_scan'] ?? []));
    $snKosong = count((array) ($kb['santri_tanpa_scan'] ?? []));
    $rataHadir = (float) ($kb['rata_hadir'] ?? 0);

    if ($kgKosong > 0) {
        $saran[] = 'Koordinasikan dengan pembimbing/munawib untuk ' . $kgKosong . ' jadwal kegiatan tanpa scan hadir — cek apakah jadwal aktif dan perangkat scan berfungsi.';
    }
    if ($snKosong > 0) {
        $saran[] = 'Follow-up ' . $snKosong . ' santri tanpa scan hadir (wali/pembimbing) agar kebiasaan scan tertanam sebelum akhir bulan.';
    }
    if ($rataHadir < 75 && (int) ($kb['total_santri'] ?? 0) > 0) {
        $saran[] = 'Rata-rata kehadiran bulan ini ' . $rataHadir . '% — pertimbangkan sosialisasi ulang scan dan evaluasi jadwal yang terlalu padat.';
    }

    foreach ((array) ($kb['tingkatan_persen'] ?? []) as $tg => $row) {
        $buruk = (float) ($row['persen']['Buruk'] ?? 0);
        if ($buruk >= 20.0) {
            $saran[] = 'Tingkatan ' . $tg . ': ' . $buruk . '% santri kategori Buruk — prioritaskan pembinaan dan pantau ALPA mingguan.';
        }
    }

    if ($kgKosong === 0 && $snKosong === 0 && $rataHadir >= 85) {
        $saran[] = 'Kondisi keaktifan bulan ini relatif baik. Pertahankan monitoring harian lewat menu Keaktifan Hari Ini.';
    }

    $saran[] = 'Pastikan presensi bulan ini sudah difinalisasi (ALPA otomatis) sebelum rapat yayasan — gunakan Segarkan ALPA di Rekap Presensi jika ada data baru.';

    return array_values(array_unique($saran));
}
