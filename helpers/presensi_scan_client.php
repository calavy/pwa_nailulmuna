<?php

declare(strict_types=1);

/** Selisih jam HP vs server di atas batas ini → pakai jam server untuk jadwal (detik). */
function presensi_scan_client_clock_skew_max_sec(): int
{
    return 300;
}

/** Batas jam client di masa depan (detik) — ditolak meski sync offline. */
function presensi_scan_client_clock_future_max_sec(): int
{
    return 300;
}

/**
 * Sync antrian offline: percayai scan_client_at meski upload terlambat (lewati skew 5 menit).
 *
 * @param array<string, mixed> $post
 */
function presensi_scan_trust_delayed_client_clock(array $post): bool
{
    if (!function_exists('offline_sync_wants_json')) {
        require_once __DIR__ . '/offline_sync_http.php';
    }
    if (offline_sync_wants_json()) {
        return true;
    }
    if (!function_exists('offline_sync_client_uuid_from_post')) {
        require_once __DIR__ . '/offline_sync_dedup.php';
    }

    return offline_sync_client_uuid_from_post($post) !== '';
}

/**
 * Waktu scan dari perangkat (offline queue / sync) — dipakai saat upload, bukan jam server.
 *
 * @param array<string, mixed> $post
 * @param array{trust_delayed_client?:bool} $opts
 * @return array{tanggal:string,jam:string,from_client:bool,from_client_skew:bool,client_skew_sec:?int}
 */
function presensi_scan_resolve_clock(array $post, array $opts = []): array
{
    $trustDelayed = !empty($opts['trust_delayed_client'])
        || presensi_scan_trust_delayed_client_clock($post);
    $maxAgeSec = 86400 * 7;
    $skewMax = presensi_scan_client_clock_skew_max_sec();
    $futureMax = presensi_scan_client_clock_future_max_sec();

    $server = [
        'tanggal' => date('Y-m-d'),
        'jam' => date('H:i:s'),
        'from_client' => false,
        'from_client_skew' => false,
        'client_skew_sec' => null,
    ];

    $acceptClientTs = static function (int $ts) use ($server, $trustDelayed, $maxAgeSec, $skewMax, $futureMax): ?array {
        if ($ts > time() + $futureMax) {
            return null;
        }
        $age = abs(time() - $ts);
        if ($age > $maxAgeSec) {
            return null;
        }
        $skew = abs(time() - $ts);
        if (!$trustDelayed && $skew > $skewMax) {
            return array_merge($server, [
                'from_client_skew' => true,
                'client_skew_sec' => $skew,
            ]);
        }

        return [
            'tanggal' => date('Y-m-d', $ts),
            'jam' => date('H:i:s', $ts),
            'from_client' => true,
            'from_client_skew' => false,
            'client_skew_sec' => $skew,
        ];
    };

    $rawAt = trim((string) ($post['scan_client_at'] ?? ''));
    if ($rawAt !== '') {
        $ts = strtotime($rawAt);
        if ($ts !== false) {
            $resolved = $acceptClientTs($ts);
            if ($resolved !== null) {
                return $resolved;
            }
        }
    }

    $rawDate = trim((string) ($post['scan_client_date'] ?? ''));
    $rawJam = trim((string) ($post['scan_client_jam'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $rawJam)) {
        $jamNorm = strlen($rawJam) === 5 ? $rawJam . ':00' : $rawJam;
        $ts = strtotime($rawDate . ' ' . $jamNorm);
        if ($ts !== false) {
            $resolved = $acceptClientTs($ts);
            if ($resolved !== null) {
                if (!empty($resolved['from_client'])) {
                    return array_merge($resolved, [
                        'tanggal' => $rawDate,
                        'jam' => $jamNorm,
                    ]);
                }

                return $resolved;
            }
        }
    }

    return $server;
}

/**
 * @param array{tanggal:string,jam:string,from_client:bool,from_client_skew?:bool,client_skew_sec?:?int} $clock
 * @return array<string, mixed>
 */
function presensi_scan_clock_meta(array $clock): array
{
    return [
        'tanggal' => (string) ($clock['tanggal'] ?? ''),
        'jam' => (string) ($clock['jam'] ?? ''),
        'from_client' => !empty($clock['from_client']),
        'from_client_skew' => !empty($clock['from_client_skew']),
        'client_skew_sec' => isset($clock['client_skew_sec']) ? (int) $clock['client_skew_sec'] : null,
    ];
}

/** Hitung catatan keterlambatan dari jam mulai jadwal & jam scan. */
function presensi_scan_catatan_telat(?string $jamMulai, string $jamPresensi, int $lateThresholdMinutes): ?string
{
    if ($lateThresholdMinutes <= 0 || $jamMulai === null || trim($jamMulai) === '') {
        return null;
    }
    $mulai = DateTime::createFromFormat('H:i:s', strlen($jamMulai) === 5 ? $jamMulai . ':00' : $jamMulai);
    $scan = DateTime::createFromFormat('H:i:s', strlen($jamPresensi) === 5 ? $jamPresensi . ':00' : $jamPresensi);
    if (!$mulai || !$scan) {
        return null;
    }
    $threshold = (clone $mulai)->modify('+' . $lateThresholdMinutes . ' minutes');
    if ($scan <= $threshold) {
        return null;
    }
    $diff = $scan->getTimestamp() - $threshold->getTimestamp();

    return 'Terlambat ' . (int) ceil($diff / 60) . ' menit';
}
