<?php

declare(strict_types=1);

require_once __DIR__ . '/rekap_periode.php';
require_once __DIR__ . '/yayasan_portal.php';
require_once __DIR__ . '/santri_operasional.php';

function yayasan_kesehatan_status_label(string $status): string
{
    return match (strtoupper(trim($status))) {
        'RAWAT_PONDOK' => 'Rawat di pondok',
        'DIRUJUK_RS' => 'Dirujuk RS',
        'ISOLASI' => 'Isolasi',
        'SELESAI' => 'Selesai',
        default => $status !== '' ? $status : '—',
    };
}

function yayasan_kesehatan_hitung_hari_overlap(string $mulai, string $selesai, string $periodeMulai, string $periodeSelesai): int
{
    $a = max(strtotime($mulai) ?: 0, strtotime($periodeMulai) ?: 0);
    $b = min(strtotime($selesai) ?: 0, strtotime($periodeSelesai) ?: 0);
    if ($a <= 0 || $b <= 0 || $a > $b) {
        return 0;
    }

    return (int) floor(($b - $a) / 86400) + 1;
}

/**
 * @return array{sql:string, params:array<string, mixed>}
 */
function yayasan_kesehatan_izin_sakit_sql(PDO $pdo, string $startDate, string $endDate, string $tingkatan = ''): array
{
    $approvalSql = '';
    if (column_exists($pdo, 'perizinan', 'approval_status')) {
        $approvalSql = ' AND i.approval_status = "DISETUJUI"';
    }
    $aktifSql = santri_sql_aktif_only('s');
    $params = [
        'start_date' => $startDate,
        'end_date' => $endDate,
    ];
    $tingkatanSql = '';
    if ($tingkatan !== '') {
        $tingkatanSql = ' AND s.tingkatan = :tingkatan';
        $params['tingkatan'] = $tingkatan;
    }

    $sql = "
        SELECT
            i.id,
            i.santri_id,
            i.tanggal_mulai,
            i.tanggal_selesai,
            i.jam_mulai,
            i.jam_selesai,
            i.alasan,
            i.status_izin,
            i.created_at,
            s.nama_santri,
            s.nis,
            s.tingkatan
        FROM perizinan i
        INNER JOIN santri s ON s.id = i.santri_id AND {$aktifSql}
        WHERE UPPER(TRIM(i.jenis_izin)) = 'SAKIT'
          AND i.status_izin = 'IZIN'
          AND i.tanggal_selesai >= :start_date
          AND i.tanggal_mulai <= :end_date
          {$approvalSql}
          {$tingkatanSql}
        ORDER BY i.tanggal_mulai DESC, s.nama_santri ASC
    ";

    return ['sql' => $sql, 'params' => $params];
}

/**
 * Data laporan kesehatan yayasan berbasis izin sakit & E-Health.
 *
 * @param array<string, mixed> $get mode, month, year, tingkatan
 * @return array<string, mixed>
 */
function yayasan_kesehatan_pack(PDO $pdo, array $get): array
{
    $empty = [
        'ready' => false,
        'mode' => 'hijriyah',
        'month' => 1,
        'year' => 1400,
        'start_date' => '',
        'end_date' => '',
        'periode_label' => '',
        'tingkatan' => '',
        'hijri_months' => hijri_nama_bulan_list(),
        'tingkatan_list' => [],
        'summary' => [
            'total_kasus' => 0,
            'total_santri' => 0,
            'total_hari_sakit' => 0,
            'rata_hari_per_santri' => 0.0,
            'sakit_aktif_hari_ini' => 0,
            'ehealth_records' => 0,
            'suhu_tinggi' => 0,
        ],
        'chart_tingkatan' => ['labels' => [], 'kasus' => [], 'hari' => []],
        'chart_bulan' => ['labels' => [], 'kasus' => [], 'santri' => []],
        'chart_status' => ['labels' => [], 'values' => []],
        'chart_suhu' => ['labels' => [], 'values' => []],
        'per_tingkatan' => [],
        'per_santri' => [],
        'detail_rows' => [],
        'aktif_hari_ini' => [],
        'ehealth_rows' => [],
        'gejala_top' => [],
    ];

    if (!table_exists($pdo, 'perizinan') || !table_exists($pdo, 'santri')) {
        return $empty;
    }

    $periode = rekap_resolve_periode($pdo, [
        'mode' => (string) ($get['mode'] ?? 'hijriyah'),
        'month' => $get['month'] ?? null,
        'year' => $get['year'] ?? null,
    ]);
    $startDate = (string) $periode['start_date'];
    $endDate = (string) $periode['end_date'];
    $tingkatan = trim((string) ($get['tingkatan'] ?? ''));

    $bundle = yayasan_kesehatan_izin_sakit_sql($pdo, $startDate, $endDate, $tingkatan);
    $st = $pdo->prepare($bundle['sql']);
    $st->execute($bundle['params']);
    $izinRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $ehealthBySantriDate = [];
    if (table_exists($pdo, 'ehealth_records')) {
        $ehSt = $pdo->prepare('
            SELECT e.*, s.nama_santri, s.nis, s.tingkatan
            FROM ehealth_records e
            INNER JOIN santri s ON s.id = e.santri_id AND ' . santri_sql_aktif_only('s') . '
            WHERE DATE(e.created_at) BETWEEN :start_date AND :end_date
            ORDER BY e.created_at DESC
        ');
        $ehSt->execute(['start_date' => $startDate, 'end_date' => $endDate]);
        foreach ($ehSt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $eh) {
            $sid = (int) ($eh['santri_id'] ?? 0);
            $tgl = substr((string) ($eh['created_at'] ?? ''), 0, 10);
            $ehealthBySantriDate[$sid . '|' . $tgl] = $eh;
            if (!isset($ehealthBySantriDate[(string) $sid])) {
                $ehealthBySantriDate[(string) $sid] = $eh;
            }
        }
    }

    $detailRows = [];
    $perSantri = [];
    $perTingkatan = [];
    $santriUnik = [];
    $totalHari = 0;

    foreach ($izinRows as $row) {
        $sid = (int) ($row['santri_id'] ?? 0);
        $tg = trim((string) ($row['tingkatan'] ?? '')) ?: '—';
        $mulai = (string) ($row['tanggal_mulai'] ?? '');
        $selesai = (string) ($row['tanggal_selesai'] ?? '');
        $hari = yayasan_kesehatan_hitung_hari_overlap($mulai, $selesai, $startDate, $endDate);
        $totalHari += $hari;
        $santriUnik[$sid] = true;

        $eh = $ehealthBySantriDate[$sid . '|' . $mulai] ?? $ehealthBySantriDate[(string) $sid] ?? null;
        $detail = $row;
        $detail['hari_efektif'] = $hari;
        $detail['gejala'] = (string) ($eh['gejala'] ?? '');
        $detail['suhu_tubuh'] = $eh['suhu_tubuh'] ?? null;
        $detail['status_kesehatan'] = (string) ($eh['status_kesehatan'] ?? '');
        $detail['tindakan'] = (string) ($eh['tindakan'] ?? '');
        $detailRows[] = $detail;

        if (!isset($perSantri[$sid])) {
            $perSantri[$sid] = [
                'santri_id' => $sid,
                'nama_santri' => (string) ($row['nama_santri'] ?? ''),
                'nis' => (string) ($row['nis'] ?? ''),
                'tingkatan' => $tg,
                'kasus' => 0,
                'hari_sakit' => 0,
            ];
        }
        $perSantri[$sid]['kasus']++;
        $perSantri[$sid]['hari_sakit'] += $hari;

        if (!isset($perTingkatan[$tg])) {
            $perTingkatan[$tg] = ['tingkatan' => $tg, 'kasus' => 0, 'hari_sakit' => 0, 'santri' => []];
        }
        $perTingkatan[$tg]['kasus']++;
        $perTingkatan[$tg]['hari_sakit'] += $hari;
        $perTingkatan[$tg]['santri'][$sid] = true;
    }

    usort($perSantri, static function (array $a, array $b): int {
        $cmp = ($b['hari_sakit'] ?? 0) <=> ($a['hari_sakit'] ?? 0);
        return $cmp !== 0 ? $cmp : strcmp((string) ($a['nama_santri'] ?? ''), (string) ($b['nama_santri'] ?? ''));
    });
    $perSantriList = array_values($perSantri);

    $perTingkatanList = [];
    foreach ($perTingkatan as $tg => $item) {
        $perTingkatanList[] = [
            'tingkatan' => $tg,
            'kasus' => (int) $item['kasus'],
            'hari_sakit' => (int) $item['hari_sakit'],
            'jumlah_santri' => count($item['santri']),
        ];
    }
    usort($perTingkatanList, static fn(array $a, array $b): int => ($b['hari_sakit'] ?? 0) <=> ($a['hari_sakit'] ?? 0));

    $chartTingkatanLabels = [];
    $chartTingkatanKasus = [];
    $chartTingkatanHari = [];
    foreach ($perTingkatanList as $pt) {
        $chartTingkatanLabels[] = (string) $pt['tingkatan'];
        $chartTingkatanKasus[] = (int) $pt['kasus'];
        $chartTingkatanHari[] = (int) $pt['hari_sakit'];
    }

    $chartBulanLabels = [];
    $chartBulanKasus = [];
    $chartBulanSantri = [];
    $anchorTs = strtotime($endDate) ?: time();
    for ($i = 5; $i >= 0; $i--) {
        $monthStart = date('Y-m-01', strtotime('-' . $i . ' months', $anchorTs));
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $chartBulanLabels[] = date('M y', strtotime($monthStart));
        $monthBundle = yayasan_kesehatan_izin_sakit_sql($pdo, $monthStart, $monthEnd, $tingkatan);
        $mSt = $pdo->prepare($monthBundle['sql']);
        $mSt->execute($monthBundle['params']);
        $monthRows = $mSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $monthSantri = [];
        foreach ($monthRows as $mr) {
            $monthSantri[(int) ($mr['santri_id'] ?? 0)] = true;
        }
        $chartBulanKasus[] = count($monthRows);
        $chartBulanSantri[] = count($monthSantri);
    }

    $statusCounts = [
        'RAWAT_PONDOK' => 0,
        'DIRUJUK_RS' => 0,
        'ISOLASI' => 0,
        'SELESAI' => 0,
    ];
    $suhuBuckets = ['<37' => 0, '37-37.9' => 0, '38-38.9' => 0, '≥39' => 0, 'Tidak diisi' => 0];
    $gejalaFreq = [];
    $ehealthRows = [];
    $suhuTinggi = 0;

    if (table_exists($pdo, 'ehealth_records')) {
        $ehAll = $pdo->prepare('
            SELECT e.*, s.nama_santri, s.nis, s.tingkatan
            FROM ehealth_records e
            INNER JOIN santri s ON s.id = e.santri_id AND ' . santri_sql_aktif_only('s') . '
            WHERE DATE(e.created_at) BETWEEN :start_date AND :end_date
            ORDER BY e.created_at DESC
        ');
        $ehAll->execute(['start_date' => $startDate, 'end_date' => $endDate]);
        $ehealthRows = $ehAll->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($ehealthRows as $eh) {
            $stRaw = strtoupper((string) ($eh['status_kesehatan'] ?? 'RAWAT_PONDOK'));
            if (isset($statusCounts[$stRaw])) {
                $statusCounts[$stRaw]++;
            }
            $suhu = $eh['suhu_tubuh'] ?? null;
            if ($suhu === null || $suhu === '') {
                $suhuBuckets['Tidak diisi']++;
            } else {
                $sv = (float) $suhu;
                if ($sv >= 39) {
                    $suhuBuckets['≥39']++;
                    $suhuTinggi++;
                } elseif ($sv >= 38) {
                    $suhuBuckets['38-38.9']++;
                    $suhuTinggi++;
                } elseif ($sv >= 37) {
                    $suhuBuckets['37-37.9']++;
                } else {
                    $suhuBuckets['<37']++;
                }
            }
            $gejala = trim((string) ($eh['gejala'] ?? ''));
            if ($gejala !== '') {
                $key = mb_strtolower($gejala);
                $gejalaFreq[$key] = ($gejalaFreq[$key] ?? 0) + 1;
            }
        }
    }

    arsort($gejalaFreq);
    $gejalaTop = [];
    foreach (array_slice($gejalaFreq, 0, 8, true) as $g => $n) {
        $gejalaTop[] = ['gejala' => $g, 'jumlah' => $n];
    }

    $chartStatusLabels = [];
    $chartStatusValues = [];
    foreach ($statusCounts as $code => $n) {
        if ($n <= 0) {
            continue;
        }
        $chartStatusLabels[] = yayasan_kesehatan_status_label($code);
        $chartStatusValues[] = $n;
    }

    $aktifHariIni = yayasan_ketertiban_sakit_perlu($pdo);
    if ($tingkatan !== '') {
        $aktifHariIni = array_values(array_filter($aktifHariIni, static function (array $r) use ($tingkatan): bool {
            return strtolower((string) ($r['tingkatan'] ?? '')) === strtolower($tingkatan);
        }));
    }

    $tingkatanList = [];
    if (table_exists($pdo, 'tingkatan')) {
        $tingkatanList = $pdo->query('SELECT nama_tingkatan FROM tingkatan ORDER BY nama_tingkatan ASC')->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    $totalSantri = count($santriUnik);

    return [
        'ready' => true,
        'mode' => (string) $periode['mode'],
        'month' => (int) $periode['month'],
        'year' => (int) $periode['year'],
        'start_date' => $startDate,
        'end_date' => $endDate,
        'periode_label' => (string) $periode['label'],
        'hijri_label' => (string) ($periode['hijri_label'] ?? $periode['label']),
        'rentang_tampilan' => (string) ($periode['rentang_tampilan'] ?? ''),
        'tingkatan' => $tingkatan,
        'hijri_months' => hijri_nama_bulan_list(),
        'tingkatan_list' => $tingkatanList,
        'summary' => [
            'total_kasus' => count($izinRows),
            'total_santri' => $totalSantri,
            'total_hari_sakit' => $totalHari,
            'rata_hari_per_santri' => $totalSantri > 0 ? round($totalHari / $totalSantri, 1) : 0.0,
            'sakit_aktif_hari_ini' => count($aktifHariIni),
            'ehealth_records' => count($ehealthRows),
            'suhu_tinggi' => $suhuTinggi,
        ],
        'chart_tingkatan' => [
            'labels' => $chartTingkatanLabels,
            'kasus' => $chartTingkatanKasus,
            'hari' => $chartTingkatanHari,
        ],
        'chart_bulan' => [
            'labels' => $chartBulanLabels,
            'kasus' => $chartBulanKasus,
            'santri' => $chartBulanSantri,
        ],
        'chart_status' => [
            'labels' => $chartStatusLabels,
            'values' => $chartStatusValues,
        ],
        'chart_suhu' => [
            'labels' => array_keys($suhuBuckets),
            'values' => array_values($suhuBuckets),
        ],
        'per_tingkatan' => $perTingkatanList,
        'per_santri' => $perSantriList,
        'detail_rows' => $detailRows,
        'aktif_hari_ini' => $aktifHariIni,
        'ehealth_rows' => $ehealthRows,
        'gejala_top' => $gejalaTop,
    ];
}
