<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_akun_mutasi.php';
require_once __DIR__ . '/keuangan_rekonsiliasi.php';

/**
 * Laporan Arus Kas — PAP / ISAK 35 (metode langsung + rekonsiliasi saldo kas).
 *
 * @return array{
 *   date_from: string,
 *   date_to: string,
 *   periode_label: string,
 *   nama_lembaga: string,
 *   operasi: array{baris: list<array{label: string, nominal: int, indent?: bool}>, total: int},
 *   investasi: array{baris: list<array{label: string, nominal: int, indent?: bool}>, total: int},
 *   pendanaan: array{baris: list<array{label: string, nominal: int, indent?: bool}>, total: int},
 *   kenaikan_kas: int,
 *   kas_awal: int,
 *   kas_akhir: int,
 *   kas_akhir_hitung: int,
 *   selisih_rekonsiliasi: int,
 *   rekonsiliasi: array<string, mixed>,
 *   metode: string
 * }
 */
function keuangan_build_arus_kas(PDO $pdo, ?string $dateFrom = null, ?string $dateTo = null): array
{
    [$dateFrom, $dateTo] = keuangan_aruskas_normalisasi_periode($dateFrom, $dateTo);

    $namaLembaga = trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren Nailul Muna'));
    if ($namaLembaga === '') {
        $namaLembaga = 'Pondok Pesantren Nailul Muna';
    }

  $bulanId = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $periodeLabel = keuangan_aruskas_format_tanggal_label($dateFrom, $bulanId)
        . ' s.d. ' . keuangan_aruskas_format_tanggal_label($dateTo, $bulanId);

    $kasAwal = keuangan_aruskas_total_kas($pdo, date('Y-m-d', strtotime($dateFrom . ' -1 day')));
    $kasAkhir = keuangan_aruskas_total_kas($pdo, $dateTo);

    // —— Aktivitas operasi (arus kas langsung, detail per pos) ——
    $operasiBaris = [];
    $totalMasukOps = 0;
    $totalKeluarOps = 0;

    $penerimaanPerPos = [];
    if (table_exists($pdo, 'keuangan_pembayaran_detail')) {
        $penerimaanStmt = $pdo->prepare("
            SELECT COALESCE(d.pos_nama, d.pos_slug, 'Pembayaran') AS label_pos,
                   SUM(d.nominal) AS total
            FROM keuangan_pembayaran_detail d
            INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
            WHERE p.tanggal_bayar BETWEEN :dari AND :sampai
            GROUP BY d.pos_slug, d.pos_nama
            ORDER BY total DESC, label_pos ASC
        ");
        $penerimaanStmt->execute(['dari' => $dateFrom, 'sampai' => $dateTo]);
        foreach ($penerimaanStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $nom = (int) round((float) ($row['total'] ?? 0));
            if ($nom === 0) {
                continue;
            }
            $penerimaanPerPos[] = [
                'label' => (string) ($row['label_pos'] ?? 'Pembayaran'),
                'nominal' => $nom,
            ];
            $totalMasukOps += $nom;
        }
    }

    $penerimaanTotalStmt = $pdo->prepare('
        SELECT COALESCE(SUM(total_nominal), 0) FROM keuangan_pembayaran
        WHERE tanggal_bayar BETWEEN :dari AND :sampai
    ');
    $penerimaanTotalStmt->execute(['dari' => $dateFrom, 'sampai' => $dateTo]);
    $totalPenerimaan = (int) round((float) ($penerimaanTotalStmt->fetchColumn() ?: 0));

    if ($penerimaanPerPos === [] && $totalPenerimaan > 0) {
        $penerimaanPerPos[] = ['label' => 'Pembayaran santri/wali', 'nominal' => $totalPenerimaan];
        $totalMasukOps += $totalPenerimaan;
    }

    $pemasukanLain = [];
    if (table_exists($pdo, 'keuangan_pemasukan')) {
        $pemasukanStmt = $pdo->prepare("
            SELECT sumber, SUM(nominal) AS total
            FROM keuangan_pemasukan
            WHERE tanggal BETWEEN :dari AND :sampai
            GROUP BY sumber
            ORDER BY total DESC, sumber ASC
        ");
        $pemasukanStmt->execute(['dari' => $dateFrom, 'sampai' => $dateTo]);
        foreach ($pemasukanStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $nom = (int) round((float) ($row['total'] ?? 0));
            if ($nom === 0) {
                continue;
            }
            $pemasukanLain[] = [
                'label' => (string) ($row['sumber'] ?? 'Lainnya'),
                'nominal' => $nom,
            ];
            $totalMasukOps += $nom;
        }
    }

    $pengeluaranPerPos = [];
    $pengeluaranOpsStmt = $pdo->prepare("
        SELECT COALESCE(pos, 'Beban operasional') AS label_pos, SUM(nominal) AS total
        FROM keuangan_pengeluaran
        WHERE tanggal BETWEEN :dari AND :sampai
          AND NOT (
              LOWER(COALESCE(pos, '')) LIKE '%aset%'
              OR LOWER(COALESCE(pos, '')) LIKE '%invent%'
              OR LOWER(COALESCE(pos, '')) LIKE '%invest%'
          )
        GROUP BY pos
        ORDER BY total DESC, label_pos ASC
    ");
    $pengeluaranOpsStmt->execute(['dari' => $dateFrom, 'sampai' => $dateTo]);
    foreach ($pengeluaranOpsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $nom = (int) round((float) ($row['total'] ?? 0));
        if ($nom === 0) {
            continue;
        }
        $pengeluaranPerPos[] = [
            'label' => (string) ($row['label_pos'] ?? 'Beban'),
            'nominal' => $nom,
        ];
        $totalKeluarOps += $nom;
    }

    $totalGaji = 0;
    if (table_exists($pdo, 'keuangan_gaji_pembimbing')) {
        $gajiStmt = $pdo->prepare('
            SELECT COALESCE(SUM(total_bayar), 0) FROM keuangan_gaji_pembimbing
            WHERE tanggal_bayar BETWEEN :dari AND :sampai
        ');
        $gajiStmt->execute(['dari' => $dateFrom, 'sampai' => $dateTo]);
        $totalGaji = (int) round((float) ($gajiStmt->fetchColumn() ?: 0));
        $totalKeluarOps += $totalGaji;
    }

    if ($penerimaanPerPos !== [] || $pemasukanLain !== []) {
        $operasiBaris[] = [
            'label' => 'Kas masuk — penerimaan per pos',
            'nominal' => 0,
            'baris_tipe' => 'judul',
        ];
        foreach ($penerimaanPerPos as $item) {
            $operasiBaris[] = [
                'label' => $item['label'],
                'nominal' => $item['nominal'],
                'indent' => true,
            ];
        }
        foreach ($pemasukanLain as $item) {
            $operasiBaris[] = [
                'label' => $item['label'] . ' (pemasukan lain)',
                'nominal' => $item['nominal'],
                'indent' => true,
            ];
        }
        if ($totalMasukOps > 0) {
            $operasiBaris[] = [
                'label' => 'Subtotal kas masuk',
                'nominal' => $totalMasukOps,
                'baris_tipe' => 'subtotal_grup',
            ];
        }
    }

    if ($pengeluaranPerPos !== [] || $totalGaji > 0) {
        $operasiBaris[] = [
            'label' => 'Kas keluar — pengeluaran per pos',
            'nominal' => 0,
            'baris_tipe' => 'judul',
        ];
        foreach ($pengeluaranPerPos as $item) {
            $operasiBaris[] = [
                'label' => $item['label'],
                'nominal' => -$item['nominal'],
                'indent' => true,
            ];
        }
        if ($totalGaji > 0) {
            $operasiBaris[] = [
                'label' => 'Gaji pembimbing',
                'nominal' => -$totalGaji,
                'indent' => true,
            ];
        }
        if ($totalKeluarOps > 0) {
            $operasiBaris[] = [
                'label' => 'Subtotal kas keluar',
                'nominal' => -$totalKeluarOps,
                'baris_tipe' => 'subtotal_grup',
            ];
        }
    }

    $penyusutan = 0;
    if (table_exists($pdo, 'akuntansi_jurnal_penyesuaian')) {
        $penyStmt = $pdo->prepare("
            SELECT COALESCE(SUM(debit), 0) FROM akuntansi_jurnal_penyesuaian
            WHERE kode_akun = '6101' AND tanggal BETWEEN :dari AND :sampai
        ");
        $penyStmt->execute(['dari' => $dateFrom, 'sampai' => $dateTo]);
        $penyusutan = (int) round((float) ($penyStmt->fetchColumn() ?: 0));
        if ($penyusutan > 0) {
            $operasiBaris[] = [
                'label' => 'Penyesuaian: penyusutan aset tetap (non-kas)',
                'nominal' => 0,
                'indent' => true,
                'catatan' => 'Rp ' . number_format($penyusutan, 0, ',', '.') . ' — tidak mempengaruhi arus kas',
            ];
        }
    }

    $totalOperasi = $totalMasukOps - $totalKeluarOps;

    // —— Aktivitas investasi ——
    $investasiBaris = [];
    $investPengStmt = $pdo->prepare("
        SELECT COALESCE(pos, 'Investasi') AS label_pos, SUM(nominal) AS total
        FROM keuangan_pengeluaran
        WHERE tanggal BETWEEN :dari AND :sampai
          AND (
              LOWER(COALESCE(pos, '')) LIKE '%aset%'
              OR LOWER(COALESCE(pos, '')) LIKE '%invent%'
              OR LOWER(COALESCE(pos, '')) LIKE '%invest%'
          )
        GROUP BY pos
        ORDER BY total DESC
    ");
    $investPengStmt->execute(['dari' => $dateFrom, 'sampai' => $dateTo]);
    foreach ($investPengStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $nom = (int) round((float) ($row['total'] ?? 0));
        if ($nom === 0) {
            continue;
        }
        $investasiBaris[] = [
            'label' => 'Pengeluaran investasi: ' . (string) ($row['label_pos'] ?? 'Aset'),
            'nominal' => -$nom,
            'indent' => true,
        ];
    }

    if (table_exists($pdo, 'akuntansi_aset_tetap')) {
        $asetStmt = $pdo->prepare('
            SELECT nama_aset, kategori_aset, harga_perolehan
            FROM akuntansi_aset_tetap
            WHERE tanggal_perolehan BETWEEN :dari AND :sampai AND harga_perolehan > 0
            ORDER BY tanggal_perolehan ASC, nama_aset ASC
        ');
        $asetStmt->execute(['dari' => $dateFrom, 'sampai' => $dateTo]);
        foreach ($asetStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $nom = (int) round((float) ($row['harga_perolehan'] ?? 0));
            if ($nom === 0) {
                continue;
            }
            $label = (string) ($row['nama_aset'] ?? 'Aset tetap');
            $kat = trim((string) ($row['kategori_aset'] ?? ''));
            if ($kat !== '') {
                $label .= ' (' . $kat . ')';
            }
            $investasiBaris[] = [
                'label' => 'Perolehan aset tetap: ' . $label,
                'nominal' => -$nom,
                'indent' => true,
            ];
        }
    }

    $totalInvestasi = array_sum(array_column($investasiBaris, 'nominal'));

    // —— Aktivitas pendanaan ——
    $pendanaanBaris = [];
    if (table_exists($pdo, 'akuntansi_jurnal_umum') && table_exists($pdo, 'akuntansi_chart_of_accounts')) {
        $hibahStmt = $pdo->prepare("
            SELECT j.kode_akun, j.nama_akun, COALESCE(SUM(j.kredit), 0) - COALESCE(SUM(j.debit), 0) AS neto
            FROM akuntansi_jurnal_umum j
            INNER JOIN akuntansi_chart_of_accounts c ON c.kode_akun = j.kode_akun
            WHERE c.kelompok_laporan = 'ASET_NETO' AND j.tanggal BETWEEN :dari AND :sampai
            GROUP BY j.kode_akun, j.nama_akun
            HAVING neto <> 0
            ORDER BY j.kode_akun ASC
        ");
        $hibahStmt->execute(['dari' => $dateFrom, 'sampai' => $dateTo]);
        foreach ($hibahStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $neto = (int) round((float) ($row['neto'] ?? 0));
            if ($neto === 0) {
                continue;
            }
            $pendanaanBaris[] = [
                'label' => (string) ($row['kode_akun'] ?? '') . ' — ' . (string) ($row['nama_akun'] ?? 'Aset neto'),
                'nominal' => $neto,
                'indent' => true,
            ];
        }

        $utangStmt = $pdo->prepare("
            SELECT j.kode_akun, j.nama_akun, COALESCE(SUM(j.kredit), 0) - COALESCE(SUM(j.debit), 0) AS neto
            FROM akuntansi_jurnal_umum j
            INNER JOIN akuntansi_chart_of_accounts c ON c.kode_akun = j.kode_akun
            WHERE c.kelompok_laporan = 'LIABILITAS' AND j.kode_akun <> '2102'
              AND j.tanggal BETWEEN :dari AND :sampai
            GROUP BY j.kode_akun, j.nama_akun
            HAVING neto <> 0
            ORDER BY j.kode_akun ASC
        ");
        $utangStmt->execute(['dari' => $dateFrom, 'sampai' => $dateTo]);
        foreach ($utangStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $neto = (int) round((float) ($row['neto'] ?? 0));
            if ($neto === 0) {
                continue;
            }
            $pendanaanBaris[] = [
                'label' => 'Utang/pinjaman: ' . (string) ($row['kode_akun'] ?? '') . ' — ' . (string) ($row['nama_akun'] ?? ''),
                'nominal' => $neto,
                'indent' => true,
            ];
        }
    }

    $totalPendanaan = array_sum(array_column($pendanaanBaris, 'nominal'));

    $kenaikanKas = $totalOperasi + $totalInvestasi + $totalPendanaan;
    $kasAkhirHitung = $kasAwal + $kenaikanKas;
    $selisih = $kasAkhir - $kasAkhirHitung;

    $rekonsiliasi = keuangan_rekonsiliasi_kas_ringkas(
        $pdo,
        $dateFrom,
        $dateTo,
        $kasAwal,
        $kasAkhir
    );

    return [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'periode_label' => $periodeLabel,
        'nama_lembaga' => $namaLembaga,
        'metode' => 'langsung',
        'operasi' => [
            'baris' => $operasiBaris,
            'total' => $totalOperasi,
            'total_masuk' => $totalMasukOps,
            'total_keluar' => $totalKeluarOps,
        ],
        'investasi' => ['baris' => $investasiBaris, 'total' => $totalInvestasi],
        'pendanaan' => ['baris' => $pendanaanBaris, 'total' => $totalPendanaan],
        'kenaikan_kas' => $kenaikanKas,
        'kas_awal' => $kasAwal,
        'kas_akhir' => $kasAkhir,
        'kas_akhir_hitung' => $kasAkhirHitung,
        'selisih_rekonsiliasi' => $selisih,
        'rekonsiliasi' => $rekonsiliasi,
    ];
}

/**
 * Arus kas per rentang tanggal dengan cache sesi.
 *
 * @return array<string, mixed>
 */
function keuangan_build_arus_kas_cached(PDO $pdo, ?string $dateFrom = null, ?string $dateTo = null, int $ttlSec = 600): array
{
    [$dateFrom, $dateTo] = keuangan_aruskas_normalisasi_periode($dateFrom, $dateTo);
    $cacheKey = 'lak_' . $dateFrom . '_' . $dateTo;
    if (isset($_SESSION['user'])) {
        $bucket = $_SESSION['keuangan_aruskas_cache_v1'] ?? null;
        if (is_array($bucket)) {
            $entry = $bucket[$cacheKey] ?? null;
            if (is_array($entry) && (int) ($entry['expires'] ?? 0) > time() && is_array($entry['data'] ?? null)) {
                return $entry['data'];
            }
        }
    }
    $data = keuangan_build_arus_kas($pdo, $dateFrom, $dateTo);
    if (isset($_SESSION['user'])) {
        if (!isset($_SESSION['keuangan_aruskas_cache_v1']) || !is_array($_SESSION['keuangan_aruskas_cache_v1'])) {
            $_SESSION['keuangan_aruskas_cache_v1'] = [];
        }
        $bucket = $_SESSION['keuangan_aruskas_cache_v1'];
        $bucket[$cacheKey] = ['expires' => time() + max(60, $ttlSec), 'data' => $data];
        if (count($bucket) > 6) {
            uasort($bucket, static fn (array $a, array $b): int => (int) ($b['expires'] ?? 0) <=> (int) ($a['expires'] ?? 0));
            $bucket = array_slice($bucket, 0, 6, true);
        }
        $_SESSION['keuangan_aruskas_cache_v1'] = $bucket;
    }

    return $data;
}

function keuangan_aruskas_total_dari_baris(array $baris): int
{
    $total = 0;
    foreach ($baris as $row) {
        $tipe = (string) ($row['baris_tipe'] ?? '');
        if (in_array($tipe, ['judul', 'subjudul', 'subtotal_grup'], true)) {
            continue;
        }
        $total += (int) ($row['nominal'] ?? 0);
    }

    return $total;
}

/**
 * @return array{0: string, 1: string}
 */
function keuangan_aruskas_normalisasi_periode(?string $dateFrom, ?string $dateTo): array
{
    $to = $dateTo !== null && $dateTo !== '' ? $dateTo : date('Y-m-d');
    $tsTo = strtotime($to);
    if ($tsTo === false) {
        $to = date('Y-m-d');
        $tsTo = time();
    }
    $to = date('Y-m-d', $tsTo);

    $from = $dateFrom !== null && $dateFrom !== '' ? $dateFrom : date('Y-01-01', $tsTo);
    $tsFrom = strtotime($from);
    if ($tsFrom === false) {
        $from = date('Y-m-01', $tsTo);
        $tsFrom = strtotime($from);
    }
    $from = date('Y-m-d', $tsFrom);

    if ($from > $to) {
        [$from, $to] = [$to, $from];
    }

    return [$from, $to];
}

function keuangan_aruskas_total_kas(PDO $pdo, string $asOf): int
{
    if (!table_exists($pdo, 'keuangan_akun')) {
        return 0;
    }
    $asOf = date('Y-m-d', strtotime($asOf) ?: time());
    $masukSub = keuangan_sql_subquery_masuk_per_akun($pdo);

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(
            COALESCE(a.opening_balance, 0)
            + COALESCE(inc.total_masuk, 0)
            - COALESCE(exp.total_keluar, 0)
        ), 0) AS saldo
        FROM keuangan_akun a
        LEFT JOIN ( {$masukSub} ) inc ON inc.akun_id = a.id
        LEFT JOIN (
            SELECT akun_id, SUM(nominal) AS total_keluar
            FROM keuangan_pengeluaran
            WHERE akun_id IS NOT NULL AND tanggal <= :as_of2
            GROUP BY akun_id
        ) exp ON exp.akun_id = a.id
        WHERE a.is_active = 1
    ");
    $stmt->execute(['as_of' => $asOf, 'as_of2' => $asOf]);

    return (int) round((float) ($stmt->fetchColumn() ?: 0));
}

/**
 * @param list<string> $bulanId
 */
function keuangan_aruskas_format_tanggal_label(string $date, array $bulanId): string
{
    $ts = strtotime($date);
    if ($ts === false) {
        return $date;
    }

    return (int) date('j', $ts) . ' ' . ($bulanId[(int) date('n', $ts)] ?? '') . ' ' . date('Y', $ts);
}

function keuangan_aruskas_css(): string
{
    return '
body.aruskas-page .app-main .container-fluid { max-width: none; padding-left: 1rem; padding-right: 1rem; }
body.aruskas-page .aruskas-report-body { padding: 1.25rem 1.5rem !important; overflow-x: auto; }
.aruskas-sheet { width: 100%; max-width: none; margin: 0; font-family: inherit; }
.aruskas-sheet h1 { text-align: center; font-size: 1.35rem; text-transform: uppercase; letter-spacing: 0.06em; margin: 0 0 6px; }
.aruskas-sheet .sub { text-align: center; font-size: 1.05rem; font-weight: 600; margin: 0; }
.aruskas-sheet .per { text-align: center; color: #64748b; font-size: 0.95rem; margin: 0 0 1.25rem; }
.aruskas-sheet .metode { text-align: center; font-size: 0.85rem; color: #475569; margin: -0.75rem 0 1.25rem; }
.aruskas-table { width: 100%; border-collapse: collapse; font-size: 0.96rem; table-layout: fixed; }
.aruskas-table td { padding: 7px 18px; border-bottom: 1px solid #e2e8f0; vertical-align: top; line-height: 1.4; }
.aruskas-table .lbl { width: 62%; color: #1e293b; word-wrap: break-word; overflow-wrap: anywhere; }
.aruskas-table .amt { width: 38%; text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; font-weight: 600; padding-right: 22px !important; }
.aruskas-table .section td { background: #0f4c5c; color: #fff; font-weight: 700; font-size: 0.9rem; padding: 10px 18px; border-bottom: none; }
.aruskas-table .section-operasi td { background: #0f766e; }
.aruskas-table .section-investasi td { background: #b45309; }
.aruskas-table .section-pendanaan td { background: #1d4ed8; }
.aruskas-table .subtotal td { background: #f1f5f9; font-weight: 700; border-top: 2px solid #cbd5e1; }
.aruskas-table .indent .lbl { padding-left: 1.5rem; }
.aruskas-table .catatan .lbl { font-size: 0.82rem; color: #64748b; font-style: italic; }
.aruskas-table .grand td { background: #ecfdf5; font-weight: 700; font-size: 1.02rem; border-top: 3px double #0f766e; border-bottom: 3px double #0f766e; }
.aruskas-table .saldo td { background: #eff6ff; font-weight: 700; }
.aruskas-table .neg { color: #b91c1c; }
.aruskas-table .pos { color: #0f766e; }
.aruskas-table .judul td { background: #f8fafc; font-weight: 700; color: #0f4c5c; border-bottom: 1px solid #cbd5e1; }
.aruskas-table .subjudul td { background: #fff; font-weight: 600; color: #475569; font-size: 0.9rem; }
.aruskas-table .subtotal-grup td { background: #f1f5f9; font-weight: 700; border-top: 1px dashed #cbd5e1; }
.aruskas-rekon-box { margin-top: 1.25rem; padding: 1rem 1.25rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 0.9rem; }
.aruskas-rekon-box h3 { font-size: 0.95rem; font-weight: 700; margin: 0 0 0.5rem; color: #0f4c5c; }
.aruskas-rekon-pending { margin-top: 0.75rem; }
.aruskas-rekon-pending table { width: 100%; font-size: 0.85rem; border-collapse: collapse; }
.aruskas-rekon-pending th, .aruskas-rekon-pending td { padding: 4px 8px; border-bottom: 1px solid #e2e8f0; text-align: left; }
.aruskas-rekon-pending th:last-child, .aruskas-rekon-pending td:last-child { text-align: right; }
.aruskas-rekon.ok { color: #0f766e; }
.aruskas-rekon.warn { color: #b45309; }
@media print {
    @page { size: landscape; margin: 10mm; }
    body { margin: 0; }
    .aruskas-table .section td, .aruskas-table .grand td, .aruskas-table .saldo td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
';
}

/**
 * @param callable(int): string $fmt
 */
function keuangan_aruskas_render_html(array $lak, callable $fmt): void
{
    $nama = htmlspecialchars((string) $lak['nama_lembaga']);
    echo '<div class="aruskas-sheet">';
    echo '<h1>Laporan Arus Kas</h1>';
    echo '<p class="sub">' . $nama . '</p>';
    echo '<p class="per">Untuk periode ' . htmlspecialchars((string) $lak['periode_label']) . '</p>';
    echo '<p class="metode">Disusun dengan metode langsung (PAP / ISAK 35)</p>';

    echo '<table class="aruskas-table" role="presentation">';

    keuangan_aruskas_render_bagian($lak['operasi'] ?? [], 'ARUS KAS DARI AKTIVITAS OPERASI', 'section-operasi', $fmt);
    keuangan_aruskas_render_bagian($lak['investasi'] ?? [], 'ARUS KAS DARI AKTIVITAS INVESTASI', 'section-investasi', $fmt);
    keuangan_aruskas_render_bagian($lak['pendanaan'] ?? [], 'ARUS KAS DARI AKTIVITAS PENDANAAN', 'section-pendanaan', $fmt);

    $kenaikan = (int) ($lak['kenaikan_kas'] ?? 0);
    echo '<tr class="grand"><td class="lbl">KENAIKAN (PENURUNAN) KAS BERSIH</td>';
    echo '<td class="amt ' . ($kenaikan < 0 ? 'neg' : 'pos') . '">' . htmlspecialchars($fmt($kenaikan)) . '</td></tr>';

    echo '<tr class="saldo"><td class="lbl">Saldo kas dan setara kas awal periode</td>';
    echo '<td class="amt">' . htmlspecialchars($fmt((int) ($lak['kas_awal'] ?? 0))) . '</td></tr>';

    echo '<tr class="saldo"><td class="lbl">Saldo kas dan setara kas akhir periode</td>';
    echo '<td class="amt">' . htmlspecialchars($fmt((int) ($lak['kas_akhir'] ?? 0))) . '</td></tr>';

    echo '</table>';

    $selisih = (int) ($lak['selisih_rekonsiliasi'] ?? 0);
    if ($selisih !== 0) {
        echo '<p class="aruskas-rekon warn">Selisih rekonsiliasi arus kas ' . htmlspecialchars($fmt($selisih))
            . ' — saldo akhir per hitungan arus kas: ' . htmlspecialchars($fmt((int) ($lak['kas_akhir_hitung'] ?? 0))) . '.</p>';
    } else {
        echo '<p class="aruskas-rekon ok">Rekonsiliasi arus kas sesuai: kenaikan bersih + saldo awal = saldo akhir.</p>';
    }

    $rekon = $lak['rekonsiliasi'] ?? [];
    if (is_array($rekon) && $rekon !== []) {
        keuangan_aruskas_render_rekonsiliasi_kas($rekon, $fmt);
    }

    echo '</div>';
}

/**
 * @param array<string, mixed> $rekon
 * @param callable(int): string $fmt
 */
function keuangan_aruskas_render_rekonsiliasi_kas(array $rekon, callable $fmt): void
{
    $selisihKas = (int) ($rekon['selisih'] ?? 0);
    echo '<div class="aruskas-rekon-box">';
    echo '<h3>Sinkronisasi Kas Fisik</h3>';
    echo '<p class="mb-1">(Iuran santri + titipan saku + donasi/infaq + pemasukan lain) − pengeluaran operasional = perubahan kas operasional.</p>';
    echo '<table class="aruskas-table" role="presentation" style="margin-top:0.5rem">';
    echo '<tr><td class="lbl indent">Penerimaan iuran santri</td><td class="amt pos">' . htmlspecialchars($fmt((int) ($rekon['total_iuran'] ?? 0))) . '</td></tr>';
    echo '<tr><td class="lbl indent">Penerimaan titipan saku</td><td class="amt pos">' . htmlspecialchars($fmt((int) ($rekon['total_titipan_saku'] ?? 0))) . '</td></tr>';
    echo '<tr><td class="lbl indent">Penerimaan donasi/infaq</td><td class="amt pos">' . htmlspecialchars($fmt((int) ($rekon['total_donasi'] ?? 0))) . '</td></tr>';
    echo '<tr><td class="lbl indent">Pemasukan lain-lain</td><td class="amt pos">' . htmlspecialchars($fmt((int) ($rekon['total_pemasukan_lain'] ?? 0))) . '</td></tr>';
    echo '<tr class="subtotal"><td class="lbl">Total kas masuk operasional</td><td class="amt pos">' . htmlspecialchars($fmt((int) ($rekon['total_masuk'] ?? 0))) . '</td></tr>';
    echo '<tr><td class="lbl indent">Total pengeluaran operasional</td><td class="amt neg">(' . htmlspecialchars($fmt((int) ($rekon['total_keluar'] ?? 0))) . ')</td></tr>';
    echo '<tr class="subtotal"><td class="lbl">Kas bersih operasional (formula)</td><td class="amt">' . htmlspecialchars($fmt((int) ($rekon['kas_bersih_operasi'] ?? 0))) . '</td></tr>';
    echo '<tr><td class="lbl indent">Saldo kas awal periode</td><td class="amt">' . htmlspecialchars($fmt((int) ($rekon['kas_awal'] ?? 0))) . '</td></tr>';
    echo '<tr><td class="lbl indent">Saldo kas akhir (fisik)</td><td class="amt">' . htmlspecialchars($fmt((int) ($rekon['kas_akhir'] ?? 0))) . '</td></tr>';
    echo '<tr class="subtotal"><td class="lbl">Perubahan kas fisik (akhir − awal)</td><td class="amt">' . htmlspecialchars($fmt((int) ($rekon['kas_delta_fisik'] ?? 0))) . '</td></tr>';
    echo '</table>';
    if ($selisihKas !== 0) {
        echo '<p class="aruskas-rekon warn mt-2 mb-0">Selisih sinkronisasi kas operasional: <strong>' . htmlspecialchars($fmt($selisihKas)) . '</strong>. Periksa transaksi tanpa jurnal atau mutasi di luar periode.</p>';
    } else {
        echo '<p class="aruskas-rekon ok mt-2 mb-0">Formula kas operasional sesuai dengan perubahan saldo kas fisik.</p>';
    }

    $pending = $rekon['transaksi_tanpa_jurnal'] ?? [];
    if (is_array($pending) && $pending !== []) {
        echo '<div class="aruskas-rekon-pending">';
        echo '<p class="fw-semibold mb-1">Transaksi belum memiliki jurnal otomatis (' . count($pending) . '):</p>';
        echo '<table><thead><tr><th>Tanggal</th><th>Jenis</th><th>Keterangan</th><th>Nominal</th></tr></thead><tbody>';
        foreach ($pending as $tx) {
            echo '<tr>';
            echo '<td>' . htmlspecialchars((string) ($tx['tanggal'] ?? '')) . '</td>';
            echo '<td>' . htmlspecialchars((string) ($tx['tipe'] ?? '')) . ' #' . (int) ($tx['id'] ?? 0) . '</td>';
            echo '<td>' . htmlspecialchars((string) ($tx['keterangan'] ?? '')) . '</td>';
            echo '<td>' . htmlspecialchars($fmt((int) ($tx['nominal'] ?? 0))) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';
}

/**
 * @param array{baris?: list<array{label: string, nominal: int, indent?: bool, catatan?: string, baris_tipe?: string}>, total?: int} $bagian
 * @param callable(int): string $fmt
 */
function keuangan_aruskas_render_bagian(array $bagian, string $judul, string $sectionClass, callable $fmt): void
{
    echo '<tr class="section ' . htmlspecialchars($sectionClass) . '"><td colspan="2">' . htmlspecialchars($judul) . '</td></tr>';

    $baris = $bagian['baris'] ?? [];
    if ($baris === []) {
        echo '<tr><td class="lbl indent">Tidak ada transaksi</td><td class="amt">' . htmlspecialchars($fmt(0)) . '</td></tr>';
    } else {
        foreach ($baris as $row) {
            $tipe = (string) ($row['baris_tipe'] ?? '');
            $rowClass = trim(
                (!empty($row['indent']) ? ' indent' : '')
                . ($tipe === 'judul' ? ' judul' : '')
                . ($tipe === 'subjudul' ? ' subjudul' : '')
                . ($tipe === 'subtotal_grup' ? ' subtotal-grup' : '')
                . (!empty($row['catatan']) ? ' catatan' : '')
            );
            $nom = (int) ($row['nominal'] ?? 0);
            $cls = $nom < 0 ? 'neg' : ($nom > 0 ? 'pos' : '');
            if (in_array($tipe, ['judul', 'subjudul'], true)) {
                $cls = '';
            }
            echo '<tr class="' . trim($rowClass) . '">';
            echo '<td class="lbl">' . htmlspecialchars((string) ($row['label'] ?? ''));
            if (!empty($row['catatan'])) {
                echo '<br><small>' . htmlspecialchars((string) $row['catatan']) . '</small>';
            }
            echo '</td>';
            if (in_array($tipe, ['judul', 'subjudul'], true)) {
                echo '<td class="amt"></td></tr>';
            } else {
                echo '<td class="amt ' . $cls . '">' . htmlspecialchars($fmt($nom)) . '</td></tr>';
            }
        }
    }

    $total = (int) ($bagian['total'] ?? 0);
    echo '<tr class="subtotal"><td class="lbl">Kas bersih dari aktivitas — ' . htmlspecialchars(strtolower(str_replace('ARUS KAS DARI ', '', $judul))) . '</td>';
    echo '<td class="amt ' . ($total < 0 ? 'neg' : 'pos') . '">' . htmlspecialchars($fmt($total)) . '</td></tr>';
}
