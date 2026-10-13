<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/pondok_kalender.php';
require_once __DIR__ . '/keuangan_transaksi.php';
require_once __DIR__ . '/keuangan_rekonsiliasi.php';
require_once __DIR__ . '/keuangan_aruskas.php';
require_once __DIR__ . '/keuangan_rekap_tagihan_bulan.php';
require_once __DIR__ . '/keuangan_riwayat_pembayaran.php';
require_once __DIR__ . '/keuangan_alokasi.php';

/**
 * Rekap kas masuk/keluar & saldo per bulan tagihan TA (1 s.d. bulan berjalan).
 *
 * @return array{
 *   tahun_mulai:int,
 *   tahun_selesai:int,
 *   ta_label:string,
 *   bulan_berjalan:int,
 *   bulan_berjalan_label:string,
 *   nama_lembaga:string,
 *   saldo_awal_ta:int,
 *   saldo_akhir:int,
 *   saldo_akhir_hitung:int,
 *   saldo_akhir_fisik:int,
 *   saldo_akhir_uang_nyata:int,
 *   selisih_saldo:int,
 *   baris:list<array<string, mixed>>,
 *   total:array<string, int>
 * }
 */
function keuangan_build_rekap_kas_bulanan(
    PDO $pdo,
    ?int $tahunAjaranMulai = null,
    ?int $tahunAjaranSelesai = null,
    ?int $sampaiBulan = null
): array {
    $periode = keuangan_periode_berjalan($pdo);
    $taNorm = pondok_normalisasi_tahun_ajaran_input(
        $pdo,
        $tahunAjaranMulai ?? (int) $periode['mulai'],
        $tahunAjaranSelesai ?? (int) $periode['selesai']
    );
    $tm = $taNorm['mulai'];
    $ts = $taNorm['selesai'];
    $bulanBerjalan = $sampaiBulan ?? keuangan_bulan_berjalan(null, $pdo);
    $bulanBerjalan = max(1, min(12, $bulanBerjalan));

    $namaLembaga = trim((string) app_setting($pdo, 'nama_ponpes', 'Pondok Pesantren Nailul Muna'));
    if ($namaLembaga === '') {
        $namaLembaga = 'Pondok Pesantren Nailul Muna';
    }

    $slots = pondok_bulan_slots_tahun_ajaran($pdo, $tm, $ts);
    $slotByBulan = [];
    foreach ($slots as $slot) {
        $m = (int) ($slot['bulan_tagihan'] ?? 0);
        if ($m >= 1 && $m <= 12) {
            $slotByBulan[$m] = $slot;
        }
    }

    $slotPertama = $slotByBulan[1] ?? null;
    $taMulaiTanggal = is_array($slotPertama) ? trim((string) ($slotPertama['masehi_awal'] ?? '')) : '';
    if ($taMulaiTanggal === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $taMulaiTanggal)) {
        $taMulaiTanggal = sprintf('%04d-07-01', $tm >= 1900 && $tm < 2100 ? $tm : (int) date('Y'));
    }

    $hariSebelumTa = date('Y-m-d', strtotime($taMulaiTanggal . ' -1 day') ?: time());
    $saldoBerjalan = keuangan_aruskas_total_kas($pdo, $hariSebelumTa);
    $saldoAwalTa = $saldoBerjalan;

    $today = date('Y-m-d');
    $baris = [];
    $totals = [
        'masuk_syahriyah' => 0,
        'masuk_makan' => 0,
        'masuk_saku' => 0,
        'masuk_awal_tahun' => 0,
        'masuk_lain_bayar' => 0,
        'masuk_donasi' => 0,
        'masuk_lain' => 0,
        'masuk_total' => 0,
        'keluar_syahriyah' => 0,
        'keluar_makan' => 0,
        'keluar_saku' => 0,
        'keluar_awal_tahun' => 0,
        'keluar_lain' => 0,
        'keluar' => 0,
        'bersih' => 0,
    ];

    for ($m = 1; $m <= $bulanBerjalan; $m++) {
        $slot = $slotByBulan[$m] ?? null;
        $label = is_array($slot)
            ? pondok_bulan_slot_label_tampilan($pdo, $slot)
            : pondok_bulan_label($pdo, $m, $tm, $ts);

        $dari = is_array($slot) ? trim((string) ($slot['masehi_awal'] ?? '')) : '';
        $sampai = is_array($slot) ? trim((string) ($slot['masehi_akhir'] ?? '')) : '';
        if ($dari === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari)) {
            continue;
        }
        if ($sampai === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) {
            $sampai = $dari;
        }
        if ($m === $bulanBerjalan && $today >= $dari && $today <= $sampai) {
            $sampai = $today;
        }

        $mutasi = keuangan_rekap_kas_mutasi_periode($pdo, $dari, $sampai);
        $katMasuk = keuangan_riwayat_pembayaran_ringkasan_masuk_kategori($pdo, $dari, $sampai);
        $katKeluar = keuangan_pengeluaran_ringkasan_kategori($pdo, $dari, $sampai, true);
        $saldoAwalBulan = $saldoBerjalan;
        $saldoAkhirBulan = $saldoAwalBulan + (int) $mutasi['bersih'];
        $saldoFisik = keuangan_aruskas_total_kas($pdo, $sampai);

        $row = [
            'bulan' => $m,
            'label' => $label,
            'tanggal_dari' => $dari,
            'tanggal_sampai' => $sampai,
            'periode_teks' => $dari . ' s/d ' . $sampai,
            'saldo_awal' => $saldoAwalBulan,
            'masuk_syahriyah' => (int) ($katMasuk['syahriyah'] ?? 0),
            'masuk_makan' => (int) ($katMasuk['makan'] ?? 0),
            'masuk_saku' => (int) ($katMasuk['saku'] ?? 0),
            'masuk_awal_tahun' => (int) ($katMasuk['awal_tahun'] ?? 0),
            'masuk_lain_bayar' => (int) ($katMasuk['lain'] ?? 0),
            'masuk_donasi' => (int) $mutasi['masuk_donasi'],
            'masuk_lain' => (int) $mutasi['masuk_lain'],
            'masuk_total' => (int) $mutasi['masuk_total'],
            'keluar_syahriyah' => (int) ($katKeluar['syahriyah'] ?? 0),
            'keluar_makan' => (int) ($katKeluar['makan'] ?? 0),
            'keluar_saku' => (int) ($katKeluar['saku'] ?? 0),
            'keluar_awal_tahun' => (int) ($katKeluar['awal_tahun'] ?? 0),
            'keluar_lain' => (int) ($katKeluar['lain'] ?? 0),
            'keluar' => (int) $mutasi['keluar'],
            'bersih' => (int) $mutasi['bersih'],
            'saldo_akhir' => $saldoAkhirBulan,
            'saldo_fisik' => $saldoFisik,
            'selisih_saldo' => $saldoFisik - $saldoAkhirBulan,
            'is_bulan_ini' => $m === $bulanBerjalan,
        ];
        $baris[] = $row;

        foreach ([
            'masuk_syahriyah', 'masuk_makan', 'masuk_saku', 'masuk_awal_tahun', 'masuk_lain_bayar',
            'masuk_donasi', 'masuk_lain', 'masuk_total',
            'keluar_syahriyah', 'keluar_makan', 'keluar_saku', 'keluar_awal_tahun', 'keluar_lain',
            'keluar', 'bersih',
        ] as $k) {
            $totals[$k] += (int) ($row[$k] ?? 0);
        }

        $saldoBerjalan = $saldoAkhirBulan;
    }

    $mutasiTaPenuh = keuangan_rekap_kas_mutasi_periode($pdo, $taMulaiTanggal, $today);
    $saldoAkhirHitung = $saldoAwalTa + (int) $mutasiTaPenuh['bersih'];
    $saldoAkhirFisik = keuangan_aruskas_total_kas($pdo, $today);

    $tagihanRekap = keuangan_rekap_tagihan_bulanan_ta($pdo, $tm, $ts, $bulanBerjalan);
    $baris = keuangan_rekap_kas_gabung_tagihan($baris, $tagihanRekap['baris'] ?? []);
    $baris = keuangan_rekap_kas_bulan_append_baris_awal_tahun($baris, $tagihanRekap, $totals, $taMulaiTanggal, $today);

    require_once __DIR__ . '/keuangan_diagnostik.php';
    $kasBank = keuangan_dashboard_kas_bank_detail($pdo);

    return [
        'tahun_mulai' => $tm,
        'tahun_selesai' => $ts,
        'ta_label' => pondok_tahun_ajaran_label($pdo, ['mulai' => $tm, 'selesai' => $ts]),
        'bulan_berjalan' => $bulanBerjalan,
        'bulan_berjalan_label' => pondok_bulan_label($pdo, $bulanBerjalan, $tm, $ts),
        'nama_lembaga' => $namaLembaga,
        'saldo_awal_ta' => $saldoAwalTa,
        'saldo_akhir' => $saldoAkhirHitung,
        'saldo_akhir_hitung' => $saldoAkhirHitung,
        'saldo_akhir_fisik' => $saldoAkhirFisik,
        'saldo_akhir_uang_nyata' => $saldoAkhirFisik,
        'selisih_saldo' => $saldoAkhirFisik - $saldoAkhirHitung,
        'baris' => $baris,
        'total' => $totals,
        'tagihan' => $tagihanRekap,
        'kas_bank' => $kasBank,
    ];
}

function keuangan_rekap_kas_bulan_css(): string
{
    return '
body.rekap-kas-bulan-page .app-main .container-fluid { max-width: none; }
.rekap-kas-table-wrap {
    overflow-x: auto; overflow-y: visible;
    -webkit-overflow-scrolling: touch;
    scrollbar-color: #94a3b8 #f1f5f9;
    border: 1px solid #e2e8f0; border-radius: 8px;
    max-width: 100%;
}
.rekap-kas-table {
    --rekap-sticky-bulan: 7.25rem;
    --rekap-sticky-periode: 9rem;
    width: 100%; min-width: 1180px; font-size: 0.875rem;
    margin-bottom: 0; border-collapse: separate; border-spacing: 0;
}
.rekap-kas-table th, .rekap-kas-table td {
    padding: 0.45rem 0.6rem; vertical-align: middle;
    border: 1px solid #e2e8f0;
}
.rekap-kas-table thead th {
    background: #134e4a; color: #fff !important;
    font-weight: 600; white-space: nowrap; text-align: center;
}
.rekap-kas-table thead .rekap-kas-head-meta th {
    font-size: 0.7rem; font-weight: 700; letter-spacing: 0.04em;
    text-transform: uppercase; padding: 0.4rem 0.5rem;
    background: #0f4c5c; position: sticky; top: 0; z-index: 14;
}
.rekap-kas-table thead .rekap-kas-head-group th {
    font-size: 0.78rem; letter-spacing: 0.02em;
    padding-top: 0.5rem; padding-bottom: 0.3rem;
    position: sticky; top: 1.65rem; z-index: 13;
}
.rekap-kas-table thead .rekap-kas-head-detail th {
    font-size: 0.72rem; font-weight: 500; background: #115e59;
    padding-top: 0.3rem; padding-bottom: 0.5rem;
    position: sticky; top: 3.15rem; z-index: 12;
}
.rekap-kas-table .rekap-kas-grp-mutasi { background: #0f766e !important; }
.rekap-kas-table .rekap-kas-grp-saldo-mid { background: #475569 !important; }
.rekap-kas-table .rekap-kas-grp-periode { background: #0f4c5c !important; }
.rekap-kas-table .rekap-kas-grp-masuk { background: #0d9488 !important; }
.rekap-kas-table .rekap-kas-grp-keluar { background: #b91c1c !important; }
.rekap-kas-table .rekap-kas-grp-verif { background: #1d4ed8 !important; }
.rekap-kas-table .rekap-kas-grp-tagihan { background: #6d28d9 !important; }
.rekap-kas-table tr.rekap-kas-row-awal-tahun td { background: #f5f3ff; border-top: 2px solid #c4b5fd; }
.rekap-kas-table tr.rekap-kas-row-awal-tahun td.rekap-kas-col-bulan,
.rekap-kas-table tr.rekap-kas-row-awal-tahun td.rekap-kas-col-saldo-awal { background: #f5f3ff; }
.rekap-kas-table tr.rekap-kas-row-awal-tahun .rekap-kas-col-bulan strong { color: #5b21b6; }
.rekap-kas-table td.rekap-kas-tagihan { background: #faf5ff; }
.rekap-kas-table td.rekap-kas-tagihan-sisa { background: #fffbeb; color: #92400e; font-weight: 600; }
.rekap-kas-table .text-end, .rekap-kas-table td.keu-money {
    text-align: right; font-variant-numeric: tabular-nums;
    white-space: nowrap; min-width: 5.25rem;
}
.rekap-kas-table .rekap-kas-col-bulan {
    min-width: var(--rekap-sticky-bulan); max-width: var(--rekap-sticky-bulan);
    text-align: left !important; position: sticky; left: 0; z-index: 4;
    background: #fff; box-shadow: 2px 0 4px rgba(15, 76, 92, 0.06);
}
.rekap-kas-table .rekap-kas-col-saldo-awal {
    position: sticky; left: var(--rekap-sticky-bulan); z-index: 3;
    background: #fff; box-shadow: 2px 0 4px rgba(15, 76, 92, 0.04);
}
.rekap-kas-table thead .rekap-kas-col-bulan { z-index: 16; }
.rekap-kas-table thead .rekap-kas-col-saldo-awal { z-index: 15; background: #134e4a; color: #fff !important; }
.rekap-kas-table thead .rekap-kas-col-periode { background: #134e4a; }
.rekap-kas-table thead .rekap-kas-col-saldo { background: #134e4a; }
.rekap-kas-table tfoot .rekap-kas-col-bulan,
.rekap-kas-table tfoot .rekap-kas-col-saldo-awal { background: #e2e8f0; z-index: 4; }
.rekap-kas-table .rekap-kas-col-periode {
    font-size: 0.78rem; max-width: var(--rekap-sticky-periode);
    white-space: normal; line-height: 1.25;
}
@media (min-width: 769px) {
    .rekap-kas-table .rekap-kas-col-saldo-awal {
        left: calc(var(--rekap-sticky-bulan) + var(--rekap-sticky-periode));
    }
}
.rekap-kas-table tbody .rekap-kas-col-periode { color: #64748b; font-size: 0.78rem; }
.rekap-kas-table td.rekap-kas-masuk { background: #f0fdf4; }
.rekap-kas-table td.rekap-kas-masuk-total { background: #dcfce7; font-weight: 600; color: #166534; }
.rekap-kas-table td.rekap-kas-keluar { background: #fef2f2; color: #b91c1c; font-weight: 600; }
.rekap-kas-table td.rekap-kas-saldo { background: #f8fafc; font-weight: 600; }
.rekap-kas-table td.rekap-kas-verif { background: #eff6ff; }
.rekap-kas-table .rekap-kas-zero { color: #94a3b8; }
.rekap-kas-table tbody tr.bulan-ini { background: #ecfdf5; }
.rekap-kas-table tbody tr.bulan-ini td.rekap-kas-col-bulan { background: #ecfdf5; }
.rekap-kas-table tbody tr.bulan-ini td.rekap-kas-col-saldo-awal { background: #ecfdf5; }
.rekap-kas-table tbody tr.bulan-ini td.rekap-kas-masuk { background: #d1fae5; }
.rekap-kas-table tbody tr.bulan-ini td.rekap-kas-keluar { background: #fee2e2; }
.rekap-kas-table tbody tr:hover td { filter: brightness(0.99); }
.rekap-kas-table tfoot tr.rekap-kas-foot-total td {
    font-weight: 700; background: #e2e8f0;
    border-top: 2px solid #64748b;
}
.rekap-kas-table tfoot td.rekap-kas-masuk-total { color: #166534; }
.rekap-kas-table tfoot td.rekap-kas-keluar { color: #b91c1c; }
.rekap-kas-table .selisih-ok { color: #64748b; }
.rekap-kas-table .selisih-warn { color: #b45309; font-weight: 700; }
.rekap-kas-saldo-akun-block { margin-top: 1rem; }
.rekap-kas-saldo-akun-block .app-mini-stat { height: 100%; }
@media (max-width: 768px) {
    .rekap-kas-table .rekap-kas-col-periode { display: none; }
    .rekap-kas-table thead .rekap-kas-col-periode { display: none; }
}
.rekap-kas-table td.rekap-kas-masuk a.rekap-kas-link { color: inherit; border-bottom: 1px dotted currentColor; }
.rekap-kas-table td.rekap-kas-masuk a.rekap-kas-link:hover { color: #0d6efd; }
.rekap-kas-table td.rekap-kas-keluar a.rekap-kas-link { color: inherit; border-bottom: 1px dotted currentColor; }
.rekap-kas-table td.rekap-kas-keluar a.rekap-kas-link:hover { color: #dc3545; }
.rekap-kas-table td.rekap-kas-keluar-total { font-weight: 700; }
.rekap-kas-table-wrap--ringkas { overflow-x: auto; max-width: 100%; }
@media (min-width: 992px) {
    .rekap-kas-table-wrap--ringkas { overflow-x: visible; }
}
.rekap-kas-table--ringkas {
    min-width: 0; table-layout: fixed; font-size: 0.8125rem;
}
.rekap-kas-table--ringkas thead th {
    position: sticky; top: 0; z-index: 10;
    padding: 0.35rem 0.45rem; font-size: 0.75rem; line-height: 1.2;
    white-space: normal;
}
.rekap-kas-table--ringkas th, .rekap-kas-table--ringkas td {
    padding: 0.35rem 0.45rem;
}
.rekap-kas-table--ringkas .rekap-kas-col-bulan {
    min-width: 0; max-width: none; width: 14%;
    position: sticky; left: 0; z-index: 4;
    white-space: normal; line-height: 1.25;
}
.rekap-kas-table--ringkas thead .rekap-kas-col-bulan { z-index: 11; }
.rekap-kas-table--ringkas .rekap-kas-col-bulan .rekap-kas-periode-hint {
    display: block; font-size: 0.72rem; font-weight: 400; color: #64748b; margin-top: 0.15rem;
}
.rekap-kas-table--ringkas .text-end { min-width: 0; }
.rekap-kas-table--ringkas col.rekap-kas-col-num { width: 9.5%; }
@media print {
    .rekap-kas-table { font-size: 8pt; min-width: 0; }
    .rekap-kas-table-wrap { border: none; overflow: visible; }
    .rekap-kas-table thead th, .rekap-kas-table td.rekap-kas-masuk,
    .rekap-kas-table td.rekap-kas-keluar, .rekap-kas-table tbody tr.bulan-ini td {
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    .rekap-kas-table .rekap-kas-col-bulan,
    .rekap-kas-table .rekap-kas-col-saldo-awal,
    .rekap-kas-table thead th { position: static; box-shadow: none; }
}
.rekap-kas-pos-nav {
    display: flex; flex-wrap: wrap; gap: 0.35rem 0.5rem;
    padding: 0.5rem 0.65rem; margin-bottom: 0.75rem;
    background: #f1f5f9; border-radius: 8px; border: 1px solid #e2e8f0;
    position: sticky; top: 0.5rem; z-index: 5;
}
.rekap-kas-pos-nav a { font-size: 0.78rem; white-space: nowrap; }
.rekap-kas-pos-section { margin-bottom: 1.25rem; scroll-margin-top: 4rem; }
.rekap-kas-pos-section .card-header { padding: 0.5rem 0.75rem; font-size: 0.9rem; }
.rekap-kas-pos-table-wrap { overflow-x: auto; max-width: 100%; border: 1px solid #e2e8f0; border-radius: 8px; }
.rekap-kas-pos-table {
    width: 100%; min-width: 0; table-layout: fixed; font-size: 0.8125rem; margin-bottom: 0;
}
.rekap-kas-pos-table th, .rekap-kas-pos-table td {
    padding: 0.35rem 0.45rem; vertical-align: middle; border: 1px solid #e2e8f0;
}
.rekap-kas-pos-table thead th {
    background: #134e4a; color: #fff !important; font-size: 0.72rem; font-weight: 600;
}
.rekap-kas-pos-table tfoot td { font-weight: 700; background: #e2e8f0; }
.rekap-kas-pos-table .text-end { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.rekap-kas-pos--awal-tahun tbody tr td { background: #f5f3ff; }
.rekap-kas-rekon-panel { font-size: 0.875rem; }
.rekap-kas-rekon-panel table { margin-bottom: 0; }
';
}

/**
 * @return array<string, array{masuk?:string,keluar?:string,riwayat_kat:string,virtual?:bool}>
 */
function keuangan_rekap_kas_mutasi_pos_map(bool $showSaku): array
{
    $map = [
        'syahriyah' => ['masuk' => 'masuk_syahriyah', 'keluar' => 'keluar_syahriyah', 'riwayat_kat' => 'syahriyah'],
        'makan' => ['masuk' => 'masuk_makan', 'keluar' => 'keluar_makan', 'riwayat_kat' => 'makan'],
    ];
    if ($showSaku) {
        $map['saku'] = ['masuk' => 'masuk_saku', 'keluar' => 'keluar_saku', 'riwayat_kat' => 'saku'];
    }

    return $map;
}

/**
 * Mutasi kas per POS dari baris rekap (tanpa query baru).
 *
 * @return array<string, array{
 *   bulanan:array<int,array{masuk:int,keluar:int}>,
 *   subtotal:array{masuk:int,keluar:int},
 *   riwayat_kat:string
 * }>
 */
function keuangan_rekap_kas_mutasi_by_pos_from_rekap(array $rekap, bool $showSaku): array
{
    $map = keuangan_rekap_kas_mutasi_pos_map($showSaku);
    $out = [];
    foreach ($map as $slug => $cfg) {
        $out[$slug] = [
            'bulanan' => [],
            'subtotal' => ['masuk' => 0, 'keluar' => 0],
            'riwayat_kat' => (string) ($cfg['riwayat_kat'] ?? ''),
        ];
    }
    $out['donasi'] = [
        'bulanan' => [],
        'subtotal' => ['masuk' => 0, 'keluar' => 0],
        'riwayat_kat' => '',
        'kas_only' => true,
    ];
    $out['lain'] = [
        'bulanan' => [],
        'subtotal' => ['masuk' => 0, 'keluar' => 0],
        'riwayat_kat' => '',
        'kas_only' => true,
    ];

    $tot = is_array($rekap['total'] ?? null) ? $rekap['total'] : [];
    foreach ($map as $slug => $cfg) {
        $out[$slug]['subtotal']['masuk'] = (int) ($tot[$cfg['masuk'] ?? ''] ?? 0);
        $out[$slug]['subtotal']['keluar'] = (int) ($tot[$cfg['keluar'] ?? ''] ?? 0);
    }
    $out['donasi']['subtotal']['masuk'] = (int) ($tot['masuk_donasi'] ?? 0);
    $out['lain']['subtotal']['masuk'] = (int) ($tot['masuk_lain_bayar'] ?? 0) + (int) ($tot['masuk_lain'] ?? 0);
    $out['lain']['subtotal']['keluar'] = (int) ($tot['keluar_lain'] ?? 0);

    foreach ($rekap['baris'] ?? [] as $row) {
        if (!empty($row['is_tagihan_awal_tahun'])) {
            continue;
        }
        $m = (int) ($row['bulan'] ?? 0);
        if ($m < 1 || $m > 12) {
            continue;
        }
        foreach ($map as $slug => $cfg) {
            $mk = (string) ($cfg['masuk'] ?? '');
            $kk = (string) ($cfg['keluar'] ?? '');
            $out[$slug]['bulanan'][$m] = [
                'masuk' => $mk !== '' ? (int) ($row[$mk] ?? 0) : 0,
                'keluar' => $kk !== '' ? (int) ($row[$kk] ?? 0) : 0,
                'tanggal_dari' => (string) ($row['tanggal_dari'] ?? ''),
                'tanggal_sampai' => (string) ($row['tanggal_sampai'] ?? ''),
                'label' => (string) ($row['label'] ?? ('Bulan ' . $m)),
                'periode_teks' => (string) ($row['periode_teks'] ?? ''),
            ];
        }
        $out['donasi']['bulanan'][$m] = [
            'masuk' => (int) ($row['masuk_donasi'] ?? 0),
            'keluar' => 0,
            'tanggal_dari' => (string) ($row['tanggal_dari'] ?? ''),
            'tanggal_sampai' => (string) ($row['tanggal_sampai'] ?? ''),
            'label' => (string) ($row['label'] ?? ('Bulan ' . $m)),
            'periode_teks' => (string) ($row['periode_teks'] ?? ''),
        ];
        $out['lain']['bulanan'][$m] = [
            'masuk' => (int) ($row['masuk_lain_bayar'] ?? 0) + (int) ($row['masuk_lain'] ?? 0),
            'keluar' => (int) ($row['keluar_lain'] ?? 0),
            'tanggal_dari' => (string) ($row['tanggal_dari'] ?? ''),
            'tanggal_sampai' => (string) ($row['tanggal_sampai'] ?? ''),
            'label' => (string) ($row['label'] ?? ('Bulan ' . $m)),
            'periode_teks' => (string) ($row['periode_teks'] ?? ''),
        ];
    }

    return $out;
}

/**
 * @return array{dari:string,sampai:string}
 */
function keuangan_rekap_kas_baris_awal_tahun_periode(array $rekap): array
{
    foreach ($rekap['baris'] ?? [] as $row) {
        if (empty($row['is_tagihan_awal_tahun'])) {
            continue;
        }
        $dari = trim((string) ($row['tanggal_dari'] ?? ''));
        $sampai = trim((string) ($row['tanggal_sampai'] ?? ''));
        if ($dari !== '' && $sampai !== '') {
            return ['dari' => $dari, 'sampai' => $sampai];
        }
    }

    return ['dari' => '', 'sampai' => ''];
}

/**
 * Kas masuk riil per slug komponen Awal Tahun (agregasi transaksi pembayaran).
 *
 * @return array<string, int>
 */
function keuangan_rekap_kas_masuk_awal_tahun_by_slug(PDO $pdo, array $rekap): array
{
    $out = [];
    if (!table_exists($pdo, 'keuangan_pembayaran') || !table_exists($pdo, 'keuangan_pembayaran_detail')) {
        return $out;
    }
    $per = keuangan_rekap_kas_baris_awal_tahun_periode($rekap);
    $dari = $per['dari'];
    $sampai = $per['sampai'];
    if ($dari === '' || $sampai === '') {
        return $out;
    }

    $st = $pdo->prepare('
        SELECT LOWER(TRIM(d.pos_slug)) AS slug, COALESCE(SUM(d.nominal), 0) AS total
        FROM keuangan_pembayaran_detail d
        INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
        WHERE p.tanggal_bayar BETWEEN :dari AND :sampai
          AND p.jenis_periode = \'AWAL_TAHUN\'
        GROUP BY LOWER(TRIM(d.pos_slug))
    ');
    $st->execute(['dari' => $dari, 'sampai' => $sampai]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $slug = strtolower(trim((string) ($row['slug'] ?? '')));
        if ($slug === '') {
            continue;
        }
        $out[$slug] = (int) round((float) ($row['total'] ?? 0));
    }

    return $out;
}

function keuangan_rekap_kas_pos_should_show_section(
    array $posEntry,
    array $mutasiEntry,
    bool $isKasOnly = false
): bool {
    if (!empty($posEntry['wajib'])) {
        return true;
    }
    if ($isKasOnly) {
        $m = (int) ($mutasiEntry['subtotal']['masuk'] ?? 0);
        $k = (int) ($mutasiEntry['subtotal']['keluar'] ?? 0);

        return $m !== 0 || $k !== 0;
    }
    $st = is_array($posEntry['subtotal_ta'] ?? null) ? $posEntry['subtotal_ta'] : [];
    $tag = (int) ($st['expected'] ?? 0) + (int) ($st['paid'] ?? 0);
    $m = (int) ($mutasiEntry['subtotal']['masuk'] ?? 0);
    $k = (int) ($mutasiEntry['subtotal']['keluar'] ?? 0);

    return $tag !== 0 || $m !== 0 || $k !== 0;
}

/**
 * @param callable(int): string $fmt
 */
function keuangan_rekap_kas_bulan_render_pos_table_header(bool $kasOnly): void
{
    echo '<thead><tr>';
    echo '<th class="text-start">Periode</th>';
    if (!$kasOnly) {
        echo '<th class="text-end">Target</th><th class="text-end">Terbayar</th><th class="text-end">Sisa</th><th class="text-end">%</th>';
    }
    echo '<th class="text-end">Kas masuk</th><th class="text-end">Kas keluar</th>';
    echo '<th class="text-end" title="Kas masuk − kas keluar (bukan saldo)">Netto</th>';
    echo '</tr></thead>';
}

/**
 * @param callable(int): string $fmt
 */
function keuangan_rekap_kas_bulan_echo_pos_netto_td(int $netto, callable $fmt, bool $forceDash = false, ?string $dashTitle = null): void
{
    if ($forceDash) {
        $title = $dashTitle !== null && $dashTitle !== ''
            ? ' title="' . htmlspecialchars($dashTitle) . '"' : '';
        echo '<td class="text-end rekap-kas-netto"' . $title . '><span class="rekap-kas-zero">—</span></td>';

        return;
    }
    if ($netto === 0) {
        echo '<td class="text-end rekap-kas-netto keu-money"><span class="rekap-kas-zero">—</span></td>';

        return;
    }
    if ($netto > 0) {
        echo '<td class="text-end rekap-kas-netto rekap-kas-masuk keu-money">' . htmlspecialchars($fmt($netto)) . '</td>';

        return;
    }
    echo '<td class="text-end rekap-kas-netto rekap-kas-keluar keu-money">−' . htmlspecialchars($fmt(abs($netto))) . '</td>';
}

/**
 * @param callable(int): string $fmt
 */
function keuangan_rekap_kas_bulan_render_pos_sections(
    array $rekap,
    array $matrix,
    array $mutasiByPos,
    callable $fmt,
    bool $showSaku,
    ?PDO $pdo = null
): void {
    $bySlug = is_array($matrix['by_slug'] ?? null) ? $matrix['by_slug'] : [];
    $bulananSlugs = is_array($matrix['bulanan_slugs'] ?? null) ? $matrix['bulanan_slugs'] : [];
    $awalSlugs = is_array($matrix['awal_tahun_slugs'] ?? null) ? $matrix['awal_tahun_slugs'] : [];
    $sampai = (int) ($rekap['bulan_berjalan'] ?? 0);
    if ($bySlug === [] || $sampai < 1) {
        return;
    }

    $navItems = [];
    foreach ($bulananSlugs as $slug) {
        if ($slug === 'saku' && !$showSaku) {
            continue;
        }
        $pos = $bySlug[$slug] ?? null;
        $mut = $mutasiByPos[$slug] ?? ['subtotal' => ['masuk' => 0, 'keluar' => 0]];
        if (!is_array($pos) || !keuangan_rekap_kas_pos_should_show_section($pos, $mut)) {
            continue;
        }
        $navItems[] = ['id' => 'pos-' . $slug, 'label' => (string) ($pos['nama'] ?? $slug)];
    }
    if ($awalSlugs !== []) {
        $navItems[] = ['id' => 'pos-awal-tahun', 'label' => 'Awal Tahun'];
    }
    foreach (['donasi' => 'Donasi', 'lain' => 'Lain-lain'] as $vslug => $vlabel) {
        $mut = $mutasiByPos[$vslug] ?? null;
        if (is_array($mut) && keuangan_rekap_kas_pos_should_show_section(['wajib' => false], $mut, true)) {
            $navItems[] = ['id' => 'pos-kas-' . $vslug, 'label' => $vlabel . ' (kas)'];
        }
    }

    echo '<div id="rekap-rincian-pos" class="rekap-kas-pos-root mt-3">';
    echo '<h2 class="h6 fw-semibold mb-2">Rincian per POS</h2>';
    if ($navItems !== []) {
        echo '<nav class="rekap-kas-pos-nav" aria-label="Loncat ke POS">';
        foreach ($navItems as $item) {
            echo '<a class="badge text-bg-light border text-decoration-none" href="#' . htmlspecialchars((string) $item['id']) . '">';
            echo htmlspecialchars((string) $item['label']);
            echo '</a>';
        }
        echo '</nav>';
    }

    foreach ($bulananSlugs as $slug) {
        if ($slug === 'saku' && !$showSaku) {
            continue;
        }
        $pos = $bySlug[$slug] ?? null;
        $mut = $mutasiByPos[$slug] ?? ['bulanan' => [], 'subtotal' => ['masuk' => 0, 'keluar' => 0], 'riwayat_kat' => ''];
        if (!is_array($pos) || !keuangan_rekap_kas_pos_should_show_section($pos, $mut)) {
            continue;
        }
        $nama = (string) ($pos['nama'] ?? $slug);
        echo '<section class="rekap-kas-pos-section card shadow-sm border-0" id="pos-' . htmlspecialchars($slug) . '">';
        echo '<div class="card-header bg-white fw-semibold">' . htmlspecialchars($nama);
        if (!empty($pos['wajib'])) {
            echo ' <span class="badge text-bg-secondary keu-status-badge">Wajib</span>';
        }
        echo '<div class="small text-muted fw-normal">Tagihan bulanan · TA ' . htmlspecialchars((string) ($rekap['ta_label'] ?? '')) . ', bulan 1–' . $sampai . '</div>';
        echo '</div><div class="card-body p-2 p-md-3">';
        echo '<div class="rekap-kas-pos-table-wrap"><table class="table table-sm rekap-kas-pos-table">';
        keuangan_rekap_kas_bulan_render_pos_table_header(false);
        echo '<tbody>';
        for ($m = 1; $m <= $sampai; $m++) {
            $tag = is_array($pos['bulanan'][$m] ?? null) ? $pos['bulanan'][$m] : ['expected' => 0, 'paid' => 0, 'sisa' => 0, 'pct' => 0];
            $km = is_array($mut['bulanan'][$m] ?? null) ? $mut['bulanan'][$m] : [];
            $dari = (string) ($km['tanggal_dari'] ?? '');
            $sampaiB = (string) ($km['tanggal_sampai'] ?? '');
            $kat = (string) ($mut['riwayat_kat'] ?? '');
            $hrefMasuk = $dari !== '' && $sampaiB !== '' && $kat !== ''
                ? keuangan_riwayat_pembayaran_href($dari, $sampaiB, 'masuk', 'kat:' . $kat) : null;
            $hrefKeluar = $dari !== '' && $sampaiB !== ''
                ? keuangan_riwayat_pembayaran_href($dari, $sampaiB, 'keluar', '') : null;
            echo '<tr>';
            echo '<td><strong>' . htmlspecialchars((string) ($km['label'] ?? ('Bulan ' . $m))) . '</strong>';
            $hint = trim((string) ($km['periode_teks'] ?? ''));
            if ($hint !== '') {
                echo '<br><span class="small text-muted">' . htmlspecialchars($hint) . '</span>';
            }
            echo '</td>';
            echo '<td class="text-end rekap-kas-tagihan keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tag['expected'] ?? 0), $fmt) . '</td>';
            echo '<td class="text-end rekap-kas-tagihan keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tag['paid'] ?? 0), $fmt) . '</td>';
            echo '<td class="text-end rekap-kas-tagihan keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tag['sisa'] ?? 0), $fmt) . '</td>';
            $pct = (int) ($tag['pct'] ?? 0);
            echo '<td class="text-end">' . ($pct > 0 || (int) ($tag['expected'] ?? 0) > 0 ? htmlspecialchars((string) $pct) . '%' : '—') . '</td>';
            $masukB = (int) ($km['masuk'] ?? 0);
            $keluarB = (int) ($km['keluar'] ?? 0);
            echo '<td class="text-end rekap-kas-masuk keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal($masukB, $fmt, 'masuk', $hrefMasuk) . '</td>';
            echo '<td class="text-end rekap-kas-keluar keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal($keluarB, $fmt, 'keluar', $hrefKeluar) . '</td>';
            keuangan_rekap_kas_bulan_echo_pos_netto_td($masukB - $keluarB, $fmt);
            echo '</tr>';
        }
        $subB = is_array($pos['subtotal_bulanan'] ?? null) ? $pos['subtotal_bulanan'] : [];
        $subM = is_array($mut['subtotal'] ?? null) ? $mut['subtotal'] : [];
        $subMasuk = (int) ($subM['masuk'] ?? 0);
        $subKeluar = (int) ($subM['keluar'] ?? 0);
        echo '</tbody><tfoot><tr>';
        echo '<td>Subtotal POS (bulan 1–' . $sampai . ')</td>';
        echo '<td class="text-end keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($subB['expected'] ?? 0), $fmt) . '</td>';
        echo '<td class="text-end keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($subB['paid'] ?? 0), $fmt) . '</td>';
        echo '<td class="text-end keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($subB['sisa'] ?? 0), $fmt) . '</td>';
        echo '<td class="text-end">' . ((int) ($subB['pct'] ?? 0) > 0 ? (int) ($subB['pct'] ?? 0) . '%' : '—') . '</td>';
        echo '<td class="text-end keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal($subMasuk, $fmt, 'masuk') . '</td>';
        echo '<td class="text-end keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal($subKeluar, $fmt, 'keluar') . '</td>';
        keuangan_rekap_kas_bulan_echo_pos_netto_td($subMasuk - $subKeluar, $fmt);
        echo '</tr></tfoot></table></div></div></section>';
    }

    $awalVisible = [];
    foreach ($awalSlugs as $slug) {
        $pos = $bySlug[$slug] ?? null;
        if (!is_array($pos)) {
            continue;
        }
        $at = is_array($pos['awal_tahun'] ?? null) ? $pos['awal_tahun'] : [];
        if ((int) ($at['expected'] ?? 0) === 0 && (int) ($at['paid'] ?? 0) === 0 && empty($pos['wajib'])) {
            continue;
        }
        $awalVisible[] = $pos;
    }
    if ($awalVisible !== []) {
        $masukAtBySlug = $pdo instanceof PDO
            ? keuangan_rekap_kas_masuk_awal_tahun_by_slug($pdo, $rekap)
            : [];
        $atPer = keuangan_rekap_kas_baris_awal_tahun_periode($rekap);
        $atDari = $atPer['dari'];
        $atSampai = $atPer['sampai'];
        $totRekap = is_array($rekap['total'] ?? null) ? $rekap['total'] : [];
        $keluarAtTa = (int) ($totRekap['keluar_awal_tahun'] ?? 0);
        $masukAtTa = (int) ($totRekap['masuk_awal_tahun'] ?? 0);
        $hrefKeluarAt = $atDari !== '' && $atSampai !== ''
            ? keuangan_riwayat_pembayaran_href($atDari, $atSampai, 'keluar', '') : null;

        echo '<section class="rekap-kas-pos-section card shadow-sm border-0 rekap-kas-pos--awal-tahun" id="pos-awal-tahun">';
        echo '<div class="card-header bg-white fw-semibold">Awal Tahun (komponen)<div class="small text-muted fw-normal">Kewajiban awal TA — bukan bulan tagihan 1–12</div></div>';
        echo '<div class="card-body p-2 p-md-3"><div class="rekap-kas-pos-table-wrap"><table class="table table-sm rekap-kas-pos-table rekap-kas-pos--awal-tahun">';
        keuangan_rekap_kas_bulan_render_pos_table_header(false);
        echo '<tbody>';
        $sumExp = 0;
        $sumPaid = 0;
        $sumMasukSlug = 0;
        foreach ($awalVisible as $pos) {
            $slug = strtolower(trim((string) ($pos['slug'] ?? '')));
            $at = is_array($pos['awal_tahun'] ?? null) ? $pos['awal_tahun'] : [];
            $masukSlug = (int) ($masukAtBySlug[$slug] ?? 0);
            $sumExp += (int) ($at['expected'] ?? 0);
            $sumPaid += (int) ($at['paid'] ?? 0);
            $sumMasukSlug += $masukSlug;
            $hrefMasukSlug = $atDari !== '' && $atSampai !== '' && $slug !== '' && $masukSlug !== 0
                ? keuangan_riwayat_pembayaran_href($atDari, $atSampai, 'masuk', 'pay:' . $slug) : null;
            echo '<tr class="rekap-kas-row-awal-tahun">';
            echo '<td><strong>' . htmlspecialchars((string) ($pos['nama'] ?? '')) . '</strong></td>';
            echo '<td class="text-end keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($at['expected'] ?? 0), $fmt) . '</td>';
            echo '<td class="text-end keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($at['paid'] ?? 0), $fmt) . '</td>';
            echo '<td class="text-end keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($at['sisa'] ?? 0), $fmt) . '</td>';
            $pct = (int) ($at['pct'] ?? 0);
            echo '<td class="text-end">' . ($pct > 0 || (int) ($at['expected'] ?? 0) > 0 ? $pct . '%' : '—') . '</td>';
            echo '<td class="text-end rekap-kas-masuk keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal($masukSlug, $fmt, 'masuk', $hrefMasukSlug) . '</td>';
            echo '<td class="text-end rekap-kas-keluar" title="Pengeluaran operasional AT tidak dipecah per komponen di sistem"><span class="rekap-kas-zero">—</span></td>';
            keuangan_rekap_kas_bulan_echo_pos_netto_td(
                0,
                $fmt,
                true,
                'Netto agregat di subtotal (kas keluar AT tidak per komponen)'
            );
            echo '</tr>';
        }
        $sumSisa = max(0, $sumExp - $sumPaid);
        echo '</tbody><tfoot><tr><td>Subtotal Awal Tahun</td>';
        echo '<td class="text-end keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal($sumExp, $fmt) . '</td>';
        echo '<td class="text-end keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal($sumPaid, $fmt) . '</td>';
        echo '<td class="text-end keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal($sumSisa, $fmt) . '</td>';
        echo '<td class="text-end">' . ($sumExp > 0 ? (int) round(($sumPaid / $sumExp) * 100) . '%' : '—') . '</td>';
        echo '<td class="text-end keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal($sumMasukSlug, $fmt, 'masuk') . '</td>';
        echo '<td class="text-end rekap-kas-keluar keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal($keluarAtTa, $fmt, 'keluar', $hrefKeluarAt) . '</td>';
        keuangan_rekap_kas_bulan_echo_pos_netto_td($sumMasukSlug - $keluarAtTa, $fmt);
        echo '</tr></tfoot></table></div>';
        $totAt = is_array($rekap['tagihan']['awal_tahun'] ?? null) ? $rekap['tagihan']['awal_tahun'] : [];
        echo '<p class="small text-muted mb-0 mt-2">Total agregat Awal Tahun (semua komponen): target ';
        echo htmlspecialchars($fmt((int) ($totAt['expected'] ?? 0))) . ', terbayar tagihan ' . htmlspecialchars($fmt((int) ($totAt['paid'] ?? 0))) . '.';
        echo ' Kas masuk AT (rekap): ' . htmlspecialchars($fmt($masukAtTa));
        if ($sumMasukSlug !== $masukAtTa && $pdo instanceof PDO) {
            echo ' · jumlah per komponen ' . htmlspecialchars($fmt($sumMasukSlug));
        }
        echo '. Kas keluar Awal Tahun (agregat): ' . htmlspecialchars($fmt($keluarAtTa)) . '.';
        echo ' Terbayar = status tagihan; kas masuk = aliran pembayaran ke kas.</p>';
        echo '</div></section>';
    }

    foreach (['donasi' => 'Donasi', 'lain' => 'Lain-lain'] as $vslug => $vtitle) {
        $mut = $mutasiByPos[$vslug] ?? null;
        if (!is_array($mut) || !keuangan_rekap_kas_pos_should_show_section(['wajib' => false], $mut, true)) {
            continue;
        }
        echo '<section class="rekap-kas-pos-section card shadow-sm border-0" id="pos-kas-' . htmlspecialchars($vslug) . '">';
        echo '<div class="card-header bg-white fw-semibold">' . htmlspecialchars($vtitle) . ' <span class="badge bg-success-subtle text-success">Kas masuk</span>';
        echo '<div class="small text-muted fw-normal">Mutasi riil — bukan tagihan POS</div></div>';
        echo '<div class="card-body p-2 p-md-3"><div class="rekap-kas-pos-table-wrap"><table class="table table-sm rekap-kas-pos-table">';
        keuangan_rekap_kas_bulan_render_pos_table_header(true);
        echo '<tbody>';
        for ($m = 1; $m <= $sampai; $m++) {
            $km = is_array($mut['bulanan'][$m] ?? null) ? $mut['bulanan'][$m] : [];
            $dari = (string) ($km['tanggal_dari'] ?? '');
            $sampaiB = (string) ($km['tanggal_sampai'] ?? '');
            $hrefMasuk = $dari !== '' && $sampaiB !== '' ? keuangan_riwayat_pembayaran_href($dari, $sampaiB, 'masuk', '') : null;
            $hrefKeluar = $dari !== '' && $sampaiB !== '' ? keuangan_riwayat_pembayaran_href($dari, $sampaiB, 'keluar', '') : null;
            $masukB = (int) ($km['masuk'] ?? 0);
            $keluarB = (int) ($km['keluar'] ?? 0);
            echo '<tr><td><strong>' . htmlspecialchars((string) ($km['label'] ?? ('Bulan ' . $m))) . '</strong></td>';
            echo '<td class="text-end rekap-kas-masuk keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal($masukB, $fmt, 'masuk', $hrefMasuk) . '</td>';
            echo '<td class="text-end rekap-kas-keluar keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal($keluarB, $fmt, 'keluar', $hrefKeluar) . '</td>';
            keuangan_rekap_kas_bulan_echo_pos_netto_td($masukB - $keluarB, $fmt);
            echo '</tr>';
        }
        $subM = is_array($mut['subtotal'] ?? null) ? $mut['subtotal'] : [];
        $subMasuk = (int) ($subM['masuk'] ?? 0);
        $subKeluar = (int) ($subM['keluar'] ?? 0);
        echo '</tbody><tfoot><tr><td>Subtotal TA</td>';
        echo '<td class="text-end keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal($subMasuk, $fmt, 'masuk') . '</td>';
        echo '<td class="text-end keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal($subKeluar, $fmt, 'keluar') . '</td>';
        keuangan_rekap_kas_bulan_echo_pos_netto_td($subMasuk - $subKeluar, $fmt);
        echo '</tr></tfoot></table></div></div></section>';
    }

    echo '</div>';
}

/**
 * @param callable(int): string $fmt
 */
function keuangan_rekap_kas_bulan_render_rekonsiliasi(array $rekap, callable $fmt): void
{
    $sub = keuangan_rekap_kas_tagihan_subtotals($rekap);
    $tot = is_array($rekap['total'] ?? null) ? $rekap['total'] : [];
    echo '<div class="card shadow-sm border-0 rekap-kas-rekon-panel mt-3 mb-2" id="rekap-rekonsiliasi">';
    echo '<div class="card-header bg-white fw-semibold py-2">Rekonsiliasi Total TA</div>';
    echo '<div class="card-body p-2 p-md-3"><div class="table-responsive"><table class="table table-sm mb-0">';
    echo '<thead><tr><th>Ringkasan</th><th class="text-end">Target tagihan</th><th class="text-end">Terbayar</th><th class="text-end">Sisa</th></tr></thead><tbody>';
    echo '<tr><td>Tagihan bulanan (bulan 1–' . (int) ($rekap['bulan_berjalan'] ?? 0) . ')</td>';
    echo '<td class="text-end keu-money">' . htmlspecialchars($fmt((int) $sub['bulanan_expected'])) . '</td>';
    echo '<td class="text-end keu-money">' . htmlspecialchars($fmt((int) $sub['bulanan_paid'])) . '</td>';
    echo '<td class="text-end keu-money">' . htmlspecialchars($fmt((int) $sub['bulanan_sisa'])) . '</td></tr>';
    echo '<tr><td>Awal Tahun (semua komponen)</td>';
    echo '<td class="text-end keu-money">' . htmlspecialchars($fmt((int) $sub['awal_tahun_expected'])) . '</td>';
    echo '<td class="text-end keu-money">' . htmlspecialchars($fmt((int) $sub['awal_tahun_paid'])) . '</td>';
    echo '<td class="text-end keu-money">' . htmlspecialchars($fmt((int) $sub['awal_tahun_sisa'])) . '</td></tr>';
    echo '</tbody><tfoot><tr class="fw-bold"><td>Total tagihan TA</td>';
    echo '<td class="text-end keu-money">' . htmlspecialchars($fmt((int) $sub['total_expected'])) . '</td>';
    echo '<td class="text-end keu-money">' . htmlspecialchars($fmt((int) $sub['total_paid'])) . '</td>';
    echo '<td class="text-end keu-money">' . htmlspecialchars($fmt((int) $sub['total_sisa'])) . '</td></tr></tfoot></table></div>';
    echo '<hr class="my-2"><p class="small mb-1"><strong>Mutasi kas operasional (TA, kumulatif):</strong> masuk ';
    echo htmlspecialchars($fmt((int) ($tot['masuk_total'] ?? 0))) . ', keluar ';
    echo htmlspecialchars($fmt((int) ($tot['keluar'] ?? 0))) . ', saldo akhir buku ';
    echo htmlspecialchars($fmt((int) ($rekap['saldo_akhir'] ?? 0))) . '.</p>';
    echo '<p class="small text-muted mb-0">Tagihan ≠ kas masuk. Total tagihan = bulanan + Awal Tahun (definisi sistem).</p>';
    echo '</div></div>';
}

/**
 * @param callable(int): string $fmt
 */
function keuangan_rekap_kas_bulan_render_saldo_akun(array $rekap, callable $fmt, bool $showSaku, bool $wrapInDetails = false): void
{
    $kb = $rekap['kas_bank'] ?? [];
    if ($kb === []) {
        return;
    }
    $asOfKb = htmlspecialchars((string) ($kb['as_of_label'] ?? $kb['as_of'] ?? ''));
    $inner = '';
    $inner .= '<div class="rekap-kas-saldo-akun-block small">';
    $inner .= '<p class="fw-semibold mb-2">Saldo terkini per jenis akun <span class="text-muted fw-normal">(' . $asOfKb . ')</span></p>';
    $inner .= '<div class="row g-2 keu-mini-stat-grid">';
    $inner .= '<div class="col-6 col-md-3"><div class="app-mini-stat keu-mini-stat--kas"><div class="app-mini-stat-label">Kas pondok</div><div class="app-mini-stat-value keu-money">' . htmlspecialchars($fmt((int) ($kb['total_kas'] ?? 0))) . '</div></div></div>';
    $inner .= '<div class="col-6 col-md-3"><div class="app-mini-stat keu-mini-stat--kas"><div class="app-mini-stat-label">Rekening bank</div><div class="app-mini-stat-value keu-money">' . htmlspecialchars($fmt((int) ($kb['total_bank'] ?? 0))) . '</div></div></div>';
    if ($showSaku) {
        $inner .= '<div class="col-6 col-md-3"><div class="app-mini-stat"><div class="app-mini-stat-label">Titipan saku*</div><div class="app-mini-stat-value keu-money">' . htmlspecialchars($fmt((int) ($kb['kas_titipan_saku'] ?? 0))) . '</div></div></div>';
    }
    $inner .= '<div class="col-6 col-md-3"><div class="app-mini-stat keu-mini-stat--kas"><div class="app-mini-stat-label">Total kas pondok</div><div class="app-mini-stat-value keu-money">' . htmlspecialchars($fmt((int) ($kb['total'] ?? 0))) . '</div></div></div>';
    $inner .= '</div>';
    $inner .= '<details class="mt-2 text-muted"><summary class="text-body">Keterangan cakupan</summary>';
    if ($showSaku) {
        $inner .= '<p class="mb-0 mt-1">*Titipan saku tidak masuk total kas pondok / total masuk. Verifikasi fisik = kas + bank pondok (tanpa saku).</p>';
    } else {
        $inner .= '<p class="mb-0 mt-1">Tampilan kas pondok (1101/1102). <a href="' . htmlspecialchars(app_href('/keuangan/rekap-kas-bulan.php?view=full')) . '">Rekap lengkap (+ saku)</a> · <a href="' . htmlspecialchars(app_href('/keuangan/saku.php')) . '">Modul Saku</a>.</p>';
    }
    $inner .= '</details></div>';

    if ($wrapInDetails) {
        echo '<details class="rekap-kas-saldo-akun-details mt-2 small">';
        echo '<summary class="fw-semibold text-body">Saldo akun terkini <span class="text-muted fw-normal">(' . $asOfKb . ')</span></summary>';
        echo $inner;
        echo '</details>';
    } else {
        echo $inner;
    }
}

/**
 * @param callable(int): string $fmt
 */
function keuangan_rekap_kas_bulan_render_tabel_ringkas(array $rekap, callable $fmt, bool $showSaku = false): void
{
    $barisAll = $rekap['baris'] ?? [];
    if ($barisAll === []) {
        echo '<div class="rekap-kas-empty" role="status">';
        echo '<div><i class="fa-solid fa-table-cells" aria-hidden="true"></i></div>';
        echo '<p class="mb-2 fw-semibold">Belum ada baris rekap untuk filter ini</p>';
        echo '<p class="small mb-0">Ubah tahun ajaran atau bulan sampai, atau pastikan data transaksi sudah tercatat.</p>';
        echo '</div>';

        return;
    }

    echo '<div class="rekap-kas-table-wrap rekap-kas-table-wrap--ringkas" tabindex="0" aria-label="Tabel rekap kas ringkas">';
    echo '<table class="table table-sm rekap-kas-table rekap-kas-table--ringkas mb-0">';
    echo '<colgroup>';
    echo '<col><col class="rekap-kas-col-num"><col class="rekap-kas-col-num"><col class="rekap-kas-col-num">';
    echo '<col class="rekap-kas-col-num"><col class="rekap-kas-col-num"><col class="rekap-kas-col-num">';
    echo '<col class="rekap-kas-col-num"><col class="rekap-kas-col-num">';
    echo '</colgroup>';
    echo '<thead><tr>';
    echo '<th class="rekap-kas-col-bulan text-start">Bulan</th>';
    echo '<th class="text-end" title="Saldo awal bulan">Saldo awal</th>';
    echo '<th class="text-end">Masuk</th>';
    echo '<th class="text-end">Keluar</th>';
    echo '<th class="text-end" title="Saldo akhir buku">Saldo buku</th>';
    echo '<th class="text-end rekap-kas-grp-tagihan" title="Target tagihan — bukan kas masuk">Tagihan</th>';
    echo '<th class="text-end rekap-kas-grp-tagihan">Terbayar</th>';
    echo '<th class="text-end rekap-kas-grp-tagihan">Sisa</th>';
    echo '<th class="text-end" title="Selisih fisik vs buku">Selisih</th>';
    echo '</tr></thead><tbody>';

    foreach ($barisAll as $row) {
        $isAwalTa = !empty($row['is_tagihan_awal_tahun']);
        $dariBulan = (string) ($row['tanggal_dari'] ?? '');
        $sampaiBulan = (string) ($row['tanggal_sampai'] ?? '');
        $hrefKeluar = $dariBulan !== '' && $sampaiBulan !== ''
            ? keuangan_riwayat_pembayaran_href($dariBulan, $sampaiBulan, 'keluar', '') : null;
        $hrefMasuk = $dariBulan !== '' && $sampaiBulan !== ''
            ? keuangan_riwayat_pembayaran_href($dariBulan, $sampaiBulan, 'masuk', '') : null;

        $clsParts = [];
        if ($isAwalTa) {
            $clsParts[] = 'rekap-kas-row-awal-tahun';
        }
        if (!empty($row['is_bulan_ini'])) {
            $clsParts[] = 'bulan-ini';
        }
        $cls = $clsParts !== [] ? ' class="' . implode(' ', $clsParts) . '"' : '';
        echo '<tr' . $cls . '>';
        echo '<td class="rekap-kas-col-bulan"><strong>' . htmlspecialchars((string) ($row['label'] ?? '')) . '</strong>';
        if ($isAwalTa) {
            echo ' <span class="badge text-bg-secondary keu-status-badge">Awal Tahun</span>';
        } elseif (!empty($row['is_bulan_ini'])) {
            echo ' <span class="badge bg-success">berjalan</span>';
        }
        $periodeTeks = trim((string) ($row['periode_teks'] ?? ''));
        if ($periodeTeks !== '') {
            echo '<span class="rekap-kas-periode-hint">' . htmlspecialchars($periodeTeks) . '</span>';
        }
        echo '</td>';
        if ($isAwalTa) {
            echo '<td class="text-end rekap-kas-saldo keu-money"><span class="rekap-kas-zero" title="Bukan periode bulan tagihan">—</span></td>';
        } else {
            echo '<td class="text-end rekap-kas-saldo keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['saldo_awal'] ?? 0), $fmt, 'saldo') . '</td>';
        }
        echo '<td class="text-end rekap-kas-masuk rekap-kas-masuk-total keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['masuk_total'] ?? 0), $fmt, 'masuk', $hrefMasuk) . '</td>';
        echo '<td class="text-end rekap-kas-keluar rekap-kas-keluar-total keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['keluar'] ?? 0), $fmt, 'keluar', $isAwalTa ? null : $hrefKeluar) . '</td>';
        if ($isAwalTa) {
            echo '<td class="text-end rekap-kas-saldo"><span class="rekap-kas-zero">—</span></td>';
        } else {
            echo '<td class="text-end rekap-kas-saldo keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['saldo_akhir'] ?? 0), $fmt, 'saldo') . '</td>';
        }
        echo '<td class="text-end rekap-kas-tagihan keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['tagihan_target'] ?? 0), $fmt) . '</td>';
        echo '<td class="text-end rekap-kas-tagihan keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['tagihan_terbayar'] ?? 0), $fmt) . '</td>';
        echo '<td class="text-end rekap-kas-tagihan rekap-kas-tagihan-sisa keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['tagihan_sisa'] ?? 0), $fmt) . '</td>';
        if ($isAwalTa) {
            echo '<td class="text-end rekap-kas-verif selisih-ok">—</td>';
        } else {
            $selisihRow = (int) ($row['selisih_saldo'] ?? 0);
            echo '<td class="text-end rekap-kas-verif keu-money' . ($selisihRow !== 0 ? ' selisih-warn' : ' selisih-ok') . '">';
            echo $selisihRow !== 0 ? htmlspecialchars($fmt($selisihRow)) : '—';
            echo '</td>';
        }
        echo '</tr>';
    }

    $tot = $rekap['total'] ?? [];
    $tagTot = is_array($rekap['tagihan']['total'] ?? null) ? $rekap['tagihan']['total'] : [];
    $barisBulan = array_values(array_filter(
        $barisAll,
        static fn (array $r): bool => empty($r['is_tagihan_awal_tahun'])
    ));
    $taDari = $barisBulan !== [] ? (string) ($barisBulan[0]['tanggal_dari'] ?? '') : '';
    $taSampai = $barisBulan !== [] ? (string) ($barisBulan[count($barisBulan) - 1]['tanggal_sampai'] ?? '') : '';
    $hrefTaKeluar = $taDari !== '' && $taSampai !== '' ? keuangan_riwayat_pembayaran_href($taDari, $taSampai, 'keluar', '') : null;
    $hrefTaMasuk = $taDari !== '' && $taSampai !== '' ? keuangan_riwayat_pembayaran_href($taDari, $taSampai, 'masuk', '') : null;

    echo '</tbody><tfoot><tr class="rekap-kas-foot-total">';
    echo '<td class="rekap-kas-col-bulan"><strong>Total TA</strong> <span class="rekap-kas-periode-hint">bulan 1–' . (int) ($rekap['bulan_berjalan'] ?? 0) . ', + awal tahun</span></td>';
    echo '<td class="text-end rekap-kas-saldo keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($rekap['saldo_awal_ta'] ?? 0), $fmt, 'saldo') . '</td>';
    echo '<td class="text-end rekap-kas-masuk rekap-kas-masuk-total keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['masuk_total'] ?? 0), $fmt, 'masuk', $hrefTaMasuk) . '</td>';
    echo '<td class="text-end rekap-kas-keluar rekap-kas-keluar-total keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['keluar'] ?? 0), $fmt, 'keluar', $hrefTaKeluar) . '</td>';
    echo '<td class="text-end rekap-kas-saldo keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($rekap['saldo_akhir'] ?? 0), $fmt, 'saldo') . '</td>';
    echo '<td class="text-end rekap-kas-tagihan keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tagTot['expected'] ?? 0), $fmt) . '</td>';
    echo '<td class="text-end rekap-kas-tagihan keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tagTot['paid'] ?? 0), $fmt) . '</td>';
    echo '<td class="text-end rekap-kas-tagihan rekap-kas-tagihan-sisa keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tagTot['sisa'] ?? 0), $fmt) . '</td>';
    $selisihTa = (int) ($rekap['selisih_saldo'] ?? 0);
    echo '<td class="text-end rekap-kas-verif keu-money' . ($selisihTa !== 0 ? ' selisih-warn' : ' selisih-ok') . '">';
    echo $selisihTa !== 0 ? htmlspecialchars($fmt($selisihTa)) : '—';
    echo '</td>';
    echo '</tr></tfoot></table></div>';

    keuangan_rekap_kas_bulan_render_saldo_akun($rekap, $fmt, $showSaku, true);
}

/**
 * @param list<array<string,mixed>> $baris
 * @param array<string,mixed> $tagihanRekap
 * @param array<string,int> $totalsTa
 * @return list<array<string,mixed>>
 */
function keuangan_rekap_kas_bulan_append_baris_awal_tahun(
    array $baris,
    array $tagihanRekap,
    array $totalsTa,
    string $taMulaiTanggal,
    string $today
): array {
    $awal = is_array($tagihanRekap['awal_tahun'] ?? null) ? $tagihanRekap['awal_tahun'] : [];
    $expected = (int) ($awal['expected'] ?? 0);
    $paid = (int) ($awal['paid'] ?? 0);
    $sisa = (int) ($awal['sisa'] ?? 0);
    if ($expected === 0 && $paid === 0 && $sisa === 0) {
        return $baris;
    }

    $masukAt = (int) ($totalsTa['masuk_awal_tahun'] ?? 0);
    $keluarAt = (int) ($totalsTa['keluar_awal_tahun'] ?? 0);

    $baris[] = [
        'bulan' => 0,
        'label' => 'Awal Tahun (TA)',
        'tanggal_dari' => $taMulaiTanggal,
        'tanggal_sampai' => $today,
        'periode_teks' => 'Kewajiban awal TA · penerimaan AT kumulatif s.d. hari ini',
        'is_tagihan_awal_tahun' => true,
        'saldo_awal' => 0,
        'masuk_syahriyah' => 0,
        'masuk_makan' => 0,
        'masuk_saku' => 0,
        'masuk_awal_tahun' => $masukAt,
        'masuk_lain_bayar' => 0,
        'masuk_donasi' => 0,
        'masuk_lain' => 0,
        'masuk_total' => $masukAt,
        'keluar_syahriyah' => 0,
        'keluar_makan' => 0,
        'keluar_saku' => 0,
        'keluar_awal_tahun' => $keluarAt,
        'keluar_lain' => 0,
        'keluar' => $keluarAt,
        'bersih' => $masukAt - $keluarAt,
        'saldo_akhir' => 0,
        'saldo_fisik' => 0,
        'selisih_saldo' => 0,
        'tagihan_target' => $expected,
        'tagihan_terbayar' => $paid,
        'tagihan_sisa' => $sisa,
        'tagihan_pct' => (int) ($awal['pct'] ?? 0),
    ];

    return $baris;
}

function keuangan_rekap_kas_bulan_fmt_nominal(int $nominal, callable $fmt, string $kind = 'neutral', ?string $href = null): string
{
    if ($nominal === 0 && $kind !== 'saldo') {
        return '<span class="rekap-kas-zero">—</span>';
    }
    $text = htmlspecialchars($fmt($nominal));
    if ($kind === 'keluar' && $nominal !== 0) {
        $inner = '(' . $text . ')';
    } else {
        $inner = $text;
    }
    if ($href !== null && $nominal !== 0) {
        return '<a href="' . htmlspecialchars($href) . '" class="rekap-kas-link text-decoration-none" title="Lihat detail transaksi">' . $inner . '</a>';
    }

    return $inner;
}

/**
 * @param callable(int): string $fmt
 */
function keuangan_rekap_kas_bulan_render_tabel(array $rekap, callable $fmt, bool $showSaku = false, string $layout = 'ringkas'): void
{
    if ($layout === 'ringkas') {
        keuangan_rekap_kas_bulan_render_tabel_ringkas($rekap, $fmt, $showSaku);

        return;
    }

    $barisAll = $rekap['baris'] ?? [];
    if ($barisAll === []) {
        echo '<div class="rekap-kas-empty" role="status">';
        echo '<div><i class="fa-solid fa-table-cells" aria-hidden="true"></i></div>';
        echo '<p class="mb-2 fw-semibold">Belum ada baris rekap untuk filter ini</p>';
        echo '<p class="small mb-0">Ubah tahun ajaran atau bulan sampai, atau pastikan data transaksi sudah tercatat.</p>';
        echo '</div>';

        return;
    }

    $colspanMasuk = $showSaku ? 7 : 6;
    $colspanKeluar = $showSaku ? 5 : 4;
    $colspanMutasi = $colspanMasuk + $colspanKeluar;

    echo '<div class="rekap-kas-table-wrap rekap-kas-table-scroll" tabindex="0" aria-label="Tabel rekap kas — geser horizontal di layar kecil">';
    echo '<table class="table table-sm rekap-kas-table mb-0">';
    echo '<thead>';
    echo '<tr class="rekap-kas-head-meta">';
    echo '<th colspan="3" class="rekap-kas-grp-periode">Periode &amp; saldo awal</th>';
    echo '<th colspan="' . $colspanMutasi . '" class="rekap-kas-grp-mutasi">Mutasi kas</th>';
    echo '<th colspan="1" class="rekap-kas-grp-saldo-mid">Saldo buku</th>';
    echo '<th colspan="3" class="rekap-kas-grp-tagihan">Tagihan</th>';
    echo '<th colspan="2" class="rekap-kas-grp-verif">Verifikasi (fisik vs buku)</th>';
    echo '</tr>';
    echo '<tr class="rekap-kas-head-group">';
    echo '<th rowspan="2" class="rekap-kas-col-bulan">Bulan</th>';
    echo '<th rowspan="2" class="rekap-kas-col-periode">Periode</th>';
    echo '<th rowspan="2" class="text-end rekap-kas-col-saldo rekap-kas-col-saldo-awal">Saldo awal</th>';
    echo '<th colspan="' . $colspanMasuk . '" class="rekap-kas-grp-masuk">Kas masuk</th>';
    echo '<th colspan="' . $colspanKeluar . '" class="rekap-kas-grp-keluar">Kas keluar</th>';
    echo '<th rowspan="2" class="text-end rekap-kas-col-saldo">Saldo akhir</th>';
    echo '<th colspan="3" class="rekap-kas-grp-tagihan">Status tagihan</th>';
    echo '<th colspan="2" class="rekap-kas-grp-verif">Uang fisik</th>';
    echo '</tr>';
    echo '<tr class="rekap-kas-head-detail">';
    echo '<th class="text-end">Syahriyah</th><th class="text-end">Makan</th>';
    if ($showSaku) {
        echo '<th class="text-end" title="Titipan — tidak masuk total kas pondok">Saku*</th>';
    }
    echo '<th class="text-end">Awal Tahun</th><th class="text-end">Donasi</th><th class="text-end">Lain</th><th class="text-end">Total masuk</th>';
    echo '<th class="text-end">Syahriyah</th><th class="text-end">Makan</th>';
    if ($showSaku) {
        echo '<th class="text-end" title="Info titipan">Saku*</th>';
    }
    echo '<th class="text-end">Awal Tahun</th><th class="text-end">Total keluar</th>';
    echo '<th class="text-end">Terbayar</th>';
    echo '<th class="text-end">Sisa</th><th class="text-end">Capai</th>';
    echo '<th class="text-end">Fisik</th><th class="text-end">Selisih</th>';
    echo '</tr></thead><tbody>';

    foreach ($rekap['baris'] ?? [] as $row) {
        $isAwalTa = !empty($row['is_tagihan_awal_tahun']);
        $dariBulan = (string) ($row['tanggal_dari'] ?? '');
        $sampaiBulan = (string) ($row['tanggal_sampai'] ?? '');
        $hrefSy = $dariBulan !== '' && $sampaiBulan !== ''
            ? keuangan_riwayat_pembayaran_href($dariBulan, $sampaiBulan, 'masuk', 'kat:syahriyah') : null;
        $hrefMakan = $dariBulan !== '' && $sampaiBulan !== ''
            ? keuangan_riwayat_pembayaran_href($dariBulan, $sampaiBulan, 'masuk', 'kat:makan') : null;
        $hrefSaku = $dariBulan !== '' && $sampaiBulan !== ''
            ? keuangan_riwayat_pembayaran_href($dariBulan, $sampaiBulan, 'masuk', 'kat:saku') : null;
        $hrefAwal = $dariBulan !== '' && $sampaiBulan !== ''
            ? keuangan_riwayat_pembayaran_href($dariBulan, $sampaiBulan, 'masuk', 'kat:awal_tahun') : null;
        $hrefKeluar = $dariBulan !== '' && $sampaiBulan !== ''
            ? keuangan_riwayat_pembayaran_href($dariBulan, $sampaiBulan, 'keluar', '') : null;
        $hrefMasuk = $dariBulan !== '' && $sampaiBulan !== ''
            ? keuangan_riwayat_pembayaran_href($dariBulan, $sampaiBulan, 'masuk', '') : null;
        $lainMasuk = (int) ($row['masuk_lain_bayar'] ?? 0) + (int) ($row['masuk_lain'] ?? 0);

        $clsParts = [];
        if ($isAwalTa) {
            $clsParts[] = 'rekap-kas-row-awal-tahun';
        }
        if (!empty($row['is_bulan_ini'])) {
            $clsParts[] = 'bulan-ini';
        }
        $cls = $clsParts !== [] ? ' class="' . implode(' ', $clsParts) . '"' : '';
        echo '<tr' . $cls . '>';
        echo '<td class="rekap-kas-col-bulan"><strong>' . htmlspecialchars((string) ($row['label'] ?? '')) . '</strong>';
        if ($isAwalTa) {
            echo ' <span class="badge text-bg-secondary keu-status-badge">Awal Tahun</span>';
        } elseif (!empty($row['is_bulan_ini'])) {
            echo ' <span class="badge bg-success">berjalan</span>';
        }
        echo '</td>';
        echo '<td class="rekap-kas-col-periode">' . htmlspecialchars((string) ($row['periode_teks'] ?? '')) . '</td>';
        if ($isAwalTa) {
            echo '<td class="text-end rekap-kas-saldo rekap-kas-col-saldo-awal keu-money"><span class="rekap-kas-zero" title="Bukan periode bulan tagihan">—</span></td>';
        } else {
            echo '<td class="text-end rekap-kas-saldo rekap-kas-col-saldo-awal keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['saldo_awal'] ?? 0), $fmt, 'saldo') . '</td>';
        }
        echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['masuk_syahriyah'] ?? 0), $fmt, 'masuk', $hrefSy) . '</td>';
        echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['masuk_makan'] ?? 0), $fmt, 'masuk', $hrefMakan) . '</td>';
        if ($showSaku) {
            echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['masuk_saku'] ?? 0), $fmt, 'masuk', $hrefSaku) . '</td>';
        }
        echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['masuk_awal_tahun'] ?? 0), $fmt, 'masuk', $hrefAwal) . '</td>';
        echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['masuk_donasi'] ?? 0), $fmt) . '</td>';
        echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal($lainMasuk, $fmt) . '</td>';
        echo '<td class="text-end rekap-kas-masuk rekap-kas-masuk-total">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['masuk_total'] ?? 0), $fmt, 'masuk', $hrefMasuk) . '</td>';
        echo '<td class="text-end rekap-kas-keluar">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['keluar_syahriyah'] ?? 0), $fmt, 'keluar', $hrefKeluar) . '</td>';
        echo '<td class="text-end rekap-kas-keluar">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['keluar_makan'] ?? 0), $fmt, 'keluar', $hrefKeluar) . '</td>';
        if ($showSaku) {
            echo '<td class="text-end rekap-kas-keluar">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['keluar_saku'] ?? 0), $fmt, 'keluar', $hrefKeluar) . '</td>';
        }
        echo '<td class="text-end rekap-kas-keluar">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['keluar_awal_tahun'] ?? 0), $fmt, 'keluar', $hrefKeluar) . '</td>';
        echo '<td class="text-end rekap-kas-keluar rekap-kas-keluar-total">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['keluar'] ?? 0), $fmt, 'keluar', $isAwalTa ? null : $hrefKeluar) . '</td>';
        if ($isAwalTa) {
            echo '<td class="text-end rekap-kas-saldo"><span class="rekap-kas-zero">—</span></td>';
        } else {
            echo '<td class="text-end rekap-kas-saldo">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['saldo_akhir'] ?? 0), $fmt, 'saldo') . '</td>';
        }
        echo '<td class="text-end rekap-kas-tagihan">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['tagihan_terbayar'] ?? 0), $fmt) . '</td>';
        echo '<td class="text-end rekap-kas-tagihan rekap-kas-tagihan-sisa">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['tagihan_sisa'] ?? 0), $fmt) . '</td>';
        $pctTag = (int) ($row['tagihan_pct'] ?? 0);
        echo '<td class="text-end rekap-kas-tagihan">' . ($pctTag > 0 || (int) ($row['tagihan_target'] ?? 0) > 0 ? htmlspecialchars((string) $pctTag) . '%' : '<span class="rekap-kas-zero">—</span>') . '</td>';
        if ($isAwalTa) {
            echo '<td class="text-end rekap-kas-verif"><span class="rekap-kas-zero">—</span></td>';
            echo '<td class="text-end rekap-kas-verif selisih-ok">—</td>';
        } else {
            $selisihRow = (int) ($row['selisih_saldo'] ?? 0);
            echo '<td class="text-end rekap-kas-verif">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['saldo_fisik'] ?? 0), $fmt, 'saldo') . '</td>';
            echo '<td class="text-end rekap-kas-verif' . ($selisihRow !== 0 ? ' selisih-warn' : ' selisih-ok') . '">';
            echo $selisihRow !== 0 ? htmlspecialchars($fmt($selisihRow)) : '—';
            echo '</td>';
        }
        echo '</tr>';
    }

    $tot = $rekap['total'] ?? [];
    $tagTot = is_array($rekap['tagihan']['total'] ?? null) ? $rekap['tagihan']['total'] : [];
    $barisAll = $rekap['baris'] ?? [];
    $barisBulan = array_values(array_filter(
        $barisAll,
        static fn (array $r): bool => empty($r['is_tagihan_awal_tahun'])
    ));
    $taDari = $barisBulan !== [] ? (string) ($barisBulan[0]['tanggal_dari'] ?? '') : '';
    $taSampai = $barisBulan !== [] ? (string) ($barisBulan[count($barisBulan) - 1]['tanggal_sampai'] ?? '') : '';
    $hrefTaSy = $taDari !== '' && $taSampai !== '' ? keuangan_riwayat_pembayaran_href($taDari, $taSampai, 'masuk', 'kat:syahriyah') : null;
    $hrefTaMakan = $taDari !== '' && $taSampai !== '' ? keuangan_riwayat_pembayaran_href($taDari, $taSampai, 'masuk', 'kat:makan') : null;
    $hrefTaSaku = $taDari !== '' && $taSampai !== '' ? keuangan_riwayat_pembayaran_href($taDari, $taSampai, 'masuk', 'kat:saku') : null;
    $hrefTaAwal = $taDari !== '' && $taSampai !== '' ? keuangan_riwayat_pembayaran_href($taDari, $taSampai, 'masuk', 'kat:awal_tahun') : null;
    $hrefTaKeluar = $taDari !== '' && $taSampai !== '' ? keuangan_riwayat_pembayaran_href($taDari, $taSampai, 'keluar', '') : null;
    $hrefTaMasuk = $taDari !== '' && $taSampai !== '' ? keuangan_riwayat_pembayaran_href($taDari, $taSampai, 'masuk', '') : null;
    $lainTa = (int) ($tot['masuk_lain_bayar'] ?? 0) + (int) ($tot['masuk_lain'] ?? 0);
    echo '</tbody><tfoot><tr class="rekap-kas-foot-total">';
    echo '<td class="rekap-kas-col-bulan" colspan="2"><strong>Total TA</strong> (bulanan + awal tahun, bulan 1–' . (int) ($rekap['bulan_berjalan'] ?? 0) . ')</td>';
    echo '<td class="text-end rekap-kas-saldo rekap-kas-col-saldo-awal keu-money">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($rekap['saldo_awal_ta'] ?? 0), $fmt, 'saldo') . '</td>';
    echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['masuk_syahriyah'] ?? 0), $fmt, 'masuk', $hrefTaSy) . '</td>';
    echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['masuk_makan'] ?? 0), $fmt, 'masuk', $hrefTaMakan) . '</td>';
    if ($showSaku) {
        echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['masuk_saku'] ?? 0), $fmt, 'masuk', $hrefTaSaku) . '</td>';
    }
    echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['masuk_awal_tahun'] ?? 0), $fmt, 'masuk', $hrefTaAwal) . '</td>';
    echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['masuk_donasi'] ?? 0), $fmt) . '</td>';
    echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal($lainTa, $fmt) . '</td>';
    echo '<td class="text-end rekap-kas-masuk rekap-kas-masuk-total">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['masuk_total'] ?? 0), $fmt, 'masuk', $hrefTaMasuk) . '</td>';
    echo '<td class="text-end rekap-kas-keluar">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['keluar_syahriyah'] ?? 0), $fmt, 'keluar', $hrefTaKeluar) . '</td>';
    echo '<td class="text-end rekap-kas-keluar">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['keluar_makan'] ?? 0), $fmt, 'keluar', $hrefTaKeluar) . '</td>';
    if ($showSaku) {
        echo '<td class="text-end rekap-kas-keluar">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['keluar_saku'] ?? 0), $fmt, 'keluar', $hrefTaKeluar) . '</td>';
    }
    echo '<td class="text-end rekap-kas-keluar">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['keluar_awal_tahun'] ?? 0), $fmt, 'keluar', $hrefTaKeluar) . '</td>';
    echo '<td class="text-end rekap-kas-keluar rekap-kas-keluar-total">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['keluar'] ?? 0), $fmt, 'keluar', $hrefTaKeluar) . '</td>';
    echo '<td class="text-end rekap-kas-saldo">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($rekap['saldo_akhir'] ?? 0), $fmt, 'saldo') . '</td>';
    echo '<td class="text-end rekap-kas-tagihan">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tagTot['paid'] ?? 0), $fmt) . '</td>';
    echo '<td class="text-end rekap-kas-tagihan rekap-kas-tagihan-sisa">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tagTot['sisa'] ?? 0), $fmt) . '</td>';
    echo '<td class="text-end rekap-kas-tagihan">' . ((int) ($tagTot['pct'] ?? 0) > 0 ? htmlspecialchars((string) (int) ($tagTot['pct'] ?? 0)) . '%' : '—') . '</td>';
    echo '<td class="text-end rekap-kas-verif">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($rekap['saldo_akhir_fisik'] ?? 0), $fmt, 'saldo') . '</td>';
    $selisihTa = (int) ($rekap['selisih_saldo'] ?? 0);
    echo '<td class="text-end rekap-kas-verif' . ($selisihTa !== 0 ? ' selisih-warn' : ' selisih-ok') . '">';
    echo $selisihTa !== 0 ? htmlspecialchars($fmt($selisihTa)) : '—';
    echo '</td>';
    echo '</tr></tfoot></table></div>';

    keuangan_rekap_kas_bulan_render_saldo_akun($rekap, $fmt, $showSaku, false);
}
