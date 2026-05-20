<?php

function app_setting(PDO $pdo, string $key, ?string $default = null): ?string
{
    if (!table_exists($pdo, 'app_settings')) {
        return $default;
    }

    $statement = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = :setting_key LIMIT 1');
    $statement->execute(['setting_key' => $key]);
    $value = $statement->fetchColumn();

    return $value !== false ? (string) $value : $default;
}

/**
 * Tahun Masehi default untuk filter laporan (bila URL tidak menyertakan tahun).
 * BERJALAN: mengikuti tahun dari tanggal server. TETAP: nilai app_tahun_masehi_tetap (1900–2100).
 */
function app_tahun_masehi_default(PDO $pdo): int
{
    $yNow = (int) date('Y');
    $mode = strtoupper(trim((string) app_setting($pdo, 'app_tahun_masehi_mode', 'BERJALAN')));
    if ($mode !== 'TETAP') {
        return $yNow;
    }
    $fixed = (int) app_setting($pdo, 'app_tahun_masehi_tetap', (string) $yNow);
    if ($fixed < 1900 || $fixed > 2100) {
        return $yNow;
    }

    return $fixed;
}

function save_setting(PDO $pdo, string $key, string $value): void
{
    $statement = $pdo->prepare('
        INSERT INTO app_settings (setting_key, setting_value)
        VALUES (:setting_key, :setting_value)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ');
    $statement->execute([
        'setting_key' => $key,
        'setting_value' => $value,
    ]);
}

/** Nama pondok/pesantren untuk branding aplikasi dan surat. */
function app_brand_nama_ponpes(PDO $pdo, string $fallback = 'A.P.I Nailul Muna'): string
{
    $nama = trim((string) app_setting($pdo, 'nama_ponpes', ''));
    if ($nama === '' || $nama === 'Nama Pondok Pesantren') {
        return $fallback;
    }

    return $nama;
}

/**
 * Nilai awal pengaturan pesantren bila belum pernah disimpan.
 *
 * @return array<string, string>
 */
function pondok_settings_defaults(): array
{
    return [
        'nama_ponpes' => 'Pondok Pesantren Nailul Muna',
        'jenis_pendidikan' => 'Pondok Pesantren / Pesantren Putra Putri',
        'alamat_ponpes' => '',
        'nama_pengasuh' => '',
        'logo_path' => '',
        'wa_gateway_url' => '',
        'wa_gateway_token' => '',
        'wa_sender' => '',
        'wa_pengurus' => '',
        'jam_kirim_wa_auto' => '',
        'wa_tagihan_auto_enabled' => '0',
        'wa_tagihan_calendar' => 'MASEHI',
        'wa_tagihan_day' => '5',
        'wa_tagihan_send_time' => '08:00',
        'batas_alpa_notif' => '3',
        'batas_telat_menit' => '15',
        'kategori_baik_max' => '1',
        'kategori_sedang_max' => '3',
        'izin_perpanjangan_max_hari' => '7',
        'izin_perpanjangan_jenis' => 'SAKIT,KELUAR',
        'app_tahun_masehi_mode' => 'BERJALAN',
        'app_tahun_masehi_tetap' => (string) (int) date('Y'),
    ];
}

/** Isi kunci pengaturan pondok yang belum ada di app_settings (tanpa menimpa nilai lama). */
function ensure_pondok_settings_defaults(PDO $pdo): void
{
    if (!table_exists($pdo, 'app_settings')) {
        return;
    }
    $ins = $pdo->prepare('
        INSERT IGNORE INTO app_settings (setting_key, setting_value)
        VALUES (:k, :v)
    ');
    foreach (pondok_settings_defaults() as $key => $value) {
        $ins->execute(['k' => $key, 'v' => $value]);
    }
}

/** Normalisasi isi QR bayar cashless (sama di scan & pengaturan peta kode). */
function cashless_normalize_money_qr_payload(string $raw): string
{
    $t = trim($raw);
    if (stripos($t, 'CASHLESSPAY:') === 0) {
        $t = trim(substr($t, strlen('CASHLESSPAY:')));
    }
    $out = preg_replace('/[^A-Za-z0-9]/', '', $t) ?? '';

    return strtoupper($out);
}

function ensure_cashless_nominal_qr_map_table(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS cashless_nominal_qr_map (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kode_qr VARCHAR(120) NOT NULL,
            nominal INT NOT NULL DEFAULT 0,
            keterangan VARCHAR(160) NULL,
            is_aktif TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_cashless_nominal_qr_kode (kode_qr)
        )
    ');
}

function islamic_calendar_locale(): string
{
    return 'en_US@calendar=islamic-umalqura';
}

function get_hijri_year_month(string $date): string
{
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        $timestamp = time();
    }
    if (!class_exists('IntlDateFormatter')) {
        return date('Y-m', $timestamp);
    }

    $formatter = new IntlDateFormatter(
        islamic_calendar_locale(),
        IntlDateFormatter::NONE,
        IntlDateFormatter::NONE,
        date_default_timezone_get(),
        IntlDateFormatter::TRADITIONAL,
        'yyyy-MM'
    );
    $formatted = $formatter->format($timestamp);
    if (is_string($formatted) && preg_match('/^\d{4}-\d{2}$/', $formatted)) {
        return $formatted;
    }

    // Fallback ke varian kalender islamic default bila umalqura tidak tersedia.
    $fallbackFormatter = new IntlDateFormatter(
        'id_ID@calendar=islamic',
        IntlDateFormatter::NONE,
        IntlDateFormatter::NONE,
        date_default_timezone_get(),
        IntlDateFormatter::TRADITIONAL,
        'yyyy-MM'
    );
    $fallback = $fallbackFormatter->format($timestamp);
    return is_string($fallback) && preg_match('/^\d{4}-\d{2}$/', $fallback) ? $fallback : date('Y-m', $timestamp);
}

/**
 * Tanggal hijriyah yyyy-MM-dd dari tanggal masehi Y-m-d (Intl: Um al-Qura, lalu islamic).
 * Rentang bulan H. ke Masehi (get_gregorian_range_from_hijri_month) diselaraskan dengan fungsi ini.
 */
function get_hijri_full_date(string $date): string
{
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        $timestamp = time();
    }
    if (!class_exists('IntlDateFormatter')) {
        return date('Y-m-d', $timestamp);
    }
    foreach ([islamic_calendar_locale(), 'id_ID@calendar=islamic'] as $loc) {
        $formatter = new IntlDateFormatter(
            $loc,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            date_default_timezone_get(),
            IntlDateFormatter::TRADITIONAL,
            'yyyy-MM-dd'
        );
        $formatted = $formatter->format($timestamp);
        if (is_string($formatted) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $formatted)) {
            return $formatted;
        }
    }

    return date('Y-m-d', $timestamp);
}

function get_hijri_ym_from_gregorian_month(int $year, int $month): string
{
    $date = sprintf('%04d-%02d-01', $year, $month);
    return get_hijri_year_month($date);
}

/**
 * Memecah string hijriyah yyyy-mm-dd menjadi komponen bilangan.
 *
 * @return array{y:int,m:int,d:int}|null
 */
function hijri_parse_ymd_parts(string $hijriYmd): ?array
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $hijriYmd, $m)) {
        return null;
    }

    return ['y' => (int) $m[1], 'm' => (int) $m[2], 'd' => (int) $m[3]];
}

/**
 * Perkiraan rentang tanggal Masehi (Y-m-d) untuk satu bulan H. bila IntlCalendar tidak ada.
 * Penting: angka tahun H. (mis. 1447) tidak boleh dipakai sebagai tahun pada kalender Masehi.
 * Metode: memetakan 12 bulan H. ke potongan merata rentang Masehi perkiraan untuk satu tahun H. penuh
 * (bukan hisab resmi; aktifkan extension=intl untuk Um al-Qura).
 *
 * @return array{0: string, 1: string}
 */
function hijri_month_gregorian_bounds_tabular_gregorian_year_split(int $hijriYear, int $hijriMonth): array
{
    $hy = max(1300, min(1600, $hijriYear));
    $hm = max(1, min(12, $hijriMonth));
    $gyLo = (int) max(1, floor(622.5446 + 0.970224 * ($hy - 1)));
    $gyHi = (int) min(2100, ceil(622.5446 + 0.970224 * $hy));
    $tStart = strtotime(sprintf('%04d-01-01', $gyLo));
    $tEnd = strtotime(sprintf('%04d-12-31', $gyHi));
    if ($tStart === false || $tEnd === false || $tEnd < $tStart) {
        return [sprintf('%04d-01-01', $gyLo), sprintf('%04d-12-31', max($gyLo, $gyHi))];
    }
    $span = $tEnd - $tStart;
    $a = (int) ($tStart + (int) floor($span * ($hm - 1) / 12));
    $b = (int) ($tStart + (int) floor($span * $hm / 12) - 86400);
    if ($b < $a) {
        $b = min($tEnd, $a + 28 * 86400);
    }
    $b = min($b, $tEnd);

    return [date('Y-m-d', $a), date('Y-m-d', $b)];
}

/**
 * Perkiraan rentang Masehi (Y-m-d) untuk satu bulan H. dari IntlCalendar.
 * Urutan locale sama dengan get_hijri_full_date (Um al-Qura lalu islamic).
 *
 * @return array{0: string, 1: string}
 */
function hijri_month_gregorian_bounds_intl_approx(int $hijriYear, int $hijriMonth): array
{
    if (!class_exists('IntlCalendar')) {
        return hijri_month_gregorian_bounds_tabular_gregorian_year_split($hijriYear, $hijriMonth);
    }

    $timezone = new DateTimeZone(date_default_timezone_get());
    foreach ([islamic_calendar_locale(), 'id_ID@calendar=islamic'] as $loc) {
        $calendar = IntlCalendar::createInstance($timezone, $loc);
        if (!$calendar) {
            continue;
        }

        $calendar->set(IntlCalendar::FIELD_YEAR, $hijriYear);
        $calendar->set(IntlCalendar::FIELD_MONTH, $hijriMonth - 1);
        $calendar->set(IntlCalendar::FIELD_DAY_OF_MONTH, 1);
        $calendar->set(IntlCalendar::FIELD_HOUR_OF_DAY, 0);
        $calendar->set(IntlCalendar::FIELD_MINUTE, 0);
        $calendar->set(IntlCalendar::FIELD_SECOND, 0);
        $startMs = $calendar->getTime();
        if ($startMs === false) {
            continue;
        }

        $nextCalendar = clone $calendar;
        $nextCalendar->add(IntlCalendar::FIELD_MONTH, 1);
        $nextStartMs = $nextCalendar->getTime();
        if ($nextStartMs === false) {
            continue;
        }

        $start = date('Y-m-d', (int) floor(((float) $startMs) / 1000));
        $end = date('Y-m-d', (int) floor((((float) $nextStartMs) - 86400000) / 1000));

        return [$start, $end];
    }

    return hijri_month_gregorian_bounds_tabular_gregorian_year_split($hijriYear, $hijriMonth);
}

/**
 * Daftar tanggal Masehi (Y-m-d) yang menurut get_hijri_full_date() jatuh pada bulan H. (tahun & bulan).
 * Menyelaraskan arah Hijri→Masehi dengan Masehi→Hijri (sumber konversi yang dipakai aplikasi).
 *
 * @return list<string>
 */
function get_masehi_days_in_hijri_month(int $hijriYear, int $hijriMonth): array
{
    [$approxStart, $approxEnd] = hijri_month_gregorian_bounds_intl_approx($hijriYear, $hijriMonth);
    if (!class_exists('IntlDateFormatter')) {
        return masehi_linear_days_between($approxStart, $approxEnd);
    }

    $padDays = 70;
    $cur = strtotime($approxStart . ' -' . $padDays . ' days');
    $lim = strtotime($approxEnd . ' +' . $padDays . ' days');
    if ($cur === false || $lim === false) {
        return masehi_linear_days_between($approxStart, $approxEnd);
    }

    $hits = [];
    while ($cur <= $lim) {
        $ymd = date('Y-m-d', $cur);
        $parts = hijri_parse_ymd_parts(get_hijri_full_date($ymd));
        if ($parts && $parts['y'] === $hijriYear && $parts['m'] === $hijriMonth) {
            $hits[] = $ymd;
        }
        $cur = strtotime('+1 day', $cur);
    }

    if ($hits === []) {
        return masehi_linear_days_between($approxStart, $approxEnd);
    }

    sort($hits);

    return $hits;
}

/**
 * @return list<string>
 */
function masehi_linear_days_between(string $gStart, string $gEnd): array
{
    $out = [];
    $cur = strtotime($gStart);
    $endTs = strtotime($gEnd);
    if ($cur === false || $endTs === false) {
        return [];
    }
    while ($cur <= $endTs) {
        $out[] = date('Y-m-d', $cur);
        $cur = strtotime('+1 day', $cur);
    }

    return $out;
}

/**
 * Rentang Masehi (inklusif) setara satu bulan hijriyah menurut get_hijri_full_date().
 *
 * @return array{0: string, 1: string}
 */
function get_gregorian_range_from_hijri_month(int $hijriYear, int $hijriMonth): array
{
    $days = get_masehi_days_in_hijri_month($hijriYear, $hijriMonth);
    if ($days !== []) {
        return [$days[0], $days[count($days) - 1]];
    }

    return hijri_month_gregorian_bounds_intl_approx($hijriYear, $hijriMonth);
}

function ensure_jadwal_kegiatan_tempat(PDO $pdo): void
{
    if (!table_exists($pdo, 'jadwal_kegiatan')) {
        return;
    }
    try {
        $pdo->exec('ALTER TABLE jadwal_kegiatan ADD COLUMN IF NOT EXISTS tempat VARCHAR(255) NULL');
    } catch (PDOException $e) {
        try {
            $pdo->exec('ALTER TABLE jadwal_kegiatan ADD COLUMN tempat VARCHAR(255) NULL');
        } catch (PDOException $e2) {
            $m2 = $e2->getMessage();
            if (stripos($m2, 'Duplicate column') !== false || strpos($m2, '1060') !== false) {
                return;
            }
            throw $e2;
        }
    }
}

function activity_for_tingkatan(PDO $pdo, string $tingkatan, string $date, string $time): ?array
{
    if (!table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan')) {
        return null;
    }
    ensure_jadwal_kegiatan_tempat($pdo);

    $day = date('N', strtotime($date));
    $statement = $pdo->prepare('
        SELECT k.id, k.nama_kegiatan, j.jam_mulai, j.jam_selesai, j.tempat
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        WHERE (j.tingkatan = :tingkatan OR j.tingkatan = "Semua Tingkatan")
          AND (j.hari_ke = 0 OR j.hari_ke = :hari_ke)
          AND :jam_now BETWEEN j.jam_mulai AND j.jam_selesai
          AND k.is_active = 1
        LIMIT 1
    ');
    $statement->execute([
        'tingkatan' => $tingkatan,
        'hari_ke' => $day,
        'jam_now' => $time,
    ]);

    $result = $statement->fetch();
    return $result ?: null;
}

function resolve_wa_endpoint(string $endpoint, string $token): string
{
    $endpoint = trim($endpoint);
    if ($endpoint !== '') {
        $normalized = $endpoint;
        if (!preg_match('#^https?://#i', $normalized)) {
            $normalized = 'https://' . ltrim($normalized, '/');
        }

        $parts = parse_url($normalized);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');

        // Banyak kasus URL panel/login Fonnte (mis. md.fonnte.com/new/login.php).
        // Paksa ke endpoint API resmi agar token-only tetap jalan.
        if (strpos($host, 'fonnte.com') !== false || strpos($host, 'fonte') !== false) {
            if ($host !== 'api.fonnte.com' || stripos($path, '/send') === false) {
                return 'https://api.fonnte.com/send';
            }
        }

        return $normalized;
    }

    // Jika hanya token yang diisi, gunakan endpoint default Fonnte.
    if (trim($token) !== '') {
        return 'https://api.fonnte.com/send';
    }

    return '';
}

function send_wa_message_with_result(PDO $pdo, string $phone, string $message, array $override = []): array
{
    $endpoint = isset($override['endpoint']) ? trim((string) $override['endpoint']) : app_setting($pdo, 'wa_gateway_url', '');
    $token = isset($override['token']) ? trim((string) $override['token']) : app_setting($pdo, 'wa_gateway_token', '');
    $sender = isset($override['sender']) ? trim((string) $override['sender']) : app_setting($pdo, 'wa_sender', '');
    $endpoint = resolve_wa_endpoint($endpoint, $token);
    $phone = normalize_wa_phone($phone);

    if ($endpoint === '' || $phone === '') {
        return [
            'success' => false,
            'http_code' => 0,
            'error' => $endpoint === '' ? 'WA gateway URL kosong.' : 'Nomor WA tidak valid.',
            'response' => '',
            'target' => $phone,
        ];
    }

    $payload = [
        'token' => $token,
        'sender' => $sender,
        'target' => $phone,
        'message' => $message,
    ];

    $ch = curl_init($endpoint);
    $isFonte = (bool) preg_match('/fonte|fonnte/i', $endpoint);
    $headers = [];
    if ($isFonte && $token !== '') {
        $headers[] = 'Authorization: ' . $token;
    }
    if ($isFonte) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $payload = [
            'token' => $token,
            'target' => $phone,
            'message' => $message,
            'countryCode' => '62',
        ];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 3,
    ]);
    $rawResponse = curl_exec($ch);
    $error = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $responseHeaders = '';
    $response = '';
    if (is_string($rawResponse)) {
        $responseHeaders = substr($rawResponse, 0, $headerSize);
        $response = substr($rawResponse, $headerSize);
    }

    $isSuccess = $error === '' && $statusCode >= 200 && $statusCode < 300;
    // Redirect ke halaman login berarti endpoint salah/landing page, anggap gagal eksplisit.
    if (in_array($statusCode, [301, 302, 303, 307, 308], true)) {
        $isSuccess = false;
    }
    if ($isSuccess && is_string($response) && $response !== '') {
        $decoded = json_decode($response, true);
        if (is_array($decoded) && array_key_exists('status', $decoded)) {
            $statusValue = $decoded['status'];
            if ($statusValue === false || $statusValue === 0 || $statusValue === 'false') {
                $isSuccess = false;
            }
        }
    }

    $location = '';
    if ($responseHeaders !== '') {
        if (preg_match('/^Location:\s*(.+)$/mi', $responseHeaders, $matches)) {
            $location = trim((string) ($matches[1] ?? ''));
        }
    }
    $responseText = $error !== '' ? $error : (string) $response;
    if ($location !== '') {
        $responseText .= "\n[redirect] " . $location;
    }
    if (table_exists($pdo, 'wa_logs')) {
        $log = $pdo->prepare('
            INSERT INTO wa_logs (target_phone, message, response_text, is_success)
            VALUES (:target_phone, :message, :response_text, :is_success)
        ');
        $log->execute([
            'target_phone' => $phone,
            'message' => $message,
            'response_text' => $responseText,
            'is_success' => $isSuccess ? 1 : 0,
        ]);
    }

    return [
        'success' => $isSuccess,
        'http_code' => $statusCode,
        'error' => $error,
        'response' => $responseText,
        'target' => $phone,
    ];
}

function send_wa_message(PDO $pdo, string $phone, string $message): bool
{
    $result = send_wa_message_with_result($pdo, $phone, $message);
    return (bool) ($result['success'] ?? false);
}

function parse_phone_list(string $raw): array
{
    $parts = preg_split('/[\s,;]+/', trim($raw)) ?: [];
    $phones = [];
    foreach ($parts as $part) {
        $phone = normalize_wa_phone($part);
        if ($phone !== null && $phone !== '') {
            $phones[] = $phone;
        }
    }

    return array_values(array_unique($phones));
}

function normalize_wa_phone(string $phone): string
{
    $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
    if ($digits === '') {
        return '';
    }
    if (strpos($digits, '0') === 0) {
        return '62' . substr($digits, 1);
    }
    if (strpos($digits, '8') === 0) {
        return '62' . $digits;
    }
    return $digits;
}

/** Tautan https://wa.me/… untuk membuka chat dengan teks awal (tanpa gateway). */
function wa_me_chat_url(string $phoneRaw, string $text = ''): ?string
{
    $digits = normalize_wa_phone($phoneRaw);
    if ($digits === '' || strlen($digits) < 10) {
        return null;
    }
    $base = 'https://wa.me/' . $digits;
    $t = trim($text);
    if ($t === '') {
        return $base;
    }

    return $base . '?text=' . rawurlencode($t);
}

function send_wa_bulk(PDO $pdo, string $phonesRaw, string $message): int
{
    $phones = parse_phone_list($phonesRaw);
    $sent = 0;
    foreach ($phones as $phone) {
        if (send_wa_message($pdo, $phone, $message)) {
            $sent++;
        }
    }

    return $sent;
}

function trigger_auto_wa_notifications(PDO $pdo): void
{
    if (!table_exists($pdo, 'app_settings') || !table_exists($pdo, 'presensi') || !table_exists($pdo, 'santri')) {
        return;
    }

    $pengurusWa = trim((string) app_setting($pdo, 'wa_pengurus', ''));
    if ($pengurusWa === '') {
        return;
    }

    $jamAutoWa = trim((string) app_setting($pdo, 'jam_kirim_wa_auto', ''));
    if ($jamAutoWa !== '' && date('H:i') < $jamAutoWa) {
        return;
    }

    $today = date('Y-m-d');
    $lastSentDate = trim((string) app_setting($pdo, 'wa_auto_last_sent_date', ''));
    if ($lastSentDate === $today) {
        return;
    }

    $threshold = max(1, (int) app_setting($pdo, 'batas_alpa_notif', '3'));
    $startMonth = date('Y-m-01');
    $endMonth = date('Y-m-t');

    $sqlWithKegiatan = '
        SELECT
            COALESCE(k.nama_kegiatan, "Tanpa kegiatan") AS nama_kegiatan,
            s.nama_santri,
            s.nis,
            s.tingkatan,
            COUNT(p.id) AS total_alpha
        FROM presensi p
        INNER JOIN santri s ON s.id = p.santri_id
        LEFT JOIN kegiatan k ON k.id = p.kegiatan_id
        WHERE p.status_presensi = "ALPA"
          AND p.tanggal_presensi BETWEEN :start_date AND :end_date
        GROUP BY COALESCE(p.kegiatan_id, 0), COALESCE(k.nama_kegiatan, "Tanpa kegiatan"), s.id, s.nama_santri, s.nis, s.tingkatan
        HAVING COUNT(p.id) >= :threshold
        ORDER BY nama_kegiatan ASC, total_alpha DESC, s.nama_santri ASC
        LIMIT 80
    ';
    $sqlNoKegiatan = '
        SELECT
            "Tanpa kegiatan" AS nama_kegiatan,
            s.nama_santri,
            s.nis,
            s.tingkatan,
            COUNT(p.id) AS total_alpha
        FROM presensi p
        INNER JOIN santri s ON s.id = p.santri_id
        WHERE p.status_presensi = "ALPA"
          AND p.tanggal_presensi BETWEEN :start_date AND :end_date
        GROUP BY s.id, s.nama_santri, s.nis, s.tingkatan
        HAVING COUNT(p.id) >= :threshold
        ORDER BY total_alpha DESC, s.nama_santri ASC
        LIMIT 80
    ';
    $stmt = $pdo->prepare(table_exists($pdo, 'kegiatan') ? $sqlWithKegiatan : $sqlNoKegiatan);
    $stmt->bindValue(':start_date', $startMonth);
    $stmt->bindValue(':end_date', $endMonth);
    $stmt->bindValue(':threshold', $threshold, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    if (!$rows) {
        return;
    }

    $tsPeriode = strtotime($startMonth) ?: time();
    $namaBulanId = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $periodeLabel = ($namaBulanId[(int) date('n', $tsPeriode)] ?? date('F', $tsPeriode)) . ' ' . date('Y', $tsPeriode);
    if (class_exists('IntlDateFormatter')) {
        $fmt = new IntlDateFormatter('id_ID', IntlDateFormatter::FULL, IntlDateFormatter::NONE, date_default_timezone_get(), IntlDateFormatter::GREGORIAN, 'LLLL yyyy');
        $periodeLabel = $fmt->format($tsPeriode) ?: $periodeLabel;
    }

    $message = wa_format_rekap_alpa_per_kegiatan($pdo, $periodeLabel, $threshold, $rows);

    $sent = send_wa_bulk($pdo, $pengurusWa, $message);
    if ($sent > 0) {
        save_setting($pdo, 'wa_auto_last_sent_date', $today);
        save_setting($pdo, 'wa_auto_last_sent_at', date('Y-m-d H:i:s'));
    }
}

function ensure_point_tables(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS point_rules (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kode_rule VARCHAR(40) NOT NULL UNIQUE,
            kategori VARCHAR(80) NOT NULL,
            nama_rule VARCHAR(150) NOT NULL,
            bobot_poin INT NOT NULL DEFAULT 0,
            contoh_pelanggaran TEXT NULL,
            urutan INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS point_sanctions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ambang_poin INT NOT NULL,
            tindakan TEXT NOT NULL,
            urutan INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS point_ledger (
            id INT AUTO_INCREMENT PRIMARY KEY,
            santri_id INT NOT NULL,
            tanggal DATE NOT NULL,
            jenis_perubahan ENUM("PLUS","MINUS") NOT NULL DEFAULT "PLUS",
            point_delta INT NOT NULL,
            rule_id INT NULL,
            sumber_data VARCHAR(40) NOT NULL DEFAULT "MANUAL",
            reference_presensi_id INT NULL,
            keterangan TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_point_source_ref (sumber_data, reference_presensi_id),
            INDEX idx_point_santri_tanggal (santri_id, tanggal),
            CONSTRAINT fk_point_ledger_santri FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE,
            CONSTRAINT fk_point_ledger_rule FOREIGN KEY (rule_id) REFERENCES point_rules(id) ON DELETE SET NULL
        )
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS point_followups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            santri_id INT NOT NULL,
            periode_bulan TINYINT NOT NULL,
            periode_tahun SMALLINT NOT NULL,
            total_poin INT NOT NULL DEFAULT 0,
            tindakan VARCHAR(120) NOT NULL,
            durasi_keterangan VARCHAR(120) NULL,
            keterangan TEXT NULL,
            status_tindak ENUM("BELUM","PROSES","SELESAI") NOT NULL DEFAULT "BELUM",
            bukti_tindak TEXT NULL,
            handled_by_user_id INT NULL,
            handled_by_nama VARCHAR(120) NOT NULL,
            tanggal_tindak DATE NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_followup_periode (periode_tahun, periode_bulan),
            INDEX idx_followup_santri (santri_id),
            CONSTRAINT fk_point_followups_santri FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
        )
    ');
    $pdo->exec('ALTER TABLE point_followups ADD COLUMN IF NOT EXISTS status_tindak ENUM("BELUM","PROSES","SELESAI") NOT NULL DEFAULT "BELUM"');
    $pdo->exec('ALTER TABLE point_followups ADD COLUMN IF NOT EXISTS bukti_tindak TEXT NULL');

    $rulesCount = (int) $pdo->query('SELECT COUNT(*) FROM point_rules')->fetchColumn();
    if ($rulesCount === 0) {
        $defaults = [
            ['A_SANGAT_BERAT', 'A. Sangat Berat', 'Pelanggaran sangat berat', 25, 'Percintaan, Pencurian, Perkelahian, Perjudian, Narkoba/Miras, Asusila.', 10],
            ['B_BERAT_15', 'B. Berat', 'Pelanggaran berat', 15, 'Membawa HP/Elektronik tanpa izin, kendaraan tanpa izin, ghosob, masuk asrama lawan jenis.', 20],
            ['B_BERAT_10', 'B. Berat', 'Pelanggaran berat level 2', 10, 'Bolos ngaji/belajar/mujahadah, merusak fasilitas, kata kasar, tidur saat kegiatan sama.', 30],
            ['C_SEDANG_5', 'C. Sedang', 'Pelanggaran sedang', 5, 'Keluar tanpa izin, ngiras/ngendong, bermain catur/kartu, meminjam dipan.', 40],
            ['C_SEDANG_3', 'C. Sedang', 'Pelanggaran sedang level 2', 3, 'Tidak piket, gaduh, tidur saat kegiatan.', 50],
            ['D_RINGAN_1', 'D. Ringan', 'Pelanggaran ringan', 1, 'Peci non-hitam, lengan pendek saat sholat, rambut/model tidak lazim, geland/kalung, sampah.', 60],
        ];
        $insertRule = $pdo->prepare('
            INSERT INTO point_rules (kode_rule, kategori, nama_rule, bobot_poin, contoh_pelanggaran, urutan)
            VALUES (:kode_rule, :kategori, :nama_rule, :bobot_poin, :contoh_pelanggaran, :urutan)
        ');
        foreach ($defaults as $row) {
            $insertRule->execute([
                'kode_rule' => $row[0],
                'kategori' => $row[1],
                'nama_rule' => $row[2],
                'bobot_poin' => $row[3],
                'contoh_pelanggaran' => $row[4],
                'urutan' => $row[5],
            ]);
        }
    }

    $sanctionCount = (int) $pdo->query('SELECT COUNT(*) FROM point_sanctions')->fetchColumn();
    if ($sanctionCount === 0) {
        $sanctions = [
            [10, 'Pilihan: Membaca Al-Quran 2 juz, Mujahadah 1 jam, atau 1 jam bersih-bersih.', 10],
            [25, 'Wajib gundul (putra)/kerudung disiplin (putri). Pilihan: berdiri 2 jam, baca Yasin 2 jam, Mujahadah 2 jam, atau 2 jam bersih-bersih.', 20],
            [50, 'Surat Peringatan 1 (SP1). Wajib gundul/kerudung disiplin. Pilihan: baca Yasin 3 jam, Al-Quran 5 juz, Mujahadah 3 jam, atau 3 jam bersih-bersih.', 30],
            [75, 'Surat Peringatan 2 (SP2) dan pemanggilan orang tua. Wajib gundul/kerudung disiplin. Pilihan: baca Yasin 4 jam, Al-Quran 7 juz, Mujahadah 4 jam, atau 4 jam bersih-bersih.', 40],
            [100, 'Sanksi final: dikeluarkan dari pesantren. Wajib gundul/kerudung disiplin hingga dijemput. Pilihan: baca Yasin 5 jam, Al-Quran 9 juz, Mujahadah 5 jam, atau 5 jam bersih-bersih.', 50],
        ];
        $insertSanction = $pdo->prepare('
            INSERT INTO point_sanctions (ambang_poin, tindakan, urutan)
            VALUES (:ambang_poin, :tindakan, :urutan)
        ');
        foreach ($sanctions as $item) {
            $insertSanction->execute([
                'ambang_poin' => $item[0],
                'tindakan' => $item[1],
                'urutan' => $item[2],
            ]);
        }
    }
}

function poin_ambang_sanksi_minimum(PDO $pdo): int
{
    if (!table_exists($pdo, 'point_sanctions')) {
        return 10;
    }
    $v = $pdo->query('SELECT MIN(ambang_poin) FROM point_sanctions WHERE is_active = 1')->fetchColumn();
    if ($v === false || $v === null) {
        return 10;
    }
    $m = (int) $v;

    return $m > 0 ? $m : 10;
}

/**
 * Status tindak lanjut terbaru per santri untuk periode bulan/tahun (berdasarkan id terbesar).
 *
 * @return array<int, string> santri_id => BELUM|PROSES|SELESAI
 */
function poin_latest_followup_status_map(PDO $pdo, int $month, int $year): array
{
    if (!table_exists($pdo, 'point_followups')) {
        return [];
    }
    $st = $pdo->prepare('
        SELECT pf.santri_id, pf.status_tindak
        FROM point_followups pf
        INNER JOIN (
            SELECT santri_id, MAX(id) AS mid
            FROM point_followups
            WHERE periode_bulan = :m AND periode_tahun = :y
            GROUP BY santri_id
        ) t ON t.mid = pf.id
    ');
    $st->execute(['m' => $month, 'y' => $year]);
    $map = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $map[(int) $r['santri_id']] = strtoupper((string) $r['status_tindak']);
    }

    return $map;
}

/**
 * Santri dengan total poin periode >= ambang sanksi aktif terendah,
 * yang belum ditangani: tidak ada tindak lanjut atau status terakhir bukan SELESAI.
 *
 * @return list<array{santri_id:int,nis:string,nama_santri:string,tingkatan:?string,total_poin:int|string}>
 */
function poin_santri_perlu_tindakan(PDO $pdo, int $month, int $year, ?string $tingkatanFilter = null): array
{
    if (!table_exists($pdo, 'point_ledger')) {
        return [];
    }
    $ambangMin = poin_ambang_sanksi_minimum($pdo);
    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));
    $statusMap = poin_latest_followup_status_map($pdo, $month, $year);

    $stmt = $pdo->prepare('
        SELECT s.id AS santri_id, s.nis, s.nama_santri, s.tingkatan,
            COALESCE(SUM(pl.point_delta), 0) AS total_poin
        FROM santri s
        LEFT JOIN point_ledger pl ON pl.santri_id = s.id AND pl.tanggal BETWEEN :a AND :b
        GROUP BY s.id, s.nis, s.nama_santri, s.tingkatan
        HAVING total_poin >= :ambang
        ORDER BY total_poin DESC, s.nama_santri ASC
    ');
    $stmt->execute(['a' => $start, 'b' => $end, 'ambang' => $ambangMin]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if ($tingkatanFilter !== null && $tingkatanFilter !== '') {
            if (strcasecmp((string) ($row['tingkatan'] ?? ''), $tingkatanFilter) !== 0) {
                continue;
            }
        }
        $sid = (int) $row['santri_id'];
        if (($statusMap[$sid] ?? '') === 'SELESAI') {
            continue;
        }
        $out[] = $row;
    }

    return $out;
}

function sync_points_from_presensi(PDO $pdo, int $createdBy): int
{
    if (!table_exists($pdo, 'presensi') || !table_exists($pdo, 'point_ledger')) {
        return 0;
    }

    $pointAlpa = (int) app_setting($pdo, 'point_auto_alpa', '5');
    $pointTelat = (int) app_setting($pdo, 'point_auto_telat', '1');
    $insert = $pdo->prepare('
        INSERT INTO point_ledger (santri_id, tanggal, jenis_perubahan, point_delta, sumber_data, reference_presensi_id, keterangan, created_by)
        VALUES (:santri_id, :tanggal, "PLUS", :point_delta, :sumber_data, :reference_presensi_id, :keterangan, :created_by)
    ');

    $added = 0;
    if ($pointAlpa > 0) {
        $alpaRows = $pdo->query('
            SELECT p.id, p.santri_id, p.tanggal_presensi
            FROM presensi p
            LEFT JOIN point_ledger pl ON pl.sumber_data = "PRESENSI_ALPA_AUTO" AND pl.reference_presensi_id = p.id
            WHERE p.status_presensi = "ALPA"
              AND pl.id IS NULL
        ')->fetchAll();
        foreach ($alpaRows as $row) {
            $insert->execute([
                'santri_id' => (int) $row['santri_id'],
                'tanggal' => $row['tanggal_presensi'],
                'point_delta' => $pointAlpa,
                'sumber_data' => 'PRESENSI_ALPA_AUTO',
                'reference_presensi_id' => (int) $row['id'],
                'keterangan' => 'Auto poin dari presensi ALPA.',
                'created_by' => $createdBy,
            ]);
            $added++;
        }
    }

    if ($pointTelat > 0) {
        $telatRows = $pdo->query('
            SELECT p.id, p.santri_id, p.tanggal_presensi, p.catatan
            FROM presensi p
            LEFT JOIN point_ledger pl ON pl.sumber_data = "PRESENSI_TELAT_AUTO" AND pl.reference_presensi_id = p.id
            WHERE p.catatan LIKE "%Terlambat%"
              AND pl.id IS NULL
        ')->fetchAll();
        foreach ($telatRows as $row) {
            $insert->execute([
                'santri_id' => (int) $row['santri_id'],
                'tanggal' => $row['tanggal_presensi'],
                'point_delta' => $pointTelat,
                'sumber_data' => 'PRESENSI_TELAT_AUTO',
                'reference_presensi_id' => (int) $row['id'],
                'keterangan' => 'Auto poin dari presensi telat. ' . (string) ($row['catatan'] ?? ''),
                'created_by' => $createdBy,
            ]);
            $added++;
        }
    }

    return $added;
}

function santri_category(int $alphaCount, int $goodMax, int $mediumMax): string
{
    if ($alphaCount === 0) {
        return 'Bagus';
    }

    if ($alphaCount <= $goodMax) {
        return 'Baik';
    }

    if ($alphaCount <= $mediumMax) {
        return 'Sedang';
    }

    return 'Buruk';
}

function sanitize_db_column_name(string $name): string
{
    $column = strtolower(trim($name));
    $column = str_replace(['-', '/', '.'], '_', $column);
    $column = preg_replace('/\s+/', '_', $column) ?? $column;
    $column = preg_replace('/[^a-z0-9_]/', '', $column) ?? $column;
    return trim($column, '_');
}

function ensure_santri_identity_columns(PDO $pdo): void
{
    $definitions = [
        'nik' => 'VARCHAR(40) NULL',
        'jenis_kelamin' => 'VARCHAR(20) NULL',
        'tempat_lahir_kab' => 'VARCHAR(120) NULL',
        'tanggal_lahir' => 'VARCHAR(20) NULL',
        'bulan_lahir' => 'VARCHAR(20) NULL',
        'tahun_lahir' => 'VARCHAR(10) NULL',
        'jumlah_saudara' => 'VARCHAR(10) NULL',
        'anak_ke' => 'VARCHAR(10) NULL',
        'hobi' => 'VARCHAR(120) NULL',
        'cita_cita' => 'VARCHAR(120) NULL',
        'dusun' => 'VARCHAR(120) NULL',
        'rt_rw' => 'VARCHAR(30) NULL',
        'desa_kelurahan' => 'VARCHAR(120) NULL',
        'kecamatan' => 'VARCHAR(120) NULL',
        'kabupaten' => 'VARCHAR(120) NULL',
        'propinsi' => 'VARCHAR(120) NULL',
        'nama_ayah' => 'VARCHAR(120) NULL',
        'pekerjaan_ayah' => 'VARCHAR(120) NULL',
        'no_kontak_ayah' => 'VARCHAR(30) NULL',
        'nama_ibu' => 'VARCHAR(120) NULL',
        'pekerjaan_ibu' => 'VARCHAR(120) NULL',
        'no_kontak_ibu' => 'VARCHAR(30) NULL',
        'nama_kafil' => 'VARCHAR(120) NULL',
        'status_kafil' => 'VARCHAR(80) NULL',
        'pekerjaan_kafil' => 'VARCHAR(120) NULL',
        'no_kontak_kafil' => 'VARCHAR(30) NULL',
        'pendidikan_diniyyah_terakhir' => 'TEXT NULL',
        'pendidikan_formal_terakhir' => 'TEXT NULL',
        'kitab_yang_pernah_dikaji' => 'TEXT NULL',
        'keluhan_sakit' => 'TEXT NULL',
        'pengobatan' => 'TEXT NULL',
        'tanggal_masuk' => 'DATE NULL',
        'alasan_mondok' => 'TEXT NULL',
        'atas_keinginan' => 'TEXT NULL',
        'mengapa_nailul' => 'TEXT NULL',
        'kategori_kelas' => 'VARCHAR(80) NULL',
        'no_wa_wali' => 'VARCHAR(40) NULL',
        'wali_portal_pin_hash' => 'VARCHAR(255) NULL',
        'santri_portal_pin_hash' => 'VARCHAR(255) NULL',
        'wali_santri_id' => 'INT NULL',
        'status_santri' => 'VARCHAR(30) NOT NULL DEFAULT \'AKTIF\'',
        'alasan_keluar' => 'TEXT NULL',
        'tanggal_keluar' => 'DATE NULL',
        'nama_kamar' => 'VARCHAR(120) NULL',
        'no_ranjang' => 'VARCHAR(80) NULL',
        'asrama_ranjang_id' => 'INT NULL',
        'kelas_ruangan_id' => 'INT NULL',
    ];

    foreach ($definitions as $column => $typeSql) {
        $pdo->exec('ALTER TABLE santri ADD COLUMN IF NOT EXISTS ' . $column . ' ' . $typeSql);
    }

    if (column_exists($pdo, 'santri', 'no_ranjang')) {
        try {
            $pdo->exec('ALTER TABLE santri MODIFY COLUMN no_ranjang VARCHAR(80) NULL');
        } catch (Throwable $e) {
            // abaikan
        }
    }

    if (column_exists($pdo, 'santri', 'wali_santri_id')) {
        try {
            $pdo->exec('CREATE INDEX idx_santri_wali_santri ON santri (wali_santri_id)');
        } catch (PDOException $e) {
            $m = strtolower($e->getMessage());
            if (!str_contains($m, 'duplicate') && !str_contains($m, '1061') && !str_contains($m, 'exists')) {
                throw $e;
            }
        }
    }

    if (function_exists('santri_status_migrate_legacy')) {
        require_once __DIR__ . '/santri_status.php';
        santri_status_migrate_legacy($pdo);
    }
}

function kelas_keuangan_kode_preference_rank(string $kode): int
{
    $k = strtoupper(trim($kode));
    if ($k === '') {
        return 0;
    }
    if (preg_match('/^\d+$/', $k)) {
        return 1;
    }
    if (strlen($k) <= 2) {
        return 2;
    }

    return 10;
}

/** Gabungkan entri lama (kode 1/2/3) ke MUAD/WUSTO/ULYA dan hapus duplikat per tarif. */
function kelas_keuangan_cleanup_duplicate_rows(PDO $pdo): void
{
    static $cleaned = false;
    if ($cleaned) {
        return;
    }
    $cleaned = true;

    $canonicalSeed = [
        ['MUAD', 'Muadalah', 'muadalah', 1],
        ['WUSTO', 'Wustho', 'wustho', 2],
        ['ULYA', 'Ulya', 'ulya', 3],
    ];
    $ins = $pdo->prepare('INSERT IGNORE INTO kelas_keuangan (kode, nama_tampilan, tarif_keuangan_tier, urutan, is_aktif) VALUES (:k, :n, :t, :u, 1)');
    foreach ($canonicalSeed as $s) {
        $ins->execute(['k' => $s[0], 'n' => $s[1], 't' => $s[2], 'u' => $s[3]]);
    }

    $legacyToCanonical = ['1' => 'MUAD', '2' => 'WUSTO', '3' => 'ULYA'];
    $hasSantriKat = column_exists($pdo, 'santri', 'kategori_kelas');
    foreach ($legacyToCanonical as $oldKode => $newKode) {
        $canon = $pdo->prepare('SELECT id FROM kelas_keuangan WHERE UPPER(TRIM(kode)) = :k LIMIT 1');
        $canon->execute(['k' => $newKode]);
        $canonId = (int) ($canon->fetchColumn() ?: 0);
        if ($canonId <= 0) {
            continue;
        }
        if ($hasSantriKat) {
            $pdo->prepare('UPDATE santri SET kategori_kelas = :baru WHERE UPPER(TRIM(kategori_kelas)) = :lama')
                ->execute(['baru' => $newKode, 'lama' => strtoupper($oldKode)]);
        }
        $pdo->prepare('DELETE FROM kelas_keuangan WHERE UPPER(TRIM(kode)) = :lama AND id <> :id')
            ->execute(['lama' => strtoupper($oldKode), 'id' => $canonId]);
    }

    $rows = $pdo->query('SELECT id, kode, tarif_keuangan_tier FROM kelas_keuangan WHERE is_aktif = 1 ORDER BY urutan ASC, id ASC')
        ->fetchAll(PDO::FETCH_ASSOC) ?: [];
    /** @var array<string, array{id:int,kode:string,tarif_keuangan_tier:string}> $bestByTier */
    $bestByTier = [];
    foreach ($rows as $row) {
        $tier = strtolower(trim((string) ($row['tarif_keuangan_tier'] ?? 'wustho')));
        if (!in_array($tier, ['muadalah', 'wustho', 'ulya'], true)) {
            $tier = 'wustho';
        }
        $kode = strtoupper(trim((string) ($row['kode'] ?? '')));
        if (!isset($bestByTier[$tier])) {
            $bestByTier[$tier] = ['id' => (int) $row['id'], 'kode' => $kode, 'tarif_keuangan_tier' => $tier];
            continue;
        }
        $keep = $bestByTier[$tier];
        $keepRank = kelas_keuangan_kode_preference_rank($keep['kode']);
        $rowRank = kelas_keuangan_kode_preference_rank($kode);
        if ($rowRank > $keepRank) {
            if ($hasSantriKat && $keep['kode'] !== $kode) {
                $pdo->prepare('UPDATE santri SET kategori_kelas = :baru WHERE UPPER(TRIM(kategori_kelas)) = :lama')
                    ->execute(['baru' => $kode, 'lama' => $keep['kode']]);
            }
            $pdo->prepare('DELETE FROM kelas_keuangan WHERE id = :id')->execute(['id' => $keep['id']]);
            $bestByTier[$tier] = ['id' => (int) $row['id'], 'kode' => $kode, 'tarif_keuangan_tier' => $tier];
        } else {
            if ($hasSantriKat && $keep['kode'] !== $kode) {
                $pdo->prepare('UPDATE santri SET kategori_kelas = :baru WHERE UPPER(TRIM(kategori_kelas)) = :lama')
                    ->execute(['baru' => $keep['kode'], 'lama' => $kode]);
            }
            $pdo->prepare('DELETE FROM kelas_keuangan WHERE id = :id')->execute(['id' => (int) $row['id']]);
        }
    }
}

function ensure_kelas_keuangan_table(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS kelas_keuangan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kode VARCHAR(40) NOT NULL,
            nama_tampilan VARCHAR(120) NOT NULL,
            tarif_keuangan_tier VARCHAR(20) NOT NULL DEFAULT \'wustho\',
            urutan INT NOT NULL DEFAULT 0,
            is_aktif TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_kelas_keuangan_kode (kode)
        )
    ');
    $cnt = (int) $pdo->query('SELECT COUNT(*) FROM kelas_keuangan')->fetchColumn();
    if ($cnt === 0) {
        $seed = [
            ['MUAD', 'Muadalah', 'muadalah', 1],
            ['WUSTO', 'Wustho', 'wustho', 2],
            ['ULYA', 'Ulya', 'ulya', 3],
        ];
        $ins = $pdo->prepare('INSERT INTO kelas_keuangan (kode, nama_tampilan, tarif_keuangan_tier, urutan, is_aktif) VALUES (:k, :n, :t, :u, 1)');
        foreach ($seed as $s) {
            $ins->execute(['k' => $s[0], 'n' => $s[1], 't' => $s[2], 'u' => $s[3]]);
        }
    }
    kelas_keuangan_cleanup_duplicate_rows($pdo);
}

/** @return list<array{id:int,kode:string,nama_tampilan:string,tarif_keuangan_tier:string,urutan:int,is_aktif:int}> */
function kelas_keuangan_all_rows(PDO $pdo): array
{
    ensure_kelas_keuangan_table($pdo);
    kelas_keuangan_cleanup_duplicate_rows($pdo);

    return $pdo->query('SELECT id, kode, nama_tampilan, tarif_keuangan_tier, urutan, is_aktif FROM kelas_keuangan ORDER BY urutan ASC, nama_tampilan ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<array{id:int,kode:string,nama_tampilan:string,tarif_keuangan_tier:string,urutan:int}> */
function kelas_keuangan_list_active(PDO $pdo): array
{
    ensure_kelas_keuangan_table($pdo);
    kelas_keuangan_cleanup_duplicate_rows($pdo);

    return $pdo->query('SELECT id, kode, nama_tampilan, tarif_keuangan_tier, urutan FROM kelas_keuangan WHERE is_aktif = 1 ORDER BY urutan ASC, nama_tampilan ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Samakan input (kode atau nama tampilan persis) ke kode master, tanpa cek is_aktif. */
function kelas_keuangan_resolve_kode(PDO $pdo, string $raw): ?string
{
    ensure_kelas_keuangan_table($pdo);
    $t = trim($raw);
    if ($t === '') {
        return null;
    }
    $u = strtoupper($t);
    $st = $pdo->prepare('SELECT kode FROM kelas_keuangan WHERE UPPER(TRIM(kode)) = :u LIMIT 1');
    $st->execute(['u' => $u]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($row) && isset($row['kode'])) {
        return strtoupper(trim((string) $row['kode']));
    }
    $st2 = $pdo->prepare('SELECT kode FROM kelas_keuangan WHERE UPPER(TRIM(nama_tampilan)) = :u LIMIT 1');
    $st2->execute(['u' => $u]);
    $row2 = $st2->fetch(PDO::FETCH_ASSOC);
    if (is_array($row2) && isset($row2['kode'])) {
        return strtoupper(trim((string) $row2['kode']));
    }

    return null;
}

function santri_normalize_kategori_kelas(PDO $pdo, string $raw): string
{
    ensure_kelas_keuangan_table($pdo);
    $resolved = kelas_keuangan_resolve_kode($pdo, $raw);
    if ($resolved === null) {
        return '';
    }
    $st = $pdo->prepare('SELECT kode FROM kelas_keuangan WHERE UPPER(TRIM(kode)) = :k AND is_aktif = 1 LIMIT 1');
    $st->execute(['k' => $resolved]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($row) && isset($row['kode'])) {
        return strtoupper(trim((string) $row['kode']));
    }

    return '';
}

function kelas_keuangan_label_for_kode(PDO $pdo, string $kode): string
{
    $k = strtoupper(trim($kode));
    if ($k === '') {
        return '';
    }
    ensure_kelas_keuangan_table($pdo);
    $st = $pdo->prepare('SELECT nama_tampilan FROM kelas_keuangan WHERE UPPER(TRIM(kode)) = :k LIMIT 1');
    $st->execute(['k' => $k]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($row) && trim((string) ($row['nama_tampilan'] ?? '')) !== '') {
        return trim((string) $row['nama_tampilan']);
    }
    $resolved = kelas_keuangan_resolve_kode($pdo, $kode);
    if ($resolved !== null && $resolved !== $k) {
        $st2 = $pdo->prepare('SELECT nama_tampilan FROM kelas_keuangan WHERE UPPER(TRIM(kode)) = :k LIMIT 1');
        $st2->execute(['k' => $resolved]);
        $row2 = $st2->fetch(PDO::FETCH_ASSOC);
        if (is_array($row2) && trim((string) ($row2['nama_tampilan'] ?? '')) !== '') {
            return trim((string) $row2['nama_tampilan']);
        }
    }

    return $k;
}

function keuangan_tier_key_from_kelas_heuristic(string $kelasKategori): string
{
    $t = strtolower(trim($kelasKategori));
    if ($t === '') {
        return 'wustho';
    }
    if (str_contains($t, 'muadalah') || str_contains($t, 'muad') || str_contains($t, 'ula') || str_contains($t, 'mts') || str_contains($t, 'smp') || $t === 'm') {
        return 'muadalah';
    }
    if (str_contains($t, 'wustho') || str_contains($t, 'wusto') || $t === 'w') {
        return 'wustho';
    }
    if (str_contains($t, 'ulya') || str_contains($t, 'uly') || str_contains($t, 'aliyah') || str_contains($t, 'ma') || str_contains($t, 'sma') || str_contains($t, 'smk') || $t === 'u') {
        return 'ulya';
    }
    return 'wustho';
}

function keuangan_tier_key_from_kelas(string $kelasKategori, ?PDO $pdo = null): string
{
    $rawIn = trim($kelasKategori);
    if ($pdo !== null) {
        $resolved = kelas_keuangan_resolve_kode($pdo, $rawIn);
        if ($resolved !== null) {
            $rawIn = $resolved;
        }
    }
    $normKey = strtoupper($rawIn);
    if ($normKey === '') {
        return keuangan_tier_key_from_kelas_heuristic('');
    }
    if ($pdo !== null) {
        ensure_kelas_keuangan_table($pdo);
        static $cache = [];
        if (!array_key_exists($normKey, $cache)) {
            $st = $pdo->prepare('SELECT tarif_keuangan_tier FROM kelas_keuangan WHERE UPPER(TRIM(kode)) = :k LIMIT 1');
            $st->execute(['k' => $normKey]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && isset($row['tarif_keuangan_tier'])) {
                $t = strtolower(trim((string) $row['tarif_keuangan_tier']));
                if (in_array($t, ['muadalah', 'wustho', 'ulya'], true)) {
                    $cache[$normKey] = $t;
                }
            }
            if (!array_key_exists($normKey, $cache)) {
                $cache[$normKey] = keuangan_tier_key_from_kelas_heuristic($kelasKategori);
            }
        }
        return $cache[$normKey];
    }
    return keuangan_tier_key_from_kelas_heuristic($kelasKategori);
}

/** Kolom grid biaya di halaman keuangan: 2=muadalah, 3=wustho, 4=ulya */
function keuangan_fee_grid_column_for_tier(string $tierKey): int
{
    return match ($tierKey) {
        'muadalah' => 2,
        'ulya' => 4,
        default => 3,
    };
}

function keuangan_monthly_bill_components(PDO $pdo, string $kelasKategori): array
{
    $defs = [
        ['slug' => 'syahriyah', 'nama' => 'Syahriyah', 'default' => ['muadalah' => 200000, 'wustho' => 210000, 'ulya' => 215000]],
        ['slug' => 'makan', 'nama' => 'Makan', 'default' => ['muadalah' => 220000, 'wustho' => 220000, 'ulya' => 220000]],
        ['slug' => 'saku', 'nama' => 'Saku', 'default' => ['muadalah' => 300000, 'wustho' => 300000, 'ulya' => 300000]],
    ];
    $tier = keuangan_tier_key_from_kelas($kelasKategori, $pdo);
    $out = [];
    foreach ($defs as $def) {
        $fallback = (int) ($def['default'][$tier] ?? 0);
        $nominal = (int) app_setting($pdo, 'keuangan_fee_' . $def['slug'] . '_' . $tier, (string) $fallback);
        $out[] = [
            'slug' => $def['slug'],
            'nama' => $def['nama'],
            'nominal' => max(0, $nominal),
        ];
    }
    return $out;
}

/** Nominal Syahriyah bulanan untuk santri (sesuai tier tarif di pengaturan keuangan). */
function syahriyah_expected_nominal_for_santri(PDO $pdo, string $kelasKategori): int
{
    foreach (keuangan_monthly_bill_components($pdo, $kelasKategori) as $c) {
        if (($c['slug'] ?? '') === 'syahriyah') {
            return max(0, (int) ($c['nominal'] ?? 0));
        }
    }

    return 0;
}

/** Jumlah pos Syahriyah yang sudah tercatat di pembayaran bulanan untuk bulan & tahun ajaran tertentu. */
function syahriyah_paid_nominal_for_month(PDO $pdo, int $santriId, int $bulanTagihan, int $tahunAjaranMulai, int $tahunAjaranSelesai): int
{
    if ($santriId <= 0 || $bulanTagihan < 1 || $bulanTagihan > 12) {
        return 0;
    }
    if (!table_exists($pdo, 'keuangan_pembayaran') || !table_exists($pdo, 'keuangan_pembayaran_detail')) {
        return 0;
    }

    $stmt = $pdo->prepare('
        SELECT COALESCE(SUM(d.nominal), 0)
        FROM keuangan_pembayaran_detail d
        INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
        WHERE p.santri_id = :sid
          AND p.jenis_periode = \'BULANAN\'
          AND p.bulan_tagihan = :bulan
          AND p.tahun_ajaran_mulai = :tm
          AND p.tahun_ajaran_selesai = :ts
          AND d.pos_slug = \'syahriyah\'
    ');
    $stmt->execute([
        'sid' => $santriId,
        'bulan' => $bulanTagihan,
        'tm' => $tahunAjaranMulai,
        'ts' => $tahunAjaranSelesai,
    ]);

    return (int) ((float) ($stmt->fetchColumn() ?: 0));
}

/** Pos bulanan yang menimbulkan tagihan jika belum dibayar (bukan saku). */
function keuangan_tagihan_wajib_slugs(): array
{
    return ['syahriyah', 'makan'];
}

/**
 * @return list<array{slug:string,nama:string,nominal:int}>
 */
function keuangan_tagihan_wajib_components(PDO $pdo, string $kelasKategori): array
{
    $slugs = keuangan_tagihan_wajib_slugs();
    $out = [];
    foreach (keuangan_monthly_bill_components($pdo, $kelasKategori) as $c) {
        if (in_array((string) ($c['slug'] ?? ''), $slugs, true)) {
            $out[] = $c;
        }
    }

    return $out;
}

function tagihan_expected_nominal_for_pos(PDO $pdo, string $kelasKategori, string $posSlug): int
{
    foreach (keuangan_monthly_bill_components($pdo, $kelasKategori) as $c) {
        if (($c['slug'] ?? '') === $posSlug) {
            return max(0, (int) ($c['nominal'] ?? 0));
        }
    }

    return 0;
}

function tagihan_paid_nominal_for_pos_month(PDO $pdo, int $santriId, int $bulanTagihan, int $tahunAjaranMulai, int $tahunAjaranSelesai, string $posSlug): int
{
    if ($santriId <= 0 || $bulanTagihan < 1 || $bulanTagihan > 12 || $posSlug === '') {
        return 0;
    }
    if (!table_exists($pdo, 'keuangan_pembayaran') || !table_exists($pdo, 'keuangan_pembayaran_detail')) {
        return 0;
    }

    $stmt = $pdo->prepare('
        SELECT COALESCE(SUM(d.nominal), 0)
        FROM keuangan_pembayaran_detail d
        INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
        WHERE p.santri_id = :sid
          AND p.jenis_periode = \'BULANAN\'
          AND p.bulan_tagihan = :bulan
          AND p.tahun_ajaran_mulai = :tm
          AND p.tahun_ajaran_selesai = :ts
          AND d.pos_slug = :pos_slug
    ');
    $stmt->execute([
        'sid' => $santriId,
        'bulan' => $bulanTagihan,
        'tm' => $tahunAjaranMulai,
        'ts' => $tahunAjaranSelesai,
        'pos_slug' => $posSlug,
    ]);

    return (int) ((float) ($stmt->fetchColumn() ?: 0));
}

function tagihan_wajib_total_expected(PDO $pdo, string $kelasKategori): int
{
    $total = 0;
    foreach (keuangan_tagihan_wajib_components($pdo, $kelasKategori) as $c) {
        $total += (int) ($c['nominal'] ?? 0);
    }

    return $total;
}

function tagihan_wajib_paid_for_month(PDO $pdo, int $santriId, int $bulanTagihan, int $tahunAjaranMulai, int $tahunAjaranSelesai): int
{
    $paid = 0;
    foreach (keuangan_tagihan_wajib_slugs() as $slug) {
        $paid += tagihan_paid_nominal_for_pos_month($pdo, $santriId, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai, $slug);
    }

    return $paid;
}

/**
 * @return array{
 *   expected_total:int,
 *   paid_total:int,
 *   sisa_total:int,
 *   status:string,
 *   statusClass:string,
 *   per_pos:array<string,array{expected:int,paid:int,sisa:int,status:string,statusClass:string}>
 * }
 */
function tagihan_wajib_status_for_month(PDO $pdo, int $santriId, int $bulanTagihan, int $tahunAjaranMulai, int $tahunAjaranSelesai, string $kelasKategori): array
{
    $perPos = [];
    $expectedTotal = 0;
    $paidTotal = 0;
    $sisaTotal = 0;
    $allLunas = true;
    $anyPaid = false;
    $anyExpected = false;

    if (!function_exists('keuangan_syahriyah_expected_dengan_potongan')) {
        require_once __DIR__ . '/keuangan_syahriyah_potongan.php';
    }

    foreach (keuangan_tagihan_wajib_components($pdo, $kelasKategori) as $c) {
        $slug = (string) ($c['slug'] ?? '');
        $expected = max(0, (int) ($c['nominal'] ?? 0));
        $expectedDasar = $expected;
        $persenPotongan = 0.0;
        $keteranganPotongan = '';
        $potonganNominal = 0;
        $potonganDijeda = false;
        if ($slug === 'syahriyah' && $santriId > 0) {
            $syPot = keuangan_syahriyah_expected_dengan_potongan(
                $pdo,
                $santriId,
                $kelasKategori,
                $bulanTagihan,
                $tahunAjaranMulai,
                $tahunAjaranSelesai
            );
            $expectedDasar = (int) ($syPot['expected_dasar'] ?? $expected);
            $expected = (int) ($syPot['expected'] ?? $expected);
            $persenPotongan = (float) ($syPot['persen'] ?? 0);
            $keteranganPotongan = (string) ($syPot['keterangan'] ?? '');
            $potonganNominal = (int) ($syPot['potongan_nominal'] ?? 0);
            $potonganDijeda = !empty($syPot['potongan_dijeda']);
        }
        $paid = $santriId > 0
            ? tagihan_paid_nominal_for_pos_month($pdo, $santriId, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai, $slug)
            : 0;
        $sisa = max(0, $expected - $paid);
        if ($expected > 0) {
            $anyExpected = true;
        }
        if ($paid > 0) {
            $anyPaid = true;
        }
        if ($expected > 0 && $paid < $expected) {
            $allLunas = false;
        }
        if ($expected <= 0) {
            $st = '—';
            $stClass = 'secondary';
        } elseif ($paid >= $expected) {
            $st = 'Lunas';
            $stClass = 'success';
        } elseif ($paid <= 0) {
            $st = 'Belum';
            $stClass = 'danger';
        } else {
            $st = 'Sebagian';
            $stClass = 'warning';
        }
        $perPos[$slug] = [
            'expected' => $expected,
            'expected_dasar' => $expectedDasar,
            'persen_potongan' => $persenPotongan,
            'keterangan_potongan' => $keteranganPotongan,
            'potongan_nominal' => $potonganNominal,
            'potongan_dijeda' => $potonganDijeda,
            'paid' => $paid,
            'sisa' => $sisa,
            'status' => $st,
            'statusClass' => $stClass,
        ];
        $expectedTotal += $expected;
        $paidTotal += $paid;
        $sisaTotal += $sisa;
    }

    if (!$anyExpected) {
        $status = '—';
        $statusClass = 'secondary';
    } elseif ($allLunas && $expectedTotal > 0) {
        $status = 'Lunas';
        $statusClass = 'success';
    } elseif (!$anyPaid) {
        $status = 'Belum';
        $statusClass = 'danger';
    } else {
        $status = 'Sebagian';
        $statusClass = 'warning';
    }

    return [
        'expected_total' => $expectedTotal,
        'paid_total' => $paidTotal,
        'sisa_total' => $sisaTotal,
        'status' => $status,
        'statusClass' => $statusClass,
        'per_pos' => $perPos,
    ];
}

function trigger_auto_wa_tagihan_wali(PDO $pdo): void
{
    if (!function_exists('keuangan_tahun_ajaran_aktif')) {
        require_once __DIR__ . '/keuangan_transaksi.php';
    }
    if (!table_exists($pdo, 'app_settings') || !table_exists($pdo, 'santri') || !table_exists($pdo, 'keuangan_pembayaran')) {
        return;
    }
    ensure_santri_identity_columns($pdo);
    if (!column_exists($pdo, 'santri', 'no_wa_wali')) {
        return;
    }
    if (trim((string) app_setting($pdo, 'wa_tagihan_auto_enabled', '0')) !== '1') {
        return;
    }
    $calendarMode = strtoupper(trim((string) app_setting($pdo, 'wa_tagihan_calendar', 'MASEHI')));
    if (!in_array($calendarMode, ['MASEHI', 'HIJRIYAH'], true)) {
        $calendarMode = 'MASEHI';
    }
    $dueDay = (int) app_setting($pdo, 'wa_tagihan_day', '5');
    $dueDay = max(1, min(30, $dueDay));
    $sendTime = trim((string) app_setting($pdo, 'wa_tagihan_send_time', '08:00'));
    if ($sendTime !== '' && preg_match('/^\d{2}:\d{2}$/', $sendTime) && date('H:i') < $sendTime) {
        return;
    }

    $today = date('Y-m-d');
    $todayDay = (int) date('j');
    $periodKey = date('Y-m');
    if ($calendarMode === 'HIJRIYAH') {
        $periodKey = get_hijri_year_month($today);
        if (class_exists('IntlDateFormatter')) {
            $fmtDay = new IntlDateFormatter(
                islamic_calendar_locale(),
                IntlDateFormatter::NONE,
                IntlDateFormatter::NONE,
                date_default_timezone_get(),
                IntlDateFormatter::TRADITIONAL,
                'd'
            );
            $hDay = $fmtDay->format(strtotime($today));
            if (is_string($hDay) && ctype_digit($hDay)) {
                $todayDay = (int) $hDay;
            }
        }
    }
    if ($todayDay !== $dueDay) {
        return;
    }

    $lastSent = trim((string) app_setting($pdo, 'wa_tagihan_last_period_key', ''));
    if ($lastSent === $calendarMode . ':' . $periodKey) {
        return;
    }

    $nameExpr = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $classExpr = column_exists($pdo, 'santri', 'kategori_kelas') ? 'kategori_kelas' : (column_exists($pdo, 'santri', 'tingkatan') ? 'tingkatan' : "''");
    $activeExpr = column_exists($pdo, 'santri', 'is_aktif') ? ' AND is_aktif = 1 ' : '';
    $stmt = $pdo->query('SELECT id, nis, ' . $nameExpr . ' AS nama_santri, ' . $classExpr . ' AS kategori_kelas, no_wa_wali FROM santri WHERE COALESCE(no_wa_wali, "") <> "" ' . $activeExpr . ' ORDER BY id ASC LIMIT 300');
    $santriRows = $stmt ? $stmt->fetchAll() : [];
    if (!$santriRows) {
        return;
    }

    $bulan = (int) date('n');
    $periodeTa = keuangan_tahun_ajaran_aktif($pdo);
    $tahunAjaranMulai = (int) ($periodeTa['mulai'] ?? 0);
    $tahunAjaranSelesai = (int) ($periodeTa['selesai'] ?? 0);
    $sentCount = 0;
    foreach ($santriRows as $row) {
        $santriId = (int) ($row['id'] ?? 0);
        if ($santriId <= 0) {
            continue;
        }
        $kelas = trim((string) ($row['kategori_kelas'] ?? ''));
        $components = keuangan_tagihan_wajib_components($pdo, $kelas);
        if ($components === []) {
            continue;
        }
        $st = tagihan_wajib_status_for_month($pdo, $santriId, $bulan, $tahunAjaranMulai, $tahunAjaranSelesai, $kelas);
        $sisa = (int) ($st['sisa_total'] ?? 0);
        if ($sisa <= 0) {
            continue;
        }

        $nama = trim((string) ($row['nama_santri'] ?? 'Santri'));
        $labelKekurangan = wa_tagihan_label_kekurangan($components, $st['per_pos'] ?? []);
        $message = wa_format_tagihan_otomatis_wali($pdo, $nama, $labelKekurangan, $sisa);
        if (send_wa_message($pdo, (string) ($row['no_wa_wali'] ?? ''), $message)) {
            $sentCount++;
        }
    }

    if ($sentCount > 0) {
        save_setting($pdo, 'wa_tagihan_last_period_key', $calendarMode . ':' . $periodKey);
        save_setting($pdo, 'wa_tagihan_last_sent_at', date('Y-m-d H:i:s'));
    }
}

function sync_daily_presence_for_tingkatan(PDO $pdo, string $tanggal, string $tingkatan, ?int $kegiatanId, int $createdBy): void
{
    if ($tingkatan === '' || !table_exists($pdo, 'presensi') || !table_exists($pdo, 'perizinan')) {
        return;
    }

    $santriStmt = $pdo->prepare('SELECT id FROM santri WHERE tingkatan = :tingkatan AND is_aktif = 1');
    $santriStmt->execute(['tingkatan' => $tingkatan]);
    $santriIds = array_map('intval', $santriStmt->fetchAll(PDO::FETCH_COLUMN));
    if (!$santriIds) {
        return;
    }

    require_once __DIR__ . '/akademik.php';
    $hijri = akademik_hijri_ym_untuk_masehi($pdo, $tanggal);
    $jam = date('H:i:s');
    $approvalFilter = '';
    if (table_exists($pdo, 'perizinan') && column_exists($pdo, 'perizinan', 'approval_status')) {
        $approvalFilter = ' AND approval_status = "DISETUJUI"';
    }
    $izinStmt = $pdo->prepare('
        SELECT santri_id, jenis_izin
        FROM perizinan
        WHERE status_izin = "IZIN"
          AND :tanggal_now BETWEEN tanggal_mulai AND tanggal_selesai' . $approvalFilter . '
    ');
    $izinStmt->execute(['tanggal_now' => $tanggal]);
    $izinMap = [];
    foreach ($izinStmt->fetchAll() as $izinRow) {
        $izinMap[(int) $izinRow['santri_id']] = (string) $izinRow['jenis_izin'];
    }

    if (!function_exists('santri_izin_tetap_berlaku')) {
        require_once __DIR__ . '/santri_izin_tetap.php';
    }
    $jamMulaiKeg = null;
    $jamSelesaiKeg = null;
    if ($kegiatanId > 0 && table_exists($pdo, 'jadwal_kegiatan')) {
        $hariKe = (int) date('N', strtotime($tanggal));
        $jadwalStmt = $pdo->prepare('
            SELECT jam_mulai, jam_selesai FROM jadwal_kegiatan
            WHERE kegiatan_id = :kid AND hari_ke = :hari
            ORDER BY jam_mulai ASC LIMIT 1
        ');
        $jadwalStmt->execute(['kid' => $kegiatanId, 'hari' => $hariKe]);
        $jadwalRow = $jadwalStmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($jadwalRow)) {
            $jamMulaiKeg = (string) ($jadwalRow['jam_mulai'] ?? null);
            $jamSelesaiKeg = (string) ($jadwalRow['jam_selesai'] ?? null);
        }
    }

    $existingStmt = $pdo->prepare('
        SELECT id, status_presensi
        FROM presensi
        WHERE santri_id = :santri_id
          AND tanggal_presensi = :tanggal_presensi
          AND (
                (:kegiatan_id IS NULL AND kegiatan_id IS NULL)
                OR kegiatan_id = :kegiatan_id
          )
        ORDER BY id DESC
        LIMIT 1
    ');
    $insertStmt = $pdo->prepare('
        INSERT INTO presensi (santri_id, kegiatan_id, tanggal_presensi, jam_presensi, status_presensi, kalender_hijriyah, created_by)
        VALUES (:santri_id, :kegiatan_id, :tanggal_presensi, :jam_presensi, :status_presensi, :kalender_hijriyah, :created_by)
    ');
    $updateStmt = $pdo->prepare('
        UPDATE presensi
        SET status_presensi = :status_presensi, jam_presensi = :jam_presensi, kalender_hijriyah = :kalender_hijriyah, created_by = :created_by
        WHERE id = :id
    ');

    foreach ($santriIds as $santriId) {
        $desiredStatus = 'ALPA';
        if (isset($izinMap[$santriId])) {
            $desiredStatus = strtoupper((string) $izinMap[$santriId]) === 'SAKIT' ? 'SAKIT' : 'IZIN';
        } elseif (santri_izin_tetap_berlaku($pdo, $santriId, $tanggal, $jamMulaiKeg, $jamSelesaiKeg)) {
            $desiredStatus = 'IZIN';
        }

        $existingStmt->execute([
            'santri_id' => $santriId,
            'tanggal_presensi' => $tanggal,
            'kegiatan_id' => $kegiatanId,
        ]);
        $existing = $existingStmt->fetch();
        if ($existing && strtoupper((string) $existing['status_presensi']) === 'HADIR') {
            continue;
        }

        if (!$existing) {
            $insertStmt->execute([
                'santri_id' => $santriId,
                'kegiatan_id' => $kegiatanId,
                'tanggal_presensi' => $tanggal,
                'jam_presensi' => $jam,
                'status_presensi' => $desiredStatus,
                'kalender_hijriyah' => $hijri,
                'created_by' => $createdBy,
            ]);
            continue;
        }

        if (strtoupper((string) $existing['status_presensi']) !== $desiredStatus) {
            $updateStmt->execute([
                'id' => (int) $existing['id'],
                'status_presensi' => $desiredStatus,
                'jam_presensi' => $jam,
                'kalender_hijriyah' => $hijri,
                'created_by' => $createdBy,
            ]);
        }
    }
}

function sync_presence_for_active_schedules(PDO $pdo, string $tanggal, string $jam, int $createdBy): int
{
    if (!table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan')) {
        return 0;
    }

    $hariKe = (int) date('N', strtotime($tanggal));
    $stmt = $pdo->prepare('
        SELECT DISTINCT j.kegiatan_id, j.tingkatan
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        WHERE (j.hari_ke = 0 OR j.hari_ke = :hari_ke)
          AND :jam_now BETWEEN j.jam_mulai AND j.jam_selesai
          AND k.is_active = 1
    ');
    $stmt->execute([
        'hari_ke' => $hariKe,
        'jam_now' => $jam,
    ]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        return 0;
    }

    $synced = 0;
    foreach ($rows as $row) {
        $tingkatan = trim((string) ($row['tingkatan'] ?? ''));
        $kegiatanId = isset($row['kegiatan_id']) ? (int) $row['kegiatan_id'] : null;
        if ($tingkatan === '' || strtolower($tingkatan) === 'semua tingkatan') {
            if (!table_exists($pdo, 'tingkatan')) {
                continue;
            }
            $tingkatanList = $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY nama_tingkatan ASC')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($tingkatanList as $tg) {
                sync_daily_presence_for_tingkatan($pdo, $tanggal, (string) $tg, $kegiatanId, $createdBy);
                $synced++;
            }
            continue;
        }

        sync_daily_presence_for_tingkatan($pdo, $tanggal, $tingkatan, $kegiatanId, $createdBy);
        $synced++;
    }

    return $synced;
}

/**
 * Label jenis izin untuk tampilan (sama dengan opsi dropdown di perizinan).
 */
function jenis_izin_label(string $jenis): string
{
    return match (strtoupper(trim($jenis))) {
        'SAKIT' => 'Sakit',
        'KELUAR' => 'Keluar',
        'TUGAS' => 'Tugas',
        'PULANG' => 'Tugas',
        default => $jenis !== '' ? $jenis : 'Keluar',
    };
}

function wa_salam_pembuka(): string
{
    return "Assalamu'alaikum warahmatullahi wabarakatuh.";
}

/**
 * Daftar pos tagihan yang masih kurang (untuk teks WA/notifikasi).
 *
 * @param list<array{slug:string,nama:string,nominal:int}> $components
 * @param array<string, array{sisa?:int}> $perPos
 */
function wa_tagihan_label_kekurangan(array $components, array $perPos): string
{
    $parts = [];
    foreach ($components as $c) {
        $slug = (string) ($c['slug'] ?? '');
        $sisa = (int) (($perPos[$slug]['sisa'] ?? 0));
        if ($sisa <= 0) {
            continue;
        }
        $nama = trim((string) ($c['nama'] ?? $slug));
        if ($nama === '') {
            $nama = $slug;
        }
        $parts[] = $nama . ' (*Rp ' . number_format($sisa, 0, ',', '.') . '*)';
    }
    if ($parts === []) {
        return 'tagihan bulanan';
    }
    if (count($parts) === 1) {
        return $parts[0];
    }
    $last = array_pop($parts);

    return implode(', ', $parts) . ' dan ' . $last;
}

/** Teks WA otomatis tagihan kekurangan ke wali (bahasa Jawa sopan). */
function wa_format_tagihan_otomatis_wali(PDO $pdo, string $namaSantri, string $labelKekurangan, int $totalSisa): string
{
    $namaPonpes = app_brand_nama_ponpes($pdo, 'Pon-Pes A.P.I Nailul Muna');
    $nama = trim($namaSantri) !== '' ? trim($namaSantri) : 'santri';
    $totalFmt = '*Rp ' . number_format(max(0, $totalSisa), 0, ',', '.') . '*';

    return "Assalamu'alaikum Wr. Wb.\n"
        . 'Nyuwun pangapunten, kepareng matur dateng Bpk/Ibu wali saking *' . $nama . "*\n"
        . 'Atasnama Pengurus *' . $namaPonpes . '* bagian Pendidikan, memberitahukan bahwa putra Bapak/Ibu masih mempunyai kekurangan '
        . $labelKekurangan . ', dan jumlah total ' . $totalFmt . ".\n"
        . 'Berkenaan dengan hal tersebut, kami mohon maaf baru saat ini bisa melaporkan kepada Bapak/Ibu, dan atas pengertian dan kerjasamanya kami haturkan terimakasih🙏.';
}

/** Ringkasan tagihan untuk notifikasi push (tanpa markup tebal). */
function push_format_tagihan_otomatis_body(string $namaSantri, string $labelKekuranganPlain, int $totalSisa): string
{
    $nama = trim($namaSantri) !== '' ? trim($namaSantri) : 'santri';
    $plain = preg_replace('/\*([^*]+)\*/', '$1', $labelKekuranganPlain) ?? $labelKekuranganPlain;
    $totalFmt = 'Rp ' . number_format(max(0, $totalSisa), 0, ',', '.');

    return "Nyuwun pangapunten. Putra/Ibu {$nama} masih kekurangan {$plain}. Total: {$totalFmt}. Terima kasih 🙏";
}

function wa_kop_instansi(PDO $pdo): string
{
    $nama = trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren'));
    return '*' . $nama . '*' . "\n" . '_Manajemen Santri — notifikasi otomatis_';
}

/**
 * Rekap ALPA bulanan: kelompok per nama kegiatan.
 *
 * @param array<int, array{nama_kegiatan: string, nama_santri: string, tingkatan: string, nis: string, total_alpha: int|string}> $rows
 */
function wa_format_rekap_alpa_per_kegiatan(PDO $pdo, string $periodeLabel, int $ambang, array $rows): string
{
    if ($rows === []) {
        return '';
    }
    $byKegiatan = [];
    foreach ($rows as $row) {
        $kg = trim((string) ($row['nama_kegiatan'] ?? '')) !== '' ? (string) $row['nama_kegiatan'] : 'Tanpa kegiatan';
        $byKegiatan[$kg][] = $row;
    }
    ksort($byKegiatan, SORT_NATURAL);

    $body = wa_salam_pembuka() . "\n\n" . wa_kop_instansi($pdo) . "\n\n"
        . "*PEMBERITAHUAN RESMI*\n"
        . "Perihal: Rekapitulasi ketidakhadiran (*ALPA*)\n"
        . 'Periode data: ' . $periodeLabel . "\n"
        . 'Kriteria: jumlah ALPA ≥ *' . $ambang . "* per santri per kegiatan\n\n"
        . "Berikut daftar santri yang memenuhi kriteria, dikelompokkan menurut *kegiatan*:\n\n";

    foreach ($byKegiatan as $namaKegiatan => $items) {
        $body .= '▸ *' . $namaKegiatan . "*\n";
        foreach ($items as $item) {
            $nama = (string) ($item['nama_santri'] ?? '-');
            $nis = trim((string) ($item['nis'] ?? ''));
            $tg = trim((string) ($item['tingkatan'] ?? ''));
            $n = (int) ($item['total_alpha'] ?? 0);
            $body .= '   • ' . $nama;
            if ($nis !== '') {
                $body .= ' (NIS ' . $nis . ')';
            }
            $body .= ' — ' . ($tg !== '' ? $tg : '-');
            $body .= ': *' . $n . "* kali ALPA\n";
        }
        $body .= "\n";
    }

    $body .= "Mohon arahan dan tindak lanjut sesuai peraturan pesantren.\n"
        . "Demikian disampaikan.\n\n"
        . '_Hormat kami,_' . "\n"
        . '_Sistem Informasi_';

    return $body;
}

/**
 * Hasil generate ALPA massal (satu tanggal / satu tingkatan / satu konteks kegiatan).
 *
 * @param array<int, array{nama_santri: string, nis: string, total_alpha: int}> $santriList
 */
function wa_format_laporan_alpa_generate(PDO $pdo, string $tanggalIdn, string $tingkatan, string $namaKegiatan, int $ambang, array $santriList): string
{
    if ($santriList === []) {
        return '';
    }
    $body = wa_salam_pembuka() . "\n\n" . wa_kop_instansi($pdo) . "\n\n"
        . "*LAPORAN RESMI — PENCATATAN ALPA*\n\n"
        . 'Tanggal kegiatan: *' . $tanggalIdn . "*\n"
        . 'Tingkatan: *' . $tingkatan . "*\n"
        . 'Kegiatan: *' . $namaKegiatan . "*\n"
        . 'Ambang pemberitahuan bulan berjalan: *≥ ' . $ambang . "* kali ALPA\n\n"
        . "Santri berikut memenuhi ambang setelah pencatatan ini:\n\n";

    foreach ($santriList as $s) {
        $nama = (string) ($s['nama_santri'] ?? '-');
        $nis = trim((string) ($s['nis'] ?? ''));
        $n = (int) ($s['total_alpha'] ?? 0);
        $body .= '• ' . $nama;
        if ($nis !== '') {
            $body .= ' (NIS ' . $nis . ')';
        }
        $body .= ': *' . $n . "* kali ALPA (kumulatif bulan ini)\n";
    }

    $body .= "\nMohon ditindaklanjuti sesuai ketentuan.\n"
        . "Demikian laporan ini disampaikan.\n\n"
        . '_Hormat kami,_' . "\n"
        . '_Sistem Informasi_';

    return $body;
}

function wa_format_pengajuan_izin_baru(
    PDO $pdo,
    string $namaSantri,
    string $nis,
    string $tingkatan,
    string $jenisKode,
    string $tanggalMulai,
    string $tanggalSelesai,
    string $jamMulai,
    string $jamSelesai,
    string $alasan
): string {
    $jenis = jenis_izin_label($jenisKode);
    $nisT = trim($nis);
    $tgT = trim($tingkatan);

    $body = 'Ada pengajuan izin baru: ' . $namaSantri . ' - Alasan: ' . $alasan . "\n\n"
        . wa_salam_pembuka() . "\n\n" . wa_kop_instansi($pdo) . "\n\n"
        . "*PEMBERITAHUAN RESMI*\n"
        . "Perihal: Pengajuan perizinan santri (menunggu persetujuan)\n\n"
        . "Dengan hormat diinformasikan bahwa telah masuk permohonan izin dengan rincian:\n\n"
        . '• Nama santri: *' . $namaSantri . "*\n";

    if ($nisT !== '') {
        $body .= '• NIS: *' . $nisT . "*\n";
    }
    if ($tgT !== '') {
        $body .= '• Tingkatan: *' . $tgT . "*\n";
    }
    $body .= '• Jenis izin: *' . $jenis . "*\n"
        . '• Tanggal: *' . $tanggalMulai . '* s/d *' . $tanggalSelesai . "*\n"
        . '• Waktu: *' . $jamMulai . '* – *' . $jamSelesai . "*\n"
        . '• Ringkasan keperluan: _' . $alasan . "_\n\n"
        . "Mohon segera ditinjau melalui panel perizinan.\n"
        . "Demikian disampaikan.\n\n"
        . '_Hormat kami,_' . "\n"
        . '_Sistem Informasi_';

    return $body;
}

function wa_format_izin_disetujui_untuk_wali(
    PDO $pdo,
    string $namaSantri,
    string $jenisLabel,
    string $tanggalSelesai,
    string $jamSelesai,
    string $alasan
): string {
    $namaPonpes = trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren'));

    return wa_salam_pembuka() . "\n\n"
        . '*Yth. Wali santri ' . $namaSantri . '*' . "\n\n"
        . wa_kop_instansi($pdo) . "\n\n"
        . "*SURAT PEMBERITAHUAN (digital)*\n\n"
        . 'Dengan hormat kami sampaikan bahwa permohonan *' . $jenisLabel . '* atas nama putra/putri Anda *'
        . $namaSantri . '* telah *DISETUJUI* oleh pengurus *' . $namaPonpes . "*.\n\n"
        . "Rincian penting:\n"
        . '• Jadwal kembali ke pesantren: *' . $tanggalSelesai . '* pukul *' . $jamSelesai . "*\n"
        . '• Keterangan: _' . $alasan . "_\n\n"
        . "Mohon putra/putri Anda kembali tepat waktu sesuai ketentuan yang berlaku.\n"
        . "Atas perhatian dan kerja samanya disampaikan terima kasih.\n\n"
        . '*Wassalamu\'alaikum warahmatullahi wabarakatuh.*' . "\n"
        . '_' . $namaPonpes . '_';
}

function user_has_acl_permission_matrix(PDO $pdo): bool
{
    if (!isset($_SESSION['user'])) {
        return false;
    }
    // Akun virtual (mis. petugas presensi id=0) tidak punya baris ACL di users.
    if ((int) ($_SESSION['user']['id'] ?? 0) <= 0) {
        return false;
    }

    return (int) ($_SESSION['user']['is_super_admin'] ?? 0) !== 1
        && table_exists($pdo, 'user_access_permissions');
}

/**
 * @return array<string, int>|null null bila tidak ada filter ACL (semua item menu boleh)
 */
function get_allowed_permission_key_map(PDO $pdo): ?array
{
    if (!user_has_acl_permission_matrix($pdo)) {
        return null;
    }
    $userId = (int) ($_SESSION['user']['id'] ?? 0);
    if ($userId <= 0) {
        return [];
    }
    $allowedPermissions = $pdo->prepare('SELECT permission_key FROM user_access_permissions WHERE user_id = :user_id');
    $allowedPermissions->execute(['user_id' => $userId]);
    $allowedKeys = array_map('strval', $allowedPermissions->fetchAll(PDO::FETCH_COLUMN));

    return array_flip($allowedKeys);
}

function filter_menu_items_by_acl(PDO $pdo, array $menuItems, array $permissionPathMap): array
{
    $allowedMap = get_allowed_permission_key_map($pdo);
    if ($allowedMap === null) {
        return $menuItems;
    }

    return array_filter(
        $menuItems,
        static function (string $label, string $path) use ($permissionPathMap, $allowedMap): bool {
            if (!isset($permissionPathMap[$path])) {
                return true;
            }
            return isset($allowedMap[$permissionPathMap[$path]]);
        },
        ARRAY_FILTER_USE_BOTH
    );
}

function enforce_route_acl_or_redirect(PDO $pdo, string $requestPath, array $permissionPathMap): void
{
    $allowedMap = get_allowed_permission_key_map($pdo);
    if ($allowedMap === null) {
        return;
    }

    foreach ($permissionPathMap as $path => $permissionKey) {
        if (str_contains($requestPath, $path) && !isset($allowedMap[$permissionKey])) {
            set_flash('error', 'Anda tidak memiliki akses ke fitur ini. Hubungi admin super.');
            require_once __DIR__ . '/app_path.php';
            $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
            if ($role === 'petugas_absensi') {
                app_redirect('presensi/scan.php');
            }
            $fallbackPath = app_url('dashboard.php');
            foreach ($permissionPathMap as $candidatePath => $candidatePermission) {
                if (isset($allowedMap[$candidatePermission])) {
                    $fallbackPath = $candidatePath;
                    break;
                }
            }
            header('Location: ' . $fallbackPath);
            exit;
        }
    }
}

function settings_pengaturan_hub_url(): string
{
    return '/menu/menu_hub.php?id=menu-grp-pengaturan';
}

/**
 * @return list<array{path:string,label:string,icon:string}>
 */
function settings_pengaturan_nav_items(?PDO $pdo = null): array
{
    static $definitions = null;
    if (!is_array($definitions)) {
        $pack = require __DIR__ . '/../includes/menu_data.php';
        $definitions = is_array($pack['pengaturanNav'] ?? null) ? $pack['pengaturanNav'] : [];
    }

    if (!($pdo instanceof PDO)) {
        return $definitions;
    }

    $pack = require __DIR__ . '/../includes/menu_data.php';
    $menuItems = filter_menu_items_by_acl($pdo, $pack['menuItems'], $pack['permissionPathMap']);
    $out = [];
    foreach ($definitions as $item) {
        $path = (string) ($item['path'] ?? '');
        if ($path !== '' && isset($menuItems[$path])) {
            $out[] = [
                'path' => $path,
                'label' => (string) ($menuItems[$path] ?? ($item['label'] ?? '')),
                'icon' => (string) ($item['icon'] ?? 'fa-solid fa-circle'),
            ];
        }
    }
    return $out;
}

function menu_tile_icon_for_path(string $path): string
{
    $exactIcons = [
        '/settings/pesantren.php' => 'fa-solid fa-mosque',
        '/settings/peraturan.php' => 'fa-solid fa-scale-balanced',
        '/settings/kalender.php' => 'fa-solid fa-calendar-days',
        '/settings/tingkatan.php' => 'fa-solid fa-layer-group',
        '/settings/kamar_ranjang.php' => 'fa-solid fa-bed',
        '/settings/kelas_ruangan.php' => 'fa-solid fa-door-open',
        '/settings/kelas_keuangan.php' => 'fa-solid fa-coins',
        '/settings/admin.php' => 'fa-solid fa-user-shield',
        '/settings/push.php' => 'fa-solid fa-bell',
        '/pembayaran/rekap_pos.php' => 'fa-solid fa-chart-pie',
        '/settings/hijri_mappings.php' => 'fa-solid fa-moon',
    ];
    if (isset($exactIcons[$path])) {
        return $exactIcons[$path];
    }
    if (str_contains($path, 'dashboard')) {
        return 'fa-solid fa-house';
    }
    if (str_contains($path, 'santri')) {
        return 'fa-solid fa-user-group';
    }
    if (str_contains($path, 'wali')) {
        return 'fa-solid fa-people-roof';
    }
    if (str_contains($path, 'pembimbing')) {
        return 'fa-solid fa-chalkboard-user';
    }
    if (str_contains($path, 'presensi')) {
        return 'fa-solid fa-qrcode';
    }
    if (str_contains($path, 'jadwal')) {
        return 'fa-solid fa-calendar-days';
    }
    if (str_contains($path, 'akademik')) {
        return 'fa-solid fa-book';
    }
    if (str_contains($path, 'perizinan')) {
        return 'fa-solid fa-person-walking-arrow-right';
    }
    if (str_contains($path, 'admin')) {
        return 'fa-solid fa-file-lines';
    }
    if (str_contains($path, 'poin')) {
        return 'fa-solid fa-star';
    }
    if (str_contains($path, 'keuangan') || str_contains($path, 'pembayaran')) {
        return 'fa-solid fa-wallet';
    }
    if (str_contains($path, 'rekap')) {
        return 'fa-solid fa-chart-column';
    }
    if (str_contains($path, 'settings')) {
        return 'fa-solid fa-gear';
    }
    return 'fa-solid fa-arrow-right';
}

/**
 * @param array<string, mixed> $node
 * @return list<string>
 */
function menu_group_collect_paths(array $node): array
{
    $sections = $node['sections'] ?? null;
    $out = [];
    if (is_array($sections) && $sections !== []) {
        foreach ($sections as $sec) {
            foreach ((array) ($sec['paths'] ?? []) as $p) {
                if (is_string($p) && $p !== '') {
                    $out[] = $p;
                }
            }
        }
    } else {
        foreach ((array) ($node['paths'] ?? []) as $p) {
            if (is_string($p) && $p !== '') {
                $out[] = $p;
            }
        }
    }
    return $out;
}

/**
 * @param array<string, mixed> $node
 */
function menu_group_visible_paths(array $node, array $menuItems): array
{
    return array_values(array_filter(
        menu_group_collect_paths($node),
        static fn(string $p): bool => array_key_exists($p, $menuItems)
    ));
}

/**
 * @param array<string, mixed> $node
 */
function menu_sidebar_group_is_active(array $node, string $requestPath, array $menuItems): bool
{
    $hubId = (string) ($node['id'] ?? '');
    if ($hubId !== '' && str_contains($requestPath, '/menu/menu_hub.php')) {
        $qid = isset($_GET['id']) ? (string) $_GET['id'] : '';
        if ($qid === $hubId) {
            return true;
        }
    }
    foreach (menu_group_visible_paths($node, $menuItems) as $cp) {
        if (str_contains($requestPath, $cp)) {
            return true;
        }
    }
    return false;
}
