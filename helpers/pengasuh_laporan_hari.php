<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/rekap_keaktifan_hari.php';
require_once __DIR__ . '/rekap_telat.php';
require_once __DIR__ . '/santri_operasional.php';

/**
 * Konteks tanggal untuk header laporan (Masehi + Hijriyah + hari).
 *
 * @return array{tgl_label:string,hijri_label:string,hari_label:string,libur_label:string,jumlah_kegiatan:int}
 */
function pengasuh_laporan_hari_konteks(PDO $pdo, string $tanggal, int $jumlahKegiatan): array
{
    require_once __DIR__ . '/akademik.php';

    $bulanId = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];
    $hariNama = [
        1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu',
    ];
    $ts = strtotime($tanggal);
    $tglLabel = $ts !== false
        ? (int) date('j', $ts) . ' ' . ($bulanId[(int) date('n', $ts)] ?? '') . ' ' . date('Y', $ts)
        : $tanggal;
    $hariLabel = $ts !== false ? ($hariNama[(int) date('N', $ts)] ?? '') : '';

    $hijriBulan = [
        1 => 'Muharram', 2 => 'Safar', 3 => 'Rabiul Awal', 4 => 'Rabiul Akhir',
        5 => 'Jumadil Awal', 6 => 'Jumadil Akhir', 7 => 'Rajab', 8 => "Sya'ban",
        9 => 'Ramadan', 10 => 'Syawal', 11 => 'Dzulqa\'dah', 12 => 'Dzulhijjah',
    ];
    $hijriLabel = '';
    try {
        $hijriLabel = akademik_hijri_hbt_ringkas($pdo, $tanggal, $hijriBulan);
    } catch (Throwable $e) {
        $hijriLabel = '';
    }

    $liburLabel = '';
    if (function_exists('akademik_libur_presensi_mode_aktif_di_tanggal')) {
        $mode = akademik_libur_presensi_mode_aktif_di_tanggal($pdo, $tanggal);
        if ($mode !== null && $mode !== '') {
            $liburLabel = 'Libur presensi: ' . $mode;
        }
    }

    return [
        'tgl_label' => $tglLabel,
        'hijri_label' => $hijriLabel,
        'hari_label' => $hariLabel,
        'libur_label' => $liburLabel,
        'jumlah_kegiatan' => $jumlahKegiatan,
    ];
}

/**
 * Kegiatan khusus (sekali pakai) pada tanggal tertentu.
 *
 * @return list<array<string, mixed>>
 */
function pengasuh_laporan_hari_kegiatan_khusus(PDO $pdo, string $tanggal): array
{
    if (!table_exists($pdo, 'kegiatan_khusus')) {
        return [];
    }
    require_once __DIR__ . '/kegiatan_khusus.php';
    kegiatan_khusus_ensure_schema($pdo);

    $st = $pdo->prepare('
        SELECT k.id, k.nama_kegiatan, k.kategori_kegiatan, k.tingkatan,
               k.jam_mulai, k.jam_selesai, k.tempat,
               COUNT(p.id) AS total_scan
        FROM kegiatan_khusus k
        LEFT JOIN presensi_kegiatan_khusus p ON p.kegiatan_khusus_id = k.id
        WHERE k.is_active = 1 AND k.tanggal = :tgl
        GROUP BY k.id, k.nama_kegiatan, k.kategori_kegiatan, k.tingkatan, k.jam_mulai, k.jam_selesai, k.tempat
        ORDER BY k.jam_mulai ASC, k.id ASC
    ');
    $st->execute(['tgl' => $tanggal]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Telat kegiatan & telat kembali izin untuk satu hari.
 *
 * @return array{kegiatan:list<array<string,mixed>>,izin:list<array<string,mixed>>,stats:array<string,int>}
 */
function pengasuh_laporan_hari_telat(PDO $pdo, string $tanggal, ?string $tingkatanFilter = null): array
{
    $lateTolerance = (int) app_setting($pdo, 'batas_telat_menit', '15');
    if ($lateTolerance < 0) {
        $lateTolerance = 0;
    }
    $tk = $tingkatanFilter ?? '';
    $rowsKeg = rekap_telat_kegiatan($pdo, $tanggal, $tanggal, $lateTolerance, '', '', $tk);
    $rowsIzin = rekap_telat_izin_kembali($pdo, $tanggal, $tanggal, $lateTolerance, '', $tk);

    return [
        'kegiatan' => $rowsKeg,
        'izin' => $rowsIzin,
        'stats' => [
            'telat_kegiatan' => count($rowsKeg),
            'telat_izin' => count($rowsIzin),
            'telat_berat' => count(array_filter($rowsKeg, static fn(array $r): bool => (int) ($r['telat_menit'] ?? 0) >= 60)),
        ],
    ];
}

/**
 * Ringkasan keaktifan PKPPS hari ini per tingkatan.
 *
 * @return list<array{tingkatan:string,hadir:int,izin:int,sakit:int,alpa:int,total:int,persen:float}>
 */
function pengasuh_laporan_hari_pkpps_snapshot(PDO $pdo, string $tanggal): array
{
    if (!table_exists($pdo, 'pkpps_santri') || !table_exists($pdo, 'presensi')) {
        return [];
    }
    require_once __DIR__ . '/pkpps.php';
    pkpps_ensure_schema($pdo);

    $aktifSql = santri_sql_aktif_only('s');
    $st = $pdo->prepare('
        SELECT
            t.nama_tingkatan AS tingkatan,
            COALESCE(SUM(CASE WHEN p.status_presensi = "HADIR" THEN 1 ELSE 0 END), 0) AS hadir,
            COALESCE(SUM(CASE WHEN p.status_presensi = "IZIN" THEN 1 ELSE 0 END), 0) AS izin,
            COALESCE(SUM(CASE WHEN p.status_presensi = "SAKIT" THEN 1 ELSE 0 END), 0) AS sakit,
            COALESCE(SUM(CASE WHEN p.status_presensi = "ALPA" THEN 1 ELSE 0 END), 0) AS alpa,
            COALESCE(COUNT(p.id), 0) AS total
        FROM pkpps_santri ps
        INNER JOIN santri s ON s.id = ps.santri_id AND ' . $aktifSql . '
        INNER JOIN pkpps_tingkatan t ON t.id = ps.pkpps_tingkatan_id AND t.is_aktif = 1
        LEFT JOIN presensi p ON p.santri_id = s.id AND p.tanggal_presensi = :tgl
        WHERE ps.is_aktif = 1
        GROUP BY t.id, t.nama_tingkatan, t.urutan
        HAVING total > 0 OR hadir > 0 OR izin > 0 OR sakit > 0 OR alpa > 0
        ORDER BY t.urutan ASC
    ');
    $st->execute(['tgl' => $tanggal]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $total = (int) ($r['total'] ?? 0);
        $hadir = (int) ($r['hadir'] ?? 0);
        $out[] = [
            'tingkatan' => (string) ($r['tingkatan'] ?? ''),
            'hadir' => $hadir,
            'izin' => (int) ($r['izin'] ?? 0),
            'sakit' => (int) ($r['sakit'] ?? 0),
            'alpa' => (int) ($r['alpa'] ?? 0),
            'total' => $total,
            'persen' => $total > 0 ? round(100 * $hadir / $total, 1) : 0.0,
        ];
    }

    return $out;
}

/**
 * Perizinan aktif & pengajuan pending pada tanggal.
 *
 * @return array{aktif:list<array<string,mixed>>,pending:list<array<string,mixed>>,pending_count:int}
 */
function pengasuh_laporan_hari_perizinan(PDO $pdo, string $tanggal): array
{
    if (!table_exists($pdo, 'perizinan') || !table_exists($pdo, 'santri')) {
        return ['aktif' => [], 'pending' => [], 'pending_count' => 0];
    }
    $aktifSql = santri_sql_aktif_only('s');
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';

    $stAktif = $pdo->prepare("
        SELECT i.id, i.jenis_izin, i.tanggal_mulai, i.tanggal_selesai, i.jam_mulai, i.jam_selesai,
               i.status_izin, i.alasan, s.{$nameCol} AS nama_santri, s.nis, s.tingkatan
        FROM perizinan i
        INNER JOIN santri s ON s.id = i.santri_id AND {$aktifSql}
        WHERE i.approval_status = 'DISETUJUI'
          AND i.tanggal_mulai <= :t AND i.tanggal_selesai >= :t
        ORDER BY s.tingkatan ASC, s.{$nameCol} ASC
        LIMIT 100
    ");
    $stAktif->execute(['t' => $tanggal]);
    $aktif = $stAktif->fetchAll(PDO::FETCH_ASSOC) ?: [];

    require_once __DIR__ . '/perizinan_jenis.php';
    $syari = perizinan_jenis_syari_kode();
    $filterPengasuh = column_exists($pdo, 'perizinan', 'pengasuh_approved_at')
        ? ' AND i.pengasuh_approved_at IS NULL'
        : '';
    $stPending = $pdo->prepare("
        SELECT i.id, i.jenis_izin, i.tanggal_mulai, i.tanggal_selesai, i.alasan, i.created_at,
               s.{$nameCol} AS nama_santri, s.nis, s.tingkatan
        FROM perizinan i
        INNER JOIN santri s ON s.id = i.santri_id AND {$aktifSql}
        WHERE i.approval_status = 'PENDING'
          AND UPPER(TRIM(i.jenis_izin)) = '{$syari}'{$filterPengasuh}
        ORDER BY i.created_at DESC
        LIMIT 20
    ");
    $stPending->execute();
    $pending = $stPending->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $pendingCount = 0;
    if (column_exists($pdo, 'perizinan', 'approval_status')) {
        $pendingCount = (int) $pdo->query("
            SELECT COUNT(*) FROM perizinan i
            INNER JOIN santri s ON s.id = i.santri_id AND {$aktifSql}
            WHERE i.approval_status = 'PENDING'
              AND UPPER(TRIM(i.jenis_izin)) = '{$syari}'{$filterPengasuh}
        ")->fetchColumn();
    }

    return [
        'aktif' => $aktif,
        'pending' => $pending,
        'pending_count' => $pendingCount,
    ];
}

/**
 * Daftar santri prioritas tindak lanjut hari ini.
 *
 * @param list<array<string,mixed>> $rows
 * @param list<array<string,mixed>> $detailKeg
 * @param list<array<string,mixed>> $telatKegiatan
 * @return list<array{priority:string,label:string,nama_santri:string,tingkatan:string,detail:string}>
 */
function pengasuh_laporan_hari_santri_perhatian(
    array $rows,
    array $detailKeg,
    array $telatKegiatan,
    PDO $pdo,
    string $tanggal
): array {
    /** @var array<int, array{alpa:int,jamaah:int,nama:string,tingkatan:string,nis:string}> $bySantri */
    $bySantri = [];
    $kegKategori = [];
    foreach ($rows as $r) {
        $kid = (int) ($r['kegiatan_id'] ?? 0);
        if ($kid > 0) {
            $kegKategori[$kid] = strtoupper((string) ($r['kategori_kegiatan'] ?? 'TAALIM'));
        }
    }
    foreach ($rows as $r) {
        $st = strtoupper((string) ($r['status_hari_ini'] ?? ''));
        if ($st !== 'ALPA') {
            continue;
        }
        $sid = (int) ($r['santri_id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        if (!isset($bySantri[$sid])) {
            $bySantri[$sid] = [
                'alpa' => 0,
                'jamaah' => 0,
                'nama' => (string) ($r['nama_santri'] ?? ''),
                'tingkatan' => (string) ($r['tingkatan'] ?? ''),
                'nis' => (string) ($r['nis'] ?? ''),
            ];
        }
        $bySantri[$sid]['alpa']++;
        $kid = (int) ($r['kegiatan_id'] ?? 0);
        if (($kegKategori[$kid] ?? '') === 'JAMAAH') {
            $bySantri[$sid]['jamaah']++;
        }
    }

    $out = [];
    foreach ($bySantri as $info) {
        if ((int) $info['alpa'] >= 2) {
            $out[] = [
                'priority' => 'tinggi',
                'label' => 'Alpa ≥2 kegiatan',
                'nama_santri' => $info['nama'],
                'tingkatan' => $info['tingkatan'],
                'detail' => (int) $info['alpa'] . ' kegiatan alpa',
            ];
        } elseif ((int) $info['jamaah'] >= 1) {
            $out[] = [
                'priority' => 'tinggi',
                'label' => "Alpa Jama'ah",
                'nama_santri' => $info['nama'],
                'tingkatan' => $info['tingkatan'],
                'detail' => (int) $info['jamaah'] . " kali alpa jama'ah",
            ];
        }
    }

    foreach ($telatKegiatan as $t) {
        $menit = (int) ($t['telat_menit'] ?? 0);
        if ($menit < 60) {
            continue;
        }
        $out[] = [
            'priority' => 'sedang',
            'label' => 'Telat ≥60 menit',
            'nama_santri' => (string) ($t['nama_santri'] ?? ''),
            'tingkatan' => (string) ($t['tingkatan'] ?? ''),
            'detail' => (string) ($t['nama_kegiatan'] ?? 'Kegiatan') . ' · ' . $menit . ' menit',
        ];
    }

    if (table_exists($pdo, 'perizinan')) {
        $aktifSql = santri_sql_aktif_only('s');
        $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
        $st = $pdo->prepare("
            SELECT s.{$nameCol} AS nama_santri, s.tingkatan, i.jenis_izin, i.tanggal_selesai, i.jam_selesai
            FROM perizinan i
            INNER JOIN santri s ON s.id = i.santri_id AND {$aktifSql}
            WHERE i.approval_status = 'DISETUJUI'
              AND i.status_izin NOT IN ('KEMBALI', 'DITOLAK')
              AND i.tanggal_selesai <= :t
              AND (i.tanggal_selesai < :t OR (i.tanggal_selesai = :t AND i.jam_selesai IS NOT NULL AND i.jam_selesai < CURTIME()))
            ORDER BY i.tanggal_selesai DESC
            LIMIT 15
        ");
        $st->execute(['t' => $tanggal]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[] = [
                'priority' => 'sedang',
                'label' => 'Belum kembali izin',
                'nama_santri' => (string) ($r['nama_santri'] ?? ''),
                'tingkatan' => (string) ($r['tingkatan'] ?? ''),
                'detail' => (string) ($r['jenis_izin'] ?? 'Izin'),
            ];
        }
    }

    usort($out, static function (array $a, array $b): int {
        $prio = ['tinggi' => 0, 'sedang' => 1, 'info' => 2];
        $pa = $prio[$a['priority'] ?? 'info'] ?? 9;
        $pb = $prio[$b['priority'] ?? 'info'] ?? 9;
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }

        return strcmp((string) ($a['nama_santri'] ?? ''), (string) ($b['nama_santri'] ?? ''));
    });

    return array_slice($out, 0, 50);
}

/**
 * Ringkasan keaktifan untuk notifikasi push harian pengasuh.
 *
 * @return array{persen:float,alpa:int,hadir:int,total:int,kegiatan:int}|null
 */
function pengasuh_laporan_hari_push_ringkasan(PDO $pdo, string $tanggal): ?array
{
    if (!table_exists($pdo, 'presensi')) {
        return null;
    }
    $rows = rekap_keaktifan_hari_data($pdo, $tanggal, null, null);
    $detailKeg = rekap_keaktifan_hari_detail_by_kegiatan($rows);
    $ringkasan = rekap_keaktifan_hari_ringkasan_from_detail($detailKeg);
    $totals = rekap_keaktifan_hari_totals($ringkasan);

    return [
        'persen' => (float) ($totals['persen'] ?? 0),
        'alpa' => (int) ($totals['alpa'] ?? 0),
        'hadir' => (int) ($totals['hadir'] ?? 0),
        'total' => (int) ($totals['total'] ?? 0),
        'kegiatan' => count($detailKeg),
    ];
}
