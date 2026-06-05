<?php

declare(strict_types=1);

require_once __DIR__ . '/pondok_kalender.php';
require_once __DIR__ . '/keuangan_ta_context.php';
require_once __DIR__ . '/keuangan_transaksi.php';
require_once __DIR__ . '/akademik.php';
require_once __DIR__ . '/hijri_kalender.php';

/**
 * Resolves one calendar month (hijriyah or masehi) to gregorian date range — used by rapor & rekap.
 *
 * @return array{mode:string,month:int,year:int,start_date:string,end_date:string,label:string,hijri_label:string}
 */
function rekap_resolve_periode(PDO $pdo, array $get): array
{
    $mode = strtolower(trim((string) ($get['mode'] ?? '')));
    if (!in_array($mode, ['masehi', 'hijriyah'], true)) {
        $mode = trim((string) app_setting($pdo, 'wa_tagihan_calendar', 'HIJRIYAH')) === 'MASEHI' ? 'masehi' : 'hijriyah';
    }
    $month = max(1, min(12, (int) ($get['month'] ?? (int) date('n'))));
    $year = (int) ($get['year'] ?? 0);
    if ($year <= 0) {
        if ($mode === 'hijriyah') {
            $anchor = akademik_hijri_anchor_hari_ini($pdo);
            $year = (int) $anchor['y'];
            $month = (int) $anchor['m'];
        } else {
            $year = (int) date('Y');
        }
    }

    if ($mode === 'hijriyah') {
        [$start, $end] = akademik_gregorian_range_from_hijri_month($pdo, $year, $month);
        $label = hijri_indeks_ke_nama($month) . ' ' . $year;
        $hijriLabel = $label;
    } else {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start) ?: time());
        $masehi = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
            7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];
        $label = ($masehi[$month] ?? (string) $month) . ' ' . $year;
        $hijri = konversiKeHijriah($pdo, $start);
        $hijriLabel = is_array($hijri)
            ? hijri_indeks_ke_nama((int) ($hijri['bulan_hijriyah'] ?? 1)) . ' ' . (int) ($hijri['tahun_hijriah'] ?? $year)
            : $label;
    }

    return [
        'mode' => $mode,
        'month' => $month,
        'year' => $year,
        'start_date' => $start,
        'end_date' => $end,
        'label' => $label,
        'hijri_label' => $hijriLabel,
    ];
}

/**
 * Filter periode rekap: satu hari, bulan tagihan TA, atau rentang tanggal.
 *
 * @return array{
 *   mode:string,
 *   tanggal:string,
 *   dari:string,
 *   sampai:string,
 *   bulan_tagihan:int,
 *   ta_mulai:int,
 *   ta_selesai:int,
 *   bulan_slots:list<array<string,mixed>>
 * }
 */
function rekap_periode_resolve(PDO $pdo, array $get, string $defaultMode = 'hari'): array
{
    $keuanganTa = keuangan_ta_resolve($pdo);
    $taMulai = (int) $keuanganTa['mulai'];
    $taSelesai = (int) $keuanganTa['selesai'];
    $slots = pondok_bulan_slots_tahun_ajaran($pdo, $taMulai, $taSelesai);
    $berjalan = keuangan_periode_berjalan($pdo);
    $bulanBerjalan = max(1, min(12, (int) ($berjalan['bulan'] ?? 1)));

    $mode = strtolower(trim((string) ($get['periode_mode'] ?? $defaultMode)));
    if (!in_array($mode, ['hari', 'bulan', 'rentang'], true)) {
        $mode = $defaultMode;
    }

    $tanggal = trim((string) ($get['tanggal'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        $tanggal = date('Y-m-d');
    }

    $dari = trim((string) ($get['dari'] ?? $get['from'] ?? $get['start_date'] ?? ''));
    $sampai = trim((string) ($get['sampai'] ?? $get['to'] ?? $get['end_date'] ?? ''));
    $bulanTagihan = max(1, min(12, (int) ($get['rekap_bulan'] ?? $bulanBerjalan)));

    if ($mode === 'bulan') {
        foreach ($slots as $slot) {
            if ((int) ($slot['bulan_tagihan'] ?? 0) === $bulanTagihan) {
                $dari = (string) ($slot['tanggal_mulai'] ?? $dari);
                $sampai = (string) ($slot['tanggal_selesai'] ?? $sampai);
                break;
            }
        }
        if ($dari === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari)) {
            $dari = sprintf('%04d-%02d-01', (int) date('Y'), $bulanTagihan);
        }
        if ($sampai === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) {
            $sampai = date('Y-m-t', strtotime($dari) ?: time());
        }
        $tanggal = $sampai;
    } elseif ($mode === 'rentang') {
        if ($dari === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari)) {
            $dari = date('Y-m-01');
        }
        if ($sampai === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) {
            $sampai = date('Y-m-d');
        }
        if ($dari > $sampai) {
            [$dari, $sampai] = [$sampai, $dari];
        }
    } else {
        $dari = $tanggal;
        $sampai = $tanggal;
    }

    return [
        'mode' => $mode,
        'tanggal' => $tanggal,
        'dari' => $dari,
        'sampai' => $sampai,
        'bulan_tagihan' => $bulanTagihan,
        'ta_mulai' => $taMulai,
        'ta_selesai' => $taSelesai,
        'bulan_slots' => $slots,
        'bulan_berjalan' => $bulanBerjalan,
    ];
}
