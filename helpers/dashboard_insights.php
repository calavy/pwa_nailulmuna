<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/santri_operasional.php';

/**
 * Format badge tren KPI.
 *
 * @return array{show:bool,direction:string,label:string}|null
 */
function dashboard_insights_trend(?int $delta, string $positiveLabel, ?string $compareLabel = null): ?array
{
    if ($delta === null) {
        return null;
    }
    if ($delta === 0) {
        return [
            'show' => true,
            'direction' => 'flat',
            'label' => $compareLabel !== null ? 'sama ' . $compareLabel : 'stabil',
        ];
    }
    $sign = $delta > 0 ? '+' : '';
    $label = $sign . $delta;
    if ($compareLabel !== null) {
        $label .= ' ' . $compareLabel;
    } else {
        $label .= ' ' . $positiveLabel;
    }

    return [
        'show' => true,
        'direction' => $delta > 0 ? 'up' : 'down',
        'label' => $label,
    ];
}

/**
 * Tren kartu KPI dashboard admin.
 *
 * @return array{putra:?array,putri:?array,mukimin:?array,izin:?array}
 */
function dashboard_kpi_trends(PDO $pdo, string $today): array
{
    $out = ['putra' => null, 'putri' => null, 'mukimin' => null, 'izin' => null];
    $bulanAwal = date('Y-m-01', strtotime($today));
    $bulanAkhir = date('Y-m-t', strtotime($today));
    $bulanLaluAwal = date('Y-m-01', strtotime($bulanAwal . ' -1 month'));
    $bulanLaluAkhir = date('Y-m-t', strtotime($bulanLaluAwal));

    if (table_exists($pdo, 'santri')
        && column_exists($pdo, 'santri', 'tanggal_masuk')
        && column_exists($pdo, 'santri', 'jenis_kelamin')) {
        $aktifWhere = '';
        if (column_exists($pdo, 'santri', 'status_santri')) {
            $aktifWhere = ' AND ' . santri_sql_aktif_only('santri');
        } elseif (column_exists($pdo, 'santri', 'is_aktif')) {
            $aktifWhere = ' AND COALESCE(santri.is_aktif, 1) = 1';
        }
        $st = $pdo->prepare('
            SELECT
                SUM(CASE WHEN TRIM(jenis_kelamin) = "Laki-laki" AND tanggal_masuk BETWEEN :a1 AND :a2 THEN 1 ELSE 0 END) AS putra_ini,
                SUM(CASE WHEN TRIM(jenis_kelamin) = "Perempuan" AND tanggal_masuk BETWEEN :a3 AND :a4 THEN 1 ELSE 0 END) AS putri_ini,
                SUM(CASE WHEN TRIM(jenis_kelamin) = "Laki-laki" AND tanggal_masuk BETWEEN :b1 AND :b2 THEN 1 ELSE 0 END) AS putra_lalu,
                SUM(CASE WHEN TRIM(jenis_kelamin) = "Perempuan" AND tanggal_masuk BETWEEN :b3 AND :b4 THEN 1 ELSE 0 END) AS putri_lalu
            FROM santri
            WHERE tanggal_masuk IS NOT NULL AND TRIM(tanggal_masuk) <> ""' . $aktifWhere
        );
        $st->execute([
            'a1' => $bulanAwal, 'a2' => $bulanAkhir,
            'a3' => $bulanAwal, 'a4' => $bulanAkhir,
            'b1' => $bulanLaluAwal, 'b2' => $bulanLaluAkhir,
            'b3' => $bulanLaluAwal, 'b4' => $bulanLaluAkhir,
        ]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $putraIni = (int) ($row['putra_ini'] ?? 0);
        $putriIni = (int) ($row['putri_ini'] ?? 0);
        if ($putraIni > 0) {
            $out['putra'] = dashboard_insights_trend($putraIni, 'bulan ini');
        } elseif ((int) ($row['putra_lalu'] ?? 0) > 0) {
            $out['putra'] = dashboard_insights_trend(0, 'bulan ini', 'vs bulan lalu');
        }
        if ($putriIni > 0) {
            $out['putri'] = dashboard_insights_trend($putriIni, 'bulan ini');
        } elseif ((int) ($row['putri_lalu'] ?? 0) > 0) {
            $out['putri'] = dashboard_insights_trend(0, 'bulan ini', 'vs bulan lalu');
        }
    }

    if (table_exists($pdo, 'akademik_alumni') && column_exists($pdo, 'akademik_alumni', 'created_at')) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM akademik_alumni WHERE DATE(created_at) BETWEEN :a AND :b');
        $st->execute(['a' => $bulanAwal, 'b' => $bulanAkhir]);
        $cnt = (int) $st->fetchColumn();
        if ($cnt > 0) {
            $out['mukimin'] = dashboard_insights_trend($cnt, 'bulan ini');
        }
    }

    if (table_exists($pdo, 'perizinan') && table_exists($pdo, 'santri')) {
        $approvalSql = column_exists($pdo, 'perizinan', 'approval_status')
            ? ' AND i.approval_status = "DISETUJUI"' : '';
        $sqlAktif = santri_sql_aktif_only('s');
        $countIzin = static function (PDO $pdo, string $tgl) use ($sqlAktif, $approvalSql): int {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM perizinan i
                 INNER JOIN santri s ON s.id = i.santri_id AND ' . $sqlAktif . '
                 WHERE i.status_izin = "IZIN"
                   AND :t BETWEEN i.tanggal_mulai AND i.tanggal_selesai' . $approvalSql
            );
            $st->execute(['t' => $tgl]);

            return (int) $st->fetchColumn();
        };
        $kemarin = date('Y-m-d', strtotime($today . ' -1 day'));
        $delta = $countIzin($pdo, $today) - $countIzin($pdo, $kemarin);
        $out['izin'] = dashboard_insights_trend($delta, '', 'vs kemarin');
    }

    return $out;
}

/**
 * Tren KPI dashboard pembimbing (konteks kelas).
 *
 * @param list<string> $tingkatanAsuhan
 * @return array{santri:?array,izin:?array,hadir:?array,alpa:?array}
 */
function dashboard_pembimbing_kpi_trends(
    PDO $pdo,
    string $today,
    array $tingkatanAsuhan,
    array $statPresensi
): array {
    $out = ['santri' => null, 'izin' => null, 'hadir' => null, 'alpa' => null];
    if ($tingkatanAsuhan === []) {
        return $out;
    }

    $bulanAwal = date('Y-m-01', strtotime($today));
    $bulanAkhir = date('Y-m-t', strtotime($today));
    if (table_exists($pdo, 'santri')
        && column_exists($pdo, 'santri', 'tanggal_masuk')) {
        $ph = implode(',', array_fill(0, count($tingkatanAsuhan), '?'));
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM santri
             WHERE tanggal_masuk BETWEEN ? AND ?
               AND tingkatan IN (' . $ph . ')'
        );
        $params = array_merge([$bulanAwal, $bulanAkhir], $tingkatanAsuhan);
        $st->execute($params);
        $cnt = (int) $st->fetchColumn();
        if ($cnt > 0) {
            $out['santri'] = dashboard_insights_trend($cnt, 'baru bulan ini');
        }
    }

    if (table_exists($pdo, 'perizinan') && table_exists($pdo, 'santri')) {
        $approvalSql = column_exists($pdo, 'perizinan', 'approval_status')
            ? ' AND i.approval_status = "DISETUJUI"' : '';
        $ph = implode(',', array_fill(0, count($tingkatanAsuhan), '?'));
        $countFn = static function (PDO $pdo, string $tgl) use ($ph, $tingkatanAsuhan, $approvalSql): int {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM perizinan i
                 INNER JOIN santri s ON s.id = i.santri_id
                 WHERE i.status_izin = "IZIN"
                   AND ? BETWEEN i.tanggal_mulai AND i.tanggal_selesai
                   AND s.tingkatan IN (' . $ph . ')' . $approvalSql
            );
            $params = array_merge([$tgl], $tingkatanAsuhan);
            $st->execute($params);

            return (int) $st->fetchColumn();
        };
        $kemarin = date('Y-m-d', strtotime($today . ' -1 day'));
        $out['izin'] = dashboard_insights_trend($countFn($pdo, $today) - $countFn($pdo, $kemarin), '', 'vs kemarin');
    }

    $hadir = (int) ($statPresensi['hadir'] ?? 0);
    $alpa = (int) ($statPresensi['alpa'] ?? 0);
    if ($hadir > 0) {
        $out['hadir'] = [
            'show' => true,
            'direction' => 'up',
            'label' => 'hari ini',
        ];
    }
    if ($alpa > 0) {
        $out['alpa'] = [
            'show' => true,
            'direction' => 'down',
            'label' => 'perlu tindak',
        ];
    }

    return $out;
}

/**
 * Data panel idle saat tidak ada kegiatan berlangsung.
 *
 * @param list<string>|null $tingkatanFilter
 * @return array{
 *   agenda:list<array<string,mixed>>,
 *   presensi:array<string,mixed>,
 *   jadwal_berikutnya:list<array<string,mixed>>
 * }
 */
function dashboard_idle_panel_data(
    PDO $pdo,
    string $today,
    string $nowTime,
    ?array $tingkatanFilter = null
): array {
    $out = [
        'agenda' => [],
        'presensi' => [],
        'jadwal_berikutnya' => [],
    ];

    if (!function_exists('akademik_agenda_for_date')) {
        require_once __DIR__ . '/kalender_agenda.php';
    }
    if (table_exists($pdo, 'akademik_agenda')) {
        $agendaAll = akademik_agenda_for_date($pdo, $today);
        foreach ($agendaAll as $ag) {
            if (!empty($ag['selesai'])) {
                continue;
            }
            $prio = strtolower(trim((string) ($ag['prioritas'] ?? 'sedang')));
            if (!in_array($prio, ['tinggi', 'sedang', 'rendah'], true)) {
                $prio = 'sedang';
            }
            $out['agenda'][] = $ag + ['_prio' => $prio];
        }
        usort($out['agenda'], static function (array $a, array $b): int {
            $order = ['tinggi' => 0, 'sedang' => 1, 'rendah' => 2];
            $pa = $order[$a['_prio'] ?? 'sedang'] ?? 1;
            $pb = $order[$b['_prio'] ?? 'sedang'] ?? 1;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            return strcmp((string) ($a['jam_mulai'] ?? ''), (string) ($b['jam_mulai'] ?? ''));
        });
        $out['agenda'] = array_slice($out['agenda'], 0, 3);
    }

    if (!function_exists('yayasan_dashboard_presensi_hari')) {
        require_once __DIR__ . '/yayasan_dashboard.php';
    }
    $out['presensi'] = yayasan_dashboard_presensi_hari($pdo, $today);

    if (table_exists($pdo, 'jadwal_kegiatan') && table_exists($pdo, 'kegiatan')) {
        ensure_kegiatan_kategori_column($pdo);
        $hariKe = (int) date('N', strtotime($today));
        $whereTk = '';
        $params = [$hariKe, $nowTime];
        if ($tingkatanFilter !== null && $tingkatanFilter !== []) {
            $ph = implode(',', array_fill(0, count($tingkatanFilter), '?'));
            $whereTk = ' AND j.tingkatan IN (' . $ph . ')';
            foreach ($tingkatanFilter as $tk) {
                $params[] = $tk;
            }
        }
        $liburFilterSql = akademik_libur_dashboard_filter_sql($pdo, $today);
        $st = $pdo->prepare(
            'SELECT k.nama_kegiatan, j.tingkatan, j.jam_mulai, j.jam_selesai, COALESCE(j.tempat, "") AS tempat
             FROM jadwal_kegiatan j
             INNER JOIN kegiatan k ON k.id = j.kegiatan_id AND k.is_active = 1
             WHERE (j.hari_ke = 0 OR j.hari_ke = ?)
               AND j.jam_mulai > ?' . $whereTk . $liburFilterSql . '
             ORDER BY j.jam_mulai ASC
             LIMIT 3'
        );
        $st->execute($params);
        $out['jadwal_berikutnya'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    return $out;
}
