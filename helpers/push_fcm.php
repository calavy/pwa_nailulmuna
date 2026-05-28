<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/** @return list<string> */
function push_default_categories_for_audience(string $audienceType): array
{
    return match ($audienceType) {
        'wali' => ['syahriyah', 'izin_keluar', 'laporan_sakit'],
        'staff' => ['izin_pengajuan', 'rapat', 'tugas_keamanan', 'presensi_scan'],
        'kiai' => ['keuangan_harian', 'pelanggaran_berat'],
        default => [],
    };
}

function push_category_labels(): array
{
    return [
        'syahriyah' => 'Pengingat Syahriah',
        'izin_keluar' => 'Izin keluar anak',
        'laporan_sakit' => 'Laporan sakit',
        'izin_pengajuan' => 'Pengajuan izin santri',
        'rapat' => 'Rapat & pengumuman',
        'tugas_keamanan' => 'Tugas keamanan',
        'keuangan_harian' => 'Ringkasan keuangan harian',
        'pelanggaran_berat' => 'Pelanggaran berat / SP',
        'presensi_scan' => 'Scan presensi (santri & pembimbing)',
    ];
}

function ensure_push_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    if (!table_exists($pdo, 'fcm_tokens')) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS fcm_tokens (
                id INT AUTO_INCREMENT PRIMARY KEY,
                token VARCHAR(512) NOT NULL,
                audience_type ENUM('wali','staff','kiai') NOT NULL,
                wali_santri_id INT NULL,
                user_id INT NULL,
                device_label VARCHAR(120) NULL,
                categories_json TEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                last_seen_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uk_fcm_token (token(191)),
                INDEX idx_fcm_wali (wali_santri_id),
                INDEX idx_fcm_user (user_id),
                INDEX idx_fcm_audience (audience_type, is_active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    if (!table_exists($pdo, 'push_logs')) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS push_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                audience_type VARCHAR(20) NULL,
                target_ref VARCHAR(80) NULL,
                category VARCHAR(50) NULL,
                title VARCHAR(200) NOT NULL,
                body TEXT NULL,
                data_json TEXT NULL,
                tokens_targeted INT NOT NULL DEFAULT 0,
                tokens_success INT NOT NULL DEFAULT 0,
                is_success TINYINT(1) NOT NULL DEFAULT 0,
                response_text TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    if (table_exists($pdo, 'users')) {
        try {
            $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
            $type = (string) ($col['Type'] ?? '');
            if ($type !== '' && !str_contains($type, "'kiai'")) {
                $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','pengurus','petugas_absensi','kiai') NOT NULL DEFAULT 'pengurus'");
            }
        } catch (PDOException $e) {
            // abaikan jika tidak bisa alter
        }
    }
}

/** @return array<string, string>|null */
function push_fcm_local_config(): ?array
{
    static $cached = false;
    if ($cached !== false) {
        return $cached;
    }
    $path = __DIR__ . '/../config/firebase.local.php';
    if (!is_file($path)) {
        $cached = null;

        return null;
    }
    $cfg = require $path;
    if (!is_array($cfg)) {
        $cached = null;

        return null;
    }
    $map = [
        'fcm_project_id' => 'project_id',
        'fcm_client_email' => 'client_email',
        'fcm_private_key' => 'private_key',
        'fcm_web_api_key' => 'web_api_key',
        'fcm_vapid_key' => 'vapid_key',
        'fcm_sender_id' => 'sender_id',
        'fcm_app_id' => 'app_id',
    ];
    $out = [];
    foreach ($map as $settingKey => $localKey) {
        $v = trim((string) ($cfg[$localKey] ?? ''));
        if ($v !== '') {
            $out[$settingKey] = $v;
        }
    }
    $cached = $out;

    return $out === [] ? null : $out;
}

function push_fcm_setting(PDO $pdo, string $key, string $default = ''): string
{
    $local = push_fcm_local_config();
    if (is_array($local) && isset($local[$key]) && trim((string) $local[$key]) !== '') {
        return trim((string) $local[$key]);
    }

    return trim((string) app_setting($pdo, $key, $default));
}

function push_fcm_enabled(PDO $pdo): bool
{
    ensure_push_schema($pdo);

    return trim((string) app_setting($pdo, 'fcm_enabled', '0')) === '1'
        && push_fcm_setting($pdo, 'fcm_project_id') !== ''
        && push_fcm_setting($pdo, 'fcm_client_email') !== ''
        && push_fcm_setting($pdo, 'fcm_private_key') !== '';
}

/** @return array<string, string> */
function push_fcm_web_config(PDO $pdo): array
{
    return [
        'enabled' => push_fcm_enabled($pdo) ? '1' : '0',
        'apiKey' => push_fcm_setting($pdo, 'fcm_web_api_key'),
        'vapidKey' => push_fcm_setting($pdo, 'fcm_vapid_key'),
        'senderId' => push_fcm_setting($pdo, 'fcm_sender_id'),
        'appId' => push_fcm_setting($pdo, 'fcm_app_id'),
        'projectId' => push_fcm_setting($pdo, 'fcm_project_id'),
    ];
}

function push_notify_mode(PDO $pdo): string
{
    $m = strtolower(trim((string) app_setting($pdo, 'fcm_notify_mode', 'both')));

    return in_array($m, ['push', 'wa', 'both'], true) ? $m : 'both';
}

function push_should_send_wa(PDO $pdo): bool
{
    $m = push_notify_mode($pdo);

    return $m === 'wa' || $m === 'both';
}

function push_should_send_fcm(PDO $pdo): bool
{
    if (!push_fcm_enabled($pdo)) {
        return false;
    }
    $m = push_notify_mode($pdo);

    return $m === 'push' || $m === 'both';
}

function push_base64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function push_fcm_access_token(PDO $pdo): ?string
{
    static $cached = null;
    static $cachedUntil = 0;
    if ($cached !== null && time() < $cachedUntil) {
        return $cached;
    }

    $email = push_fcm_setting($pdo, 'fcm_client_email');
    $privateKey = push_fcm_setting($pdo, 'fcm_private_key');
    if ($email === '' || $privateKey === '') {
        return null;
    }
    $privateKey = str_replace(['\\n', '\n'], "\n", $privateKey);
    if (!str_contains($privateKey, 'BEGIN PRIVATE KEY')) {
        $privateKey = "-----BEGIN PRIVATE KEY-----\n" . chunk_split(str_replace(["\n", "\r", ' '], '', $privateKey), 64, "\n") . "-----END PRIVATE KEY-----\n";
    }

    $now = time();
    $header = push_base64url_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
    $claim = push_base64url_encode(json_encode([
        'iss' => $email,
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now,
        'exp' => $now + 3500,
    ], JSON_THROW_ON_ERROR));
    $input = $header . '.' . $claim;

    $key = openssl_pkey_get_private($privateKey);
    if ($key === false) {
        return null;
    }
    $signature = '';
    if (!openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
        return null;
    }
    $jwt = $input . '.' . push_base64url_encode($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    if (!is_string($raw)) {
        return null;
    }
    $decoded = json_decode($raw, true);
    $token = is_array($decoded) ? trim((string) ($decoded['access_token'] ?? '')) : '';
    if ($token === '') {
        return null;
    }
    $cached = $token;
    $cachedUntil = time() + 3300;

    return $token;
}

/**
 * @param list<string> $tokens
 * @return array{success:int, failed:int, responses:list<string>}
 */
function push_fcm_send_multicast(PDO $pdo, array $tokens, string $title, string $body, array $data = [], ?string $clickUrl = null): array
{
    $tokens = array_values(array_unique(array_filter(array_map('trim', $tokens))));
    if ($tokens === []) {
        return ['success' => 0, 'failed' => 0, 'responses' => []];
    }

    $access = push_fcm_access_token($pdo);
    $projectId = push_fcm_setting($pdo, 'fcm_project_id');
    if ($access === null || $projectId === '') {
        return ['success' => 0, 'failed' => count($tokens), 'responses' => ['FCM tidak dikonfigurasi']];
    }

    $success = 0;
    $failed = 0;
    $responses = [];
    $dataPayload = $data;
    if ($clickUrl !== null && $clickUrl !== '') {
        $dataPayload['url'] = $clickUrl;
    }
    foreach ($dataPayload as $k => $v) {
        if (!is_string($v)) {
            $dataPayload[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
        }
    }

    foreach ($tokens as $token) {
        $message = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => mb_substr($title, 0, 200),
                    'body' => mb_substr($body, 0, 500),
                ],
                'data' => $dataPayload,
                'webpush' => [
                    'fcm_options' => [
                        'link' => $clickUrl ?? '/',
                    ],
                ],
            ],
        ];

        $ch = curl_init('https://fcm.googleapis.com/v1/projects/' . rawurlencode($projectId) . '/messages:send');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $access,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($message, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 200 && $code < 300) {
            $success++;
            $responses[] = 'OK';
        } else {
            $failed++;
            $responses[] = (string) $raw;
            if (is_string($raw) && (str_contains($raw, 'NOT_FOUND') || str_contains($raw, 'UNREGISTERED'))) {
                push_deactivate_token($pdo, $token);
            }
        }
    }

    return ['success' => $success, 'failed' => $failed, 'responses' => $responses];
}

function push_deactivate_token(PDO $pdo, string $token): void
{
    if (!table_exists($pdo, 'fcm_tokens')) {
        return;
    }
    $pdo->prepare('UPDATE fcm_tokens SET is_active = 0 WHERE token = :t')->execute(['t' => $token]);
}

/**
 * @param list<string> $categories
 */
function push_register_token(
    PDO $pdo,
    string $token,
    string $audienceType,
    ?int $waliSantriId,
    ?int $userId,
    array $categories = [],
    string $deviceLabel = ''
): bool {
    ensure_push_schema($pdo);
    $token = trim($token);
    if ($token === '' || !in_array($audienceType, ['wali', 'staff', 'kiai'], true)) {
        return false;
    }

    if ($categories === []) {
        $categories = push_default_categories_for_audience($audienceType);
    }
    $categories = array_values(array_unique(array_filter($categories)));

    $st = $pdo->prepare('SELECT id FROM fcm_tokens WHERE token = :t LIMIT 1');
    $st->execute(['t' => $token]);
    $existing = $st->fetchColumn();

    if ($existing) {
        $pdo->prepare('
            UPDATE fcm_tokens
            SET audience_type = :a, wali_santri_id = :w, user_id = :u, device_label = :d,
                categories_json = :c, is_active = 1, last_seen_at = NOW()
            WHERE id = :id
        ')->execute([
            'a' => $audienceType,
            'w' => $waliSantriId > 0 ? $waliSantriId : null,
            'u' => $userId > 0 ? $userId : null,
            'd' => $deviceLabel !== '' ? mb_substr($deviceLabel, 0, 120) : null,
            'c' => json_encode($categories, JSON_UNESCAPED_UNICODE),
            'id' => (int) $existing,
        ]);
    } else {
        $pdo->prepare('
            INSERT INTO fcm_tokens (token, audience_type, wali_santri_id, user_id, device_label, categories_json, is_active, last_seen_at)
            VALUES (:t, :a, :w, :u, :d, :c, 1, NOW())
        ')->execute([
            't' => $token,
            'a' => $audienceType,
            'w' => $waliSantriId > 0 ? $waliSantriId : null,
            'u' => $userId > 0 ? $userId : null,
            'd' => $deviceLabel !== '' ? mb_substr($deviceLabel, 0, 120) : null,
            'c' => json_encode($categories, JSON_UNESCAPED_UNICODE),
        ]);
    }

    return true;
}

/**
 * @return list<array{token:string, categories:list<string>}>
 */
function push_fetch_tokens(PDO $pdo, string $audienceType, ?int $waliSantriId = null, ?int $userId = null, ?string $category = null): array
{
    ensure_push_schema($pdo);
    $sql = 'SELECT token, categories_json FROM fcm_tokens WHERE is_active = 1 AND audience_type = :a';
    $params = ['a' => $audienceType];
    if ($waliSantriId !== null && $waliSantriId > 0) {
        $sql .= ' AND wali_santri_id = :w';
        $params['w'] = $waliSantriId;
    }
    if ($userId !== null && $userId > 0) {
        $sql .= ' AND user_id = :u';
        $params['u'] = $userId;
    }
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $cats = json_decode((string) ($row['categories_json'] ?? '[]'), true);
        if (!is_array($cats)) {
            $cats = push_default_categories_for_audience($audienceType);
        }
        if ($category !== null && $category !== '' && !in_array($category, $cats, true)) {
            continue;
        }
        $out[] = [
            'token' => (string) ($row['token'] ?? ''),
            'categories' => $cats,
        ];
    }

    return $out;
}

function push_log_send(
    PDO $pdo,
    string $audienceType,
    string $targetRef,
    string $category,
    string $title,
    string $body,
    array $data,
    int $targeted,
    int $success,
    string $responseText
): void {
    if (!table_exists($pdo, 'push_logs')) {
        return;
    }
    $pdo->prepare('
        INSERT INTO push_logs (audience_type, target_ref, category, title, body, data_json, tokens_targeted, tokens_success, is_success, response_text)
        VALUES (:a, :r, :c, :ti, :b, :d, :tt, :ts, :ok, :resp)
    ')->execute([
        'a' => $audienceType,
        'r' => $targetRef,
        'c' => $category,
        'ti' => mb_substr($title, 0, 200),
        'b' => $body,
        'd' => json_encode($data, JSON_UNESCAPED_UNICODE),
        'tt' => $targeted,
        'ts' => $success,
        'ok' => $success > 0 ? 1 : 0,
        'resp' => mb_substr($responseText, 0, 65000),
    ]);
}

function push_notify(
    PDO $pdo,
    string $audienceType,
    string $category,
    string $title,
    string $body,
    array $data = [],
    ?string $clickUrl = null,
    ?int $waliSantriId = null,
    ?int $userId = null
): int {
    if (!push_should_send_fcm($pdo)) {
        return 0;
    }

    $rows = push_fetch_tokens($pdo, $audienceType, $waliSantriId, $userId, $category);
    $tokens = array_map(static fn(array $r): string => $r['token'], $rows);
    $result = push_fcm_send_multicast($pdo, $tokens, $title, $body, array_merge(['category' => $category], $data), $clickUrl);
    $targetRef = $waliSantriId !== null ? 'wali:' . $waliSantriId : ($userId !== null ? 'user:' . $userId : $audienceType);
    push_log_send(
        $pdo,
        $audienceType,
        $targetRef,
        $category,
        $title,
        $body,
        $data,
        count($tokens),
        $result['success'],
        implode("\n", array_slice($result['responses'], 0, 5))
    );

    return $result['success'];
}

function push_notify_all_staff(PDO $pdo, string $category, string $title, string $body, array $data = [], ?string $clickUrl = null): int
{
    if (!push_should_send_fcm($pdo)) {
        return 0;
    }
    ensure_push_schema($pdo);
    $sql = "SELECT token FROM fcm_tokens WHERE is_active = 1 AND audience_type = 'staff'";
    if ($category !== '') {
        $rows = push_fetch_tokens($pdo, 'staff', null, null, $category);
        $tokens = array_map(static fn(array $r): string => $r['token'], $rows);
    } else {
        $tokens = array_map('strval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }
    $result = push_fcm_send_multicast($pdo, $tokens, $title, $body, array_merge(['category' => $category], $data), $clickUrl);
    push_log_send($pdo, 'staff', 'broadcast', $category, $title, $body, $data, count($tokens), $result['success'], 'staff broadcast');

    return $result['success'];
}

function push_notify_all_kiai(PDO $pdo, string $category, string $title, string $body, array $data = [], ?string $clickUrl = null): int
{
    $n = push_notify($pdo, 'kiai', $category, $title, $body, $data, $clickUrl);
    if (table_exists($pdo, 'users')) {
        $admins = $pdo->query("SELECT id FROM users WHERE role IN ('admin','kiai') OR is_super_admin = 1")->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($admins as $uid) {
            $n += push_notify($pdo, 'staff', $category, $title, $body, $data, $clickUrl, null, (int) $uid);
        }
    }

    return $n;
}

function push_resolve_wali_santri_id_for_santri(PDO $pdo, int $santriId): int
{
    if ($santriId <= 0 || !column_exists($pdo, 'santri', 'wali_santri_id')) {
        return 0;
    }
    $st = $pdo->prepare('SELECT wali_santri_id FROM santri WHERE id = :id LIMIT 1');
    $st->execute(['id' => $santriId]);

    return (int) ($st->fetchColumn() ?: 0);
}

function push_notify_wali_for_santri(
    PDO $pdo,
    int $santriId,
    string $category,
    string $title,
    string $body,
    array $data = [],
    ?string $clickUrl = null
): int {
    $waliId = push_resolve_wali_santri_id_for_santri($pdo, $santriId);
    if ($waliId <= 0) {
        return 0;
    }

    return push_notify($pdo, 'wali', $category, $title, $body, $data, $clickUrl ?? '/wali/index.php', $waliId);
}
