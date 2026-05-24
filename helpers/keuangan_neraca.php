<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_typography.php';
require_once __DIR__ . '/keuangan_akun_mutasi.php';

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
    $mutasiStmt = $pdo->prepare("
        SELECT
            a.jenis_akun,
            a.nama_akun,
            (COALESCE(a.opening_balance, 0) + COALESCE(inc.total_masuk, 0) - COALESCE(exp.total_keluar, 0)) AS saldo
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

    // Liabilitas
    $totalCashless = 0;
    if (table_exists($pdo, 'cashless_accounts')) {
        $totalCashless = (int) round((float) ($pdo->query('SELECT COALESCE(SUM(balance),0) FROM cashless_accounts')->fetchColumn() ?: 0));
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

    // Aset neto — COA + surplus operasional, lalu disesuaikan agar neraca selalu seimbang
    $asetNetoCoaBaris = keuangan_neraca_baris_from_coa($coaSaldo, 'ASET_NETO');
    $totalAsetNetoCoa = array_sum(array_column($asetNetoCoaBaris, 'nominal'));

    $ringkasanOperasi = keuangan_neraca_ringkasan_operasi($pdo, $asOf);
    $surplusOperasi = (int) ($ringkasanOperasi['surplus_operasi'] ?? 0);

    // Identitas: Aktiva = Liabilitas + Aset Neto
    $totalAsetNeto = $totalAset - $totalLiabilitas;
    $penyesuaianNeraca = $totalAsetNeto - $totalAsetNetoCoa - $surplusOperasi;

    $asetNetoBaris = $asetNetoCoaBaris;
    if ($surplusOperasi !== 0) {
        $asetNetoBaris[] = [
            'label' => 'Surplus/(defisit) operasional (tanpa titipan saku)',
            'nominal' => $surplusOperasi,
            'indent' => true,
        ];
    }
    if ($penyesuaianNeraca !== 0) {
        $asetNetoBaris[] = [
            'label' => 'Penyesuaian penyeimbang neraca',
            'nominal' => $penyesuaianNeraca,
            'indent' => true,
        ];
    }
    if ($asetNetoBaris === [] && $totalAsetNeto !== 0) {
        $asetNetoBaris[] = [
            'label' => 'Aset neto (saldo awal / simulasi)',
            'nominal' => $totalAsetNeto,
            'indent' => true,
        ];
    }

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
        'penyesuaian_neraca' => $penyesuaianNeraca,
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
        $bebanStmt = $pdo->prepare("
            SELECT COALESCE(SUM(nominal), 0) FROM keuangan_pengeluaran
            WHERE tanggal <= :t
              AND pos NOT LIKE 'Belanja Modal%'
        ");
        $bebanStmt->execute(['t' => $asOf]);
        $beban = (int) round((float) ($bebanStmt->fetchColumn() ?: 0));
    }
    if (table_exists($pdo, 'keuangan_gaji_pembimbing')) {
        $gajiStmt = $pdo->prepare('SELECT COALESCE(SUM(total_bayar), 0) FROM keuangan_gaji_pembimbing WHERE tanggal_bayar <= :t');
        $gajiStmt->execute(['t' => $asOf]);
        $beban += (int) round((float) ($gajiStmt->fetchColumn() ?: 0));
    }

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
    $pdo->exec("ALTER TABLE keuangan_akun ADD COLUMN IF NOT EXISTS opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0");
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

    echo '<div class="neraca-grid">';
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
        echo '<p class="neraca-balance-note warn">Selisih neraca ' . htmlspecialchars($fmt($selisih)) . ' — periksa jurnal dan saldo operasional.</p>';
    } else {
        echo '<p class="neraca-balance-note ok">Neraca seimbang: Jumlah Aktiva = Jumlah Pasiva.</p>';
    }
    echo '</div>';
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
