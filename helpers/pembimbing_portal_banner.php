<?php

declare(strict_types=1);

/** @return list<string> */
function pembimbing_portal_banner_variants(): array
{
    return ['default', 'kajian', 'pkpps', 'jamaah'];
}

function pembimbing_portal_banner_setting_key(string $variant): string
{
    $variant = strtolower(trim($variant));
    if (!in_array($variant, pembimbing_portal_banner_variants(), true)) {
        $variant = 'default';
    }

    return 'pb_portal_banner_' . $variant;
}

/** @return array<string, mixed> */
function pembimbing_portal_banner_defaults(string $variant): array
{
    $variant = strtolower(trim($variant));
    $presets = [
        'default' => [
            'enabled' => '1',
            'kicker' => 'Portal Pembimbing',
            'title' => '',
            'subtitle' => 'Pantau santri, keaktivan, dan kegiatan hari ini.',
            'tagline' => 'Bimbing · Pantau · Tindak',
            'icon' => 'fa-chalkboard-user',
            'gradient_from' => '#0f766e',
            'gradient_via' => '#115e59',
            'gradient_to' => '#134e4a',
            'accent' => '#2dd4bf',
            'glow' => '#5eead4',
            'pattern' => 'dots',
        ],
        'kajian' => [
            'enabled' => '1',
            'kicker' => 'Kajian · Ta\'lim',
            'title' => '',
            'subtitle' => 'Ta\'lim, tugas ikhtibar, dan keaktivan santri kajian.',
            'tagline' => 'Ilmu · Amal · Keaktivan',
            'icon' => 'fa-book-open',
            'gradient_from' => '#1e3a8a',
            'gradient_via' => '#1d4ed8',
            'gradient_to' => '#312e81',
            'accent' => '#93c5fd',
            'glow' => '#60a5fa',
            'pattern' => 'grid',
        ],
        'pkpps' => [
            'enabled' => '1',
            'kicker' => 'Program PKPPS',
            'title' => '',
            'subtitle' => 'Santri PKPPS, jadwal, tugas, dan rekap keaktivan.',
            'tagline' => 'Program · Disiplin · Prestasi',
            'icon' => 'fa-graduation-cap',
            'gradient_from' => '#92400e',
            'gradient_via' => '#b45309',
            'gradient_to' => '#78350f',
            'accent' => '#fcd34d',
            'glow' => '#fbbf24',
            'pattern' => 'rays',
        ],
        'jamaah' => [
            'enabled' => '1',
            'kicker' => 'Jama\'ah',
            'title' => '',
            'subtitle' => 'Kegiatan jama\'ah, sholat berjamaah, dan kehadiran santri.',
            'tagline' => 'Sholat · Jama\'ah · Kebersamaan',
            'icon' => 'fa-people-group',
            'gradient_from' => '#065f46',
            'gradient_via' => '#047857',
            'gradient_to' => '#064e3b',
            'accent' => '#6ee7b7',
            'glow' => '#34d399',
            'pattern' => 'waves',
        ],
    ];

    return $presets[$variant] ?? $presets['default'];
}

/** @return array<string, mixed> */
function pembimbing_portal_banner_get(PDO $pdo, string $variant): array
{
    $defaults = pembimbing_portal_banner_defaults($variant);
    $raw = app_setting($pdo, pembimbing_portal_banner_setting_key($variant), '');
    if ($raw === '') {
        return $defaults;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    return array_merge($defaults, $decoded);
}

/** @param array<string, mixed> $data */
function pembimbing_portal_banner_save(PDO $pdo, string $variant, array $data): void
{
    $defaults = pembimbing_portal_banner_defaults($variant);
    $out = [];
    foreach ($defaults as $key => $defaultVal) {
        if (!array_key_exists($key, $data)) {
            $out[$key] = $defaultVal;
            continue;
        }
        $val = $data[$key];
        if ($key === 'enabled') {
            $out[$key] = !empty($val) ? '1' : '0';
            continue;
        }
        $out[$key] = is_string($val) ? trim($val) : (string) $val;
    }
    save_setting($pdo, pembimbing_portal_banner_setting_key($variant), json_encode($out, JSON_UNESCAPED_UNICODE));
}

function pembimbing_portal_banner_resolve_variant(
    bool $isMunawibPortal,
    bool $hasPkpps,
    bool $hasKajian,
    string $rekapJenis = ''
): string {
    if ($isMunawibPortal) {
        return 'default';
    }
    $rekapJenis = strtolower(trim($rekapJenis));
    if ($rekapJenis === 'pkpps' && $hasPkpps) {
        return 'pkpps';
    }
    if ($rekapJenis === 'kajian') {
        return 'kajian';
    }
    if ($hasPkpps && !$hasKajian) {
        return 'pkpps';
    }

    return 'default';
}

/** @param array<string, mixed> $cfg */
function pembimbing_portal_banner_css_vars(array $cfg): string
{
    $from = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($cfg['gradient_from'] ?? '')) ? $cfg['gradient_from'] : '#0f766e';
    $via = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($cfg['gradient_via'] ?? '')) ? $cfg['gradient_via'] : '#115e59';
    $to = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($cfg['gradient_to'] ?? '')) ? $cfg['gradient_to'] : '#134e4a';
    $accent = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($cfg['accent'] ?? '')) ? $cfg['accent'] : '#2dd4bf';
    $glow = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($cfg['glow'] ?? '')) ? $cfg['glow'] : '#5eead4';

    return '--pb-banner-from:' . $from . ';--pb-banner-via:' . $via . ';--pb-banner-to:' . $to
        . ';--pb-banner-accent:' . $accent . ';--pb-banner-glow:' . $glow . ';';
}

/**
 * String jam & tanggal awal untuk banner (selaras app-datetime-24h.js, hindari placeholder flash).
 *
 * @return array{time:string,date:string}
 */
function pembimbing_portal_banner_clock_strings(
    string $today,
    string $nowTime,
    string $pasaran,
    string $hijri,
    bool $compactMonth = true
): array {
    $ts = strtotime($today . ' ' . substr($nowTime, 0, 8));
    if ($ts === false) {
        $ts = time();
    }
    $hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', "Jum'at", 'Sabtu'];
    $bulanPendek = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $bulanPanjang = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $h = (int) date('G', $ts);
    $m = (int) date('i', $ts);
    $s = (int) date('s', $ts);
    $timeStr = sprintf('%02d:%02d:%02d', $h, $m, $s);
    $blnList = $compactMonth ? $bulanPendek : $bulanPanjang;
    $hariStr = $hari[(int) date('w', $ts)] ?? '';
    $pasaran = trim($pasaran);
    if ($pasaran !== '') {
        $hariStr .= ' · ' . $pasaran;
    }
    $dateStr = $hariStr . ', ' . date('j', $ts) . ' ' . ($blnList[(int) date('n', $ts) - 1] ?? '') . ' ' . date('Y', $ts);
    $hijri = trim($hijri);
    if ($hijri !== '') {
        $dateStr .= ' / ' . $hijri;
    }

    return ['time' => $timeStr, 'date' => $dateStr];
}

/**
 * Identitas topbar portal pembimbing (nama + jam awal), dipakai di includes/header.php.
 *
 * @return array{name:string,time:string,date:string,pasaran:string,hijri:string,presence_label:string,scan_status:string,scan_label:string,is_munawib_portal:bool}
 */
function pembimbing_portal_topbar_identity(PDO $pdo): array
{
    require_once __DIR__ . '/pembimbing_dashboard.php';
    require_once __DIR__ . '/akademik_pasaran.php';
    require_once __DIR__ . '/hijri_kalender.php';
    require_once __DIR__ . '/akademik.php';
    require_once __DIR__ . '/pembimbing_perubahan_jadwal.php';

    $userId = (int) ($_SESSION['user']['id'] ?? 0);
    $pembimbingInfo = $userId > 0 ? pembimbing_dashboard_current_pembimbing($pdo, $userId) : null;
    $pembimbingId = $pembimbingInfo !== null ? (int) ($pembimbingInfo['id'] ?? 0) : 0;
    $name = $pembimbingInfo !== null
        ? trim((string) ($pembimbingInfo['nama'] ?? ''))
        : trim((string) ($_SESSION['user']['nama'] ?? ''));
    if ($name === '') {
        $name = 'Pembimbing';
    }

    $presenceLabel = 'Pembimbing · bertugas hari ini';
    $scanStatus = 'none';
    $scanLabel = 'Tidak ada jadwal hari ini';
    $isMunawibPortal = false;
    if (!function_exists('munawib_is_portal_session')) {
        require_once __DIR__ . '/munawib_portal.php';
    }
    if (munawib_is_portal_session()) {
        $isMunawibPortal = true;
        $konteks = munawib_portal_konteks();
        if (is_array($konteks)) {
            $mwPb = trim((string) ($konteks['pembimbing_nama'] ?? ''));
            if ($mwPb !== '') {
                $name = $mwPb;
                $mwShort = mb_strlen($mwPb) > 36 ? mb_substr($mwPb, 0, 33) . '…' : $mwPb;
                $presenceLabel = 'Munawib · menggantikan ' . $mwShort;
            } else {
                $presenceLabel = 'Munawib · bertugas hari ini';
            }
        }
    }

    $today = date('Y-m-d');
    if (!$isMunawibPortal && $pembimbingId > 0) {
        $slotsToday = pb_jadwal_slots_hari_ini($pdo, $pembimbingId, $today);
        if ($slotsToday === []) {
            $scanStatus = 'none';
            $scanLabel = 'Tidak ada jadwal hari ini';
        } elseif (pembimbing_dashboard_sudah_hadir_hari_ini($pdo, $pembimbingId, $today)) {
            $scanStatus = 'done';
            $scanLabel = 'Sudah scan';
        } else {
            $scanStatus = 'pending';
            $scanLabel = 'Belum scan';
        }
    }
    $nowTime = date('H:i:s');
    $pasaran = '';
    $hijri = '';
    ensure_hijri_mappings_table($pdo);
    ensure_akademik_hijri_awal_bulan_table($pdo);
    $hijriBulanNama = [
        1 => 'Muharram', 2 => 'Safar', 3 => "Rabi' I", 4 => "Rabi' II", 5 => 'Jumadil Awal', 6 => 'Jumadil Akhir',
        7 => 'Rajab', 8 => "Sya'ban", 9 => 'Ramadan', 10 => 'Syawal', 11 => "Dzulqa'dah", 12 => 'Dzulhijah',
    ];
    $hijri = akademik_hijri_label_h($pdo, $today, $hijriBulanNama);
    $pasaran = akademik_pasaran_tampilkan($pdo) ? akademik_pasaran_pada_tanggal($today, $pdo) : '';

    $clock = pembimbing_portal_banner_clock_strings($today, $nowTime, $pasaran, $hijri, true);

    return [
        'name' => $name,
        'time' => (string) ($clock['time'] ?? date('H:i:s')),
        'date' => (string) ($clock['date'] ?? ''),
        'pasaran' => $pasaran,
        'hijri' => $hijri,
        'presence_label' => $presenceLabel,
        'scan_status' => $scanStatus,
        'scan_label' => $scanLabel,
        'is_munawib_portal' => $isMunawibPortal,
    ];
}
