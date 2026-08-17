<?php

declare(strict_types=1);

/**
 * Waktu scan dari perangkat (offline queue / sync) — dipakai saat upload, bukan jam server.
 *
 * @param array<string, mixed> $post
 * @return array{tanggal:string,jam:string,from_client:bool}
 */
function presensi_scan_resolve_clock(array $post): array
{
    $server = [
        'tanggal' => date('Y-m-d'),
        'jam' => date('H:i:s'),
        'from_client' => false,
    ];

    $rawAt = trim((string) ($post['scan_client_at'] ?? ''));
    if ($rawAt !== '') {
        $ts = strtotime($rawAt);
        if ($ts !== false) {
            $age = abs(time() - $ts);
            if ($age <= 86400 * 7) {
                return [
                    'tanggal' => date('Y-m-d', $ts),
                    'jam' => date('H:i:s', $ts),
                    'from_client' => true,
                ];
            }
        }
    }

    $rawDate = trim((string) ($post['scan_client_date'] ?? ''));
    $rawJam = trim((string) ($post['scan_client_jam'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $rawJam)) {
        $jamNorm = strlen($rawJam) === 5 ? $rawJam . ':00' : $rawJam;
        $ts = strtotime($rawDate . ' ' . $jamNorm);
        if ($ts !== false && abs(time() - $ts) <= 86400 * 7) {
            return [
                'tanggal' => $rawDate,
                'jam' => $jamNorm,
                'from_client' => true,
            ];
        }
    }

    return $server;
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
