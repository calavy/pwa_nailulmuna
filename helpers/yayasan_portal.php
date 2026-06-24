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
    $tagihan = yayasan_portal_tagihan_ringkas($pdo);
    $piutang = (int) ($tagihan['total_piutang'] ?? 0);
    $penunggak = (int) ($tagihan['jumlah_penunggak'] ?? 0);
    $persenTertagih = (float) ($tagihan['persen_tertagih'] ?? 0);
    $neracaSeimbang = true;
    if (!function_exists('keuangan_build_neraca_cached')) {
        require_once __DIR__ . '/keuangan_neraca.php';
    }
    if (function_exists('keuangan_build_neraca_cached')) {
        $neraca = keuangan_build_neraca_cached($pdo, $today);
        $neracaSeimbang = abs((int) ($neraca['selisih'] ?? 0)) < 1;
    }

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
    ];
}

/**
 * Ringkasan tagihan bulan berjalan tanpa snapshot dashboard penuh.
 *
 * @return array<string, mixed>
 */
function yayasan_portal_tagihan_ringkas(PDO $pdo): array
{
    $empty = [
        'bulan_label' => '',
        'ta_label' => '',
        'total_piutang' => 0,
        'jumlah_penunggak' => 0,
        'persen_tertagih' => 0.0,
    ];
    if (!table_exists($pdo, 'santri') || !table_exists($pdo, 'keuangan_pembayaran')) {
        return $empty;
    }
    require_once __DIR__ . '/tagihan_bulanan.php';
    $today = date('Y-m-d');
    $periode = keuangan_periode_berjalan($pdo, $today);
    $bulan = (int) ($periode['bulan'] ?? 0);
    $tm = (int) ($periode['mulai'] ?? 0);
    $ts = (int) ($periode['selesai'] ?? 0);
    if ($bulan <= 0 || $tm <= 0 || $ts <= 0) {
        return $empty;
    }
    $listPack = tagihan_syahriyah_list_cached($pdo, $bulan, $tm, $ts, 'nama');
    $totalTagihan = (int) ($listPack['sum_tagihan'] ?? 0);
    $totalTerbayar = (int) ($listPack['sum_bayar'] ?? 0);
    $jumlahPenunggak = (int) ($listPack['count_belum'] ?? 0) + (int) ($listPack['count_sebagian'] ?? 0);
    $totalPiutang = max(0, $totalTagihan - $totalTerbayar);
    $persenTertagih = $totalTagihan > 0
        ? round(($totalTerbayar / $totalTagihan) * 100, 1)
        : 0.0;

    return [
        'bulan_label' => (string) ($periode['periode_tampilan'] ?? $periode['bulan_label'] ?? ''),
        'ta_label' => (string) ($periode['ta_label'] ?? ''),
        'total_piutang' => $totalPiutang,
        'jumlah_penunggak' => $jumlahPenunggak,
        'persen_tertagih' => $persenTertagih,
    ];
}

/** Status kas yayasan dengan cache sesi singkat (dashboard operasional). */
function yayasan_kas_status_cached(PDO $pdo, int $ttlSec = 180): array
{
    $cacheKey = 'yayasan_kas_status_v1';
    $cached = $_SESSION[$cacheKey] ?? null;
    if (
        is_array($cached)
        && (int) ($cached['expires'] ?? 0) > time()
        && is_array($cached['data'] ?? null)
    ) {
        return $cached['data'];
    }
    $data = yayasan_kas_status($pdo);
    $_SESSION[$cacheKey] = [
        'expires' => time() + max(30, $ttlSec),
        'data' => $data,
    ];

    return $data;
}

/** Invalidasi cache portal yayasan setelah transaksi keuangan berubah. */
function yayasan_portal_cache_invalidate(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    unset(
        $_SESSION['yayasan_kas_status_v1'],
        $_SESSION['yayasan_ketertiban_v1'],
        $_SESSION['yayasan_pengawasan_keu_v1']
    );
    if (function_exists('yayasan_dashboard_cache_invalidate')) {
        yayasan_dashboard_cache_invalidate();
    }
}

/**
 * Ringkasan kas untuk API / kartu dashboard (format JSON-friendly).
 *
 * @return array<string, mixed>
 */
function yayasan_kas_status_payload(array $kas): array
{
    $tagihan = is_array($kas['tagihan_bulan'] ?? null) ? $kas['tagihan_bulan'] : [];

    return [
        'level' => (string) ($kas['level'] ?? 'aman'),
        'label' => (string) ($kas['label'] ?? 'Aman'),
        'badge' => (string) ($kas['badge'] ?? 'success'),
        'ringkasan' => (string) ($kas['ringkasan'] ?? ''),
        'saldo_kas' => (int) ($kas['saldo_kas'] ?? 0),
        'saldo_kas_fmt' => keuangan_format_rupiah((int) ($kas['saldo_kas'] ?? 0)),
        'net_bulan_ini' => (int) ($kas['net_bulan_ini'] ?? 0),
        'net_bulan_ini_fmt' => keuangan_format_rupiah((int) ($kas['net_bulan_ini'] ?? 0)),
        'net_negatif' => (int) ($kas['net_bulan_ini'] ?? 0) < 0,
        'persen_tertagih' => (float) ($kas['persen_tertagih'] ?? 0),
        'neraca_seimbang' => !empty($kas['neraca_seimbang']),
        'tagihan' => [
            'total_piutang' => (int) ($tagihan['total_piutang'] ?? 0),
            'total_piutang_fmt' => keuangan_format_rupiah((int) ($tagihan['total_piutang'] ?? 0)),
            'jumlah_penunggak' => (int) ($tagihan['jumlah_penunggak'] ?? 0),
            'bulan_label' => (string) ($tagihan['bulan_label'] ?? ''),
            'ta_label' => (string) ($tagihan['ta_label'] ?? ''),
            'persen_tertagih' => (float) ($tagihan['persen_tertagih'] ?? 0),
        ],
    ];
}

/**
 * To-do mendesak pengurus yayasan (dari tindakan keuangan + perhatian snapshot).
 *
 * @return list<array{judul:string,deskripsi:string,href:string,level:string,icon:string}>
 */
function yayasan_todo_mendesak(PDO $pdo, int $limit = 12): array
{
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

    $kas = yayasan_kas_status_cached($pdo);
    $kasLevel = (string) ($kas['level'] ?? 'aman');
    if ($kasLevel === 'krisis' || $kasLevel === 'peringatan') {
        $push(
            $items,
            $seen,
            'kas:' . $kasLevel,
            (string) ($kas['label'] ?? 'Keuangan'),
            (string) ($kas['ringkasan'] ?? ''),
            '/yayasan/operasional.php',
            $kasLevel === 'krisis' ? 'danger' : 'warning',
            'fa-wallet'
        );
    }
    if ((int) ($kas['jumlah_penunggak'] ?? 0) >= 8) {
        $push(
            $items,
            $seen,
            'kas:penunggak',
            'Tagihan penunggak',
            (int) ($kas['jumlah_penunggak'] ?? 0) . ' santri · piutang ' . keuangan_format_rupiah((int) ($kas['total_piutang'] ?? 0)),
            '/pembayaran/tagihan_syahriyah.php',
            'warning',
            'fa-receipt'
        );
    }

    $ket = yayasan_ketertiban_ringkasan_cached($pdo);
    if ((int) ($ket['izin_lewat'] ?? 0) > 0) {
        $push(
            $items,
            $seen,
            'ket:izin',
            'Izin lewat toleransi',
            (int) $ket['izin_lewat'] . ' santri belum kembali sesuai batas izin.',
            '/yayasan/ketertiban.php?tab=izin',
            'danger',
            'fa-clock'
        );
    }
    if ((int) ($ket['sakit'] ?? 0) > 0) {
        $push(
            $items,
            $seen,
            'ket:sakit',
            'Sakit perlu penanganan',
            (int) $ket['sakit'] . ' santri membutuhkan tindak lanjut kesehatan.',
            '/yayasan/kesehatan.php',
            'warning',
            'fa-heart-pulse'
        );
    }
    if ((int) ($ket['alpa_beruntun'] ?? 0) > 0) {
        $push(
            $items,
            $seen,
            'ket:alpa',
            'Alpa beruntun',
            (int) $ket['alpa_beruntun'] . ' santri alpa berturut-turut tanpa keterangan.',
            '/yayasan/ketertiban.php?tab=alpa',
            'warning',
            'fa-user-xmark'
        );
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

/** Ringkasan ketertiban dengan cache sesi singkat (dashboard pengawasan). */
function yayasan_ketertiban_ringkasan_cached(PDO $pdo, int $ttlSec = 300): array
{
    $today = date('Y-m-d');
    $cacheKey = 'yayasan_ketertiban_v1';
    $cached = $_SESSION[$cacheKey] ?? null;
    if (
        is_array($cached)
        && (int) ($cached['expires'] ?? 0) > time()
        && (string) ($cached['day'] ?? '') === $today
        && is_array($cached['data'] ?? null)
    ) {
        return $cached['data'];
    }
    $data = yayasan_ketertiban_ringkasan($pdo);
    $_SESSION[$cacheKey] = [
        'expires' => time() + max(60, $ttlSec),
        'day' => $today,
        'data' => $data,
    ];

    return $data;
}
