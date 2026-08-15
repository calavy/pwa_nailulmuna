<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/wa_otomatis.php';

/** ID grup WA Fonte untuk notifikasi presensi (bisa beberapa, pisah koma). */
function wa_presensi_grup_targets(PDO $pdo): string
{
    return trim((string) app_setting($pdo, 'wa_presensi_grup_fonte', ''));
}

/** Apakah kirim otomatis ke grup presensi (munawib, kelas kosong, dll.). */
function wa_presensi_grup_enabled(PDO $pdo): bool
{
    $targets = wa_presensi_grup_targets($pdo);
    if ($targets === '') {
        return false;
    }

    return trim((string) app_setting($pdo, 'wa_presensi_grup_fonte_enabled', '1')) === '1';
}

/** Apakah kirim WA individual ke pembimbing terkait (selain petugas pendidikan & grup). */
function presensi_wa_kirim_pembimbing_enabled(PDO $pdo): bool
{
    return trim((string) app_setting($pdo, 'wa_presensi_kirim_pembimbing_enabled', '1')) === '1';
}

/**
 * Kirim ke petugas pendidikan / override + opsional grup presensi.
 *
 * @param array<string, mixed> $opts
 * @return array{sent:int,skipped:int}
 */
function presensi_wa_kirim(PDO $pdo, string $personalTargets, string $message, array $opts = []): array
{
    $sent = 0;
    $skipped = 0;
    $personalTargets = trim($personalTargets);
    if ($personalTargets !== '') {
        $bulk = send_wa_bulk_with_result($pdo, $personalTargets, $message, $opts);
        $sent += (int) ($bulk['sent'] ?? 0);
        $skipped += (int) ($bulk['skipped'] ?? 0);
    }

    if (!wa_presensi_grup_enabled($pdo)) {
        return ['sent' => $sent, 'skipped' => $skipped];
    }

    $grupTargets = wa_presensi_grup_targets($pdo);
    if ($grupTargets === '') {
        return ['sent' => $sent, 'skipped' => $skipped];
    }

    $grupOpts = $opts;
    $dedup = trim((string) ($opts['dedup_key'] ?? ''));
    if ($dedup !== '') {
        $grupOpts['dedup_key'] = $dedup . ':grup';
    }
    if (!array_key_exists('dedup_key_once', $grupOpts)) {
        $grupOpts['dedup_key_once'] = true;
    }

    $grupBulk = send_wa_bulk_with_result($pdo, $grupTargets, $message, $grupOpts);
    $sent += (int) ($grupBulk['sent'] ?? 0);
    $skipped += (int) ($grupBulk['skipped'] ?? 0);

    return ['sent' => $sent, 'skipped' => $skipped];
}

/**
 * Catat ke wa_logs jika pembimbing tidak punya nomor WA (untuk audit admin).
 */
function presensi_wa_log_pembimbing_no_wa(PDO $pdo, int $pembimbingId, string $context, string $namaPembimbing = ''): void
{
    if ($pembimbingId <= 0 || !table_exists($pdo, 'wa_logs')) {
        return;
    }

    $label = trim($namaPembimbing) !== '' ? $namaPembimbing : ('pembimbing #' . $pembimbingId);
    $note = 'SKIP no_wa kosong — ' . $label . ' (' . $context . ')';

    try {
        $log = $pdo->prepare('
            INSERT INTO wa_logs (target_phone, message, response_text, is_success)
            VALUES (:target_phone, :message, :response_text, 0)
        ');
        $log->execute([
            'target_phone' => 'pb:' . $pembimbingId,
            'message' => substr($note, 0, 500),
            'response_text' => $note,
        ]);
    } catch (Throwable $e) {
        error_log('[presensi_wa_log_pembimbing_no_wa] ' . $e->getMessage());
    }
}

/**
 * Kirim WA ke pembimbing terkait (no_wa di data pembimbing).
 *
 * @param array<int, string> $pembimbingMessages pembimbing_id => pesan
 * @param array<string, mixed> $opts
 */
function presensi_wa_kirim_ke_pembimbing(PDO $pdo, array $pembimbingMessages, array $opts = []): int
{
    if (!presensi_wa_kirim_pembimbing_enabled($pdo) || $pembimbingMessages === []) {
        return 0;
    }
    if (!table_exists($pdo, 'pembimbing')) {
        return 0;
    }

    $sent = 0;
    $baseDedup = trim((string) ($opts['dedup_key'] ?? ''));
    unset($opts['dedup_key']);

    foreach ($pembimbingMessages as $pembimbingId => $message) {
        $pembimbingId = (int) $pembimbingId;
        $message = trim((string) $message);
        if ($pembimbingId <= 0 || $message === '') {
            continue;
        }

        $st = $pdo->prepare('
            SELECT nama_pembimbing, no_wa, COALESCE(is_aktif, 1) AS is_aktif
            FROM pembimbing
            WHERE id = :id
            LIMIT 1
        ');
        $st->execute(['id' => $pembimbingId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || (int) ($row['is_aktif'] ?? 1) !== 1) {
            continue;
        }

        $phone = normalize_wa_phone(trim((string) ($row['no_wa'] ?? '')));
        if ($phone === '') {
            presensi_wa_log_pembimbing_no_wa(
                $pdo,
                $pembimbingId,
                (string) ($opts['context'] ?? 'presensi'),
                (string) ($row['nama_pembimbing'] ?? '')
            );
            continue;
        }

        $msgOpts = $opts;
        $msgOpts['kind'] = (string) ($opts['kind'] ?? 'presensi');
        if ($baseDedup !== '') {
            $msgOpts['dedup_key'] = $baseDedup . ':pb:' . $pembimbingId;
        }

        if (send_wa_message($pdo, $phone, $message, $msgOpts)) {
            $sent++;
        }
    }

    return $sent;
}
