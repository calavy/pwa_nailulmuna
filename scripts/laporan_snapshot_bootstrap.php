<?php

declare(strict_types=1);

/**
 * CLI: siapkan setting snapshot + opsional kirim tes (hosting/lokal).
 *
 *   php scripts/laporan_snapshot_bootstrap.php apply
 *   php scripts/laporan_snapshot_bootstrap.php push [--as-of=YYYY-MM-DD]
 *   php scripts/laporan_snapshot_bootstrap.php tick
 *
 * Env opsional: PONDOK_DB_PROFILE=hosting, LAPORAN_SNAPSHOT_SHARE_EMAILS=...
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/laporan_snapshot.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

ensure_pondok_settings_defaults($pdo);
$defaults = pondok_settings_defaults();

$action = $argv[1] ?? 'apply';

if ($action === 'apply') {
    save_setting($pdo, 'laporan_snapshot_sa_json_path', (string) ($defaults['laporan_snapshot_sa_json_path'] ?? 'config/google_service_account.json'));

    $cronKey = trim((string) app_setting($pdo, 'laporan_snapshot_cron_key', ''));
    if ($cronKey === '') {
        $cronKey = bin2hex(random_bytes(16));
        save_setting($pdo, 'laporan_snapshot_cron_key', $cronKey);
        echo "cron_key_generated=1\n";
    } else {
        echo "cron_key_generated=0\n";
    }

    $share = trim((string) (getenv('LAPORAN_SNAPSHOT_SHARE_EMAILS') ?: ''));
    if ($share !== '') {
        save_setting($pdo, 'laporan_snapshot_share_emails', $share);
        echo "share_emails_updated=1\n";
    }

    $sa = laporan_snapshot_sa_status($pdo);
    echo ($sa['valid_json'] ?? false) ? "sa_status=ready\n" : ('sa_status=fail ' . ($sa['error'] ?? '') . "\n");

    exit(($sa['valid_json'] ?? false) ? 0 : 1);
}

if ($action === 'push') {
    $asOf = null;
    foreach (array_slice($argv, 2) as $arg) {
        if (str_starts_with($arg, '--as-of=')) {
            $asOf = substr($arg, 8);
        }
    }
    $sa = laporan_snapshot_sa_status($pdo);
    if (!($sa['valid_json'] ?? false)) {
        fwrite(STDERR, 'SA not ready: ' . ($sa['error'] ?? '') . "\n");
        exit(1);
    }
    try {
        $asOf = laporan_snapshot_normalize_as_of($asOf);
        $collected = laporan_snapshot_collect_all($pdo, $asOf);
        $push = laporan_snapshot_push_to_google($pdo, $collected, true);
        save_setting($pdo, 'laporan_snapshot_last_run_at', date('Y-m-d H:i:s'));
        if ($push['ok'] ?? false) {
            save_setting($pdo, 'laporan_snapshot_last_error', '');
            if (($push['spreadsheet_id'] ?? '') !== '') {
                save_setting($pdo, 'laporan_snapshot_spreadsheet_id', (string) $push['spreadsheet_id']);
            }
            echo "push_ok=1 spreadsheet_id=" . (string) ($push['spreadsheet_id'] ?? '') . "\n";
            exit(0);
        }
        save_setting($pdo, 'laporan_snapshot_last_error', (string) ($push['error'] ?? 'Gagal'));
        fwrite(STDERR, 'push_fail: ' . (string) ($push['error'] ?? '') . "\n");
        exit(1);
    } catch (Throwable $e) {
        save_setting($pdo, 'laporan_snapshot_last_error', $e->getMessage());
        fwrite(STDERR, $e->getMessage() . "\n");
        exit(1);
    }
}

if ($action === 'tick') {
    $tick = laporan_snapshot_run_tick($pdo);
    echo 'tick_ran=' . (($tick['ran'] ?? false) ? '1' : '0') . "\n";
    echo 'tick_mode=' . (string) ($tick['mode'] ?? '') . "\n";
    echo 'tick_note=' . (string) ($tick['note'] ?? '') . "\n";
    exit(0);
}

fwrite(STDERR, "Usage: apply | push [--as-of=YYYY-MM-DD] | tick\n");
exit(1);
