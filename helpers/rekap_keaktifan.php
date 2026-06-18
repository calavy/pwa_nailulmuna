<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

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
 * @param list<array{tanggal:string,tk:string}> $slotKeys
 * @param array<int, list<array{tingkatan:string,hari_ke:int,jam_mulai:string,jam_selesai:string}>> $jadwalByKid
 * @return list<array{tanggal:string,tanggal_tampil:string,hari:string,jam:string,tingkatan:string}>
 */
function rekap_keaktifan_kegiatan_tanpa_scan_buat_detail(
    int $kegiatanId,
    array $slotKeys,
    array $jadwalByKid
): array {
    $hariMap = [
        1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis',
        5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu',
    ];
    $jadwalList = $jadwalByKid[$kegiatanId] ?? [];
    if ($jadwalList === [] || $slotKeys === []) {
        return [];
    }

    $details = [];
    $seen = [];
    foreach ($slotKeys as $sk) {
        $tanggal = (string) ($sk['tanggal'] ?? '');
        $tkKey = (string) ($sk['tk'] ?? '');
        if ($tanggal === '') {
            continue;
        }
        $hariKe = (int) date('N', strtotime($tanggal) ?: time());
        $hariLabel = $hariMap[$hariKe] ?? '-';

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
            $js = substr((string) ($j['jam_selesai'] ?? ''), 0, 5);
            if ($jm === '' || $js === '') {
                continue;
            }
            $jam = $jm . ' – ' . $js;
            $tgLabel = strcasecmp($jTg, 'Semua Tingkatan') === 0 ? 'Semua tingkatan' : $jTg;
            $dedupe = $tanggal . '|' . $jam . '|' . strtolower($tgLabel);
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;
            $details[] = [
                'tanggal' => $tanggal,
                'tanggal_tampil' => date('d/m/Y', strtotime($tanggal) ?: time()),
                'hari' => $hariLabel,
                'jam' => $jam,
                'tingkatan' => $tgLabel,
            ];
        }
    }

    usort($details, static function (array $a, array $b): int {
        $cmp = strcmp((string) $a['tanggal'], (string) $b['tanggal']);
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) $a['jam'], (string) $b['jam']);
    });

    return $details;
}

/**
 * Kegiatan terjadwal dalam periode yang tidak pernah discan hadir oleh santri sama sekali.
 *
 * @return list<array{
 *   kegiatan_id:int,
 *   nama_kegiatan:string,
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

    // Rekap baca-only: cukup cek scan HADIR, tanpa sync ALPA seluruh bulan (lambat / timeout).
    $eligibilitySet = presensi_jadwal_eligibility_set($pdo, $startDate, $endDate);
    if ($eligibilitySet === []) {
        return [];
    }

    $tkFilter = trim((string) ($tingkatanFilter ?? ''));
    $tkLower = strtolower($tkFilter);
    $jadwalByKid = rekap_keaktifan_jadwal_rows_by_kegiatan($pdo);

    /** @var array<int, array{hari: array<string, true>, tingkatan: array<string, true>, slot_count: int, keys: list<array{tanggal:string,tk:string}>}> $scheduled */
    $scheduled = [];
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

        if (!isset($scheduled[$kid])) {
            $scheduled[$kid] = ['hari' => [], 'tingkatan' => [], 'slot_count' => 0, 'keys' => []];
        }
        $scheduled[$kid]['hari'][$tanggal] = true;
        $scheduled[$kid]['slot_count']++;
        $scheduled[$kid]['keys'][] = ['tanggal' => $tanggal, 'tk' => $tk];
        if ($tk !== '*') {
            $scheduled[$kid]['tingkatan'][$tk] = true;
        }
    }

    if ($scheduled === []) {
        return [];
    }

    $sqlAktif = santri_sql_aktif_only('s');
    $kids = array_keys($scheduled);
    $placeholders = implode(',', array_fill(0, count($kids), '?'));
    $params = array_merge([$startDate, $endDate], $kids);
    $tkSql = '';
    if ($tkFilter !== '') {
        $tkSql = ' AND LOWER(s.tingkatan) = LOWER(?)';
        $params[] = $tkFilter;
    }

    $stmt = $pdo->prepare('
        SELECT p.kegiatan_id, p.tanggal_presensi, s.tingkatan
        FROM presensi p
        INNER JOIN santri s ON s.id = p.santri_id AND ' . $sqlAktif . '
        WHERE p.tanggal_presensi BETWEEN ? AND ?
          AND p.status_presensi = "HADIR"
          AND p.kegiatan_id IN (' . $placeholders . ')' . $tkSql . '
    ');
    $stmt->execute($params);

    /** @var array<int, int> $hadirByKegiatan */
    $hadirByKegiatan = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!presensi_row_eligible_for_hitung($pdo, $row, $eligibilitySet)) {
            continue;
        }
        $kid = (int) ($row['kegiatan_id'] ?? 0);
        if ($kid <= 0) {
            continue;
        }
        $hadirByKegiatan[$kid] = ($hadirByKegiatan[$kid] ?? 0) + 1;
    }

    $nameStmt = $pdo->prepare('SELECT id, nama_kegiatan FROM kegiatan WHERE id IN (' . $placeholders . ')');
    $nameStmt->execute($kids);
    /** @var array<int, string> $namaMap */
    $namaMap = [];
    foreach ($nameStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $nameRow) {
        $namaMap[(int) ($nameRow['id'] ?? 0)] = (string) ($nameRow['nama_kegiatan'] ?? '');
    }

    $jadwalTkStmt = $pdo->prepare('
        SELECT DISTINCT TRIM(tingkatan) AS tingkatan
        FROM jadwal_kegiatan
        WHERE kegiatan_id = :kid AND TRIM(tingkatan) <> ""
        ORDER BY tingkatan ASC
    ');

    $out = [];
    foreach ($scheduled as $kid => $meta) {
        if (($hadirByKegiatan[$kid] ?? 0) > 0) {
            continue;
        }

        $tingkatanList = array_keys($meta['tingkatan']);
        if ($tingkatanList === []) {
            $jadwalTkStmt->execute(['kid' => $kid]);
            $tingkatanList = array_values(array_filter(array_map(
                static fn(array $r): string => trim((string) ($r['tingkatan'] ?? '')),
                $jadwalTkStmt->fetchAll(PDO::FETCH_ASSOC) ?: []
            )));
        }
        sort($tingkatanList, SORT_STRING);

        $tingkatanLabel = $tingkatanList !== []
            ? implode(', ', $tingkatanList)
            : 'Semua tingkatan';

        $detail = rekap_keaktifan_kegiatan_tanpa_scan_buat_detail($kid, $meta['keys'], $jadwalByKid);
        $jumlahTidakScan = count($detail);
        if ($jumlahTidakScan === 0) {
            $jumlahTidakScan = (int) $meta['slot_count'];
        }

        $out[] = [
            'kegiatan_id' => $kid,
            'nama_kegiatan' => trim((string) ($namaMap[$kid] ?? '')) !== '' ? (string) $namaMap[$kid] : ('Kegiatan #' . $kid),
            'hari_terjadwal' => count($meta['hari']),
            'slot_jadwal' => (int) $meta['slot_count'],
            'jumlah_tidak_scan' => $jumlahTidakScan,
            'tingkatan' => $tingkatanList,
            'tingkatan_label' => $tingkatanLabel,
            'detail' => $detail,
        ];
    }

    usort($out, static function (array $a, array $b): int {
        $cmp = strcmp((string) $a['nama_kegiatan'], (string) $b['nama_kegiatan']);
        if ($cmp !== 0) {
            return $cmp;
        }

        return ((int) $a['kegiatan_id']) <=> ((int) $b['kegiatan_id']);
    });

    return $out;
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
