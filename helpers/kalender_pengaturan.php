<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/pondok_kalender.php';
require_once __DIR__ . '/akademik.php';

/** Kunci pengaturan yang dikelola di halaman Kalender terpusat. */
function kalender_pengaturan_keys(): array
{
    return [
        'wa_tagihan_calendar',
        'wa_tagihan_day',
        'wa_tagihan_send_time',
        'wa_tagihan_custom_masehi_dates',
        'akademik_kalender_default_view',
        'akademik_blokir_presensi_libur',
        'akademik_libur_presensi_mode',
        'akademik_libur_taalim_only',
        'akademik_blokir_setoran_libur',
        'akademik_blokir_penilaian_libur',
        'app_tahun_masehi_mode',
        'app_tahun_masehi_tetap',
        'pondok_ta_bulan_awal_hijri',
        'pondok_ta_bulan_awal_masehi',
    ];
}

/** @return array<string, string> */
function kalender_pengaturan_load(PDO $pdo): array
{
    ensure_pondok_settings_defaults($pdo);
    $defaults = pondok_settings_defaults();
    $out = [];
    foreach (kalender_pengaturan_keys() as $key) {
        $out[$key] = (string) app_setting($pdo, $key, $defaults[$key] ?? '');
    }
    $out['wa_tagihan_calendar'] = in_array(strtoupper($out['wa_tagihan_calendar']), ['MASEHI', 'HIJRIYAH'], true)
        ? strtoupper($out['wa_tagihan_calendar'])
        : 'HIJRIYAH';
    $out['wa_tagihan_day'] = (string) max(1, min(30, (int) ($out['wa_tagihan_day'] ?: 5)));
    $out['wa_tagihan_custom_masehi_dates'] = trim((string) ($out['wa_tagihan_custom_masehi_dates'] ?? ''));
    $dv = strtolower(trim($out['akademik_kalender_default_view']));
    $out['akademik_kalender_default_view'] = in_array($dv, ['bulan', 'masehi', 'atur', 'tahun'], true)
        ? ($dv === 'tahun' ? 'atur' : $dv)
        : 'bulan';
    $out['app_tahun_masehi_mode'] = ($out['app_tahun_masehi_mode'] ?? '') === 'TETAP' ? 'TETAP' : 'BERJALAN';
    $out['akademik_libur_presensi_mode'] = akademik_libur_presensi_mode($pdo);
    $out['pondok_ta_bulan_awal_hijri'] = (string) max(1, min(12, (int) ($out['pondok_ta_bulan_awal_hijri'] ?: 1)));
    $out['pondok_ta_bulan_awal_masehi'] = (string) max(1, min(12, (int) ($out['pondok_ta_bulan_awal_masehi'] ?: 7)));

    return $out;
}

/**
 * @return array{ok:bool,message:string,backfill?:array<string,int>}
 */
function kalender_pengaturan_simpan(PDO $pdo, array $post): array
{
    $calendarLama = strtoupper(trim((string) app_setting($pdo, 'wa_tagihan_calendar', 'HIJRIYAH')));
    $calendar = strtoupper(trim((string) ($post['wa_tagihan_calendar'] ?? 'HIJRIYAH')));
    if (!in_array($calendar, ['MASEHI', 'HIJRIYAH'], true)) {
        $calendar = 'HIJRIYAH';
    }
    save_setting($pdo, 'wa_tagihan_calendar', $calendar);

    if ($calendar === 'HIJRIYAH') {
        save_setting($pdo, 'akademik_kalender_default_view', 'bulan');
    }

    $dueDay = max(1, min(30, (int) ($post['wa_tagihan_day'] ?? 5)));
    save_setting($pdo, 'wa_tagihan_day', (string) $dueDay);
    save_setting($pdo, 'wa_tagihan_send_time', trim((string) ($post['wa_tagihan_send_time'] ?? '08:00')));
    save_setting($pdo, 'wa_tagihan_custom_masehi_dates', trim((string) ($post['wa_tagihan_custom_masehi_dates'] ?? '')));

    $dv = strtolower(trim((string) ($post['akademik_kalender_default_view'] ?? 'bulan')));
    if (!in_array($dv, ['bulan', 'masehi', 'atur'], true)) {
        $dv = 'bulan';
    }
    save_setting($pdo, 'akademik_kalender_default_view', $dv);

    save_setting($pdo, 'akademik_blokir_presensi_libur', isset($post['blok_presensi']) ? '1' : '0');
    $modePresensi = strtoupper(trim((string) ($post['akademik_libur_presensi_mode'] ?? 'TAALIM_ONLY')));
    if (!in_array($modePresensi, ['ALL_BLOCKED', 'TAALIM_ONLY', 'JAMAAH_ONLY'], true)) {
        $modePresensi = 'TAALIM_ONLY';
    }
    save_setting($pdo, 'akademik_libur_presensi_mode', $modePresensi);
    // legacy key tetap disimpan agar kompatibel
    save_setting($pdo, 'akademik_libur_taalim_only', $modePresensi === 'TAALIM_ONLY' ? '1' : '0');
    save_setting($pdo, 'akademik_blokir_setoran_libur', isset($post['blok_setoran']) ? '1' : '0');
    save_setting($pdo, 'akademik_blokir_penilaian_libur', isset($post['blok_penilaian']) ? '1' : '0');

    $tmMode = strtoupper(trim((string) ($post['app_tahun_masehi_mode'] ?? 'BERJALAN')));
    if (!in_array($tmMode, ['BERJALAN', 'TETAP'], true)) {
        $tmMode = 'BERJALAN';
    }
    save_setting($pdo, 'app_tahun_masehi_mode', $tmMode);
    $tmTetap = (int) ($post['app_tahun_masehi_tetap'] ?? date('Y'));
    save_setting($pdo, 'app_tahun_masehi_tetap', (string) max(1900, min(2100, $tmTetap)));

    $awalH = max(1, min(12, (int) ($post['pondok_ta_bulan_awal_hijri'] ?? 1)));
    $awalM = max(1, min(12, (int) ($post['pondok_ta_bulan_awal_masehi'] ?? 7)));
    save_setting($pdo, 'pondok_ta_bulan_awal_hijri', (string) $awalH);
    save_setting($pdo, 'pondok_ta_bulan_awal_masehi', (string) $awalM);

    $backfill = null;
    if ($calendar === 'HIJRIYAH' && $calendarLama !== 'HIJRIYAH') {
        $backfill = pondok_backfill_kalender_hijriyah($pdo, false);
    }

    return [
        'ok' => true,
        'message' => 'Pengaturan kalender berhasil disimpan.',
        'backfill' => $backfill,
    ];
}

/** Ringkasan hari ini untuk tampilan atas halaman. */
function kalender_pengaturan_ringkas_hari_ini(PDO $pdo): array
{
    $todayMasehi = date('Y-m-d');
    $todayHijri = akademik_hijri_tanggal_penuh($pdo, $todayMasehi);
    $hijriBulanNama = [
        1 => 'Muharram', 2 => 'Safar', 3 => "Rabi' I", 4 => "Rabi' II", 5 => 'Jumadil Awal', 6 => 'Jumadil Akhir',
        7 => 'Rajab', 8 => "Sya'ban", 9 => 'Ramadan', 10 => 'Syawal', 11 => "Dzulqa'dah", 12 => 'Dzulhijah',
    ];
    $hijriLabel = $todayHijri;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $todayHijri, $mh)) {
        $mo = (int) $mh[2];
        $hijriLabel = (int) $mh[3] . ' ' . ($hijriBulanNama[$mo] ?? ('Bulan ' . $mo)) . ' ' . (int) $mh[1] . ' H.';
    }

    return [
        'masehi_label' => akademik_masehi_label_pendek($todayMasehi),
        'masehi_ymd' => $todayMasehi,
        'hijri_label' => $hijriLabel,
        'hijri_ymd' => $todayHijri,
        'status' => pondok_kalender_status($pdo),
    ];
}
