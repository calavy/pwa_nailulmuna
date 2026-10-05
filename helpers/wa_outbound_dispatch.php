<?php

declare(strict_types=1);

/**
 * Global outbound WA dispatch: queue, rate, cooldown, retry, opt-out.
 */

function wa_outbound_queue_enabled(PDO $pdo): bool
{
    return trim((string) app_setting($pdo, 'wa_outbound_queue_enabled', '1')) === '1';
}

function wa_outbound_cooldown_sec(PDO $pdo): int
{
    return max(0, min(3600, (int) app_setting($pdo, 'wa_outbound_cooldown_sec', '120')));
}

function wa_outbound_max_attempts(PDO $pdo): int
{
    return max(1, min(10, (int) app_setting($pdo, 'wa_outbound_max_attempts', '3')));
}

function wa_outbound_global_budget_per_tick(PDO $pdo): int
{
    return max(1, min(50, (int) app_setting($pdo, 'wa_outbound_global_budget_per_tick', '8')));
}

function wa_outbound_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    if (!function_exists('table_exists')) {
        return;
    }
    try {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS wa_outbound_queue (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                target_phone VARCHAR(40) NOT NULL,
                kind VARCHAR(40) NOT NULL DEFAULT "general",
                message MEDIUMTEXT NOT NULL,
                priority TINYINT UNSIGNED NOT NULL DEFAULT 40,
                dedup_key VARCHAR(191) NULL,
                status ENUM("pending","sending","retry_wait","sent","failed","dead") NOT NULL DEFAULT "pending",
                attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
                dispatch_source ENUM("automatic","manual") NOT NULL DEFAULT "automatic",
                payload_json TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_attempt_at DATETIME NULL,
                next_retry_at DATETIME NULL,
                sent_at DATETIME NULL,
                last_error VARCHAR(500) NULL,
                INDEX idx_wa_outbound_drain (status, next_retry_at, priority, id),
                INDEX idx_wa_outbound_target (target_phone, status),
                INDEX idx_wa_outbound_dedup (dedup_key),
                INDEX idx_wa_outbound_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS wa_opt_out (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                target_phone VARCHAR(40) NOT NULL,
                category VARCHAR(40) NOT NULL DEFAULT "all",
                status ENUM("opt_out","opt_in") NOT NULL DEFAULT "opt_out",
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                note VARCHAR(255) NULL,
                UNIQUE KEY uk_wa_opt_out_target_cat (target_phone, category),
                INDEX idx_wa_opt_out_phone (target_phone)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    } catch (Throwable $e) {
        error_log('[wa_outbound_ensure_schema] ' . $e->getMessage());
    }
    $done = table_exists($pdo, 'wa_outbound_queue');
}

/** @param array<string, mixed> $opts */
function wa_outbound_priority_for_kind(string $kind, array $opts = []): int
{
    if (isset($opts['priority'])) {
        return max(1, min(99, (int) $opts['priority']));
    }
    $dedup = trim((string) ($opts['dedup_key'] ?? ''));
    $kind = strtolower(trim($kind));
    if ($kind === 'izin' || str_starts_with($dedup, 'izin:')) {
        if (str_contains($dedup, 'pengasuh') || str_contains($dedup, 'pengajuan') || !empty($opts['urgent'])) {
            return 10;
        }

        return 30;
    }
    if (str_starts_with($dedup, 'rekap_') || str_starts_with($dedup, 'poin:') || $kind === 'poin') {
        return 50;
    }
    if (str_starts_with($dedup, 'cashless_laporan') || str_contains($dedup, 'laporan_harian')) {
        return 50;
    }
    if (in_array($kind, ['tagihan', 'presensi', 'alpa'], true)) {
        return 30;
    }
    if ($kind === 'cashless' || $kind === 'rapor') {
        return 40;
    }
    if (str_starts_with($dedup, 'yayasan_') || str_starts_with($dedup, 'pb_') || str_starts_with($dedup, 'mw_')) {
        return 40;
    }

    return 40;
}

function wa_outbound_is_urgent_priority(int $priority): bool
{
    return $priority <= 15;
}

function wa_outbound_is_opted_out(PDO $pdo, string $targetPhone, string $kind): bool
{
    wa_outbound_ensure_schema($pdo);
    if (!function_exists('table_exists') || !table_exists($pdo, 'wa_opt_out')) {
        return false;
    }
    $phone = wa_otomatis_normalize_target($targetPhone);
    if ($phone === '') {
        return false;
    }
    $kind = strtolower(trim($kind));
    try {
        $st = $pdo->prepare('
            SELECT 1 FROM wa_opt_out
            WHERE target_phone = :p AND status = "opt_out"
              AND (category = "all" OR category = :k)
            LIMIT 1
        ');
        $st->execute(['p' => $phone, 'k' => $kind]);

        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        error_log('[wa_outbound_is_opted_out] ' . $e->getMessage());

        return false;
    }
}

/** @return 'retryable'|'permanent'|'invalid_target' */
function wa_outbound_classify_error(string $error, int $httpCode = 0): string
{
    $blob = strtolower(trim($error));
    if (str_contains($blob, 'tidak valid') || (str_contains($blob, 'nomor') && str_contains($blob, 'valid'))) {
        return 'invalid_target';
    }
    if (str_contains($blob, 'token gateway') || str_contains($blob, 'belum diisi')
        || str_contains($blob, 'phone number id') || str_contains($blob, 'access token meta')
        || str_contains($blob, 'invalid token')) {
        return 'permanent';
    }
    if ($httpCode >= 400 && $httpCode < 500 && $httpCode !== 429) {
        return 'invalid_target';
    }

    return 'retryable';
}

function wa_outbound_backoff_seconds(int $attemptCount): int
{
    $n = max(1, min(5, $attemptCount));

    return min(900, 60 * (2 ** ($n - 1)));
}

/**
 * @param array<string, mixed> $opts
 */
function wa_outbound_sleep_usec_for_kind(PDO $pdo, string $kind, array $opts = []): int
{
    if ($kind === 'tagihan') {
        return wa_otomatis_delay_sleep_us(wa_fonte_safe_tagihan_delay($pdo), 12);
    }
    $globalRaw = trim((string) app_setting($pdo, 'wa_fonnte_api_delay', '8-12'));
    $globalMin = wa_otomatis_delay_min_seconds($globalRaw);
    if ($globalMin < 8) {
        $globalMin = 8;
    }
    $fonnteDelay = wa_otomatis_fonnte_api_delay($pdo, array_merge($opts, ['kind' => $kind]));
    $kindMin = wa_otomatis_delay_min_seconds($fonnteDelay);
    if ($kindMin < 8) {
        $kindMin = 8;
    }

    return max($globalMin, $kindMin) * 1000000;
}

/**
 * @param array<string, mixed> $opts
 * @return array{ok:bool,queue_id?:int,queued?:bool,skipped?:bool,skipped_reason?:string,error?:string}
 */
function wa_outbound_enqueue(PDO $pdo, string $targetRaw, string $message, array $opts = []): array
{
    wa_outbound_ensure_schema($pdo);
    if (!function_exists('table_exists') || !table_exists($pdo, 'wa_outbound_queue')) {
        return ['ok' => false, 'error' => 'queue_table_missing'];
    }

    $target = wa_otomatis_normalize_target($targetRaw);
    if ($target === '') {
        return ['ok' => false, 'error' => 'invalid_target'];
    }

    $kind = trim((string) ($opts['kind'] ?? 'general'));
    if ($kind === '') {
        $kind = 'general';
    }

    if (wa_outbound_is_opted_out($pdo, $target, $kind)) {
        return ['ok' => false, 'skipped' => true, 'skipped_reason' => 'opt_out'];
    }

    $dedupKey = trim((string) ($opts['dedup_key'] ?? ''));
    $skipDedup = !empty($opts['skip_dedup']);
    if ($dedupKey !== '' && !$skipDedup && wa_dispatch_dedup_succeeded($pdo, $dedupKey)) {
        return ['ok' => false, 'skipped' => true, 'skipped_reason' => 'duplicate'];
    }

    if ($dedupKey !== '') {
        try {
            $st = $pdo->prepare('
                SELECT id FROM wa_outbound_queue
                WHERE dedup_key = :d AND status IN ("pending","sending","retry_wait")
                LIMIT 1
            ');
            $st->execute(['d' => wa_dispatch_normalize_key($dedupKey)]);
            $existingId = $st->fetchColumn();
            if ($existingId !== false) {
                return [
                    'ok' => true,
                    'queued' => true,
                    'queue_id' => (int) $existingId,
                    'skipped_reason' => 'duplicate_queue',
                ];
            }
        } catch (Throwable $e) {
            error_log('[wa_outbound_enqueue] dedup check: ' . $e->getMessage());
        }
    }

    $priority = wa_outbound_priority_for_kind($kind, $opts);
    $source = !empty($opts['dispatch_manual']) || (($opts['dispatch_source'] ?? '') === 'manual')
        ? 'manual'
        : 'automatic';

    $payload = $opts;
    unset($payload['message']);
    $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($payloadJson === false) {
        $payloadJson = '{}';
    }

    $dedupStore = $dedupKey !== '' ? wa_dispatch_normalize_key($dedupKey) : null;

    try {
        $st = $pdo->prepare('
            INSERT INTO wa_outbound_queue
                (target_phone, kind, message, priority, dedup_key, status, attempt_count,
                 dispatch_source, payload_json, next_retry_at)
            VALUES
                (:target, :kind, :message, :priority, :dedup, "pending", 0,
                 :source, :payload, NOW())
        ');
        $st->execute([
            'target' => substr($target, 0, 40),
            'kind' => substr($kind, 0, 40),
            'message' => $message,
            'priority' => $priority,
            'dedup' => $dedupStore,
            'source' => $source,
            'payload' => $payloadJson,
        ]);

        return ['ok' => true, 'queued' => true, 'queue_id' => (int) $pdo->lastInsertId()];
    } catch (Throwable $e) {
        error_log('[wa_outbound_enqueue] ' . $e->getMessage());

        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * @param array<string, mixed> $context
 * @return array{
 *   sent:int,failed:int,deferred:int,skipped:int,details:list<array<string,mixed>>,
 *   last_error:string,budget_used:int
 * }
 */
function wa_outbound_drain(PDO $pdo, int $budget, array $context = []): array
{
    wa_outbound_ensure_schema($pdo);

    $result = [
        'sent' => 0,
        'failed' => 0,
        'deferred' => 0,
        'skipped' => 0,
        'details' => [],
        'last_error' => '',
        'budget_used' => 0,
    ];

    if (!wa_outbound_queue_enabled($pdo) || !table_exists($pdo, 'wa_outbound_queue')) {
        return $result;
    }

    if (wa_otomatis_gateway_error($pdo) !== null) {
        $result['last_error'] = (string) wa_otomatis_gateway_error($pdo);

        return $result;
    }

    $warmup = wa_fonte_bulk_blocked_reason($pdo);
    if ($warmup !== null) {
        $result['last_error'] = $warmup;

        return $result;
    }

    $globalCap = wa_outbound_global_budget_per_tick($pdo);
    $providerCap = wa_fonte_bulk_limit($pdo);
    $budget = max(0, min($budget, $globalCap, $providerCap));
    if ($budget <= 0) {
        return $result;
    }

    try {
        $pdo->exec('
            UPDATE wa_outbound_queue
            SET status = "retry_wait", next_retry_at = NOW()
            WHERE status = "sending"
              AND last_attempt_at IS NOT NULL
              AND last_attempt_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        ');
    } catch (Throwable $e) {
        error_log('[wa_outbound_drain] reset stale sending: ' . $e->getMessage());
    }

    static $lastGlobalSendAt = 0.0;
    $cooldownSec = wa_outbound_cooldown_sec($pdo);
    $maxAttempts = wa_outbound_max_attempts($pdo);
    $nowStr = date('Y-m-d H:i:s');
    $processed = 0;

    while ($processed < $budget) {
        $row = wa_outbound_claim_next_row($pdo, $nowStr);
        if ($row === null) {
            break;
        }

        $queueId = (int) ($row['id'] ?? 0);
        $target = (string) ($row['target_phone'] ?? '');
        $kind = (string) ($row['kind'] ?? 'general');
        $message = (string) ($row['message'] ?? '');
        $priority = (int) ($row['priority'] ?? 40);
        $dedupKey = trim((string) ($row['dedup_key'] ?? ''));
        $attemptCount = (int) ($row['attempt_count'] ?? 0);
        $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = [];
        }

        if (wa_outbound_is_opted_out($pdo, $target, $kind)) {
            wa_outbound_mark_dead($pdo, $queueId, 'opt_out');
            $result['skipped']++;
            $processed++;
            continue;
        }

        if ($dedupKey !== '' && wa_dispatch_dedup_succeeded($pdo, $dedupKey)) {
            wa_outbound_mark_sent_duplicate($pdo, $queueId);
            $result['skipped']++;
            $processed++;
            continue;
        }

        if ($cooldownSec > 0 && !wa_outbound_is_urgent_priority($priority)) {
            $lastSent = wa_outbound_target_last_sent_ts($pdo, $target);
            if ($lastSent > 0 && (microtime(true) - $lastSent) < $cooldownSec) {
                $waitUntil = date('Y-m-d H:i:s', (int) ($lastSent + $cooldownSec));
                wa_outbound_defer_cooldown($pdo, $queueId, $waitUntil);
                $result['deferred']++;
                $processed++;
                continue;
            }
        }

        if ($lastGlobalSendAt > 0) {
            $sleepUs = wa_outbound_sleep_usec_for_kind($pdo, $kind, $payload);
            $elapsed = (microtime(true) - $lastGlobalSendAt) * 1000000;
            if ($elapsed < $sleepUs) {
                usleep((int) ($sleepUs - $elapsed));
            }
        }

        $sendOpts = $payload;
        $sendOpts['kind'] = $kind;
        if ($dedupKey !== '') {
            $sendOpts['dedup_key'] = $dedupKey;
        }
        $sendOpts['_from_outbound_worker'] = true;

        $sendResult = wa_otomatis_send_direct($pdo, $target, $message, $sendOpts);
        $lastGlobalSendAt = microtime(true);
        $processed++;
        $result['budget_used'] = $processed;

        $detail = array_merge($sendResult, ['queue_id' => $queueId, 'kind' => $kind, 'dedup_key' => $dedupKey]);
        $result['details'][] = $detail;

        if (!empty($sendResult['skipped'])) {
            wa_outbound_mark_sent_duplicate($pdo, $queueId);
            $result['skipped']++;
            continue;
        }

        if ($sendResult['success'] ?? false) {
            wa_outbound_mark_sent($pdo, $queueId);
            $result['sent']++;
            save_setting($pdo, 'wa_outbound_last_global_send_at', date('Y-m-d H:i:s'));
            continue;
        }

        $err = (string) ($sendResult['error'] ?? 'send_failed');
        $result['last_error'] = $err;
        $class = wa_outbound_classify_error($err, (int) ($sendResult['http_code'] ?? 0));
        $attemptCount++;
        if ($class === 'invalid_target' || $class === 'permanent' || $attemptCount >= $maxAttempts) {
            wa_outbound_mark_dead($pdo, $queueId, $err, $attemptCount);
            $result['failed']++;
        } else {
            $backoff = wa_outbound_backoff_seconds($attemptCount);
            wa_outbound_mark_retry_wait($pdo, $queueId, $err, $attemptCount, $backoff);
            $result['failed']++;
        }
    }

    wa_outbound_record_stats($pdo, $result);

    return $result;
}

/** @return array<string, mixed>|null */
function wa_outbound_claim_next_row(PDO $pdo, string $nowStr): ?array
{
    try {
        $st = $pdo->query('
            SELECT id, target_phone, kind, message, priority, dedup_key, attempt_count, payload_json
            FROM wa_outbound_queue
            WHERE status IN ("pending","retry_wait")
              AND (next_retry_at IS NULL OR next_retry_at <= NOW())
            ORDER BY priority ASC, id ASC
            LIMIT 1
        ');
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        if (!is_array($row)) {
            return null;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            return null;
        }
        $claim = $pdo->prepare('
            UPDATE wa_outbound_queue
            SET status = "sending", last_attempt_at = :now
            WHERE id = :id AND status IN ("pending","retry_wait")
        ');
        $claim->execute(['now' => $nowStr, 'id' => $id]);
        if ($claim->rowCount() === 0) {
            return null;
        }

        return $row;
    } catch (Throwable $e) {
        error_log('[wa_outbound_claim_next_row] ' . $e->getMessage());

        return null;
    }
}

function wa_outbound_target_last_sent_ts(PDO $pdo, string $target): float
{
    if (!table_exists($pdo, 'wa_outbound_queue')) {
        return 0.0;
    }
    $target = wa_otomatis_normalize_target($target);
    try {
        $st = $pdo->prepare('
            SELECT UNIX_TIMESTAMP(sent_at) AS ts
            FROM wa_outbound_queue
            WHERE target_phone = :t AND status = "sent" AND sent_at IS NOT NULL
            ORDER BY sent_at DESC
            LIMIT 1
        ');
        $st->execute(['t' => $target]);
        $ts = $st->fetchColumn();

        return is_numeric($ts) ? (float) $ts : 0.0;
    } catch (Throwable $e) {
        return 0.0;
    }
}

function wa_outbound_defer_cooldown(PDO $pdo, int $queueId, string $waitUntil): void
{
    try {
        $pdo->prepare('
            UPDATE wa_outbound_queue
            SET status = "retry_wait", next_retry_at = :w
            WHERE id = :id AND status = "sending"
        ')->execute(['w' => $waitUntil, 'id' => $queueId]);
    } catch (Throwable $e) {
        error_log('[wa_outbound_defer_cooldown] ' . $e->getMessage());
    }
}

function wa_outbound_mark_sent(PDO $pdo, int $queueId): void
{
    try {
        $pdo->prepare('
            UPDATE wa_outbound_queue
            SET status = "sent", sent_at = NOW(), last_error = NULL
            WHERE id = :id
        ')->execute(['id' => $queueId]);
    } catch (Throwable $e) {
        error_log('[wa_outbound_mark_sent] ' . $e->getMessage());
    }
}

function wa_outbound_mark_sent_duplicate(PDO $pdo, int $queueId): void
{
    try {
        $pdo->prepare('
            UPDATE wa_outbound_queue
            SET status = "sent", sent_at = NOW(), last_error = "skipped_duplicate"
            WHERE id = :id
        ')->execute(['id' => $queueId]);
    } catch (Throwable $e) {
        error_log('[wa_outbound_mark_sent_duplicate] ' . $e->getMessage());
    }
}

function wa_outbound_mark_dead(PDO $pdo, int $queueId, string $error, ?int $attemptCount = null): void
{
    try {
        $params = ['err' => substr($error, 0, 500), 'id' => $queueId];
        $sql = '
            UPDATE wa_outbound_queue
            SET status = "dead", last_error = :err, last_attempt_at = NOW()
        ';
        if ($attemptCount !== null) {
            $sql .= ', attempt_count = :ac';
            $params['ac'] = $attemptCount;
        }
        $sql .= ' WHERE id = :id';
        $pdo->prepare($sql)->execute($params);
    } catch (Throwable $e) {
        error_log('[wa_outbound_mark_dead] ' . $e->getMessage());
    }
}

function wa_outbound_mark_retry_wait(PDO $pdo, int $queueId, string $error, int $attemptCount, int $backoffSec): void
{
    try {
        $pdo->prepare('
            UPDATE wa_outbound_queue
            SET status = "retry_wait",
                attempt_count = :ac,
                last_error = :err,
                last_attempt_at = NOW(),
                next_retry_at = DATE_ADD(NOW(), INTERVAL :sec SECOND)
            WHERE id = :id
        ')->execute([
            'ac' => $attemptCount,
            'err' => substr($error, 0, 500),
            'sec' => max(1, $backoffSec),
            'id' => $queueId,
        ]);
    } catch (Throwable $e) {
        error_log('[wa_outbound_mark_retry_wait] ' . $e->getMessage());
    }
}

/** @param array<string, mixed> $drainResult */
function wa_outbound_record_stats(PDO $pdo, array $drainResult): void
{
    try {
        $raw = trim((string) app_setting($pdo, 'wa_outbound_stats_json', ''));
        $stats = $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($stats)) {
            $stats = [];
        }
        $stats['lifetime_sent'] = (int) ($stats['lifetime_sent'] ?? 0) + (int) ($drainResult['sent'] ?? 0);
        $stats['lifetime_failed'] = (int) ($stats['lifetime_failed'] ?? 0) + (int) ($drainResult['failed'] ?? 0);
        $stats['last_drain_at'] = date('Y-m-d H:i:s');
        if (($drainResult['sent'] ?? 0) > 0) {
            $stats['last_global_send_at'] = date('Y-m-d H:i:s');
        }
        foreach ($drainResult['details'] ?? [] as $d) {
            if (!is_array($d) || !($d['success'] ?? false)) {
                continue;
            }
            $k = (string) ($d['kind'] ?? 'general');
            $stats['per_kind'][$k] = (int) ($stats['per_kind'][$k] ?? 0) + 1;
        }
        save_setting($pdo, 'wa_outbound_stats_json', json_encode($stats, JSON_UNESCAPED_UNICODE));
    } catch (Throwable $e) {
        error_log('[wa_outbound_record_stats] ' . $e->getMessage());
    }
}

/** @return array<string, mixed> */
function wa_outbound_queue_snapshot(PDO $pdo): array
{
    wa_outbound_ensure_schema($pdo);
    $out = [
        'enabled' => wa_outbound_queue_enabled($pdo),
        'table_exists' => function_exists('table_exists') && table_exists($pdo, 'wa_outbound_queue'),
        'counts' => [],
        'per_kind_pending' => [],
        'oldest_pending' => null,
        'last_global_send' => trim((string) app_setting($pdo, 'wa_outbound_last_global_send_at', '')),
        'stats' => json_decode((string) app_setting($pdo, 'wa_outbound_stats_json', ''), true),
        'global_budget_per_tick' => wa_outbound_global_budget_per_tick($pdo),
        'cooldown_sec' => wa_outbound_cooldown_sec($pdo),
        'sent_24h' => 0,
    ];
    if (!$out['table_exists']) {
        return $out;
    }
    try {
        $st = $pdo->query('
            SELECT status, COUNT(*) AS cnt FROM wa_outbound_queue GROUP BY status
        ');
        foreach ($st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $r) {
            $out['counts'][(string) ($r['status'] ?? '')] = (int) ($r['cnt'] ?? 0);
        }
        $st2 = $pdo->query('
            SELECT kind, COUNT(*) AS cnt FROM wa_outbound_queue
            WHERE status IN ("pending","sending","retry_wait") GROUP BY kind
        ');
        foreach ($st2 ? ($st2->fetchAll(PDO::FETCH_ASSOC) ?: []) : [] as $r) {
            $out['per_kind_pending'][(string) ($r['kind'] ?? '')] = (int) ($r['cnt'] ?? 0);
        }
        $oldest = $pdo->query('
            SELECT id, created_at, kind, target_phone FROM wa_outbound_queue
            WHERE status IN ("pending","retry_wait") ORDER BY id ASC LIMIT 1
        ');
        $out['oldest_pending'] = $oldest ? ($oldest->fetch(PDO::FETCH_ASSOC) ?: null) : null;
        $err = $pdo->query('
            SELECT last_error, last_attempt_at FROM wa_outbound_queue
            WHERE last_error IS NOT NULL AND last_error != ""
            ORDER BY last_attempt_at DESC LIMIT 1
        ');
        $out['last_error_row'] = $err ? ($err->fetch(PDO::FETCH_ASSOC) ?: null) : null;
    } catch (Throwable $e) {
        $out['error'] = $e->getMessage();
    }

    try {
        $st24 = $pdo->query('
            SELECT COUNT(*) FROM wa_outbound_queue
            WHERE status = "sent" AND sent_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ');
        $out['sent_24h'] = $st24 ? (int) $st24->fetchColumn() : 0;
    } catch (Throwable $e) {
        $out['sent_24h'] = 0;
    }

    return $out;
}

/** @return list<string> */
function wa_opt_out_category_whitelist(): array
{
    $cats = ['all', 'general'];
    if (function_exists('wa_otomatis_delay_kinds')) {
        foreach (array_keys(wa_otomatis_delay_kinds()) as $k) {
            $cats[] = (string) $k;
        }
    }
    $cats[] = 'rapor';
    $cats[] = 'izin';

    return array_values(array_unique($cats));
}

function wa_outbound_mask_phone(string $phone): string
{
    $phone = trim($phone);
    if ($phone === '') {
        return '—';
    }
    if (strlen($phone) <= 8) {
        return '***';
    }

    return substr($phone, 0, 4) . '***' . substr($phone, -3);
}

/**
 * @return list<array<string, mixed>>
 */
function wa_outbound_queue_recent_rows(PDO $pdo, int $limit = 20): array
{
    wa_outbound_ensure_schema($pdo);
    if (!function_exists('table_exists') || !table_exists($pdo, 'wa_outbound_queue')) {
        return [];
    }
    $limit = max(1, min(50, $limit));
    try {
        $st = $pdo->query('
            SELECT id, created_at, priority, kind, target_phone, status, attempt_count,
                   next_retry_at, last_error, last_attempt_at
            FROM wa_outbound_queue
            WHERE status IN ("pending","retry_wait","sending","dead")
            ORDER BY priority ASC, id ASC
            LIMIT ' . $limit);

        return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        error_log('[wa_outbound_queue_recent_rows] ' . $e->getMessage());

        return [];
    }
}

/**
 * @return array{rows:list<array<string,mixed>>,total:int}
 */
function wa_opt_out_list(PDO $pdo, ?string $statusFilter = null, ?string $categoryFilter = null, int $limit = 100, int $offset = 0): array
{
    wa_outbound_ensure_schema($pdo);
    if (!table_exists($pdo, 'wa_opt_out')) {
        return ['rows' => [], 'total' => 0];
    }
    $limit = max(1, min(200, $limit));
    $offset = max(0, $offset);
    $where = ['1=1'];
    $params = [];
    if ($statusFilter !== null && in_array($statusFilter, ['opt_out', 'opt_in'], true)) {
        $where[] = 'status = :st';
        $params['st'] = $statusFilter;
    }
    if ($categoryFilter !== null && $categoryFilter !== '' && in_array($categoryFilter, wa_opt_out_category_whitelist(), true)) {
        $where[] = 'category = :cat';
        $params['cat'] = $categoryFilter;
    }
    $whereSql = implode(' AND ', $where);
    try {
        $stCount = $pdo->prepare('SELECT COUNT(*) FROM wa_opt_out WHERE ' . $whereSql);
        $stCount->execute($params);
        $total = (int) $stCount->fetchColumn();
        $st = $pdo->prepare('
            SELECT id, target_phone, category, status, created_at, note
            FROM wa_opt_out
            WHERE ' . $whereSql . '
            ORDER BY id DESC
            LIMIT ' . $limit . ' OFFSET ' . $offset);
        $st->execute($params);

        return ['rows' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
    } catch (Throwable $e) {
        error_log('[wa_opt_out_list] ' . $e->getMessage());

        return ['rows' => [], 'total' => 0];
    }
}

/** @return array{ok:bool,message:string} */
function wa_opt_out_set(PDO $pdo, string $phoneRaw, string $category, string $status, string $note = ''): array
{
    wa_outbound_ensure_schema($pdo);
    if (!table_exists($pdo, 'wa_opt_out')) {
        return ['ok' => false, 'message' => 'Tabel opt-out belum tersedia.'];
    }
    $phone = wa_otomatis_normalize_target($phoneRaw);
    if ($phone === '') {
        return ['ok' => false, 'message' => 'Nomor WA tidak valid.'];
    }
    $category = strtolower(trim($category));
    if (!in_array($category, wa_opt_out_category_whitelist(), true)) {
        return ['ok' => false, 'message' => 'Kategori tidak valid.'];
    }
    if (!in_array($status, ['opt_out', 'opt_in'], true)) {
        return ['ok' => false, 'message' => 'Status tidak valid.'];
    }
    $note = substr(trim($note), 0, 255);
    try {
        $pdo->prepare('
            INSERT INTO wa_opt_out (target_phone, category, status, note)
            VALUES (:p, :c, :s, :n)
            ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note), created_at = CURRENT_TIMESTAMP
        ')->execute([
            'p' => $phone,
            'c' => $category,
            's' => $status,
            'n' => $note !== '' ? $note : null,
        ]);

        return [
            'ok' => true,
            'message' => $status === 'opt_out'
                ? 'Nomor dicatat opt-out untuk kategori ' . $category . '.'
                : 'Opt-out dicabut (opt-in) untuk kategori ' . $category . '.',
        ];
    } catch (Throwable $e) {
        error_log('[wa_opt_out_set] ' . $e->getMessage());

        return ['ok' => false, 'message' => 'Gagal menyimpan: ' . $e->getMessage()];
    }
}

/**
 * Map hasil drain ke santri dari dedup tagihan.
 *
 * @param list<array<string, mixed>> $details
 * @return array{sent:int,failed:int,santri_sent:list<int>,santri_failed:list<int>}
 */
function wa_outbound_tagihan_apply_drain_details(array $details, string $sendKey): array
{
    $out = ['sent' => 0, 'failed' => 0, 'santri_sent' => [], 'santri_failed' => []];
    $prefix = 'tagihan:' . $sendKey . ':santri:';
    foreach ($details as $d) {
        if (!is_array($d)) {
            continue;
        }
        $dk = (string) ($d['dedup_key'] ?? '');
        if (!str_starts_with($dk, $prefix)) {
            continue;
        }
        $sid = (int) substr($dk, strlen($prefix));
        if ($sid <= 0) {
            continue;
        }
        if (!empty($d['skipped'])) {
            continue;
        }
        if ($d['success'] ?? false) {
            $out['sent']++;
            $out['santri_sent'][] = $sid;
        } else {
            $out['failed']++;
            $out['santri_failed'][] = $sid;
        }
    }

    return $out;
}
