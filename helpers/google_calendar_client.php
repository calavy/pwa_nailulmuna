<?php

declare(strict_types=1);

/**
 * Google Calendar API via Service Account (tanpa Composer).
 */

require_once __DIR__ . '/google_sheets_client.php';

const GOOGLE_CALENDAR_SCOPE = 'https://www.googleapis.com/auth/calendar';

/**
 * @param array<string, mixed> $credentials
 */
function google_calendar_sa_access_token(array $credentials): string
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
        'scope' => GOOGLE_CALENDAR_SCOPE,
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
        throw new RuntimeException('Gagal OAuth Google Calendar: ' . ($err !== '' ? $err : 'HTTP ' . $code));
    }
    $json = json_decode((string) $body, true);
    $token = trim((string) ($json['access_token'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('Token Google Calendar kosong: ' . (string) ($json['error_description'] ?? $body));
    }

    return $token;
}

function google_calendar_api_base(string $calendarId): string
{
    return 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarId);
}

/**
 * @return array<string, mixed>
 */
function google_calendar_events_insert(string $token, string $calendarId, array $event): array
{
    $url = google_calendar_api_base($calendarId) . '/events';
    $res = google_api_request('POST', $url, $token, $event);

    return is_array($res) ? $res : [];
}

/**
 * @return array<string, mixed>
 */
function google_calendar_events_patch(string $token, string $calendarId, string $eventId, array $event): array
{
    $url = google_calendar_api_base($calendarId) . '/events/' . rawurlencode($eventId);
    $res = google_api_request('PATCH', $url, $token, $event);

    return is_array($res) ? $res : [];
}

function google_calendar_events_delete(string $token, string $calendarId, string $eventId): void
{
    $url = google_calendar_api_base($calendarId) . '/events/' . rawurlencode($eventId);
    google_api_request('DELETE', $url, $token, null);
}

/**
 * @return array{items:list<array<string,mixed>>,nextSyncToken:?string,nextPageToken:?string}
 */
function google_calendar_events_list(string $token, string $calendarId, ?string $syncToken = null, ?string $pageToken = null): array
{
    $params = ['maxResults' => 250];
    if ($syncToken !== null && $syncToken !== '') {
        $params['syncToken'] = $syncToken;
    } else {
        $params['singleEvents'] = 'true';
        $params['showDeleted'] = 'true';
        $params['timeMin'] = gmdate('c', strtotime('-90 days'));
    }
    if ($pageToken !== null && $pageToken !== '') {
        $params['pageToken'] = $pageToken;
    }
    $url = google_calendar_api_base($calendarId) . '/events?' . http_build_query($params);
    $body = google_api_request('GET', $url, $token, null);

    return [
        'items' => is_array($body['items'] ?? null) ? $body['items'] : [],
        'nextSyncToken' => isset($body['nextSyncToken']) ? (string) $body['nextSyncToken'] : null,
        'nextPageToken' => isset($body['nextPageToken']) ? (string) $body['nextPageToken'] : null,
    ];
}

/**
 * @return array<string, mixed>
 */
function google_calendar_calendar_get(string $token, string $calendarId): array
{
    $url = google_calendar_api_base($calendarId);
    $res = google_api_request('GET', $url, $token, null);

    return is_array($res) ? $res : [];
}

/**
 * @return array<string, mixed>
 */
function google_calendar_watch_events(string $token, string $calendarId, string $channelId, string $webhookUrl, string $tokenSecret): array
{
    $url = google_calendar_api_base($calendarId) . '/events/watch';
    $payload = [
        'id' => $channelId,
        'type' => 'web_hook',
        'address' => $webhookUrl,
        'token' => $tokenSecret,
    ];
    $res = google_api_request('POST', $url, $token, $payload);

    return is_array($res) ? $res : [];
}

function google_calendar_channels_stop(string $token, string $channelId, string $resourceId): void
{
    google_api_request('POST', 'https://www.googleapis.com/calendar/v3/channels/stop', $token, [
        'id' => $channelId,
        'resourceId' => $resourceId,
    ]);
}
