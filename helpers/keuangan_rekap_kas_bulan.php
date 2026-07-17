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
.rekap-kas-table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; border: 1px solid #cbd5e1; border-radius: 8px; }
.rekap-kas-table { width: 100%; min-width: 1180px; font-size: 0.875rem; margin-bottom: 0; border-collapse: separate; border-spacing: 0; }
.rekap-kas-table th, .rekap-kas-table td { padding: 0.5rem 0.65rem; vertical-align: middle; border: 1px solid #e2e8f0; }
.rekap-kas-table thead th { background: #0f4c5c; color: #fff !important; font-weight: 600; white-space: nowrap; text-align: center; }
.rekap-kas-table thead .rekap-kas-head-group th { font-size: 0.8rem; letter-spacing: 0.02em; padding-top: 0.6rem; padding-bottom: 0.35rem; color: #fff !important; }
.rekap-kas-table thead .rekap-kas-head-detail th { font-size: 0.75rem; font-weight: 500; background: #134e4a; color: #fff !important; padding-top: 0.35rem; padding-bottom: 0.55rem; }
.rekap-kas-table .rekap-kas-grp-masuk { background: #0f766e !important; color: #fff !important; }
.rekap-kas-table .rekap-kas-grp-keluar { background: #991b1b !important; color: #fff !important; }
.rekap-kas-table .rekap-kas-grp-verif { background: #1e40af !important; color: #fff !important; }
.rekap-kas-table .rekap-kas-grp-tagihan { background: #7c3aed !important; color: #fff !important; }
.rekap-kas-table td.rekap-kas-tagihan { background: #f5f3ff; }
.rekap-kas-table td.rekap-kas-tagihan-target { background: #ede9fe; font-weight: 600; color: #5b21b6; }
.rekap-kas-table td.rekap-kas-tagihan-sisa { background: #fef3c7; color: #92400e; font-weight: 600; }
.rekap-kas-table .text-end { text-align: right; font-variant-numeric: tabular-nums; }
.rekap-kas-table .rekap-kas-col-bulan { min-width: 7rem; text-align: left !important; position: sticky; left: 0; z-index: 2; background: #fff; box-shadow: 2px 0 4px rgba(15, 76, 92, 0.06); }
.rekap-kas-table thead .rekap-kas-col-bulan { background: #0f4c5c; color: #fff !important; z-index: 3; }
.rekap-kas-table thead .rekap-kas-col-periode { color: #fff !important; background: #0f4c5c; }
.rekap-kas-table thead .rekap-kas-col-saldo { color: #fff !important; background: #0f4c5c; }
.rekap-kas-table tfoot .rekap-kas-col-bulan { background: #f1f5f9; }
.rekap-kas-table .rekap-kas-col-periode { font-size: 0.78rem; max-width: 9rem; white-space: normal; line-height: 1.25; }
.rekap-kas-table tbody .rekap-kas-col-periode { color: #64748b; font-size: 0.78rem; }
.rekap-kas-table td.rekap-kas-masuk { background: #f0fdf4; }
.rekap-kas-table td.rekap-kas-masuk-total { background: #dcfce7; font-weight: 600; color: #166534; }
.rekap-kas-table td.rekap-kas-keluar { background: #fef2f2; color: #b91c1c; font-weight: 600; }
.rekap-kas-table td.rekap-kas-saldo { background: #f8fafc; font-weight: 600; }
.rekap-kas-table td.rekap-kas-verif { background: #eff6ff; }
.rekap-kas-table .rekap-kas-zero { color: #94a3b8; }
.rekap-kas-table tbody tr.bulan-ini { background: #ecfdf5; }
.rekap-kas-table tbody tr.bulan-ini td.rekap-kas-col-bulan { background: #ecfdf5; }
.rekap-kas-table tbody tr.bulan-ini td.rekap-kas-masuk { background: #d1fae5; }
.rekap-kas-table tbody tr.bulan-ini td.rekap-kas-keluar { background: #fee2e2; }
.rekap-kas-table tbody tr:hover td { filter: brightness(0.98); }
.rekap-kas-table tfoot td { font-weight: 700; background: #f1f5f9; border-top: 2px solid #94a3b8; }
.rekap-kas-table tfoot td.rekap-kas-masuk-total { color: #166534; }
.rekap-kas-table tfoot td.rekap-kas-keluar { color: #b91c1c; }
.rekap-kas-table .selisih-ok { color: #64748b; }
.rekap-kas-table .selisih-warn { color: #b45309; font-weight: 700; }
@media (max-width: 768px) {
    .rekap-kas-table .rekap-kas-col-periode { display: none; }
    .rekap-kas-table thead .rekap-kas-col-periode { display: none; }
}
.rekap-kas-table td.rekap-kas-masuk a.rekap-kas-link { color: inherit; border-bottom: 1px dotted currentColor; }
.rekap-kas-table td.rekap-kas-masuk a.rekap-kas-link:hover { color: #0d6efd; }
.rekap-kas-table td.rekap-kas-keluar a.rekap-kas-link { color: inherit; border-bottom: 1px dotted currentColor; }
.rekap-kas-table td.rekap-kas-keluar a.rekap-kas-link:hover { color: #dc3545; }
.rekap-kas-table td.rekap-kas-keluar-total { font-weight: 700; }
    .rekap-kas-table { font-size: 8pt; min-width: 0; }
    .rekap-kas-table-wrap { border: none; }
    .rekap-kas-table thead th, .rekap-kas-table td.rekap-kas-masuk,
    .rekap-kas-table td.rekap-kas-keluar, .rekap-kas-table tbody tr.bulan-ini td {
        -webkit-print-color-adjust: exact; print-color-adjust: exact;
    }
    .rekap-kas-table .rekap-kas-col-bulan { position: static; box-shadow: none; }
}
';
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
function keuangan_rekap_kas_bulan_render_tabel(array $rekap, callable $fmt): void
{
    $colspanMasuk = 7;
    $colspanKeluar = 5;

    echo '<div class="rekap-kas-table-wrap">';
    echo '<table class="table table-sm rekap-kas-table mb-0">';
    echo '<thead>';
    echo '<tr class="rekap-kas-head-group">';
    echo '<th rowspan="2" class="rekap-kas-col-bulan">Bulan</th>';
    echo '<th rowspan="2" class="rekap-kas-col-periode">Periode</th>';
    echo '<th rowspan="2" class="text-end rekap-kas-col-saldo">Saldo awal</th>';
    echo '<th colspan="' . $colspanMasuk . '" class="rekap-kas-grp-masuk">Kas masuk</th>';
    echo '<th colspan="' . $colspanKeluar . '" class="rekap-kas-grp-keluar">Kas keluar</th>';
    echo '<th rowspan="2" class="text-end rekap-kas-col-saldo">Saldo akhir</th>';
    echo '<th colspan="3" class="rekap-kas-grp-tagihan">Dana tagihan</th>';
    echo '<th colspan="2" class="rekap-kas-grp-verif">Verifikasi kas</th>';
    echo '</tr>';
    echo '<tr class="rekap-kas-head-detail">';
    echo '<th class="text-end">Syahriyah</th><th class="text-end">Makan</th><th class="text-end" title="Titipan — tidak masuk total kas pondok">Saku*</th>';
    echo '<th class="text-end">Awal Tahun</th><th class="text-end">Donasi</th><th class="text-end">Lain</th><th class="text-end">Total masuk</th>';
    echo '<th class="text-end">Syahriyah</th><th class="text-end">Makan</th><th class="text-end" title="Info titipan">Saku*</th>';
    echo '<th class="text-end">Awal Tahun</th><th class="text-end">Total keluar</th>';
    echo '<th class="text-end">Terbayar</th>';
    echo '<th class="text-end">Sisa</th><th class="text-end">Capai</th>';
    echo '<th class="text-end">Fisik</th><th class="text-end">Selisih</th>';
    echo '</tr></thead><tbody>';

    foreach ($rekap['baris'] ?? [] as $row) {
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

        $cls = !empty($row['is_bulan_ini']) ? ' class="bulan-ini"' : '';
        echo '<tr' . $cls . '>';
        echo '<td class="rekap-kas-col-bulan"><strong>' . htmlspecialchars((string) ($row['label'] ?? '')) . '</strong>';
        if (!empty($row['is_bulan_ini'])) {
            echo ' <span class="badge bg-success">berjalan</span>';
        }
        echo '</td>';
        echo '<td class="rekap-kas-col-periode">' . htmlspecialchars((string) ($row['periode_teks'] ?? '')) . '</td>';
        echo '<td class="text-end rekap-kas-saldo">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['saldo_awal'] ?? 0), $fmt, 'saldo') . '</td>';
        echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['masuk_syahriyah'] ?? 0), $fmt, 'masuk', $hrefSy) . '</td>';
        echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['masuk_makan'] ?? 0), $fmt, 'masuk', $hrefMakan) . '</td>';
        echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['masuk_saku'] ?? 0), $fmt, 'masuk', $hrefSaku) . '</td>';
        echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['masuk_awal_tahun'] ?? 0), $fmt, 'masuk', $hrefAwal) . '</td>';
        echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['masuk_donasi'] ?? 0), $fmt) . '</td>';
        echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal($lainMasuk, $fmt) . '</td>';
        echo '<td class="text-end rekap-kas-masuk rekap-kas-masuk-total">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['masuk_total'] ?? 0), $fmt, 'masuk', $hrefMasuk) . '</td>';
        echo '<td class="text-end rekap-kas-keluar">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['keluar_syahriyah'] ?? 0), $fmt, 'keluar', $hrefKeluar) . '</td>';
        echo '<td class="text-end rekap-kas-keluar">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['keluar_makan'] ?? 0), $fmt, 'keluar', $hrefKeluar) . '</td>';
        echo '<td class="text-end rekap-kas-keluar">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['keluar_saku'] ?? 0), $fmt, 'keluar', $hrefKeluar) . '</td>';
        echo '<td class="text-end rekap-kas-keluar">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['keluar_awal_tahun'] ?? 0), $fmt, 'keluar', $hrefKeluar) . '</td>';
        echo '<td class="text-end rekap-kas-keluar rekap-kas-keluar-total">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['keluar'] ?? 0), $fmt, 'keluar', $hrefKeluar) . '</td>';
        echo '<td class="text-end rekap-kas-saldo">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['saldo_akhir'] ?? 0), $fmt, 'saldo') . '</td>';
        echo '<td class="text-end rekap-kas-tagihan">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['tagihan_terbayar'] ?? 0), $fmt) . '</td>';
        echo '<td class="text-end rekap-kas-tagihan rekap-kas-tagihan-sisa">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['tagihan_sisa'] ?? 0), $fmt) . '</td>';
        $pctTag = (int) ($row['tagihan_pct'] ?? 0);
        echo '<td class="text-end rekap-kas-tagihan">' . ($pctTag > 0 || (int) ($row['tagihan_target'] ?? 0) > 0 ? htmlspecialchars((string) $pctTag) . '%' : '<span class="rekap-kas-zero">—</span>') . '</td>';
        $selisihRow = (int) ($row['selisih_saldo'] ?? 0);
        echo '<td class="text-end rekap-kas-verif">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($row['saldo_fisik'] ?? 0), $fmt, 'saldo') . '</td>';
        echo '<td class="text-end rekap-kas-verif' . ($selisihRow !== 0 ? ' selisih-warn' : ' selisih-ok') . '">';
        echo $selisihRow !== 0 ? htmlspecialchars($fmt($selisihRow)) : '—';
        echo '</td>';
        echo '</tr>';
    }

    $tot = $rekap['total'] ?? [];
    $tagTot = is_array($rekap['tagihan']['total'] ?? null) ? $rekap['tagihan']['total'] : [];
    $barisAll = $rekap['baris'] ?? [];
    $taDari = $barisAll !== [] ? (string) ($barisAll[0]['tanggal_dari'] ?? '') : '';
    $taSampai = $barisAll !== [] ? (string) ($barisAll[count($barisAll) - 1]['tanggal_sampai'] ?? '') : '';
    $hrefTaSy = $taDari !== '' && $taSampai !== '' ? keuangan_riwayat_pembayaran_href($taDari, $taSampai, 'masuk', 'kat:syahriyah') : null;
    $hrefTaMakan = $taDari !== '' && $taSampai !== '' ? keuangan_riwayat_pembayaran_href($taDari, $taSampai, 'masuk', 'kat:makan') : null;
    $hrefTaSaku = $taDari !== '' && $taSampai !== '' ? keuangan_riwayat_pembayaran_href($taDari, $taSampai, 'masuk', 'kat:saku') : null;
    $hrefTaAwal = $taDari !== '' && $taSampai !== '' ? keuangan_riwayat_pembayaran_href($taDari, $taSampai, 'masuk', 'kat:awal_tahun') : null;
    $hrefTaKeluar = $taDari !== '' && $taSampai !== '' ? keuangan_riwayat_pembayaran_href($taDari, $taSampai, 'keluar', '') : null;
    $hrefTaMasuk = $taDari !== '' && $taSampai !== '' ? keuangan_riwayat_pembayaran_href($taDari, $taSampai, 'masuk', '') : null;
    $lainTa = (int) ($tot['masuk_lain_bayar'] ?? 0) + (int) ($tot['masuk_lain'] ?? 0);
    echo '</tbody><tfoot><tr>';
    echo '<td class="rekap-kas-col-bulan" colspan="2">Jumlah bulan 1–' . (int) ($rekap['bulan_berjalan'] ?? 0) . '</td>';
    echo '<td class="text-end rekap-kas-saldo">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($rekap['saldo_awal_ta'] ?? 0), $fmt, 'saldo') . '</td>';
    echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['masuk_syahriyah'] ?? 0), $fmt, 'masuk', $hrefTaSy) . '</td>';
    echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['masuk_makan'] ?? 0), $fmt, 'masuk', $hrefTaMakan) . '</td>';
    echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['masuk_saku'] ?? 0), $fmt, 'masuk', $hrefTaSaku) . '</td>';
    echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['masuk_awal_tahun'] ?? 0), $fmt, 'masuk', $hrefTaAwal) . '</td>';
    echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['masuk_donasi'] ?? 0), $fmt) . '</td>';
    echo '<td class="text-end rekap-kas-masuk">' . keuangan_rekap_kas_bulan_fmt_nominal($lainTa, $fmt) . '</td>';
    echo '<td class="text-end rekap-kas-masuk rekap-kas-masuk-total">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['masuk_total'] ?? 0), $fmt, 'masuk', $hrefTaMasuk) . '</td>';
    echo '<td class="text-end rekap-kas-keluar">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['keluar_syahriyah'] ?? 0), $fmt, 'keluar', $hrefTaKeluar) . '</td>';
    echo '<td class="text-end rekap-kas-keluar">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['keluar_makan'] ?? 0), $fmt, 'keluar', $hrefTaKeluar) . '</td>';
    echo '<td class="text-end rekap-kas-keluar">' . keuangan_rekap_kas_bulan_fmt_nominal((int) ($tot['keluar_saku'] ?? 0), $fmt, 'keluar', $hrefTaKeluar) . '</td>';
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

    $kb = $rekap['kas_bank'] ?? [];
    if ($kb !== []) {
        echo '<div class="mt-3 p-3 bg-light border rounded small">';
        echo '<strong>Saldo terkini per jenis akun</strong> (semua akun aktif — ' . htmlspecialchars((string) ($kb['as_of_label'] ?? $kb['as_of'] ?? '')) . ')';
        echo '<div class="row g-2 mt-2">';
        echo '<div class="col-md-3"><span class="text-muted">Kas pondok:</span> <strong>' . htmlspecialchars($fmt((int) ($kb['total_kas'] ?? 0))) . '</strong></div>';
        echo '<div class="col-md-3"><span class="text-muted">Rekening bank:</span> <strong>' . htmlspecialchars($fmt((int) ($kb['total_bank'] ?? 0))) . '</strong></div>';
        echo '<div class="col-md-3"><span class="text-muted">Kas titipan saku*:</span> <strong>' . htmlspecialchars($fmt((int) ($kb['kas_titipan_saku'] ?? 0))) . '</strong></div>';
        echo '<div class="col-md-3"><span class="text-muted">Total kas pondok:</span> <strong>' . htmlspecialchars($fmt((int) ($kb['total'] ?? 0))) . '</strong></div>';
        echo '</div>';
        echo '<p class="text-muted mb-0 mt-2">*Saku = titipan santri, tidak masuk total kas pondok / Total masuk. Saldo fisik verifikasi = kas+bank pondok (tanpa saku).</p>';
        echo '</div>';
    }
}
