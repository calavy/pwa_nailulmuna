<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/google_calendar_client.php';
require_once __DIR__ . '/kalender_agenda.php';

const GOOGLE_CALENDAR_TZ = 'Asia/Jakarta';

function ensure_google_calendar_tables(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS google_calendar_event_map (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entity_type ENUM("agenda","jadwal_slot") NOT NULL,
            entity_id INT UNSIGNED NOT NULL,
            calendar_role ENUM("internal","public") NOT NULL,
            google_calendar_id VARCHAR(255) NOT NULL,
            google_event_id VARCHAR(255) NOT NULL,
            google_etag VARCHAR(128) NULL,
            pwa_updated_at DATETIME NULL,
            google_updated_at DATETIME NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_entity_role (entity_type, entity_id, calendar_role),
            KEY idx_google_event (google_calendar_id, google_event_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS google_calendar_sync_log (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            direction VARCHAR(16) NOT NULL,
            entity_type VARCHAR(32) NULL,
            entity_id INT UNSIGNED NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
}

/**
 * @return array{enabled:bool,internal_id:string,public_id:string,embed_public_url:string,cron_key:string,sa_path:string}
 */
function google_calendar_settings(PDO $pdo): array
{
    ensure_pondok_settings_defaults($pdo);
    $defaults = pondok_settings_defaults();
    $sa = trim((string) app_setting($pdo, 'google_calendar_sa_json_path', ''));
    if ($sa === '') {
        $sa = trim((string) app_setting($pdo, 'laporan_snapshot_sa_json_path', (string) ($defaults['laporan_snapshot_sa_json_path'] ?? '')));
    }

    return [
        'enabled' => app_setting($pdo, 'google_calendar_enabled', '0') === '1',
        'internal_id' => trim((string) app_setting($pdo, 'google_calendar_id_internal', '')),
        'public_id' => trim((string) app_setting($pdo, 'google_calendar_id_public', '')),
        'embed_public_url' => trim((string) app_setting($pdo, 'google_calendar_embed_public_url', '')),
        'cron_key' => trim((string) app_setting($pdo, 'google_calendar_cron_key', '')),
        'sa_path' => google_calendar_resolve_sa_path($sa),
    ];
}

function google_calendar_calendar_id_from_embed_url(string $embedUrl): string
{
    $embedUrl = trim($embedUrl);
    if ($embedUrl === '') {
        return '';
    }
    if (preg_match('/[?&]src=([^&]+)/', $embedUrl, $m)) {
        return rawurldecode($m[1]);
    }

    return '';
}

function google_calendar_open_url_for_calendar_id(string $calendarId): string
{
    $calendarId = trim($calendarId);
    if ($calendarId === '') {
        return 'https://calendar.google.com/';
    }

    return 'https://calendar.google.com/calendar/u/0/r?cid=' . rawurlencode($calendarId);
}

function google_calendar_open_url_public(PDO $pdo): string
{
    $s = google_calendar_settings($pdo);
    $id = (string) $s['public_id'];
    if ($id === '' && (string) $s['embed_public_url'] !== '') {
        $id = google_calendar_calendar_id_from_embed_url((string) $s['embed_public_url']);
    }

    return google_calendar_open_url_for_calendar_id($id);
}

function google_calendar_open_url_internal(PDO $pdo): string
{
    $s = google_calendar_settings($pdo);

    return google_calendar_open_url_for_calendar_id((string) $s['internal_id']);
}

function google_calendar_has_open_links(PDO $pdo): bool
{
    $s = google_calendar_settings($pdo);
    if (trim((string) $s['public_id']) !== '' || trim((string) $s['internal_id']) !== '') {
        return true;
    }

    return google_calendar_calendar_id_from_embed_url((string) $s['embed_public_url']) !== '';
}

function google_calendar_resolve_sa_path(string $configured): string
{
    $configured = trim(str_replace('\\', '/', $configured));
    if ($configured === '') {
        return dirname(__DIR__) . '/config/google_service_account.json';
    }
    if (preg_match('#^[a-zA-Z]:/#', $configured) || str_starts_with($configured, '/')) {
        return $configured;
    }

    return dirname(__DIR__) . '/' . ltrim($configured, '/');
}

function google_calendar_enabled(PDO $pdo): bool
{
    $s = google_calendar_settings($pdo);

    return $s['enabled'] && $s['internal_id'] !== '' && $s['public_id'] !== '' && is_file($s['sa_path']);
}

function google_calendar_get_token(PDO $pdo): string
{
    $s = google_calendar_settings($pdo);
    $credentials = google_sa_load_credentials($s['sa_path']);

    return google_calendar_sa_access_token($credentials);
}

function google_calendar_log(PDO $pdo, string $direction, ?string $entityType, ?int $entityId, string $message): void
{
    ensure_google_calendar_tables($pdo);
    $pdo->prepare('
        INSERT INTO google_calendar_sync_log (direction, entity_type, entity_id, message)
        VALUES (:dir, :et, :eid, :msg)
    ')->execute([
        'dir' => mb_substr($direction, 0, 16),
        'et' => $entityType !== null ? mb_substr($entityType, 0, 32) : null,
        'eid' => $entityId !== null && $entityId > 0 ? $entityId : null,
        'msg' => mb_substr($message, 0, 4000),
    ]);
}

/** @return list<string> */
function google_calendar_calendar_roles(): array
{
    return ['internal', 'public'];
}

function google_calendar_role_to_calendar_id(array $settings, string $role): string
{
    return $role === 'public' ? (string) $settings['public_id'] : (string) $settings['internal_id'];
}

function google_calendar_hari_ke_to_rrule(int $hariKe): ?string
{
    if ($hariKe === 0) {
        return 'RRULE:FREQ=DAILY';
    }
    $map = [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU'];
    $day = $map[$hariKe] ?? null;
    if ($day === null) {
        return null;
    }

    return 'RRULE:FREQ=WEEKLY;BYDAY=' . $day;
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function google_calendar_agenda_to_event(array $row): array
{
    $id = (int) ($row['id'] ?? 0);
    $win = akademik_agenda_window($row);
    $startDate = $win['start'];
    $endDate = $win['end'];
    $jm = trim((string) ($row['jam_mulai'] ?? ''));
    $js = trim((string) ($row['jam_selesai'] ?? ''));
    $allDay = ($jm === '' && $js === '');

    $event = [
        'summary' => mb_substr(trim((string) ($row['judul'] ?? 'Agenda')), 0, 200),
        'description' => trim((string) ($row['catatan'] ?? '')) . "\n[PWA agenda #" . $id . ']',
        'extendedProperties' => [
            'private' => [
                'pwa_entity_type' => 'agenda',
                'pwa_entity_id' => (string) $id,
            ],
        ],
    ];

    if ($allDay) {
        $event['start'] = ['date' => $startDate, 'timeZone' => GOOGLE_CALENDAR_TZ];
        $exEnd = date('Y-m-d', strtotime($endDate . ' +1 day'));
        $event['end'] = ['date' => $exEnd, 'timeZone' => GOOGLE_CALENDAR_TZ];
    } else {
        $startDt = $startDate . 'T' . substr($jm, 0, 8);
        $endBase = $js !== '' ? $js : $jm;
        $endDt = $endDate . 'T' . substr($endBase, 0, 8);
        $event['start'] = ['dateTime' => $startDt, 'timeZone' => GOOGLE_CALENDAR_TZ];
        $event['end'] = ['dateTime' => $endDt, 'timeZone' => GOOGLE_CALENDAR_TZ];
    }

    $ulang = (int) ($row['ulang_interval_hari'] ?? 0);
    if ($ulang > 0) {
        $event['recurrence'] = ['RRULE:FREQ=DAILY;INTERVAL=' . $ulang];
    }

    return $event;
}

/**
 * @param array<string, mixed> $row jadwal + nama_kegiatan
 * @return array<string, mixed>
 */
function google_calendar_jadwal_to_event(array $row): array
{
    $id = (int) ($row['id'] ?? 0);
    $nama = trim((string) ($row['nama_kegiatan'] ?? 'Kegiatan'));
    $ting = trim((string) ($row['tingkatan'] ?? ''));
    $summary = $ting !== '' ? ($nama . ' · ' . $ting) : $nama;
    $tempat = trim((string) ($row['tempat'] ?? ''));
    $desc = 'Jadwal pondok #' . $id;
    if ($tempat !== '') {
        $desc .= ' · ' . $tempat;
    }

    $jm = substr((string) ($row['jam_mulai'] ?? '07:00:00'), 0, 8);
    $js = substr((string) ($row['jam_selesai'] ?? '08:00:00'), 0, 8);
    $hariKe = (int) ($row['hari_ke'] ?? 1);
    $rrule = google_calendar_hari_ke_to_rrule($hariKe);

    $event = [
        'summary' => mb_substr($summary, 0, 200),
        'description' => $desc,
        'start' => [
            'dateTime' => date('Y-m-d') . 'T' . $jm,
            'timeZone' => GOOGLE_CALENDAR_TZ,
        ],
        'end' => [
            'dateTime' => date('Y-m-d') . 'T' . $js,
            'timeZone' => GOOGLE_CALENDAR_TZ,
        ],
        'extendedProperties' => [
            'private' => [
                'pwa_entity_type' => 'jadwal_slot',
                'pwa_entity_id' => (string) $id,
            ],
        ],
    ];
    if ($rrule !== null) {
        $event['recurrence'] = [$rrule];
    }

    return $event;
}

/**
 * @return array<string, mixed>|null
 */
function google_calendar_fetch_agenda(PDO $pdo, int $id): ?array
{
    ensure_akademik_agenda_table($pdo);
    $st = $pdo->prepare('SELECT * FROM akademik_agenda WHERE id = :id LIMIT 1');
    $st->execute(['id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @return array<string, mixed>|null
 */
function google_calendar_fetch_jadwal(PDO $pdo, int $id): ?array
{
    if (!table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan')) {
        return null;
    }
    $st = $pdo->prepare('
        SELECT j.*, k.nama_kegiatan
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        WHERE j.id = :id LIMIT 1
    ');
    $st->execute(['id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/**
 * @return array<string, mixed>|null
 */
function google_calendar_get_map(PDO $pdo, string $entityType, int $entityId, string $role): ?array
{
    ensure_google_calendar_tables($pdo);
    $st = $pdo->prepare('
        SELECT * FROM google_calendar_event_map
        WHERE entity_type = :et AND entity_id = :eid AND calendar_role = :role LIMIT 1
    ');
    $st->execute(['et' => $entityType, 'eid' => $entityId, 'role' => $role]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

function google_calendar_save_map(
    PDO $pdo,
    string $entityType,
    int $entityId,
    string $role,
    string $calendarId,
    string $eventId,
    ?string $etag
): void {
    ensure_google_calendar_tables($pdo);
    $now = date('Y-m-d H:i:s');
    $pdo->prepare('
        INSERT INTO google_calendar_event_map
            (entity_type, entity_id, calendar_role, google_calendar_id, google_event_id, google_etag, pwa_updated_at)
        VALUES (:et, :eid, :role, :cid, :ev, :etag, :pwa)
        ON DUPLICATE KEY UPDATE
            google_calendar_id = VALUES(google_calendar_id),
            google_event_id = VALUES(google_event_id),
            google_etag = VALUES(google_etag),
            pwa_updated_at = VALUES(pwa_updated_at)
    ')->execute([
        'et' => $entityType,
        'eid' => $entityId,
        'role' => $role,
        'cid' => $calendarId,
        'ev' => $eventId,
        'etag' => $etag,
        'pwa' => $now,
    ]);
}

function google_calendar_delete_maps(PDO $pdo, string $entityType, int $entityId): void
{
    ensure_google_calendar_tables($pdo);
    $pdo->prepare('DELETE FROM google_calendar_event_map WHERE entity_type = :et AND entity_id = :eid')
        ->execute(['et' => $entityType, 'eid' => $entityId]);
}

function google_calendar_push_entity_to_role(PDO $pdo, string $token, array $settings, string $entityType, int $entityId, string $role): void
{
    $calendarId = google_calendar_role_to_calendar_id($settings, $role);
    if ($calendarId === '') {
        return;
    }

    if ($entityType === 'agenda') {
        $row = google_calendar_fetch_agenda($pdo, $entityId);
        if ($row === null) {
            return;
        }
        $payload = google_calendar_agenda_to_event($row);
    } else {
        $row = google_calendar_fetch_jadwal($pdo, $entityId);
        if ($row === null) {
            return;
        }
        $payload = google_calendar_jadwal_to_event($row);
    }

    $map = google_calendar_get_map($pdo, $entityType, $entityId, $role);
    if ($map !== null && trim((string) ($map['google_event_id'] ?? '')) !== '') {
        $updated = google_calendar_events_patch(
            $token,
            $calendarId,
            (string) $map['google_event_id'],
            $payload
        );
        $eventId = (string) ($updated['id'] ?? $map['google_event_id']);
        $etag = isset($updated['etag']) ? (string) $updated['etag'] : null;
    } else {
        $created = google_calendar_events_insert($token, $calendarId, $payload);
        $eventId = trim((string) ($created['id'] ?? ''));
        if ($eventId === '') {
            throw new RuntimeException('Google Calendar tidak mengembalikan event id.');
        }
        $etag = isset($created['etag']) ? (string) $created['etag'] : null;
    }

    google_calendar_save_map($pdo, $entityType, $entityId, $role, $calendarId, $eventId, $etag);
}

function google_calendar_push_entity(PDO $pdo, string $entityType, int $entityId): void
{
    if (!google_calendar_enabled($pdo)) {
        return;
    }
    try {
        $settings = google_calendar_settings($pdo);
        $token = google_calendar_get_token($pdo);
        foreach (google_calendar_calendar_roles() as $role) {
            google_calendar_push_entity_to_role($pdo, $token, $settings, $entityType, $entityId, $role);
        }
        google_calendar_log($pdo, 'push', $entityType, $entityId, 'OK ke internal + publik');
    } catch (Throwable $e) {
        google_calendar_log($pdo, 'push', $entityType, $entityId, $e->getMessage());
        save_setting($pdo, 'google_calendar_last_error', $e->getMessage());
    }
}

function google_calendar_delete_entity(PDO $pdo, string $entityType, int $entityId): void
{
    if (!google_calendar_enabled($pdo)) {
        google_calendar_delete_maps($pdo, $entityType, $entityId);

        return;
    }
    try {
        $settings = google_calendar_settings($pdo);
        $token = google_calendar_get_token($pdo);
        ensure_google_calendar_tables($pdo);
        $st = $pdo->prepare('SELECT * FROM google_calendar_event_map WHERE entity_type = :et AND entity_id = :eid');
        $st->execute(['et' => $entityType, 'eid' => $entityId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $map) {
            try {
                google_calendar_events_delete(
                    $token,
                    (string) $map['google_calendar_id'],
                    (string) $map['google_event_id']
                );
            } catch (Throwable $e) {
                google_calendar_log($pdo, 'delete', $entityType, $entityId, $e->getMessage());
            }
        }
        google_calendar_delete_maps($pdo, $entityType, $entityId);
    } catch (Throwable $e) {
        google_calendar_log($pdo, 'delete', $entityType, $entityId, $e->getMessage());
    }
}

function google_calendar_push_agenda(PDO $pdo, int $agendaId): void
{
    if ($agendaId <= 0) {
        return;
    }
    google_calendar_push_entity($pdo, 'agenda', $agendaId);
}

function google_calendar_push_jadwal_slot(PDO $pdo, int $slotId): void
{
    if ($slotId <= 0) {
        return;
    }
    google_calendar_push_entity($pdo, 'jadwal_slot', $slotId);
}

/** @param list<int> $slotIds */
function google_calendar_push_jadwal_slots(PDO $pdo, array $slotIds): void
{
    foreach ($slotIds as $id) {
        google_calendar_push_jadwal_slot($pdo, (int) $id);
    }
}

/**
 * @param array<string, mixed> $gEvent
 */
function google_calendar_apply_event_to_pwa(PDO $pdo, string $sourceRole, array $gEvent): ?array
{
    $props = is_array($gEvent['extendedProperties']['private'] ?? null)
        ? $gEvent['extendedProperties']['private']
        : [];
    $entityType = trim((string) ($props['pwa_entity_type'] ?? ''));
    $entityId = (int) ($props['pwa_entity_id'] ?? 0);
    if ($entityId <= 0 && isset($gEvent['id'])) {
        ensure_google_calendar_tables($pdo);
        $st = $pdo->prepare('
            SELECT entity_type, entity_id FROM google_calendar_event_map
            WHERE google_event_id = :ev LIMIT 1
        ');
        $st->execute(['ev' => (string) $gEvent['id']]);
        $map = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($map)) {
            $entityType = (string) $map['entity_type'];
            $entityId = (int) $map['entity_id'];
        }
    }
    if ($entityId <= 0 || !in_array($entityType, ['agenda', 'jadwal_slot'], true)) {
        return null;
    }

    $status = strtolower(trim((string) ($gEvent['status'] ?? '')));
    if ($status === 'cancelled') {
        if ($entityType === 'agenda') {
            $pdo->prepare('DELETE FROM akademik_agenda WHERE id = :id')->execute(['id' => $entityId]);
        } else {
            $pdo->prepare('DELETE FROM jadwal_kegiatan WHERE id = :id')->execute(['id' => $entityId]);
        }
        google_calendar_delete_maps($pdo, $entityType, $entityId);

        return ['entity_type' => $entityType, 'entity_id' => $entityId, 'action' => 'deleted'];
    }

    $summary = trim((string) ($gEvent['summary'] ?? ''));
    $start = is_array($gEvent['start'] ?? null) ? $gEvent['start'] : [];
    $end = is_array($gEvent['end'] ?? null) ? $gEvent['end'] : [];
    $etag = isset($gEvent['etag']) ? (string) $gEvent['etag'] : null;
    $eventId = (string) ($gEvent['id'] ?? '');

    if ($entityType === 'agenda') {
        ensure_akademik_agenda_table($pdo);
        $dateStart = (string) ($start['date'] ?? substr((string) ($start['dateTime'] ?? ''), 0, 10));
        $dateEnd = (string) ($end['date'] ?? substr((string) ($end['dateTime'] ?? ''), 0, 10));
        if ($dateStart === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStart)) {
            return null;
        }
        if ($dateEnd !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateEnd)) {
            $dateEnd = date('Y-m-d', strtotime($dateEnd . ' -1 day'));
        } else {
            $dateEnd = $dateStart;
        }
        $jm = isset($start['dateTime']) ? substr((string) $start['dateTime'], 11, 8) : null;
        $js = isset($end['dateTime']) ? substr((string) $end['dateTime'], 11, 8) : null;
        $pdo->prepare('
            UPDATE akademik_agenda SET
                judul = :judul,
                tanggal = :tgl,
                tanggal_selesai = :tgl2,
                jam_mulai = :jm,
                jam_selesai = :js
            WHERE id = :id
        ')->execute([
            'judul' => $summary !== '' ? mb_substr($summary, 0, 200) : 'Agenda',
            'tgl' => $dateStart,
            'tgl2' => $dateEnd >= $dateStart ? $dateEnd : $dateStart,
            'jm' => $jm,
            'js' => $js,
            'id' => $entityId,
        ]);
    } else {
        if (!table_exists($pdo, 'jadwal_kegiatan')) {
            return null;
        }
        $jm = isset($start['dateTime']) ? substr((string) $start['dateTime'], 11, 8) : null;
        $js = isset($end['dateTime']) ? substr((string) $end['dateTime'], 11, 8) : null;
        if ($jm !== null && $js !== null) {
            $pdo->prepare('UPDATE jadwal_kegiatan SET jam_mulai = :jm, jam_selesai = :js WHERE id = :id')
                ->execute(['jm' => $jm, 'js' => $js, 'id' => $entityId]);
        }
    }

    if ($eventId !== '') {
        $settings = google_calendar_settings($pdo);
        $calendarId = google_calendar_role_to_calendar_id($settings, $sourceRole);
        google_calendar_save_map($pdo, $entityType, $entityId, $sourceRole, $calendarId, $eventId, $etag);
        $pdo->prepare('
            UPDATE google_calendar_event_map SET google_updated_at = :t
            WHERE entity_type = :et AND entity_id = :eid AND calendar_role = :role
        ')->execute([
            't' => date('Y-m-d H:i:s'),
            'et' => $entityType,
            'eid' => $entityId,
            'role' => $sourceRole,
        ]);
    }

    return ['entity_type' => $entityType, 'entity_id' => $entityId, 'action' => 'updated', 'source_role' => $sourceRole];
}

function google_calendar_mirror_entity_to_other(PDO $pdo, string $entityType, int $entityId, string $sourceRole): void
{
    if (!google_calendar_enabled($pdo)) {
        return;
    }
    $other = $sourceRole === 'internal' ? 'public' : 'internal';
    try {
        $settings = google_calendar_settings($pdo);
        $token = google_calendar_get_token($pdo);
        google_calendar_push_entity_to_role($pdo, $token, $settings, $entityType, $entityId, $other);
        google_calendar_log($pdo, 'mirror', $entityType, $entityId, 'Mirror ke ' . $other);
    } catch (Throwable $e) {
        google_calendar_log($pdo, 'mirror', $entityType, $entityId, $e->getMessage());
    }
}

function google_calendar_pull_role(PDO $pdo, string $role): int
{
    $settings = google_calendar_settings($pdo);
    $calendarId = google_calendar_role_to_calendar_id($settings, $role);
    if ($calendarId === '') {
        return 0;
    }
    $tokenKey = 'google_calendar_sync_token_' . $role;
    $syncToken = trim((string) app_setting($pdo, $tokenKey, ''));
    $token = google_calendar_get_token($pdo);
    $applied = 0;
    $pageToken = null;

    do {
        try {
            $page = google_calendar_events_list($token, $calendarId, $syncToken !== '' ? $syncToken : null, $pageToken);
        } catch (Throwable $e) {
            if ($syncToken !== '' && str_contains($e->getMessage(), '410')) {
                save_setting($pdo, $tokenKey, '');
                $syncToken = '';
                continue;
            }
            throw $e;
        }
        foreach ($page['items'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $result = google_calendar_apply_event_to_pwa($pdo, $role, $item);
            if ($result !== null) {
                $applied++;
                google_calendar_mirror_entity_to_other(
                    $pdo,
                    (string) $result['entity_type'],
                    (int) $result['entity_id'],
                    $role
                );
            }
        }
        $pageToken = $page['nextPageToken'] ?? null;
        if ($pageToken === null && !empty($page['nextSyncToken'])) {
            save_setting($pdo, $tokenKey, (string) $page['nextSyncToken']);
        }
    } while ($pageToken !== null && $pageToken !== '');

    return $applied;
}

/**
 * @return array{ok:bool,pulled:int,error:string}
 */
function google_calendar_sync_run(PDO $pdo, bool $fullPush = false): array
{
    ensure_google_calendar_tables($pdo);
    if (!google_calendar_enabled($pdo)) {
        return ['ok' => false, 'pulled' => 0, 'error' => 'Sync Google Calendar nonaktif atau belum lengkap.'];
    }

    $pulled = 0;
    try {
        if ($fullPush) {
            ensure_akademik_agenda_table($pdo);
            $agendaIds = $pdo->query('SELECT id FROM akademik_agenda ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($agendaIds as $aid) {
                google_calendar_push_agenda($pdo, (int) $aid);
            }
            if (table_exists($pdo, 'jadwal_kegiatan')) {
                $jadwalIds = $pdo->query('SELECT id FROM jadwal_kegiatan ORDER BY id ASC')->fetchAll(PDO::FETCH_COLUMN);
                foreach ($jadwalIds as $jid) {
                    google_calendar_push_jadwal_slot($pdo, (int) $jid);
                }
            }
        }
        foreach (google_calendar_calendar_roles() as $role) {
            $pulled += google_calendar_pull_role($pdo, $role);
        }
        save_setting($pdo, 'google_calendar_last_sync_at', date('Y-m-d H:i:s'));
        save_setting($pdo, 'google_calendar_last_error', '');

        return ['ok' => true, 'pulled' => $pulled, 'error' => ''];
    } catch (Throwable $e) {
        save_setting($pdo, 'google_calendar_last_error', $e->getMessage());
        google_calendar_log($pdo, 'pull', null, null, $e->getMessage());

        return ['ok' => false, 'pulled' => $pulled, 'error' => $e->getMessage()];
    }
}

/**
 * @return array{ok:bool,client_email:string,internal_summary:string,public_summary:string,error:string}
 */
function google_calendar_test_connection(PDO $pdo): array
{
    $settings = google_calendar_settings($pdo);
    try {
        $credentials = google_sa_load_credentials($settings['sa_path']);
        $token = google_calendar_sa_access_token($credentials);
        $intSum = '';
        $pubSum = '';
        if ($settings['internal_id'] !== '') {
            $cal = google_calendar_calendar_get($token, $settings['internal_id']);
            $intSum = (string) ($cal['summary'] ?? $settings['internal_id']);
        }
        if ($settings['public_id'] !== '') {
            $cal = google_calendar_calendar_get($token, $settings['public_id']);
            $pubSum = (string) ($cal['summary'] ?? $settings['public_id']);
        }

        return [
            'ok' => true,
            'client_email' => (string) ($credentials['client_email'] ?? ''),
            'internal_summary' => $intSum,
            'public_summary' => $pubSum,
            'error' => '',
        ];
    } catch (Throwable $e) {
        return [
            'ok' => false,
            'client_email' => '',
            'internal_summary' => '',
            'public_summary' => '',
            'error' => $e->getMessage(),
        ];
    }
}

function google_calendar_register_webhook(PDO $pdo, string $role, string $webhookBaseUrl): bool
{
    if (!google_calendar_enabled($pdo)) {
        return false;
    }
    $settings = google_calendar_settings($pdo);
    $calendarId = google_calendar_role_to_calendar_id($settings, $role);
    if ($calendarId === '') {
        return false;
    }
    $cronKey = $settings['cron_key'];
    if ($cronKey === '') {
        return false;
    }
    $token = google_calendar_get_token($pdo);
    $channelKey = 'google_calendar_webhook_' . $role;
    $oldRaw = trim((string) app_setting($pdo, $channelKey, ''));
    if ($oldRaw !== '') {
        $old = json_decode($oldRaw, true);
        if (is_array($old) && !empty($old['id']) && !empty($old['resourceId'])) {
            try {
                google_calendar_channels_stop($token, (string) $old['id'], (string) $old['resourceId']);
            } catch (Throwable $e) {
                // ignore
            }
        }
    }
    $channelId = bin2hex(random_bytes(16));
    $address = rtrim($webhookBaseUrl, '/') . '/api/google/calendar/webhook.php?key=' . rawurlencode($cronKey);
    $watch = google_calendar_watch_events($token, $calendarId, $channelId, $address, $cronKey);
    save_setting($pdo, $channelKey, json_encode([
        'id' => $channelId,
        'resourceId' => (string) ($watch['resourceId'] ?? ''),
        'expiration' => (string) ($watch['expiration'] ?? ''),
        'role' => $role,
    ], JSON_UNESCAPED_UNICODE));

    return true;
}

function google_calendar_webhook_handle(PDO $pdo): void
{
    $settings = google_calendar_settings($pdo);
    $provided = trim((string) ($_GET['key'] ?? $_SERVER['HTTP_X_GOOG_CHANNEL_TOKEN'] ?? ''));
    if ($settings['cron_key'] === '' || !hash_equals($settings['cron_key'], $provided)) {
        http_response_code(403);
        echo 'Forbidden';
        exit;
    }
    google_calendar_sync_run($pdo, false);
    http_response_code(200);
    echo 'OK';
}
