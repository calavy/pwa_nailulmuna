<?php

declare(strict_types=1);

require_once __DIR__ . '/push_events.php';
require_once __DIR__ . '/app.php';

/**
 * User ID (tabel users) dari pembimbing_id via NIP = username.
 */
function presensi_notif_user_id_pembimbing(PDO $pdo, int $pembimbingId): int
{
    if ($pembimbingId <= 0 || !table_exists($pdo, 'pembimbing') || !table_exists($pdo, 'users')) {
        return 0;
    }
    $st = $pdo->prepare('
        SELECT u.id
        FROM pembimbing b
        INNER JOIN users u ON TRIM(u.username) = TRIM(b.nip)
        WHERE b.id = :id
        LIMIT 1
    ');
    $st->execute(['id' => $pembimbingId]);

    return (int) ($st->fetchColumn() ?: 0);
}

/**
 * Daftar pembimbing_id yang mengampu tingkatan pada kegiatan aktif hari ini.
 *
 * @return list<int>
 */
function presensi_notif_pembimbing_ids_untuk_santri(PDO $pdo, string $tingkatan, int $kegiatanId, string $tanggal, string $jam): array
{
    if (!table_exists($pdo, 'jadwal_kegiatan') || $kegiatanId <= 0) {
        return [];
    }
    $hariKe = (int) date('N', strtotime($tanggal));
    $st = $pdo->prepare('
        SELECT DISTINCT j.pembimbing_id
        FROM jadwal_kegiatan j
        WHERE j.kegiatan_id = :kid
          AND j.pembimbing_id IS NOT NULL
          AND j.pembimbing_id > 0
          AND (j.tingkatan = :tk OR j.tingkatan = "Semua Tingkatan" OR :tk = "")
          AND (j.hari_ke = 0 OR j.hari_ke = :hk)
          AND :jam BETWEEN j.jam_mulai AND j.jam_selesai
    ');
    $st->execute([
        'kid' => $kegiatanId,
        'tk' => $tingkatan,
        'hk' => $hariKe,
        'jam' => $jam,
    ]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $pid) {
        $id = (int) $pid;
        if ($id > 0) {
            $out[] = $id;
        }
    }

    return array_values(array_unique($out));
}

/**
 * Notifikasi ke pembimbing saat santri scan hadir (FCM + opsional WA).
 */
function presensi_notif_santri_hadir(
    PDO $pdo,
    array $santri,
    array $kegiatan,
    string $tanggal,
    string $jam,
    ?string $catatanTelat = null
): void {
    $nama = trim((string) ($santri['nama_santri'] ?? ''));
    $nis = trim((string) ($santri['nis'] ?? ''));
    $tingkatan = trim((string) ($santri['tingkatan'] ?? ''));
    $namaKeg = trim((string) ($kegiatan['nama_kegiatan'] ?? ''));
    $kegiatanId = (int) ($kegiatan['id'] ?? $kegiatan['kegiatan_id'] ?? 0);

    $body = $nama . ' (' . $nis . ') — ' . $tingkatan;
    if ($namaKeg !== '') {
        $body .= ' · ' . $namaKeg;
    }
    if ($catatanTelat !== null && $catatanTelat !== '') {
        $body .= ' · ' . $catatanTelat;
    }

    push_event_presensi_santri_scan($pdo, $nama, $body, $nis);

    $pembimbingIds = presensi_notif_pembimbing_ids_untuk_santri($pdo, $tingkatan, $kegiatanId, $tanggal, $jam);
    foreach ($pembimbingIds as $pid) {
        $uid = presensi_notif_user_id_pembimbing($pdo, $pid);
        if ($uid > 0) {
            push_event_presensi_santri_scan($pdo, $nama, $body, $nis, $uid);
        }
    }
}

/**
 * Notifikasi staff saat pembimbing scan hadir.
 */
function presensi_notif_pembimbing_hadir(PDO $pdo, array $pembimbing, string $namaKegiatan, string $tanggal, string $jam): void
{
    $nama = trim((string) ($pembimbing['nama_pembimbing'] ?? ''));
    $body = $nama . ' hadir — ' . $namaKegiatan . ' · ' . date('H:i', strtotime($jam));
    push_event_presensi_pembimbing_scan($pdo, $nama, $body);
}
