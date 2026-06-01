<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_dashboard.php';
require_once __DIR__ . '/keuangan_neraca.php';
require_once __DIR__ . '/keuangan_aruskas.php';
require_once __DIR__ . '/keuangan_typography.php';
require_once __DIR__ . '/pondok_kalender.php';
require_once __DIR__ . '/santri_operasional.php';
require_once __DIR__ . '/santri_status.php';
require_once __DIR__ . '/dashboard_stats.php';
require_once __DIR__ . '/mukimin.php';
require_once __DIR__ . '/entity_list_sort.php';

/**
 * KPI pembimbing & munawib (periode masehi).
 *
 * @return array<string, mixed>
 */
function yayasan_dashboard_sdm_kpi(PDO $pdo, string $dari, string $sampai): array
{
    require_once __DIR__ . '/munawib.php';
    munawib_ensure_schema($pdo);

    $out = [
        'pembimbing_total' => 0,
        'pembimbing_scan_bulan' => 0,
        'pembimbing_yang_hadir' => 0,
        'munawib_total' => 0,
        'munawib_scan_bulan' => 0,
        'munawib_yang_hadir' => 0,
        'penugasan_aktif' => 0,
        'top_pembimbing' => [],
        'top_munawib' => [],
    ];

    if (table_exists($pdo, 'pembimbing')) {
        $aktifSql = column_exists($pdo, 'pembimbing', 'is_aktif')
            ? ' WHERE COALESCE(is_aktif, 1) = 1' : '';
        $out['pembimbing_total'] = (int) $pdo->query('SELECT COUNT(*) FROM pembimbing' . $aktifSql)->fetchColumn();
    }

    if (table_exists($pdo, 'presensi_pembimbing') && table_exists($pdo, 'pembimbing')) {
        $orderPb = pembimbing_list_order_sql('b');
        $rows = $pdo->prepare("
            SELECT b.id, b.nama_pembimbing, b.nip, COUNT(pp.id) AS total_hadir
            FROM pembimbing b
            LEFT JOIN presensi_pembimbing pp
              ON pp.pembimbing_id = b.id AND pp.tanggal BETWEEN :d AND :s
            GROUP BY b.id, b.nama_pembimbing, b.nip
            ORDER BY total_hadir DESC, {$orderPb}
            LIMIT 5
        ");
        $rows->execute(['d' => $dari, 's' => $sampai]);
        $list = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($list as $r) {
            $h = (int) ($r['total_hadir'] ?? 0);
            $out['pembimbing_scan_bulan'] += $h;
            if ($h > 0) {
                $out['pembimbing_yang_hadir']++;
            }
        }
        $out['top_pembimbing'] = array_slice($list, 0, 3);
    }

    if (table_exists($pdo, 'munawib')) {
        $out['munawib_total'] = (int) $pdo->query('SELECT COUNT(*) FROM munawib WHERE COALESCE(is_aktif, 1) = 1')->fetchColumn();
    }

    if (table_exists($pdo, 'munawib_penugasan')) {
        $st = $pdo->prepare('
            SELECT COUNT(*) FROM munawib_penugasan
            WHERE status = "AKTIF" AND tanggal_mulai <= :t AND tanggal_selesai >= :t
        ');
        $st->execute(['t' => date('Y-m-d')]);
        $out['penugasan_aktif'] = (int) $st->fetchColumn();
    }

    if (table_exists($pdo, 'presensi_munawib') && table_exists($pdo, 'munawib')) {
        $orderMw = munawib_list_order_sql('m');
        $rows = $pdo->prepare("
            SELECT m.id, m.nama, m.nip, COUNT(pm.id) AS total_hadir
            FROM munawib m
            LEFT JOIN presensi_munawib pm
              ON pm.munawib_id = m.id AND pm.tanggal BETWEEN :d AND :s
            WHERE COALESCE(m.is_aktif, 1) = 1
            GROUP BY m.id, m.nama, m.nip
            ORDER BY total_hadir DESC, {$orderMw}
            LIMIT 5
        ");
        $rows->execute(['d' => $dari, 's' => $sampai]);
        $list = $rows->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($list as $r) {
            $h = (int) ($r['total_hadir'] ?? 0);
            $out['munawib_scan_bulan'] += $h;
            if ($h > 0) {
                $out['munawib_yang_hadir']++;
            }
        }
        $out['top_munawib'] = array_slice($list, 0, 3);
    }

    return $out;
}

/**
 * KPI poin santri bulan berjalan.
 *
 * @return array<string, mixed>
 */
function yayasan_dashboard_poin_kpi(PDO $pdo, int $month, int $year): array
{
    if (function_exists('ensure_point_tables')) {
        ensure_point_tables($pdo);
    }

    $out = [
        'ambang_min' => function_exists('poin_ambang_sanksi_minimum') ? poin_ambang_sanksi_minimum($pdo) : 10,
        'perlu_tindakan' => 0,
        'entri_bulan' => 0,
        'santri_dapat_poin' => 0,
        'top_poin' => [],
        'periode_label' => sprintf('%02d/%d', $month, $year),
    ];

    if (!table_exists($pdo, 'point_ledger')) {
        return $out;
    }

    $start = sprintf('%04d-%02d-01', $year, $month);
    $end = date('Y-m-t', strtotime($start));

    $st = $pdo->prepare('SELECT COUNT(*) FROM point_ledger WHERE tanggal BETWEEN :a AND :b');
    $st->execute(['a' => $start, 'b' => $end]);
    $out['entri_bulan'] = (int) $st->fetchColumn();

    require_once __DIR__ . '/santri_list_sort.php';
    $nameExpr = santri_list_select_nama_sql($pdo, 's', 'nama_santri');
    $st = $pdo->prepare("
        SELECT s.id, s.nis, {$nameExpr}, s.tingkatan,
               COALESCE(SUM(pl.point_delta), 0) AS total_poin
        FROM santri s
        INNER JOIN point_ledger pl ON pl.santri_id = s.id AND pl.tanggal BETWEEN :a AND :b
        WHERE " . santri_sql_aktif_only('s') . "
        GROUP BY s.id
        HAVING total_poin > 0
        ORDER BY total_poin DESC
        LIMIT 5
    ");
    $st->execute(['a' => $start, 'b' => $end]);
    $out['top_poin'] = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $st2 = $pdo->prepare("
        SELECT COUNT(DISTINCT pl.santri_id)
        FROM point_ledger pl
        INNER JOIN santri s ON s.id = pl.santri_id AND " . santri_sql_aktif_only('s') . "
        WHERE pl.tanggal BETWEEN :a AND :b AND pl.point_delta > 0
    ");
    $st2->execute(['a' => $start, 'b' => $end]);
    $out['santri_dapat_poin'] = (int) $st2->fetchColumn();

    if (function_exists('poin_santri_perlu_tindakan')) {
        $out['perlu_tindakan'] = count(poin_santri_perlu_tindakan($pdo, $month, $year));
    }

    return $out;
}

/**
 * @return array<string, int>
 */
function yayasan_dashboard_santri_kpi(PDO $pdo, string $bulanAwal, string $bulanAkhir, string $taAwal, string $taAkhir): array
{
    $kpi = [
        'santri_aktif' => 0,
        'santri_khidmah' => 0,
        'santri_nonaktif' => 0,
        'putra' => 0,
        'putri' => 0,
        'masuk_bulan_ini' => 0,
        'keluar_bulan_ini' => 0,
        'masuk_ta' => 0,
        'keluar_ta' => 0,
        'total_terdaftar' => 0,
    ];
    if (!table_exists($pdo, 'santri')) {
        return $kpi;
    }

    $hasStatus = column_exists($pdo, 'santri', 'status_santri');
    $hasTglMasuk = column_exists($pdo, 'santri', 'tanggal_masuk');
    $hasTglKeluar = column_exists($pdo, 'santri', 'tanggal_keluar');
    $hasJk = column_exists($pdo, 'santri', 'jenis_kelamin');

    if ($hasStatus) {
        $row = $pdo->query("
            SELECT
                SUM(CASE WHEN " . santri_sql_aktif_only('santri') . " THEN 1 ELSE 0 END) AS aktif,
                SUM(CASE WHEN UPPER(REPLACE(TRIM(COALESCE(santri.status_santri, '')), ' ', '_')) IN ('KHIDMAH','PENGABDIAN','PENGABDIAN_KHIDMAH') THEN 1 ELSE 0 END) AS khidmah,
                SUM(CASE WHEN UPPER(REPLACE(TRIM(COALESCE(santri.status_santri, '')), ' ', '_')) IN ('NONAKTIF','NON_AKTIF','BOYONG','ALUMNI','KELUAR','MUQIM')
                    OR COALESCE(santri.is_aktif, 1) = 0 THEN 1 ELSE 0 END) AS nonaktif,
                COUNT(*) AS total
            FROM santri
        ")->fetch(PDO::FETCH_ASSOC) ?: [];
        $kpi['santri_aktif'] = (int) ($row['aktif'] ?? 0);
        $kpi['santri_khidmah'] = (int) ($row['khidmah'] ?? 0);
        $kpi['santri_nonaktif'] = (int) ($row['nonaktif'] ?? 0);
        $kpi['total_terdaftar'] = (int) ($row['total'] ?? 0);
    } elseif (column_exists($pdo, 'santri', 'is_aktif')) {
        $kpi['santri_aktif'] = (int) $pdo->query('SELECT COUNT(*) FROM santri WHERE COALESCE(is_aktif, 1) = 1')->fetchColumn();
        $kpi['santri_nonaktif'] = (int) $pdo->query('SELECT COUNT(*) FROM santri WHERE COALESCE(is_aktif, 1) = 0')->fetchColumn();
        $kpi['total_terdaftar'] = (int) $pdo->query('SELECT COUNT(*) FROM santri')->fetchColumn();
    } else {
        $kpi['santri_aktif'] = (int) $pdo->query('SELECT COUNT(*) FROM santri')->fetchColumn();
        $kpi['total_terdaftar'] = $kpi['santri_aktif'];
    }

    if ($hasJk && $hasStatus) {
        $jkRow = $pdo->query('
            SELECT
                SUM(CASE WHEN TRIM(jenis_kelamin) = "Laki-laki" THEN 1 ELSE 0 END) AS putra,
                SUM(CASE WHEN TRIM(jenis_kelamin) = "Perempuan" THEN 1 ELSE 0 END) AS putri
            FROM santri WHERE ' . santri_sql_aktif_only('santri')
        )->fetch(PDO::FETCH_ASSOC) ?: [];
        $kpi['putra'] = (int) ($jkRow['putra'] ?? 0);
        $kpi['putri'] = (int) ($jkRow['putri'] ?? 0);
    }

    if ($hasTglMasuk) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM santri WHERE tanggal_masuk BETWEEN :d AND :s');
        $st->execute(['d' => $bulanAwal, 's' => $bulanAkhir]);
        $kpi['masuk_bulan_ini'] = (int) $st->fetchColumn();
        $st->execute(['d' => $taAwal, 's' => $taAkhir]);
        $kpi['masuk_ta'] = (int) $st->fetchColumn();
    }
    if ($hasTglKeluar) {
        $st = $pdo->prepare('
            SELECT COUNT(*) FROM santri
            WHERE tanggal_keluar BETWEEN :d AND :s
              AND tanggal_keluar IS NOT NULL AND TRIM(tanggal_keluar) <> ""
        ');
        $st->execute(['d' => $bulanAwal, 's' => $bulanAkhir]);
        $kpi['keluar_bulan_ini'] = (int) $st->fetchColumn();
        $st->execute(['d' => $taAwal, 's' => $taAkhir]);
        $kpi['keluar_ta'] = (int) $st->fetchColumn();
    }

    return $kpi;
}

/**
 * Rincian presensi santri untuk satu hari.
 *
 * @return array{total_scan:int,hadir:int,alpa:int,izin:int,sakit:int,santri_aktif:int,persen_partisipasi:float}
 */
function yayasan_dashboard_presensi_hari(PDO $pdo, string $today): array
{
    $out = [
        'total_scan' => 0,
        'hadir' => 0,
        'alpa' => 0,
        'izin' => 0,
        'sakit' => 0,
        'santri_aktif' => 0,
        'persen_partisipasi' => 0.0,
    ];
    if (!table_exists($pdo, 'santri')) {
        return $out;
    }
    $aktifSql = column_exists($pdo, 'santri', 'status_santri')
        ? santri_sql_aktif_only('santri')
        : (column_exists($pdo, 'santri', 'is_aktif') ? 'COALESCE(santri.is_aktif, 1) = 1' : '1=1');
    $out['santri_aktif'] = (int) $pdo->query('SELECT COUNT(*) FROM santri WHERE ' . $aktifSql)->fetchColumn();

    if (!table_exists($pdo, 'presensi')) {
        return $out;
    }
    require_once __DIR__ . '/presensi_jadwal.php';
    presensi_finalize_date_range($pdo, $today, $today, 1);
    try {
        $row = $pdo->prepare('
            SELECT
                COUNT(*) AS total_scan,
                SUM(CASE WHEN status_presensi = "HADIR" THEN 1 ELSE 0 END) AS hadir,
                SUM(CASE WHEN status_presensi = "ALPA" THEN 1 ELSE 0 END) AS alpa,
                SUM(CASE WHEN status_presensi = "IZIN" THEN 1 ELSE 0 END) AS izin,
                SUM(CASE WHEN status_presensi = "SAKIT" THEN 1 ELSE 0 END) AS sakit
            FROM presensi
            WHERE tanggal_presensi = :t
        ');
        $row->execute(['t' => $today]);
        $r = $row->fetch(PDO::FETCH_ASSOC) ?: [];
        $out['total_scan'] = (int) ($r['total_scan'] ?? 0);
        $out['hadir'] = (int) ($r['hadir'] ?? 0);
        $out['alpa'] = (int) ($r['alpa'] ?? 0);
        $out['izin'] = (int) ($r['izin'] ?? 0);
        $out['sakit'] = (int) ($r['sakit'] ?? 0);
    } catch (Throwable $e) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM presensi WHERE tanggal_presensi = :t');
        $st->execute(['t' => $today]);
        $out['total_scan'] = (int) $st->fetchColumn();
        $out['hadir'] = $out['total_scan'];
    }
    if ($out['santri_aktif'] > 0) {
        $out['persen_partisipasi'] = round(min(100, ($out['total_scan'] / $out['santri_aktif']) * 100), 1);
    }

    return $out;
}

/**
 * @param array<string, mixed> $item
 */
function yayasan_dashboard_push_item(array &$list, string $kategori, string $severity, string $title, string $detail, string $link = '', string $icon = ''): void
{
    $list[] = [
        'kategori' => $kategori,
        'severity' => $severity,
        'title' => $title,
        'detail' => $detail,
        'link' => $link,
        'icon' => $icon,
    ];
}

/**
 * Data dashboard yayasan: KPI, tren, saran keuangan/presensi, perhatian khusus.
 *
 * @return array<string, mixed>
 */
function yayasan_dashboard_snapshot(PDO $pdo): array
{
    $today = date('Y-m-d');
    $bulanAwal = date('Y-m-01');
    $bulanAkhir = date('Y-m-t');
    $aktif = pondok_tahun_ajaran_aktif($pdo);
    $taMulai = (int) $aktif['mulai'];
    $taSelesai = (int) $aktif['selesai'];
    $taRange = pondok_tahun_ajaran_gregorian_range($pdo, $taMulai, $taSelesai);
    $taAwal = (string) ($taRange[0] ?? $bulanAwal);
    $taAkhir = (string) ($taRange[1] ?? $bulanAkhir);

    $months = [];
    $presensiTrend = [];
    $keuanganMasuk = [];
    $keuanganKeluar = [];
    $perhatian = [];
    $saranKeuangan = [];
    $saranPresensi = [];
    $saranSdm = [];
    $saranPoin = [];
    $saranUmum = [];

    $monthMasehi = (int) date('m');
    $yearMasehi = (int) date('Y');
    $sdm = yayasan_dashboard_sdm_kpi($pdo, $bulanAwal, $bulanAkhir);
    $poin = yayasan_dashboard_poin_kpi($pdo, $monthMasehi, $yearMasehi);
    $presensiHari = yayasan_dashboard_presensi_hari($pdo, $today);

    $kpiSantri = yayasan_dashboard_santri_kpi($pdo, $bulanAwal, $bulanAkhir, $taAwal, $taAkhir);
    $stats = dashboard_collect_stats($pdo);
    $kpi = array_merge($kpiSantri, [
        'pembimbing' => (int) ($stats['pembimbing'] ?? 0),
        'presensi_hari_ini' => (int) ($presensiHari['total_scan'] ?? $stats['presensi_hari'] ?? 0),
        'mukimin' => mukimin_count($pdo),
        'izin_aktif' => 0,
        'pkpps_santri' => 0,
        'total_kas' => function_exists('keuangan_aruskas_total_kas') ? keuangan_aruskas_total_kas($pdo, $today) : 0,
        'rapat_bulan_ini' => 0,
    ]);

    if (table_exists($pdo, 'pkpps_santri')) {
        $kpi['pkpps_santri'] = (int) $pdo->query('SELECT COUNT(*) FROM pkpps_santri WHERE is_aktif = 1')->fetchColumn();
    }
    if (table_exists($pdo, 'perizinan') && table_exists($pdo, 'santri')) {
        $approvalSql = column_exists($pdo, 'perizinan', 'approval_status')
            ? ' AND i.approval_status = "DISETUJUI"' : '';
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM perizinan i
             INNER JOIN santri s ON s.id = i.santri_id AND ' . santri_sql_aktif_only('s') . '
             WHERE i.status_izin = "IZIN"
               AND :today BETWEEN i.tanggal_mulai AND i.tanggal_selesai' . $approvalSql
        );
        $st->execute(['today' => $today]);
        $kpi['izin_aktif'] = (int) $st->fetchColumn();
    }
    if (table_exists($pdo, 'yayasan_rapat')) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM yayasan_rapat WHERE tanggal_rapat BETWEEN :a AND :b');
        $st->execute(['a' => $bulanAwal, 'b' => $bulanAkhir]);
        $kpi['rapat_bulan_ini'] = (int) $st->fetchColumn();
    }

    $slots = pondok_bulan_slots_tahun_ajaran($pdo, $taMulai, $taSelesai);
    $slotSlice = array_slice($slots, -6);
    $alpaBulanBerjalan = 0;
    $hadirBulanBerjalan = 0;
    $slotTerakhir = $slotSlice !== [] ? $slotSlice[count($slotSlice) - 1] : null;

    foreach ($slotSlice as $slot) {
        $label = (string) ($slot['label_ringkas'] ?? $slot['label'] ?? ('Bulan ' . (int) ($slot['bulan_tagihan'] ?? 0)));
        $months[] = $label;
        $dari = (string) ($slot['masehi_awal'] ?? '');
        $sampai = (string) ($slot['masehi_akhir'] ?? '');
        if ($dari === '' || $sampai === '') {
            $presensiTrend[] = 0;
            $keuanganMasuk[] = 0;
            $keuanganKeluar[] = 0;
            continue;
        }

        $hadir = 0;
        $alpa = 0;
        if (table_exists($pdo, 'presensi')) {
            $st = $pdo->prepare('SELECT COUNT(*) FROM presensi WHERE tanggal_presensi BETWEEN :d AND :s');
            $st->execute(['d' => $dari, 's' => $sampai]);
            $hadir = (int) $st->fetchColumn();
            try {
                $stA = $pdo->prepare('SELECT COUNT(*) FROM presensi WHERE tanggal_presensi BETWEEN :d AND :s AND status_presensi = "ALPA"');
                $stA->execute(['d' => $dari, 's' => $sampai]);
                $alpa = (int) $stA->fetchColumn();
            } catch (Throwable $e) {
            }
        }
        $presensiTrend[] = $hadir;
        if ($slotTerakhir !== null && (int) ($slot['bulan_tagihan'] ?? 0) === (int) ($slotTerakhir['bulan_tagihan'] ?? -1)) {
            $hadirBulanBerjalan = $hadir;
            $alpaBulanBerjalan = $alpa;
        }

        $masuk = 0;
        if (table_exists($pdo, 'keuangan_pembayaran')) {
            $st = $pdo->prepare('SELECT COALESCE(SUM(total_nominal), 0) FROM keuangan_pembayaran WHERE tanggal_bayar BETWEEN :d AND :s');
            $st->execute(['d' => $dari, 's' => $sampai]);
            $masuk = (int) round((float) $st->fetchColumn());
        }
        if (table_exists($pdo, 'keuangan_pemasukan')) {
            $st = $pdo->prepare('SELECT COALESCE(SUM(nominal), 0) FROM keuangan_pemasukan WHERE tanggal BETWEEN :d AND :s');
            $st->execute(['d' => $dari, 's' => $sampai]);
            $masuk += (int) round((float) $st->fetchColumn());
        }
        $keuanganMasuk[] = $masuk;

        $keluar = 0;
        if (table_exists($pdo, 'keuangan_pengeluaran')) {
            $st = $pdo->prepare('SELECT COALESCE(SUM(nominal), 0) FROM keuangan_pengeluaran WHERE tanggal BETWEEN :d AND :s');
            $st->execute(['d' => $dari, 's' => $sampai]);
            $keluar = (int) round((float) $st->fetchColumn());
        }
        $keuanganKeluar[] = $keluar;
    }

    $dash = keuangan_dashboard_snapshot_cached($pdo, 120);
    $neraca = keuangan_build_neraca($pdo, $today);
    $selisih = (int) ($neraca['selisih'] ?? 0);
    $neracaSeimbang = abs($selisih) < 1;
    $fmt = static fn(int $n): string => keuangan_format_rupiah($n);

    $piutang = 0;
    $penunggak = 0;
    $persenTertagih = 0.0;
    $penunggakTanpaWa = 0;
    $periodeKeu = [];
    $tag = [];
    if (is_array($dash)) {
        $tag = $dash['tagihan_bulan'] ?? [];
        $piutang = (int) ($tag['total_piutang'] ?? 0);
        $penunggak = (int) ($tag['jumlah_penunggak'] ?? 0);
        $persenTertagih = (float) ($tag['persen_tertagih'] ?? 0);
        $wa = $dash['wa_tagihan'] ?? [];
        $penunggakTanpaWa = (int) ($wa['penunggak_tanpa_wa'] ?? 0);
        $periodeKeu = $tag;
    }

    $masukBulanIni = (int) ($keuanganMasuk[count($keuanganMasuk) - 1] ?? 0);
    $keluarBulanIni = (int) ($keuanganKeluar[count($keuanganKeluar) - 1] ?? 0);
    $netBulanIni = $masukBulanIni - $keluarBulanIni;

    // ——— Perhatian khusus (prioritas tinggi) ———
    if (!$neracaSeimbang) {
        yayasan_dashboard_push_item($perhatian, 'keuangan', 'danger', 'Neraca tidak seimbang',
            'Selisih ' . $fmt(abs($selisih)) . ' per ' . date('d/m/Y') . ' — segera rekonsiliasi jurnal dan saldo akun.',
            '/keuangan/neraca.php', 'fa-scale-unbalanced');
    }
    if ($piutang > 0 && $persenTertagih < 60) {
        yayasan_dashboard_push_item($perhatian, 'keuangan', 'danger', 'Penagihan kritis',
            'Hanya ' . $persenTertagih . '% tagihan tertagih · piutang ' . $fmt($piutang) . ' (' . $penunggak . ' santri).',
            '/pembayaran/tagihan_syahriyah.php', 'fa-receipt');
    }
    if ($netBulanIni < 0 && abs($netBulanIni) > 500000) {
        yayasan_dashboard_push_item($perhatian, 'keuangan', 'warning', 'Defisit arus kas bulan berjalan',
            'Pengeluaran melebihi pemasukan ' . $fmt(abs($netBulanIni)) . ' — evaluasi pos beban dan realisasi anggaran.',
            '/keuangan/arus-kas.php', 'fa-arrow-trend-down');
    }

    $presensiBulanLalu = count($presensiTrend) >= 2 ? (int) $presensiTrend[count($presensiTrend) - 2] : 0;
    if ($presensiBulanLalu > 0 && $hadirBulanBerjalan > 0) {
        $pctChange = (($hadirBulanBerjalan - $presensiBulanLalu) / $presensiBulanLalu) * 100;
        if ($pctChange <= -15) {
            yayasan_dashboard_push_item($perhatian, 'presensi', 'danger', 'Kehadiran menurun tajam',
                'Scan presensi bulan ini turun ' . abs((int) round($pctChange)) . '% dibanding bulan lalu — periksa jadwal, libur, dan kepatuhan scan.',
                '/rekap/index.php', 'fa-chart-line');
        }
    }
    if ($alpaBulanBerjalan > 50) {
        yayasan_dashboard_push_item($perhatian, 'presensi', 'warning', 'Alpa tinggi bulan berjalan',
            $alpaBulanBerjalan . ' catatan alpa dalam periode tagihan berjalan — koordinasi dengan pembimbing dan perizinan.',
            '/rekap/izin_telat.php', 'fa-user-xmark');
    }
    if ((int) $kpi['izin_aktif'] > 30) {
        yayasan_dashboard_push_item($perhatian, 'presensi', 'info', 'Banyak santri sedang izin',
            (int) $kpi['izin_aktif'] . ' santri aktif berstatus izin hari ini — pastikan presensi dan keamanan terpantau.',
            '/perizinan/index.php', 'fa-person-walking-arrow-right');
    }
    if ((int) $kpi['keluar_bulan_ini'] >= 5) {
        yayasan_dashboard_push_item($perhatian, 'umum', 'warning', 'Lonjakan santri keluar',
            (int) $kpi['keluar_bulan_ini'] . ' santri tercatat keluar bulan ini — tinjau alasan dan kekurangan administrasi/keuangan.',
            '/santri/keluar.php', 'fa-right-from-bracket');
    }

    // ——— Saran keuangan ———
    if (is_array($dash) && function_exists('keuangan_dashboard_build_tindakan')) {
        $wa = $dash['wa_tagihan'] ?? [];
        $periode = keuangan_periode_berjalan($pdo, $today);
        foreach (keuangan_dashboard_build_tindakan(
            $neracaSeimbang,
            $selisih,
            $piutang,
            $penunggak,
            $penunggakTanpaWa,
            (int) ($wa['penunggak_dengan_wa'] ?? 0),
            (bool) ($wa['enabled'] ?? false),
            (bool) ($wa['period_sudah_kirim'] ?? false),
            (bool) ($wa['hari_ini_jadwal_kirim'] ?? false),
            (int) ($wa['due_day'] ?? 5),
            (string) ($wa['send_time'] ?? ''),
            $periode,
            (array) ($tag['top_penunggak'] ?? [])
        ) as $t) {
            yayasan_dashboard_push_item(
                $saranKeuangan,
                'keuangan',
                (string) ($t['level'] ?? 'info'),
                (string) ($t['judul'] ?? ''),
                (string) ($t['deskripsi'] ?? ''),
                (string) ($t['href'] ?? ''),
                (string) ($t['icon'] ?? 'fa-coins')
            );
        }
    } else {
        if ($penunggak > 0) {
            yayasan_dashboard_push_item($saranKeuangan, 'keuangan', 'warning', 'Kejar penagihan syahriyah',
                $penunggak . ' santri belum lunas · piutang ' . $fmt($piutang) . '.',
                '/pembayaran/tagihan_syahriyah.php', 'fa-receipt');
        }
        if ($penunggakTanpaWa > 0) {
            yayasan_dashboard_push_item($saranKeuangan, 'keuangan', 'info', 'Lengkapi nomor WA wali',
                $penunggakTanpaWa . ' penunggak tanpa nomor WhatsApp — sulit diingatkan otomatis.',
                '/santri/index.php', 'fa-brands fa-whatsapp');
        }
    }
    if ($masukBulanIni > 0 && $keluarBulanIni > $masukBulanIni * 1.2) {
        yayasan_dashboard_push_item($saranKeuangan, 'keuangan', 'warning', 'Pengeluaran mendominasi',
            'Bulan ini keluar ' . $fmt($keluarBulanIni) . ' vs masuk ' . $fmt($masukBulanIni) . ' — review pos pengeluaran terbesar.',
            '/keuangan/riwayat_pengeluaran.php', 'fa-file-invoice-dollar');
    }
    if ((int) $kpi['total_kas'] < $piutang && $piutang > 0) {
        yayasan_dashboard_push_item($saranKeuangan, 'keuangan', 'info', 'Kas vs piutang',
            'Saldo kas ' . $fmt((int) $kpi['total_kas']) . ' lebih kecil dari piutang tagihan — perhatikan likuiditas operasional.',
            '/keuangan/index.php', 'fa-vault');
    }

    // ——— Saran presensi ———
    if ((int) $kpi['presensi_hari_ini'] === 0 && (int) $kpi['santri_aktif'] > 0) {
        yayasan_dashboard_push_item($saranPresensi, 'presensi', 'warning', 'Belum ada scan hari ini',
            'Tidak ada presensi tercatat untuk hari ini — pastikan petugas scan dan jadwal kegiatan aktif.',
            '/presensi/scan.php', 'fa-qrcode');
    }
    if ($presensiBulanLalu > 0 && $hadirBulanBerjalan > $presensiBulanLalu) {
        $naik = (int) round((($hadirBulanBerjalan - $presensiBulanLalu) / $presensiBulanLalu) * 100);
        yayasan_dashboard_push_item($saranPresensi, 'presensi', 'success', 'Tren kehadiran membaik',
            'Scan bulan berjalan naik +' . $naik . '% vs bulan sebelumnya — pertahankan disiplin scan.',
            '/rekap/index.php', 'fa-arrow-trend-up');
    }
    yayasan_dashboard_push_item($saranPresensi, 'presensi', 'info', 'Rekap keaktivan SDM',
        'Pantau kehadiran pembimbing dan munawib agar selaras dengan presensi santri.',
        '/rekap/keaktivan_sdm.php', 'fa-chalkboard-user');
    if (table_exists($pdo, 'pkpps_santri') && (int) $kpi['pkpps_santri'] > 0) {
        yayasan_dashboard_push_item($saranPresensi, 'presensi', 'info', 'Keaktivan PKPPS',
            (int) $kpi['pkpps_santri'] . ' santri PKPPS aktif — cek rekap kehadiran program khusus.',
            '/rekap/pkpps_keaktivan.php', 'fa-book-open');
    }

    // ——— Saran SDM (pembimbing & munawib) ———
    $pbTotal = (int) ($sdm['pembimbing_total'] ?? 0);
    $pbHadir = (int) ($sdm['pembimbing_yang_hadir'] ?? 0);
    if ($pbTotal > 0 && $pbHadir < max(1, (int) ceil($pbTotal * 0.5))) {
        yayasan_dashboard_push_item($saranSdm, 'sdm', 'warning', 'Keaktivan pembimbing rendah',
            'Hanya ' . $pbHadir . ' dari ' . $pbTotal . ' pembimbing tercatat scan bulan ini — koordinasi jadwal dan disiplin hadir.',
            '/rekap/keaktivan_sdm.php', 'fa-chalkboard-user');
    } elseif ($pbTotal > 0) {
        yayasan_dashboard_push_item($saranSdm, 'sdm', 'success', 'Pembimbing aktif scan',
            $pbHadir . ' pembimbing hadir (' . (int) ($sdm['pembimbing_scan_bulan'] ?? 0) . ' scan) bulan ini.',
            '/rekap/pembimbing.php', 'fa-money-check-dollar');
    }
    $mwTotal = (int) ($sdm['munawib_total'] ?? 0);
    if ($mwTotal > 0 && (int) ($sdm['penugasan_aktif'] ?? 0) === 0) {
        yayasan_dashboard_push_item($saranSdm, 'sdm', 'info', 'Belum ada penugasan munawib aktif',
            $mwTotal . ' munawib terdaftar, tetapi tidak ada penugasan pengganti pembimbing hari ini.',
            '/pembimbing/munawib.php', 'fa-user-clock');
    }
    if ((int) ($sdm['penugasan_aktif'] ?? 0) > 0) {
        yayasan_dashboard_push_item($saranSdm, 'sdm', 'info', 'Munawib bertugas',
            (int) $sdm['penugasan_aktif'] . ' penugasan aktif · ' . (int) ($sdm['munawib_yang_hadir'] ?? 0) . ' munawib scan bulan ini.',
            '/rekap/munawib.php', 'fa-user-group');
    }

    // ——— Saran poin ———
    if (!table_exists($pdo, 'point_ledger')) {
        yayasan_dashboard_push_item($saranPoin, 'poin', 'info', 'Modul poin belum aktif',
            'Tabel point_ledger belum tersedia — jalankan migrasi/skema poin pondok.',
            '/poin/input.php', 'fa-star');
    } else {
        $ambang = (int) ($poin['ambang_min'] ?? 10);
        $perlu = (int) ($poin['perlu_tindakan'] ?? 0);
        if ($perlu > 0) {
            yayasan_dashboard_push_item($perhatian, 'poin', 'danger', 'Santri perlu tindak lanjut poin',
                $perlu . ' santri ≥ ' . $ambang . ' poin bulan ' . (string) ($poin['periode_label'] ?? '') . ' belum selesai ditindak.',
                '/poin/rekap.php', 'fa-gavel');
            yayasan_dashboard_push_item($saranPoin, 'poin', 'warning', 'Tindak lanjut sanksi poin',
                'Buka rekap poin dan catat tindak lanjut (PROSES/SELESAI) untuk santri di atas ambang.',
                '/poin/rekap.php?month=' . $monthMasehi . '&year=' . $yearMasehi, 'fa-clipboard-check');
        } else {
            yayasan_dashboard_push_item($saranPoin, 'poin', 'success', 'Tindak lanjut poin terpantau',
                'Tidak ada santri aktif di atas ambang ' . $ambang . ' poin yang belum ditindak bulan ini.',
                '/poin/rekap.php', 'fa-circle-check');
        }
        if ((int) ($poin['santri_dapat_poin'] ?? 0) > 20) {
            yayasan_dashboard_push_item($saranPoin, 'poin', 'info', 'Volume pelanggaran',
                (int) $poin['santri_dapat_poin'] . ' santri menerima poin plus bulan ini — evaluasi pola di input poin.',
                '/poin/input.php', 'fa-list-check');
        }
    }

    // ——— Saran umum ———
    if ((int) $kpi['masuk_bulan_ini'] > 0) {
        yayasan_dashboard_push_item($saranUmum, 'umum', 'success', 'Santri baru bulan ini',
            (int) $kpi['masuk_bulan_ini'] . ' santri dengan tanggal masuk bulan ini — pastikan data wali, tagihan, dan kartu lengkap.',
            '/santri/index.php', 'fa-user-plus');
    }
    if ((int) $kpi['mukimin'] > 0) {
        yayasan_dashboard_push_item($saranUmum, 'umum', 'info', 'Data mukimin / alumni',
            (int) $kpi['mukimin'] . ' entri mukimin — jaga konsistensi status santri nonaktif/khidmah.',
            '/settings/akses_mukimin.php', 'fa-graduation-cap');
    }
    if ((int) $kpi['rapat_bulan_ini'] === 0) {
        yayasan_dashboard_push_item($saranUmum, 'umum', 'info', 'Rapat yayasan',
            'Belum ada rapat tercatat bulan ini — dokumentasikan rapat dan notulen di modul yayasan.',
            '/yayasan/rapat.php', 'fa-handshake');
    }
    if (trim((string) app_setting($pdo, 'wa_tagihan_auto_enabled', '0')) !== '1' && $penunggak > 0) {
        yayasan_dashboard_push_item($saranUmum, 'umum', 'info', 'Otomasi WA tagihan',
            'Aktifkan pengingat tagihan otomatis untuk mengurangi tunggakan.',
            '/settings/wa_otomatis.php', 'fa-gear');
    }

    $maxPres = $presensiTrend !== [] ? max($presensiTrend) : 0;
    $maxKeu = 1;
    foreach ($keuanganMasuk as $v) {
        $maxKeu = max($maxKeu, $v);
    }
    foreach ($keuanganKeluar as $v) {
        $maxKeu = max($maxKeu, $v);
    }

    $trenPresensiLabel = 'stabil';
    if ($presensiBulanLalu > 0 && $hadirBulanBerjalan > 0) {
        $diff = $hadirBulanBerjalan - $presensiBulanLalu;
        if ($diff > $presensiBulanLalu * 0.1) {
            $trenPresensiLabel = 'naik';
        } elseif ($diff < -$presensiBulanLalu * 0.1) {
            $trenPresensiLabel = 'turun';
        }
    }
    $trenKeuanganLabel = 'stabil';
    if ($masukBulanIni > 0 || $keluarBulanIni > 0) {
        if ($netBulanIni > $masukBulanIni * 0.1) {
            $trenKeuanganLabel = 'surplus';
        } elseif ($netBulanIni < 0) {
            $trenKeuanganLabel = 'defisit';
        }
    }

    return [
        'months' => $months,
        'presensi_trend' => $presensiTrend,
        'keuangan_masuk' => $keuanganMasuk,
        'keuangan_keluar' => $keuanganKeluar,
        'perhatian' => $perhatian,
        'saran_keuangan' => $saranKeuangan,
        'saran_presensi' => $saranPresensi,
        'saran_sdm' => $saranSdm,
        'saran_poin' => $saranPoin,
        'saran_umum' => $saranUmum,
        'sdm' => $sdm,
        'poin' => $poin,
        'presensi_hari' => $presensiHari,
        'perbaikan' => array_merge($perhatian, $saranKeuangan, $saranPresensi, $saranSdm, $saranPoin, $saranUmum),
        'kpi' => $kpi,
        'max_presensi' => max(1, $maxPres),
        'max_keuangan' => $maxKeu,
        'keuangan_ringkas' => $dash,
        'neraca_seimbang' => $neracaSeimbang,
        'net_bulan_ini' => $netBulanIni,
        'masuk_bulan_ini' => $masukBulanIni,
        'keluar_bulan_ini' => $keluarBulanIni,
        'tren_presensi' => $trenPresensiLabel,
        'tren_keuangan' => $trenKeuanganLabel,
        'ta_label' => $taMulai . '/' . $taSelesai,
        'periode_keuangan_label' => (string) ($periodeKeu['bulan_label'] ?? ''),
    ];
}
