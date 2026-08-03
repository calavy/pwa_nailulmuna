<?php

declare(strict_types=1);

/** Format jam tampilan 24 jam (HH:MM atau HH:MM:SS). */
function app_format_jam(?string $time, bool $withSeconds = false): string
{
    $t = trim((string) $time);
    if ($t === '') {
        return '—';
    }
    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([AaPp])\.?\s*[Mm]\.?$/', $t, $m)) {
        $h = (int) $m[1];
        if (strtoupper($m[4]) === 'P' && $h < 12) {
            $h += 12;
        }
        if (strtoupper($m[4]) === 'A' && $h === 12) {
            $h = 0;
        }
        $sec = $m[3] ?? '00';

        return str_pad((string) $h, 2, '0', STR_PAD_LEFT) . ':' . $m[2] . ($withSeconds ? ':' . $sec : '');
    }
    if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?/', $t, $m)) {
        $out = str_pad((string) ((int) $m[1]), 2, '0', STR_PAD_LEFT) . ':' . $m[2];
        if ($withSeconds && isset($m[3])) {
            $out .= ':' . $m[3];
        }

        return $out;
    }
    $ts = strtotime($t);
    if ($ts === false) {
        return $t;
    }

    return $withSeconds ? date('H:i:s', $ts) : date('H:i', $ts);
}

/** Normalisasi jam ke HH:MM (24 jam). Kosong jika tidak valid. */
function app_normalize_jam_hm(string $raw): string
{
    $formatted = app_format_jam($raw);
    if ($formatted === '—' || !preg_match('/^(\d{2}):(\d{2})$/', $formatted, $m)) {
        return '';
    }

    return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
}

/** Konversi HH:MM ke total menit sejak tengah malam. Null jika tidak valid. */
function app_jam_to_minutes(string $jamHm): ?int
{
    $jam = app_normalize_jam_hm($jamHm);
    if ($jam === '' || !preg_match('/^(\d{2}):(\d{2})$/', $jam, $m)) {
        return null;
    }

    return ((int) $m[1]) * 60 + (int) $m[2];
}

/** Apakah waktu sekarang sudah melewati jam kirim (kosong = langsung). */
function app_jam_sudah_lewat(string $jamKirim, ?string $nowHm = null): bool
{
    $jamMinutes = app_jam_to_minutes($jamKirim);
    if ($jamMinutes === null) {
        return true;
    }
    $nowMinutes = app_jam_to_minutes($nowHm ?? date('H:i'));
    if ($nowMinutes === null) {
        return false;
    }

    return $nowMinutes >= $jamMinutes;
}

/** Tanggal + jam 24 jam: dd/mm/yyyy HH:MM */
function app_format_datetime_id(?string $datetime): string
{
    $raw = trim((string) $datetime);
    if ($raw === '') {
        return '—';
    }
    $ts = strtotime($raw);
    if ($ts === false) {
        return $raw;
    }

    return date('d/m/Y H:i', $ts);
}

/** Tanggal saja: dd/mm/yyyy */
function app_format_tanggal_id(?string $date): string
{
    $raw = trim((string) $date);
    if ($raw === '') {
        return '—';
    }
    $ts = strtotime($raw);

    return $ts !== false ? date('d/m/Y', $ts) : $raw;
}

/** Rentang jam: 07:00–08:30 (24 jam). */
function app_format_jam_rentang(?string $jamMulai, ?string $jamSelesai): string
{
    $jm = app_format_jam($jamMulai);
    $js = app_format_jam($jamSelesai);
    if ($jm === '—' && $js === '—') {
        return '—';
    }
    if ($js === '—' || $jm === $js) {
        return $jm;
    }

    return $jm . '–' . $js;
}

/** Tampilan periode izin di tabel: tanggal + jam 24 jam. */
function app_format_periode_izin_tabel(string $tglMulai, string $tglSelesai, ?string $jamMulai = null, ?string $jamSelesai = null): string
{
    $d1 = app_format_tanggal_id($tglMulai);
    $d2 = app_format_tanggal_id($tglSelesai);
    $jm = app_format_jam($jamMulai);
    $js = app_format_jam($jamSelesai);

    return $d1 . ' ' . $jm . ' s/d ' . $d2 . ' ' . $js;
}

/** Atribut HTML input waktu (teks HH:MM, tanpa picker AM/PM). */
function app_time_input_attrs(): string
{
    return 'class="form-control input-time-24" inputmode="numeric" pattern="^([01]?[0-9]|2[0-3]):[0-5][0-9]$" placeholder="HH:MM" maxlength="5" autocomplete="off"';
}

/** Render input jam 24 jam (type=text, bukan type=time). */
function app_render_time_input(
    string $name,
    ?string $value = '',
    bool $required = false,
    string $id = '',
    string $extraClass = ''
): string {
    $val = app_format_jam($value);
    if ($val === '—') {
        $val = '';
    }
    $idAttr = $id !== '' ? ' id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"' : '';
    $req = $required ? ' required' : '';
    $cls = trim('form-control input-time-24 ' . $extraClass);

    return '<input type="text" name="' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '"' . $idAttr
        . ' class="' . htmlspecialchars($cls, ENT_QUOTES, 'UTF-8') . '" value="' . htmlspecialchars($val, ENT_QUOTES, 'UTF-8') . '"'
        . ' inputmode="numeric" pattern="^([01]?[0-9]|2[0-3]):[0-5][0-9]$" placeholder="HH:MM" maxlength="5" autocomplete="off"' . $req . '>';
}

/** Rentang tanggal/jam untuk izin (24 jam). */
function app_format_izin_rentang(string $tglMulai, string $tglSelesai, ?string $jamMulai = null, ?string $jamSelesai = null): string
{
    $jm = app_format_jam($jamMulai);
    $js = app_format_jam($jamSelesai);
    $d1 = app_format_tanggal_id($tglMulai);
    $d2 = app_format_tanggal_id($tglSelesai);
    if ($d1 === $d2) {
        return $d1 . ' ' . $jm . ' – ' . $js;
    }

    return $d1 . ' ' . $jm . ' s/d ' . $d2 . ' ' . $js;
}
