<?php

declare(strict_types=1);

/**
 * Pause / resume global outbound (backend CLI).
 *
 * Usage:
 *   php scripts/_wa_outbound_pause.php pause "alasan opsional"
 *   php scripts/_wa_outbound_pause.php resume
 *   php scripts/_wa_outbound_pause.php status
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/wa_outbound_dispatch.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Database tidak tersedia.\n");
    exit(1);
}

$cmd = strtolower(trim((string) ($argv[1] ?? 'status')));
$reason = trim((string) ($argv[2] ?? ''));

if ($cmd === 'pause') {
    wa_outbound_pause($pdo, $reason !== '' ? $reason : 'manual_cli');
    echo "Outbound PAUSED\n";
    exit(0);
}

if ($cmd === 'resume') {
    wa_outbound_resume($pdo);
    wa_outbound_circuit_close($pdo);
    echo "Outbound RESUMED (circuit closed)\n";
    exit(0);
}

echo json_encode([
    'pause' => wa_outbound_pause_status($pdo),
    'circuit' => wa_outbound_circuit_status($pdo),
    'daily' => [
        'budget' => wa_outbound_daily_budget($pdo),
        'sent' => wa_outbound_daily_sent_count($pdo),
        'remaining' => wa_outbound_daily_remaining($pdo),
    ],
    'snapshot' => wa_outbound_queue_snapshot($pdo),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

exit(0);
