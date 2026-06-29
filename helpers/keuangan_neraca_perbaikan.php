<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_rekonsiliasi.php';
require_once __DIR__ . '/keuangan_aruskas.php';

/**
 * Temuan analisis penyebab neraca tidak seimbang.
 *
 * @return array<string, mixed>
 */
function keuangan_neraca_analisis_selisih(PDO $pdo, array $neraca): array
{
    $asOf = (string) ($neraca['as_of'] ?? date('Y-m-d'));
    $selisih = (int) ($neraca['selisih'] ?? 0);
    $ring = is_array($neraca['ringkasan'] ?? null) ? $neraca['ringkasan'] : [];

    $tanpaJurnal = is_array($neraca['transaksi_tanpa_jurnal'] ?? null)
        ? $neraca['transaksi_tanpa_jurnal']
        : keuangan_rekonsiliasi_transaksi_tanpa_jurnal($pdo, '2000-01-01', $asOf);
    $nominalTanpaJurnal = 0;
    foreach ($tanpaJurnal as $tx) {
        $nominalTanpaJurnal += (int) ($tx['nominal'] ?? 0);
    }

    $pembayaranTanpaAkun = keuangan_neraca_hitung_transaksi_tanpa_akun($pdo, 'keuangan_pembayaran', 'tanggal_bayar', $asOf);
    $pemasukanTanpaAkun = keuangan_neraca_hitung_transaksi_tanpa_akun($pdo, 'keuangan_pemasukan', 'tanggal', $asOf);
    $pengeluaranTanpaAkun = keuangan_neraca_hitung_transaksi_tanpa_akun($pdo, 'keuangan_pengeluaran', 'tanggal', $asOf);

    $sakuBayar = (int) ($ring['pendapatan_saku'] ?? 0);
    $cashlessSaldo = 0;
    if (table_exists($pdo, 'cashless_accounts')) {
        $cashlessSaldo = (int) round((float) ($pdo->query('SELECT COALESCE(SUM(balance),0) FROM cashless_accounts')->fetchColumn() ?: 0));
    }
    $selisihSaku = $sakuBayar - $cashlessSaldo;

    $totalAset = (int) ($neraca['aset']['total'] ?? 0);
    $totalPasiva = (int) ($neraca['total_pasiva'] ?? 0);
    $totalLiab = (int) ($neraca['liabilitas']['total'] ?? 0);
    $totalNeto = (int) ($neraca['aset_neto']['total'] ?? 0);
    $surplus = (int) ($ring['surplus_operasi'] ?? 0);

    $yearStart = substr($asOf, 0, 4) . '-01-01';
    $kasAwal = keuangan_aruskas_total_kas($pdo, date('Y-m-d', strtotime($asOf . ' -1 day') ?: time()));
    $rekon = keuangan_rekonsiliasi_kas_ringkas($pdo, $yearStart, $asOf, $kasAwal, keuangan_aruskas_total_kas($pdo, $asOf));

    $asetCoaLain = 0;
    foreach ($neraca['aset']['sections'] ?? [] as $sec) {
        if ((string) ($sec['judul'] ?? '') === 'Akun Aset (buku besar)') {
            $asetCoaLain = (int) ($sec['subtotal'] ?? 0);
        }
    }

    return [
        'as_of' => $asOf,
        'selisih' => $selisih,
        'arah' => $selisih > 0 ? 'aktiva_lebih' : ($selisih < 0 ? 'pasiva_lebih' : 'seimbang'),
        'total_aset' => $totalAset,
        'total_pasiva' => $totalPasiva,
        'total_liabilitas' => $totalLiab,
        'total_aset_neto' => $totalNeto,
        'surplus_operasi' => $surplus,
        'transaksi_tanpa_jurnal' => $tanpaJurnal,
        'jumlah_tanpa_jurnal' => count($tanpaJurnal),
        'nominal_tanpa_jurnal' => $nominalTanpaJurnal,
        'pembayaran_tanpa_akun' => $pembayaranTanpaAkun,
        'pemasukan_tanpa_akun' => $pemasukanTanpaAkun,
        'pengeluaran_tanpa_akun' => $pengeluaranTanpaAkun,
        'saku_dibayar' => $sakuBayar,
        'cashless_saldo' => $cashlessSaldo,
        'selisih_saku_cashless' => $selisihSaku,
        'aset_coa_lain' => $asetCoaLain,
        'rekonsiliasi_kas' => $rekon,
    ];
}

/**
 * @return array{jumlah:int,nominal:int,sampel:list<array<string,mixed>>}
 */
function keuangan_neraca_hitung_transaksi_tanpa_akun(PDO $pdo, string $table, string $dateCol, string $asOf): array
{
    if (!table_exists($pdo, $table) || !column_exists($pdo, $table, 'akun_id')) {
        return ['jumlah' => 0, 'nominal' => 0, 'sampel' => []];
    }
    $nomCol = $table === 'keuangan_pembayaran' ? 'total_nominal' : 'nominal';
    $st = $pdo->prepare("
        SELECT COUNT(*) AS jumlah, COALESCE(SUM({$nomCol}), 0) AS nominal
        FROM {$table}
        WHERE {$dateCol} <= :as_of AND (akun_id IS NULL OR akun_id <= 0)
    ");
    $st->execute(['as_of' => $asOf]);
    $agg = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $st2 = $pdo->prepare("
        SELECT id, {$dateCol} AS tanggal, {$nomCol} AS nominal
        FROM {$table}
        WHERE {$dateCol} <= :as_of AND (akun_id IS NULL OR akun_id <= 0)
        ORDER BY {$dateCol} DESC, id DESC
        LIMIT 10
    ");
    $st2->execute(['as_of' => $asOf]);

    return [
        'jumlah' => (int) ($agg['jumlah'] ?? 0),
        'nominal' => (int) round((float) ($agg['nominal'] ?? 0)),
        'sampel' => $st2->fetchAll(PDO::FETCH_ASSOC) ?: [],
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function keuangan_neraca_saran_perbaikan(PDO $pdo, array $neraca, ?array $analisis = null): array
{
    $analisis ??= keuangan_neraca_analisis_selisih($pdo, $neraca);
    $selisih = (int) ($analisis['selisih'] ?? 0);
    if (abs($selisih) < 1) {
        return [[
            'kode' => 'seimbang',
            'prioritas' => 'rendah',
            'judul' => 'Neraca sudah seimbang',
            'deskripsi' => 'Tidak ada tindakan perbaikan yang diperlukan per ' . ($analisis['as_of'] ?? '') . '.',
            'langkah' => [],
            'link' => '/keuangan/neraca.php?per=' . urlencode((string) ($analisis['as_of'] ?? date('Y-m-d'))),
            'link_label' => 'Kembali ke neraca',
            'jumlah' => 0,
            'nominal' => 0,
        ]];
    }

    $saran = [];
    $arah = (string) ($analisis['arah'] ?? '');

    $pengTanpaAkun = (array) ($analisis['pengeluaran_tanpa_akun'] ?? []);
    if ((int) ($pengTanpaAkun['jumlah'] ?? 0) > 0) {
        $saran[] = [
            'kode' => 'pengeluaran_tanpa_akun',
            'prioritas' => 'tinggi',
            'judul' => 'Pengeluaran tanpa akun kas/bank',
            'deskripsi' => 'Beban tercatat di aset neto, tetapi kas operasional tidak berkurang karena akun penerima tidak diisi. Ini sering membuat pasiva tampak lebih kecil dari aktiva.',
            'langkah' => [
                'Buka riwayat pengeluaran dan edit transaksi yang akunnya kosong.',
                'Pilih akun kas/bank yang benar-benar digunakan untuk pembayaran.',
                'Setelah diperbaiki, saldo kas di neraca akan turun sesuai pengeluaran.',
            ],
            'link' => '/keuangan/riwayat_pengeluaran.php',
            'link_label' => 'Riwayat pengeluaran',
            'jumlah' => (int) $pengTanpaAkun['jumlah'],
            'nominal' => (int) $pengTanpaAkun['nominal'],
        ];
    }

    $bayTanpaAkun = (array) ($analisis['pembayaran_tanpa_akun'] ?? []);
    if ((int) ($bayTanpaAkun['jumlah'] ?? 0) > 0) {
        $saran[] = [
            'kode' => 'pembayaran_tanpa_akun',
            'prioritas' => 'tinggi',
            'judul' => 'Pembayaran santri tanpa akun penerimaan',
            'deskripsi' => 'Uang masuk tidak masuk ke saldo kas operasional, sementara aset neto tetap bertambah dari iuran.',
            'langkah' => [
                'Periksa riwayat pembayaran santri yang belum memiliki akun kas/bank.',
                'Koreksi melalui menu edit pembayaran (jika tersedia) atau input ulang dengan akun yang benar.',
            ],
            'link' => '/pembayaran/riwayat.php',
            'link_label' => 'Riwayat pembayaran',
            'jumlah' => (int) $bayTanpaAkun['jumlah'],
            'nominal' => (int) $bayTanpaAkun['nominal'],
        ];
    }

    $masukTanpaAkun = (array) ($analisis['pemasukan_tanpa_akun'] ?? []);
    if ((int) ($masukTanpaAkun['jumlah'] ?? 0) > 0) {
        $saran[] = [
            'kode' => 'pemasukan_tanpa_akun',
            'prioritas' => 'tinggi',
            'judul' => 'Pemasukan lain tanpa akun penerimaan',
            'deskripsi' => 'Donasi/infaq tercatat di aset neto tetapi tidak menambah kas operasional.',
            'langkah' => [
                'Edit pemasukan di riwayat pemasukan dan pilih akun kas/bank.',
                'Pastikan klasifikasi sumber (donasi vs lain-lain) sudah benar.',
            ],
            'link' => '/keuangan/riwayat_pemasukan.php',
            'link_label' => 'Riwayat pemasukan',
            'jumlah' => (int) $masukTanpaAkun['jumlah'],
            'nominal' => (int) $masukTanpaAkun['nominal'],
        ];
    }

    $jTanpaJurnal = (int) ($analisis['jumlah_tanpa_jurnal'] ?? 0);
    if ($jTanpaJurnal > 0) {
        $saran[] = [
            'kode' => 'tanpa_jurnal',
            'prioritas' => 'sedang',
            'judul' => 'Transaksi belum memiliki jurnal otomatis',
            'deskripsi' => 'Transaksi lama atau input manual mungkin belum tersinkron ke buku besar. Gunakan tombol sinkronisasi jurnal di halaman ini.',
            'langkah' => [
                'Klik «Sinkronkan jurnal» untuk membuat jurnal otomatis transaksi yang memenuhi syarat.',
                'Transaksi tanpa akun kas/bank akan dilewati — perbaiki akun terlebih dahulu.',
                'Setelah sinkron, buka ulang neraca untuk melihat perubahan.',
            ],
            'link' => '/keuangan/neraca-perbaikan.php?per=' . urlencode((string) ($analisis['as_of'] ?? '')),
            'link_label' => 'Halaman perbaikan',
            'jumlah' => $jTanpaJurnal,
            'nominal' => (int) ($analisis['nominal_tanpa_jurnal'] ?? 0),
            'aksi' => 'backfill_jurnal',
        ];
    }

    $selisihSaku = (int) ($analisis['selisih_saku_cashless'] ?? 0);
    if (abs($selisihSaku) > 0) {
        $saran[] = [
            'kode' => 'saku_cashless',
            'prioritas' => 'sedang',
            'judul' => 'Selisih pembayaran saku vs saldo cashless',
            'deskripsi' => 'Total pembayaran pos saku (' . number_format((int) $analisis['saku_dibayar'], 0, ',', '.')
                . ') tidak sama dengan liabilitas titipan cashless (' . number_format((int) $analisis['cashless_saldo'], 0, ',', '.') . ').',
            'langkah' => [
                'Pastikan setiap top-up saku melalui pembayaran santri pos «Saku».',
                'Periksa transaksi cashless koperasi (belanja, koreksi) di laporan cashless.',
                'Koreksi manual saldo cashless hanya jika ada bukti administratif.',
            ],
            'link' => '/keuangan/cashless_laporan.php',
            'link_label' => 'Laporan cashless',
            'jumlah' => 0,
            'nominal' => abs($selisihSaku),
        ];
    }

    $rekon = (array) ($analisis['rekonsiliasi_kas'] ?? []);
    if (abs((int) ($rekon['selisih'] ?? 0)) > 0) {
        $saran[] = [
            'kode' => 'kas_fisik',
            'prioritas' => 'sedang',
            'judul' => 'Selisih sinkronisasi kas fisik',
            'deskripsi' => 'Formula (masuk − keluar) tidak sama dengan perubahan saldo kas di buku.',
            'langkah' => [
                'Bandingkan rekap kas bulanan dengan mutasi harian.',
                'Periksa saldo awal akun di Pengaturan → Akun kas/bank.',
                'Pastikan tidak ada transfer antar akun yang belum dicatat.',
            ],
            'link' => '/keuangan/rekap-kas-bulan.php',
            'link_label' => 'Rekap kas bulanan',
            'jumlah' => 0,
            'nominal' => abs((int) $rekon['selisih']),
        ];
    }

    if ((int) ($analisis['aset_coa_lain'] ?? 0) !== 0) {
        $saran[] = [
            'kode' => 'aset_coa',
            'prioritas' => 'rendah',
            'judul' => 'Saldo akun aset buku besar (COA)',
            'deskripsi' => 'Ada mutasi jurnal pada akun aset selain kas operasional. Pastikan tidak dobel dengan kas atau aset tetap inventaris.',
            'langkah' => [
                'Review jurnal umum pada akun aset.',
                'Samakan dengan daftar inventaris dan saldo kas operasional.',
            ],
            'link' => '/keuangan/inventaris.php',
            'link_label' => 'Inventaris aset',
            'jumlah' => 0,
            'nominal' => abs((int) $analisis['aset_coa_lain']),
        ];
    }

    $saran[] = [
        'kode' => 'saldo_awal',
        'prioritas' => 'rendah',
        'judul' => 'Periksa saldo awal akun kas/bank',
        'deskripsi' => 'Saldo awal yang tidak sesuai akan memengaruhi kas di aktiva tanpa mengubah aset neto operasional.',
        'langkah' => [
            'Buka Pengaturan Keuangan → bagian Akun kas/bank.',
            'Sesuaikan «Saldo awal» dengan posisi kas nyata di awal tahun ajaran / tahun buku.',
            'Catat penyesuaian dalam berita acara, bukan penyesuaian otomatis neraca.',
        ],
        'link' => '/keuangan/pengaturan.php?bagian=akun',
        'link_label' => 'Pengaturan akun',
        'jumlah' => 0,
        'nominal' => 0,
    ];

    if ($arah === 'aktiva_lebih') {
        $saran[] = [
            'kode' => 'arah_aktiva',
            'prioritas' => 'rendah',
            'judul' => 'Aktiva lebih besar dari pasiva',
            'deskripsi' => 'Kemungkinan: kas terlalu tinggi, beban kurang tercatat di kas, atau liabilitas saku/cashless kurang tercatat.',
            'langkah' => [
                'Prioritaskan perbaikan pengeluaran/pembayaran tanpa akun (lihat saran prioritas tinggi di atas).',
                'Pastikan titipan saku masuk liabilitas, bukan pendapatan.',
            ],
            'link' => '/keuangan/neraca.php?per=' . urlencode((string) ($analisis['as_of'] ?? '')),
            'link_label' => 'Lihat neraca',
            'jumlah' => 0,
            'nominal' => abs($selisih),
        ];
    } elseif ($arah === 'pasiva_lebih') {
        $saran[] = [
            'kode' => 'arah_pasiva',
            'prioritas' => 'rendah',
            'judul' => 'Pasiva lebih besar dari aktiva',
            'deskripsi' => 'Kemungkinan: penerimaan tercatat di aset neto tetapi kas belum masuk, atau saldo awal kas terlalu kecil.',
            'langkah' => [
                'Periksa pemasukan/pembayaran tanpa akun.',
                'Naikkan saldo awal kas jika memang ada dana yang belum diinput.',
            ],
            'link' => '/keuangan/neraca.php?per=' . urlencode((string) ($analisis['as_of'] ?? '')),
            'link_label' => 'Lihat neraca',
            'jumlah' => 0,
            'nominal' => abs($selisih),
        ];
    }

    usort($saran, static function (array $a, array $b): int {
        $prio = ['tinggi' => 0, 'sedang' => 1, 'rendah' => 2];
        $pa = $prio[$a['prioritas'] ?? 'rendah'] ?? 2;
        $pb = $prio[$b['prioritas'] ?? 'rendah'] ?? 2;
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }

        return ((int) ($b['nominal'] ?? 0)) <=> ((int) ($a['nominal'] ?? 0));
    });

    return $saran;
}

function keuangan_neraca_invalidate_cache(): void
{
    unset($_SESSION['keuangan_neraca_cache_v1'], $_SESSION['keuangan_aruskas_cache_v1']);
    if (function_exists('keuangan_dashboard_cache_invalidate')) {
        keuangan_dashboard_cache_invalidate();
    }
}

function keuangan_neraca_perbaikan_css(): string
{
    return '
body.neraca-perbaikan-page .saran-card { border-left: 4px solid #cbd5e1; }
body.neraca-perbaikan-page .saran-card.prio-tinggi { border-left-color: #dc2626; }
body.neraca-perbaikan-page .saran-card.prio-sedang { border-left-color: #d97706; }
body.neraca-perbaikan-page .saran-card.prio-rendah { border-left-color: #64748b; }
body.neraca-perbaikan-page .saran-langkah { margin: 0.5rem 0 0; padding-left: 1.1rem; font-size: 0.9rem; }
';
}

/**
 * @param callable(int): string $fmt
 */
function keuangan_neraca_perbaikan_render_saran(array $saran, callable $fmt): void
{
    foreach ($saran as $item) {
        if (($item['kode'] ?? '') === 'seimbang') {
            echo '<div class="alert alert-success"><i class="fa-solid fa-circle-check me-1"></i> ' . htmlspecialchars((string) $item['deskripsi']) . '</div>';
            continue;
        }
        $prio = (string) ($item['prioritas'] ?? 'rendah');
        $badge = match ($prio) {
            'tinggi' => 'danger',
            'sedang' => 'warning',
            default => 'secondary',
        };
        echo '<div class="card shadow-sm mb-3 saran-card prio-' . htmlspecialchars($prio) . '">';
        echo '<div class="card-body">';
        echo '<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">';
        echo '<h2 class="h6 mb-0 fw-semibold">' . htmlspecialchars((string) ($item['judul'] ?? '')) . '</h2>';
        echo '<span class="badge bg-' . $badge . '">' . htmlspecialchars(ucfirst($prio)) . '</span>';
        echo '</div>';
        echo '<p class="text-muted small mb-2">' . htmlspecialchars((string) ($item['deskripsi'] ?? '')) . '</p>';
        if ((int) ($item['jumlah'] ?? 0) > 0 || (int) ($item['nominal'] ?? 0) > 0) {
            echo '<p class="small mb-2">';
            if ((int) ($item['jumlah'] ?? 0) > 0) {
                echo '<strong>' . (int) $item['jumlah'] . '</strong> transaksi';
            }
            if ((int) ($item['nominal'] ?? 0) > 0) {
                echo ((int) ($item['jumlah'] ?? 0) > 0 ? ' · ' : '') . 'terkait <strong>' . htmlspecialchars($fmt((int) $item['nominal'])) . '</strong>';
            }
            echo '</p>';
        }
        $langkah = $item['langkah'] ?? [];
        if (is_array($langkah) && $langkah !== []) {
            echo '<ol class="saran-langkah text-muted">';
            foreach ($langkah as $step) {
                echo '<li>' . htmlspecialchars((string) $step) . '</li>';
            }
            echo '</ol>';
        }
        if (!empty($item['link'])) {
            echo '<a class="btn btn-sm btn-outline-primary" href="' . htmlspecialchars(app_href((string) $item['link'])) . '">';
            echo '<i class="fa-solid fa-arrow-up-right-from-square me-1"></i>' . htmlspecialchars((string) ($item['link_label'] ?? 'Buka'));
            echo '</a>';
        }
        echo '</div></div>';
    }
}
