<?php

declare(strict_types=1);

/**
 * Lapisan terpusat pengiriman WA otomatis (personal & grup Fonte).
 * Dipakai oleh send_wa_message_with_result / send_wa_bulk di helpers/app.php.
 */

/** Normalisasi target: nomor personal Indonesia atau ID grup WhatsApp / Fonte. */
function wa_otomatis_normalize_target(string $raw): string
{
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    $compact = preg_replace('/\s+/', '', $raw) ?? $raw;
    if (preg_match('/^[\d-]+@g\.us$/i', $compact)) {
        return strtolower($compact);
    }

    $digits = preg_replace('/[^0-9]/', '', $raw) ?? '';
    if ($digits === '') {
        return '';
    }

    if (wa_otomatis_is_group_digits($digits)) {
        return $digits;
    }

    if (strpos($digits, '0') === 0) {
        return '62' . substr($digits, 1);
    }
    if (strpos($digits, '8') === 0 && strlen($digits) <= 13) {
        return '62' . $digits;
    }

    return $digits;
}

/** Grup WA / Fonte biasanya ≥15 digit dan bukan format nomor HP 62… pendek. */
function wa_otomatis_is_group_digits(string $digits): bool
{
    $len = strlen($digits);
    if ($len < 15) {
        return false;
    }
    if (strpos($digits, '62') === 0 && $len <= 14) {
        return false;
    }

    return true;
}

function wa_otomatis_is_group_target(string $normalized): bool
{
    if (str_ends_with(strtolower($normalized), '@g.us')) {
        return true;
    }

    return wa_otomatis_is_group_digits($normalized);
}

/**
 * Fonnte connectOnly: true = tolak jika perangkat WA putus; false = antrekan sampai online.
 *
 * @param array<string, mixed> $override
 */
function wa_otomatis_fonnte_connect_only(PDO $pdo, array $override = []): bool
{
    if (array_key_exists('connect_only', $override)) {
        return (bool) $override['connect_only'];
    }

    return trim((string) app_setting($pdo, 'wa_fonnte_queue_offline', '0')) !== '1';
}

function wa_otomatis_enrich_api_error(string $error, string $target): string
{
    if ($error === '') {
        return '';
    }
    $low = strtolower($error);
    if (str_contains($low, 'disconnected device') || str_contains($low, 'device disconnected')) {
        return $error
            . ' — perangkat WA di Fonnte tidak terhubung. Buka dashboard Fonnte → Device → scan QR WhatsApp, '
            . 'atau aktifkan opsi "Antrekan saat perangkat offline" di tab Gateway.';
    }
    if (str_contains($low, 'input invalid') && wa_otomatis_is_group_target($target)) {
        return $error
            . ' — untuk grup: salin ID dari Fonnte (format …@g.us), update daftar grup, pastikan nomor WA perangkat masih anggota grup.';
    }

    return $error;
}

/** Format target untuk payload gateway (Fonnte grup wajib …@g.us). */
function wa_otomatis_format_target_for_payload(string $normalized, bool $isFonte): string
{
    if ($normalized === '') {
        return '';
    }
    if (str_ends_with(strtolower($normalized), '@g.us')) {
        return $normalized;
    }
    if ($isFonte && wa_otomatis_is_group_digits($normalized)) {
        return $normalized . '@g.us';
    }

    return $normalized;
}

/** @return list<string> */
function wa_otomatis_parse_targets(string $raw): array
{
    $parts = preg_split('/[\s,;]+/', trim($raw)) ?: [];
    $phones = [];
    foreach ($parts as $part) {
        $phone = wa_otomatis_normalize_target((string) $part);
        if ($phone !== '') {
            $phones[] = $phone;
        }
    }

    return array_values(array_unique($phones));
}

/**
 * @param array<string, mixed> $override
 * @return array{endpoint:string,token:string,sender:string}
 */
function wa_otomatis_gateway_config(PDO $pdo, array $override = []): array
{
    $endpoint = isset($override['endpoint'])
        ? trim((string) $override['endpoint'])
        : trim((string) app_setting($pdo, 'wa_gateway_url', ''));
    $token = isset($override['token'])
        ? trim((string) $override['token'])
        : trim((string) app_setting($pdo, 'wa_gateway_token', ''));
    $sender = isset($override['sender'])
        ? trim((string) $override['sender'])
        : trim((string) app_setting($pdo, 'wa_sender', ''));

    return [
        'endpoint' => resolve_wa_endpoint($endpoint, $token),
        'token' => $token,
        'sender' => $sender,
    ];
}

/**
 * @param array<string, mixed> $override
 */
function wa_otomatis_gateway_error(PDO $pdo, array $override = []): ?string
{
    $cfg = wa_otomatis_gateway_config($pdo, $override);
    if ($cfg['token'] === '') {
        return 'Token gateway WA belum diisi (Pengaturan → Gateway WA).';
    }
    if ($cfg['endpoint'] === '') {
        return 'URL gateway WA tidak valid.';
    }

    return null;
}

/**
 * Apakah job otomatis boleh mengirim WA (master toggle + pengaturan per jenis).
 *
 * Mode push/wa (fcm_notify_mode) hanya membatasi notifikasi izin yang punya alternatif push.
 * Tagihan, presensi, alpa, kelas kosong, dan cashless memakai toggle masing-masing.
 *
 * @param string $kind general|tagihan|izin|cashless
 */
function wa_otomatis_should_run(PDO $pdo, string $kind = 'general'): bool
{
    if (trim((string) app_setting($pdo, 'wa_otomatis_master_enabled', '1')) !== '1') {
        return false;
    }

    if ($kind === 'tagihan') {
        return trim((string) app_setting($pdo, 'wa_tagihan_auto_enabled', '0')) === '1';
    }

    if ($kind === 'izin') {
        if (!function_exists('push_should_send_wa')) {
            require_once __DIR__ . '/push_fcm.php';
        }

        return push_should_send_wa($pdo);
    }

    // general, cashless, presensi — WA native; tidak diblokir mode push-only
    return true;
}

function wa_otomatis_extract_api_error(string $response, string $curlError, int $httpCode): string
{
    if ($curlError !== '') {
        return $curlError;
    }
    if ($httpCode === 0) {
        return 'Tidak ada respons dari gateway WA.';
    }

    $decoded = json_decode($response, true);
    if (is_array($decoded)) {
        foreach (['reason', 'message', 'msg', 'detail', 'error', 'pesan'] as $key) {
            if (!isset($decoded[$key])) {
                continue;
            }
            $val = $decoded[$key];
            if (is_string($val) && trim($val) !== '') {
                return trim($val);
            }
            if (is_array($val) && isset($val[0]) && is_string($val[0])) {
                return trim($val[0]);
            }
        }
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            foreach (['reason', 'message', 'error'] as $key) {
                if (!empty($decoded['data'][$key]) && is_string($decoded['data'][$key])) {
                    return trim((string) $decoded['data'][$key]);
                }
            }
        }
    }

    if ($httpCode >= 400) {
        $snippet = trim(preg_replace('/\s+/', ' ', strip_tags($response)) ?? '');
        if ($snippet !== '' && strlen($snippet) <= 200) {
            return 'HTTP ' . $httpCode . ': ' . $snippet;
        }

        return 'HTTP ' . $httpCode;
    }

    return '';
}

function wa_otomatis_parse_success(string $response, int $httpCode, string $curlError): bool
{
    if ($curlError !== '' || $httpCode === 0) {
        return false;
    }
    if (in_array($httpCode, [301, 302, 303, 307, 308], true)) {
        return false;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        return false;
    }

    if ($response === '') {
        return true;
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        return true;
    }

    $isSuccess = true;
    if (array_key_exists('status', $decoded)) {
        $statusValue = $decoded['status'];
        if ($statusValue === false || $statusValue === 0 || $statusValue === 'false' || $statusValue === 'failed' || $statusValue === 'error') {
            $isSuccess = false;
        }
        if (is_string($statusValue) && in_array(strtolower($statusValue), ['success', 'sent', 'ok', 'true'], true)) {
            $isSuccess = true;
        }
    }
    if (isset($decoded['success']) && ($decoded['success'] === false || $decoded['success'] === 0 || $decoded['success'] === 'false')) {
        $isSuccess = false;
    }
    if (isset($decoded['error']) && $decoded['error'] !== '' && $decoded['error'] !== null && $decoded['error'] !== false) {
        $isSuccess = false;
    }

    return $isSuccess;
}

function wa_otomatis_is_retryable(int $httpCode, string $curlError): bool
{
    if ($curlError !== '') {
        return true;
    }

    return $httpCode === 0 || $httpCode >= 500;
}

/**
 * Satu kali kirim ke gateway (tanpa retry).
 *
 * @param array<string, mixed> $override
 * @return array{success:bool,http_code:int,error:string,response:string,target:string}
 */
function wa_otomatis_send_once(PDO $pdo, string $targetRaw, string $message, array $override = []): array
{
    $target = wa_otomatis_normalize_target($targetRaw);
    $gwErr = wa_otomatis_gateway_error($pdo, $override);
    if ($gwErr !== null) {
        return [
            'success' => false,
            'http_code' => 0,
            'error' => $gwErr,
            'response' => $gwErr,
            'target' => $target,
        ];
    }
    if ($target === '') {
        return [
            'success' => false,
            'http_code' => 0,
            'error' => 'Nomor / ID grup WA tidak valid.',
            'response' => 'Nomor / ID grup WA tidak valid.',
            'target' => '',
        ];
    }

    $cfg = wa_otomatis_gateway_config($pdo, $override);
    $endpoint = $cfg['endpoint'];
    $token = $cfg['token'];
    $sender = $cfg['sender'];

    $ch = curl_init($endpoint);
    $isFonte = (bool) preg_match('/fonte|fonnte/i', $endpoint);
    $apiTarget = wa_otomatis_format_target_for_payload($target, $isFonte);

    $payload = [
        'token' => $token,
        'sender' => $sender,
        'target' => $apiTarget,
        'message' => $message,
    ];

    $headers = [];
    if ($isFonte && $token !== '') {
        $headers[] = 'Authorization: ' . $token;
    }
    if ($isFonte) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $payload = [
            'token' => $token,
            'target' => $apiTarget,
            'message' => $message,
        ];
        if (!wa_otomatis_is_group_target($target)) {
            $payload['countryCode'] = '62';
        }
        if (!wa_otomatis_fonnte_connect_only($pdo, $override)) {
            $payload['connectOnly'] = 'false';
        }
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_MAXREDIRS => 0,
    ]);
    $rawResponse = curl_exec($ch);
    $curlError = curl_error($ch);
    $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $responseHeaders = '';
    $response = '';
    if (is_string($rawResponse)) {
        $responseHeaders = substr($rawResponse, 0, $headerSize);
        $response = substr($rawResponse, $headerSize);
    }

    $isSuccess = wa_otomatis_parse_success((string) $response, $statusCode, $curlError);
    $apiError = wa_otomatis_extract_api_error((string) $response, $curlError, $statusCode);

    $location = '';
    if ($responseHeaders !== '' && preg_match('/^Location:\s*(.+)$/mi', $responseHeaders, $matches)) {
        $location = trim((string) ($matches[1] ?? ''));
    }
    $responseText = $apiError !== '' ? $apiError : ($curlError !== '' ? $curlError : (string) $response);
    if ($location !== '') {
        $responseText .= "\n[redirect] " . $location;
        $isSuccess = false;
    }

    if (table_exists($pdo, 'wa_logs')) {
        $log = $pdo->prepare('
            INSERT INTO wa_logs (target_phone, message, response_text, is_success)
            VALUES (:target_phone, :message, :response_text, :is_success)
        ');
        $log->execute([
            'target_phone' => $target,
            'message' => $message,
            'response_text' => $responseText,
            'is_success' => $isSuccess ? 1 : 0,
        ]);
    }

    $displayTarget = $apiTarget !== '' ? $apiTarget : $target;
    $errorOut = $isSuccess ? '' : wa_otomatis_enrich_api_error($apiError !== '' ? $apiError : $curlError, $target);
    if (!$isSuccess && $errorOut !== '' && function_exists('save_setting')) {
        save_setting($pdo, 'wa_auto_last_gateway_error', $errorOut);
    }

    return [
        'success' => $isSuccess,
        'http_code' => $statusCode,
        'error' => $errorOut,
        'response' => $responseText,
        'target' => $displayTarget,
    ];
}

/**
 * Batas pecah pesan ke gateway (hanya dipakai laporan ALPA).
 */
function wa_otomatis_gateway_chunk_max(PDO $pdo): int
{
    return max(40, (int) app_setting($pdo, 'wa_otomatis_chunk_max', '100'));
}

/**
 * Pecah pesan panjang menjadi beberapa bagian (batas karakter gateway WA).
 *
 * @return list<string>
 */
function wa_otomatis_chunk_message(string $message, int $maxLen = 100): array
{
    $message = trim($message);
    if ($message === '') {
        return [''];
    }
    $maxLen = max(40, min(2000, $maxLen));
    if (mb_strlen($message) <= $maxLen) {
        return [$message];
    }

    $chunks = [];
    $paragraphs = preg_split("/\r\n|\n|\r/", $message) ?: [$message];
    $buffer = '';

    $flush = static function () use (&$buffer, &$chunks): void {
        $buf = trim($buffer);
        if ($buf !== '') {
            $chunks[] = $buf;
        }
        $buffer = '';
    };

    foreach ($paragraphs as $para) {
        $para = trim((string) $para);
        if ($para === '') {
            continue;
        }
        if (mb_strlen($para) > $maxLen) {
            $flush();
            $words = preg_split('/\s+/u', $para) ?: [$para];
            $line = '';
            foreach ($words as $word) {
                $candidate = $line === '' ? $word : ($line . ' ' . $word);
                if (mb_strlen($candidate) <= $maxLen) {
                    $line = $candidate;
                    continue;
                }
                if ($line !== '') {
                    $chunks[] = $line;
                }
                while (mb_strlen($word) > $maxLen) {
                    $chunks[] = mb_substr($word, 0, $maxLen);
                    $word = mb_substr($word, $maxLen);
                }
                $line = $word;
            }
            if ($line !== '') {
                $buffer = $line;
            }
            continue;
        }
        $candidate = $buffer === '' ? $para : ($buffer . "\n" . $para);
        if (mb_strlen($candidate) <= $maxLen) {
            $buffer = $candidate;
            continue;
        }
        $flush();
        $buffer = $para;
    }
    $flush();

    return $chunks !== [] ? $chunks : [mb_substr($message, 0, $maxLen)];
}

/**
 * Kirim dengan retry singkat pada gangguan jaringan / server.
 *
 * @param array<string, mixed> $opts endpoint, token, sender, max_retries, delay_ms
 * @return array{success:bool,http_code:int,error:string,response:string,target:string,attempts:int}
 */
function wa_otomatis_send(PDO $pdo, string $targetRaw, string $message, array $opts = []): array
{
    $maxRetries = max(0, min(3, (int) ($opts['max_retries'] ?? 2)));
    $delayMs = max(100, min(2000, (int) ($opts['delay_ms'] ?? 400)));
    // Default: satu kiriman penuh. Hanya laporan ALPA yang mengisi chunk_max (lihat send_wa_bulk_messages).
    $chunkMax = max(0, (int) ($opts['chunk_max'] ?? 0));
    $chunkDelayMs = max(100, min(3000, (int) ($opts['chunk_delay_ms'] ?? 450)));
    $override = $opts;
    unset($override['max_retries'], $override['delay_ms'], $override['chunk_max'], $override['chunk_delay_ms']);

    $chunks = ($chunkMax > 0) ? wa_otomatis_chunk_message($message, $chunkMax) : [trim($message)];
    if ($chunks === []) {
        $chunks = [''];
    }

    $last = ['success' => false, 'http_code' => 0, 'error' => 'Pesan kosong', 'response' => '', 'target' => '', 'attempts' => 0];
    $totalAttempts = 0;
    foreach ($chunks as $idx => $chunk) {
        if ($idx > 0) {
            usleep($chunkDelayMs * 1000);
        }
        for ($i = 0; $i <= $maxRetries; $i++) {
            $totalAttempts++;
            $last = wa_otomatis_send_once($pdo, $targetRaw, $chunk, $override);
            if ($last['success'] ?? false) {
                break;
            }
            if ($i < $maxRetries && wa_otomatis_is_retryable((int) ($last['http_code'] ?? 0), (string) ($last['error'] ?? ''))) {
                usleep($delayMs * 1000);
                continue;
            }
            break;
        }
        if (!($last['success'] ?? false)) {
            break;
        }
    }

    $last['attempts'] = $totalAttempts;
    if (count($chunks) > 1) {
        $last['chunks'] = count($chunks);
    }

    return $last;
}

/**
 * Kirim ke banyak target (personal atau grup).
 *
 * @param array<string, mixed> $opts delay_between_ms, max_retries
 * @return array{sent:int,failed:int,total:int,details:list<array<string,mixed>>}
 */
function wa_otomatis_send_bulk(PDO $pdo, string $targetsRaw, string $message, array $opts = []): array
{
    $targets = wa_otomatis_parse_targets($targetsRaw);
    $delayMs = max(0, min(3000, (int) ($opts['delay_between_ms'] ?? 350)));
    $sent = 0;
    $failed = 0;
    $details = [];

    foreach ($targets as $idx => $target) {
        if ($idx > 0 && $delayMs > 0) {
            usleep($delayMs * 1000);
        }
        $result = wa_otomatis_send($pdo, $target, $message, $opts);
        if ($result['success'] ?? false) {
            $sent++;
        } else {
            $failed++;
        }
        $details[] = $result;
    }

    return [
        'sent' => $sent,
        'failed' => $failed,
        'total' => count($targets),
        'details' => $details,
    ];
}

/** Nomor WA wali santri (resolver lengkap + normalisasi). */
function wa_otomatis_santri_wali_phone(PDO $pdo, int|array $santriRow): string
{
    if (!function_exists('santri_resolve_no_wa_wali')) {
        require_once __DIR__ . '/santri_wa.php';
    }
    $raw = santri_resolve_no_wa_wali($pdo, $santriRow);

    return wa_otomatis_normalize_target($raw);
}

/**
 * Job WA terjadwal untuk cron (tanpa throttle navigasi web).
 * Dipanggil dari cron/wa_auto.php pada tick berat (~5 menit).
 */
function wa_auto_run_scheduled_wa(PDO $pdo): void
{
    if (!function_exists('trigger_auto_wa_notifications')) {
        require_once __DIR__ . '/app.php';
    }
    if (!function_exists('cashless_wa_cron_laporan_harian')) {
        require_once __DIR__ . '/cashless_wa.php';
    }

    $started = microtime(true);
    $results = [
        'alpa' => ['ran' => false, 'note' => ''],
        'tagihan' => ['ran' => false, 'note' => ''],
        'kelas_kosong' => ['ran' => false, 'note' => ''],
        'cashless_laporan' => ['ran' => false, 'note' => ''],
    ];

    if (wa_otomatis_gateway_error($pdo) !== null) {
        save_setting($pdo, 'wa_auto_scheduled_last_at', date('Y-m-d H:i:s'));
        save_setting($pdo, 'wa_auto_scheduled_last_result', json_encode([
            'skipped' => true,
            'reason' => 'gateway',
            'gateway_error' => wa_otomatis_gateway_error($pdo),
        ], JSON_UNESCAPED_UNICODE));

        return;
    }

    trigger_auto_wa_notifications($pdo);
    $results['alpa']['ran'] = true;

    trigger_auto_wa_tagihan_wali($pdo);
    $results['tagihan']['ran'] = true;

    trigger_wa_kelas_kosong_bertahap($pdo);
    $results['kelas_kosong']['ran'] = true;

    cashless_wa_cron_laporan_harian($pdo);
    $results['cashless_laporan']['ran'] = true;

    save_setting($pdo, 'wa_auto_scheduled_last_at', date('Y-m-d H:i:s'));
    save_setting($pdo, 'wa_auto_scheduled_last_result', json_encode([
        'skipped' => false,
        'duration_ms' => (int) round((microtime(true) - $started) * 1000),
        'jobs' => $results,
        'alpa_stats' => json_decode((string) app_setting($pdo, 'wa_auto_alpa_last_result', ''), true),
        'tagihan_stats' => json_decode((string) app_setting($pdo, 'wa_tagihan_last_run_stats', ''), true),
    ], JSON_UNESCAPED_UNICODE));
}

/**
 * Satu tick WA otomatis (ringan + berat). Dipakai cron dan fallback navigasi web.
 *
 * @return array{light:bool,heavy:bool,gateway_ok:bool}
 */
function wa_auto_run_tick(PDO $pdo): array
{
    $now = time();
    save_setting($pdo, 'wa_auto_last_run_at', date('Y-m-d H:i:s'));
    $gwErr = wa_otomatis_gateway_error($pdo);
    save_setting($pdo, 'wa_auto_last_gateway_ok', $gwErr === null ? '1' : '0');
    if ($gwErr !== null) {
        save_setting($pdo, 'wa_auto_last_gateway_error', $gwErr);
    }

    $runLight = false;
    $runHeavy = false;

    if ($gwErr === null) {
        if (!function_exists('trigger_wa_pembimbing_belum_scan')) {
            require_once __DIR__ . '/wa_pembimbing_scan.php';
        }

        $lightInterval = max(45, (int) app_setting($pdo, 'wa_auto_light_interval_sec', '60'));
        $lastLight = (int) app_setting($pdo, 'wa_auto_light_last_at', '0');
        $runLight = $lastLight <= 0 || ($now - $lastLight) >= $lightInterval;
        if ($runLight) {
            trigger_wa_pembimbing_belum_scan($pdo);
            trigger_wa_mudabir_belum_hadir($pdo);
            save_setting($pdo, 'wa_auto_light_last_at', (string) $now);
        }

        $heavyInterval = max(300, (int) app_setting($pdo, 'wa_auto_heavy_interval_sec', '300'));
        $lastHeavy = (int) app_setting($pdo, 'wa_auto_heavy_last_at', '0');
        $runHeavy = $lastHeavy <= 0 || ($now - $lastHeavy) >= $heavyInterval;
        if ($runHeavy) {
            wa_auto_run_scheduled_wa($pdo);
            if (!function_exists('trigger_push_tagihan_wali_from_cron')) {
                require_once __DIR__ . '/push_events.php';
            }
            trigger_push_tagihan_wali_from_cron($pdo);
            trigger_push_daily_kiai($pdo);

            $cleanupLast = trim((string) app_setting($pdo, 'wa_debounce_cleanup_last_date', ''));
            if ($cleanupLast !== date('Y-m-d')) {
                $removed = wa_cleanup_old_debounce_keys($pdo, 30);
                save_setting($pdo, 'wa_debounce_cleanup_last_date', date('Y-m-d'));
                if ($removed > 0) {
                    save_setting($pdo, 'wa_debounce_cleanup_last_count', (string) $removed);
                }
            }

            save_setting($pdo, 'wa_auto_heavy_last_at', (string) $now);
            save_setting($pdo, 'wa_auto_last_heavy_at', date('Y-m-d H:i:s'));
        }
    }

    return [
        'light' => $runLight,
        'heavy' => $runHeavy,
        'gateway_ok' => $gwErr === null,
    ];
}

/**
 * Fallback bila cron tidak jalan: tick WA saat staf buka aplikasi (throttle sama dengan cron).
 */
function wa_auto_web_fallback_tick(PDO $pdo): void
{
    static $ranThisRequest = false;
    if ($ranThisRequest) {
        return;
    }
    $ranThisRequest = true;

    if (trim((string) app_setting($pdo, 'wa_auto_web_fallback_enabled', '1')) !== '1') {
        return;
    }

    $requestPath = app_normalize_request_path((string) ($_SERVER['REQUEST_URI'] ?? ''));
    if ($requestPath !== '' && (app_request_path_is_lightweight($requestPath) || str_contains($requestPath, '/cron/wa_auto.php'))) {
        return;
    }

    wa_auto_run_tick($pdo);
}
