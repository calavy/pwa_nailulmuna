<?php

declare(strict_types=1);

require_once __DIR__ . '/akademik.php';
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/pkpps.php';

/**
 * Konteks jadwal scan presensi untuk hari & jam tertentu (hitung mundur).
 *
 * @return array{
 *   state: string,
 *   nama_kegiatan: string,
 *   tingkatan: string,
 *   jam_mulai: string,
 *   jam_selesai: string,
 *   tempat: string,
 *   ends_at: string,
 *   starts_at: string,
 *   seconds_remaining: int,
 *   seconds_until_start: int,
 *   libur_nama: string,
 *   slots: list<array<string, mixed>>
 * }
 */
function presensi_scan_jadwal_context(PDO $pdo, ?string $tanggal = null, ?string $jam = null): array
{
    $empty = [
        'state' => 'none',
        'nama_kegiatan' => '',
        'tingkatan' => '',
        'jam_mulai' => '',
        'jam_selesai' => '',
        'tempat' => '',
        'ends_at' => '',
        'starts_at' => '',
        'seconds_remaining' => 0,
        'seconds_until_start' => 0,
        'libur_nama' => '',
        'slots' => [],
    ];

    if (!table_exists($pdo, 'kegiatan')) {
        return $empty;
    }
    $hasKajian = table_exists($pdo, 'jadwal_kegiatan');
    $hasPkpps = table_exists($pdo, 'pkpps_jadwal');
    if (!$hasKajian && !$hasPkpps) {
        return $empty;
    }

    ensure_jadwal_kegiatan_tempat($pdo);
    ensure_kegiatan_kategori_column($pdo);
    $tanggal = $tanggal ?? date('Y-m-d');
    $jam = $jam ?? date('H:i:s');
    $hariKe = (int) date('N', strtotime($tanggal));

    ensure_akademik_libur_table($pdo);
    $libur = akademik_libur_info($pdo, $tanggal, 'presensi');
    $modeLiburAktif = akademik_libur_presensi_mode_aktif_di_tanggal($pdo, $tanggal);
    if ($modeLiburAktif === 'ALL_BLOCKED') {
        return array_merge($empty, [
            'state' => 'libur',
            'libur_nama' => (string) ($libur['nama'] ?? 'Hari libur'),
        ]);
    }
    $kategoriFilterSql = $modeLiburAktif !== null
        ? akademik_libur_presensi_filter_sql_by_mode($modeLiburAktif, 'COALESCE(k.kategori_kegiatan, "TAALIM")')
        : '';

    $st = $pdo->prepare('
        SELECT k.id AS kegiatan_id, k.nama_kegiatan, COALESCE(k.kategori_kegiatan, "TAALIM") AS kategori_kegiatan, j.tingkatan, j.jam_mulai, j.jam_selesai, j.tempat
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        WHERE (j.hari_ke = 0 OR j.hari_ke = :hari_ke)
          AND k.is_active = 1
          ' . $kategoriFilterSql . '
        ORDER BY j.jam_mulai ASC, k.nama_kegiatan ASC
    ');
    $rows = [];
    if ($hasKajian) {
        $st->execute(['hari_ke' => $hariKe]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $pkppsSlotsRaw = pkpps_jadwal_slots_for_presensi_scan($pdo, $tanggal, $hariKe, $modeLiburAktif);
    foreach ($pkppsSlotsRaw as $ps) {
        $rows[] = [
            'kegiatan_id' => (int) ($ps['kegiatan_id'] ?? 0),
            'nama_kegiatan' => (string) ($ps['nama_kegiatan'] ?? ''),
            'kategori_kegiatan' => 'TAALIM',
            'tingkatan' => (string) ($ps['tingkatan'] ?? ''),
            'jam_mulai' => (string) ($ps['jam_mulai'] ?? ''),
            'jam_selesai' => (string) ($ps['jam_selesai'] ?? ''),
            'tempat' => (string) ($ps['tempat'] ?? ''),
        ];
    }

    if ($rows === []) {
        return $empty;
    }

    $nowTs = strtotime($tanggal . ' ' . $jam);
    if ($nowTs === false) {
        return $empty;
    }

    $active = [];
    $upcoming = [];

    foreach ($rows as $row) {
        $mulai = trim((string) ($row['jam_mulai'] ?? ''));
        $selesai = trim((string) ($row['jam_selesai'] ?? ''));
        if ($mulai === '' || $selesai === '') {
            continue;
        }
        $startTs = strtotime($tanggal . ' ' . $mulai);
        $endTs = strtotime($tanggal . ' ' . $selesai);
        if ($startTs === false || $endTs === false) {
            continue;
        }
        if ($endTs < $startTs) {
            $endTs += 86400;
        }

        $slot = [
            'kegiatan_id' => (int) ($row['kegiatan_id'] ?? 0),
            'nama_kegiatan' => (string) ($row['nama_kegiatan'] ?? ''),
            'tingkatan' => (string) ($row['tingkatan'] ?? ''),
            'jam_mulai' => substr($mulai, 0, 5),
            'jam_selesai' => substr($selesai, 0, 5),
            'tempat' => trim((string) ($row['tempat'] ?? '')),
            'starts_at' => date('c', $startTs),
            'ends_at' => date('c', $endTs),
            'seconds_remaining' => max(0, $endTs - $nowTs),
            'seconds_until_start' => max(0, $startTs - $nowTs),
        ];

        if ($nowTs >= $startTs && $nowTs <= $endTs) {
            $active[] = $slot;
        } elseif ($nowTs < $startTs) {
            $upcoming[] = $slot;
        }
    }

    if ($active !== []) {
        usort($active, static fn(array $a, array $b): int => ($a['seconds_remaining'] ?? 0) <=> ($b['seconds_remaining'] ?? 0));
        $primary = $active[0];

        return [
            'state' => 'active',
            'nama_kegiatan' => $primary['nama_kegiatan'],
            'tingkatan' => $primary['tingkatan'],
            'jam_mulai' => $primary['jam_mulai'],
            'jam_selesai' => $primary['jam_selesai'],
            'tempat' => $primary['tempat'],
            'ends_at' => $primary['ends_at'],
            'starts_at' => $primary['starts_at'],
            'seconds_remaining' => (int) $primary['seconds_remaining'],
            'seconds_until_start' => 0,
            'libur_nama' => '',
            'slots' => $active,
        ];
    }

    if ($upcoming !== []) {
        usort($upcoming, static fn(array $a, array $b): int => ($a['seconds_until_start'] ?? 0) <=> ($b['seconds_until_start'] ?? 0));
        $next = $upcoming[0];

        return [
            'state' => 'upcoming',
            'nama_kegiatan' => $next['nama_kegiatan'],
            'tingkatan' => $next['tingkatan'],
            'jam_mulai' => $next['jam_mulai'],
            'jam_selesai' => $next['jam_selesai'],
            'tempat' => $next['tempat'],
            'ends_at' => $next['ends_at'],
            'starts_at' => $next['starts_at'],
            'seconds_remaining' => 0,
            'seconds_until_start' => (int) $next['seconds_until_start'],
            'libur_nama' => '',
            'slots' => [],
        ];
    }

    return array_merge($empty, ['state' => 'ended']);
}
