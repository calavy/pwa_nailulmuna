<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/**
 * Apakah tingkatan santri termasuk slot jadwal (termasuk "Semua Tingkatan").
 */
function presensi_jadwal_tingkatan_cocok(string $jadwalTingkatan, string $santriTingkatan): bool
{
    $jadwalTingkatan = trim($jadwalTingkatan);
    $santriTingkatan = trim($santriTingkatan);
    if ($jadwalTingkatan === '') {
        return false;
    }
    if (strcasecmp($jadwalTingkatan, 'Semua Tingkatan') === 0) {
        return $santriTingkatan !== '';
    }

    return strcasecmp($jadwalTingkatan, $santriTingkatan) === 0;
}

/**
 * Tingkatan wajib presensi pada tanggal + kegiatan (ada di jadwal).
 */
function presensi_tingkatan_terjadwal(PDO $pdo, string $tingkatan, int $kegiatanId, string $tanggal): bool
{
    $tingkatan = trim($tingkatan);
    if ($tingkatan === '' || $kegiatanId <= 0 || !table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan')) {
        return false;
    }

    $hariKe = (int) date('N', strtotime($tanggal) ?: time);
    $stmt = $pdo->prepare('
        SELECT j.tingkatan
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id AND k.is_active = 1
        WHERE j.kegiatan_id = :kid
          AND (j.hari_ke = 0 OR j.hari_ke = :hari)
    ');
    $stmt->execute(['kid' => $kegiatanId, 'hari' => $hariKe]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $jadwalTg) {
        if (presensi_jadwal_tingkatan_cocok((string) $jadwalTg, $tingkatan)) {
            return true;
        }
    }

    return false;
}

/**
 * Santri wajib dicatat presensinya (tingkatannya masuk jadwal kegiatan pada tanggal itu).
 */
function presensi_santri_wajib_hadir(PDO $pdo, int $santriId, ?int $kegiatanId, string $tanggal, ?string $tingkatan = null): bool
{
    if ($kegiatanId === null || $kegiatanId <= 0 || $santriId <= 0) {
        return false;
    }
    if ($tingkatan === null && table_exists($pdo, 'santri')) {
        $st = $pdo->prepare('SELECT tingkatan FROM santri WHERE id = :id LIMIT 1');
        $st->execute(['id' => $santriId]);
        $tingkatan = (string) ($st->fetchColumn() ?: '');
    }

    return presensi_tingkatan_terjadwal($pdo, trim((string) $tingkatan), $kegiatanId, $tanggal);
}

/**
 * Kunci lookup: kegiatan|tanggal|tingkatan — untuk filter rekap massal.
 *
 * @return array<string, true>
 */
function presensi_jadwal_eligibility_set(PDO $pdo, string $startDate, string $endDate): array
{
    $set = [];
    if (!table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan')) {
        return $set;
    }

    $stmt = $pdo->query('
        SELECT j.kegiatan_id, j.tingkatan, j.hari_ke
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id AND k.is_active = 1
    ');
    $jadwalRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($jadwalRows === []) {
        return $set;
    }

    $startTs = strtotime($startDate) ?: time();
    $endTs = strtotime($endDate) ?: $startTs;
    for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
        $tanggal = date('Y-m-d', $ts);
        $hariKe = (int) date('N', $ts);
        foreach ($jadwalRows as $jr) {
            $hariJadwal = (int) ($jr['hari_ke'] ?? 0);
            if ($hariJadwal !== 0 && $hariJadwal !== $hariKe) {
                continue;
            }
            $kid = (int) ($jr['kegiatan_id'] ?? 0);
            if ($kid <= 0) {
                continue;
            }
            $jadwalTg = trim((string) ($jr['tingkatan'] ?? ''));
            if (strcasecmp($jadwalTg, 'Semua Tingkatan') === 0) {
                $set[$kid . '|' . $tanggal . '|*'] = true;
            } elseif ($jadwalTg !== '') {
                $set[$kid . '|' . $tanggal . '|' . strtolower($jadwalTg)] = true;
            }
        }
    }

    return $set;
}

/**
 * @param array<string, true> $eligibilitySet dari presensi_jadwal_eligibility_set()
 */
function presensi_row_eligible_for_hitung(PDO $pdo, array $row, array $eligibilitySet = []): bool
{
    $kegiatanId = isset($row['kegiatan_id']) ? (int) $row['kegiatan_id'] : 0;
    if ($kegiatanId <= 0) {
        return false;
    }

    $tanggal = (string) ($row['tanggal_presensi'] ?? '');
    $tingkatan = trim((string) ($row['tingkatan'] ?? ''));
    if ($tanggal === '' || $tingkatan === '') {
        return false;
    }

    if ($eligibilitySet !== []) {
        $key = $kegiatanId . '|' . $tanggal . '|' . strtolower($tingkatan);
        $wildcard = $kegiatanId . '|' . $tanggal . '|*';

        return isset($eligibilitySet[$key]) || isset($eligibilitySet[$wildcard]);
    }

    return presensi_tingkatan_terjadwal($pdo, $tingkatan, $kegiatanId, $tanggal);
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function presensi_filter_rows_eligible(PDO $pdo, array $rows, string $startDate, string $endDate): array
{
    if ($rows === []) {
        return [];
    }
    $eligibilitySet = presensi_jadwal_eligibility_set($pdo, $startDate, $endDate);
    $out = [];
    foreach ($rows as $row) {
        if (presensi_row_eligible_for_hitung($pdo, $row, $eligibilitySet)) {
            $out[] = $row;
        }
    }

    return $out;
}

/** Apakah waktu acuan sudah melewati jam selesai kegiatan pada tanggal tersebut. */
function presensi_jam_selesai_lewat(string $tanggal, string $jamSelesai, ?string $asOfDatetime = null): bool
{
    $tanggal = trim($tanggal);
    $jamSelesai = trim($jamSelesai);
    if ($tanggal === '' || $jamSelesai === '') {
        return false;
    }
    $asOf = $asOfDatetime ?? date('Y-m-d H:i:s');
    $batas = strtotime($tanggal . ' ' . substr($jamSelesai, 0, 8));
    $now = strtotime($asOf);

    return $batas !== false && $now !== false && $now > $batas;
}

/**
 * Status tampilan/rekap: belum scan selama kegiatan → BELUM; setelah jam selesai → ALPA.
 */
function presensi_status_efektif(?string $statusDb, string $tanggal, ?string $jamSelesai, ?string $asOfDatetime = null): string
{
    $st = strtoupper(trim((string) $statusDb));
    if ($st === '') {
        $st = 'BELUM';
    }
    if (in_array($st, ['HADIR', 'IZIN', 'SAKIT'], true)) {
        return $st;
    }
    $jamSelesai = $jamSelesai !== null ? trim($jamSelesai) : '';
    $lewat = $jamSelesai !== '' && presensi_jam_selesai_lewat($tanggal, $jamSelesai, $asOfDatetime);
    if ($st === 'ALPA' && !$lewat) {
        return 'BELUM';
    }
    if (($st === 'BELUM' || $st === 'ALPA') && $lewat) {
        return 'ALPA';
    }
    if ($st === 'ALPA') {
        return 'ALPA';
    }

    return $st === '' ? 'BELUM' : $st;
}

/**
 * Peta jam_selesai per kegiatan+tingkatan pada satu tanggal.
 *
 * @return array<string, string> kunci "kegiatan_id|tingkatan_lower" atau "kegiatan_id|*"
 */
function presensi_jadwal_jam_selesai_map(PDO $pdo, string $tanggal): array
{
    $map = [];
    if (!table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan')) {
        return $map;
    }
    $hariKe = (int) date('N', strtotime($tanggal) ?: time);
    $st = $pdo->prepare('
        SELECT j.kegiatan_id, j.tingkatan, j.jam_selesai
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id AND k.is_active = 1
        WHERE j.hari_ke = 0 OR j.hari_ke = :hk
    ');
    $st->execute(['hk' => $hariKe]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $kid = (int) ($row['kegiatan_id'] ?? 0);
        if ($kid <= 0) {
            continue;
        }
        $js = (string) ($row['jam_selesai'] ?? '');
        if ($js === '') {
            continue;
        }
        $tg = trim((string) ($row['tingkatan'] ?? ''));
        if (strcasecmp($tg, 'Semua Tingkatan') === 0) {
            $map[$kid . '|*'] = $js;
        } elseif ($tg !== '') {
            $map[$kid . '|' . strtolower($tg)] = $js;
        }
    }

    return $map;
}

function presensi_jadwal_jam_selesai_for(PDO $pdo, string $tanggal, int $kegiatanId, string $tingkatan, ?array $map = null): ?string
{
    if ($kegiatanId <= 0) {
        return null;
    }
    $map ??= presensi_jadwal_jam_selesai_map($pdo, $tanggal);
    $tk = strtolower(trim($tingkatan));
    if (isset($map[$kegiatanId . '|' . $tk])) {
        return $map[$kegiatanId . '|' . $tk];
    }

    return $map[$kegiatanId . '|*'] ?? null;
}

/**
 * Finalisasi ALPA: sinkronkan presensi untuk slot yang jam selesainya sudah lewat.
 */
function presensi_finalize_date_range(PDO $pdo, string $startDate, string $endDate, int $createdBy = 1): void
{
    if (!function_exists('sync_presence_for_ended_schedules')) {
        require_once __DIR__ . '/app.php';
    }
    if (!table_exists($pdo, 'jadwal_kegiatan')) {
        return;
    }
    $startTs = strtotime($startDate) ?: time();
    $endTs = strtotime($endDate) ?: $startTs;
    $today = date('Y-m-d');
    $nowJam = date('H:i:s');
    for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
        $tanggal = date('Y-m-d', $ts);
        if ($tanggal > $today) {
            continue;
        }
        $jam = $tanggal === $today ? $nowJam : '23:59:59';
        sync_presence_for_ended_schedules($pdo, $tanggal, $jam, $createdBy);
        if ($tanggal === $today && function_exists('sync_presence_for_active_schedules')) {
            sync_presence_for_active_schedules($pdo, $tanggal, $jam, $createdBy);
        }
    }
}

/**
 * Terapkan status efektif (BELUM→ALPA setelah jam selesai) pada baris rekap harian.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function presensi_apply_status_efektif_rows(PDO $pdo, array $rows, string $tanggal, ?string $asOfDatetime = null): array
{
    if ($rows === []) {
        return [];
    }
    $map = presensi_jadwal_jam_selesai_map($pdo, $tanggal);
    foreach ($rows as &$row) {
        $kid = (int) ($row['kegiatan_id'] ?? 0);
        $tk = (string) ($row['tingkatan'] ?? '');
        $jamSelesai = presensi_jadwal_jam_selesai_for($pdo, $tanggal, $kid, $tk, $map);
        $raw = (string) ($row['status_hari_ini'] ?? $row['status_presensi'] ?? 'BELUM');
        $efektif = presensi_status_efektif($raw, $tanggal, $jamSelesai, $asOfDatetime);
        if (isset($row['status_hari_ini'])) {
            $row['status_hari_ini'] = $efektif;
        }
        if (isset($row['status_presensi'])) {
            $row['status_presensi'] = $efektif;
        }
    }
    unset($row);

    return $rows;
}

/**
 * Ambil baris presensi mentah untuk rekap keaktifan.
 *
 * @return list<array<string, mixed>>
 */
function presensi_fetch_rows_rekap(PDO $pdo, string $startDate, string $endDate, int $kegiatanId = 0): array
{
    if (!table_exists($pdo, 'presensi') || !table_exists($pdo, 'santri')) {
        return [];
    }

    $auditUserId = (int) ($_SESSION['user']['id'] ?? 1);
    presensi_finalize_date_range($pdo, $startDate, $endDate, $auditUserId > 0 ? $auditUserId : 1);

    require_once __DIR__ . '/santri_operasional.php';
    $sqlAktif = santri_sql_aktif_only('s');
    $kegiatanSql = '';
    $params = ['start_date' => $startDate, 'end_date' => $endDate];
    if ($kegiatanId > 0) {
        $kegiatanSql = ' AND p.kegiatan_id = :kegiatan_id';
        $params['kegiatan_id'] = $kegiatanId;
    }

    $stmt = $pdo->prepare('
        SELECT
            p.id,
            p.tanggal_presensi,
            p.status_presensi,
            p.kegiatan_id,
            s.id AS santri_id,
            s.nama_santri,
            s.nis,
            s.tingkatan,
            COALESCE(k.nama_kegiatan, "Tanpa Kegiatan") AS nama_kegiatan
        FROM presensi p
        INNER JOIN santri s ON s.id = p.santri_id AND ' . $sqlAktif . '
        LEFT JOIN kegiatan k ON k.id = p.kegiatan_id
        WHERE p.tanggal_presensi BETWEEN :start_date AND :end_date' . $kegiatanSql . '
        ORDER BY s.nama_santri ASC, p.tanggal_presensi ASC
    ');
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return presensi_filter_rows_eligible($pdo, $rows, $startDate, $endDate);
}
