<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/pondok_kalender.php';
require_once __DIR__ . '/keuangan_transaksi.php';
require_once __DIR__ . '/keuangan_rekonsiliasi.php';
require_once __DIR__ . '/keuangan_aruskas.php';

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
 *   saldo_akhir_fisik:int,
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
        'masuk_iuran' => 0,
        'masuk_saku' => 0,
        'masuk_donasi' => 0,
        'masuk_lain' => 0,
        'masuk_total' => 0,
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
        $saldoAwalBulan = $saldoBerjalan;
        $saldoAkhirBulan = $saldoAwalBulan + (int) $mutasi['bersih'];
        $saldoFisik = keuangan_aruskas_total_kas($pdo, $sampai);

        $baris[] = [
            'bulan' => $m,
            'label' => $label,
            'tanggal_dari' => $dari,
            'tanggal_sampai' => $sampai,
            'periode_teks' => $dari . ' s/d ' . $sampai,
            'saldo_awal' => $saldoAwalBulan,
            'masuk_iuran' => (int) $mutasi['masuk_iuran'],
            'masuk_saku' => (int) $mutasi['masuk_saku'],
            'masuk_donasi' => (int) $mutasi['masuk_donasi'],
            'masuk_lain' => (int) $mutasi['masuk_lain'],
            'masuk_total' => (int) $mutasi['masuk_total'],
            'keluar' => (int) $mutasi['keluar'],
            'bersih' => (int) $mutasi['bersih'],
            'saldo_akhir' => $saldoAkhirBulan,
            'saldo_fisik' => $saldoFisik,
            'selisih_saldo' => $saldoFisik - $saldoAkhirBulan,
            'is_bulan_ini' => $m === $bulanBerjalan,
        ];

        foreach (['masuk_iuran', 'masuk_saku', 'masuk_donasi', 'masuk_lain', 'masuk_total', 'keluar', 'bersih'] as $k) {
            $totals[$k] += (int) $mutasi[$k];
        }

        $saldoBerjalan = $saldoAkhirBulan;
    }

    $saldoAkhirHitung = $saldoAwalTa + (int) $totals['bersih'];
    $saldoAkhirFisik = $baris !== []
        ? (int) ($baris[count($baris) - 1]['saldo_fisik'] ?? keuangan_aruskas_total_kas($pdo, $today))
        : keuangan_aruskas_total_kas($pdo, $today);

    return [
        'tahun_mulai' => $tm,
        'tahun_selesai' => $ts,
        'ta_label' => pondok_tahun_ajaran_label($pdo, ['mulai' => $tm, 'selesai' => $ts]),
        'bulan_berjalan' => $bulanBerjalan,
        'bulan_berjalan_label' => pondok_bulan_label($pdo, $bulanBerjalan, $tm, $ts),
        'nama_lembaga' => $namaLembaga,
        'saldo_awal_ta' => $saldoAwalTa,
        'saldo_akhir' => $saldoAkhirHitung,
        'saldo_akhir_fisik' => $saldoAkhirFisik,
        'selisih_saldo' => $saldoAkhirFisik - $saldoAkhirHitung,
        'baris' => $baris,
        'total' => $totals,
    ];
}

function keuangan_rekap_kas_bulan_css(): string
{
    return '
body.rekap-kas-bulan-page .app-main .container-fluid { max-width: none; }
.rekap-kas-table { width: 100%; font-size: 0.9rem; }
.rekap-kas-table th, .rekap-kas-bulan-table td { padding: 0.45rem 0.6rem; vertical-align: middle; }
.rekap-kas-table thead th { background: #0f4c5c; color: #fff; font-weight: 600; white-space: nowrap; }
.rekap-kas-table .text-end { text-align: right; font-variant-numeric: tabular-nums; }
.rekap-kas-table tbody tr.bulan-ini { background: #ecfdf5; }
.rekap-kas-table tfoot td { font-weight: 700; background: #f1f5f9; border-top: 2px solid #cbd5e1; }
.rekap-kas-table .sub-hdr { font-size: 0.78rem; color: #64748b; }
@media print {
    .rekap-kas-table { font-size: 8.5pt; }
    .rekap-kas-table thead th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
';
}

/**
 * @param callable(int): string $fmt
 */
function keuangan_rekap_kas_bulan_render_tabel(array $rekap, callable $fmt): void
{
    echo '<div class="table-responsive">';
    echo '<table class="table table-sm table-bordered rekap-kas-table rekap-kas-bulan-table mb-0">';
    echo '<thead><tr>';
    echo '<th>Bulan</th><th>Periode (M)</th>';
    echo '<th class="text-end">Saldo awal</th>';
    echo '<th class="text-end">Masuk iuran</th><th class="text-end">Masuk saku</th>';
    echo '<th class="text-end">Donasi/infaq</th><th class="text-end">Masuk lain</th>';
    echo '<th class="text-end">Total masuk</th><th class="text-end">Total keluar</th>';
    echo '<th class="text-end">Saldo akhir</th>';
    echo '</tr></thead><tbody>';

    foreach ($rekap['baris'] ?? [] as $row) {
        $cls = !empty($row['is_bulan_ini']) ? ' class="bulan-ini"' : '';
        echo '<tr' . $cls . '>';
        echo '<td><strong>' . htmlspecialchars((string) ($row['label'] ?? '')) . '</strong>';
        if (!empty($row['is_bulan_ini'])) {
            echo ' <span class="badge bg-success">berjalan</span>';
        }
        echo '</td>';
        echo '<td class="sub-hdr">' . htmlspecialchars((string) ($row['periode_teks'] ?? '')) . '</td>';
        echo '<td class="text-end">' . htmlspecialchars($fmt((int) ($row['saldo_awal'] ?? 0))) . '</td>';
        echo '<td class="text-end">' . htmlspecialchars($fmt((int) ($row['masuk_iuran'] ?? 0))) . '</td>';
        echo '<td class="text-end">' . htmlspecialchars($fmt((int) ($row['masuk_saku'] ?? 0))) . '</td>';
        echo '<td class="text-end">' . htmlspecialchars($fmt((int) ($row['masuk_donasi'] ?? 0))) . '</td>';
        echo '<td class="text-end">' . htmlspecialchars($fmt((int) ($row['masuk_lain'] ?? 0))) . '</td>';
        echo '<td class="text-end fw-semibold">' . htmlspecialchars($fmt((int) ($row['masuk_total'] ?? 0))) . '</td>';
        echo '<td class="text-end text-danger">(' . htmlspecialchars($fmt((int) ($row['keluar'] ?? 0))) . ')</td>';
        echo '<td class="text-end fw-bold">' . htmlspecialchars($fmt((int) ($row['saldo_akhir'] ?? 0))) . '</td>';
        echo '</tr>';
    }

    $tot = $rekap['total'] ?? [];
    echo '</tbody><tfoot><tr>';
    echo '<td colspan="2">Jumlah bulan 1–' . (int) ($rekap['bulan_berjalan'] ?? 0) . '</td>';
    echo '<td class="text-end">' . htmlspecialchars($fmt((int) ($rekap['saldo_awal_ta'] ?? 0))) . '</td>';
    echo '<td class="text-end">' . htmlspecialchars($fmt((int) ($tot['masuk_iuran'] ?? 0))) . '</td>';
    echo '<td class="text-end">' . htmlspecialchars($fmt((int) ($tot['masuk_saku'] ?? 0))) . '</td>';
    echo '<td class="text-end">' . htmlspecialchars($fmt((int) ($tot['masuk_donasi'] ?? 0))) . '</td>';
    echo '<td class="text-end">' . htmlspecialchars($fmt((int) ($tot['masuk_lain'] ?? 0))) . '</td>';
    echo '<td class="text-end">' . htmlspecialchars($fmt((int) ($tot['masuk_total'] ?? 0))) . '</td>';
    echo '<td class="text-end">(' . htmlspecialchars($fmt((int) ($tot['keluar'] ?? 0))) . ')</td>';
    echo '<td class="text-end">' . htmlspecialchars($fmt((int) ($rekap['saldo_akhir'] ?? 0))) . '</td>';
    echo '</tr></tfoot></table></div>';
}
