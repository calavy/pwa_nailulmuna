<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_dashboard.php';
require_once __DIR__ . '/keuangan_typography.php';
require_once __DIR__ . '/keuangan_aruskas.php';
require_once __DIR__ . '/santri_operasional.php';
require_once __DIR__ . '/yayasan_dashboard.php';
require_once __DIR__ . '/kalender_agenda.php';
require_once __DIR__ . '/entity_list_sort.php';

/**
 * Indikator keamanan kas yayasan: Aman / Peringatan / Krisis.
 *
 * @return array{
 *   level: string,
 *   label: string,
 *   badge: string,
 *   saldo_kas: int,
 *   total_piutang: int,
 *   jumlah_penunggak: int,
 *   net_bulan_ini: int,
 *   neraca_seimbang: bool,
 *   persen_tertagih: float,
 *   ringkasan: string
 * }
 */
function yayasan_kas_status(PDO $pdo): array
{
    $today = date('Y-m-d');
    $saldoKas = function_exists('keuangan_aruskas_total_kas') ? keuangan_aruskas_total_kas($pdo, $today) : 0;
    $dash = keuangan_dashboard_snapshot_cached($pdo) ?? keuangan_dashboard_snapshot($pdo);
    $tagihan = is_array($dash) ? ($dash['tagihan_bulan'] ?? []) : [];
    $piutang = (int) ($tagihan['total_piutang'] ?? 0);
    $penunggak = (int) ($tagihan['jumlah_penunggak'] ?? 0);
    $persenTertagih = (float) ($tagihan['persen_tertagih'] ?? 0);
    $neracaSeimbang = is_array($dash) ? (bool) ($dash['neraca']['seimbang'] ?? true) : true;

    $bulanAwal = date('Y-m-01');
    $masuk = 0;
    $keluar = 0;
    if (table_exists($pdo, 'keuangan_pembayaran')) {
        $stM = $pdo->prepare('SELECT COALESCE(SUM(total_nominal), 0) FROM keuangan_pembayaran WHERE tanggal_bayar BETWEEN :a AND :b');
        $stM->execute(['a' => $bulanAwal, 'b' => $today]);
        $masuk += (int) round((float) $stM->fetchColumn());
    }
    if (table_exists($pdo, 'keuangan_pemasukan')) {
        $stP = $pdo->prepare('SELECT COALESCE(SUM(nominal), 0) FROM keuangan_pemasukan WHERE tanggal BETWEEN :a AND :b');
        $stP->execute(['a' => $bulanAwal, 'b' => $today]);
        $masuk += (int) round((float) $stP->fetchColumn());
    }
    if (table_exists($pdo, 'keuangan_pengeluaran')) {
        $stK = $pdo->prepare('SELECT COALESCE(SUM(nominal), 0) FROM keuangan_pengeluaran WHERE tanggal BETWEEN :a AND :b');
        $stK->execute(['a' => $bulanAwal, 'b' => $today]);
        $keluar = (int) round((float) $stK->fetchColumn());
    }
    $netBulan = $masuk - $keluar;

    $level = 'aman';
    $ringkasan = 'Arus kas dan penagihan dalam batas aman.';

    if (!$neracaSeimbang || $saldoKas < 0 || ($penunggak >= 15 && $persenTertagih < 50)) {
        $level = 'krisis';
        $ringkasan = 'Kondisi keuangan kritis — segera tinjau kas, piutang, dan neraca.';
    } elseif ($saldoKas < 500000 || $netBulan < 0 || $penunggak >= 8 || $persenTertagih < 75) {
        $level = 'peringatan';
        $ringkasan = 'Perlu perhatian: saldo, tunggakan, atau arus kas bulan ini.';
    }

    $label = match ($level) {
        'krisis' => 'Krisis',
        'peringatan' => 'Peringatan',
        default => 'Aman',
    };
    $badge = match ($level) {
        'krisis' => 'danger',
        'peringatan' => 'warning',
        default => 'success',
    };

    return [
        'level' => $level,
        'label' => $label,
        'badge' => $badge,
        'saldo_kas' => $saldoKas,
        'total_piutang' => $piutang,
        'jumlah_penunggak' => $penunggak,
        'net_bulan_ini' => $netBulan,
        'neraca_seimbang' => $neracaSeimbang,
        'persen_tertagih' => $persenTertagih,
        'ringkasan' => $ringkasan,
        'tagihan_bulan' => $tagihan,
        'keuangan_ringkas' => $dash,
    ];
}

/**
 * To-do mendesak pengurus yayasan (dari tindakan keuangan + perhatian snapshot).
 *
 * @return list<array{judul:string,deskripsi:string,href:string,level:string,icon:string}>
 */
function yayasan_todo_mendesak(PDO $pdo, int $limit = 12): array
{
    $snap = yayasan_dashboard_snapshot_cached($pdo);
    $items = [];
    $seen = [];

    $push = static function (array &$list, array &$seen, string $key, string $judul, string $deskripsi, string $href, string $level, string $icon): void {
        if ($key === '' || isset($seen[$key])) {
            return;
        }
        $seen[$key] = true;
        $list[] = [
            'judul' => $judul,
            'deskripsi' => $deskripsi,
            'href' => $href,
            'level' => $level,
            'icon' => $icon,
        ];
    };

    foreach ($snap['perhatian'] ?? [] as $p) {
        $sev = (string) ($p['severity'] ?? 'warning');
        $level = $sev === 'danger' ? 'danger' : ($sev === 'warning' ? 'warning' : 'info');
        $push(
            $items,
            $seen,
            'p:' . ($p['title'] ?? ''),
            (string) ($p['title'] ?? ''),
            (string) ($p['detail'] ?? ''),
            (string) ($p['link'] ?? '/yayasan/operasional.php'),
            $level,
            (string) ($p['icon'] ?? 'fa-circle-exclamation')
        );
    }

    $dash = $snap['keuangan_ringkas'] ?? null;
    if (is_array($dash)) {
        foreach ($dash['tindakan'] ?? [] as $t) {
            $lvl = (string) ($t['level'] ?? 'info');
            $push(
                $items,
                $seen,
                't:' . ($t['judul'] ?? ''),
                (string) ($t['judul'] ?? ''),
                (string) ($t['deskripsi'] ?? ''),
                (string) ($t['href'] ?? '/pembayaran/tagihan_syahriyah.php'),
                $lvl === 'danger' ? 'danger' : ($lvl === 'warning' ? 'warning' : 'info'),
                (string) ($t['icon'] ?? 'fa-wallet')
            );
        }
    }

    if (function_exists('poin_santri_perlu_tindakan')) {
        $poinRows = poin_santri_perlu_tindakan($pdo, (int) date('m'), (int) date('Y'));
        if ($poinRows !== []) {
            $push(
                $items,
                $seen,
                'poin:tindak',
                'Tindak lanjut poin santri',
                count($poinRows) . ' santri membutuhkan tindakan disiplin poin.',
                '/poin/rekap.php',
                'warning',
                'fa-scale-balanced'
            );
        }
    }

    return array_slice($items, 0, max(1, $limit));
}

/**
 * Agenda rapat yayasan + akademik mendatang.
 *
 * @return list<array{tanggal:string,judul:string,jenis:string,waktu:?string,href:string,sumber:string}>
 */
function yayasan_kegiatan_mendatang(PDO $pdo, int $daysAhead = 21, int $limit = 15): array
{
    require_once __DIR__ . '/yayasan.php';
    yayasan_ensure_tables($pdo);
    ensure_akademik_agenda_table($pdo);

    $today = date('Y-m-d');
    $end = date('Y-m-d', strtotime('+' . max(1, $daysAhead) . ' days'));
    $out = [];

    if (table_exists($pdo, 'yayasan_rapat')) {
        $st = $pdo->prepare('
            SELECT id, judul, jenis, tanggal_rapat, waktu_mulai, waktu_selesai, lokasi
            FROM yayasan_rapat
            WHERE tanggal_rapat BETWEEN :a AND :b
            ORDER BY tanggal_rapat ASC, COALESCE(waktu_mulai, "00:00:00") ASC
            LIMIT 20
        ');
        $st->execute(['a' => $today, 'b' => $end]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $waktu = trim((string) ($r['waktu_mulai'] ?? ''));
            $out[] = [
                'tanggal' => (string) ($r['tanggal_rapat'] ?? ''),
                'judul' => (string) ($r['judul'] ?? 'Rapat yayasan'),
                'jenis' => yayasan_label_jenis_rapat((string) ($r['jenis'] ?? '')),
                'waktu' => $waktu !== '' ? substr($waktu, 0, 5) : null,
                'tempat' => trim((string) ($r['lokasi'] ?? '')),
                'href' => '/yayasan/rapat.php',
                'sumber' => 'rapat',
            ];
        }
    }

    foreach (akademik_agenda_for_range($pdo, $today, $end) as $ag) {
        if (!empty($ag['selesai'])) {
            continue;
        }
        $out[] = [
            'tanggal' => (string) ($ag['tanggal'] ?? ''),
            'judul' => (string) ($ag['judul'] ?? 'Agenda'),
            'jenis' => (string) ($ag['jenis'] ?? 'acara'),
            'waktu' => !empty($ag['jam_mulai']) ? substr((string) $ag['jam_mulai'], 0, 5) : null,
            'tempat' => '',
            'href' => '/akademik/kalender.php',
            'sumber' => 'agenda',
        ];
    }

    usort($out, static function (array $a, array $b): int {
        $cmp = strcmp((string) $a['tanggal'], (string) $b['tanggal']);
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) ($a['waktu'] ?? ''), (string) ($b['waktu'] ?? ''));
    });

    return array_slice($out, 0, max(1, $limit));
}

/**
 * Santri izin melewati toleransi (belum kembali setelah batas + grace).
 *
 * @return list<array<string, mixed>>
 */
function yayasan_ketertiban_izin_lewat(PDO $pdo): array
{
    if (!table_exists($pdo, 'perizinan') || !table_exists($pdo, 'santri')) {
        return [];
    }
    $approvalSql = column_exists($pdo, 'perizinan', 'approval_status')
        ? ' AND i.approval_status = "DISETUJUI"' : '';
    $aktifSql = santri_sql_aktif_only('s');
    $st = $pdo->prepare("
        SELECT i.id, i.jenis_izin, i.alasan, i.tanggal_mulai, i.tanggal_selesai,
               i.jam_mulai, i.jam_selesai, i.waktu_keluar, i.waktu_kembali, i.grace_menit,
               s.id AS santri_id, s.nama_santri, s.nis, s.tingkatan
        FROM perizinan i
        INNER JOIN santri s ON s.id = i.santri_id AND {$aktifSql}
        WHERE i.status_izin = 'IZIN'
          AND (i.waktu_kembali IS NULL OR TRIM(i.waktu_kembali) = '')
          {$approvalSql}
        ORDER BY i.tanggal_selesai ASC, s.tingkatan ASC, s.nama_santri ASC
    ");
    $st->execute();
    $rows = [];
    $now = time();
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $dueDate = (string) ($row['tanggal_selesai'] ?? '');
        $dueTime = (string) ($row['jam_selesai'] ?? '23:59:59');
        if ($dueTime === '' || $dueTime === '00:00:00') {
            $dueTime = '23:59:59';
        }
        $grace = max(0, (int) ($row['grace_menit'] ?? 15));
        $dueTs = strtotime($dueDate . ' ' . $dueTime);
        if ($dueTs === false) {
            continue;
        }
        $batasTs = $dueTs + ($grace * 60);
        if ($now <= $batasTs) {
            continue;
        }
        $telatMenit = (int) floor(($now - $batasTs) / 60);
        $row['telat_menit'] = $telatMenit;
        $row['telat_label'] = $telatMenit >= 1440
            ? intdiv($telatMenit, 1440) . ' hari'
            : ($telatMenit >= 60 ? intdiv($telatMenit, 60) . ' jam' : $telatMenit . ' menit');
        $rows[] = $row;
    }

    return $rows;
}

/**
 * Santri sakit yang perlu perhatian (izin sakit aktif atau presensi sakit hari ini).
 *
 * @return list<array<string, mixed>>
 */
function yayasan_ketertiban_sakit_perlu(PDO $pdo, ?string $tanggal = null): array
{
    $tgl = $tanggal ?? date('Y-m-d');
    if (!table_exists($pdo, 'santri')) {
        return [];
    }
    $aktifSql = santri_sql_aktif_only('s');
    $byId = [];

    if (table_exists($pdo, 'perizinan')) {
        $approvalSql = column_exists($pdo, 'perizinan', 'approval_status')
            ? ' AND i.approval_status = "DISETUJUI"' : '';
        $st = $pdo->prepare("
            SELECT s.id AS santri_id, s.nama_santri, s.nis, s.tingkatan,
                   i.alasan, i.tanggal_mulai, i.tanggal_selesai, 'izin_sakit' AS sumber
            FROM perizinan i
            INNER JOIN santri s ON s.id = i.santri_id AND {$aktifSql}
            WHERE UPPER(i.jenis_izin) = 'SAKIT'
              AND i.status_izin = 'IZIN'
              AND :t BETWEEN i.tanggal_mulai AND i.tanggal_selesai
              {$approvalSql}
        ");
        $st->execute(['t' => $tgl]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $byId[(int) $r['santri_id']] = $r;
        }
    }

    if (table_exists($pdo, 'presensi')) {
        $st = $pdo->prepare("
            SELECT s.id AS santri_id, s.nama_santri, s.nis, s.tingkatan,
                   MAX(p.catatan) AS alasan, :t AS tanggal_mulai, :t AS tanggal_selesai,
                   'presensi_sakit' AS sumber
            FROM presensi p
            INNER JOIN santri s ON s.id = p.santri_id AND {$aktifSql}
            WHERE p.tanggal_presensi = :t AND p.status_presensi = 'SAKIT'
            GROUP BY s.id, s.nama_santri, s.nis, s.tingkatan
        ");
        $st->execute(['t' => $tgl]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $sid = (int) $r['santri_id'];
            if (!isset($byId[$sid])) {
                $byId[$sid] = $r;
            }
        }
    }

    $rows = array_values($byId);
    usort($rows, static fn(array $a, array $b): int => strcmp((string) $a['nama_santri'], (string) $b['nama_santri']));

    return $rows;
}

/**
 * Santri dengan alpa berturut-turut tanpa keterangan (≥ minDays hari kerja presensi).
 *
 * @return list<array<string, mixed>>
 */
function yayasan_ketertiban_alpa_beruntun(PDO $pdo, int $minDays = 3, int $lookbackDays = 10): array
{
    if (!table_exists($pdo, 'presensi') || !table_exists($pdo, 'santri') || !table_exists($pdo, 'kegiatan')) {
        return [];
    }
    $aktifSql = santri_sql_aktif_only('s');
    $start = date('Y-m-d', strtotime('-' . max($minDays, $lookbackDays) . ' days'));
    $end = date('Y-m-d');

    $st = $pdo->prepare("
        SELECT s.id AS santri_id, s.nama_santri, s.nis, s.tingkatan,
               p.tanggal_presensi, p.status_presensi
        FROM presensi p
        INNER JOIN santri s ON s.id = p.santri_id AND {$aktifSql}
        INNER JOIN kegiatan k ON k.id = p.kegiatan_id AND k.is_active = 1
        WHERE p.tanggal_presensi BETWEEN :a AND :b
        ORDER BY s.id ASC, p.tanggal_presensi DESC
    ");
    $st->execute(['a' => $start, 'b' => $end]);

    /** @var array<int, array<string, string>> $bySantriDates */
    $bySantriDates = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $sid = (int) $r['santri_id'];
        $tgl = (string) $r['tanggal_presensi'];
        $status = strtoupper((string) ($r['status_presensi'] ?? ''));
        if (!isset($bySantriDates[$sid])) {
            $bySantriDates[$sid] = [
                'santri_id' => (string) $sid,
                'nama_santri' => (string) ($r['nama_santri'] ?? ''),
                'nis' => (string) ($r['nis'] ?? ''),
                'tingkatan' => (string) ($r['tingkatan'] ?? ''),
                'days' => [],
            ];
        }
        if (!isset($bySantriDates[$sid]['days'][$tgl])) {
            $bySantriDates[$sid]['days'][$tgl] = $status;
        } elseif ($status === 'HADIR') {
            $bySantriDates[$sid]['days'][$tgl] = 'HADIR';
        } elseif ($bySantriDates[$sid]['days'][$tgl] !== 'HADIR' && $status === 'ALPA') {
            $bySantriDates[$sid]['days'][$tgl] = 'ALPA';
        }
    }

    $out = [];
    foreach ($bySantriDates as $sid => $info) {
        $days = is_array($info['days'] ?? null) ? $info['days'] : [];
        if ($days === []) {
            continue;
        }
        krsort($days);
        $streak = 0;
        foreach ($days as $status) {
            if ($status === 'ALPA') {
                $streak++;
                continue;
            }
            if ($status === 'HADIR' || $status === 'IZIN' || $status === 'SAKIT') {
                break;
            }
        }
        if ($streak >= $minDays) {
            $out[] = [
                'santri_id' => (int) $sid,
                'nama_santri' => (string) ($info['nama_santri'] ?? ''),
                'nis' => (string) ($info['nis'] ?? ''),
                'tingkatan' => (string) ($info['tingkatan'] ?? ''),
                'hari_alpa_beruntun' => $streak,
            ];
        }
    }

    usort($out, static fn(array $a, array $b): int => ($b['hari_alpa_beruntun'] <=> $a['hari_alpa_beruntun'])
        ?: strcmp((string) $a['nama_santri'], (string) $b['nama_santri']));

    return $out;
}

/**
 * Ringkasan ketertiban untuk dashboard pengawasan.
 *
 * @return array{izin_lewat:int,sakit:int,alpa_beruntun:int,total:int}
 */
function yayasan_ketertiban_ringkasan(PDO $pdo): array
{
    $izin = yayasan_ketertiban_izin_lewat($pdo);
    $sakit = yayasan_ketertiban_sakit_perlu($pdo);
    $alpa = yayasan_ketertiban_alpa_beruntun($pdo);

    return [
        'izin_lewat' => count($izin),
        'sakit' => count($sakit),
        'alpa_beruntun' => count($alpa),
        'total' => count($izin) + count($sakit) + count($alpa),
        'izin_rows' => $izin,
        'sakit_rows' => $sakit,
        'alpa_rows' => $alpa,
    ];
}
