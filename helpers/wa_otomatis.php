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

    if (stripos($raw, '@g.us') !== false) {
        $beforeAt = strstr($raw, '@', true);
        $digits = preg_replace('/[^0-9]/', '', (string) ($beforeAt !== false ? $beforeAt : $raw)) ?? '';

        return $digits !== '' ? $digits : '';
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
    return wa_otomatis_is_group_digits($normalized);
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
 * Apakah job otomatis boleh mengirim WA (mode push/wa + master toggle).
 *
 * @param string $kind general|tagihan|izin
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

    if (!function_exists('push_should_send_wa')) {
        require_once __DIR__ . '/push_fcm.php';
    }

    return push_should_send_wa($pdo);
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

    $payload = [
        'token' => $token,
        'sender' => $sender,
        'target' => $target,
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
            'target' => $target,
            'message' => $message,
        ];
        if (!wa_otomatis_is_group_target($target)) {
            $payload['countryCode'] = '62';
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

    return [
        'success' => $isSuccess,
        'http_code' => $statusCode,
        'error' => $isSuccess ? '' : ($apiError !== '' ? $apiError : $curlError),
        'response' => $responseText,
        'target' => $target,
    ];
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
    $override = $opts;
    unset($override['max_retries'], $override['delay_ms']);

    $last = [];
    $attempts = 0;
    for ($i = 0; $i <= $maxRetries; $i++) {
        $attempts++;
        $last = wa_otomatis_send_once($pdo, $targetRaw, $message, $override);
        if ($last['success'] ?? false) {
            $last['attempts'] = $attempts;

            return $last;
        }
        if ($i < $maxRetries && wa_otomatis_is_retryable((int) ($last['http_code'] ?? 0), (string) ($last['error'] ?? ''))) {
            usleep($delayMs * 1000);
            continue;
        }
        break;
    }

    $last['attempts'] = $attempts;

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
function wa_otomatis_santri_wali_phone(PDO $pdo, array $santriRow): string
{
    if (!function_exists('santri_resolve_no_wa_wali')) {
        require_once __DIR__ . '/santri_wa.php';
    }
    $raw = santri_resolve_no_wa_wali($pdo, $santriRow);

    return wa_otomatis_normalize_target($raw);
}
