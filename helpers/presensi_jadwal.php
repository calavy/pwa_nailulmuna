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

    $hariKe = (int) date('N', strtotime($tanggal) ?: time());
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
    static $cache = [];
    $cacheKey = $startDate . '|' . $endDate;
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $set = [];
    if (!table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan')) {
        return $cache[$cacheKey] = $set;
    }

    $stmt = $pdo->query('
        SELECT j.kegiatan_id, j.tingkatan, j.hari_ke
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id AND k.is_active = 1
    ');
    $jadwalRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($jadwalRows === []) {
        return $cache[$cacheKey] = $set;
    }

    /** @var array<int, list<array<string, mixed>>> $jadwalByHari */
    $jadwalByHari = [];
    foreach ($jadwalRows as $jr) {
        $hariJadwal = (int) ($jr['hari_ke'] ?? 0);
        $jadwalByHari[$hariJadwal][] = $jr;
    }

    $startTs = strtotime($startDate) ?: time();
    $endTs = strtotime($endDate) ?: $startTs;
    for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
        $tanggal = date('Y-m-d', $ts);
        $hariKe = (int) date('N', $ts);
        $slots = array_merge($jadwalByHari[0] ?? [], $jadwalByHari[$hariKe] ?? []);
        foreach ($slots as $jr) {
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

    return $cache[$cacheKey] = $set;
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

    $effStart = $endDate;
    $effEnd = $startDate;
    foreach ($rows as $row) {
        $d = (string) ($row['tanggal_presensi'] ?? '');
        if ($d === '' || $d < $startDate || $d > $endDate) {
            continue;
        }
        if ($d < $effStart) {
            $effStart = $d;
        }
        if ($d > $effEnd) {
            $effEnd = $d;
        }
    }
    if ($effStart > $effEnd) {
        return [];
    }

    $eligibilitySet = presensi_jadwal_eligibility_set($pdo, $effStart, $effEnd);
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
 * Status tampilan/rekap: tanpa scan/izin/sakit → ALPA setelah jam kegiatan selesai;
 * sebelum selesai belum dihitung (bukan istirahat).
 */
function presensi_status_efektif(?string $statusDb, string $tanggal, ?string $jamSelesai, ?string $asOfDatetime = null): string
{
    $st = strtoupper(trim((string) $statusDb));
    if (in_array($st, ['HADIR', 'IZIN', 'SAKIT'], true)) {
        return $st;
    }
    if ($st === 'ISTIRAHAT') {
        $st = '';
    }
    $jamSelesai = $jamSelesai !== null ? trim($jamSelesai) : '';
    if ($jamSelesai === '') {
        return $st === 'ALPA' ? 'ALPA' : '';
    }
    if (presensi_jam_selesai_lewat($tanggal, $jamSelesai, $asOfDatetime)) {
        return 'ALPA';
    }

    return '';
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
    $hariKe = (int) date('N', strtotime($tanggal) ?: time());
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

const PRESENSI_FINALIZED_SETTING_KEY = 'presensi_finalized_dates';

/** @return array<string, true> */
function presensi_finalized_dates_map(PDO $pdo, bool $reload = false): array
{
    static $cache = null;
    if (!$reload && is_array($cache)) {
        return $cache;
    }
    $raw = app_setting($pdo, PRESENSI_FINALIZED_SETTING_KEY, '{}');
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        $cache = [];

        return $cache;
    }
    $out = [];
    foreach ($decoded as $d => $flag) {
        if (is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && $flag) {
            $out[$d] = true;
        }
    }
    $cache = $out;

    return $cache;
}

function presensi_finalized_date_is_set(PDO $pdo, string $tanggal): bool
{
    $map = presensi_finalized_dates_map($pdo);

    return isset($map[$tanggal]);
}

function presensi_finalized_date_mark(PDO $pdo, string $tanggal): void
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return;
    }
    $map = presensi_finalized_dates_map($pdo);
    $map[$tanggal] = true;
    if (count($map) > 400) {
        ksort($map);
        $map = array_slice($map, -365, null, true);
    }
    save_setting($pdo, PRESENSI_FINALIZED_SETTING_KEY, json_encode($map, JSON_UNESCAPED_UNICODE));
    presensi_finalized_dates_map($pdo, true);
}

/** Hapus tanda finalisasi untuk rentang tanggal (paksa sync ulang). */
function presensi_finalized_dates_clear_range(PDO $pdo, string $startDate, string $endDate): void
{
    $startTs = strtotime($startDate) ?: time();
    $endTs = strtotime($endDate) ?: $startTs;
    $map = presensi_finalized_dates_map($pdo);
    for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
        unset($map[date('Y-m-d', $ts)]);
    }
    save_setting($pdo, PRESENSI_FINALIZED_SETTING_KEY, json_encode($map, JSON_UNESCAPED_UNICODE));
    presensi_finalized_dates_map($pdo, true);
}

/**
 * Finalisasi ALPA: sinkronkan presensi untuk slot yang jam selesainya sudah lewat.
 *
 * Hari lampau yang sudah difinalisasi diskip (kecuali $forceRefresh). Hari ini selalu di-sync.
 */
function presensi_finalize_date_range(PDO $pdo, string $startDate, string $endDate, int $createdBy = 1, bool $forceRefresh = false): void
{
    static $finalizedRanges = [];
    $key = $startDate . '|' . $endDate . '|' . ($forceRefresh ? '1' : '0');
    if (isset($finalizedRanges[$key])) {
        return;
    }
    $finalizedRanges[$key] = true;

    try {
        if (!function_exists('sync_presence_for_ended_schedules')) {
            require_once __DIR__ . '/app.php';
        }
        require_once __DIR__ . '/presensi_admin.php';
        ensure_presensi_indexes($pdo);
        if (!table_exists($pdo, 'jadwal_kegiatan')) {
            return;
        }
        if ($forceRefresh) {
            presensi_finalized_dates_clear_range($pdo, $startDate, $endDate);
        }
        $startTs = strtotime($startDate) ?: time();
        $endTs = strtotime($endDate) ?: $startTs;
        if ($endTs < $startTs) {
            return;
        }
        $maxDays = 62;
        if ((int) (($endTs - $startTs) / 86400) > $maxDays) {
            $endTs = $startTs + ($maxDays * 86400);
        }
        $today = date('Y-m-d');
        $nowJam = date('H:i:s');
        for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
            $tanggal = date('Y-m-d', $ts);
            if ($tanggal > $today) {
                continue;
            }
            $isToday = $tanggal === $today;
            if (!$isToday && !$forceRefresh && presensi_finalized_date_is_set($pdo, $tanggal)) {
                continue;
            }
            $pdo = pondok_pdo_ping($pdo);
            $jam = $isToday ? $nowJam : '23:59:59';
            sync_presence_for_ended_schedules($pdo, $tanggal, $jam, $createdBy);
            if ($isToday && function_exists('sync_presence_for_active_schedules')) {
                sync_presence_for_active_schedules($pdo, $tanggal, $jam, $createdBy);
            }
            if (!$isToday) {
                presensi_finalized_date_mark($pdo, $tanggal);
            }
        }
    } catch (Throwable $e) {
        error_log('[presensi_finalize_date_range] ' . $startDate . '..' . $endDate . ': ' . $e->getMessage());
    }
}

/** Finalisasi hari ini saja, dibatasi agar rekap PKPPS tidak sync berulang tiap klik. */
function presensi_finalize_today_throttled(PDO $pdo, int $createdBy = 1, int $ttlSeconds = 180): void
{
    $today = date('Y-m-d');
    $sessionKey = 'presensi_finalize_today_ts_' . $today;
    $last = (int) ($_SESSION[$sessionKey] ?? 0);
    if ($last > 0 && (time() - $last) < $ttlSeconds) {
        return;
    }
    presensi_finalize_date_range($pdo, $today, $today, $createdBy > 0 ? $createdBy : 1);
    $_SESSION[$sessionKey] = time();
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
        $raw = (string) ($row['status_hari_ini'] ?? $row['status_presensi'] ?? '');
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
function presensi_fetch_rows_rekap(
    PDO $pdo,
    string $startDate,
    string $endDate,
    int $kegiatanId = 0,
    ?string $kalenderHijriyahKey = null,
    bool $runFinalize = false
): array {
    if (!table_exists($pdo, 'presensi') || !table_exists($pdo, 'santri')) {
        return [];
    }

    require_once __DIR__ . '/rekap_keaktifan.php';
    $clamped = rekap_keaktifan_clamp_periode($pdo, $startDate, $endDate);
    if ($clamped === null) {
        return [];
    }
    [$startDate, $endDate] = $clamped;

    $auditUserId = (int) ($_SESSION['user']['id'] ?? 1);
    if ($runFinalize) {
        presensi_finalize_date_range($pdo, $startDate, $endDate, $auditUserId > 0 ? $auditUserId : 1);
    } else {
        rekap_keaktifan_prepare_periode_presensi($pdo, $startDate, $endDate);
    }

    require_once __DIR__ . '/santri_operasional.php';
    $sqlAktif = santri_sql_aktif_only('s');
    $kegiatanSql = '';
    $kalenderSql = '';
    $params = ['start_date' => $startDate, 'end_date' => $endDate];
    if ($kegiatanId > 0) {
        $kegiatanSql = ' AND p.kegiatan_id = :kegiatan_id';
        $params['kegiatan_id'] = $kegiatanId;
    }
    if ($kalenderHijriyahKey !== null && $kalenderHijriyahKey !== '' && column_exists($pdo, 'presensi', 'kalender_hijriyah')) {
        $kalenderSql = ' AND p.kalender_hijriyah = :kalender_hijriyah';
        $params['kalender_hijriyah'] = $kalenderHijriyahKey;
    }

    $stmt = $pdo->prepare('
        SELECT
            p.id,
            p.tanggal_presensi,
            p.status_presensi,
            p.catatan,
            p.kegiatan_id,
            s.id AS santri_id,
            s.nama_santri,
            s.nis,
            s.tingkatan,
            COALESCE(k.nama_kegiatan, "Tanpa Kegiatan") AS nama_kegiatan,
            COALESCE(k.kategori_kegiatan, "TAALIM") AS kategori_kegiatan
        FROM presensi p
        INNER JOIN santri s ON s.id = p.santri_id AND ' . $sqlAktif . '
        LEFT JOIN kegiatan k ON k.id = p.kegiatan_id
        WHERE p.tanggal_presensi BETWEEN :start_date AND :end_date' . $kegiatanSql . $kalenderSql . '
        ORDER BY s.nama_santri ASC, p.tanggal_presensi ASC
    ');
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return presensi_filter_rows_eligible($pdo, $rows, $startDate, $endDate);
}
