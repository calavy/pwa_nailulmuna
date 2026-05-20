<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_akun_mutasi.php';

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

    // —— Aktivitas operasi (arus kas langsung) ——
    $operasiBaris = [];

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
            $operasiBaris[] = [
                'label' => 'Penerimaan: ' . (string) ($row['label_pos'] ?? 'Pembayaran'),
                'nominal' => $nom,
                'indent' => true,
            ];
        }
    }

    $penerimaanTotalStmt = $pdo->prepare('
        SELECT COALESCE(SUM(total_nominal), 0) FROM keuangan_pembayaran
        WHERE tanggal_bayar BETWEEN :dari AND :sampai
    ');
    $penerimaanTotalStmt->execute(['dari' => $dateFrom, 'sampai' => $dateTo]);
    $totalPenerimaan = (int) round((float) ($penerimaanTotalStmt->fetchColumn() ?: 0));

    if ($operasiBaris === [] && $totalPenerimaan > 0) {
        $operasiBaris[] = [
            'label' => 'Penerimaan dari pembayaran santri/wali',
            'nominal' => $totalPenerimaan,
            'indent' => true,
        ];
    }

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
            $operasiBaris[] = [
                'label' => 'Pemasukan lain: ' . (string) ($row['sumber'] ?? 'Lainnya'),
                'nominal' => $nom,
                'indent' => true,
            ];
        }
    }

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
        $operasiBaris[] = [
            'label' => 'Pembayaran: ' . (string) ($row['label_pos'] ?? 'Beban'),
            'nominal' => -$nom,
            'indent' => true,
        ];
    }

    if (table_exists($pdo, 'keuangan_gaji_pembimbing')) {
        $gajiStmt = $pdo->prepare('
            SELECT COALESCE(SUM(total_bayar), 0) FROM keuangan_gaji_pembimbing
            WHERE tanggal_bayar BETWEEN :dari AND :sampai
        ');
        $gajiStmt->execute(['dari' => $dateFrom, 'sampai' => $dateTo]);
        $totalGaji = (int) round((float) ($gajiStmt->fetchColumn() ?: 0));
        if ($totalGaji > 0) {
            $operasiBaris[] = [
                'label' => 'Pembayaran gaji pembimbing',
                'nominal' => -$totalGaji,
                'indent' => true,
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

    $totalOperasi = array_sum(array_column($operasiBaris, 'nominal'));

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

    return [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'periode_label' => $periodeLabel,
        'nama_lembaga' => $namaLembaga,
        'metode' => 'langsung',
        'operasi' => ['baris' => $operasiBaris, 'total' => $totalOperasi],
        'investasi' => ['baris' => $investasiBaris, 'total' => $totalInvestasi],
        'pendanaan' => ['baris' => $pendanaanBaris, 'total' => $totalPendanaan],
        'kenaikan_kas' => $kenaikanKas,
        'kas_awal' => $kasAwal,
        'kas_akhir' => $kasAkhir,
        'kas_akhir_hitung' => $kasAkhirHitung,
        'selisih_rekonsiliasi' => $selisih,
    ];
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
.aruskas-rekon { text-align: center; margin-top: 1rem; font-size: 0.9rem; }
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
        echo '<p class="aruskas-rekon warn">Selisih rekonsiliasi kas ' . htmlspecialchars($fmt($selisih))
            . ' — saldo akhir per buku kas: ' . htmlspecialchars($fmt((int) ($lak['kas_akhir_hitung'] ?? 0))) . '.</p>';
    } else {
        echo '<p class="aruskas-rekon ok">Rekonsiliasi kas sesuai: kenaikan bersih + saldo awal = saldo akhir.</p>';
    }

    echo '</div>';
}

/**
 * @param array{baris?: list<array{label: string, nominal: int, indent?: bool, catatan?: string}>, total?: int} $bagian
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
            $indent = !empty($row['indent']) ? ' indent' : '';
            $catatan = !empty($row['catatan']) ? ' catatan' : '';
            $nom = (int) ($row['nominal'] ?? 0);
            $cls = $nom < 0 ? 'neg' : ($nom > 0 ? 'pos' : '');
            echo '<tr class="' . trim($indent . $catatan) . '">';
            echo '<td class="lbl">' . htmlspecialchars((string) ($row['label'] ?? ''));
            if (!empty($row['catatan'])) {
                echo '<br><small>' . htmlspecialchars((string) $row['catatan']) . '</small>';
            }
            echo '</td>';
            echo '<td class="amt ' . $cls . '">' . htmlspecialchars($fmt($nom)) . '</td></tr>';
        }
    }

    $total = (int) ($bagian['total'] ?? 0);
    echo '<tr class="subtotal"><td class="lbl">Kas bersih dari aktivitas — ' . htmlspecialchars(strtolower(str_replace('ARUS KAS DARI ', '', $judul))) . '</td>';
    echo '<td class="amt ' . ($total < 0 ? 'neg' : 'pos') . '">' . htmlspecialchars($fmt($total)) . '</td></tr>';
}
