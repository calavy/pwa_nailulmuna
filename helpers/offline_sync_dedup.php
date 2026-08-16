<?php

declare(strict_types=1);

/** Pastikan tabel log idempotensi offline ada. */
function offline_sync_ensure_log_table(PDO $pdo): void
{
    if (!function_exists('table_exists')) {
        require_once __DIR__ . '/app.php';
    }
    if (table_exists($pdo, 'offline_sync_log')) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS offline_sync_log (
            client_uuid VARCHAR(36) NOT NULL PRIMARY KEY,
            module ENUM('presensi_scan', 'poin_input') NOT NULL,
            user_id INT NOT NULL DEFAULT 0,
            result ENUM('accepted', 'duplicate', 'conflict_lost', 'error') NOT NULL DEFAULT 'accepted',
            server_record_id INT NULL,
            client_created_at DATETIME NULL,
            synced_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_offline_sync_module (module, synced_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

/**
 * @return array{handled:bool,type:string,message:string,conflict?:bool,winner?:string}|null
 */
function offline_sync_idempotent_response(PDO $pdo, string $clientUuid, string $module): ?array
{
    $clientUuid = trim($clientUuid);
    if ($clientUuid === '' || !preg_match('/^[a-f0-9-]{36}$/i', $clientUuid)) {
        return null;
    }
    offline_sync_ensure_log_table($pdo);
    $st = $pdo->prepare('SELECT result, server_record_id FROM offline_sync_log WHERE client_uuid = :uuid AND module = :mod LIMIT 1');
    $st->execute(['uuid' => $clientUuid, 'mod' => $module]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $result = (string) ($row['result'] ?? '');
    if ($result === 'accepted') {
        return [
            'handled' => true,
            'type' => 'success',
            'message' => 'Data sudah tersinkronkan sebelumnya.',
            'conflict' => false,
            'winner' => 'server',
        ];
    }
    if ($result === 'duplicate' || $result === 'conflict_lost') {
        return [
            'handled' => true,
            'type' => 'duplicate',
            'message' => 'Duplikat — presensi/poin sudah tercatat dari perangkat atau waktu lain.',
            'conflict' => true,
            'winner' => 'server',
        ];
    }

    return null;
}

function offline_sync_log_write(
    PDO $pdo,
    string $clientUuid,
    string $module,
    int $userId,
    string $result,
    ?int $serverRecordId = null,
    ?string $clientCreatedAt = null
): void {
    $clientUuid = trim($clientUuid);
    if ($clientUuid === '' || !preg_match('/^[a-f0-9-]{36}$/i', $clientUuid)) {
        return;
    }
    if (!in_array($module, ['presensi_scan', 'poin_input'], true)) {
        return;
    }
    if (!in_array($result, ['accepted', 'duplicate', 'conflict_lost', 'error'], true)) {
        $result = 'error';
    }
    offline_sync_ensure_log_table($pdo);
    $st = $pdo->prepare('
        INSERT INTO offline_sync_log (client_uuid, module, user_id, result, server_record_id, client_created_at)
        VALUES (:uuid, :mod, :uid, :res, :rid, :cat)
        ON DUPLICATE KEY UPDATE
            result = VALUES(result),
            server_record_id = COALESCE(VALUES(server_record_id), server_record_id),
            synced_at = CURRENT_TIMESTAMP
    ');
    $st->execute([
        'uuid' => $clientUuid,
        'mod' => $module,
        'uid' => max(0, $userId),
        'res' => $result,
        'rid' => $serverRecordId,
        'cat' => $clientCreatedAt !== null && $clientCreatedAt !== '' ? $clientCreatedAt : null,
    ]);
}

/** Bandingkan jam presensi — return negative jika client lebih awal. */
function offline_sync_compare_time_hms(string $a, string $b): int
{
    $norm = static function (string $t): string {
        $t = trim($t);
        if (strlen($t) === 5) {
            return $t . ':00';
        }

        return $t;
    };

    return strcmp($norm($a), $norm($b));
}

/**
 * Keputusan saat presensi santri sudah ada untuk kegiatan+tanggal yang sama.
 *
 * @param array<string,mixed> $existingRow
 * @return array{action:string,message?:string}
 */
function offline_sync_presensi_existing_decision(array $existingRow, string $clientJam, bool $fromClient): array
{
    if (!$fromClient) {
        return [
            'action' => 'duplicate',
            'message' => 'Presensi sudah tercatat untuk kegiatan aktif ini. Scan ditolak.',
        ];
    }
    $serverJam = (string) ($existingRow['jam_presensi'] ?? '');
    $cmp = offline_sync_compare_time_hms($clientJam, $serverJam);
    if ($cmp < 0) {
        return ['action' => 'replace'];
    }

    return [
        'action' => 'duplicate',
        'message' => 'Duplikat — sudah tercatat pukul ' . substr($serverJam, 0, 5) . '. Scan offline lebih lambat dilewati.',
    ];
}

function offline_sync_client_uuid_from_post(array $post): string
{
    $uuid = trim((string) ($post['client_uuid'] ?? $post['client_token'] ?? ''));

    return preg_match('/^[a-f0-9-]{36}$/i', $uuid) ? $uuid : '';
}
