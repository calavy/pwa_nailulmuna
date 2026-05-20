<?php

declare(strict_types=1);

require_once __DIR__ . '/akademik.php';
require_once __DIR__ . '/hijri_kalender.php';

/**
 * Resolusi periode rekap (Masehi atau Hijriyah) dari parameter GET.
 *
 * @return array{
 *   mode:string,
 *   month:int,
 *   year:int,
 *   start_date:string,
 *   end_date:string,
 *   label:string,
 *   hijri_label:string
 * }
 */
function rekap_resolve_periode(PDO $pdo, array $get): array
{
    $mode = strtolower(trim((string) ($get['mode'] ?? 'masehi')));
    if (!in_array($mode, ['masehi', 'hijriyah'], true)) {
        $mode = 'masehi';
    }
    $appYear = app_tahun_masehi_default($pdo);
    $anchor = akademik_hijri_anchor_hari_ini($pdo);
    $month = (int) ($get['month'] ?? ($mode === 'hijriyah' ? $anchor['m'] : (int) date('n')));
    $year = (int) ($get['year'] ?? ($mode === 'hijriyah' ? $anchor['y'] : $appYear));
    $month = max(1, min(12, $month));

    $masehiMonths = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $hijriMonths = hijri_nama_bulan_list();

    if ($mode === 'hijriyah') {
        if (!hijri_tahun_valid($year)) {
            $year = (int) $anchor['y'];
        }
        [$startDate, $endDate] = hijri_rentang_masehi_bulan($pdo, $year, $month);
        $label = ($hijriMonths[$month] ?? 'Bulan ' . $month) . ' ' . $year . ' H';
        $hijriLabel = $label;
    } else {
        $year = max(1900, min(2100, $year > 0 ? $year : $appYear));
        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = date('Y-m-t', strtotime($startDate));
        $label = ($masehiMonths[$month] ?? sprintf('%02d', $month)) . ' ' . $year;
        $hijriLabel = trim((string) get_hijri_full_date($startDate));
    }

    return [
        'mode' => $mode,
        'month' => $month,
        'year' => $year,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'label' => $label,
        'hijri_label' => $hijriLabel,
    ];
}
