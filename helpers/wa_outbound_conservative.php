<?php

declare(strict_types=1);

/**
 * Conservative dispatch: dispatch_id, circuit breaker, pause/resume, daily budget.
 */

function wa_outbound_daily_budget(PDO $pdo): int
{
    return max(0, min(5000, (int) app_setting($pdo, 'wa_outbound_daily_budget', '400')));
}

function wa_outbound_is_paused(PDO $pdo): bool
{
    return trim((string) app_setting($pdo, 'wa_outbound_paused', '0')) === '1';
}

function wa_outbound_pause(PDO $pdo, string $reason = '', ?int $userId = null): void
{
    save_setting($pdo, 'wa_outbound_paused', '1');
    save_setting($pdo, 'wa_outbound_paused_at', date('Y-m-d H:i:s'));
    save_setting($pdo, 'wa_outbound_paused_reason', substr(trim($reason), 0, 500));
    if ($userId !== null && $userId > 0) {
        save_setting($pdo, 'wa_outbound_paused_by', (string) $userId);
    }
}

function wa_outbound_resume(PDO $pdo): void
{
    save_setting($pdo, 'wa_outbound_paused', '0');
    save_setting($pdo, 'wa_outbound_resume_started_at', date('Y-m-d H:i:s'));
    save_setting($pdo, 'wa_outbound_resume_ramp_ticks', '0');
}

/** @return array<string, mixed> */
function wa_outbound_pause_status(PDO $pdo): array
{
    return [
        'paused' => wa_outbound_is_paused($pdo),
        'paused_at' => trim((string) app_setting($pdo, 'wa_outbound_paused_at', '')),
        'paused_reason' => trim((string) app_setting($pdo, 'wa_outbound_paused_reason', '')),
        'resume_started_at' => trim((string) app_setting($pdo, 'wa_outbound_resume_started_at', '')),
    ];
}

function wa_outbound_daily_sent_reset_if_needed(PDO $pdo): void
{
    $today = date('Y-m-d');
    $stored = trim((string) app_setting($pdo, 'wa_outbound_daily_budget_date', ''));
    if ($stored === $today) {
        return;
    }
    save_setting($pdo, 'wa_outbound_daily_budget_date', $today);
    save_setting($pdo, 'wa_outbound_daily_sent_count', '0');
}

function wa_outbound_daily_sent_count(PDO $pdo): int
{
    wa_outbound_daily_sent_reset_if_needed($pdo);

    return max(0, (int) app_setting($pdo, 'wa_outbound_daily_sent_count', '0'));
}

function wa_outbound_daily_remaining(PDO $pdo): int
{
    $cap = wa_outbound_daily_budget($pdo);
    if ($cap <= 0) {
        return PHP_INT_MAX;
    }

    return max(0, $cap - wa_outbound_daily_sent_count($pdo));
}

function wa_outbound_daily_record_sent(PDO $pdo, int $n = 1): void
{
    if ($n <= 0) {
        return;
    }
    wa_outbound_daily_sent_reset_if_needed($pdo);
    $cur = wa_outbound_daily_sent_count($pdo);
    save_setting($pdo, 'wa_outbound_daily_sent_count', (string) ($cur + $n));
}

function wa_outbound_effective_budget_per_tick(PDO $pdo): int
{
    $base = wa_outbound_global_budget_per_tick($pdo);
    $resumeAt = trim((string) app_setting($pdo, 'wa_outbound_resume_started_at', ''));
    if ($resumeAt === '') {
        return $base;
    }
    $ts = strtotime($resumeAt);
    if ($ts === false || (time() - $ts) > 3600) {
        return $base;
    }
    $ticks = max(0, (int) app_setting($pdo, 'wa_outbound_resume_ramp_ticks', '0'));
    save_setting($pdo, 'wa_outbound_resume_ramp_ticks', (string) ($ticks + 1));
    if ($ticks < 3) {
        return max(1, min($base, 2));
    }
    if ($ticks < 6) {
        return max(1, min($base, 4));
    }

    return $base;
}

function wa_outbound_circuit_state(PDO $pdo): string
{
    $state = strtolower(trim((string) app_setting($pdo, 'wa_outbound_circuit_state', 'closed')));
    if (!in_array($state, ['closed', 'open', 'half_open'], true)) {
        return 'closed';
    }
    if ($state === 'open') {
        $until = trim((string) app_setting($pdo, 'wa_outbound_circuit_open_until', ''));
        if ($until !== '' && strtotime($until) !== false && time() >= strtotime($until)) {
            save_setting($pdo, 'wa_outbound_circuit_state', 'half_open');

            return 'half_open';
        }
    }

    return $state;
}

function wa_outbound_circuit_blocks_drain(PDO $pdo): bool
{
    return wa_outbound_circuit_state($pdo) === 'open';
}

function wa_outbound_circuit_open(PDO $pdo, string $reason, int $durationSec): void
{
    $durationSec = max(60, min(7200, $durationSec));
    save_setting($pdo, 'wa_outbound_circuit_state', 'open');
    save_setting($pdo, 'wa_outbound_circuit_open_until', date('Y-m-d H:i:s', time() + $durationSec));
    save_setting($pdo, 'wa_outbound_circuit_reason', substr(trim($reason), 0, 500));
    save_setting($pdo, 'wa_outbound_circuit_opened_at', date('Y-m-d H:i:s'));
}

function wa_outbound_circuit_close(PDO $pdo): void
{
    save_setting($pdo, 'wa_outbound_circuit_state', 'closed');
    save_setting($pdo, 'wa_outbound_circuit_open_until', '');
    save_setting($pdo, 'wa_outbound_circuit_reason', '');
    save_setting($pdo, 'wa_outbound_fail_window_json', '');
}

function wa_outbound_circuit_record_failure(PDO $pdo, string $reason): void
{
    $windowSec = max(60, min(3600, (int) app_setting($pdo, 'wa_outbound_circuit_window_sec', '600')));
    $threshold = max(2, min(20, (int) app_setting($pdo, 'wa_outbound_circuit_fail_threshold', '5')));
    $raw = trim((string) app_setting($pdo, 'wa_outbound_fail_window_json', ''));
    $win = $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($win)) {
        $win = [];
    }
    $start = (int) ($win['window_start'] ?? 0);
    $count = (int) ($win['fail_count'] ?? 0);
    $now = time();
    if ($start <= 0 || ($now - $start) > $windowSec) {
        $start = $now;
        $count = 0;
    }
    $count++;
    $win = ['window_start' => $start, 'fail_count' => $count, 'last_reason' => substr($reason, 0, 200)];
    save_setting($pdo, 'wa_outbound_fail_window_json', json_encode($win, JSON_UNESCAPED_UNICODE));

    if ($count >= $threshold) {
        $opens = max(0, (int) app_setting($pdo, 'wa_outbound_circuit_open_count', '0'));
        $dur = min(1800, 300 * (2 ** min(4, $opens)));
        save_setting($pdo, 'wa_outbound_circuit_open_count', (string) ($opens + 1));
        wa_outbound_circuit_open($pdo, $reason . ' (' . $count . ' failures/' . $windowSec . 's)', $dur);
    }
}

function wa_outbound_circuit_record_success(PDO $pdo): void
{
    save_setting($pdo, 'wa_outbound_fail_window_json', '');
    save_setting($pdo, 'wa_outbound_circuit_open_count', '0');
    if (wa_outbound_circuit_state($pdo) === 'half_open') {
        wa_outbound_circuit_close($pdo);
    }
}

/** @return array<string, mixed> */
function wa_outbound_circuit_status(PDO $pdo): array
{
    return [
        'state' => wa_outbound_circuit_state($pdo),
        'open_until' => trim((string) app_setting($pdo, 'wa_outbound_circuit_open_until', '')),
        'reason' => trim((string) app_setting($pdo, 'wa_outbound_circuit_reason', '')),
        'fail_window' => json_decode((string) app_setting($pdo, 'wa_outbound_fail_window_json', ''), true),
    ];
}

function wa_outbound_migrate_schema_extras(PDO $pdo): void
{
    static $migrated = false;
    if ($migrated) {
        return;
    }
    if (!function_exists('table_exists') || !table_exists($pdo, 'wa_outbound_queue')) {
        return;
    }
    try {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS wa_dispatch_id_seq (
                seq_date DATE NOT NULL PRIMARY KEY,
                last_num INT UNSIGNED NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    } catch (Throwable $e) {
        error_log('[wa_outbound_migrate] seq: ' . $e->getMessage());
    }
    $cols = [
        'dispatch_id' => 'VARCHAR(24) NULL',
        'provider_message_id' => 'VARCHAR(128) NULL',
        'retry_reason' => 'VARCHAR(64) NULL',
    ];
    foreach ($cols as $col => $def) {
        if (function_exists('column_exists') && !column_exists($pdo, 'wa_outbound_queue', $col)) {
            try {
                $pdo->exec('ALTER TABLE wa_outbound_queue ADD COLUMN ' . $col . ' ' . $def);
            } catch (Throwable $e) {
                error_log('[wa_outbound_migrate] ' . $col . ': ' . $e->getMessage());
            }
        }
    }
    if (function_exists('column_exists') && column_exists($pdo, 'wa_outbound_queue', 'dispatch_id')) {
        try {
            $pdo->exec('CREATE UNIQUE INDEX uk_wa_outbound_dispatch_id ON wa_outbound_queue (dispatch_id)');
        } catch (Throwable $e) {
            /* index may exist */
        }
    }
    wa_outbound_migrate_wa_logs_columns($pdo);
    $migrated = true;
}

function wa_outbound_migrate_wa_logs_columns(PDO $pdo): void
{
    if (!function_exists('table_exists') || !table_exists($pdo, 'wa_logs')) {
        return;
    }
    $cols = [
        'dispatch_id' => 'VARCHAR(24) NULL',
        'dedup_key' => 'VARCHAR(191) NULL',
        'kind' => 'VARCHAR(40) NULL',
        'provider_message_id' => 'VARCHAR(128) NULL',
    ];
    foreach ($cols as $col => $def) {
        if (function_exists('column_exists') && !column_exists($pdo, 'wa_logs', $col)) {
            try {
                $pdo->exec('ALTER TABLE wa_logs ADD COLUMN ' . $col . ' ' . $def);
            } catch (Throwable $e) {
                if (!str_contains($e->getMessage(), 'Duplicate column')) {
                    error_log('[wa_outbound_migrate wa_logs] ' . $col . ': ' . $e->getMessage());
                }
            }
        }
    }
}

function wa_dispatch_id_allocate(PDO $pdo): string
{
    wa_outbound_migrate_schema_extras($pdo);
    $dateKey = date('Y-m-d');
    $label = 'WA-' . date('Ymd') . '-';
    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            $pdo->beginTransaction();
            $pdo->prepare('
                INSERT INTO wa_dispatch_id_seq (seq_date, last_num) VALUES (:d, 0)
                ON DUPLICATE KEY UPDATE seq_date = seq_date
            ')->execute(['d' => $dateKey]);
            $st = $pdo->prepare('SELECT last_num FROM wa_dispatch_id_seq WHERE seq_date = :d FOR UPDATE');
            $st->execute(['d' => $dateKey]);
            $num = (int) ($st->fetchColumn() ?: 0);
            $num++;
            $pdo->prepare('UPDATE wa_dispatch_id_seq SET last_num = :n WHERE seq_date = :d')
                ->execute(['n' => $num, 'd' => $dateKey]);
            $pdo->commit();

            return $label . str_pad((string) $num, 6, '0', STR_PAD_LEFT);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('[wa_dispatch_id_allocate] ' . $e->getMessage());
        }
    }

    return $label . str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);
}

/** @return array<string, mixed>|null */
function wa_outbound_get_by_dispatch_id(PDO $pdo, string $dispatchId): ?array
{
    wa_outbound_migrate_schema_extras($pdo);
    $dispatchId = trim($dispatchId);
    if ($dispatchId === '' || !function_exists('table_exists') || !table_exists($pdo, 'wa_outbound_queue')) {
        return null;
    }
    try {
        $st = $pdo->prepare('
            SELECT id, dispatch_id, dedup_key, target_phone, kind, status, attempt_count,
                   provider_message_id, created_at, sent_at, last_error, retry_reason
            FROM wa_outbound_queue WHERE dispatch_id = :d LIMIT 1
        ');
        $st->execute(['d' => $dispatchId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        error_log('[wa_outbound_get_by_dispatch_id] ' . $e->getMessage());

        return null;
    }
}
