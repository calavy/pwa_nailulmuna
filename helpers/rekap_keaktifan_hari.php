<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/santri_operasional.php';
require_once __DIR__ . '/presensi_jadwal.php';

/**
 * Filter kategori kegiatan untuk rekap keaktifan: null|'' = semua, JAMAAH, TAALIM.
 */
function rekap_keaktifan_hari_normalize_kategori(?string $kategori): ?string
{
    $k = strtoupper(trim((string) $kategori));
    if ($k === '' || $k === 'ALL' || $k === 'SEMUA') {
        return null;
    }
    if (in_array($k, ['JAMAAH', 'TAALIM', 'TA\'LIM'], true)) {
        return $k === 'TA\'LIM' ? 'TAALIM' : $k;
    }

    return null;
}

/**
 * Laporan keaktifan santri hari ini per kegiatan (seluruh pondok atau filter tingkatan).
 *
 * @return list<array<string, mixed>>
 */
function rekap_keaktifan_hari_data(PDO $pdo, string $tanggal, ?string $tingkatanFilter = null, ?string $kategoriKegiatan = null): array
{
    static $dataCache = [];
    $cacheKey = $tanggal . '|' . ($tingkatanFilter ?? '') . '|' . ($kategoriKegiatan ?? '');
    if (isset($dataCache[$cacheKey])) {
        return $dataCache[$cacheKey];
    }

    if (!table_exists($pdo, 'presensi') || !table_exists($pdo, 'santri') || !table_exists($pdo, 'kegiatan') || !table_exists($pdo, 'jadwal_kegiatan')) {
        return [];
    }
    $aktifSql = santri_sql_aktif_only('s');
    $hariKe = (int) date('N', strtotime($tanggal) ?: time());
    $params = ['tgl' => $tanggal, 'hari' => $hariKe];
    $tkWhere = '';
    if ($tingkatanFilter !== null && $tingkatanFilter !== '') {
        $tkWhere = ' AND s.tingkatan = :tk';
        $params['tk'] = $tingkatanFilter;
    }
    $katNorm = rekap_keaktifan_hari_normalize_kategori($kategoriKegiatan);
    $katWhere = '';
    if ($katNorm !== null) {
        $katWhere = ' AND COALESCE(k.kategori_kegiatan, "TAALIM") = :kat';
        $params['kat'] = $katNorm;
    }

    $sql = "
        SELECT DISTINCT
            k.id AS kegiatan_id,
            k.nama_kegiatan,
            COALESCE(k.kategori_kegiatan, 'TAALIM') AS kategori_kegiatan,
            s.id AS santri_id,
            s.nis,
            s.nama_santri,
            s.tingkatan,
            COALESCE(NULLIF(TRIM(p.status_presensi), ''), '') AS status_hari_ini,
            p.jam_presensi,
            p.catatan
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id AND k.is_active = 1
        INNER JOIN santri s ON (
            (
                (j.tingkatan = 'Semua Tingkatan' AND TRIM(COALESCE(s.tingkatan, '')) <> '')
                OR s.tingkatan = j.tingkatan
            )
            AND {$aktifSql}
        )
        LEFT JOIN presensi p ON p.santri_id = s.id
            AND p.kegiatan_id = k.id
            AND p.tanggal_presensi = :tgl
        WHERE (j.hari_ke = 0 OR j.hari_ke = :hari)
          {$tkWhere}
          {$katWhere}
        ORDER BY k.nama_kegiatan ASC, s.tingkatan ASC, s.nama_santri ASC
    ";
    static $finalizedDates = [];
    if (!isset($finalizedDates[$tanggal])) {
        $auditUserId = (int) ($_SESSION['user']['id'] ?? 1);
        presensi_finalize_date_range($pdo, $tanggal, $tanggal, $auditUserId > 0 ? $auditUserId : 1);
        $finalizedDates[$tanggal] = true;
    }

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $rows = presensi_apply_status_efektif_rows($pdo, $rows, $tanggal);
    $dataCache[$cacheKey] = $rows;

    return $rows;
}

/**
 * Ringkasan per kegiatan: jumlah hadir, izin, sakit, alpa.
 *
 * @return list<array<string, mixed>>
 */
/**
 * Ringkasan per kegiatan dari baris mentah (tanpa query ulang).
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function rekap_keaktifan_hari_ringkasan_from_rows(array $rows): array
{
    $byKeg = [];
    foreach ($rows as $r) {
        $kid = (int) ($r['kegiatan_id'] ?? 0);
        if (!isset($byKeg[$kid])) {
            $byKeg[$kid] = [
                'kegiatan_id' => $kid,
                'nama_kegiatan' => (string) ($r['nama_kegiatan'] ?? ''),
                'hadir' => 0,
                'izin' => 0,
                'sakit' => 0,
                'alpa' => 0,
                'total' => 0,
            ];
        }
        $st = strtoupper((string) ($r['status_hari_ini'] ?? ''));
        if ($st === 'ISTIRAHAT') {
            $st = 'ALPA';
        }
        $byKeg[$kid]['total']++;
        match ($st) {
            'HADIR' => $byKeg[$kid]['hadir']++,
            'IZIN' => $byKeg[$kid]['izin']++,
            'SAKIT' => $byKeg[$kid]['sakit']++,
            'ALPA' => $byKeg[$kid]['alpa']++,
            default => null,
        };
    }

    return array_values($byKeg);
}

/**
 * Ringkasan per kegiatan dari hasil detail (hindari iterasi ganda).
 *
 * @param list<array<string, mixed>> $detailKeg
 * @return list<array<string, mixed>>
 */
function rekap_keaktifan_hari_ringkasan_from_detail(array $detailKeg): array
{
    $out = [];
    foreach ($detailKeg as $d) {
        $out[] = [
            'kegiatan_id' => (int) ($d['kegiatan_id'] ?? 0),
            'nama_kegiatan' => (string) ($d['nama_kegiatan'] ?? ''),
            'hadir' => (int) ($d['hadir'] ?? 0),
            'izin' => (int) ($d['izin'] ?? 0),
            'sakit' => (int) ($d['sakit'] ?? 0),
            'alpa' => (int) ($d['alpa'] ?? 0),
            'total' => (int) ($d['total'] ?? 0),
        ];
    }

    return $out;
}

function rekap_keaktifan_hari_ringkasan_kegiatan(PDO $pdo, string $tanggal, ?string $tingkatanFilter = null, ?string $kategoriKegiatan = null): array
{
    return rekap_keaktifan_hari_ringkasan_from_rows(
        rekap_keaktifan_hari_data($pdo, $tanggal, $tingkatanFilter, $kategoriKegiatan)
    );
}

/**
 * Total agregat dari ringkasan per kegiatan.
 *
 * @param list<array<string, mixed>> $ringkasan
 * @return array{hadir:int,izin:int,sakit:int,alpa:int,total:int,persen:float}
 */
function rekap_keaktifan_hari_totals(array $ringkasan): array
{
    $tot = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'total' => 0];
    foreach ($ringkasan as $rg) {
        $tot['hadir'] += (int) ($rg['hadir'] ?? 0);
        $tot['izin'] += (int) ($rg['izin'] ?? 0);
        $tot['sakit'] += (int) ($rg['sakit'] ?? 0);
        $tot['alpa'] += (int) ($rg['alpa'] ?? 0);
        $tot['total'] += (int) ($rg['total'] ?? 0);
    }
    $tot['persen'] = $tot['total'] > 0
        ? round(100 * $tot['hadir'] / $tot['total'], 1)
        : 0.0;

    return $tot;
}

/**
 * Detail per kegiatan: hitungan + daftar santri per status.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function rekap_keaktifan_hari_detail_by_kegiatan(array $rows): array
{
    $byKeg = [];
    foreach ($rows as $r) {
        $kid = (int) ($r['kegiatan_id'] ?? 0);
        if (!isset($byKeg[$kid])) {
            $byKeg[$kid] = [
                'kegiatan_id' => $kid,
                'nama_kegiatan' => (string) ($r['nama_kegiatan'] ?? ''),
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
        if (!in_array($st, ['HADIR', 'IZIN', 'SAKIT', 'ALPA'], true)) {
            $byKeg[$kid]['total']++;
            continue;
        }
        if (!isset($byKeg[$kid]['santri'][$st])) {
            $byKeg[$kid]['santri']['ALPA'] ??= [];
            $st = 'ALPA';
        }
        $byKeg[$kid]['total']++;
        match ($st) {
            'HADIR' => $byKeg[$kid]['hadir']++,
            'IZIN' => $byKeg[$kid]['izin']++,
            'SAKIT' => $byKeg[$kid]['sakit']++,
            'ALPA' => $byKeg[$kid]['alpa']++,
            default => null,
        };
        $jam = $r['jam_presensi'] ?? null;
        $byKeg[$kid]['santri'][$st][] = [
            'nama_santri' => (string) ($r['nama_santri'] ?? ''),
            'nis' => (string) ($r['nis'] ?? ''),
            'tingkatan' => (string) ($r['tingkatan'] ?? ''),
            'jam_presensi' => $jam !== null && $jam !== '' ? substr((string) $jam, 0, 5) : null,
            'catatan' => trim((string) ($r['catatan'] ?? '')),
        ];
    }

    return array_values($byKeg);
}

/**
 * Agregat daftar santri per status dari semua kartu kegiatan (untuk popup total hero).
 *
 * @param list<array<string, mixed>> $detailKeg
 * @return array<string, list<array<string, mixed>>>
 */
function rekap_keaktifan_hari_santri_agregat(array $detailKeg): array
{
    $out = ['HADIR' => [], 'IZIN' => [], 'SAKIT' => [], 'ALPA' => []];
    foreach ($detailKeg as $dk) {
        $namaKeg = trim((string) ($dk['nama_kegiatan'] ?? ''));
        $santri = $dk['santri'] ?? [];
        if (!is_array($santri)) {
            continue;
        }
        foreach (array_keys($out) as $st) {
            foreach ($santri[$st] ?? [] as $s) {
                if (!is_array($s)) {
                    continue;
                }
                $entry = $s;
                if ($namaKeg !== '') {
                    $entry['kegiatan'] = $namaKeg;
                }
                $out[$st][] = $entry;
            }
        }
    }

    return $out;
}

/**
 * Keaktifan per tingkatan (kelas): santri unik hadir / total santri aktif.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array{tingkatan:string,masuk:int,total:int,persen:float}>
 */
function rekap_keaktifan_hari_by_tingkatan(array $rows): array
{
    /** @var array<string, array{hadir: array<int, true>, total: array<int, true>}> $map */
    $map = [];
    foreach ($rows as $r) {
        $tk = trim((string) ($r['tingkatan'] ?? ''));
        if ($tk === '') {
            $tk = '-';
        }
        $sid = (int) ($r['santri_id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        if (!isset($map[$tk])) {
            $map[$tk] = ['hadir' => [], 'total' => []];
        }
        $map[$tk]['total'][$sid] = true;
        if (strtoupper((string) ($r['status_hari_ini'] ?? '')) === 'HADIR') {
            $map[$tk]['hadir'][$sid] = true;
        }
    }
    $out = [];
    foreach ($map as $tk => $info) {
        $total = count($info['total']);
        $masuk = count($info['hadir']);
        $out[] = [
            'tingkatan' => $tk,
            'masuk' => $masuk,
            'total' => $total,
            'persen' => $total > 0 ? round(100 * $masuk / $total, 1) : 0.0,
        ];
    }
    usort($out, static fn(array $a, array $b): int => strcmp((string) $a['tingkatan'], (string) $b['tingkatan']));

    return $out;
}

/**
 * Detail santri per tingkatan (kelas) untuk satu hari — hadir vs belum hadir.
 *
 * @param list<array<string, mixed>> $rows
 * @return array{
 *   tingkatan:string,
 *   masuk:int,
 *   total:int,
 *   persen:float,
 *   hadir:list<array<string,mixed>>,
 *   belum:list<array<string,mixed>>
 * }
 */
function rekap_keaktifan_hari_detail_kelas(array $rows, string $tingkatan): array
{
    $tk = trim($tingkatan);
    /** @var array<int, array<string, mixed>> $bySid */
    $bySid = [];
    foreach ($rows as $r) {
        $rTk = trim((string) ($r['tingkatan'] ?? ''));
        if ($rTk === '') {
            $rTk = '-';
        }
        if ($rTk !== $tk) {
            continue;
        }
        $sid = (int) ($r['santri_id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $st = strtoupper((string) ($r['status_hari_ini'] ?? ''));
        if ($st === 'ISTIRAHAT') {
            $st = 'ALPA';
        }
        if (!isset($bySid[$sid])) {
            $bySid[$sid] = [
                'santri_id' => $sid,
                'nama_santri' => (string) ($r['nama_santri'] ?? '-'),
                'nis' => (string) ($r['nis'] ?? ''),
                'hadir' => false,
                'status' => 'ALPA',
            ];
        }
        if ($st === 'HADIR') {
            $bySid[$sid]['hadir'] = true;
            $bySid[$sid]['status'] = 'HADIR';
        } elseif (!$bySid[$sid]['hadir'] && in_array($st, ['IZIN', 'SAKIT', 'ALPA'], true)) {
            $bySid[$sid]['status'] = $st;
        }
    }

    $hadir = [];
    $belum = [];
    foreach ($bySid as $s) {
        if ($s['hadir']) {
            $hadir[] = $s;
        } else {
            $belum[] = $s;
        }
    }
    usort($hadir, static fn(array $a, array $b): int => strcmp((string) $a['nama_santri'], (string) $b['nama_santri']));
    usort($belum, static fn(array $a, array $b): int => strcmp((string) $a['nama_santri'], (string) $b['nama_santri']));

    $total = count($bySid);
    $masuk = count($hadir);

    return [
        'tingkatan' => $tk,
        'masuk' => $masuk,
        'total' => $total,
        'persen' => $total > 0 ? round(100 * $masuk / $total, 1) : 0.0,
        'hadir' => $hadir,
        'belum' => $belum,
    ];
}

/**
 * Santri tidak hadir (ghaib) per kegiatan — untuk drill-down.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function rekap_keaktifan_hari_ghaib_per_kegiatan(array $rows): array
{
    $byKeg = rekap_keaktifan_hari_detail_by_kegiatan($rows);
    foreach ($byKeg as &$kg) {
        $ghaib = [];
        foreach (['ALPA', 'IZIN', 'SAKIT'] as $st) {
            foreach ($kg['santri'][$st] ?? [] as $s) {
                $cat = trim((string) ($s['catatan'] ?? ''));
                $ghaib[] = array_merge($s, [
                    'status' => $st,
                    'keterangan' => $cat !== '' ? $cat : match ($st) {
                        'IZIN' => 'Izin',
                        'SAKIT' => 'Sakit',
                        'ALPA' => 'Alpa (tidak scan hingga akhir kegiatan)',
                        default => 'Alpa',
                    },
                ]);
            }
        }
        $kg['masuk'] = (int) ($kg['hadir'] ?? 0);
        $kg['ghaib'] = $ghaib;
        $kg['ghaib_count'] = count($ghaib);
    }
    unset($kg);

    return $byKeg;
}

/**
 * Presensi SDM hari ini (pembimbing & munawib) dari scan gerbang.
 *
 * @return array{
 *   pembimbing: array{masuk:int,total:int,tidak_hadir:list<array<string,mixed>>},
 *   munawib: array{masuk:int,total:int,tidak_hadir:list<array<string,mixed>>}
 * }
 */
function rekap_keaktifan_hari_sdm(PDO $pdo, string $tanggal): array
{
    require_once __DIR__ . '/entity_list_sort.php';

    $out = [
        'pembimbing' => ['masuk' => 0, 'total' => 0, 'tidak_hadir' => []],
        'munawib' => ['masuk' => 0, 'total' => 0, 'tidak_hadir' => []],
    ];

    $izinPb = [];
    if (table_exists($pdo, 'perizinan_pembimbing')) {
        $st = $pdo->prepare('
            SELECT pembimbing_id, jenis_izin
            FROM perizinan_pembimbing
            WHERE status_izin = "IZIN"
              AND tanggal_mulai <= :t AND tanggal_selesai >= :t
        ');
        $st->execute(['t' => $tanggal]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $pid = (int) ($r['pembimbing_id'] ?? 0);
            $jenis = strtoupper((string) ($r['jenis_izin'] ?? 'IZIN'));
            $izinPb[$pid] = $jenis === 'SAKIT' ? 'Sakit' : 'Izin';
        }
    }

    if (table_exists($pdo, 'pembimbing')) {
        $aktifSql = column_exists($pdo, 'pembimbing', 'is_aktif')
            ? ' WHERE COALESCE(is_aktif, 1) = 1' : '';
        $orderPb = pembimbing_list_order_sql('b');
        $list = $pdo->query("SELECT b.id, b.nama_pembimbing, b.nip FROM pembimbing b{$aktifSql} ORDER BY {$orderPb}")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $hadirIds = [];
        if (table_exists($pdo, 'presensi_pembimbing')) {
            $st = $pdo->prepare('SELECT DISTINCT pembimbing_id FROM presensi_pembimbing WHERE tanggal = :t');
            $st->execute(['t' => $tanggal]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
                $hadirIds[(int) $id] = true;
            }
        }
        $out['pembimbing']['total'] = count($list);
        foreach ($list as $pb) {
            $pid = (int) ($pb['id'] ?? 0);
            if (isset($hadirIds[$pid])) {
                $out['pembimbing']['masuk']++;
                continue;
            }
            $status = $izinPb[$pid] ?? 'Tanpa Keterangan';
            $out['pembimbing']['tidak_hadir'][] = [
                'id' => $pid,
                'nama' => (string) ($pb['nama_pembimbing'] ?? ''),
                'nip' => (string) ($pb['nip'] ?? ''),
                'status' => $status,
            ];
        }
    }

    if (table_exists($pdo, 'munawib')) {
        require_once __DIR__ . '/munawib.php';
        munawib_ensure_schema($pdo);
        $orderMw = munawib_list_order_sql('m');
        $list = $pdo->query('SELECT m.id, m.nama, m.nip FROM munawib m WHERE COALESCE(m.is_aktif,1)=1 ORDER BY ' . $orderMw)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $hadirIds = [];
        if (table_exists($pdo, 'presensi_munawib')) {
            $st = $pdo->prepare('SELECT DISTINCT munawib_id FROM presensi_munawib WHERE tanggal = :t');
            $st->execute(['t' => $tanggal]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) {
                $hadirIds[(int) $id] = true;
            }
        }
        $out['munawib']['total'] = count($list);
        foreach ($list as $mw) {
            $mid = (int) ($mw['id'] ?? 0);
            if (isset($hadirIds[$mid])) {
                $out['munawib']['masuk']++;
                continue;
            }
            $out['munawib']['tidak_hadir'][] = [
                'id' => $mid,
                'nama' => (string) ($mw['nama'] ?? ''),
                'nip' => (string) ($mw['nip'] ?? ''),
                'status' => 'Tanpa Keterangan',
            ];
        }
    }

    return $out;
}

/**
 * Riwayat pembimbing yang sudah melakukan scan hadir hari ini.
 *
 * @return list<array{id:int,nama:string,nip:string,jam:string,kegiatan:string,tingkatan:string,tempat:string}>
 */
function rekap_keaktifan_hari_riwayat_pembimbing_masuk(PDO $pdo, string $tanggal): array
{
    if (!table_exists($pdo, 'presensi_pembimbing') || !table_exists($pdo, 'pembimbing')) {
        return [];
    }
    $sql = '
        SELECT
            b.id,
            b.nama_pembimbing AS nama,
            COALESCE(b.nip, "") AS nip,
            DATE_FORMAT(pp.jam, "%H:%i") AS jam,
            COALESCE(k.nama_kegiatan, "Kegiatan") AS kegiatan,
            COALESCE(j.tingkatan, "-") AS tingkatan,
            COALESCE(j.tempat, "") AS tempat
        FROM presensi_pembimbing pp
        INNER JOIN pembimbing b ON b.id = pp.pembimbing_id
        LEFT JOIN kegiatan k ON k.id = pp.kegiatan_id
        LEFT JOIN jadwal_kegiatan j ON j.kegiatan_id = pp.kegiatan_id
            AND (j.hari_ke = 0 OR j.hari_ke = :hk)
            AND pp.jam BETWEEN j.jam_mulai AND j.jam_selesai
            AND (j.pembimbing_id IS NULL OR j.pembimbing_id = pp.pembimbing_id)
        WHERE pp.tanggal = :t
        ORDER BY pp.jam DESC, b.nama_pembimbing ASC
    ';
    $st = $pdo->prepare($sql);
    $st->execute([
        't' => $tanggal,
        'hk' => (int) date('N', strtotime($tanggal)),
    ]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Deteksi kegiatan terjadwal yang kosong:
 * - santri hadir = 0, atau
 * - pembimbing & munawib sama-sama tidak hadir.
 *
 * @return list<array<string,mixed>>
 */
function rekap_keaktifan_hari_kegiatan_kosong(PDO $pdo, string $tanggal, ?string $tingkatanFilter = null, ?string $kategoriKegiatan = null): array
{
    if (!table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan')) {
        return [];
    }

    $hariKe = (int) date('N', strtotime($tanggal));
    $params = ['hk' => $hariKe];
    $whereTk = '';
    if ($tingkatanFilter !== null && $tingkatanFilter !== '') {
        $whereTk = ' AND j.tingkatan = :tk';
        $params['tk'] = $tingkatanFilter;
    }
    $katNorm = rekap_keaktifan_hari_normalize_kategori($kategoriKegiatan);
    $whereKat = '';
    if ($katNorm !== null) {
        $whereKat = ' AND COALESCE(k.kategori_kegiatan, "TAALIM") = :kat';
        $params['kat'] = $katNorm;
    }

    $jadwalSql = '
        SELECT
            j.id AS jadwal_id,
            j.kegiatan_id,
            j.tingkatan,
            j.jam_mulai,
            j.jam_selesai,
            COALESCE(j.tempat, "") AS tempat,
            COALESCE(k.nama_kegiatan, "Kegiatan") AS nama_kegiatan,
            COALESCE(k.kategori_kegiatan, "TAALIM") AS kategori_kegiatan,
            COALESCE(p.nama_pembimbing, "-") AS nama_pembimbing,
            COALESCE(j.pembimbing_id, 0) AS pembimbing_id
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
        LEFT JOIN pembimbing p ON p.id = j.pembimbing_id
        WHERE k.is_active = 1
          AND (j.hari_ke = 0 OR j.hari_ke = :hk)
          ' . $whereTk . '
          ' . $whereKat . '
        ORDER BY j.jam_mulai ASC, k.nama_kegiatan ASC, j.tingkatan ASC
    ';
    $st = $pdo->prepare($jadwalSql);
    $st->execute($params);
    $jadwal = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($jadwal === []) {
        return [];
    }

    $aktifSql = santri_sql_aktif_only('s');
    $santriTotalSt = $pdo->prepare('SELECT COUNT(*) FROM santri s WHERE ' . $aktifSql . ' AND s.tingkatan = :tk');
    $santriHadirSt = $pdo->prepare('
        SELECT COUNT(DISTINCT p.santri_id)
        FROM presensi p
        INNER JOIN santri s ON s.id = p.santri_id
        WHERE p.tanggal_presensi = :t
          AND p.kegiatan_id = :k
          AND p.status_presensi = "HADIR"
          AND ' . $aktifSql . '
          AND s.tingkatan = :tk
    ');
    $pbHadirSt = $pdo->prepare('
        SELECT 1
        FROM presensi_pembimbing
        WHERE tanggal = :t
          AND kegiatan_id = :k
          AND (:pb = 0 OR pembimbing_id = :pb)
        LIMIT 1
    ');
    $mwHadirSt = $pdo->prepare('
        SELECT 1
        FROM presensi_munawib
        WHERE tanggal = :t AND kegiatan_id = :k
        LIMIT 1
    ');

    $out = [];
    foreach ($jadwal as $j) {
        $tk = trim((string) ($j['tingkatan'] ?? ''));
        if ($tk === '') {
            continue;
        }
        $kid = (int) ($j['kegiatan_id'] ?? 0);
        if ($kid <= 0) {
            continue;
        }
        $pbId = (int) ($j['pembimbing_id'] ?? 0);

        $santriTotalSt->execute(['tk' => $tk]);
        $santriTotal = (int) $santriTotalSt->fetchColumn();

        $santriHadirSt->execute(['t' => $tanggal, 'k' => $kid, 'tk' => $tk]);
        $santriHadir = (int) $santriHadirSt->fetchColumn();

        $pbHadir = false;
        if (table_exists($pdo, 'presensi_pembimbing')) {
            $pbHadirSt->execute(['t' => $tanggal, 'k' => $kid, 'pb' => $pbId]);
            $pbHadir = (bool) $pbHadirSt->fetchColumn();
        }
        $mwHadir = false;
        if (table_exists($pdo, 'presensi_munawib')) {
            $mwHadirSt->execute(['t' => $tanggal, 'k' => $kid]);
            $mwHadir = (bool) $mwHadirSt->fetchColumn();
        }

        $reasons = [];
        if ($santriHadir === 0 && $santriTotal > 0) {
            $reasons[] = 'Tidak ada santri scan';
        }
        if (!$pbHadir && !$mwHadir) {
            $reasons[] = 'Pembimbing & munawib belum hadir';
        }
        if ($reasons === []) {
            continue;
        }

        $out[] = [
            'jadwal_id' => (int) ($j['jadwal_id'] ?? 0),
            'kegiatan_id' => $kid,
            'nama_kegiatan' => (string) ($j['nama_kegiatan'] ?? 'Kegiatan'),
            'tingkatan' => $tk,
            'jam_mulai' => substr((string) ($j['jam_mulai'] ?? ''), 0, 5),
            'jam_selesai' => substr((string) ($j['jam_selesai'] ?? ''), 0, 5),
            'tempat' => trim((string) ($j['tempat'] ?? '')),
            'nama_pembimbing' => (string) ($j['nama_pembimbing'] ?? '-'),
            'santri_hadir' => $santriHadir,
            'santri_total' => $santriTotal,
            'pembimbing_hadir' => $pbHadir,
            'munawib_hadir' => $mwHadir,
            'reasons' => $reasons,
        ];
    }

    return $out;
}