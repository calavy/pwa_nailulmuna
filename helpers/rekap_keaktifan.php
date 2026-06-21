<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/** Tanggal mulai scan resmi untuk rekap keaktivan (Y-m-d) atau kosong = semua riwayat. */
function rekap_keaktifan_tanggal_mulai_scan(PDO $pdo): string
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $raw = trim((string) app_setting($pdo, 'keaktifan_tanggal_mulai_scan', ''));
    if ($raw === '') {
        return $cached = '';
    }
    $ts = strtotime($raw);

    return $cached = ($ts !== false) ? date('Y-m-d', $ts) : '';
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
function rekap_keaktifan_build_per_santri(array $rows, int $goodMax, int $mediumMax): array
{
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
                'total' => 0,
                'per_kegiatan' => [],
            ];
        }
        $status = strtoupper((string) ($row['status_presensi'] ?? ''));
        $kgLabel = trim((string) ($row['nama_kegiatan'] ?? '')) !== '' ? (string) $row['nama_kegiatan'] : 'Tanpa Kegiatan';
        if (!isset($bySantri[$sid]['per_kegiatan'][$kgLabel])) {
            $bySantri[$sid]['per_kegiatan'][$kgLabel] = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'total' => 0];
        }
        $bySantri[$sid]['total']++;
        $bySantri[$sid]['per_kegiatan'][$kgLabel]['total']++;
        if ($status === 'HADIR') {
            $bySantri[$sid]['hadir']++;
            $bySantri[$sid]['per_kegiatan'][$kgLabel]['hadir']++;
        } elseif ($status === 'IZIN') {
            $bySantri[$sid]['izin']++;
            $bySantri[$sid]['per_kegiatan'][$kgLabel]['izin']++;
        } elseif ($status === 'SAKIT') {
            $bySantri[$sid]['sakit']++;
            $bySantri[$sid]['per_kegiatan'][$kgLabel]['sakit']++;
        } elseif ($status === 'ALPA') {
            $bySantri[$sid]['alpa']++;
            $bySantri[$sid]['per_kegiatan'][$kgLabel]['alpa']++;
        }
    }

    $ranked = [];
    foreach ($bySantri as $item) {
        $total = (int) $item['total'];
        $hadir = (int) $item['hadir'];
        $alpa = (int) $item['alpa'];
        $persen = $total > 0 ? round(($hadir / $total) * 100, 2) : 0;
        $kategori = santri_category($alpa, $goodMax, $mediumMax);
        $skor = ($persen * 10) - ($alpa * 5);
        $item['persen_hadir'] = $persen;
        $item['kategori'] = $kategori;
        $item['skor'] = $skor;
        foreach ($item['per_kegiatan'] as $kgName => $kgStats) {
            $kgTotal = (int) $kgStats['total'];
            $kgHadir = (int) $kgStats['hadir'];
            $kgAlpa = (int) $kgStats['alpa'];
            $kgPersen = $kgTotal > 0 ? round(($kgHadir / $kgTotal) * 100, 2) : 0.0;
            $item['per_kegiatan'][$kgName]['persen_hadir'] = $kgPersen;
            $item['per_kegiatan'][$kgName]['kategori'] = santri_category($kgAlpa, $goodMax, $mediumMax);
        }
        ksort($item['per_kegiatan']);
        $ranked[] = $item;
    }

    usort($ranked, static function (array $a, array $b): int {
        if ($a['skor'] === $b['skor']) {
            return strcmp((string) $a['nama_santri'], (string) $b['nama_santri']);
        }

        return $b['skor'] <=> $a['skor'];
    });

    return $ranked;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array<string, array{hadir:int,izin:int,sakit:int,alpa:int,total:int,santri_count:int}>
 */
function rekap_keaktifan_build_per_kegiatan(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $kgLabel = trim((string) ($row['nama_kegiatan'] ?? '')) !== '' ? (string) $row['nama_kegiatan'] : 'Tanpa Kegiatan';
        if (!isset($out[$kgLabel])) {
            $out[$kgLabel] = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'total' => 0, 'santri_ids' => []];
        }
        $status = strtoupper((string) ($row['status_presensi'] ?? ''));
        $out[$kgLabel]['total']++;
        $sid = (int) ($row['santri_id'] ?? 0);
        if ($sid > 0) {
            $out[$kgLabel]['santri_ids'][$sid] = true;
        }
        if ($status === 'HADIR') {
            $out[$kgLabel]['hadir']++;
        } elseif ($status === 'IZIN') {
            $out[$kgLabel]['izin']++;
        } elseif ($status === 'SAKIT') {
            $out[$kgLabel]['sakit']++;
        } elseif ($status === 'ALPA') {
            $out[$kgLabel]['alpa']++;
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
    bool $runFinalize = true
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

    $santriIds = array_values(array_unique(array_filter(array_map('intval', $santriIds), static fn (int $id): bool => $id > 0)));
    if ($santriIds !== []) {
        $ph = implode(',', array_fill(0, count($santriIds), '?'));
        $where .= ' AND s.id IN (' . $ph . ')';
        $params = array_merge($params, $santriIds);
    }

    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
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
        FROM presensi p
        INNER JOIN santri s ON s.id = p.santri_id AND ' . $sqlAktif . '
        LEFT JOIN kegiatan k ON k.id = p.kegiatan_id
        WHERE ' . $where . '
        ORDER BY s.' . $nameCol . ' ASC, p.tanggal_presensi ASC
    ');
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return presensi_filter_rows_eligible($pdo, $rows, $startDate, $endDate);
}

/**
 * @param list<array<string, mixed>> $rows baris eligible
 * @return array{hadir:int,izin:int,sakit:int,alpa:int,total:int}
 */
function rekap_keaktifan_totals_from_rows(array $rows): array
{
    $totals = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'total' => 0];
    foreach ($rows as $row) {
        $totals['total']++;
        $status = strtoupper((string) ($row['status_presensi'] ?? ''));
        if ($status === 'HADIR') {
            $totals['hadir']++;
        } elseif ($status === 'IZIN') {
            $totals['izin']++;
        } elseif ($status === 'SAKIT') {
            $totals['sakit']++;
        } elseif ($status === 'ALPA') {
            $totals['alpa']++;
        }
    }

    return $totals;
}

/**
 * Rekap per kegiatan dari baris eligible (format daftar untuk portal).
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array{kegiatan_id:int,nama_kegiatan:string,kategori_kegiatan:string,hadir:int,izin:int,sakit:int,alpa:int,total:int}>
 */
function rekap_keaktifan_kegiatan_list_from_rows(array $rows): array
{
    /** @var array<string, array{kegiatan_id:int,nama_kegiatan:string,kategori_kegiatan:string,hadir:int,izin:int,sakit:int,alpa:int,total:int}> $byKey */
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
                'total' => 0,
            ];
        }
        $status = strtoupper((string) ($row['status_presensi'] ?? ''));
        $byKey[$key]['total']++;
        if ($status === 'HADIR') {
            $byKey[$key]['hadir']++;
        } elseif ($status === 'IZIN') {
            $byKey[$key]['izin']++;
        } elseif ($status === 'SAKIT') {
            $byKey[$key]['sakit']++;
        } elseif ($status === 'ALPA') {
            $byKey[$key]['alpa']++;
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
    $kategoriKeys = ['Bagus', 'Baik', 'Sedang', 'Buruk'];
    $out = [];
    foreach ($ranked as $row) {
        $tg = trim((string) ($row['tingkatan'] ?? '')) !== '' ? (string) $row['tingkatan'] : '-';
        if (!isset($out[$tg])) {
            $out[$tg] = [
                'hadir' => 0,
                'izin' => 0,
                'sakit' => 0,
                'alpa' => 0,
                'total' => 0,
                'santri_count' => 0,
                'kategori' => array_fill_keys($kategoriKeys, 0),
                'santri_by_kategori' => array_fill_keys($kategoriKeys, []),
            ];
        }
        $out[$tg]['hadir'] += (int) $row['hadir'];
        $out[$tg]['izin'] += (int) $row['izin'];
        $out[$tg]['sakit'] += (int) $row['sakit'];
        $out[$tg]['alpa'] += (int) $row['alpa'];
        $out[$tg]['total'] += (int) $row['total'];
        $out[$tg]['santri_count']++;

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
            'total' => (int) $row['total'],
            'persen_hadir' => (float) ($row['persen_hadir'] ?? 0),
            'kategori' => $kat,
        ];
    }
    foreach ($out as $tg => $data) {
        $total = (int) $data['total'];
        $out[$tg]['persen_hadir'] = $total > 0 ? round(((int) $data['hadir'] / $total) * 100, 2) : 0.0;
        foreach ($kategoriKeys as $katKey) {
            usort($out[$tg]['santri_by_kategori'][$katKey], static function (array $a, array $b): int {
                return strcmp((string) $a['nama_santri'], (string) $b['nama_santri']);
            });
        }
    }
    ksort($out);

    return $out;
}

function rekap_keaktifan_kategori_badge_class(string $kategori): string
{
    return match ($kategori) {
        'Bagus' => 'success',
        'Baik' => 'info',
        'Sedang' => 'warning',
        'Buruk' => 'danger',
        default => 'secondary',
    };
}

/** @return list<string> */
function rekap_keaktifan_kategori_urutan(): array
{
    return ['Bagus', 'Baik', 'Sedang', 'Buruk'];
}

/** Kategori utama perbandingan antar tingkatan (selain Bagus). */
function rekap_keaktifan_kategori_perbandingan(): array
{
    return ['Baik', 'Sedang', 'Buruk'];
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
        'Bagus' => '#16a34a',
        'Baik' => '#0ea5e9',
        'Sedang' => '#f59e0b',
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
        'jam' => $jamMulai !== '' ? ($jamMulai . ' – ' . substr($jamSelesai, 0, 5)) : substr($jamSelesai, 0, 5),
        'jam_selesai' => $jamSelesai,
        'tingkatan' => $tgLabel,
    ];
}

/**
 * Setiap jadwal kegiatan (tanggal + tingkatan) tanpa satupun scan HADIR = 1 hitungan.
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

    if (!table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan') || !table_exists($pdo, 'presensi')) {
        return [];
    }

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
    if ($kids !== []) {
        $placeholders = implode(',', array_fill(0, count($kids), '?'));
        $nameStmt = $pdo->prepare('SELECT id, nama_kegiatan FROM kegiatan WHERE id IN (' . $placeholders . ')');
        $nameStmt->execute($kids);
        foreach ($nameStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $nameRow) {
            $namaMap[(int) ($nameRow['id'] ?? 0)] = (string) ($nameRow['nama_kegiatan'] ?? '');
        }
    }

    $out = [];
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

        $slotMeta = rekap_keaktifan_kegiatan_tanpa_scan_slot_meta($pdo, $kid, $tanggal, $tk, $jadwalByKid);
        if ($slotMeta === null) {
            continue;
        }
        if (!presensi_jam_selesai_lewat($tanggal, (string) ($slotMeta['jam_selesai'] ?? ''))) {
            continue;
        }

        $sudahScan = false;
        if ($tk === '*') {
            if ($tkFilter !== '') {
                $sudahScan = isset($hadirPerSlot[$kid . '|' . $tanggal . '|' . $tkLower]);
            } else {
                $sudahScan = isset($hadirKegiatanTanggal[$kid . '|' . $tanggal]);
            }
        } else {
            $sudahScan = isset($hadirPerSlot[$kid . '|' . $tanggal . '|' . strtolower($tk)]);
        }
        if ($sudahScan) {
            continue;
        }

        $tingkatanLabel = (string) ($slotMeta['tingkatan'] ?? ($tk === '*' ? 'Semua tingkatan' : $tk));
        $detailRow = [
            'tanggal' => (string) $slotMeta['tanggal'],
            'tanggal_tampil' => (string) $slotMeta['tanggal_tampil'],
            'tanggal_hijri' => (string) ($slotMeta['tanggal_hijri'] ?? ''),
            'hari' => (string) $slotMeta['hari'],
            'jam' => (string) $slotMeta['jam'],
            'tingkatan' => $tingkatanLabel,
        ];

        $out[] = [
            'kegiatan_id' => $kid,
            'nama_kegiatan' => trim((string) ($namaMap[$kid] ?? '')) !== '' ? (string) $namaMap[$kid] : ('Kegiatan #' . $kid),
            'tanggal' => $tanggal,
            'tanggal_tampil' => (string) $slotMeta['tanggal_tampil'],
            'tanggal_hijri' => (string) ($slotMeta['tanggal_hijri'] ?? ''),
            'hari' => (string) $slotMeta['hari'],
            'jam' => (string) $slotMeta['jam'],
            'hari_terjadwal' => 1,
            'slot_jadwal' => 1,
            'jumlah_tidak_scan' => 1,
            'tingkatan' => [$tingkatanLabel],
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

/** Total slot jadwal tanpa scan (bukan jumlah kegiatan atau santri). */
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
