<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/pkpps.php';
require_once __DIR__ . '/santri_operasional.php';
require_once __DIR__ . '/presensi_admin.php';
require_once __DIR__ . '/presensi_jadwal.php';
require_once __DIR__ . '/rekap_keaktifan_hari.php';

/**
 * Baris presensi santri PKPPS per jadwal pada tanggal.
 *
 * @return list<array<string, mixed>>
 */
function rekap_pkpps_keaktifan_hari_santri_rows(PDO $pdo, string $tanggal, ?int $tingkatanFilter = null): array
{
    if (
        !table_exists($pdo, 'pkpps_jadwal')
        || !table_exists($pdo, 'pkpps_santri')
        || !table_exists($pdo, 'santri')
        || !table_exists($pdo, 'kegiatan')
    ) {
        return [];
    }

    pkpps_ensure_schema($pdo);
    ensure_presensi_pkpps_column($pdo);

    $hariKe = (int) date('N', strtotime($tanggal) ?: time());
    $aktifSql = santri_sql_aktif_only('s');
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';

    $auditUserId = (int) ($_SESSION['user']['id'] ?? 1);
    presensi_finalize_date_range($pdo, $tanggal, $tanggal, $auditUserId > 0 ? $auditUserId : 1);

    $params = ['tgl' => $tanggal, 'hari' => $hariKe];
    $tkWhere = '';
    if ($tingkatanFilter !== null && $tingkatanFilter > 0) {
        $tkWhere = ' AND j.pkpps_tingkatan_id = :tid';
        $params['tid'] = $tingkatanFilter;
    }

    $sql = "
        SELECT
            j.id AS pkpps_jadwal_id,
            j.kegiatan_id,
            k.nama_kegiatan,
            t.id AS pkpps_tingkatan_id,
            t.nama_tingkatan AS pkpps_tingkatan,
            j.jam_mulai,
            j.jam_selesai,
            COALESCE(j.tempat, '') AS tempat,
            COALESCE(b.nama_pembimbing, '') AS nama_pembimbing,
            s.id AS santri_id,
            s.nis,
            s.{$nameCol} AS nama_santri,
            t.nama_tingkatan AS tingkatan,
            COALESCE(NULLIF(TRIM(p.status_presensi), ''), '') AS status_hari_ini,
            p.jam_presensi,
            p.catatan
        FROM pkpps_jadwal j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id AND k.is_active = 1
        INNER JOIN pkpps_tingkatan t ON t.id = j.pkpps_tingkatan_id AND t.is_aktif = 1
        INNER JOIN pkpps_santri ps ON ps.pkpps_tingkatan_id = t.id AND ps.is_aktif = 1
        INNER JOIN santri s ON s.id = ps.santri_id AND {$aktifSql}
        LEFT JOIN pembimbing b ON b.id = j.pembimbing_id
        LEFT JOIN presensi p ON p.santri_id = s.id
            AND p.kegiatan_id = j.kegiatan_id
            AND p.tanggal_presensi = :tgl
        WHERE j.is_aktif = 1
          AND (j.hari_ke = 0 OR j.hari_ke = :hari)
          {$tkWhere}
        ORDER BY t.urutan ASC, j.jam_mulai ASC, k.nama_kegiatan ASC, s.{$nameCol} ASC
    ";

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return presensi_apply_status_efektif_rows($pdo, $rows, $tanggal);
}

/**
 * Kartu kegiatan santri PKPPS (format selaras rekap keaktifan hari).
 *
 * @return list<array<string, mixed>>
 */
function rekap_pkpps_keaktifan_hari_santri_cards(PDO $pdo, string $tanggal, ?int $tingkatanFilter = null): array
{
    $rows = rekap_pkpps_keaktifan_hari_santri_rows($pdo, $tanggal, $tingkatanFilter);
    $byJadwal = [];

    foreach ($rows as $r) {
        $jid = (int) ($r['pkpps_jadwal_id'] ?? 0);
        if ($jid <= 0) {
            continue;
        }
        if (!isset($byJadwal[$jid])) {
            $namaKeg = trim((string) ($r['nama_kegiatan'] ?? ''));
            $tingkat = trim((string) ($r['pkpps_tingkatan'] ?? ''));
            $jamMulai = substr((string) ($r['jam_mulai'] ?? ''), 0, 5);
            $jamSelesai = substr((string) ($r['jam_selesai'] ?? ''), 0, 5);
            $pb = trim((string) ($r['nama_pembimbing'] ?? ''));
            $metaJam = $jamMulai !== '' ? $jamMulai . ($jamSelesai !== '' ? '–' . $jamSelesai : '') : '';

            $byJadwal[$jid] = [
                'kegiatan_id' => $jid,
                'pkpps_jadwal_id' => $jid,
                'nama_kegiatan' => $namaKeg . ($tingkat !== '' ? ' · ' . $tingkat : ''),
                'pkpps_tingkatan' => $tingkat,
                'jam_label' => $metaJam,
                'pembimbing' => $pb,
                'tempat' => trim((string) ($r['tempat'] ?? '')),
                'hadir' => 0,
                'izin' => 0,
                'sakit' => 0,
                'alpa' => 0,
                'total' => 0,
                'santri' => [
                    'HADIR' => [],
                    'IZIN' => [],
                    'SAKIT' => [],
                    'ALPA' => [],
                ],
            ];
        }

        $st = strtoupper((string) ($r['status_hari_ini'] ?? ''));
        if ($st === 'ISTIRAHAT') {
            $st = 'ALPA';
        }
        if (!in_array($st, ['HADIR', 'IZIN', 'SAKIT', 'ALPA'], true)) {
            $st = 'ALPA';
        }

        $byJadwal[$jid]['total']++;
        match ($st) {
            'HADIR' => $byJadwal[$jid]['hadir']++,
            'IZIN' => $byJadwal[$jid]['izin']++,
            'SAKIT' => $byJadwal[$jid]['sakit']++,
            'ALPA' => $byJadwal[$jid]['alpa']++,
            default => null,
        };

        $jam = $r['jam_presensi'] ?? null;
        $byJadwal[$jid]['santri'][$st][] = [
            'nama_santri' => (string) ($r['nama_santri'] ?? ''),
            'nis' => (string) ($r['nis'] ?? ''),
            'tingkatan' => (string) ($r['pkpps_tingkatan'] ?? ''),
            'jam_presensi' => $jam !== null && $jam !== '' ? substr((string) $jam, 0, 5) : null,
            'catatan' => trim((string) ($r['catatan'] ?? '')),
        ];
    }

    return array_values($byJadwal);
}

/**
 * Kartu keaktifan pembimbing PKPPS per jadwal hari ini.
 *
 * @return list<array<string, mixed>>
 */
function rekap_pkpps_keaktifan_hari_pembimbing_cards(PDO $pdo, string $tanggal): array
{
    if (!table_exists($pdo, 'pkpps_jadwal') || !table_exists($pdo, 'pembimbing')) {
        return [];
    }

    pkpps_ensure_schema($pdo);
    $hariKe = (int) date('N', strtotime($tanggal) ?: time());

    $st = $pdo->prepare('
        SELECT
            j.id AS pkpps_jadwal_id,
            j.kegiatan_id,
            j.pembimbing_id,
            j.jam_mulai,
            j.jam_selesai,
            COALESCE(j.tempat, "") AS tempat,
            k.nama_kegiatan,
            t.nama_tingkatan AS pkpps_tingkatan,
            b.nama_pembimbing,
            b.nip
        FROM pkpps_jadwal j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id AND k.is_active = 1
        INNER JOIN pkpps_tingkatan t ON t.id = j.pkpps_tingkatan_id AND t.is_aktif = 1
        INNER JOIN pembimbing b ON b.id = j.pembimbing_id
        WHERE j.is_aktif = 1
          AND j.pembimbing_id IS NOT NULL
          AND j.pembimbing_id > 0
          AND (j.hari_ke = 0 OR j.hari_ke = :hari)
        ORDER BY b.nama_pembimbing ASC, j.jam_mulai ASC
    ');
    $st->execute(['hari' => $hariKe]);
    $jadwalRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($jadwalRows === []) {
        return [];
    }

    $hadirMap = [];
    if (table_exists($pdo, 'presensi_pembimbing')) {
        $hasKg = column_exists($pdo, 'presensi_pembimbing', 'kegiatan_id');
        $pp = $pdo->prepare('
            SELECT pembimbing_id, kegiatan_id, DATE_FORMAT(jam, "%H:%i") AS jam
            FROM presensi_pembimbing
            WHERE tanggal = :t
        ');
        $pp->execute(['t' => $tanggal]);
        foreach ($pp->fetchAll(PDO::FETCH_ASSOC) ?: [] as $hr) {
            $pid = (int) ($hr['pembimbing_id'] ?? 0);
            $kg = $hasKg ? (int) ($hr['kegiatan_id'] ?? 0) : 0;
            $key = $pid . ':' . $kg;
            $hadirMap[$key] = (string) ($hr['jam'] ?? '');
        }
    }

    $byPb = [];
    foreach ($jadwalRows as $jr) {
        $pid = (int) ($jr['pembimbing_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        if (!isset($byPb[$pid])) {
            $byPb[$pid] = [
                'pembimbing_id' => $pid,
                'nama_pembimbing' => (string) ($jr['nama_pembimbing'] ?? ''),
                'nip' => (string) ($jr['nip'] ?? ''),
                'hadir' => 0,
                'belum' => 0,
                'total' => 0,
                'jadwal' => [],
            ];
        }

        $kgId = (int) ($jr['kegiatan_id'] ?? 0);
        $key = $pid . ':' . $kgId;
        $jamScan = $hadirMap[$key] ?? '';
        $sudah = $jamScan !== '';
        $byPb[$pid]['total']++;
        if ($sudah) {
            $byPb[$pid]['hadir']++;
        } else {
            $byPb[$pid]['belum']++;
        }

        $jamMulai = substr((string) ($jr['jam_mulai'] ?? ''), 0, 5);
        $jamSelesai = substr((string) ($jr['jam_selesai'] ?? ''), 0, 5);
        $byPb[$pid]['jadwal'][] = [
            'kegiatan' => (string) ($jr['nama_kegiatan'] ?? ''),
            'tingkatan' => (string) ($jr['pkpps_tingkatan'] ?? ''),
            'jam' => $jamMulai . ($jamSelesai !== '' ? '–' . $jamSelesai : ''),
            'tempat' => trim((string) ($jr['tempat'] ?? '')),
            'status' => $sudah ? 'HADIR' : 'BELUM',
            'jam_scan' => $jamScan,
        ];
    }

    return array_values($byPb);
}

function ensure_presensi_pkpps_column(PDO $pdo): void
{
    if (function_exists('ensure_presensi_jadwal_column')) {
        ensure_presensi_jadwal_column($pdo);
    }
}
