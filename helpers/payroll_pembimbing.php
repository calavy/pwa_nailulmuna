<?php

declare(strict_types=1);

/**
 * Modul payroll pembimbing.
 *
 * Master tarif global per kriteria beban kerja (Berat/Sedang/Ringan/Khusus)
 * disimpan di tabel `tarif_payroll_pembimbing`. Kriteria beban per jam mengikuti
 * `kegiatan.payroll_kriteria` untuk kegiatan Ta'lim (atur di Pengaturan → Beban Payroll Ta'lim).
 * Per pembimbing hanya `gaji_pokok` (tunjangan tetap bulanan).
 *
 * Formula akhir bulanan:
 *   total_gaji = gaji_pokok + Σ(jam_per_scan × tarif_per_jam[kriteria_kegiatan])
 *
 * Kriteria beban per mata pelajaran/kegiatan disimpan di `kegiatan.payroll_kriteria`.
 * total_jam_kerja dihitung dari presensi_pembimbing di-join jadwal (fallback 1 jam/scan).
 */

const PAYROLL_PEMBIMBING_KRITERIA = ['BERAT', 'SEDANG', 'RINGAN', 'KHUSUS'];
const PAYROLL_PEMBIMBING_DEFAULT_KRITERIA = 'RINGAN';
const PAYROLL_PEMBIMBING_JAM_MODES = ['jadwal', 'per_scan'];

/** @return array<string,string> */
function payroll_pembimbing_jam_mode_labels(): array
{
    return [
        'jadwal' => 'Durasi jadwal',
        'per_scan' => '1 jam per scan hadir',
    ];
}

function payroll_pembimbing_normalize_jam_mode(?string $mode): string
{
    $m = strtolower(trim((string) $mode));

    return in_array($m, PAYROLL_PEMBIMBING_JAM_MODES, true) ? $m : 'jadwal';
}

/** Ekspresi SQL jam kerja — mode jadwal (durasi slot) atau per_scan (1 jam tiap DATANG). */
function payroll_pembimbing_scan_jam_expr_sql(string $jamMode, string $presensiAlias = 'p'): string
{
    if (payroll_pembimbing_normalize_jam_mode($jamMode) === 'per_scan') {
        $p = preg_replace('/[^a-z_]/', '', strtolower($presensiAlias)) ?: 'p';

        return 'CASE WHEN ' . $p . '.jenis_scan = "DATANG" THEN 1 ELSE 0 END';
    }

    return payroll_pembimbing_scan_jam_case_sql($presensiAlias);
}

/** Pastikan kolom presensi_pembimbing siap untuk payroll (kegiatan_id). */
function payroll_pembimbing_ensure_presensi_schema(PDO $pdo): void
{
    if (!function_exists('table_exists') || !table_exists($pdo, 'presensi_pembimbing')) {
        return;
    }
    if (function_exists('column_exists') && column_exists($pdo, 'presensi_pembimbing', 'kegiatan_id')) {
        return;
    }
    try {
        $pdo->exec('ALTER TABLE presensi_pembimbing ADD COLUMN IF NOT EXISTS kegiatan_id INT NULL AFTER pembimbing_id');
    } catch (Throwable $e) {
        try {
            $pdo->exec('ALTER TABLE presensi_pembimbing ADD COLUMN kegiatan_id INT NULL AFTER pembimbing_id');
        } catch (Throwable $e2) {
            try {
                $pdo->exec('ALTER TABLE presensi_pembimbing ADD COLUMN kegiatan_id INT NULL');
            } catch (Throwable $e3) {
                // Biarkan query builder fallback tanpa kolom kegiatan_id.
            }
        }
    }
}

function payroll_pembimbing_presensi_has_kegiatan_id(PDO $pdo): bool
{
    payroll_pembimbing_ensure_presensi_schema($pdo);

    return function_exists('column_exists')
        && table_exists($pdo, 'presensi_pembimbing')
        && column_exists($pdo, 'presensi_pembimbing', 'kegiatan_id');
}

/** Join kegiatan efektif dari presensi langsung atau jadwal yang cocok. */
function payroll_pembimbing_kegiatan_join_sql(PDO $pdo, string $presensiAlias = 'p'): string
{
    $p = preg_replace('/[^a-z_]/', '', strtolower($presensiAlias)) ?: 'p';
    if (payroll_pembimbing_presensi_has_kegiatan_id($pdo)) {
        return '
        LEFT JOIN kegiatan k ON k.id = ' . $p . '.kegiatan_id
        LEFT JOIN kegiatan k_j ON k_j.id = COALESCE(j.kegiatan_id, pj.kegiatan_id)
    ';
    }

    return '
        LEFT JOIN kegiatan k_j ON k_j.id = COALESCE(j.kegiatan_id, pj.kegiatan_id)
    ';
}

/** Join jadwal presensi pembimbing — cocokkan jadwal kajian/PKPPS pembimbing + jam scan. */
function payroll_pembimbing_scan_jadwal_join_sql(PDO $pdo, string $presensiAlias = 'p'): string
{
    $p = preg_replace('/[^a-z_]/', '', strtolower($presensiAlias)) ?: 'p';
    $hasKg = payroll_pembimbing_presensi_has_kegiatan_id($pdo);

    if ($hasKg) {
        $jadwalKgWhere = '
                (
                    (' . $p . '.kegiatan_id IS NOT NULL AND j2.kegiatan_id = ' . $p . '.kegiatan_id)
                    OR (
                        (' . $p . '.kegiatan_id IS NULL OR ' . $p . '.kegiatan_id = 0)
                        AND j2.pembimbing_id = ' . $p . '.pembimbing_id
                    )
                )
        ';
        $pkppsKgWhere = '
                (
                    (' . $p . '.kegiatan_id IS NOT NULL AND pj2.kegiatan_id = ' . $p . '.kegiatan_id)
                    OR (
                        (' . $p . '.kegiatan_id IS NULL OR ' . $p . '.kegiatan_id = 0)
                        AND pj2.pembimbing_id = ' . $p . '.pembimbing_id
                    )
                )
        ';
    } else {
        $jadwalKgWhere = 'j2.pembimbing_id = ' . $p . '.pembimbing_id';
        $pkppsKgWhere = 'pj2.pembimbing_id = ' . $p . '.pembimbing_id';
    }

    return '
        LEFT JOIN jadwal_kegiatan j ON j.id = (
            SELECT j2.id FROM jadwal_kegiatan j2
            INNER JOIN kegiatan k2 ON k2.id = j2.kegiatan_id AND k2.is_active = 1
            WHERE ' . $jadwalKgWhere . '
              AND (j2.hari_ke = 0 OR j2.hari_ke = WEEKDAY(' . $p . '.tanggal) + 1)
              AND (
                    j2.pembimbing_id = ' . $p . '.pembimbing_id
                    OR j2.pembimbing_id IS NULL
                    OR j2.pembimbing_id = 0
                  )
              AND (
                    ' . $p . '.jam IS NULL OR TRIM(' . $p . '.jam) = ""
                    OR (' . $p . '.jam BETWEEN j2.jam_mulai AND j2.jam_selesai)
                  )
            ORDER BY
                CASE
                    WHEN j2.pembimbing_id = ' . $p . '.pembimbing_id THEN 0
                    WHEN j2.pembimbing_id IS NULL OR j2.pembimbing_id = 0 THEN 1
                    ELSE 2
                END,
                j2.jam_mulai ASC,
                j2.id ASC
            LIMIT 1
        )
        LEFT JOIN pkpps_jadwal pj ON pj.id = (
            SELECT pj2.id FROM pkpps_jadwal pj2
            INNER JOIN kegiatan k3 ON k3.id = pj2.kegiatan_id AND k3.is_active = 1
            WHERE ' . $pkppsKgWhere . '
              AND pj2.pembimbing_id = ' . $p . '.pembimbing_id
              AND pj2.is_aktif = 1
              AND (pj2.hari_ke = 0 OR pj2.hari_ke = WEEKDAY(' . $p . '.tanggal) + 1)
              AND (
                    ' . $p . '.jam IS NULL OR TRIM(' . $p . '.jam) = ""
                    OR (' . $p . '.jam BETWEEN pj2.jam_mulai AND pj2.jam_selesai)
                  )
            ORDER BY pj2.jam_mulai ASC, pj2.id ASC
            LIMIT 1
        )
    ';
}

/** Ekspresi SQL jam kerja dari satu baris presensi_pembimbing. */
function payroll_pembimbing_scan_jam_case_sql(string $presensiAlias = 'p'): string
{
    $p = preg_replace('/[^a-z_]/', '', strtolower($presensiAlias)) ?: 'p';

    return '
        CASE
            WHEN ' . $p . '.jenis_scan = "DATANG"
                AND COALESCE(j.jam_mulai, pj.jam_mulai) IS NOT NULL
                AND COALESCE(j.jam_selesai, pj.jam_selesai) IS NOT NULL
                THEN GREATEST(
                    TIMESTAMPDIFF(MINUTE, COALESCE(j.jam_mulai, pj.jam_mulai), COALESCE(j.jam_selesai, pj.jam_selesai)),
                    0
                ) / 60
            WHEN ' . $p . '.jenis_scan = "DATANG" THEN 1
            ELSE 0
        END
    ';
}

/** @return array<string,string> kriteria => label tampilan */
function payroll_pembimbing_kriteria_labels(): array
{
    return [
        'BERAT' => 'Berat',
        'SEDANG' => 'Sedang',
        'RINGAN' => 'Ringan',
        'KHUSUS' => 'Khusus/Lainnya',
    ];
}

/** Pastikan tabel/kolom payroll ada. Idempotent + once-per-session guard. */
function payroll_pembimbing_ensure_schema(PDO $pdo): void
{
    payroll_pembimbing_ensure_presensi_schema($pdo);

    static $done = false;
    if ($done) {
        return;
    }
    if (isset($_SESSION['payroll_pembimbing_v1'])) {
        $done = true;
        return;
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tarif_payroll_pembimbing (
                kriteria ENUM('BERAT','SEDANG','RINGAN','KHUSUS') NOT NULL PRIMARY KEY,
                nominal_per_jam DECIMAL(12,2) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            INSERT IGNORE INTO tarif_payroll_pembimbing (kriteria, nominal_per_jam) VALUES
                ('BERAT', 50000),
                ('SEDANG', 35000),
                ('RINGAN', 25000),
                ('KHUSUS', 40000)
        ");

        if (function_exists('table_exists') && table_exists($pdo, 'pembimbing')) {
            if (function_exists('column_exists')) {
                if (!column_exists($pdo, 'pembimbing', 'gaji_pokok')) {
                    $pdo->exec("ALTER TABLE pembimbing ADD COLUMN gaji_pokok DECIMAL(12,2) NOT NULL DEFAULT 0");
                }
                if (!column_exists($pdo, 'pembimbing', 'tarif_kriteria')) {
                    $pdo->exec("ALTER TABLE pembimbing ADD COLUMN tarif_kriteria ENUM('BERAT','SEDANG','RINGAN','KHUSUS') NOT NULL DEFAULT 'RINGAN'");
                }
            } else {
                @$pdo->exec("ALTER TABLE pembimbing ADD COLUMN gaji_pokok DECIMAL(12,2) NOT NULL DEFAULT 0");
                @$pdo->exec("ALTER TABLE pembimbing ADD COLUMN tarif_kriteria ENUM('BERAT','SEDANG','RINGAN','KHUSUS') NOT NULL DEFAULT 'RINGAN'");
            }
        }
        if (function_exists('ensure_kegiatan_payroll_kriteria_column')) {
            ensure_kegiatan_payroll_kriteria_column($pdo);
        }
        $_SESSION['payroll_pembimbing_v1'] = 1;
    } catch (Throwable $e) {
        // Jangan fatal — schema akan dicoba lagi navigasi berikutnya.
    }
    $done = true;
}

/**
 * Ambil tarif per jam untuk setiap kriteria. Mengembalikan map lengkap dengan
 * fallback 0 jika baris seed belum ada (defensif).
 *
 * @return array<string,float>
 */
function payroll_pembimbing_tarif_map(PDO $pdo): array
{
    $map = array_fill_keys(PAYROLL_PEMBIMBING_KRITERIA, 0.0);
    try {
        $rows = $pdo->query('SELECT kriteria, nominal_per_jam FROM tarif_payroll_pembimbing')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $k = strtoupper((string) ($r['kriteria'] ?? ''));
            if (isset($map[$k])) {
                $map[$k] = (float) ($r['nominal_per_jam'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        // tabel belum siap — kembalikan map default 0
    }

    return $map;
}

/**
 * Hitung komponen gaji bulanan satu pembimbing.
 *
 * @param array<string,float> $tarifMap output payroll_pembimbing_tarif_map()
 * @return array{
 *     gaji_pokok: float,
 *     tarif_per_jam: float,
 *     total_jam: float,
 *     gaji_per_jam: float,
 *     total_gaji: float,
 *     kriteria: string,
 *     kriteria_label: string
 * }
 */
function payroll_pembimbing_compute(float $totalJam, float $gajiPokok, string $kriteria, array $tarifMap): array
{
    $kriteria = strtoupper(trim($kriteria));
    if (!in_array($kriteria, PAYROLL_PEMBIMBING_KRITERIA, true)) {
        $kriteria = PAYROLL_PEMBIMBING_DEFAULT_KRITERIA;
    }
    $tarifPerJam = (float) ($tarifMap[$kriteria] ?? 0);
    $gajiPokok = max(0.0, $gajiPokok);
    $totalJam = max(0.0, $totalJam);
    $gajiPerJam = $totalJam * $tarifPerJam;
    $labels = payroll_pembimbing_kriteria_labels();

    return [
        'gaji_pokok' => $gajiPokok,
        'tarif_per_jam' => $tarifPerJam,
        'total_jam' => $totalJam,
        'gaji_per_jam' => $gajiPerJam,
        'total_gaji' => $gajiPokok + $gajiPerJam,
        'kriteria' => $kriteria,
        'kriteria_label' => $labels[$kriteria] ?? $kriteria,
    ];
}

/** Validasi kriteria — kembalikan kriteria valid atau default. */
function payroll_pembimbing_normalize_kriteria(?string $kriteria): string
{
    $k = strtoupper(trim((string) $kriteria));
    return in_array($k, PAYROLL_PEMBIMBING_KRITERIA, true) ? $k : PAYROLL_PEMBIMBING_DEFAULT_KRITERIA;
}

/** Ekspresi SQL kriteria beban payroll per baris presensi (hanya kegiatan Ta'lim; Jama'ah = RINGAN). */
function payroll_pembimbing_scan_kriteria_case_sql(PDO $pdo, string $presensiAlias = 'p'): string
{
    $p = preg_replace('/[^a-z_]/', '', strtolower($presensiAlias)) ?: 'p';
    $hasKg = payroll_pembimbing_presensi_has_kegiatan_id($pdo);
    $directBranch = $hasKg
        ? '
            WHEN ' . $p . '.kegiatan_id IS NOT NULL AND ' . $p . '.kegiatan_id > 0
                 AND k.id IS NOT NULL
                 AND UPPER(COALESCE(k.kategori_kegiatan, "TAALIM")) = "TAALIM"
                THEN UPPER(COALESCE(NULLIF(k.payroll_kriteria, ""), "RINGAN"))'
        : '';
    $jadwalBranch = '
            WHEN k_j.id IS NOT NULL
                 AND UPPER(COALESCE(k_j.kategori_kegiatan, "TAALIM")) = "TAALIM"
                THEN UPPER(COALESCE(NULLIF(k_j.payroll_kriteria, ""), "RINGAN"))';

    return '
        CASE' . $directBranch . $jadwalBranch . '
            ELSE "RINGAN"
        END
    ';
}

/** Filter kegiatan pada query presensi_pembimbing (abaikan jika kolom belum ada). */
function payroll_pembimbing_kegiatan_filter_sql(PDO $pdo, int $kegiatanFilterId, string $presensiAlias = 'p'): string
{
    if ($kegiatanFilterId <= 0 || !payroll_pembimbing_presensi_has_kegiatan_id($pdo)) {
        return '';
    }
    $p = preg_replace('/[^a-z_]/', '', strtolower($presensiAlias)) ?: 'p';

    return ' AND ' . $p . '.kegiatan_id = :kegiatan_id';
}

/**
 * Jam kerja per pembimbing dikelompokkan per kriteria beban (dari kegiatan/mapel).
 *
 * @return array<int, array{total_jam: float, by_kriteria: array<string, float>}>
 */
function payroll_pembimbing_jam_kriteria_map(
    PDO $pdo,
    string $startDate,
    string $endDate,
    int $kegiatanFilterId = 0,
    string $jamMode = 'jadwal'
): array {
    if (!table_exists($pdo, 'presensi_pembimbing')) {
        return [];
    }
    ensure_kegiatan_payroll_kriteria_column($pdo);

    $sql = '
        SELECT p.pembimbing_id,
               ' . payroll_pembimbing_scan_kriteria_case_sql($pdo, 'p') . ' AS kriteria,
               SUM(' . payroll_pembimbing_scan_jam_expr_sql($jamMode, 'p') . ') AS jam
        FROM presensi_pembimbing p
        INNER JOIN pembimbing pb ON pb.id = p.pembimbing_id
        ' . payroll_pembimbing_scan_jadwal_join_sql($pdo, 'p') . '
        ' . payroll_pembimbing_kegiatan_join_sql($pdo, 'p') . '
        WHERE p.tanggal BETWEEN :start_date AND :end_date
          AND p.jenis_scan = "DATANG"
    ';
    $params = ['start_date' => $startDate, 'end_date' => $endDate];
    $sql .= payroll_pembimbing_kegiatan_filter_sql($pdo, $kegiatanFilterId, 'p');
    if ($kegiatanFilterId > 0 && payroll_pembimbing_presensi_has_kegiatan_id($pdo)) {
        $params['kegiatan_id'] = $kegiatanFilterId;
    }
    $sql .= ' GROUP BY p.pembimbing_id, kriteria';

    $st = $pdo->prepare($sql);
    $st->execute($params);

    /** @var array<int, array{total_jam: float, by_kriteria: array<string, float>}> $map */
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $pid = (int) ($row['pembimbing_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $kriteria = payroll_pembimbing_normalize_kriteria((string) ($row['kriteria'] ?? ''));
        $jam = max(0.0, (float) ($row['jam'] ?? 0));
        if (!isset($map[$pid])) {
            $map[$pid] = ['total_jam' => 0.0, 'by_kriteria' => []];
        }
        $map[$pid]['total_jam'] += $jam;
        $map[$pid]['by_kriteria'][$kriteria] = ($map[$pid]['by_kriteria'][$kriteria] ?? 0.0) + $jam;
    }

    return $map;
}

/**
 * Hitung gaji bulanan dengan tarif berbeda per kriteria kegiatan.
 *
 * @param array<string,float> $jamByKriteria kriteria => total jam
 * @param array<string,float> $tarifMap
 * @return array{
 *     gaji_pokok: float,
 *     tarif_per_jam: float,
 *     total_jam: float,
 *     gaji_per_jam: float,
 *     total_gaji: float,
 *     kriteria: string,
 *     kriteria_label: string,
 *     by_kriteria: array<string, float>
 * }
 */
function payroll_pembimbing_compute_mixed(float $gajiPokok, array $jamByKriteria, array $tarifMap): array
{
    $labels = payroll_pembimbing_kriteria_labels();
    $gajiPokok = max(0.0, $gajiPokok);
    $totalJam = 0.0;
    $gajiPerJam = 0.0;
    $labelParts = [];
    $primaryKriteria = PAYROLL_PEMBIMBING_DEFAULT_KRITERIA;
    $primaryJam = 0.0;

    foreach ($jamByKriteria as $rawK => $jam) {
        $k = payroll_pembimbing_normalize_kriteria((string) $rawK);
        $jam = max(0.0, (float) $jam);
        if ($jam <= 0) {
            continue;
        }
        $totalJam += $jam;
        $nominal = $jam * (float) ($tarifMap[$k] ?? 0);
        $gajiPerJam += $nominal;
        $labelParts[] = ($labels[$k] ?? $k) . ' ' . number_format($jam, 2, ',', '.') . 'j';
        if ($jam > $primaryJam) {
            $primaryJam = $jam;
            $primaryKriteria = $k;
        }
    }

    $kriteriaLabel = $labelParts !== [] ? implode(' · ', $labelParts) : ($labels[PAYROLL_PEMBIMBING_DEFAULT_KRITERIA] ?? 'Ringan');
    $tarifEfektif = $totalJam > 0 ? $gajiPerJam / $totalJam : 0.0;

    return [
        'gaji_pokok' => $gajiPokok,
        'tarif_per_jam' => $tarifEfektif,
        'total_jam' => $totalJam,
        'gaji_per_jam' => $gajiPerJam,
        'total_gaji' => $gajiPokok + $gajiPerJam,
        'kriteria' => count($labelParts) > 1 ? 'MIXED' : $primaryKriteria,
        'kriteria_label' => $kriteriaLabel,
        'by_kriteria' => $jamByKriteria,
    ];
}

/**
 * Slot jadwal yang diharapkan per pembimbing dalam rentang tanggal (kajian + PKPPS).
 *
 * @return array<int, list<array{tanggal:string,kegiatan_id:int}>>
 */
function payroll_pembimbing_expected_slots_by_pembimbing(
    PDO $pdo,
    string $startDate,
    string $endDate,
    int $kegiatanFilterId = 0
): array {
    /** @var list<array{pembimbing_id:int,hari_ke:int,kegiatan_id:int}> $templates */
    $templates = [];

    if (table_exists($pdo, 'jadwal_kegiatan')) {
        $sql = '
            SELECT j.pembimbing_id, j.hari_ke, j.kegiatan_id
            FROM jadwal_kegiatan j
            INNER JOIN kegiatan k ON k.id = j.kegiatan_id AND k.is_active = 1
            WHERE j.pembimbing_id IS NOT NULL AND j.pembimbing_id > 0
              AND COALESCE(k.kategori_kegiatan, "TAALIM") != "JAMAAH"
        ';
        if ($kegiatanFilterId > 0) {
            $sql .= ' AND j.kegiatan_id = ' . (int) $kegiatanFilterId;
        }
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $templates[] = [
                'pembimbing_id' => (int) ($row['pembimbing_id'] ?? 0),
                'hari_ke' => (int) ($row['hari_ke'] ?? 0),
                'kegiatan_id' => (int) ($row['kegiatan_id'] ?? 0),
            ];
        }
    }

    if (table_exists($pdo, 'pkpps_jadwal')) {
        $sql = '
            SELECT pj.pembimbing_id, pj.hari_ke, pj.kegiatan_id
            FROM pkpps_jadwal pj
            INNER JOIN kegiatan k ON k.id = pj.kegiatan_id AND k.is_active = 1
            WHERE pj.is_aktif = 1 AND pj.pembimbing_id IS NOT NULL AND pj.pembimbing_id > 0
        ';
        if ($kegiatanFilterId > 0) {
            $sql .= ' AND pj.kegiatan_id = ' . (int) $kegiatanFilterId;
        }
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $templates[] = [
                'pembimbing_id' => (int) ($row['pembimbing_id'] ?? 0),
                'hari_ke' => (int) ($row['hari_ke'] ?? 0),
                'kegiatan_id' => (int) ($row['kegiatan_id'] ?? 0),
            ];
        }
    }

    /** @var array<int, list<array{tanggal:string,kegiatan_id:int}>> $out */
    $out = [];
    /** @var array<int, array<string, true>> $seen */
    $seen = [];
    if ($templates === []) {
        return $out;
    }

    $startTs = strtotime($startDate) ?: time();
    $endTs = strtotime($endDate) ?: $startTs;
    for ($ts = $startTs; $ts <= $endTs; $ts += 86400) {
        $hariKe = (int) date('N', $ts);
        $tanggal = date('Y-m-d', $ts);
        foreach ($templates as $tpl) {
            $jHari = (int) ($tpl['hari_ke'] ?? 0);
            if ($jHari !== 0 && $jHari !== $hariKe) {
                continue;
            }
            $pid = (int) ($tpl['pembimbing_id'] ?? 0);
            $kid = (int) ($tpl['kegiatan_id'] ?? 0);
            if ($pid <= 0 || $kid <= 0) {
                continue;
            }
            $slotKey = $tanggal . '|' . $kid;
            if (isset($seen[$pid][$slotKey])) {
                continue;
            }
            $seen[$pid][$slotKey] = true;
            $out[$pid][] = ['tanggal' => $tanggal, 'kegiatan_id' => $kid];
        }
    }

    return $out;
}

/**
 * Kunci slot hadir dari presensi pembimbing (tanggal|kegiatan_id).
 *
 * @return array<int, array<string, true>>
 */
function payroll_pembimbing_hadir_slot_keys_map(
    PDO $pdo,
    string $startDate,
    string $endDate,
    int $kegiatanFilterId = 0
): array {
    if (!table_exists($pdo, 'presensi_pembimbing')) {
        return [];
    }

    payroll_pembimbing_ensure_presensi_schema($pdo);
    $hasKg = payroll_pembimbing_presensi_has_kegiatan_id($pdo);
    $kgSelect = $hasKg ? 'COALESCE(kegiatan_id, 0) AS kegiatan_id' : '0 AS kegiatan_id';

    $sql = '
        SELECT pembimbing_id, tanggal, ' . $kgSelect . '
        FROM presensi_pembimbing
        WHERE tanggal BETWEEN :start_date AND :end_date
          AND jenis_scan = "DATANG"
    ';
    $params = ['start_date' => $startDate, 'end_date' => $endDate];
    if ($kegiatanFilterId > 0 && $hasKg) {
        $sql .= ' AND kegiatan_id = :kegiatan_id';
        $params['kegiatan_id'] = $kegiatanFilterId;
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);

    /** @var array<int, array<string, true>> $map */
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $pid = (int) ($row['pembimbing_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $key = (string) ($row['tanggal'] ?? '') . '|' . (int) ($row['kegiatan_id'] ?? 0);
        $map[$pid][$key] = true;
    }

    return $map;
}

/**
 * Tanggal izin/sakit pembimbing dalam periode.
 *
 * @return array<int, array{IZIN:list<string>,SAKIT:list<string>}>
 */
function payroll_pembimbing_izin_dates_by_pembimbing(PDO $pdo, string $startDate, string $endDate): array
{
    if (!table_exists($pdo, 'perizinan_pembimbing')) {
        return [];
    }

    $st = $pdo->prepare('
        SELECT pembimbing_id, jenis_izin, tanggal_mulai, tanggal_selesai
        FROM perizinan_pembimbing
        WHERE status_izin = "IZIN"
          AND tanggal_mulai <= :end_date
          AND tanggal_selesai >= :start_date
    ');
    $st->execute(['start_date' => $startDate, 'end_date' => $endDate]);

    /** @var array<int, array{IZIN:list<string>,SAKIT:list<string>}> $out */
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $pid = (int) ($row['pembimbing_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        if (!isset($out[$pid])) {
            $out[$pid] = ['IZIN' => [], 'SAKIT' => []];
        }
        $jenis = strtoupper((string) ($row['jenis_izin'] ?? '')) === 'SAKIT' ? 'SAKIT' : 'IZIN';
        $from = max($startDate, (string) ($row['tanggal_mulai'] ?? $startDate));
        $to = min($endDate, (string) ($row['tanggal_selesai'] ?? $endDate));
        $fromTs = strtotime($from) ?: false;
        $toTs = strtotime($to) ?: false;
        if ($fromTs === false || $toTs === false || $toTs < $fromTs) {
            continue;
        }
        for ($ts = $fromTs; $ts <= $toTs; $ts += 86400) {
            $out[$pid][$jenis][] = date('Y-m-d', $ts);
        }
    }

    foreach ($out as &$dates) {
        $dates['IZIN'] = array_values(array_unique($dates['IZIN']));
        $dates['SAKIT'] = array_values(array_unique($dates['SAKIT']));
    }
    unset($dates);

    return $out;
}

/**
 * Hitung alpa pembimbing: slot jadwal tanpa scan DATANG & tanpa izin/sakit pada tanggal itu.
 */
function payroll_pembimbing_hitung_alpa(
    int $pembimbingId,
    array $expectedSlots,
    array $hadirKeys,
    array $izinDates,
    int $totalDays,
    int $distinctHariHadir,
    int $izinHari,
    int $sakitHari
): int {
    if ($expectedSlots !== []) {
        $izinSet = array_fill_keys((array) ($izinDates['IZIN'] ?? []), true);
        $sakitSet = array_fill_keys((array) ($izinDates['SAKIT'] ?? []), true);
        $alpa = 0;
        foreach ($expectedSlots as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $tanggal = (string) ($slot['tanggal'] ?? '');
            $kid = (int) ($slot['kegiatan_id'] ?? 0);
            if ($tanggal === '') {
                continue;
            }
            $key = $tanggal . '|' . $kid;
            if (isset($hadirKeys[$key]) || isset($izinSet[$tanggal]) || isset($sakitSet[$tanggal])) {
                continue;
            }
            $alpa++;
        }

        return $alpa;
    }

    return max(0, $totalDays - $distinctHariHadir - $izinHari - $sakitHari);
}

/**
 * Total jam + jumlah scan DATANG per pembimbing (sumber tunggal payroll & rekap).
 *
 * @return array<int, array{total_jam:float,total_datang:int,hari_hadir:int}>
 */
function payroll_pembimbing_presensi_agg_map(
    PDO $pdo,
    string $startDate,
    string $endDate,
    int $kegiatanFilterId = 0,
    string $jamMode = 'jadwal'
): array {
    if (!table_exists($pdo, 'presensi_pembimbing')) {
        return [];
    }

    $sql = '
        SELECT p.pembimbing_id,
               SUM(' . payroll_pembimbing_scan_jam_expr_sql($jamMode, 'p') . ') AS total_jam,
               SUM(CASE WHEN p.jenis_scan = "DATANG" THEN 1 ELSE 0 END) AS total_datang,
               COUNT(DISTINCT CASE WHEN p.jenis_scan = "DATANG" THEN p.tanggal END) AS hari_hadir
        FROM presensi_pembimbing p
        ' . payroll_pembimbing_scan_jadwal_join_sql($pdo, 'p') . '
        WHERE p.tanggal BETWEEN :start_date AND :end_date
    ';
    $params = ['start_date' => $startDate, 'end_date' => $endDate];
    $sql .= payroll_pembimbing_kegiatan_filter_sql($pdo, $kegiatanFilterId, 'p');
    if ($kegiatanFilterId > 0 && payroll_pembimbing_presensi_has_kegiatan_id($pdo)) {
        $params['kegiatan_id'] = $kegiatanFilterId;
    }
    $sql .= ' GROUP BY p.pembimbing_id';

    $st = $pdo->prepare($sql);
    $st->execute($params);

    /** @var array<int, array{total_jam:float,total_datang:int,hari_hadir:int}> $map */
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $pid = (int) ($row['pembimbing_id'] ?? 0);
        if ($pid <= 0) {
            continue;
        }
        $map[$pid] = [
            'total_jam' => (float) ($row['total_jam'] ?? 0),
            'total_datang' => (int) ($row['total_datang'] ?? 0),
            'hari_hadir' => (int) ($row['hari_hadir'] ?? 0),
        ];
    }

    return $map;
}

/**
 * Daftar kegiatan Ta'lim untuk pengaturan beban payroll + ringkasan slot jadwal.
 *
 * @return list<array<string, mixed>>
 */
function payroll_pembimbing_taalim_kegiatan_settings_rows(PDO $pdo, string $searchQ = ''): array
{
    if (!table_exists($pdo, 'kegiatan')) {
        return [];
    }
    ensure_kegiatan_kategori_column($pdo);
    ensure_kegiatan_payroll_kriteria_column($pdo);

    $sql = '
        SELECT k.id, k.nama_kegiatan,
               COALESCE(k.payroll_kriteria, "RINGAN") AS payroll_kriteria,
               COALESCE(k.is_active, 1) AS is_active,
               (SELECT COUNT(*) FROM jadwal_kegiatan j WHERE j.kegiatan_id = k.id) AS jumlah_jadwal_kajian,
               (SELECT COUNT(*) FROM pkpps_jadwal pj WHERE pj.kegiatan_id = k.id AND pj.is_aktif = 1) AS jumlah_jadwal_pkpps
        FROM kegiatan k
        WHERE COALESCE(k.kategori_kegiatan, "TAALIM") = "TAALIM"
    ';
    $params = [];
    if ($searchQ !== '') {
        $sql .= ' AND k.nama_kegiatan LIKE :q';
        $params['q'] = '%' . $searchQ . '%';
    }
    $sql .= ' ORDER BY k.nama_kegiatan ASC';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $kid = (int) ($row['id'] ?? 0);
        if ($kid <= 0) {
            continue;
        }
        $jKajian = (int) ($row['jumlah_jadwal_kajian'] ?? 0);
        $jPkpps = (int) ($row['jumlah_jadwal_pkpps'] ?? 0);
        $rows[] = [
            'id' => $kid,
            'nama_kegiatan' => (string) ($row['nama_kegiatan'] ?? ''),
            'payroll_kriteria' => payroll_pembimbing_normalize_kriteria((string) ($row['payroll_kriteria'] ?? '')),
            'is_active' => (int) ($row['is_active'] ?? 1),
            'jumlah_jadwal_kajian' => $jKajian,
            'jumlah_jadwal_pkpps' => $jPkpps,
            'jumlah_jadwal' => $jKajian + $jPkpps,
        ];
    }

    return $rows;
}

/**
 * Slot jadwal per kegiatan Ta'lim (kajian + PKPPS) untuk tampilan pengaturan.
 *
 * @param list<int> $kegiatanIds
 * @return array<int, list<array<string, mixed>>>
 */
function payroll_pembimbing_jadwal_slots_by_kegiatan(PDO $pdo, array $kegiatanIds): array
{
    $ids = array_values(array_filter(array_map('intval', $kegiatanIds), static fn(int $id): bool => $id > 0));
    if ($ids === []) {
        return [];
    }
    $out = array_fill_keys($ids, []);
    $in = implode(',', array_fill(0, count($ids), '?'));

    if (table_exists($pdo, 'jadwal_kegiatan')) {
        $sql = '
            SELECT j.kegiatan_id, j.hari_ke, j.jam_mulai, j.jam_selesai, j.tingkatan,
                   COALESCE(pb.nama_pembimbing, "-") AS nama_pembimbing, "KAJIAN" AS sumber
            FROM jadwal_kegiatan j
            LEFT JOIN pembimbing pb ON pb.id = j.pembimbing_id
            WHERE j.kegiatan_id IN (' . $in . ')
            ORDER BY j.hari_ke ASC, j.jam_mulai ASC, j.id ASC
        ';
        $st = $pdo->prepare($sql);
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $kid = (int) ($row['kegiatan_id'] ?? 0);
            if ($kid <= 0) {
                continue;
            }
            $out[$kid][] = [
                'sumber' => 'KAJIAN',
                'hari_ke' => (int) ($row['hari_ke'] ?? 0),
                'jam_mulai' => substr((string) ($row['jam_mulai'] ?? ''), 0, 5),
                'jam_selesai' => substr((string) ($row['jam_selesai'] ?? ''), 0, 5),
                'tingkatan' => (string) ($row['tingkatan'] ?? '-'),
                'nama_pembimbing' => (string) ($row['nama_pembimbing'] ?? '-'),
            ];
        }
    }

    if (table_exists($pdo, 'pkpps_jadwal')) {
        $sql = '
            SELECT pj.kegiatan_id, pj.hari_ke, pj.jam_mulai, pj.jam_selesai,
                   COALESCE(pt.nama_tingkatan, "-") AS tingkatan,
                   COALESCE(pb.nama_pembimbing, "-") AS nama_pembimbing, "PKPPS" AS sumber
            FROM pkpps_jadwal pj
            LEFT JOIN pkpps_tingkatan pt ON pt.id = pj.pkpps_tingkatan_id
            LEFT JOIN pembimbing pb ON pb.id = pj.pembimbing_id
            WHERE pj.kegiatan_id IN (' . $in . ') AND pj.is_aktif = 1
            ORDER BY pj.hari_ke ASC, pj.jam_mulai ASC, pj.id ASC
        ';
        $st = $pdo->prepare($sql);
        $st->execute($ids);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $kid = (int) ($row['kegiatan_id'] ?? 0);
            if ($kid <= 0) {
                continue;
            }
            $out[$kid][] = [
                'sumber' => 'PKPPS',
                'hari_ke' => (int) ($row['hari_ke'] ?? 0),
                'jam_mulai' => substr((string) ($row['jam_mulai'] ?? ''), 0, 5),
                'jam_selesai' => substr((string) ($row['jam_selesai'] ?? ''), 0, 5),
                'tingkatan' => (string) ($row['tingkatan'] ?? '-'),
                'nama_pembimbing' => (string) ($row['nama_pembimbing'] ?? '-'),
            ];
        }
    }

    return $out;
}

/** Simpan kriteria payroll kegiatan Ta'lim (bulk). */
function payroll_pembimbing_save_taalim_kegiatan_kriteria(PDO $pdo, array $kriteriaInput): array
{
    ensure_kegiatan_kategori_column($pdo);
    ensure_kegiatan_payroll_kriteria_column($pdo);
    if (!is_array($kriteriaInput) || $kriteriaInput === []) {
        return ['ok' => false, 'message' => 'Tidak ada data untuk disimpan.', 'updated' => 0];
    }

    $stmt = $pdo->prepare('
        UPDATE kegiatan
        SET payroll_kriteria = :kriteria
        WHERE id = :id AND COALESCE(kategori_kegiatan, "TAALIM") = "TAALIM"
    ');
    $updated = 0;
    try {
        foreach ($kriteriaInput as $rawId => $rawK) {
            $id = (int) $rawId;
            if ($id <= 0) {
                continue;
            }
            $k = payroll_pembimbing_normalize_kriteria((string) $rawK);
            $stmt->execute(['kriteria' => $k, 'id' => $id]);
            $updated += $stmt->rowCount();
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => 'Gagal menyimpan: ' . $e->getMessage(), 'updated' => $updated];
    }

    return [
        'ok' => true,
        'message' => 'Beban payroll ' . $updated . ' kegiatan Ta\'lim diperbarui.',
        'updated' => $updated,
    ];
}

/** Label singkat hari jadwal (1=Senin … 7=Minggu, 0=Setiap hari). */
function payroll_pembimbing_hari_jadwal_label(int $hariKe): string
{
    if ($hariKe === 0) {
        return 'Setiap hari';
    }
    $map = [1 => 'Sen', 2 => 'Sel', 3 => 'Rab', 4 => 'Kam', 5 => 'Jum', 6 => 'Sab', 7 => 'Min'];

    return $map[$hariKe] ?? ('Hari ' . $hariKe);
}

/**
 * Resolve periode rekap/payroll pembimbing (masehi atau hijriyah sesuai kalender pondok).
 *
 * @param array<string, mixed> $query GET (?month, ?year, ?cal, ?previous_mode, ?anchor_masehi_year)
 * @return array{
 *     calendar_mode: string,
 *     month: int,
 *     year: int,
 *     start_date: string,
 *     end_date: string,
 *     total_days: int,
 *     period_label: string,
 *     period_bridge: string,
 *     hijri_ym: ?string,
 *     anchor_masehi_year: int,
 *     year_min: int,
 *     year_max: int,
 *     masehi_months: array<int, string>,
 *     hijriyah_months: array<int, string>,
 *     current_hijri_year: int,
 *     current_masehi_year: int
 * }
 */
function payroll_pembimbing_resolve_period(PDO $pdo, array $query): array
{
    require_once __DIR__ . '/rekap_periode.php';
    require_once __DIR__ . '/pondok_kalender.php';
    require_once __DIR__ . '/akademik.php';
    require_once __DIR__ . '/hijri_kalender.php';

    $defaultMode = pondok_kalender_hijriyah($pdo) ? 'hijriyah' : 'masehi';
    $mode = strtolower(trim((string) ($query['cal'] ?? $query['mode'] ?? $defaultMode)));
    if (!in_array($mode, ['masehi', 'hijriyah'], true)) {
        $mode = $defaultMode;
    }
    $previousMode = strtolower(trim((string) ($query['previous_mode'] ?? $mode)));
    if (!in_array($previousMode, ['masehi', 'hijriyah'], true)) {
        $previousMode = $mode;
    }

    $masehiMonths = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];
    $hijriyahMonths = hijri_nama_bulan_list();

    $appTahunMasehiDefault = app_tahun_masehi_default($pdo);
    $currentMasehiYear = (int) date('Y');
    $anchorHijriIni = akademik_hijri_anchor_hari_ini($pdo);
    $currentHijriYear = (int) ($anchorHijriIni['y'] ?? 0);

    $month = max(1, min(12, (int) ($query['month'] ?? date('m'))));
    $year = (int) ($query['year'] ?? ($mode === 'hijriyah' ? $currentHijriYear : $appTahunMasehiDefault));
    $anchorMasehiYear = (int) ($query['anchor_masehi_year'] ?? $appTahunMasehiDefault);

    if ($previousMode !== $mode) {
        if ($previousMode === 'masehi' && $mode === 'hijriyah') {
            $anchorMasehiYear = $year;
            $convertedHijriYm = get_hijri_ym_from_gregorian_month($year, $month);
            $year = (int) substr($convertedHijriYm, 0, 4);
            $month = max(1, min(12, (int) substr($convertedHijriYm, 5, 2)));
        } elseif ($previousMode === 'hijriyah' && $mode === 'masehi') {
            [$convertedStart] = akademik_gregorian_range_from_hijri_month($pdo, $year, $month);
            $year = (int) date('Y', strtotime($convertedStart));
            $month = (int) date('m', strtotime($convertedStart));
        }
    }

    $yearMin = $mode === 'hijriyah' ? 1300 : 1900;
    $yearMax = $mode === 'hijriyah' ? 1700 : 2100;
    if ($year <= 0) {
        $year = $mode === 'hijriyah' ? $currentHijriYear : $appTahunMasehiDefault;
    }
    $year = max($yearMin, min($yearMax, $year));

    if ($mode === 'masehi') {
        $anchorMasehiYear = $year;
    } else {
        $anchorMasehiYear = max(1900, min(2100, $anchorMasehiYear));
    }

    $resolved = rekap_resolve_periode($pdo, [
        'mode' => $mode,
        'month' => $month,
        'year' => $year,
    ]);
    $startDate = (string) $resolved['start_date'];
    $endDate = (string) $resolved['end_date'];
    $periodLabel = (string) $resolved['label'];
    if ($mode === 'masehi') {
        $periodBridge = $periodLabel . ' ↔ ' . (string) ($resolved['hijri_label'] ?? $periodLabel);
        $hijriYm = $resolved['kalender_hijriyah_key'] ?? null;
    } else {
        $mEqM = (int) date('m', strtotime($startDate));
        $mEqY = (int) date('Y', strtotime($startDate));
        $periodBridge = (($masehiMonths[$mEqM] ?? (string) $mEqM) . ' ' . $mEqY) . ' ↔ ' . $periodLabel;
        $hijriYm = sprintf('%04d-%02d', $year, $month);
    }

    $totalDays = max(1, (int) ((strtotime($endDate) - strtotime($startDate)) / 86400) + 1);

    return [
        'calendar_mode' => $mode,
        'month' => (int) $resolved['month'],
        'year' => (int) $resolved['year'],
        'start_date' => $startDate,
        'end_date' => $endDate,
        'total_days' => $totalDays,
        'period_label' => $periodLabel,
        'period_bridge' => $periodBridge,
        'rentang_tampilan' => (string) ($resolved['rentang_tampilan'] ?? ''),
        'hijri_ym' => $hijriYm ?? null,
        'anchor_masehi_year' => $anchorMasehiYear,
        'year_min' => $yearMin,
        'year_max' => $yearMax,
        'masehi_months' => $masehiMonths,
        'hijriyah_months' => $hijriyahMonths,
        'current_hijri_year' => $currentHijriYear,
        'current_masehi_year' => $currentMasehiYear,
    ];
}

/** Pastikan tabel pembayaran gaji pembimbing ada. */
function payroll_pembimbing_ensure_gaji_table(PDO $pdo): void
{
    payroll_pembimbing_ensure_schema($pdo);
    if (function_exists('table_exists') && table_exists($pdo, 'keuangan_gaji_pembimbing')) {
        if (!column_exists($pdo, 'keuangan_gaji_pembimbing', 'pengeluaran_id')) {
            try {
                $pdo->exec('ALTER TABLE keuangan_gaji_pembimbing ADD COLUMN pengeluaran_id INT NULL AFTER keterangan');
            } catch (Throwable $e) {
            }
        }
        if (!column_exists($pdo, 'keuangan_gaji_pembimbing', 'total_hak')) {
            try {
                $pdo->exec('ALTER TABLE keuangan_gaji_pembimbing ADD COLUMN total_hak DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_bayar');
            } catch (Throwable $e) {
            }
        }
        if (!column_exists($pdo, 'keuangan_gaji_pembimbing', 'sisa_dana_umum')) {
            try {
                $pdo->exec('ALTER TABLE keuangan_gaji_pembimbing ADD COLUMN sisa_dana_umum DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER total_hak');
            } catch (Throwable $e) {
            }
        }

        return;
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS keuangan_gaji_pembimbing (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pembimbing_id INT NOT NULL,
                periode_mode ENUM('MASEHI','HIJRIYAH') NOT NULL DEFAULT 'MASEHI',
                periode_label VARCHAR(30) NOT NULL,
                bulan TINYINT NOT NULL,
                tahun SMALLINT NOT NULL,
                total_jam DECIMAL(8,2) NOT NULL DEFAULT 0,
                tarif_per_jam DECIMAL(12,2) NOT NULL DEFAULT 0,
                total_bayar DECIMAL(12,2) NOT NULL DEFAULT 0,
                total_hak DECIMAL(12,2) NOT NULL DEFAULT 0,
                sisa_dana_umum DECIMAL(12,2) NOT NULL DEFAULT 0,
                tanggal_bayar DATE NOT NULL,
                keterangan VARCHAR(255) NULL,
                pengeluaran_id INT NULL,
                created_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_gaji_pembimbing_periode (pembimbing_id, periode_mode, tahun, bulan)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Throwable $e) {
        // abaikan
    }
}

/**
 * Status pembayaran gaji per pembimbing untuk periode.
 *
 * @return array<int, array{id:int,tanggal_bayar:string,total_bayar:float}>
 */
function payroll_pembimbing_paid_map(PDO $pdo, string $periodeMode, int $bulan, int $tahun): array
{
    payroll_pembimbing_ensure_gaji_table($pdo);
    if (!table_exists($pdo, 'keuangan_gaji_pembimbing')) {
        return [];
    }
    $mode = strtoupper($periodeMode) === 'HIJRIYAH' ? 'HIJRIYAH' : 'MASEHI';
    $st = $pdo->prepare('
        SELECT id, pembimbing_id, tanggal_bayar, total_bayar
        FROM keuangan_gaji_pembimbing
        WHERE periode_mode = :mode AND bulan = :bulan AND tahun = :tahun
    ');
    $st->execute(['mode' => $mode, 'bulan' => $bulan, 'tahun' => $tahun]);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pid = (int) ($r['pembimbing_id'] ?? 0);
        if ($pid > 0) {
            $map[$pid] = $r;
        }
    }

    return $map;
}

/** Label alokasi dana umum dari sisa jatah gaji pembimbing (bayar kurang dari hak). */
function payroll_pembimbing_dana_umum_sisa_label(): string
{
    return 'Dana Umum (Sisa Jatah Gaji Pembimbing)';
}

/** Total sisa jatah gaji yang dialihkan ke dana umum (sudah dibayar sebagian). */
function payroll_pembimbing_dana_umum_sisa_total(PDO $pdo): int
{
    payroll_pembimbing_ensure_gaji_table($pdo);
    if (!table_exists($pdo, 'keuangan_gaji_pembimbing') || !column_exists($pdo, 'keuangan_gaji_pembimbing', 'sisa_dana_umum')) {
        return 0;
    }
    $st = $pdo->query('SELECT COALESCE(SUM(sisa_dana_umum), 0) FROM keuangan_gaji_pembimbing WHERE sisa_dana_umum > 0');

    return (int) round((float) ($st->fetchColumn() ?: 0));
}

/** Pengeluaran yang memakai alokasi sisa jatah gaji pembimbing. */
function payroll_pembimbing_dana_umum_sisa_terpakai(PDO $pdo): int
{
    if (!table_exists($pdo, 'keuangan_pengeluaran')) {
        return 0;
    }
    $label = payroll_pembimbing_dana_umum_sisa_label();
    $st = $pdo->prepare('SELECT COALESCE(SUM(nominal), 0) FROM keuangan_pengeluaran WHERE alokasi_nama = :alokasi');
    $st->execute(['alokasi' => $label]);

    return (int) round((float) ($st->fetchColumn() ?: 0));
}

/** Sisa jatah gaji yang masih bisa dipakai untuk pengeluaran umum. */
function payroll_pembimbing_dana_umum_sisa_tersedia(PDO $pdo): int
{
    return max(0, payroll_pembimbing_dana_umum_sisa_total($pdo) - payroll_pembimbing_dana_umum_sisa_terpakai($pdo));
}

/**
 * Rincian scan presensi pembimbing per bulan (untuk payroll / audit).
 *
 * @return list<array<string, mixed>>
 */
function payroll_pembimbing_presensi_valid_rows(
    PDO $pdo,
    int $pembimbingId,
    string $startDate,
    string $endDate,
    int $kegiatanFilterId = 0,
    string $jamMode = 'jadwal'
): array {
    if ($pembimbingId <= 0 || !table_exists($pdo, 'presensi_pembimbing')) {
        return [];
    }

    ensure_kegiatan_payroll_kriteria_column($pdo);
    $tarifMap = payroll_pembimbing_tarif_map($pdo);
    $labels = payroll_pembimbing_kriteria_labels();
    $namaKegiatanExpr = payroll_pembimbing_presensi_has_kegiatan_id($pdo)
        ? 'COALESCE(k.nama_kegiatan, k_j.nama_kegiatan, "-")'
        : 'COALESCE(k_j.nama_kegiatan, "-")';

    $sql = '
        SELECT p.id, p.tanggal, p.jam, p.jenis_scan,
               ' . $namaKegiatanExpr . ' AS nama_kegiatan,
               ' . payroll_pembimbing_scan_kriteria_case_sql($pdo, 'p') . ' AS payroll_kriteria,
               j.id AS jadwal_id,
               COALESCE(j.jam_mulai, pj.jam_mulai) AS jadwal_mulai,
               COALESCE(j.jam_selesai, pj.jam_selesai) AS jadwal_selesai,
               ' . payroll_pembimbing_scan_jam_expr_sql($jamMode, 'p') . ' AS jam_hitung
        FROM presensi_pembimbing p
        INNER JOIN pembimbing pb ON pb.id = p.pembimbing_id
        ' . payroll_pembimbing_scan_jadwal_join_sql($pdo, 'p') . '
        ' . payroll_pembimbing_kegiatan_join_sql($pdo, 'p') . '
        WHERE p.pembimbing_id = :pid
          AND p.tanggal BETWEEN :start_date AND :end_date
          AND p.jenis_scan = "DATANG"
    ';
    $params = [
        'pid' => $pembimbingId,
        'start_date' => $startDate,
        'end_date' => $endDate,
    ];
    $sql .= payroll_pembimbing_kegiatan_filter_sql($pdo, $kegiatanFilterId, 'p');
    if ($kegiatanFilterId > 0 && payroll_pembimbing_presensi_has_kegiatan_id($pdo)) {
        $params['kegiatan_id'] = $kegiatanFilterId;
    }
    $sql .= ' ORDER BY p.tanggal ASC, p.jam ASC, p.id ASC';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $jadwalMulai = (string) ($row['jadwal_mulai'] ?? '');
        $jadwalSelesai = (string) ($row['jadwal_selesai'] ?? '');
        $valid = (int) ($row['jadwal_id'] ?? 0) > 0 || ($jadwalMulai !== '' && $jadwalSelesai !== '');
        $kriteria = payroll_pembimbing_normalize_kriteria((string) ($row['payroll_kriteria'] ?? ''));
        $jamHitung = round((float) ($row['jam_hitung'] ?? 0), 2);
        $out[] = [
            'id' => (int) ($row['id'] ?? 0),
            'tanggal' => (string) ($row['tanggal'] ?? ''),
            'jam' => (string) ($row['jam'] ?? ''),
            'nama_kegiatan' => (string) ($row['nama_kegiatan'] ?? '-'),
            'payroll_kriteria' => $kriteria,
            'payroll_kriteria_label' => $labels[$kriteria] ?? $kriteria,
            'jadwal_mulai' => $jadwalMulai,
            'jadwal_selesai' => $jadwalSelesai,
            'jam_hitung' => $jamHitung,
            'gaji_baris' => (int) round($jamHitung * (float) ($tarifMap[$kriteria] ?? 0)),
            'valid_jadwal' => $valid,
        ];
    }

    return $out;
}

/**
 * Semua baris presensi DATANG pembimbing dalam periode (untuk ekspor XLSX).
 *
 * @return list<array<string, mixed>>
 */
function payroll_pembimbing_presensi_export_rows(
    PDO $pdo,
    string $startDate,
    string $endDate,
    int $kegiatanFilterId = 0,
    string $jamMode = 'jadwal'
): array {
    if (!table_exists($pdo, 'presensi_pembimbing')) {
        return [];
    }
    ensure_kegiatan_payroll_kriteria_column($pdo);
    $tarifMap = payroll_pembimbing_tarif_map($pdo);
    $labels = payroll_pembimbing_kriteria_labels();
    $namaKegiatanExpr = payroll_pembimbing_presensi_has_kegiatan_id($pdo)
        ? 'COALESCE(k.nama_kegiatan, k_j.nama_kegiatan, "-")'
        : 'COALESCE(k_j.nama_kegiatan, "-")';

    $sql = '
        SELECT p.tanggal, p.jam, pb.nip, pb.nama_pembimbing,
               ' . $namaKegiatanExpr . ' AS nama_kegiatan,
               ' . payroll_pembimbing_scan_kriteria_case_sql($pdo, 'p') . ' AS payroll_kriteria,
               j.id AS jadwal_id,
               COALESCE(j.jam_mulai, pj.jam_mulai) AS jadwal_mulai,
               COALESCE(j.jam_selesai, pj.jam_selesai) AS jadwal_selesai,
               ' . payroll_pembimbing_scan_jam_expr_sql($jamMode, 'p') . ' AS jam_hitung
        FROM presensi_pembimbing p
        INNER JOIN pembimbing pb ON pb.id = p.pembimbing_id
        ' . payroll_pembimbing_scan_jadwal_join_sql($pdo, 'p') . '
        ' . payroll_pembimbing_kegiatan_join_sql($pdo, 'p') . '
        WHERE p.tanggal BETWEEN :start_date AND :end_date
          AND p.jenis_scan = "DATANG"
    ';
    $params = ['start_date' => $startDate, 'end_date' => $endDate];
    $sql .= payroll_pembimbing_kegiatan_filter_sql($pdo, $kegiatanFilterId, 'p');
    if ($kegiatanFilterId > 0 && payroll_pembimbing_presensi_has_kegiatan_id($pdo)) {
        $params['kegiatan_id'] = $kegiatanFilterId;
    }
    $sql .= ' ORDER BY p.tanggal ASC, pb.nama_pembimbing ASC, p.jam ASC, p.id ASC';

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $jadwalMulai = (string) ($row['jadwal_mulai'] ?? '');
        $jadwalSelesai = (string) ($row['jadwal_selesai'] ?? '');
        $valid = (int) ($row['jadwal_id'] ?? 0) > 0 || ($jadwalMulai !== '' && $jadwalSelesai !== '');
        $kriteria = payroll_pembimbing_normalize_kriteria((string) ($row['payroll_kriteria'] ?? ''));
        $jamHitung = round((float) ($row['jam_hitung'] ?? 0), 2);
        $jadwalLabel = ($jadwalMulai !== '' && $jadwalSelesai !== '')
            ? substr($jadwalMulai, 0, 5) . '–' . substr($jadwalSelesai, 0, 5)
            : '';
        $out[] = [
            'tanggal' => (string) ($row['tanggal'] ?? ''),
            'jam' => substr((string) ($row['jam'] ?? ''), 0, 5),
            'nip' => (string) ($row['nip'] ?? ''),
            'nama_pembimbing' => (string) ($row['nama_pembimbing'] ?? ''),
            'nama_kegiatan' => (string) ($row['nama_kegiatan'] ?? '-'),
            'payroll_kriteria_label' => $labels[$kriteria] ?? $kriteria,
            'jadwal' => $jadwalLabel,
            'jam_hitung' => $jamHitung,
            'gaji_baris' => (int) round($jamHitung * (float) ($tarifMap[$kriteria] ?? 0)),
            'valid_jadwal' => $valid ? 'Ya' : 'Tidak',
        ];
    }

    return $out;
}

/**
 * Baris spreadsheet rekap + detail presensi pembimbing per periode.
 *
 * @param list<array<string, mixed>> $rekapRows baris rekap dari rekap/pembimbing.php
 * @param list<array<string, mixed>> $detailRows output payroll_pembimbing_presensi_export_rows()
 * @return list<list<mixed>>
 */
function payroll_pembimbing_build_xlsx_rows(
    string $periodLabel,
    string $rentangTampilan,
    array $rekapRows,
    array $detailRows,
    string $jamMode = 'jadwal'
): array {
    $jamModeLabel = payroll_pembimbing_jam_mode_labels()[payroll_pembimbing_normalize_jam_mode($jamMode)] ?? 'Durasi jadwal';
    $rows = [
        ['Rekap Presensi & Payroll Pembimbing'],
        ['Periode', $periodLabel],
        ['Rentang', $rentangTampilan],
        ['Metode hitung jam', $jamModeLabel],
        [],
        ['NIP', 'Nama', 'Hadir', 'Izin', 'Sakit', 'Alpa', 'Telat', 'Jam Kegiatan', 'Kriteria Beban', 'Tarif efektif/jam', 'Gaji Pokok', 'Gaji Per Jam', 'Total Gaji', 'Status Bayar'],
    ];
    foreach ($rekapRows as $r) {
        $rows[] = [
            (string) ($r['nip'] ?? ''),
            (string) ($r['nama_pembimbing'] ?? ''),
            (int) ($r['total_datang'] ?? 0),
            (int) ($r['izin'] ?? 0),
            (int) ($r['sakit'] ?? 0),
            (int) ($r['alpa'] ?? 0),
            (int) ($r['telat'] ?? 0),
            round((float) ($r['total_jam'] ?? 0), 2),
            (string) ($r['kriteria_label'] ?? ''),
            (int) round((float) ($r['tarif_per_jam'] ?? 0)),
            (int) round((float) ($r['gaji_pokok_n'] ?? 0)),
            (int) round((float) ($r['gaji_per_jam'] ?? 0)),
            (int) ($r['gaji_bulanan'] ?? 0),
            (string) ($r['status_bayar'] ?? ''),
        ];
    }
    $rows[] = [];
    $rows[] = ['Detail Presensi DATANG'];
    $rows[] = ['Tanggal', 'Jam Scan', 'NIP', 'Nama', 'Kegiatan', 'Kriteria Beban', 'Jadwal', 'Jam Hitung', 'Gaji Baris', 'Valid Jadwal'];
    foreach ($detailRows as $d) {
        $rows[] = [
            (string) ($d['tanggal'] ?? ''),
            (string) ($d['jam'] ?? ''),
            (string) ($d['nip'] ?? ''),
            (string) ($d['nama_pembimbing'] ?? ''),
            (string) ($d['nama_kegiatan'] ?? ''),
            (string) ($d['payroll_kriteria_label'] ?? ''),
            (string) ($d['jadwal'] ?? ''),
            (float) ($d['jam_hitung'] ?? 0),
            (int) ($d['gaji_baris'] ?? 0),
            (string) ($d['valid_jadwal'] ?? ''),
        ];
    }

    return $rows;
}

/**
 * Catat pembayaran gaji pembimbing + pengeluaran kas (arus kas berkurang).
 *
 * @param array<string, mixed> $post
 * @return array{ok:bool,message:string}
 */
function payroll_pembimbing_bayar(PDO $pdo, array $post, int $userId): array
{
    require_once __DIR__ . '/keuangan_transaksi.php';
    require_once __DIR__ . '/keuangan_jurnal.php';

    payroll_pembimbing_ensure_gaji_table($pdo);
    ensure_keuangan_transaksi_tables($pdo);

    $pembimbingId = (int) ($post['pembimbing_id'] ?? 0);
    $calendarMode = strtolower(trim((string) ($post['cal'] ?? 'hijriyah')));
    $month = max(1, min(12, (int) ($post['month'] ?? 0)));
    $year = (int) ($post['year'] ?? 0);
    $tanggalBayar = trim((string) ($post['tanggal_bayar'] ?? date('Y-m-d')));
    $akunId = (int) ($post['akun_id'] ?? 0);
    $nominalInput = keuangan_money_input_to_int((string) ($post['nominal_bayar'] ?? '0'));

    if ($pembimbingId <= 0 || $month <= 0 || $year <= 0) {
        return ['ok' => false, 'message' => 'Data pembimbing atau periode tidak valid.'];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalBayar)) {
        $tanggalBayar = date('Y-m-d');
    }

    $pb = $pdo->prepare('SELECT id, nip, nama_pembimbing FROM pembimbing WHERE id = :id LIMIT 1');
    $pb->execute(['id' => $pembimbingId]);
    $pembimbing = $pb->fetch(PDO::FETCH_ASSOC);
    if (!$pembimbing) {
        return ['ok' => false, 'message' => 'Pembimbing tidak ditemukan.'];
    }

    $periodeMode = $calendarMode === 'hijriyah' ? 'HIJRIYAH' : 'MASEHI';
    $paidMap = payroll_pembimbing_paid_map($pdo, $periodeMode, $month, $year);
    if (isset($paidMap[$pembimbingId])) {
        return ['ok' => false, 'message' => 'Gaji periode ini sudah dibayar.'];
    }

    $jamMode = payroll_pembimbing_normalize_jam_mode((string) ($post['jam_mode'] ?? 'jadwal'));
    $period = payroll_pembimbing_resolve_period($pdo, [
        'cal' => $calendarMode,
        'month' => $month,
        'year' => $year,
    ]);
    $tarifMap = payroll_pembimbing_tarif_map($pdo);
    $startDate = (string) $period['start_date'];
    $endDate = (string) $period['end_date'];

    $jamKriteriaMap = payroll_pembimbing_jam_kriteria_map($pdo, $startDate, $endDate, 0, $jamMode);
    $byKriteria = $jamKriteriaMap[$pembimbingId]['by_kriteria'] ?? [];

    $metaStmt = $pdo->prepare('SELECT gaji_pokok, tarif_kriteria FROM pembimbing WHERE id = :id');
    $metaStmt->execute(['id' => $pembimbingId]);
    $meta = $metaStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $calc = payroll_pembimbing_compute_mixed(
        (float) ($meta['gaji_pokok'] ?? 0),
        $byKriteria,
        $tarifMap
    );
    $totalJam = (float) ($calc['total_jam'] ?? 0);
    $totalBayar = $nominalInput > 0 ? $nominalInput : (int) round($calc['total_gaji']);
    $totalHak = (int) round($calc['total_gaji']);
    $sisaDanaUmum = max(0, $totalHak - $totalBayar);
    if ($totalBayar <= 0) {
        return ['ok' => false, 'message' => 'Nominal gaji harus lebih dari 0.'];
    }

    if ($akunId <= 0) {
        foreach (keuangan_fetch_akun_aktif($pdo) as $ar) {
            if ((int) ($ar['is_default'] ?? 0) === 1) {
                $akunId = (int) ($ar['id'] ?? 0);
                break;
            }
        }
        if ($akunId <= 0) {
            $akunRows = keuangan_fetch_akun_aktif($pdo);
            $akunId = $akunRows !== [] ? (int) ($akunRows[0]['id'] ?? 0) : 0;
        }
    }
    if ($akunId <= 0) {
        return ['ok' => false, 'message' => 'Pilih akun kas/bank sumber pembayaran.'];
    }

    $periodLabel = (string) $period['period_label'];
    $namaPb = trim((string) ($pembimbing['nama_pembimbing'] ?? 'Pembimbing'));
    $nipPb = trim((string) ($pembimbing['nip'] ?? ''));
    $keterangan = 'Gaji ' . $namaPb . ($nipPb !== '' ? ' (NIP ' . $nipPb . ')' : '') . ' · ' . $periodLabel;
    $pos = 'Gaji Pembimbing';

    try {
        $pdo->beginTransaction();

        $cols = ['tanggal', 'penanggung_jawab', 'pos', 'alokasi_nama', 'nominal', 'keterangan', 'created_by'];
        $vals = [':tanggal', ':penanggung_jawab', ':pos', ':alokasi_nama', ':nominal', ':keterangan', ':created_by'];
        $params = [
            'tanggal' => $tanggalBayar,
            'penanggung_jawab' => trim((string) ($_SESSION['user']['nama'] ?? 'Admin')),
            'pos' => $pos,
            'alokasi_nama' => 'SDM Pembimbing',
            'nominal' => $totalBayar,
            'keterangan' => $keterangan,
            'created_by' => $userId > 0 ? $userId : null,
        ];
        if (column_exists($pdo, 'keuangan_pengeluaran', 'metode_keluar')) {
            $cols[] = 'metode_keluar';
            $vals[] = ':metode_keluar';
            $params['metode_keluar'] = 'KAS';
        }
        if (column_exists($pdo, 'keuangan_pengeluaran', 'akun_id')) {
            $cols[] = 'akun_id';
            $vals[] = ':akun_id';
            $params['akun_id'] = $akunId;
        }
        $sql = 'INSERT INTO keuangan_pengeluaran (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
        $pdo->prepare($sql)->execute($params);
        $pengeluaranId = (int) $pdo->lastInsertId();

        keuangan_transaksi_bootstrap_jurnal();
        keuangan_jurnal_pengeluaran($pdo, $pengeluaranId, $tanggalBayar, $akunId, $totalBayar, $pos, $userId);

        $gajiCols = [
            'pembimbing_id', 'periode_mode', 'periode_label', 'bulan', 'tahun',
            'total_jam', 'tarif_per_jam', 'total_bayar', 'tanggal_bayar', 'keterangan', 'created_by',
        ];
        $gajiVals = [
            ':pembimbing_id', ':periode_mode', ':periode_label', ':bulan', ':tahun',
            ':total_jam', ':tarif_per_jam', ':total_bayar', ':tanggal_bayar', ':keterangan', ':created_by',
        ];
        $gajiParams = [
            'pembimbing_id' => $pembimbingId,
            'periode_mode' => $periodeMode,
            'periode_label' => $periodLabel,
            'bulan' => $month,
            'tahun' => $year,
            'total_jam' => round($totalJam, 2),
            'tarif_per_jam' => round((float) $calc['tarif_per_jam'], 2),
            'total_bayar' => $totalBayar,
            'tanggal_bayar' => $tanggalBayar,
            'keterangan' => $keterangan,
            'created_by' => $userId > 0 ? $userId : null,
        ];
        if (column_exists($pdo, 'keuangan_gaji_pembimbing', 'pengeluaran_id')) {
            $gajiCols[] = 'pengeluaran_id';
            $gajiVals[] = ':pengeluaran_id';
            $gajiParams['pengeluaran_id'] = $pengeluaranId;
        }
        if (column_exists($pdo, 'keuangan_gaji_pembimbing', 'total_hak')) {
            $gajiCols[] = 'total_hak';
            $gajiVals[] = ':total_hak';
            $gajiParams['total_hak'] = $totalHak;
        }
        if (column_exists($pdo, 'keuangan_gaji_pembimbing', 'sisa_dana_umum')) {
            $gajiCols[] = 'sisa_dana_umum';
            $gajiVals[] = ':sisa_dana_umum';
            $gajiParams['sisa_dana_umum'] = $sisaDanaUmum;
        }
        $gajiSql = 'INSERT INTO keuangan_gaji_pembimbing (' . implode(', ', $gajiCols) . ') VALUES (' . implode(', ', $gajiVals) . ')';
        $pdo->prepare($gajiSql)->execute($gajiParams);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal mencatat pembayaran: ' . $e->getMessage()];
    }

    if (!function_exists('keuangan_dashboard_cache_invalidate')) {
        require_once __DIR__ . '/keuangan_dashboard.php';
    }
    keuangan_dashboard_cache_invalidate();

    $msg = 'Gaji ' . $namaPb . ' periode ' . $periodLabel . ' berhasil dibayar (' . keuangan_format_rupiah($totalBayar) . '). Arus kas telah dikurangi.';
    if ($sisaDanaUmum > 0) {
        $msg .= ' Sisa jatah ' . keuangan_format_rupiah($sisaDanaUmum) . ' tersedia untuk dana umum di Input Pengeluaran.';
    }

    return [
        'ok' => true,
        'message' => $msg,
        'sisa_dana_umum' => $sisaDanaUmum,
    ];
}
