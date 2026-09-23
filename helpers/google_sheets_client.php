<?php

declare(strict_types=1);

/**
 * Google Sheets / Drive REST client via Service Account (tanpa Composer).
 */

function google_base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * @return array<string, mixed>
 */
function google_sa_load_credentials(string $path): array
{
    $path = trim($path);
    if ($path === '') {
        throw new RuntimeException('Path kredensial Google Service Account belum diisi.');
    }
    if (!is_file($path)) {
        throw new RuntimeException('File kredensial Google tidak ditemukan: ' . $path);
    }
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        throw new RuntimeException('File kredensial Google kosong atau tidak bisa dibaca.');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('File kredensial Google bukan JSON valid.');
    }
    foreach (['client_email', 'private_key'] as $key) {
        if (trim((string) ($data[$key] ?? '')) === '') {
            throw new RuntimeException('Kredensial Google tidak lengkap (missing ' . $key . ').');
        }
    }

    return $data;
}

/**
 * @param array<string, mixed> $credentials
 */
function google_sa_access_token(array $credentials): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Ekstensi cURL tidak tersedia di server.');
    }
    if (!function_exists('openssl_sign')) {
        throw new RuntimeException('OpenSSL tidak tersedia untuk JWT Service Account.');
    }

    $now = time();
    $header = google_base64url_encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim = google_base64url_encode((string) json_encode([
        'iss' => (string) $credentials['client_email'],
        'scope' => 'https://www.googleapis.com/auth/spreadsheets https://www.googleapis.com/auth/drive',
        'aud' => 'https://oauth2.googleapis.com/token',
        'exp' => $now + 3600,
        'iat' => $now,
    ]));
    $unsigned = $header . '.' . $claim;
    $privateKey = (string) $credentials['private_key'];
    $signature = '';
    if (!openssl_sign($unsigned, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('Gagal menandatangani JWT Service Account.');
    }
    $jwt = $unsigned . '.' . google_base64url_encode($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code >= 400) {
        throw new RuntimeException('Gagal OAuth Google: ' . ($err !== '' ? $err : 'HTTP ' . $code));
    }
    $json = json_decode((string) $body, true);
    $token = trim((string) ($json['access_token'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('Token Google kosong: ' . (string) ($json['error_description'] ?? $body));
    }

    return $token;
}

function google_api_error_message(int $httpCode, string $url, string $googleMessage): string
{
    $msg = trim($googleMessage);
    if ($msg === '') {
        $msg = 'HTTP ' . $httpCode;
    }
    $out = 'Google API error: ' . $msg;
    if ($httpCode !== 403) {
        return $out;
    }
    if (str_contains($url, 'sheets.googleapis.com')) {
        return $out . ' — Share spreadsheet ke email Service Account (Editor). Aktifkan Google Sheets API + Drive API di project Cloud Console yang sama dengan file JSON key.';
    }
    if (str_contains($url, 'drive.googleapis.com') && str_contains($url, '/permissions')) {
        return $out . ' — Service Account harus Editor di file. Jika Workspace melarang invite otomatis, kosongkan email penerima di pengaturan lalu share Viewer manual dari Google Drive.';
    }
    if (str_contains($url, 'drive.googleapis.com')) {
        return $out . ' — Aktifkan Google Drive API di project Service Account.';
    }

    return $out;
}

/**
 * @return array<string, mixed>
 */
function google_api_request(string $method, string $url, string $token, ?array $payload = null, int $timeoutSec = 60): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('Ekstensi cURL tidak tersedia di server.');
    }
    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ];
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => $timeoutSec,
    ];
    if ($payload !== null) {
        $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = $json;
    }
    $opts[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('Permintaan Google API gagal: ' . $err);
    }
    $decoded = json_decode((string) $body, true);
    if ($code >= 400) {
        $googleMsg = is_array($decoded)
            ? trim((string) (($decoded['error']['message'] ?? '') ?: ($decoded['error_description'] ?? '')))
            : '';
        throw new RuntimeException(google_api_error_message($code, $url, $googleMsg));
    }

    return is_array($decoded) ? $decoded : [];
}

function google_sheets_ensure_spreadsheet(string $token, ?string $spreadsheetId, string $title): string
{
    $spreadsheetId = trim((string) $spreadsheetId);
    if ($spreadsheetId !== '') {
        google_api_request('GET', 'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId), $token);

        return $spreadsheetId;
    }

    $title = trim($title) !== '' ? trim($title) : 'PWA Nailul Muna — Snapshot Laporan';
    $res = google_api_request('POST', 'https://sheets.googleapis.com/v4/spreadsheets', $token, [
        'properties' => ['title' => $title],
    ]);
    $id = trim((string) ($res['spreadsheetId'] ?? ''));
    if ($id === '') {
        throw new RuntimeException('Gagal membuat spreadsheet Google.');
    }

    return $id;
}

/**
 * @param list<string> $tabNames
 */
function google_sheets_ensure_tabs(string $token, string $spreadsheetId, array $tabNames): void
{
    $meta = google_api_request(
        'GET',
        'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . '?fields=sheets.properties.title',
        $token
    );
    $existing = [];
    foreach ((array) ($meta['sheets'] ?? []) as $sheet) {
        $title = trim((string) ($sheet['properties']['title'] ?? ''));
        if ($title !== '') {
            $existing[$title] = true;
        }
    }

    $requests = [];
    foreach ($tabNames as $tab) {
        $tab = trim((string) $tab);
        if ($tab === '' || isset($existing[$tab])) {
            continue;
        }
        $requests[] = ['addSheet' => ['properties' => ['title' => $tab]]];
    }
    if ($requests === []) {
        return;
    }

    google_api_request(
        'POST',
        'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . ':batchUpdate',
        $token,
        ['requests' => $requests]
    );
}

/**
 * @param list<list<string|int|float|null>> $rows
 */
function google_sheets_write_tab(string $token, string $spreadsheetId, string $tabName, array $rows): int
{
    $tabName = trim($tabName);
    if ($tabName === '') {
        throw new RuntimeException('Nama tab spreadsheet kosong.');
    }
    if ($rows === []) {
        $rows = [['(kosong)']];
    }

    $safeTab = str_replace("'", "''", $tabName);
    $range = rawurlencode("'" . $safeTab . "'!A1");

    google_api_request(
        'POST',
        'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId) . '/values/' . $range . ':clear',
        $token,
        null
    );

    $values = [];
    foreach ($rows as $row) {
        $line = [];
        foreach ($row as $cell) {
            if ($cell === null) {
                $line[] = '';
            } elseif (is_bool($cell)) {
                $line[] = $cell ? '1' : '0';
            } else {
                $line[] = (string) $cell;
            }
        }
        $values[] = $line;
    }

    google_api_request(
        'PUT',
        'https://sheets.googleapis.com/v4/spreadsheets/' . rawurlencode($spreadsheetId)
            . '/values/' . $range . '?valueInputOption=RAW',
        $token,
        ['values' => $values],
        120
    );

    return count($values);
}

function google_drive_share_email(string $token, string $fileId, string $email, string $role = 'reader'): void
{
    $email = trim(strtolower($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }
    $role = in_array($role, ['reader', 'writer', 'commenter'], true) ? $role : 'reader';

    try {
        google_api_request(
            'POST',
            'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '/permissions',
            $token,
            [
                'type' => 'user',
                'role' => $role,
                'emailAddress' => $email,
            ]
        );
    } catch (RuntimeException $e) {
        if (stripos($e->getMessage(), 'already exists') !== false || stripos($e->getMessage(), 'duplicate') !== false) {
            return;
        }
        throw $e;
    }
}

/**
 * @return list<string>
 */
function google_drive_share_emails(string $token, string $fileId, string $emailsCsv, string $role = 'reader'): array
{
    $shared = [];
    foreach (preg_split('/[\s,;]+/', trim($emailsCsv), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $email) {
        $email = trim(strtolower((string) $email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        google_drive_share_email($token, $fileId, $email, $role);
        $shared[] = $email;
    }

    return $shared;
}
