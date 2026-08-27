<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/datetime_display.php';
require_once __DIR__ . '/penilaian_kehadiran.php';

/** Tanggal mulai scan resmi untuk rekap keaktivan (Y-m-d) atau kosong = semua riwayat. */
function rekap_keaktifan_tanggal_mulai_scan(PDO $pdo): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $raw = trim((string) app_setting($pdo, 'keaktifan_tanggal_mulai_scan', ''));
    $legacy = trim((string) app_setting($pdo, 'alpa_notif_tanggal_mulai', ''));
    // Migrasi sekali: tanggal lama khusus WA Alpa → setting tunggal keaktivan.
    if ($raw === '' && $legacy !== '') {
        $tsLegacy = strtotime($legacy);
        if ($tsLegacy !== false) {
            $raw = date('Y-m-d', $tsLegacy);
            save_setting($pdo, 'keaktifan_tanggal_mulai_scan', $raw);
        }
    }
    if ($legacy !== '') {
        save_setting($pdo, 'alpa_notif_tanggal_mulai', '');
    }
    if ($raw === '') {
        return $cached = '';
    }
    $ts = strtotime($raw);

    return $cached = ($ts !== false) ? date('Y-m-d', $ts) : '';
}

/**
 * Klausul AND: abaikan poin auto-presensi (ALPA/telat) sebelum tanggal mulai scan.
 * Data ledger lama tetap di DB; tidak ikut SUM / daftar hitungan.
 * Sumber lain (MANUAL, peraturan, dll.) tidak diubah.
 */
function rekap_poin_presensi_eligible_sql(PDO $pdo, string $alias = 'pl'): string
{
    $mulai = rekap_keaktifan_tanggal_mulai_scan($pdo);
    if ($mulai === '') {
        return '';
    }
    $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'pl';

    return " AND (
        {$a}.sumber_data NOT IN ('PRESENSI_ALPA_AUTO', 'PRESENSI_TELAT_AUTO')
        OR {$a}.tanggal >= '" . $mulai . "'
    )";
}

/**
 * Potong rentang rekap agar tidak memuat presensi sebelum tanggal mulai scan.
 *
 * @return array{0:string,1:string}|null null = periode seluruhnya sebelum mulai scan
 */
function rekap_keaktifan_clamp_periode(PDO $pdo, string $startDate, string $endDate): ?array
{
    $mulaiScan = rekap_keaktifan_tanggal_mulai_scan($pdo);
    if ($mulaiScan === '') {
        return [$startDate, $endDate];
    }
    if ($endDate < $mulaiScan) {
        return null;
    }
    if ($startDate < $mulaiScan) {
        $startDate = $mulaiScan;
    }
    if ($startDate > $endDate) {
        return null;
    }

    return [$startDate, $endDate];
}

/** Saran tanggal mulai scan dari presensi pertama di database. */
function rekap_keaktifan_suggest_tanggal_mulai_scan(PDO $pdo): string
{
    if (!table_exists($pdo, 'presensi')) {
        return '';
    }
    try {
        $min = $pdo->query('SELECT MIN(tanggal_presensi) FROM presensi')->fetchColumn();
    } catch (Throwable $e) {
        return '';
    }
    if (!is_string($min) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $min)) {
        return '';
    }

    return $min;
}

/**
 * @return array{aktif:bool,tanggal:string,label:string}
 */
function rekap_keaktifan_scan_start_meta(PDO $pdo): array
{
    $tanggal = rekap_keaktifan_tanggal_mulai_scan($pdo);

    return [
        'aktif' => $tanggal !== '',
        'tanggal' => $tanggal,
        'label' => $tanggal !== '' ? app_format_tanggal_id($tanggal) : '',
    ];
}

/** Catatan singkat sumber hitungan rekap keaktivan untuk UI portal/rekap. */
function rekap_keaktifan_rekap_footnote(PDO $pdo): string
{
    $meta = rekap_keaktifan_scan_start_meta($pdo);
    $parts = ['hanya jadwal kegiatan tingkatan yang terhitung'];
    if ($meta['aktif']) {
        $parts[] = 'presensi dihitung sejak ' . $meta['label'] . ' (tanggal mulai scan)';
    }

    return implode(' · ', $parts);
}

/**
 * @param list<array<string, mixed>> $rows presensi terfilter (eligible)
 * @return list<array<string, mixed>>
 */
function rekap_keaktifan_build_per_santri(array $rows, int $goodMax = 1, int $mediumMax = 3, int $lateTolerance = 15): array
{
    unset($goodMax, $mediumMax);
    $bySantri = [];
    foreach ($rows as $row) {
        $sid = (int) ($row['santri_id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        if (!isset($bySantri[$sid])) {
            $bySantri[$sid] = [
                'santri_id' => $sid,
                'nis' => (string) ($row['nis'] ?? ''),
                'nama_santri' => (string) ($row['nama_santri'] ?? ''),
                'tingkatan' => (string) ($row['tingkatan'] ?? ''),
                'hadir' => 0,
                'izin' => 0,
                'sakit' => 0,
                'alpa' => 0,
                'telat' => 0,
                'total' => 0,
                'per_kegiatan' => [],
            ];
        }
        $kgLabel = trim((string) ($row['nama_kegiatan'] ?? '')) !== '' ? (string) $row['nama_kegiatan'] : 'Tanpa Kegiatan';
        if (!isset($bySantri[$sid]['per_kegiatan'][$kgLabel])) {
            $bySantri[$sid]['per_kegiatan'][$kgLabel] = penilaian_kehadiran_counts_empty();
        }
        $bySantri[$sid]['total']++;
        $bySantri[$sid]['per_kegiatan'][$kgLabel]['total']++;
        $bucket = penilaian_kehadiran_row_bucket($row, $lateTolerance);
        if ($bucket !== '' && isset($bySantri[$sid][$bucket])) {
            $bySantri[$sid][$bucket]++;
            $bySantri[$sid]['per_kegiatan'][$kgLabel][$bucket]++;
        }
    }

    $ranked = [];
    foreach ($bySantri as $item) {
        $item = penilaian_kehadiran_apply_to_stats($item);
        foreach ($item['per_kegiatan'] as $kgName => $kgStats) {
            $item['per_kegiatan'][$kgName] = penilaian_kehadiran_apply_to_stats($kgStats);
        }
        ksort($item['per_kegiatan']);
        $ranked[] = $item;
    }

    usort($ranked, static function (array $a, array $b): int {
        $cmp = ((int) ($b['skor'] ?? 0)) <=> ((int) ($a['skor'] ?? 0));
        if ($cmp !== 0) {
            return $cmp;
        }
        $cmpAlpa = ((int) ($a['alpa'] ?? 0)) <=> ((int) ($b['alpa'] ?? 0));
        if ($cmpAlpa !== 0) {
            return $cmpAlpa;
        }

        return strcmp((string) $a['nama_santri'], (string) $b['nama_santri']);
    });

    return $ranked;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array<string, array{hadir:int,izin:int,sakit:int,alpa:int,telat:int,total:int,santri_count:int}>
 */
function rekap_keaktifan_build_per_kegiatan(array $rows, int $lateTolerance = 15): array
{
    $out = [];
    foreach ($rows as $row) {
        $kgLabel = trim((string) ($row['nama_kegiatan'] ?? '')) !== '' ? (string) $row['nama_kegiatan'] : 'Tanpa Kegiatan';
        if (!isset($out[$kgLabel])) {
            $out[$kgLabel] = [
                'hadir' => 0,
                'izin' => 0,
                'sakit' => 0,
                'alpa' => 0,
                'telat' => 0,
                'total' => 0,
                'santri_ids' => [],
            ];
        }
        $out[$kgLabel]['total']++;
        $sid = (int) ($row['santri_id'] ?? 0);
        if ($sid > 0) {
            $out[$kgLabel]['santri_ids'][$sid] = true;
        }
        $bucket = penilaian_kehadiran_row_bucket($row, $lateTolerance);
        if ($bucket !== '' && isset($out[$kgLabel][$bucket])) {
            $out[$kgLabel][$bucket]++;
        }
    }
    foreach ($out as $label => $data) {
        $out[$label]['santri_count'] = count($data['santri_ids'] ?? []);
        unset($out[$label]['santri_ids']);
    }
    ksort($out);

    return $out;
}

/**
 * Ambil baris presensi yang eligible (hanya jadwal tingkatan valid) — sumber resmi rekap keaktifan.
 *
 * @param list<int> $santriIds kosong = semua santri aktif dalam rentang
 * @return list<array<string, mixed>>
 */
function rekap_keaktifan_fetch_eligible_rows(
    PDO $pdo,
    string $startDate,
    string $endDate,
    array $santriIds = [],
    int $kegiatanId = 0,
    bool $runFinalize = true,
    ?string $kalenderHijriyahKey = null
): array {
    require_once __DIR__ . '/presensi_jadwal.php';
    require_once __DIR__ . '/santri_operasional.php';

    if (!table_exists($pdo, 'presensi') || !table_exists($pdo, 'santri')) {
        return [];
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        return [];
    }

    $clamped = rekap_keaktifan_clamp_periode($pdo, $startDate, $endDate);
    if ($clamped === null) {
        return [];
    }
    [$startDate, $endDate] = $clamped;

    if ($runFinalize) {
        $auditUserId = (int) ($_SESSION['user']['id'] ?? 1);
        presensi_finalize_date_range($pdo, $startDate, $endDate, $auditUserId > 0 ? $auditUserId : 1);
    }

    $sqlAktif = santri_sql_aktif_only('s');
    $params = [$startDate, $endDate];
    $where = 'p.tanggal_presensi BETWEEN ? AND ?';
    if ($kegiatanId > 0) {
        $where .= ' AND p.kegiatan_id = ?';
        $params[] = $kegiatanId;
    }
    if ($kalenderHijriyahKey !== null && $kalenderHijriyahKey !== '' && column_exists($pdo, 'presensi', 'kalender_hijriyah')) {
        $where .= ' AND p.kalender_hijriyah = ?';
        $params[] = $kalenderHijriyahKey;
    }

    $santriIds = array_values(array_unique(array_filter(array_map('intval', $santriIds), static fn (int $id): bool => $id > 0)));
    if ($santriIds !== []) {
        $ph = implode(',', array_fill(0, count($santriIds), '?'));
        $where .= ' AND s.id IN (' . $ph . ')';
        $params = array_merge($params, $santriIds);
    }

    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $selectExtra = column_exists($pdo, 'presensi', 'catatan') ? ', p.catatan' : ', "" AS catatan';
    $selectExtra .= column_exists($pdo, 'presensi', 'jam_presensi') ? ', p.jam_presensi' : ', NULL AS jam_presensi';
    $joinJadwal = '';
    if (column_exists($pdo, 'presensi', 'jadwal_kegiatan_id') && table_exists($pdo, 'jadwal_kegiatan')) {
        $selectExtra .= ', COALESCE(j.jam_mulai, "") AS jam_mulai_jadwal';
        $joinJadwal = ' LEFT JOIN jadwal_kegiatan j ON j.id = p.jadwal_kegiatan_id';
    } else {
        $selectExtra .= ', "" AS jam_mulai_jadwal';
    }
    $stmt = $pdo->prepare('
        SELECT
            p.id,
            p.tanggal_presensi,
            p.status_presensi,
            p.kegiatan_id,
            s.id AS santri_id,
            s.' . $nameCol . ' AS nama_santri,
            s.nis,
            s.tingkatan,
            COALESCE(k.nama_kegiatan, "Tanpa Kegiatan") AS nama_kegiatan,
            COALESCE(k.kategori_kegiatan, "TAALIM") AS kategori_kegiatan
            ' . $selectExtra . '
        FROM presensi p
        INNER JOIN santri s ON s.id = p.santri_id AND ' . $sqlAktif . '
        LEFT JOIN kegiatan k ON k.id = p.kegiatan_id
        ' . $joinJadwal . '
        WHERE ' . $where . '
        ORDER BY s.' . $nameCol . ' ASC, p.tanggal_presensi ASC
    ');
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $rows = presensi_filter_rows_eligible($pdo, $rows, $startDate, $endDate);
    require_once __DIR__ . '/keaktifan_alpa_tanpa_scan.php';
    $rows = keaktifan_exclude_alpa_slot_kosong($pdo, $rows);

    return penilaian_kehadiran_annotate_rows($rows, penilaian_kehadiran_batas_telat($pdo));
}

/**
 * @param list<array<string, mixed>> $rows baris eligible
 * @return array{hadir:int,izin:int,sakit:int,alpa:int,telat:int,total:int}
 */
function rekap_keaktifan_totals_from_rows(array $rows, int $lateTolerance = 15): array
{
    $totals = penilaian_kehadiran_counts_empty();
    foreach ($rows as $row) {
        $totals['total']++;
        $bucket = penilaian_kehadiran_row_bucket($row, $lateTolerance);
        if ($bucket !== '' && isset($totals[$bucket])) {
            $totals[$bucket]++;
        }
    }

    return $totals;
}

/**
 * Rekap per kegiatan dari baris eligible (format daftar untuk portal).
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array{kegiatan_id:int,nama_kegiatan:string,kategori_kegiatan:string,hadir:int,izin:int,sakit:int,alpa:int,telat:int,total:int}>
 */
function rekap_keaktifan_kegiatan_list_from_rows(array $rows, int $lateTolerance = 15): array
{
    /** @var array<string, array{kegiatan_id:int,nama_kegiatan:string,kategori_kegiatan:string,hadir:int,izin:int,sakit:int,alpa:int,telat:int,total:int}> $byKey */
    $byKey = [];
    foreach ($rows as $row) {
        $kid = (int) ($row['kegiatan_id'] ?? 0);
        $label = trim((string) ($row['nama_kegiatan'] ?? '')) !== '' ? (string) $row['nama_kegiatan'] : 'Lainnya / tanpa kegiatan';
        $kat = strtoupper(trim((string) ($row['kategori_kegiatan'] ?? 'TAALIM')));
        $key = $kid . '|' . $label;
        if (!isset($byKey[$key])) {
            $byKey[$key] = [
                'kegiatan_id' => $kid,
                'nama_kegiatan' => $label,
                'kategori_kegiatan' => $kat !== '' ? $kat : 'TAALIM',
                'hadir' => 0,
                'izin' => 0,
                'sakit' => 0,
                'alpa' => 0,
                'telat' => 0,
                'total' => 0,
            ];
        }
        $byKey[$key]['total']++;
        $bucket = penilaian_kehadiran_row_bucket($row, $lateTolerance);
        if ($bucket !== '' && isset($byKey[$key][$bucket])) {
            $byKey[$key][$bucket]++;
        }
    }

    $out = array_values($byKey);
    usort($out, static fn (array $a, array $b): int => strcmp((string) $a['nama_kegiatan'], (string) $b['nama_kegiatan']));

    return $out;
}

/**
 * @param list<array<string, mixed>> $ranked dari rekap_keaktifan_build_per_santri
 * @return array<string, array<string, mixed>>
 */
function rekap_keaktifan_build_per_tingkatan(array $ranked): array
{
    $kategoriKeys = penilaian_kehadiran_predikat_urutan();
    $out = [];
    foreach ($ranked as $row) {
        $tg = trim((string) ($row['tingkatan'] ?? '')) !== '' ? (string) $row['tingkatan'] : '-';
        if (!isset($out[$tg])) {
            $out[$tg] = [
                'hadir' => 0,
                'izin' => 0,
                'sakit' => 0,
                'alpa' => 0,
                'telat' => 0,
                'total' => 0,
                'santri_count' => 0,
                'sum_persen' => 0.0,
                'kategori' => array_fill_keys($kategoriKeys, 0),
                'santri_by_kategori' => array_fill_keys($kategoriKeys, []),
            ];
        }
        $out[$tg]['hadir'] += (int) $row['hadir'];
        $out[$tg]['izin'] += (int) $row['izin'];
        $out[$tg]['sakit'] += (int) $row['sakit'];
        $out[$tg]['alpa'] += (int) $row['alpa'];
        $out[$tg]['telat'] += (int) ($row['telat'] ?? 0);
        $out[$tg]['total'] += (int) $row['total'];
        $out[$tg]['santri_count']++;
        $out[$tg]['sum_persen'] += (float) ($row['persen_hadir'] ?? 0);

        $kat = (string) ($row['kategori'] ?? 'Buruk');
        if (!isset($out[$tg]['kategori'][$kat])) {
            $out[$tg]['kategori'][$kat] = 0;
            $out[$tg]['santri_by_kategori'][$kat] = [];
        }
        $out[$tg]['kategori'][$kat]++;
        $out[$tg]['santri_by_kategori'][$kat][] = [
            'santri_id' => (int) ($row['santri_id'] ?? 0),
            'nis' => (string) ($row['nis'] ?? ''),
            'nama_santri' => (string) ($row['nama_santri'] ?? ''),
            'hadir' => (int) $row['hadir'],
            'alpa' => (int) $row['alpa'],
            'izin' => (int) $row['izin'],
            'sakit' => (int) $row['sakit'],
            'telat' => (int) ($row['telat'] ?? 0),
            'total' => (int) $row['total'],
            'persen_hadir' => (float) ($row['persen_hadir'] ?? 0),
            'kategori' => $kat,
        ];
    }
    foreach ($out as $tg => $data) {
        $cnt = max(1, (int) $data['santri_count']);
        $out[$tg]['persen_hadir'] = round(((float) $data['sum_persen']) / $cnt, 1);
        unset($out[$tg]['sum_persen']);
        foreach ($kategoriKeys as $katKey) {
            usort($out[$tg]['santri_by_kategori'][$katKey], static function (array $a, array $b): int {
                return strcmp((string) $a['nama_santri'], (string) $b['nama_santri']);
            });
        }
    }
    ksort($out);

    return $out;
}

/**
 * @param array<string, array<string, mixed>> $byTingkatan dari rekap_keaktifan_build_per_tingkatan
 * @return list<array<string, mixed>>
 */
function rekap_keaktifan_rank_tingkatan_list(array $byTingkatan): array
{
    $list = [];
    foreach ($byTingkatan as $nama => $data) {
        $list[] = array_merge(['tingkatan' => $nama], $data);
    }
    usort($list, static function (array $a, array $b): int {
        $cmp = ($b['persen_hadir'] ?? 0) <=> ($a['persen_hadir'] ?? 0);
        if ($cmp !== 0) {
            return $cmp;
        }
        $cmpHadir = ((int) ($b['hadir'] ?? 0)) <=> ((int) ($a['hadir'] ?? 0));
        if ($cmpHadir !== 0) {
            return $cmpHadir;
        }

        return strcmp((string) ($a['tingkatan'] ?? ''), (string) ($b['tingkatan'] ?? ''));
    });
    foreach ($list as $i => &$row) {
        $row['rank'] = $i + 1;
    }
    unset($row);

    return $list;
}

/**
 * Siapkan presensi untuk rekap/ranking — hindari finalisasi ulang seluruh bulan.
 */
function rekap_keaktifan_prepare_periode_presensi(PDO $pdo, string $startDate, string $endDate): void
{
    require_once __DIR__ . '/presensi_jadwal.php';
    $today = date('Y-m-d');
    $auditUserId = (int) ($_SESSION['user']['id'] ?? 1);
    $finalizeEnd = $endDate > $today ? $today : $endDate;
    if ($startDate <= $finalizeEnd) {
        presensi_finalize_date_range($pdo, $startDate, $finalizeEnd, $auditUserId > 0 ? $auditUserId : 1);
    }
}

/**
 * Ranking tingkatan langsung dari baris eligible (tanpa per-kegiatan per santri).
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function rekap_keaktifan_rank_tingkatan_from_rows(array $rows, int $goodMax = 1, int $mediumMax = 3, bool $includeSantriList = true, int $lateTolerance = 15): array
{
    unset($goodMax, $mediumMax);
    $kategoriKeys = penilaian_kehadiran_predikat_urutan();
    $bySantri = [];
    foreach ($rows as $row) {
        $sid = (int) ($row['santri_id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        if (!isset($bySantri[$sid])) {
            $bySantri[$sid] = [
                'santri_id' => $sid,
                'nis' => (string) ($row['nis'] ?? ''),
                'nama_santri' => (string) ($row['nama_santri'] ?? ''),
                'tingkatan' => trim((string) ($row['tingkatan'] ?? '')) !== '' ? (string) $row['tingkatan'] : '-',
                'hadir' => 0,
                'izin' => 0,
                'sakit' => 0,
                'alpa' => 0,
                'telat' => 0,
                'total' => 0,
            ];
        }
        $bySantri[$sid]['total']++;
        $bucket = penilaian_kehadiran_row_bucket($row, $lateTolerance);
        if ($bucket !== '' && isset($bySantri[$sid][$bucket])) {
            $bySantri[$sid][$bucket]++;
        }
    }

    $byTingkatan = [];
    foreach ($bySantri as $item) {
        $item = penilaian_kehadiran_apply_to_stats($item);
        $tg = (string) ($item['tingkatan'] ?? '-');
        if (!isset($byTingkatan[$tg])) {
            $byTingkatan[$tg] = [
                'hadir' => 0,
                'izin' => 0,
                'sakit' => 0,
                'alpa' => 0,
                'telat' => 0,
                'total' => 0,
                'santri_count' => 0,
                'sum_persen' => 0.0,
                'kategori' => array_fill_keys($kategoriKeys, 0),
                'santri_list' => [],
            ];
        }
        $kat = (string) ($item['kategori'] ?? 'Buruk');
        if (!isset($byTingkatan[$tg]['kategori'][$kat])) {
            $byTingkatan[$tg]['kategori'][$kat] = 0;
        }
        $totalSantri = (int) $item['total'];
        $byTingkatan[$tg]['hadir'] += (int) $item['hadir'];
        $byTingkatan[$tg]['izin'] += (int) $item['izin'];
        $byTingkatan[$tg]['sakit'] += (int) $item['sakit'];
        $byTingkatan[$tg]['alpa'] += (int) $item['alpa'];
        $byTingkatan[$tg]['telat'] += (int) ($item['telat'] ?? 0);
        $byTingkatan[$tg]['total'] += $totalSantri;
        $byTingkatan[$tg]['santri_count']++;
        $byTingkatan[$tg]['sum_persen'] += (float) ($item['persen_hadir'] ?? 0);
        $byTingkatan[$tg]['kategori'][$kat]++;
        if ($includeSantriList) {
            $byTingkatan[$tg]['santri_list'][] = [
                'santri_id' => (int) ($item['santri_id'] ?? 0),
                'nis' => (string) ($item['nis'] ?? ''),
                'nama_santri' => (string) ($item['nama_santri'] ?? ''),
                'hadir' => (int) $item['hadir'],
                'izin' => (int) $item['izin'],
                'sakit' => (int) $item['sakit'],
                'alpa' => (int) $item['alpa'],
                'telat' => (int) ($item['telat'] ?? 0),
                'total' => $totalSantri,
                'persen_hadir' => (float) ($item['persen_hadir'] ?? 0),
                'kategori' => $kat,
            ];
        }
    }
    foreach ($byTingkatan as $tg => $data) {
        $cnt = max(1, (int) $data['santri_count']);
        $byTingkatan[$tg]['persen_hadir'] = round(((float) $data['sum_persen']) / $cnt, 1);
        unset($byTingkatan[$tg]['sum_persen']);
        if ($includeSantriList && !empty($byTingkatan[$tg]['santri_list'])) {
            usort($byTingkatan[$tg]['santri_list'], static function (array $a, array $b): int {
            $cmp = ($b['persen_hadir'] ?? 0) <=> ($a['persen_hadir'] ?? 0);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmpAlpa = ((int) ($a['alpa'] ?? 0)) <=> ((int) ($b['alpa'] ?? 0));
            if ($cmpAlpa !== 0) {
                return $cmpAlpa;
            }

            return strcmp((string) ($a['nama_santri'] ?? ''), (string) ($b['nama_santri'] ?? ''));
        });
            foreach ($byTingkatan[$tg]['santri_list'] as $i => &$santriRow) {
                $santriRow['rank'] = $i + 1;
            }
            unset($santriRow);
        } elseif (!$includeSantriList) {
            unset($byTingkatan[$tg]['santri_list']);
        }
    }

    return rekap_keaktifan_rank_tingkatan_list($byTingkatan);
}

/**
 * @param list<array<string, mixed>> $rows
 */
function rekap_keaktifan_filter_rows_by_kategori(array $rows, ?string $kategoriKegiatan): array
{
    if ($kategoriKegiatan === null || $kategoriKegiatan === '') {
        return $rows;
    }
    $kat = strtoupper(trim($kategoriKegiatan));

    return array_values(array_filter($rows, static function (array $row) use ($kat): bool {
        $rowKat = strtoupper(trim((string) ($row['kategori_kegiatan'] ?? 'TAALIM')));

        return $rowKat === $kat;
    }));
}

/**
 * Data grafik ranking tingkatan (urutan: terbaik di atas).
 *
 * @param list<array<string, mixed>> $ranking
 * @return array{
 *   labels:list<string>,
 *   persen_hadir:list<float>,
 *   bar_colors:list<string>,
 *   stacked_datasets:list<array<string,mixed>>
 * }
 */
function rekap_keaktifan_rank_tingkatan_chart_payload(array $ranking): array
{
    $labels = [];
    $persen = [];
    $colors = [];
    $stackedColors = [
        'Baik' => 'rgba(25, 135, 84, 0.85)',
        'Cukup' => 'rgba(13, 202, 240, 0.85)',
        'Sedang' => 'rgba(255, 193, 7, 0.9)',
        'Kurang' => 'rgba(253, 126, 20, 0.9)',
        'Buruk' => 'rgba(220, 53, 69, 0.85)',
    ];
    $stacked = [];
    foreach (penilaian_kehadiran_predikat_urutan() as $katLabel) {
        $stacked[] = [
            'label' => $katLabel,
            'data' => [],
            'backgroundColor' => $stackedColors[$katLabel] ?? 'rgba(148, 163, 184, 0.85)',
            'stack' => 'kat',
        ];
    }
    $max = max(1, count($ranking));
    foreach ($ranking as $i => $row) {
        $labels[] = (string) ($row['tingkatan'] ?? '-');
        $p = (float) ($row['persen_hadir'] ?? 0);
        $persen[] = $p;
        $ratio = $max > 1 ? $i / ($max - 1) : 0.0;
        $colors[] = sprintf('rgba(%d, %d, %d, 0.88)', (int) round(34 + 180 * $ratio), (int) round(160 - 90 * $ratio), (int) round(80 - 40 * $ratio));

        $kat = (array) ($row['kategori'] ?? []);
        $cnt = max(1, (int) ($row['santri_count'] ?? 0));
        foreach ($stacked as $si => $stackRow) {
            $stacked[$si]['data'][] = round(((int) ($kat[$stackRow['label']] ?? 0) / $cnt) * 100, 1);
        }
    }

    return [
        'labels' => $labels,
        'persen_hadir' => $persen,
        'bar_colors' => $colors,
        'stacked_datasets' => $stacked,
    ];
}

/**
 * Ranking keaktifan per tingkatan — jalur cepat (cache sesi + tanpa finalisasi bulan penuh).
 *
 * @return list<array<string, mixed>>
 */
function rekap_keaktifan_rank_tingkatan_for_periode(
    PDO $pdo,
    string $startDate,
    string $endDate,
    int $goodMax,
    int $mediumMax,
    bool $forceRefresh = false,
    ?string $kategoriKegiatan = null,
    ?string $kalenderHijriyahKey = null,
    bool $summaryOnly = false
): array {
    require_once __DIR__ . '/rekap_keaktifan_hari.php';
    require_once __DIR__ . '/keaktifan_alpa_tanpa_scan.php';
    $alpaTanpaScanOn = keaktifan_alpa_jika_tanpa_scan_enabled($pdo);
    require_once __DIR__ . '/penilaian_kehadiran.php';
    $telatHadir = penilaian_kehadiran_telat_dihitung_hadir($pdo);
    $cacheKey = rekap_keaktifan_rank_tingkatan_cache_key($startDate, $endDate, $goodMax, $mediumMax, $kategoriKegiatan, $kalenderHijriyahKey, $alpaTanpaScanOn, $telatHadir)
        . ($summaryOnly ? '_sum' : '_full');
    $cacheTsKey = $cacheKey . '_ts';
    $ttl = 600;
    if (
        !$forceRefresh
        && isset($_SESSION[$cacheKey], $_SESSION[$cacheTsKey])
        && is_array($_SESSION[$cacheKey])
        && (time() - (int) $_SESSION[$cacheTsKey]) < $ttl
    ) {
        return $_SESSION[$cacheKey];
    }

    rekap_keaktifan_prepare_periode_presensi($pdo, $startDate, $endDate);
    $rows = rekap_keaktifan_fetch_eligible_rows($pdo, $startDate, $endDate, [], 0, false, $kalenderHijriyahKey);
    $katNorm = rekap_keaktifan_hari_normalize_kategori($kategoriKegiatan);
    $rows = rekap_keaktifan_filter_rows_by_kategori($rows, $katNorm);
    $ranking = rekap_keaktifan_rank_tingkatan_from_rows($rows, $goodMax, $mediumMax, !$summaryOnly, penilaian_kehadiran_batas_telat($pdo));

    $_SESSION[$cacheKey] = $ranking;
    $_SESSION[$cacheTsKey] = time();

    return $ranking;
}

/** Kunci cache ranking tingkatan (selaras dengan rekap_keaktifan_rank_tingkatan_for_periode). */
function rekap_keaktifan_rank_tingkatan_cache_key(
    string $startDate,
    string $endDate,
    int $goodMax,
    int $mediumMax,
    ?string $kategoriKegiatan,
    ?string $kalenderHijriyahKey,
    bool $alpaTanpaScanOn = true,
    bool $telatDihitungHadir = false
): string {
    require_once __DIR__ . '/rekap_keaktifan_hari.php';
    $katNorm = rekap_keaktifan_hari_normalize_kategori($kategoriKegiatan);

    return 'rekap_rank_tingkatan_v9_' . md5($startDate . '|' . $endDate . '|' . $goodMax . '|' . $mediumMax . '|' . ($katNorm ?? '') . '|' . ($kalenderHijriyahKey ?? '') . '|' . ($alpaTanpaScanOn ? '1' : '0') . '|' . ($telatDihitungHadir ? '1' : '0'));
}

/** Hapus cache ranking keaktifan di sesi (setelah saklar ALPA tanpa scan berubah). */
function rekap_keaktifan_rank_tingkatan_cache_invalidate(): void
{
    foreach (array_keys($_SESSION ?? []) as $key) {
        if (!is_string($key)) {
            continue;
        }
        if (str_starts_with($key, 'rekap_rank_tingkatan_')) {
            unset($_SESSION[$key]);
        }
    }
}

/**
 * Ringkasan tingkatan tanpa daftar santri (untuk render awal halaman cepat).
 *
 * @param list<array<string, mixed>> $ranking
 * @return list<array<string, mixed>>
 */
function rekap_keaktifan_rank_tingkatan_summaries(array $ranking): array
{
    $out = [];
    foreach ($ranking as $row) {
        $summary = $row;
        unset($summary['santri_list']);
        $out[] = $summary;
    }

    return $out;
}

/**
 * Daftar santri satu tingkatan dari cache ranking (tanpa render ulang seluruh halaman).
 *
 * @return list<array<string, mixed>>
 */
function rekap_keaktifan_rank_tingkatan_santri_list(
    PDO $pdo,
    string $startDate,
    string $endDate,
    string $tingkatan,
    int $goodMax,
    int $mediumMax,
    ?string $kategoriKegiatan = null,
    ?string $kalenderHijriyahKey = null,
    bool $forceRefresh = false
): array {
    require_once __DIR__ . '/rekap_keaktifan_hari.php';
    $katNorm = rekap_keaktifan_hari_normalize_kategori($kategoriKegiatan);
    $needle = trim($tingkatan);
    $cacheKey = 'rekap_rank_tg_santri_v3_' . md5($startDate . '|' . $endDate . '|' . $goodMax . '|' . $mediumMax . '|' . ($katNorm ?? '') . '|' . ($kalenderHijriyahKey ?? '') . '|' . strtolower($needle));
    $cacheTsKey = $cacheKey . '_ts';
    $ttl = 120;
    if (
        !$forceRefresh
        && isset($_SESSION[$cacheKey], $_SESSION[$cacheTsKey])
        && is_array($_SESSION[$cacheKey])
        && (time() - (int) $_SESSION[$cacheTsKey]) < $ttl
    ) {
        return $_SESSION[$cacheKey];
    }

    rekap_keaktifan_prepare_periode_presensi($pdo, $startDate, $endDate);
    $rows = rekap_keaktifan_fetch_eligible_rows($pdo, $startDate, $endDate, [], 0, false, $kalenderHijriyahKey);
    $rows = rekap_keaktifan_filter_rows_by_kategori($rows, $katNorm);
    $rows = array_values(array_filter($rows, static function (array $row) use ($needle): bool {
        return strcasecmp((string) ($row['tingkatan'] ?? ''), $needle) === 0;
    }));
    $ranking = rekap_keaktifan_rank_tingkatan_from_rows($rows, $goodMax, $mediumMax, true, penilaian_kehadiran_batas_telat($pdo));
    $santriList = [];
    foreach ($ranking as $row) {
        if (strcasecmp((string) ($row['tingkatan'] ?? ''), $needle) === 0) {
            $santriList = is_array($row['santri_list'] ?? null) ? $row['santri_list'] : [];
            break;
        }
    }

    $_SESSION[$cacheKey] = $santriList;
    $_SESSION[$cacheTsKey] = time();

    return $santriList;
}

/**
 * Data per tingkatan (termasuk santri per kategori) — cache sesi, tanpa finalisasi bulan penuh.
 *
 * @return array<string, array<string, mixed>>
 */
function rekap_keaktifan_by_tingkatan_for_periode(
    PDO $pdo,
    string $startDate,
    string $endDate,
    int $goodMax,
    int $mediumMax,
    int $kegiatanId = 0,
    ?string $kalenderHijriyahKey = null,
    bool $forceRefresh = false
): array {
    $cacheKey = 'rekap_by_tingkatan_v3_' . md5($startDate . '|' . $endDate . '|' . $goodMax . '|' . $mediumMax . '|' . $kegiatanId . '|' . ($kalenderHijriyahKey ?? ''));
    $cacheTsKey = $cacheKey . '_ts';
    $ttl = 120;
    if (
        !$forceRefresh
        && isset($_SESSION[$cacheKey], $_SESSION[$cacheTsKey])
        && is_array($_SESSION[$cacheKey])
        && (time() - (int) $_SESSION[$cacheTsKey]) < $ttl
    ) {
        return $_SESSION[$cacheKey];
    }

    rekap_keaktifan_prepare_periode_presensi($pdo, $startDate, $endDate);
    $rows = rekap_keaktifan_fetch_eligible_rows($pdo, $startDate, $endDate, [], $kegiatanId, false, $kalenderHijriyahKey);
    $ranked = rekap_keaktifan_build_per_santri($rows, $goodMax, $mediumMax);
    $byTingkatan = rekap_keaktifan_build_per_tingkatan($ranked);

    $_SESSION[$cacheKey] = $byTingkatan;
    $_SESSION[$cacheTsKey] = time();

    return $byTingkatan;
}

function rekap_keaktifan_kategori_badge_class(string $kategori): string
{
    return penilaian_kehadiran_badge_tone($kategori);
}

/** @return list<string> */
function rekap_keaktifan_kategori_urutan(): array
{
    return penilaian_kehadiran_predikat_urutan();
}

/** Kategori perbandingan antar tingkatan (selain Baik). */
function rekap_keaktifan_kategori_perbandingan(): array
{
    return ['Cukup', 'Sedang', 'Kurang', 'Buruk'];
}

/**
 * Persentase santri per kategori keaktifan dalam satu tingkatan.
 *
 * @param array<string, array<string, mixed>> $byTingkatan
 * @return array<string, array{santri_count:int,kategori:array<string,int>,persen:array<string,float>}>
 */
function rekap_keaktifan_kategori_persen_per_tingkatan(array $byTingkatan): array
{
    $out = [];
    foreach ($byTingkatan as $tg => $data) {
        $total = max(0, (int) ($data['santri_count'] ?? 0));
        $persen = [];
        foreach (rekap_keaktifan_kategori_urutan() as $katKey) {
            $count = (int) ($data['kategori'][$katKey] ?? 0);
            $persen[$katKey] = $total > 0 ? round(($count / $total) * 100, 1) : 0.0;
        }
        $out[(string) $tg] = [
            'santri_count' => $total,
            'kategori' => (array) ($data['kategori'] ?? []),
            'persen' => $persen,
        ];
    }
    ksort($out);

    return $out;
}

/**
 * Data siap pakai untuk grafik perbandingan kategori per tingkatan.
 *
 * @param array<string, array{santri_count:int,kategori:array<string,int>,persen:array<string,float>}> $persenPerTingkatan
 * @return array{
 *   labels:list<string>,
 *   datasets:list<array{label:string,data:list<float>,backgroundColor:string}>,
 *   stacked_datasets:list<array{label:string,data:list<float>,backgroundColor:string}>
 * }
 */
function rekap_keaktifan_chart_tingkatan_kategori(array $persenPerTingkatan): array
{
    $labels = array_keys($persenPerTingkatan);
    $colors = [
        'Baik' => '#16a34a',
        'Cukup' => '#0ea5e9',
        'Sedang' => '#f59e0b',
        'Kurang' => '#fd7e14',
        'Buruk' => '#ef4444',
    ];
    $grouped = [];
    foreach (rekap_keaktifan_kategori_perbandingan() as $katKey) {
        $grouped[] = [
            'label' => $katKey,
            'data' => array_map(
                static fn(array $row): float => (float) ($row['persen'][$katKey] ?? 0),
                array_values($persenPerTingkatan)
            ),
            'backgroundColor' => $colors[$katKey] ?? '#94a3b8',
        ];
    }
    $stacked = [];
    foreach (rekap_keaktifan_kategori_urutan() as $katKey) {
        $stacked[] = [
            'label' => $katKey,
            'data' => array_map(
                static fn(array $row): float => (float) ($row['persen'][$katKey] ?? 0),
                array_values($persenPerTingkatan)
            ),
            'backgroundColor' => $colors[$katKey] ?? '#94a3b8',
        ];
    }

    return [
        'labels' => $labels,
        'datasets' => $grouped,
        'stacked_datasets' => $stacked,
    ];
}

/**
 * @return array<int, list<array{tingkatan:string,hari_ke:int,jam_mulai:string,jam_selesai:string}>>
 */
function rekap_keaktifan_jadwal_rows_by_kegiatan(PDO $pdo): array
{
    if (!table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan')) {
        return [];
    }
    $rows = $pdo->query('
        SELECT j.kegiatan_id, j.tingkatan, j.hari_ke, j.jam_mulai, j.jam_selesai
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id AND k.is_active = 1
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $byKid = [];
    foreach ($rows as $row) {
        $kid = (int) ($row['kegiatan_id'] ?? 0);
        if ($kid <= 0) {
            continue;
        }
        $byKid[$kid][] = [
            'tingkatan' => trim((string) ($row['tingkatan'] ?? '')),
            'hari_ke' => (int) ($row['hari_ke'] ?? 0),
            'jam_mulai' => substr((string) ($row['jam_mulai'] ?? ''), 0, 8),
            'jam_selesai' => substr((string) ($row['jam_selesai'] ?? ''), 0, 8),
        ];
    }

    return $byKid;
}

/**
 * Satu baris detail untuk slot kegiatan (tanggal + tingkatan jadwal).
 *
 * @return array{tanggal:string,tanggal_tampil:string,tanggal_hijri:string,hari:string,jam:string,jam_selesai:string,tingkatan:string}|null
 */
function rekap_keaktifan_kegiatan_tanpa_scan_slot_meta(
    PDO $pdo,
    int $kegiatanId,
    string $tanggal,
    string $tkKey,
    array $jadwalByKid
): ?array {
    $hariMap = [
        1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis',
        5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu',
    ];
    if ($kegiatanId <= 0 || $tanggal === '') {
        return null;
    }
    $hariKe = (int) date('N', strtotime($tanggal) ?: time());
    $hariLabel = $hariMap[$hariKe] ?? '-';
    $jadwalList = $jadwalByKid[$kegiatanId] ?? [];
    $jamMulai = '';
    $jamSelesai = '';
    $tgLabel = $tkKey === '*' ? 'Semua tingkatan' : $tkKey;

    foreach ($jadwalList as $j) {
        $jHari = (int) ($j['hari_ke'] ?? 0);
        if ($jHari !== 0 && $jHari !== $hariKe) {
            continue;
        }
        $jTg = trim((string) ($j['tingkatan'] ?? ''));
        if ($jTg === '') {
            continue;
        }
        if ($tkKey !== '*') {
            $cocok = strcasecmp($jTg, 'Semua Tingkatan') === 0
                || strtolower($jTg) === strtolower($tkKey);
            if (!$cocok) {
                continue;
            }
        }
        $jm = substr((string) ($j['jam_mulai'] ?? ''), 0, 5);
        $js = substr((string) ($j['jam_selesai'] ?? ''), 0, 8);
        if ($jm === '' || $js === '') {
            continue;
        }
        $jamMulai = $jm;
        $jamSelesai = $js;
        if ($tkKey === '*' && strcasecmp($jTg, 'Semua Tingkatan') === 0) {
            $tgLabel = 'Semua tingkatan';
        } elseif ($tkKey !== '*') {
            $tgLabel = strcasecmp($jTg, 'Semua Tingkatan') === 0 ? $tkKey : $jTg;
        }
        break;
    }

    if ($jamSelesai === '') {
        return null;
    }

    $hijriLabel = $tanggal;
    if (function_exists('konversiKeHijriah')) {
        require_once __DIR__ . '/hijri_kalender.php';
        $hijri = konversiKeHijriah($pdo, $tanggal);
        if (is_array($hijri)) {
            $hijriLabel = sprintf(
                '%02d/%s/%d',
                (int) ($hijri['tanggal_hijriyah'] ?? 0),
                hijri_indeks_ke_nama((int) ($hijri['bulan_hijriyah'] ?? 1)),
                (int) ($hijri['tahun_hijriah'] ?? 0)
            );
        }
    }

    return [
        'tanggal' => $tanggal,
        'tanggal_tampil' => date('d/m/Y', strtotime($tanggal) ?: time()),
        'tanggal_hijri' => $hijriLabel,
        'hari' => $hariLabel,
        'jam_mulai' => $jamMulai,
        'jam' => $jamMulai !== '' ? ($jamMulai . ' – ' . substr($jamSelesai, 0, 5)) : substr($jamSelesai, 0, 5),
        'jam_selesai' => $jamSelesai,
        'tingkatan' => $tgLabel,
    ];
}

/**
 * Setiap slot waktu kegiatan (tanggal + jam) tanpa satupun scan HADIR = 1 hitungan.
 * Tidak dikalikan jumlah tingkatan atau santri — 1 waktu tanpa scan = 1.
 * Periode mengikuti rentang masehi/hijriyah dari rekap_resolve_periode().
 *
 * @return list<array{
 *   kegiatan_id:int,
 *   nama_kegiatan:string,
 *   tanggal:string,
 *   tanggal_tampil:string,
 *   tanggal_hijri:string,
 *   hari:string,
 *   jam:string,
 *   hari_terjadwal:int,
 *   slot_jadwal:int,
 *   jumlah_tidak_scan:int,
 *   tingkatan:list<string>,
 *   tingkatan_label:string,
 *   detail:list<array{tanggal:string,tanggal_tampil:string,hari:string,jam:string,tingkatan:string}>
 * }>
 */
function rekap_keaktifan_kegiatan_tanpa_scan_bulan(
    PDO $pdo,
    string $startDate,
    string $endDate,
    ?string $tingkatanFilter = null,
    int $kegiatanFilterId = 0
): array {
    require_once __DIR__ . '/presensi_jadwal.php';
    require_once __DIR__ . '/santri_operasional.php';
    require_once __DIR__ . '/keaktifan_alpa_tanpa_scan.php';

    if (!table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan') || !table_exists($pdo, 'presensi')) {
        return [];
    }

    $clamped = rekap_keaktifan_clamp_periode($pdo, $startDate, $endDate);
    if ($clamped === null) {
        return [];
    }
    [$startDate, $endDate] = $clamped;

    $eligibilitySet = presensi_jadwal_eligibility_set($pdo, $startDate, $endDate);
    if ($eligibilitySet === []) {
        return [];
    }

    $tkFilter = trim((string) ($tingkatanFilter ?? ''));
    $tkLower = strtolower($tkFilter);
    $jadwalByKid = rekap_keaktifan_jadwal_rows_by_kegiatan($pdo);

    $sqlAktif = santri_sql_aktif_only('s');
    $stmt = $pdo->prepare('
        SELECT p.kegiatan_id, p.tanggal_presensi, s.tingkatan
        FROM presensi p
        INNER JOIN santri s ON s.id = p.santri_id AND ' . $sqlAktif . '
        WHERE p.tanggal_presensi BETWEEN ? AND ?
          AND p.status_presensi = "HADIR"
    ');
    $stmt->execute([$startDate, $endDate]);

    /** @var array<string, true> $hadirPerSlot */
    $hadirPerSlot = [];
    /** @var array<string, true> $hadirKegiatanTanggal */
    $hadirKegiatanTanggal = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!presensi_row_eligible_for_hitung($pdo, $row, $eligibilitySet)) {
            continue;
        }
        $kid = (int) ($row['kegiatan_id'] ?? 0);
        $tanggal = (string) ($row['tanggal_presensi'] ?? '');
        $tk = strtolower(trim((string) ($row['tingkatan'] ?? '')));
        if ($kid <= 0 || $tanggal === '' || $tk === '') {
            continue;
        }
        $hadirPerSlot[$kid . '|' . $tanggal . '|' . $tk] = true;
        $hadirKegiatanTanggal[$kid . '|' . $tanggal] = true;
    }

    $kidSet = [];
    foreach (array_keys($eligibilitySet) as $key) {
        $parts = explode('|', (string) $key, 3);
        if (count($parts) < 3) {
            continue;
        }
        $kid = (int) $parts[0];
        if ($kid > 0) {
            $kidSet[$kid] = true;
        }
    }
    $kids = array_keys($kidSet);
    /** @var array<int, string> $namaMap */
    $namaMap = [];
    /** @var array<int, string> $katMap */
    $katMap = [];
    if ($kids !== []) {
        $placeholders = implode(',', array_fill(0, count($kids), '?'));
        $nameStmt = $pdo->prepare('
            SELECT id, nama_kegiatan, COALESCE(kategori_kegiatan, "TAALIM") AS kategori_kegiatan
            FROM kegiatan WHERE id IN (' . $placeholders . ')
        ');
        $nameStmt->execute($kids);
        foreach ($nameStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $nameRow) {
            $id = (int) ($nameRow['id'] ?? 0);
            $namaMap[$id] = (string) ($nameRow['nama_kegiatan'] ?? '');
            $katMap[$id] = (string) ($nameRow['kategori_kegiatan'] ?? 'TAALIM');
        }
    }

    $out = [];
    /** @var array<string, array{kegiatan_id:int,nama_kegiatan:string,tanggal:string,tanggal_tampil:string,tanggal_hijri:string,hari:string,jam:string,jam_mulai:string,jam_selesai:string,tingkatan_labels:list<string>,detail:list<array<string,mixed>>}> $byWaktu */
    $byWaktu = [];

    foreach (array_keys($eligibilitySet) as $key) {
        $parts = explode('|', (string) $key, 3);
        if (count($parts) < 3) {
            continue;
        }
        $kid = (int) $parts[0];
        $tanggal = (string) $parts[1];
        $tk = (string) $parts[2];
        if ($kid <= 0 || $tanggal === '') {
            continue;
        }
        if ($kegiatanFilterId > 0 && $kid !== $kegiatanFilterId) {
            continue;
        }
        if ($tkFilter !== '' && $tk !== '*' && strtolower($tk) !== $tkLower) {
            continue;
        }
        if (!keaktifan_tanpa_scan_kategori_dihitung((string) ($katMap[$kid] ?? 'TAALIM'))) {
            continue;
        }

        $slotMeta = rekap_keaktifan_kegiatan_tanpa_scan_slot_meta($pdo, $kid, $tanggal, $tk, $jadwalByKid);
        if ($slotMeta === null) {
            continue;
        }
        if (!presensi_jam_selesai_lewat($tanggal, (string) ($slotMeta['jam_selesai'] ?? ''))) {
            continue;
        }

        $jamMulaiKey = substr((string) ($slotMeta['jam_mulai'] ?? ''), 0, 5);
        $jamSelesaiKey = substr((string) ($slotMeta['jam_selesai'] ?? ''), 0, 8);
        $waktuKey = $kid . '|' . $tanggal . '|' . $jamMulaiKey . '|' . $jamSelesaiKey;

        if (!isset($byWaktu[$waktuKey])) {
            $byWaktu[$waktuKey] = [
                'kegiatan_id' => $kid,
                'nama_kegiatan' => trim((string) ($namaMap[$kid] ?? '')) !== '' ? (string) $namaMap[$kid] : ('Kegiatan #' . $kid),
                'tanggal' => $tanggal,
                'tanggal_tampil' => (string) $slotMeta['tanggal_tampil'],
                'tanggal_hijri' => (string) ($slotMeta['tanggal_hijri'] ?? ''),
                'hari' => (string) $slotMeta['hari'],
                'jam' => (string) $slotMeta['jam'],
                'jam_mulai' => $jamMulaiKey,
                'jam_selesai' => $jamSelesaiKey,
                'tingkatan_labels' => [],
                'tingkatan_keys' => [],
            ];
        }

        $tingkatanLabel = (string) ($slotMeta['tingkatan'] ?? ($tk === '*' ? 'Semua tingkatan' : $tk));
        $tkNorm = strtolower($tk);
        if ($tkNorm !== '' && !in_array($tkNorm, $byWaktu[$waktuKey]['tingkatan_keys'], true)) {
            $byWaktu[$waktuKey]['tingkatan_keys'][] = $tkNorm;
            $byWaktu[$waktuKey]['tingkatan_labels'][] = $tingkatanLabel;
        }
    }

    foreach ($byWaktu as $slot) {
        $kid = (int) ($slot['kegiatan_id'] ?? 0);
        $tanggal = (string) ($slot['tanggal'] ?? '');
        if ($kid <= 0 || $tanggal === '') {
            continue;
        }

        $sudahScan = false;
        if ($tkFilter !== '') {
            foreach ((array) ($slot['tingkatan_keys'] ?? []) as $tkNorm) {
                if ($tkNorm === '*' || $tkNorm === $tkLower) {
                    if (isset($hadirPerSlot[$kid . '|' . $tanggal . '|' . $tkLower])) {
                        $sudahScan = true;
                        break;
                    }
                }
                if ($tkNorm !== '*' && isset($hadirPerSlot[$kid . '|' . $tanggal . '|' . $tkNorm])) {
                    $sudahScan = true;
                    break;
                }
            }
            if (!$sudahScan && in_array('*', (array) ($slot['tingkatan_keys'] ?? []), true)) {
                $sudahScan = isset($hadirPerSlot[$kid . '|' . $tanggal . '|' . $tkLower])
                    || isset($hadirKegiatanTanggal[$kid . '|' . $tanggal]);
            }
        } else {
            $sudahScan = isset($hadirKegiatanTanggal[$kid . '|' . $tanggal]);
        }
        if ($sudahScan) {
            continue;
        }

        $labels = array_values(array_unique((array) ($slot['tingkatan_labels'] ?? [])));
        if ($labels === []) {
            $labels = ['Semua tingkatan'];
        }
        $tingkatanLabel = count($labels) === 1 ? $labels[0] : implode(', ', $labels);
        $detailRow = [
            'tanggal' => $tanggal,
            'tanggal_tampil' => (string) ($slot['tanggal_tampil'] ?? ''),
            'tanggal_hijri' => (string) ($slot['tanggal_hijri'] ?? ''),
            'hari' => (string) ($slot['hari'] ?? ''),
            'jam' => (string) ($slot['jam'] ?? ''),
            'tingkatan' => $tingkatanLabel,
        ];

        $out[] = [
            'kegiatan_id' => $kid,
            'nama_kegiatan' => (string) ($slot['nama_kegiatan'] ?? ('Kegiatan #' . $kid)),
            'tanggal' => $tanggal,
            'tanggal_tampil' => (string) ($slot['tanggal_tampil'] ?? ''),
            'tanggal_hijri' => (string) ($slot['tanggal_hijri'] ?? ''),
            'hari' => (string) ($slot['hari'] ?? ''),
            'jam' => (string) ($slot['jam'] ?? ''),
            'hari_terjadwal' => 1,
            'slot_jadwal' => 1,
            'jumlah_tidak_scan' => 1,
            'tingkatan' => $labels,
            'tingkatan_label' => $tingkatanLabel,
            'detail' => [$detailRow],
        ];
    }

    usort($out, static function (array $a, array $b): int {
        $cmp = strcmp((string) ($a['tanggal'] ?? ''), (string) ($b['tanggal'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }
        $cmp = strcmp((string) $a['nama_kegiatan'], (string) $b['nama_kegiatan']);
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) ($a['tingkatan_label'] ?? ''), (string) ($b['tingkatan_label'] ?? ''));
    });

    return $out;
}

/**
 * Gabung baris per slot jadwal menjadi satu baris per kegiatan.
 * jumlah_tidak_scan = banyak waktu/jadwal tanpa scan (bukan jumlah santri).
 *
 * @param list<array<string, mixed>> $slotRows dari rekap_keaktifan_kegiatan_tanpa_scan_bulan()
 * @return list<array{
 *   kegiatan_id:int,
 *   nama_kegiatan:string,
 *   jumlah_tidak_scan:int,
 *   detail:list<array{tanggal:string,tanggal_tampil:string,tanggal_hijri:string,hari:string,jam:string,tingkatan:string}>
 * }>
 */
function rekap_keaktifan_kegiatan_tanpa_scan_group_by_kegiatan(array $slotRows): array
{
    /** @var array<int, array{kegiatan_id:int,nama_kegiatan:string,jumlah_tidak_scan:int,detail:list<array<string,mixed>>}> $byKid */
    $byKid = [];
    foreach ($slotRows as $row) {
        $kid = (int) ($row['kegiatan_id'] ?? 0);
        if ($kid <= 0) {
            continue;
        }
        if (!isset($byKid[$kid])) {
            $byKid[$kid] = [
                'kegiatan_id' => $kid,
                'nama_kegiatan' => (string) ($row['nama_kegiatan'] ?? ('Kegiatan #' . $kid)),
                'jumlah_tidak_scan' => 0,
                'detail' => [],
            ];
        }
        $byKid[$kid]['jumlah_tidak_scan'] += (int) ($row['jumlah_tidak_scan'] ?? 1);
        $detail = (array) ($row['detail'] ?? []);
        if ($detail === [] && !empty($row['tanggal'])) {
            $detail = [[
                'tanggal' => (string) ($row['tanggal'] ?? ''),
                'tanggal_tampil' => (string) ($row['tanggal_tampil'] ?? ''),
                'tanggal_hijri' => (string) ($row['tanggal_hijri'] ?? ''),
                'hari' => (string) ($row['hari'] ?? ''),
                'jam' => (string) ($row['jam'] ?? ''),
                'tingkatan' => (string) ($row['tingkatan_label'] ?? ''),
            ]];
        }
        foreach ($detail as $d) {
            if (!is_array($d)) {
                continue;
            }
            $byKid[$kid]['detail'][] = $d;
        }
    }

    $out = array_values($byKid);
    usort($out, static function (array $a, array $b): int {
        $cmp = ($b['jumlah_tidak_scan'] ?? 0) <=> ($a['jumlah_tidak_scan'] ?? 0);
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) ($a['nama_kegiatan'] ?? ''), (string) ($b['nama_kegiatan'] ?? ''));
    });

    foreach ($out as &$row) {
        usort($row['detail'], static function (array $a, array $b): int {
            $cmp = strcmp((string) ($a['tanggal'] ?? ''), (string) ($b['tanggal'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) ($a['jam'] ?? ''), (string) ($b['jam'] ?? ''));
        });
    }
    unset($row);

    return $out;
}

/** Total slot waktu tanpa scan (bukan jumlah kegiatan, tingkatan, atau santri). */
function rekap_keaktifan_kegiatan_tanpa_scan_total_jadwal(array $slotRows): int
{
    $total = 0;
    foreach ($slotRows as $row) {
        $total += (int) ($row['jumlah_tidak_scan'] ?? 1);
    }

    return $total > 0 ? $total : count($slotRows);
}

/**
 * Santri aktif yang masuk jadwal pada periode tetapi tidak pernah scan hadir sama sekali.
 *
 * @return list<array{
 *   santri_id:int,
 *   nis:string,
 *   nama_santri:string,
 *   tingkatan:string,
 *   hari_wajib:int,
 *   slot_wajib:int
 * }>
 */
function rekap_keaktifan_santri_tanpa_scan_bulan(
    PDO $pdo,
    string $startDate,
    string $endDate,
    ?string $tingkatanFilter = null
): array {
    require_once __DIR__ . '/presensi_jadwal.php';
    require_once __DIR__ . '/santri_operasional.php';

    if (!table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'presensi') || !table_exists($pdo, 'santri')) {
        return [];
    }

    $clamped = rekap_keaktifan_clamp_periode($pdo, $startDate, $endDate);
    if ($clamped === null) {
        return [];
    }
    [$startDate, $endDate] = $clamped;

    $eligibilitySet = presensi_jadwal_eligibility_set($pdo, $startDate, $endDate);
    if ($eligibilitySet === []) {
        return [];
    }

    $tkFilter = trim((string) ($tingkatanFilter ?? ''));

    $slotSemuaTingkatan = 0;
    /** @var array<string, int> $slotByTingkatan */
    $slotByTingkatan = [];
    /** @var array<string, array<string, true>> $hariByTingkatan */
    $hariByTingkatan = [];
    /** @var array<string, true> $hariSemuaTingkatan */
    $hariSemuaTingkatan = [];

    foreach (array_keys($eligibilitySet) as $key) {
        $parts = explode('|', (string) $key, 3);
        if (count($parts) < 3) {
            continue;
        }
        $tanggal = (string) $parts[1];
        $tk = (string) $parts[2];
        if ($tanggal === '') {
            continue;
        }
        if ($tk === '*') {
            $slotSemuaTingkatan++;
            $hariSemuaTingkatan[$tanggal] = true;
            continue;
        }
        $tkKey = strtolower($tk);
        $slotByTingkatan[$tkKey] = ($slotByTingkatan[$tkKey] ?? 0) + 1;
        if (!isset($hariByTingkatan[$tkKey])) {
            $hariByTingkatan[$tkKey] = [];
        }
        $hariByTingkatan[$tkKey][$tanggal] = true;
    }

    $sqlAktif = santri_sql_aktif_only('s');
    $santriParams = [];
    $tkSql = '';
    if ($tkFilter !== '') {
        $tkSql = ' AND LOWER(TRIM(s.tingkatan)) = LOWER(?)';
        $santriParams[] = $tkFilter;
    }
    $santriStmt = $pdo->prepare('
        SELECT s.id, s.nis, s.nama_santri, TRIM(s.tingkatan) AS tingkatan
        FROM santri s
        WHERE ' . $sqlAktif . $tkSql . '
        ORDER BY s.tingkatan ASC, s.nama_santri ASC
    ');
    $santriStmt->execute($santriParams);
    $santriRows = $santriStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($santriRows === []) {
        return [];
    }

    $hadirStmt = $pdo->prepare('
        SELECT p.santri_id, p.kegiatan_id, p.tanggal_presensi, s.tingkatan
        FROM presensi p
        INNER JOIN santri s ON s.id = p.santri_id AND ' . $sqlAktif . '
        WHERE p.tanggal_presensi BETWEEN :start AND :end
          AND p.status_presensi = "HADIR"' . ($tkFilter !== '' ? ' AND LOWER(TRIM(s.tingkatan)) = LOWER(:tk)' : '') . '
    ');
    $hadirParams = ['start' => $startDate, 'end' => $endDate];
    if ($tkFilter !== '') {
        $hadirParams['tk'] = $tkFilter;
    }
    $hadirStmt->execute($hadirParams);

    /** @var array<int, int> $hadirBySantri */
    $hadirBySantri = [];
    foreach ($hadirStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!presensi_row_eligible_for_hitung($pdo, $row, $eligibilitySet)) {
            continue;
        }
        $sid = (int) ($row['santri_id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $hadirBySantri[$sid] = ($hadirBySantri[$sid] ?? 0) + 1;
    }

    $hariSemuaCount = count($hariSemuaTingkatan);
    $out = [];
    foreach ($santriRows as $santri) {
        $sid = (int) ($santri['id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $tingkatan = trim((string) ($santri['tingkatan'] ?? ''));
        if ($tingkatan === '') {
            continue;
        }
        $tkKey = strtolower($tingkatan);
        $slotWajib = $slotSemuaTingkatan + (int) ($slotByTingkatan[$tkKey] ?? 0);
        if ($slotWajib <= 0) {
            continue;
        }
        if (($hadirBySantri[$sid] ?? 0) > 0) {
            continue;
        }

        $hariTk = $hariByTingkatan[$tkKey] ?? [];
        $hariWajib = count($hariTk + $hariSemuaTingkatan);

        $out[] = [
            'santri_id' => $sid,
            'nis' => (string) ($santri['nis'] ?? ''),
            'nama_santri' => (string) ($santri['nama_santri'] ?? ''),
            'tingkatan' => $tingkatan,
            'hari_wajib' => $hariWajib,
            'slot_wajib' => $slotWajib,
        ];
    }

    usort($out, static function (array $a, array $b): int {
        $cmp = strcmp((string) $a['tingkatan'], (string) $b['tingkatan']);
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) $a['nama_santri'], (string) $b['nama_santri']);
    });

    return $out;
}

/**
 * Roster santri wajib hadir pada satu slot kegiatan+tanggal (untuk UI rekap tanpa scan).
 *
 * @return list<array{santri_id:int,nis:string,nama_santri:string,tingkatan:string,status:string,hadir:bool}>
 */
function rekap_keaktifan_slot_santri_roster(
    PDO $pdo,
    int $kegiatanId,
    string $tanggal,
    ?string $tingkatanFilter = null
): array {
    require_once __DIR__ . '/presensi_jadwal.php';
    require_once __DIR__ . '/santri_operasional.php';

    if ($kegiatanId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return [];
    }
    if (!table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan') || !table_exists($pdo, 'santri')) {
        return [];
    }

    $mulaiScan = rekap_keaktifan_tanggal_mulai_scan($pdo);
    if ($mulaiScan !== '' && $tanggal < $mulaiScan) {
        return [];
    }

    $aktifSql = santri_sql_aktif_only('s');
    $hariKe = (int) date('N', strtotime($tanggal) ?: time());
    $params = [
        'kid' => $kegiatanId,
        'tgl' => $tanggal,
        'hari' => $hariKe,
    ];
    $tkWhere = '';
    $tkFilter = trim((string) ($tingkatanFilter ?? ''));
    if ($tkFilter !== '') {
        // Filter tampilan: hanya santri tingkatan itu (bukan filter jadwal "Semua Tingkatan").
        $tkWhere = ' AND s.tingkatan = :tk';
        $params['tk'] = $tkFilter;
    }

    $sql = '
        SELECT DISTINCT
            s.id AS santri_id,
            s.nis,
            s.nama_santri,
            s.tingkatan,
            COALESCE(NULLIF(TRIM(p.status_presensi), ""), "") AS status_presensi
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id AND k.is_active = 1
        INNER JOIN santri s ON (
            (
                (j.tingkatan = "Semua Tingkatan" AND TRIM(COALESCE(s.tingkatan, "")) <> "")
                OR s.tingkatan = j.tingkatan
            )
            AND ' . $aktifSql . '
        )
        LEFT JOIN presensi p ON p.santri_id = s.id
            AND p.kegiatan_id = k.id
            AND p.tanggal_presensi = :tgl
        WHERE j.kegiatan_id = :kid
          AND (j.hari_ke = 0 OR j.hari_ke = :hari)
          ' . $tkWhere . '
        ORDER BY s.tingkatan ASC, s.nama_santri ASC
    ';

    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (PDOException $e) {
        error_log('[rekap_keaktifan_slot_santri_roster] ' . $e->getMessage());

        return [];
    }

    $santriIds = array_values(array_unique(array_filter(array_map(
        static fn(array $r): int => (int) ($r['santri_id'] ?? 0),
        $rows
    ), static fn(int $id): bool => $id > 0)));

    require_once __DIR__ . '/presensi_tanpa_scan_koreksi.php';
    $bebasMap = presensi_alpa_bebas_map($pdo, $kegiatanId, $tanggal, $santriIds);

    /** @var array<int, array{id:int,status_presensi:string}> $presensiBySid */
    $presensiBySid = [];
    if ($santriIds !== [] && table_exists($pdo, 'presensi')) {
        $ph = implode(',', array_fill(0, count($santriIds), '?'));
        $stP = $pdo->prepare('
            SELECT id, santri_id, status_presensi FROM presensi
            WHERE kegiatan_id = ? AND tanggal_presensi = ? AND santri_id IN (' . $ph . ')
            ORDER BY id DESC
        ');
        $stP->execute(array_merge([$kegiatanId, $tanggal], $santriIds));
        foreach ($stP->fetchAll(PDO::FETCH_ASSOC) ?: [] as $pr) {
            $sid = (int) ($pr['santri_id'] ?? 0);
            if ($sid > 0 && !isset($presensiBySid[$sid])) {
                $presensiBySid[$sid] = [
                    'id' => (int) ($pr['id'] ?? 0),
                    'status_presensi' => (string) ($pr['status_presensi'] ?? ''),
                ];
            }
        }
    }

    if ($rows !== [] && function_exists('presensi_apply_status_efektif_rows')) {
        $forEfektif = [];
        foreach ($rows as $r) {
            $forEfektif[] = [
                'santri_id' => (int) ($r['santri_id'] ?? 0),
                'kegiatan_id' => $kegiatanId,
                'tanggal_presensi' => $tanggal,
                'tingkatan' => (string) ($r['tingkatan'] ?? ''),
                'status_presensi' => (string) ($r['status_presensi'] ?? ''),
                'status_hari_ini' => (string) ($r['status_presensi'] ?? ''),
            ];
        }
        $efektif = presensi_apply_status_efektif_rows($pdo, $forEfektif, $tanggal);
        $bySid = [];
        foreach ($efektif as $er) {
            $sid = (int) ($er['santri_id'] ?? 0);
            if ($sid > 0) {
                $status = strtoupper(trim((string) ($er['status_presensi'] ?? $er['status_hari_ini'] ?? '')));
                $bySid[$sid] = $status;
            }
        }
        foreach ($rows as &$r) {
            $sid = (int) ($r['santri_id'] ?? 0);
            if ($sid > 0 && isset($bySid[$sid])) {
                $r['status_presensi'] = $bySid[$sid];
            }
        }
        unset($r);
    }

    $out = [];
    $seen = [];
    foreach ($rows as $r) {
        $sid = (int) ($r['santri_id'] ?? 0);
        if ($sid <= 0 || isset($seen[$sid])) {
            continue;
        }
        $seen[$sid] = true;
        $status = strtoupper(trim((string) ($r['status_presensi'] ?? '')));
        if (!empty($bebasMap[$sid]) && $status !== 'HADIR') {
            $status = 'BEBAS';
        }
        $presensiRow = $presensiBySid[$sid] ?? null;
        $out[] = [
            'santri_id' => $sid,
            'nis' => (string) ($r['nis'] ?? ''),
            'nama_santri' => (string) ($r['nama_santri'] ?? ''),
            'tingkatan' => (string) ($r['tingkatan'] ?? ''),
            'status' => $status,
            'hadir' => $status === 'HADIR',
            'presensi_id' => $presensiRow['id'] ?? 0,
            'alpa_bebas' => !empty($bebasMap[$sid]),
        ];
    }

    return $out;
}
