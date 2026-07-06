<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_typography.php';
require_once __DIR__ . '/keuangan_akun_mutasi.php';
require_once __DIR__ . '/keuangan_pengaturan.php';
require_once __DIR__ . '/keuangan_rekonsiliasi.php';

/**
 * Susun Laporan Posisi Keuangan (Neraca) pondok — PAP / ISAK 35.
 *
 * @return array{
 *   as_of: string,
 *   as_of_label: string,
 *   nama_lembaga: string,
 *   aset: array{sections: list<array{judul: string, baris: list<array{label: string, nominal: int, indent?: bool}>, subtotal: int}>, total: int},
 *   liabilitas: array{sections: list<array{judul: string, baris: list<array{label: string, nominal: int, indent?: bool}>, subtotal: int}>, total: int},
 *   aset_neto: array{sections: list<array{judul: string, baris: list<array{label: string, nominal: int, indent?: bool}>, subtotal: int}>, total: int},
 *   total_pasiva: int,
 *   selisih: int
 * }
 */
function keuangan_build_neraca(PDO $pdo, ?string $asOfDate = null): array
{
  ensure_keuangan_neraca_tables($pdo);

    $asOf = $asOfDate !== null && $asOfDate !== '' ? $asOfDate : date('Y-m-d');
    $ts = strtotime($asOf);
    if ($ts === false) {
        $asOf = date('Y-m-d');
        $ts = time();
    }
    $asOf = date('Y-m-d', $ts);

    $namaLembaga = trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren Nailul Muna'));
    if ($namaLembaga === '') {
        $namaLembaga = 'Pondok Pesantren Nailul Muna';
    }

    $bulanId = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $asOfLabel = (int) date('j', $ts) . ' ' . ($bulanId[(int) date('n', $ts)] ?? '') . ' ' . date('Y', $ts);

    // Kas & bank per akun operasional (pembayaran santri + pemasukan lain)
    $masukSub = keuangan_sql_subquery_masuk_per_akun($pdo);
    $openingExpr = keuangan_sql_opening_balance_expr($pdo);
    $mutasiStmt = $pdo->prepare("
        SELECT
            a.jenis_akun,
            a.nama_akun,
            ({$openingExpr} + COALESCE(inc.total_masuk, 0) - COALESCE(exp.total_keluar, 0)) AS saldo
        FROM keuangan_akun a
        LEFT JOIN ( {$masukSub} ) inc ON inc.akun_id = a.id
        LEFT JOIN (
            SELECT akun_id, SUM(nominal) AS total_keluar
            FROM keuangan_pengeluaran
            WHERE akun_id IS NOT NULL AND tanggal <= :as_of2
            GROUP BY akun_id
        ) exp ON exp.akun_id = a.id
        WHERE a.is_active = 1
        ORDER BY a.jenis_akun ASC, a.nama_akun ASC
    ");
    $mutasiStmt->execute(['as_of' => $asOf, 'as_of2' => $asOf]);
    $kasBankBaris = [];
    $totalKasBank = 0;
    foreach ($mutasiStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $saldo = (int) round((float) ($row['saldo'] ?? 0));
        if ($saldo === 0) {
            continue;
        }
        $label = (string) ($row['nama_akun'] ?? '');
        $jenis = (string) ($row['jenis_akun'] ?? '');
        if ($jenis !== '' && stripos($label, $jenis) === false) {
            $label .= ' (' . $jenis . ')';
        }
        $kasBankBaris[] = ['label' => $label, 'nominal' => $saldo, 'indent' => true];
        $totalKasBank += $saldo;
    }

    // Aset tetap
    $asetTetapRows = [];
    if (table_exists($pdo, 'akuntansi_aset_tetap')) {
        $asetTetapStmt = $pdo->prepare('
            SELECT nama_aset, kategori_aset, harga_perolehan, akumulasi_penyusutan
            FROM akuntansi_aset_tetap
            WHERE is_active = 1 AND tanggal_perolehan <= :as_of
            ORDER BY kategori_aset ASC, nama_aset ASC
        ');
        $asetTetapStmt->execute(['as_of' => $asOf]);
        $asetTetapRows = $asetTetapStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $asetTetapBaris = [];
    $totalAsetTetapKotor = 0;
    $totalAkumulasi = 0;
    foreach ($asetTetapRows as $row) {
        $harga = (int) round((float) ($row['harga_perolehan'] ?? 0));
        $akum = (int) round((float) ($row['akumulasi_penyusutan'] ?? 0));
        $buku = max(0, $harga - $akum);
        if ($harga <= 0 && $buku <= 0) {
            continue;
        }
        $asetTetapBaris[] = [
            'label' => (string) ($row['nama_aset'] ?? '-') . ' (' . (string) ($row['kategori_aset'] ?? '') . ')',
            'nominal' => $buku,
            'indent' => true,
        ];
        $totalAsetTetapKotor += $harga;
        $totalAkumulasi += $akum;
    }
    $totalAsetTetapBersih = max(0, $totalAsetTetapKotor - $totalAkumulasi);

    // Saldo COA dari jurnal (per kelompok)
    $coaSaldo = keuangan_neraca_coa_saldo_map($pdo, $asOf);

    $asetSections = [];
    if ($kasBankBaris !== []) {
        $asetSections[] = [
            'judul' => 'Kas dan Setara Kas',
            'baris' => $kasBankBaris,
            'subtotal' => $totalKasBank,
        ];
    }
    if ($asetTetapBaris !== []) {
        $barisAsetTetap = $asetTetapBaris;
        if ($totalAkumulasi > 0) {
            $barisAsetTetap[] = [
                'label' => 'Dikurangi: Akumulasi penyusutan',
                'nominal' => -$totalAkumulasi,
                'indent' => true,
            ];
        }
        $asetSections[] = [
            'judul' => 'Aset Tetap (nilai buku)',
            'baris' => $barisAsetTetap,
            'subtotal' => $totalAsetTetapBersih,
        ];
    }

    $asetCoaBaris = keuangan_neraca_baris_from_coa($coaSaldo, 'ASET', ['1101', '1102', '1201', '1301', '1309']);
    if ($asetCoaBaris !== []) {
        $asetSections[] = [
            'judul' => 'Akun Aset (buku besar)',
            'baris' => $asetCoaBaris,
            'subtotal' => array_sum(array_column($asetCoaBaris, 'nominal')),
        ];
    }

    $totalAsetCoa = array_sum(array_column($asetCoaBaris, 'nominal'));
    $totalAset = $totalKasBank + $totalAsetTetapBersih + $totalAsetCoa;

    // Liabilitas titipan santri — dari ledger transaksi (top-up − belanja), bukan balance mentah.
    $totalCashless = 0;
    if (table_exists($pdo, 'cashless_transactions') || table_exists($pdo, 'cashless_accounts')) {
        require_once __DIR__ . '/cashless_koperasi.php';
        cashless_koperasi_ensure_schema($pdo);
        $totalCashless = (int) (cashless_saku_total_real($pdo)['total'] ?? 0);
    }
    $liabSections = [];
    $liabBaris = [];
    if ($totalCashless > 0) {
        $liabBaris[] = [
            'label' => 'Saldo titipan santri (cashless / jajan)',
            'nominal' => $totalCashless,
            'indent' => true,
        ];
    }
    $liabCoaBaris = keuangan_neraca_baris_from_coa($coaSaldo, 'LIABILITAS');
    foreach ($liabCoaBaris as $lb) {
        $liabBaris[] = $lb;
    }
    if ($liabBaris !== []) {
        $liabSections[] = [
            'judul' => 'Liabilitas',
            'baris' => $liabBaris,
            'subtotal' => array_sum(array_column($liabBaris, 'nominal')),
        ];
    }
    $totalLiabilitas = array_sum(array_column($liabBaris, 'nominal'));

    // Aset neto — dari COA + surplus operasional (apa adanya, tanpa penyeimbang otomatis)
    $asetNetoCoaBaris = keuangan_neraca_baris_from_coa($coaSaldo, 'ASET_NETO');
    $totalAsetNetoCoa = array_sum(array_column($asetNetoCoaBaris, 'nominal'));

    $ringkasanOperasi = keuangan_neraca_ringkasan_operasi($pdo, $asOf);
    $surplusOperasi = (int) ($ringkasanOperasi['surplus_operasi'] ?? 0);

    $asetNetoBaris = $asetNetoCoaBaris;
    // Hindari double-count: surplus operasional hanya jika buku besar belum punya saldo aset neto
    if ($surplusOperasi !== 0 && abs($totalAsetNetoCoa) < 1) {
        $asetNetoBaris[] = [
            'label' => 'Surplus/(defisit) operasional (tanpa titipan saku)',
            'nominal' => $surplusOperasi,
            'indent' => true,
        ];
    }

    $totalAsetNeto = array_sum(array_column($asetNetoBaris, 'nominal'));

    $asetNetoSections = [];
    if ($asetNetoBaris !== []) {
        $asetNetoSections[] = [
            'judul' => 'Aset Neto',
            'baris' => $asetNetoBaris,
            'subtotal' => $totalAsetNeto,
        ];
    }

    $totalPasiva = $totalLiabilitas + $totalAsetNeto;

    return [
        'as_of' => $asOf,
        'as_of_label' => $asOfLabel,
        'nama_lembaga' => $namaLembaga,
        'aset' => ['sections' => $asetSections, 'total' => $totalAset],
        'liabilitas' => ['sections' => $liabSections, 'total' => $totalLiabilitas],
        'aset_neto' => ['sections' => $asetNetoSections, 'total' => $totalAsetNeto],
        'total_pasiva' => $totalPasiva,
        'selisih' => $totalAset - $totalPasiva,
        'ringkasan' => $ringkasanOperasi,
        'penyesuaian_neraca' => 0,
    ];
}

/**
 * Pendapatan operasional untuk ekuitas: pembayaran dikurangi pos saku (sudah masuk liabilitas cashless).
 *
 * @return array{
 *   pendapatan_total:int,
 *   pendapatan_saku:int,
 *   pendapatan_pembayaran:int,
 *   pendapatan_lain:int,
 *   pendapatan_operasi:int,
 *   beban:int,
 *   penyusutan:int,
 *   surplus_operasi:int
 * }
 */
function keuangan_neraca_ringkasan_operasi(PDO $pdo, string $asOf): array
{
    $pendapatanTotal = 0;
    $pendapatanSaku = 0;

    if (table_exists($pdo, 'keuangan_pembayaran')) {
        $pendStmt = $pdo->prepare('SELECT COALESCE(SUM(total_nominal), 0) FROM keuangan_pembayaran WHERE tanggal_bayar <= :t');
        $pendStmt->execute(['t' => $asOf]);
        $pendapatanTotal = (int) round((float) ($pendStmt->fetchColumn() ?: 0));

        if (table_exists($pdo, 'keuangan_pembayaran_detail')) {
            $sakuStmt = $pdo->prepare('
                SELECT COALESCE(SUM(d.nominal), 0)
                FROM keuangan_pembayaran_detail d
                INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
                WHERE p.tanggal_bayar <= :t AND LOWER(TRIM(d.pos_slug)) = \'saku\'
            ');
            $sakuStmt->execute(['t' => $asOf]);
            $pendapatanSaku = (int) round((float) ($sakuStmt->fetchColumn() ?: 0));
        }
    }

    $beban = 0;
    if (table_exists($pdo, 'keuangan_pengeluaran')) {
        $opsWhere = keuangan_sql_pengeluaran_operasional_where();
        $bebanStmt = $pdo->prepare("
            SELECT COALESCE(SUM(nominal), 0) FROM keuangan_pengeluaran
            WHERE tanggal <= :t AND {$opsWhere}
        ");
        $bebanStmt->execute(['t' => $asOf]);
        $beban = (int) round((float) ($bebanStmt->fetchColumn() ?: 0));
    }
    $beban += keuangan_sum_gaji_keluar_tambahan_asof($pdo, $asOf);

    $penyusutan = 0;
    if (table_exists($pdo, 'akuntansi_jurnal_penyesuaian')) {
        $penyStmt = $pdo->prepare("
            SELECT COALESCE(SUM(debit), 0)
            FROM akuntansi_jurnal_penyesuaian
            WHERE kode_akun = '6101' AND tanggal <= :t
        ");
        $penyStmt->execute(['t' => $asOf]);
        $penyusutan = (int) round((float) ($penyStmt->fetchColumn() ?: 0));
    }

    $pendapatanPembayaran = max(0, $pendapatanTotal - $pendapatanSaku);

    $pendapatanLain = 0;
    if (table_exists($pdo, 'keuangan_pemasukan')) {
        $lainStmt = $pdo->prepare('SELECT COALESCE(SUM(nominal), 0) FROM keuangan_pemasukan WHERE tanggal <= :t');
        $lainStmt->execute(['t' => $asOf]);
        $pendapatanLain = (int) round((float) ($lainStmt->fetchColumn() ?: 0));
    }

    $pendapatanOperasi = $pendapatanPembayaran + $pendapatanLain;
    $surplusOperasi = $pendapatanOperasi - $beban - $penyusutan;

    return [
        'pendapatan_total' => $pendapatanTotal,
        'pendapatan_saku' => $pendapatanSaku,
        'pendapatan_pembayaran' => $pendapatanPembayaran,
        'pendapatan_lain' => $pendapatanLain,
        'pendapatan_operasi' => $pendapatanOperasi,
        'beban' => $beban,
        'penyusutan' => $penyusutan,
        'surplus_operasi' => $surplusOperasi,
    ];
}

function ensure_keuangan_neraca_tables(PDO $pdo): void
{
    if (!table_exists($pdo, 'keuangan_akun')) {
        return;
    }
    if (!empty($_SESSION['keuangan_neraca_opening_v1'])) {
        return;
    }
    $pdo->exec("ALTER TABLE keuangan_akun ADD COLUMN IF NOT EXISTS opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0");
    $_SESSION['keuangan_neraca_opening_v1'] = 1;
}

/**
 * Neraca per tanggal dengan cache sesi (dashboard & laporan).
 *
 * @return array<string, mixed>
 */
function keuangan_build_neraca_cached(PDO $pdo, ?string $asOfDate = null, int $ttlSec = 600): array
{
    $asOf = $asOfDate !== null && $asOfDate !== '' ? $asOfDate : date('Y-m-d');
    $ts = strtotime($asOf);
    if ($ts === false) {
        $asOf = date('Y-m-d');
    } else {
        $asOf = date('Y-m-d', $ts);
    }
    $cacheKey = 'neraca_' . $asOf;
    if (isset($_SESSION['user'])) {
        $bucket = $_SESSION['keuangan_neraca_cache_v1'] ?? null;
        if (is_array($bucket)) {
            $entry = $bucket[$cacheKey] ?? null;
            if (is_array($entry) && (int) ($entry['expires'] ?? 0) > time() && is_array($entry['data'] ?? null)) {
                return $entry['data'];
            }
        }
    }
    $data = keuangan_build_neraca($pdo, $asOf);
    if (isset($_SESSION['user'])) {
        if (!isset($_SESSION['keuangan_neraca_cache_v1']) || !is_array($_SESSION['keuangan_neraca_cache_v1'])) {
            $_SESSION['keuangan_neraca_cache_v1'] = [];
        }
        $bucket = $_SESSION['keuangan_neraca_cache_v1'];
        $bucket[$cacheKey] = ['expires' => time() + max(60, $ttlSec), 'data' => $data];
        if (count($bucket) > 5) {
            uasort($bucket, static fn (array $a, array $b): int => (int) ($b['expires'] ?? 0) <=> (int) ($a['expires'] ?? 0));
            $bucket = array_slice($bucket, 0, 5, true);
        }
        $_SESSION['keuangan_neraca_cache_v1'] = $bucket;
    }

    return $data;
}

/**
 * @return array<string, array{nama: string, kelompok: string, sifat: string, saldo: int}>
 */
function keuangan_neraca_coa_saldo_map(PDO $pdo, string $asOf): array
{
    if (!table_exists($pdo, 'akuntansi_chart_of_accounts')) {
        return [];
    }
    $stmt = $pdo->prepare("
        SELECT c.kode_akun, c.nama_akun, c.kelompok_laporan, c.sifat_akun,
               COALESCE(SUM(j.debit), 0) AS total_debit,
               COALESCE(SUM(j.kredit), 0) AS total_kredit
        FROM akuntansi_chart_of_accounts c
        LEFT JOIN (
            SELECT kode_akun, debit, kredit FROM akuntansi_jurnal_umum WHERE tanggal <= :as_of
            UNION ALL
            SELECT kode_akun, debit, kredit FROM akuntansi_jurnal_penyesuaian WHERE tanggal <= :as_of2
        ) j ON j.kode_akun = c.kode_akun
        WHERE c.is_active = 1
        GROUP BY c.kode_akun, c.nama_akun, c.kelompok_laporan, c.sifat_akun
        HAVING COALESCE(SUM(j.debit), 0) <> 0 OR COALESCE(SUM(j.kredit), 0) <> 0
        ORDER BY c.kode_akun ASC
    ");
    $stmt->execute(['as_of' => $asOf, 'as_of2' => $asOf]);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $kode = (string) ($row['kode_akun'] ?? '');
        $debit = (int) round((float) ($row['total_debit'] ?? 0));
        $kredit = (int) round((float) ($row['total_kredit'] ?? 0));
        $sifat = strtoupper((string) ($row['sifat_akun'] ?? 'DEBIT'));
        $saldo = $sifat === 'KREDIT' ? ($kredit - $debit) : ($debit - $kredit);
        if ($saldo === 0) {
            continue;
        }
        $map[$kode] = [
            'nama' => (string) ($row['nama_akun'] ?? $kode),
            'kelompok' => strtoupper((string) ($row['kelompok_laporan'] ?? '')),
            'sifat' => $sifat,
            'saldo' => $saldo,
        ];
    }
    return $map;
}

/**
 * @param array<string, array{nama: string, kelompok: string, sifat: string, saldo: int}> $coaMap
 * @param list<string> $excludeKode
 * @return list<array{label: string, nominal: int, indent?: bool}>
 */
function keuangan_neraca_baris_from_coa(array $coaMap, string $kelompok, array $excludeKode = []): array
{
    $baris = [];
    $exclude = array_fill_keys($excludeKode, true);
    foreach ($coaMap as $kode => $item) {
        if (($item['kelompok'] ?? '') !== $kelompok || isset($exclude[$kode])) {
            continue;
        }
        $baris[] = [
            'label' => $kode . ' — ' . ($item['nama'] ?? $kode),
            'nominal' => (int) ($item['saldo'] ?? 0),
            'indent' => true,
        ];
    }
    return $baris;
}

/** CSS bersama tampilan neraca 2 kolom (aktiva | pasiva), lebar penuh. */
function keuangan_neraca_css_dua_kolom(): string
{
    return '
body.neraca-page .app-main .container-fluid { max-width: none; padding-left: 1rem; padding-right: 1rem; }
body.neraca-page .neraca-report-card { border-radius: 12px; }
body.neraca-page .neraca-report-body { padding: 1.25rem 1.5rem !important; overflow-x: auto; }
.neraca-sheet { width: 100%; max-width: none; margin: 0; }
.neraca-sheet h1 { text-align: center; font-size: 1.35rem; text-transform: uppercase; letter-spacing: 0.06em; margin: 0 0 6px; }
.neraca-sheet .sub, .neraca-sheet .per { text-align: center; margin: 0; }
.neraca-sheet .sub { font-size: 1.05rem; font-weight: 600; }
.neraca-sheet .per { color: #64748b; font-size: 0.95rem; margin-bottom: 1.25rem; }
.neraca-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: 0;
    width: 100%;
    min-width: 0;
    border: 2px solid #0f4c5c;
    border-radius: 10px;
    overflow: hidden;
    box-shadow: 0 4px 24px rgba(15, 23, 42, 0.08);
}
.neraca-kolom { display: flex; flex-direction: column; min-height: 100%; min-width: 0; }
.neraca-kolom-aktiva { background: linear-gradient(180deg, #f0fdfa 0%, #fff 140px); border-right: 2px solid #0f4c5c; }
.neraca-kolom-pasiva { background: linear-gradient(180deg, #eff6ff 0%, #fff 140px); }
.neraca-kolom-head {
    background: #0f4c5c;
    color: #fff;
    text-align: center;
    font-weight: 700;
    padding: 14px 16px;
    font-size: 1.05rem;
    letter-spacing: 0.14em;
}
.neraca-kolom-aktiva .neraca-kolom-head { background: #0f766e; }
.neraca-kolom-pasiva .neraca-kolom-head { background: #1d4ed8; }
.neraca-kolom-body { flex: 1; padding: 10px 0 4px; min-width: 0; }
.neraca-kolom table { width: 100%; border-collapse: collapse; font-size: 0.95rem; table-layout: fixed; }
.neraca-kolom td { padding: 7px 18px; vertical-align: top; border-bottom: 1px solid #e2e8f0; line-height: 1.4; }
.neraca-kolom tr:last-child td { border-bottom: none; }
.neraca-kolom .lbl {
    width: 58%;
    color: #1e293b;
    word-wrap: break-word;
    overflow-wrap: anywhere;
    hyphens: auto;
}
.neraca-kolom .amt {
    width: 42%;
    text-align: right;
    white-space: nowrap;
    font-variant-numeric: tabular-nums;
    font-weight: 600;
    color: #0f172a;
    font-size: 0.93rem;
    padding-right: 22px !important;
}
.neraca-kolom .grp td {
    background: #f1f5f9;
    font-weight: 700;
    font-size: 0.84rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #475569;
    padding-top: 12px;
    padding-bottom: 6px;
}
.neraca-kolom .grp-main td {
    background: #e2e8f0;
    font-weight: 700;
    color: #0f172a;
    font-size: 0.9rem;
    text-transform: none;
    letter-spacing: 0;
}
.neraca-kolom .indent .lbl { padding-left: 1.35rem; }
.neraca-kolom .sub-row td { border-top: 1px dashed #cbd5e1; }
.neraca-kolom .sub-row .lbl { font-weight: 600; color: #475569; padding-left: 1.35rem; }
.neraca-kolom .sub-row .amt { font-weight: 700; }
.neraca-kolom-foot {
    margin-top: auto;
    border-top: 2px solid #0f4c5c;
    padding: 14px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    font-weight: 700;
    font-size: 1.02rem;
}
.neraca-kolom-aktiva .neraca-kolom-foot { background: #ccfbf1; color: #115e59; }
.neraca-kolom-pasiva .neraca-kolom-foot { background: #dbeafe; color: #1e3a8a; }
.neraca-balance-note { text-align: center; margin-top: 1rem; font-size: 0.9rem; }
.neraca-balance-note.ok { color: #0f766e; }
.neraca-balance-note.warn { color: #b45309; }
.neraca-balance-note.danger { color: #b91c1c; font-weight: 700; background: #fef2f2; border: 1px solid #fecaca; border-radius: 8px; padding: 0.65rem 1rem; }
.neraca-grid--imbalance .neraca-kolom-foot { background: #fef2f2 !important; color: #991b1b !important; border-top-color: #dc2626 !important; }
.neraca-diagnostik { margin-top: 1rem; padding: 1rem; background: #fffbeb; border: 1px solid #fcd34d; border-radius: 8px; font-size: 0.88rem; }
@media (min-width: 1400px) {
    .neraca-kolom table { font-size: 1rem; }
    .neraca-kolom .amt { font-size: 0.98rem; }
}
@media (max-width: 768px) {
    .neraca-grid { grid-template-columns: 1fr; }
    .neraca-kolom-aktiva { border-right: none; border-bottom: 2px solid #0f4c5c; }
    .neraca-kolom .lbl { width: 55%; }
    .neraca-kolom .amt { width: 45%; font-size: 0.88rem; }
}
@media print {
    @page { size: landscape; margin: 10mm; }
    body { margin: 0; }
    .neraca-sheet { max-width: none; width: 100%; }
    .neraca-grid { grid-template-columns: 1fr 1fr !important; box-shadow: none; }
    .neraca-kolom-aktiva { border-right: 2px solid #0f4c5c !important; border-bottom: none !important; }
    .neraca-kolom table { font-size: 9.5pt; }
    .neraca-kolom td { padding: 4px 10px; }
    .neraca-kolom-aktiva, .neraca-kolom-pasiva { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
';
}

/**
 * @param callable(int): string $fmt
 */
function keuangan_neraca_render_html(array $neraca, callable $fmt): void
{
    $nama = htmlspecialchars((string) $neraca['nama_lembaga']);
    echo '<div class="neraca-sheet">';
    echo '<h1>Neraca Keuangan</h1>';
    echo '<p class="sub">' . $nama . '</p>';
    echo '<p class="per">Per ' . htmlspecialchars((string) $neraca['as_of_label']) . '</p>';

    $selisih = (int) ($neraca['selisih'] ?? 0);
    $seimbang = abs($selisih) < 1;
    $gridClass = $seimbang ? '' : ' neraca-grid--imbalance';

    echo '<div class="neraca-grid' . $gridClass . '">';
    echo '<div class="neraca-kolom neraca-kolom-aktiva">';
    echo '<div class="neraca-kolom-head">AKTIVA</div>';
    echo '<div class="neraca-kolom-body">';
    keuangan_neraca_render_isi_kolom($neraca['aset'], $fmt, 'AKTIVA');
    echo '</div>';
    echo '<div class="neraca-kolom-foot"><span>Jumlah Aktiva</span><span>' . htmlspecialchars($fmt((int) $neraca['aset']['total'])) . '</span></div>';
    echo '</div>';

    echo '<div class="neraca-kolom neraca-kolom-pasiva">';
    echo '<div class="neraca-kolom-head">PASIVA</div>';
    echo '<div class="neraca-kolom-body">';
    keuangan_neraca_render_isi_pasiva($neraca, $fmt);
    echo '</div>';
    echo '<div class="neraca-kolom-foot"><span>Jumlah Pasiva</span><span>' . htmlspecialchars($fmt((int) $neraca['total_pasiva'])) . '</span></div>';
    echo '</div>';
    echo '</div>';

    $selisih = (int) ($neraca['selisih'] ?? 0);
    if ($selisih !== 0) {
        $arah = $selisih > 0 ? 'Aktiva lebih besar dari pasiva' : 'Pasiva lebih besar dari aktiva';
        echo '<p class="neraca-balance-note danger"><i class="fa-solid fa-circle-exclamation me-1"></i> '
            . 'Neraca tidak seimbang — selisih ' . htmlspecialchars($fmt(abs($selisih)))
            . ' (' . htmlspecialchars($arah) . '). Lihat panel kesalahan pencatatan di atas.</p>';
    } else {
        echo '<p class="neraca-balance-note ok">Neraca seimbang: Jumlah Aktiva = Jumlah Pasiva.</p>';
    }
    echo '</div>';
}

/**
 * @param callable(int): string $fmt
 */
function keuangan_neraca_render_diagnostik(array $neraca, callable $fmt): void
{
    $pending = $neraca['transaksi_tanpa_jurnal'] ?? [];
    if (!is_array($pending) || $pending === []) {
        return;
    }
    echo '<div class="neraca-diagnostik">';
    echo '<p class="fw-semibold mb-1">Transaksi operasional tanpa jurnal otomatis (' . count($pending) . ' terakhir):</p>';
    echo '<table class="table table-sm table-bordered mb-0"><thead><tr><th>Tanggal</th><th>Jenis</th><th>Keterangan</th><th class="text-end">Nominal</th></tr></thead><tbody>';
    foreach (array_slice($pending, 0, 15) as $tx) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars((string) ($tx['tanggal'] ?? '')) . '</td>';
        echo '<td>' . htmlspecialchars((string) ($tx['tipe'] ?? '')) . ' #' . (int) ($tx['id'] ?? 0) . '</td>';
        echo '<td>' . htmlspecialchars((string) ($tx['keterangan'] ?? '')) . '</td>';
        echo '<td class="text-end">' . htmlspecialchars($fmt((int) ($tx['nominal'] ?? 0))) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

/**
 * @param callable(int): string $fmt
 */
function keuangan_neraca_render_isi_pasiva(array $neraca, callable $fmt): void
{
    echo '<table role="presentation">';
    $liab = $neraca['liabilitas'] ?? ['sections' => [], 'total' => 0];
    $neto = $neraca['aset_neto'] ?? ['sections' => [], 'total' => 0];
    if ((int) ($liab['total'] ?? 0) !== 0 || ($liab['sections'] ?? []) !== []) {
        echo '<tr class="grp-main"><td colspan="2" class="lbl">Liabilitas (Kewajiban)</td></tr>';
        keuangan_neraca_render_baris_tabel($liab, $fmt, true);
        echo '<tr class="sub-row"><td class="lbl indent">Total Liabilitas</td>';
        echo '<td class="amt">' . htmlspecialchars($fmt((int) ($liab['total'] ?? 0))) . '</td></tr>';
    }
    if ((int) ($neto['total'] ?? 0) !== 0 || ($neto['sections'] ?? []) !== []) {
        echo '<tr class="grp-main"><td colspan="2" class="lbl">Aset Neto (Ekuitas)</td></tr>';
        keuangan_neraca_render_baris_tabel($neto, $fmt, true);
        echo '<tr class="sub-row"><td class="lbl indent">Total Aset Neto</td>';
        echo '<td class="amt">' . htmlspecialchars($fmt((int) ($neto['total'] ?? 0))) . '</td></tr>';
    }
    if (($liab['sections'] ?? []) === [] && ($neto['sections'] ?? []) === []) {
        echo '<tr><td class="lbl indent">— Tidak ada pos —</td><td class="amt">' . htmlspecialchars($fmt(0)) . '</td></tr>';
    }
    echo '</table>';
}

/**
 * @param callable(int): string $fmt
 */
function keuangan_neraca_render_isi_kolom(array $bagian, callable $fmt, string $labelKosong): void
{
    echo '<table role="presentation">';
    $sections = $bagian['sections'] ?? [];
    if ($sections === []) {
        echo '<tr><td class="lbl indent">— Tidak ada pos —</td><td class="amt">' . htmlspecialchars($fmt(0)) . '</td></tr>';
    } else {
        keuangan_neraca_render_baris_tabel($bagian, $fmt, true);
    }
    echo '</table>';
}

/**
 * @param callable(int): string $fmt
 */
function keuangan_neraca_render_baris_tabel(array $bagian, callable $fmt, bool $showSubtotal): void
{
    foreach ($bagian['sections'] ?? [] as $sec) {
        $judul = (string) ($sec['judul'] ?? '');
        if ($judul !== '') {
            echo '<tr class="grp"><td colspan="2" class="lbl">' . htmlspecialchars($judul) . '</td></tr>';
        }
        foreach ($sec['baris'] ?? [] as $baris) {
            $indent = !empty($baris['indent']) ? ' indent' : '';
            $nom = (int) ($baris['nominal'] ?? 0);
            echo '<tr><td class="lbl' . $indent . '">' . htmlspecialchars((string) ($baris['label'] ?? '')) . '</td>';
            echo '<td class="amt">' . htmlspecialchars($fmt($nom)) . '</td></tr>';
        }
        if ($showSubtotal && $judul !== '') {
            echo '<tr class="sub-row"><td class="lbl indent">Subtotal ' . htmlspecialchars($judul) . '</td>';
            echo '<td class="amt">' . htmlspecialchars($fmt((int) ($sec['subtotal'] ?? 0))) . '</td></tr>';
        }
    }
}

/** Ambang penyesuaian neraca dianggap tidak sehat (Rp). */
function keuangan_neraca_penyesuaian_threshold(): int
{
    return 100_000;
}

/**
 * Indikator kesehatan neraca (penyesuaian, jurnal, saku vs cashless).
 *
 * @return array{
 *   penyesuaian_neraca:int,
 *   penyesuaian_abs:int,
 *   penyesuaian_besar:bool,
 *   jumlah_tanpa_jurnal:int,
 *   transaksi_tanpa_jurnal:list<array<string,mixed>>,
 *   saku_dibayar:int,
 *   cashless_saldo:int,
 *   selisih_saku_cashless:int,
 *   level:string,
 *   seimbang_formal:bool
 * }
 */
function keuangan_neraca_kesehatan(PDO $pdo, array $neraca, ?int $penyesuaianThreshold = null): array
{
    $asOf = (string) ($neraca['as_of'] ?? date('Y-m-d'));
    $threshold = $penyesuaianThreshold ?? keuangan_neraca_penyesuaian_threshold();
    $selisihNeraca = (int) ($neraca['selisih'] ?? 0);
    $selisihAbs = abs($selisihNeraca);
    $ring = is_array($neraca['ringkasan'] ?? null) ? $neraca['ringkasan'] : [];

    $tanpaJurnal = keuangan_rekonsiliasi_transaksi_tanpa_jurnal($pdo, '2000-01-01', $asOf);
    $jumlahTanpaJurnal = count($tanpaJurnal);

    $sakuBayar = (int) ($ring['pendapatan_saku'] ?? 0);
    $cashlessSaldo = 0;
    if (table_exists($pdo, 'cashless_transactions') || table_exists($pdo, 'cashless_accounts')) {
        require_once __DIR__ . '/cashless_koperasi.php';
        cashless_koperasi_ensure_schema($pdo);
        $cashlessSaldo = (int) (cashless_saku_total_real($pdo)['total'] ?? 0);
    }
    $selisihSaku = $sakuBayar - $cashlessSaldo;

    $selisihBesar = $selisihAbs >= $threshold;
    $selisihSakuBesar = abs($selisihSaku) >= $threshold;

    $level = 'ok';
    if ($selisihBesar || $jumlahTanpaJurnal > 0 || $selisihSakuBesar) {
        $level = 'warn';
    }
    if ($selisihBesar && ($jumlahTanpaJurnal > 0 || $selisihSakuBesar)) {
        $level = 'danger';
    }

    return [
        'penyesuaian_neraca' => 0,
        'penyesuaian_abs' => 0,
        'penyesuaian_besar' => false,
        'penyesuaian_threshold' => $threshold,
        'selisih_neraca' => $selisihNeraca,
        'selisih_abs' => $selisihAbs,
        'selisih_besar' => $selisihBesar,
        'jumlah_tanpa_jurnal' => $jumlahTanpaJurnal,
        'transaksi_tanpa_jurnal' => array_slice($tanpaJurnal, 0, 15),
        'saku_dibayar' => $sakuBayar,
        'cashless_saldo' => $cashlessSaldo,
        'selisih_saku_cashless' => $selisihSaku,
        'level' => $level,
        'seimbang_formal' => $selisihAbs < 1,
    ];
}

/**
 * Panel indikator kesehatan neraca (hanya tampilan web, bukan cetak).
 *
 * @param callable(int): string $fmt
 */
function keuangan_neraca_render_panel_kesehatan(
    array $kesehatan,
    callable $fmt,
    string $asOf,
    bool $showBackfill = true
): void {
    $level = (string) ($kesehatan['level'] ?? 'ok');
    $selisihNeraca = (int) ($kesehatan['selisih_neraca'] ?? 0);
    $selisihAbs = (int) ($kesehatan['selisih_abs'] ?? abs($selisihNeraca));
    $jumlahTanpaJurnal = (int) ($kesehatan['jumlah_tanpa_jurnal'] ?? 0);
    $selisihSaku = (int) ($kesehatan['selisih_saku_cashless'] ?? 0);
    $threshold = (int) ($kesehatan['penyesuaian_threshold'] ?? 100000);

    $alertClass = match ($level) {
        'danger' => 'alert-danger',
        'warn' => 'alert-warning',
        default => 'alert-success',
    };

    echo '<div class="card shadow-sm mb-3 neraca-kesehatan-card">';
    echo '<div class="card-header fw-semibold d-flex flex-wrap justify-content-between align-items-center gap-2">';
    echo '<span><i class="fa-solid fa-heart-pulse me-1"></i> Indikator kesehatan neraca</span>';
    if ($level === 'ok') {
        echo '<span class="badge bg-success">Data konsisten</span>';
    } elseif ($level === 'warn') {
        echo '<span class="badge bg-warning text-dark">Perlu perhatian</span>';
    } else {
        echo '<span class="badge bg-danger">Perlu tindakan</span>';
    }
    echo '</div>';
    echo '<div class="card-body">';

    echo '<div class="alert ' . $alertClass . ' py-2 mb-3">';
    if ($selisihAbs === 0 && $jumlahTanpaJurnal === 0 && abs($selisihSaku) < 1000) {
        echo '<i class="fa-solid fa-circle-check me-1"></i> Neraca seimbang. Data operasional dan buku besar selaras.';
    } elseif ($selisihAbs > 0) {
        echo '<i class="fa-solid fa-triangle-exclamation me-1"></i> Neraca belum seimbang — selisih '
            . '<strong>' . htmlspecialchars($fmt($selisihAbs)) . '</strong>'
            . ($selisihNeraca > 0 ? ' (aktiva lebih besar dari pasiva)' : ' (pasiva lebih besar dari aktiva)')
            . '. Periksa jurnal, saldo akun, dan transaksi operasional.';
    } else {
        echo '<i class="fa-solid fa-info-circle me-1"></i> Neraca seimbang. Periksa indikator di bawah untuk memastikan kualitas data.';
    }
    echo '</div>';

    echo '<div class="row g-2 mb-3">';
    echo '<div class="col-md-4"><div class="border rounded p-2 h-100">';
    echo '<div class="small text-muted">Selisih neraca</div>';
    echo '<div class="fw-semibold ' . ($selisihAbs >= $threshold ? 'text-danger' : 'text-success') . '">';
    echo htmlspecialchars($fmt($selisihNeraca));
    echo '</div></div></div>';
    echo '<div class="col-md-4"><div class="border rounded p-2 h-100">';
    echo '<div class="small text-muted">Transaksi tanpa jurnal</div>';
    echo '<div class="fw-semibold ' . ($jumlahTanpaJurnal > 0 ? 'text-warning' : 'text-success') . '">';
    echo (int) $jumlahTanpaJurnal;
    echo '</div></div></div>';
    echo '<div class="col-md-4"><div class="border rounded p-2 h-100">';
    echo '<div class="small text-muted">Selisih saku vs cashless</div>';
    echo '<div class="fw-semibold ' . (abs($selisihSaku) >= 1000 ? 'text-warning' : 'text-success') . '">';
    echo htmlspecialchars($fmt($selisihSaku));
    echo '</div></div></div>';
    echo '</div>';

    echo '<div class="d-flex flex-wrap gap-2">';
    if ($showBackfill && $jumlahTanpaJurnal > 0) {
        echo '<form method="post" class="mb-0" onsubmit="return confirm(\'Buat jurnal otomatis untuk transaksi yang belum punya jurnal?\');">';
        echo '<input type="hidden" name="action" value="backfill_jurnal">';
        echo '<input type="hidden" name="per" value="' . htmlspecialchars($asOf) . '">';
        echo '<button type="submit" class="btn btn-sm btn-warning"><i class="fa-solid fa-rotate me-1"></i> Sinkronkan jurnal (' . $jumlahTanpaJurnal . ')</button>';
        echo '</form>';
    }
    echo '<a href="' . htmlspecialchars(app_href('/keuangan/neraca-perbaikan.php?per=' . urlencode($asOf))) . '" class="btn btn-sm btn-outline-secondary">Detail analisis</a>';
    echo '<a href="' . htmlspecialchars(app_href('/keuangan/rekap-kas-bulan.php')) . '" class="btn btn-sm btn-outline-primary">Rekap kas bulanan</a>';
    echo '</div>';

    $pending = $kesehatan['transaksi_tanpa_jurnal'] ?? [];
    if (is_array($pending) && $pending !== []) {
        echo '<div class="mt-3">';
        keuangan_neraca_render_diagnostik(['transaksi_tanpa_jurnal' => $pending], $fmt);
        echo '</div>';
    }

    echo '</div></div>';
}
