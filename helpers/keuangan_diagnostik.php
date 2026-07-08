<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_perbaikan_kas.php';
require_once __DIR__ . '/keuangan_validasi_pesan.php';
require_once __DIR__ . '/keuangan_transaksi.php';
require_once __DIR__ . '/keuangan_pengaturan.php';

/**
 * Daftar gaji pembimbing tanpa baris pengeluaran kas.
 *
 * @return list<array<string, mixed>>
 */
function keuangan_diagnostik_list_gaji_tanpa_pengeluaran(PDO $pdo, int $limit = 50): array
{
    if (!table_exists($pdo, 'keuangan_gaji_pembimbing') || !column_exists($pdo, 'keuangan_gaji_pembimbing', 'pengeluaran_id')) {
        return [];
    }
    if (!function_exists('keuangan_sql_gaji_belum_di_pengeluaran_where')) {
        require_once __DIR__ . '/keuangan_rekonsiliasi.php';
    }
    $where = keuangan_sql_gaji_belum_di_pengeluaran_where($pdo, 'g');
    $cols = ['g.id', 'g.total_bayar', 'g.keterangan', 'g.created_at'];
    if (column_exists($pdo, 'keuangan_gaji_pembimbing', 'bulan')) {
        $cols[] = 'g.bulan AS bulan_tagihan';
    } elseif (column_exists($pdo, 'keuangan_gaji_pembimbing', 'bulan_tagihan')) {
        $cols[] = 'g.bulan_tagihan';
    }
    if (column_exists($pdo, 'keuangan_gaji_pembimbing', 'tahun_ajaran_mulai')) {
        $cols[] = 'g.tahun_ajaran_mulai';
        if (column_exists($pdo, 'keuangan_gaji_pembimbing', 'tahun_ajaran_selesai')) {
            $cols[] = 'g.tahun_ajaran_selesai';
        }
    } elseif (column_exists($pdo, 'keuangan_gaji_pembimbing', 'tahun')) {
        $cols[] = 'g.tahun AS tahun_ajaran_mulai';
        $cols[] = 'g.tahun AS tahun_ajaran_selesai';
    }
    if (column_exists($pdo, 'keuangan_gaji_pembimbing', 'periode_label')) {
        $cols[] = 'g.periode_label';
    }
    if (column_exists($pdo, 'keuangan_gaji_pembimbing', 'tanggal_bayar')) {
        $cols[] = 'g.tanggal_bayar';
    }
    $lim = max(1, min(100, $limit));
    try {
        $st = $pdo->query(
            'SELECT ' . implode(', ', $cols) . "
            FROM keuangan_gaji_pembimbing g
            WHERE {$where}
            ORDER BY g.id DESC
            LIMIT {$lim}"
        );
    } catch (PDOException $e) {
        error_log('keuangan_diagnostik_list_gaji_tanpa_pengeluaran: ' . $e->getMessage());

        return [];
    }

    return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

/**
 * Diagnostik keuangan menyeluruh untuk Perbaikan Kas & dashboard.
 *
 * @return array{
 *   as_of:string,
 *   ringkas:array<string,int>,
 *   items:list<array<string,mixed>>,
 *   perbaikan_kas:array<string,mixed>
 * }
 */
function keuangan_diagnostik_menyeluruh(PDO $pdo, ?string $asOf = null): array
{
    $asOf = $asOf !== null && $asOf !== '' ? date('Y-m-d', strtotime($asOf) ?: time()) : date('Y-m-d');
    $fmt = static fn(int $n): string => keuangan_format_rupiah($n);

    $perbaikan = keuangan_perbaikan_kas_ringkas($pdo, $asOf);
    $items = [];

    foreach (['pembayaran', 'pemasukan', 'pengeluaran'] as $tipe) {
        $rows = $perbaikan[$tipe] ?? [];
        if ($rows === []) {
            continue;
        }
        $def = keuangan_kesalahan_kas_def($tipe . '_tanpa_akun');
        $nominal = 0;
        foreach ($rows as $r) {
            $nominal += (int) round((float) ($r['nominal'] ?? 0));
        }
        $items[] = [
            'kode' => $tipe . '_tanpa_akun',
            'prioritas' => 'tinggi',
            'judul' => (string) ($def['judul'] ?? ''),
            'penjelasan' => (string) ($def['penjelasan'] ?? ''),
            'dampak' => (string) ($def['dampak'] ?? ''),
            'solusi' => (string) ($def['solusi'] ?? ''),
            'jumlah' => count($rows),
            'nominal' => $nominal,
            'nominal_fmt' => $fmt($nominal),
            'href_aksi' => '/keuangan/perbaikan-kas.php#' . $tipe,
            'href_label' => 'Perbaiki di tabel',
            'bisa_perbaiki_otomatis' => true,
        ];
    }

    $duplikat = $perbaikan['duplikat'] ?? [];
    if ($duplikat !== []) {
        $def = keuangan_kesalahan_kas_def('pembayaran_dobel');
        $items[] = [
            'kode' => 'pembayaran_dobel',
            'prioritas' => 'sedang',
            'judul' => (string) ($def['judul'] ?? ''),
            'penjelasan' => (string) ($def['penjelasan'] ?? ''),
            'dampak' => (string) ($def['dampak'] ?? ''),
            'solusi' => (string) ($def['solusi'] ?? ''),
            'jumlah' => count($duplikat),
            'nominal' => 0,
            'nominal_fmt' => '—',
            'href_aksi' => '/keuangan/perbaikan-kas.php#duplikat',
            'href_label' => 'Lihat daftar',
            'bisa_perbaiki_otomatis' => false,
        ];
    }

    $nominalBerlebihan = $perbaikan['nominal_berlebihan'] ?? [];
    if ($nominalBerlebihan !== []) {
        $def = keuangan_kesalahan_kas_def('pembayaran_nominal_berlebihan');
        $nomExcess = 0;
        foreach ($nominalBerlebihan as $nb) {
            $nomExcess += (int) ($nb['kelebihan'] ?? 0);
        }
        $items[] = [
            'kode' => 'pembayaran_nominal_berlebihan',
            'prioritas' => 'sedang',
            'judul' => (string) ($def['judul'] ?? ''),
            'penjelasan' => (string) ($def['penjelasan'] ?? ''),
            'dampak' => (string) ($def['dampak'] ?? ''),
            'solusi' => (string) ($def['solusi'] ?? ''),
            'jumlah' => count($nominalBerlebihan),
            'nominal' => $nomExcess,
            'nominal_fmt' => $fmt($nomExcess),
            'href_aksi' => '/keuangan/perbaikan-kas.php#nominal-berlebihan',
            'href_label' => 'Perbaiki nominal',
            'bisa_perbaiki_otomatis' => false,
        ];
    }

    $totalDetailSelisih = $perbaikan['total_detail_selisih'] ?? [];
    if ($totalDetailSelisih !== []) {
        $def = keuangan_kesalahan_kas_def('pembayaran_total_detail_selisih');
        $items[] = [
            'kode' => 'pembayaran_total_detail_selisih',
            'prioritas' => 'sedang',
            'judul' => (string) ($def['judul'] ?? ''),
            'penjelasan' => (string) ($def['penjelasan'] ?? ''),
            'dampak' => (string) ($def['dampak'] ?? ''),
            'solusi' => (string) ($def['solusi'] ?? ''),
            'jumlah' => count($totalDetailSelisih),
            'nominal' => 0,
            'nominal_fmt' => count($totalDetailSelisih) . ' baris',
            'href_aksi' => '/keuangan/perbaikan-kas.php#total-detail-selisih',
            'href_label' => 'Lihat daftar',
            'bisa_perbaiki_otomatis' => false,
        ];
    }

    $gajiRows = keuangan_diagnostik_list_gaji_tanpa_pengeluaran($pdo);
    if ($gajiRows !== []) {
        $def = keuangan_kesalahan_kas_def('gaji_tanpa_pengeluaran');
        $nomGaji = 0;
        foreach ($gajiRows as $g) {
            $nomGaji += (int) round((float) ($g['total_bayar'] ?? 0));
        }
        $items[] = [
            'kode' => 'gaji_tanpa_pengeluaran',
            'prioritas' => 'sedang',
            'judul' => (string) ($def['judul'] ?? ''),
            'penjelasan' => (string) ($def['penjelasan'] ?? ''),
            'dampak' => (string) ($def['dampak'] ?? ''),
            'solusi' => (string) ($def['solusi'] ?? ''),
            'jumlah' => count($gajiRows),
            'nominal' => $nomGaji,
            'nominal_fmt' => $fmt($nomGaji),
            'href_aksi' => '/keuangan/perbaikan-kas.php#gaji',
            'href_label' => 'Lihat gaji',
            'bisa_perbaiki_otomatis' => false,
        ];
    }

    if (!function_exists('keuangan_pembayaran_list_saku_tanpa_topup')) {
        require_once __DIR__ . '/keuangan_pembayaran_admin.php';
    }
    $sakuOrphans = keuangan_pembayaran_list_saku_tanpa_topup($pdo);
    if ($sakuOrphans !== []) {
        $nomSakuOrphan = 0;
        foreach ($sakuOrphans as $so) {
            $nomSakuOrphan += (int) round((float) ($so['nominal_saku'] ?? 0));
        }
        $items[] = [
            'kode' => 'saku_tanpa_topup',
            'prioritas' => 'tinggi',
            'judul' => 'Pembayaran Saku tanpa top-up cashless',
            'penjelasan' => 'Ada pembayaran pos Saku yang tercatat tetapi saldo cashless santri belum bertambah (tidak ada baris TOPUP).',
            'dampak' => 'Saldo saku santri di aplikasi cashless tidak sesuai dengan pembayaran yang sudah diterima.',
            'solusi' => 'Jalankan backfill top-up dari halaman Perbaikan Kas. Guard anti-duplikat mencegah top-up ganda.',
            'jumlah' => count($sakuOrphans),
            'nominal' => $nomSakuOrphan,
            'nominal_fmt' => $fmt($nomSakuOrphan),
            'href_aksi' => '/keuangan/perbaikan-kas.php#saku-topup',
            'href_label' => 'Perbaiki top-up',
            'bisa_perbaiki_otomatis' => true,
            'aksi' => 'backfill_saku_topup',
        ];
    }

    if (table_exists($pdo, 'keuangan_pembayaran')) {
        try {
            if (!function_exists('keuangan_build_neraca_cached')) {
                require_once __DIR__ . '/keuangan_neraca.php';
            }
            if (!function_exists('keuangan_neraca_saran_perbaikan')) {
                require_once __DIR__ . '/keuangan_neraca_perbaikan.php';
            }
            $neraca = keuangan_build_neraca_cached($pdo, $asOf);
            $analisis = keuangan_neraca_analisis_selisih($pdo, $neraca);
            $saranNeraca = keuangan_neraca_saran_perbaikan($pdo, $neraca, $analisis);
            $skipKode = ['pembayaran_tanpa_akun', 'pemasukan_tanpa_akun', 'pengeluaran_tanpa_akun', 'seimbang'];
            foreach ($saranNeraca as $s) {
                $kode = (string) ($s['kode'] ?? '');
                if ($kode === '' || in_array($kode, $skipKode, true)) {
                    continue;
                }
                $items[] = [
                    'kode' => $kode,
                    'prioritas' => (string) ($s['prioritas'] ?? 'sedang'),
                    'judul' => (string) ($s['judul'] ?? ''),
                    'penjelasan' => (string) ($s['deskripsi'] ?? ''),
                    'dampak' => '',
                    'solusi' => implode(' ', (array) ($s['langkah'] ?? [])),
                    'jumlah' => (int) ($s['jumlah'] ?? 0),
                    'nominal' => (int) ($s['nominal'] ?? 0),
                    'nominal_fmt' => $fmt((int) ($s['nominal'] ?? 0)),
                    'href_aksi' => (string) ($s['link'] ?? '/keuangan/neraca-perbaikan.php'),
                    'href_label' => (string) ($s['link_label'] ?? 'Detail'),
                    'bisa_perbaiki_otomatis' => ($s['aksi'] ?? '') === 'backfill_jurnal',
                    'aksi' => (string) ($s['aksi'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            error_log('keuangan_diagnostik_menyeluruh neraca: ' . $e->getMessage());
        }

        $kasMode = keuangan_kas_saldo_mode($pdo);
        $opening = keuangan_kas_total_opening_balance($pdo);
        if ($kasMode === KEUNGAN_KAS_MODE_TRANSAKSI && $opening > 0) {
            $items[] = [
                'kode' => 'saldo_awal_mode',
                'prioritas' => 'rendah',
                'judul' => 'Saldo awal tercatat di akun (mode transaksi)',
                'penjelasan' => 'Mode saldo kas «mulai dari nol» aktif, tetapi ada saldo awal di pengaturan akun.',
                'dampak' => 'Saldo fisik bisa lebih tinggi dari mutasi transaksi saja.',
                'solusi' => 'Samakan saldo awal akun dengan bukti fisik, atau ubah mode di Pengaturan Keuangan.',
                'jumlah' => 0,
                'nominal' => $opening,
                'nominal_fmt' => $fmt($opening),
                'href_aksi' => '/keuangan/pengaturan.php?bagian=akun',
                'href_label' => 'Pengaturan akun',
                'bisa_perbaiki_otomatis' => false,
            ];
        }
    }

    $items[] = [
        'kode' => 'transfer_manual',
        'prioritas' => 'rendah',
        'judul' => 'Transfer antar kas dan rekening',
        'penjelasan' => 'Pindah uang dari kas ke bank (atau sebaliknya) harus dicatat sebagai pengeluaran dari akun sumber dan pemasukan ke akun tujuan.',
        'dampak' => 'Tanpa pencatatan ganda, total likuid benar tetapi per-akun salah.',
        'solusi' => 'Catat dua baris: keluar dari akun asal, masuk ke akun tujuan, dengan keterangan transfer.',
        'jumlah' => 0,
        'nominal' => 0,
        'nominal_fmt' => '—',
        'href_aksi' => '/keuangan/panduan.php',
        'href_label' => 'Panduan alur',
        'bisa_perbaiki_otomatis' => false,
    ];

    $prioritasOrder = ['tinggi' => 0, 'sedang' => 1, 'rendah' => 2];
    usort($items, static function (array $a, array $b) use ($prioritasOrder): int {
        $pa = $prioritasOrder[$a['prioritas'] ?? 'rendah'] ?? 9;
        $pb = $prioritasOrder[$b['prioritas'] ?? 'rendah'] ?? 9;
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }

        return ((int) ($b['nominal'] ?? 0)) <=> ((int) ($a['nominal'] ?? 0));
    });

    $countTinggi = 0;
    $countSedang = 0;
    $countRendah = 0;
    $nominalMasalah = (int) ($perbaikan['nominal'] ?? 0);
    foreach ($items as $it) {
        $p = (string) ($it['prioritas'] ?? '');
        if ($p === 'tinggi') {
            $countTinggi++;
        } elseif ($p === 'sedang') {
            $countSedang++;
        } else {
            $countRendah++;
        }
    }

    return [
        'as_of' => $asOf,
        'ringkas' => [
            'tinggi' => $countTinggi,
            'sedang' => $countSedang,
            'rendah' => $countRendah,
            'total_item' => count($items),
            'tanpa_akun' => (int) ($perbaikan['jumlah'] ?? 0),
            'nominal_tanpa_akun' => $nominalMasalah,
            'duplikat' => count($duplikat),
            'gaji_tanpa_pengeluaran' => count($gajiRows),
            'saku_tanpa_topup' => count($sakuOrphans),
            'nominal_berlebihan' => count($nominalBerlebihan),
            'total_detail_selisih' => count($totalDetailSelisih),
        ],
        'items' => $items,
        'perbaikan_kas' => $perbaikan,
        'gaji_tanpa_pengeluaran' => $gajiRows,
        'saku_tanpa_topup' => $sakuOrphans,
        'nominal_berlebihan' => $nominalBerlebihan,
        'total_detail_selisih' => $totalDetailSelisih,
    ];
}

/**
 * Mutasi kas/bank hari ini (semua akun aktif).
 *
 * @return array{masuk:int,keluar:int,bersih:int,tanggal:string}
 */
function keuangan_dashboard_mutasi_hari_ini(PDO $pdo, ?string $tanggal = null): array
{
    $tanggal = $tanggal !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal) ? $tanggal : date('Y-m-d');
    $masuk = 0;
    $keluar = 0;

    if (table_exists($pdo, 'keuangan_pembayaran')) {
        $st = $pdo->prepare('SELECT COALESCE(SUM(total_nominal), 0) FROM keuangan_pembayaran WHERE tanggal_bayar = :t AND akun_id IS NOT NULL AND akun_id > 0');
        $st->execute(['t' => $tanggal]);
        $masuk += (int) round((float) ($st->fetchColumn() ?: 0));
    }
    if (table_exists($pdo, 'keuangan_pemasukan')) {
        $st = $pdo->prepare('SELECT COALESCE(SUM(nominal), 0) FROM keuangan_pemasukan WHERE tanggal = :t AND akun_id IS NOT NULL AND akun_id > 0');
        $st->execute(['t' => $tanggal]);
        $masuk += (int) round((float) ($st->fetchColumn() ?: 0));
    }
    if (table_exists($pdo, 'keuangan_pengeluaran')) {
        $st = $pdo->prepare('SELECT COALESCE(SUM(nominal), 0) FROM keuangan_pengeluaran WHERE tanggal = :t AND akun_id IS NOT NULL AND akun_id > 0');
        $st->execute(['t' => $tanggal]);
        $keluar += (int) round((float) ($st->fetchColumn() ?: 0));
    }

    return [
        'tanggal' => $tanggal,
        'masuk' => $masuk,
        'keluar' => $keluar,
        'bersih' => $masuk - $keluar,
    ];
}

/**
 * Akun kas/bank dengan mutasi bulan berjalan per akun.
 *
 * @return array<string, mixed>
 */
function keuangan_dashboard_kas_bank_detail(PDO $pdo, ?string $asOf = null, ?string $bulanAwal = null, ?string $bulanAkhir = null): array
{
    if (!table_exists($pdo, 'keuangan_akun')) {
        return ['total' => 0, 'total_kas' => 0, 'total_bank' => 0, 'akun' => [], 'as_of_label' => date('d/m/Y')];
    }

    $asOf = $asOf !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf) ? $asOf : date('Y-m-d');
    $bulanAwal = $bulanAwal ?? date('Y-m-01', strtotime($asOf) ?: time());
    $bulanAkhir = $bulanAkhir ?? $asOf;

    if (!function_exists('keuangan_sql_subquery_masuk_per_akun')) {
        require_once __DIR__ . '/keuangan_akun_mutasi.php';
    }
    if (!function_exists('keuangan_sql_opening_balance_expr')) {
        require_once __DIR__ . '/keuangan_pengaturan.php';
    }

    $stmt = $pdo->prepare("
        SELECT a.id, a.jenis_akun, a.nama_akun, a.nama_bank, a.no_rekening
        FROM keuangan_akun a
        WHERE a.is_active = 1
        ORDER BY a.jenis_akun ASC, a.nama_akun ASC
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $akunOut = [];
    $totalKas = 0;
    $totalBank = 0;
    $openingExpr = keuangan_sql_opening_balance_expr($pdo);

    foreach ($rows as $row) {
        $aid = (int) ($row['id'] ?? 0);
        if ($aid <= 0) {
            continue;
        }

        $masukBulan = 0;
        $keluarBulan = 0;
        if (table_exists($pdo, 'keuangan_pembayaran')) {
            $st = $pdo->prepare('SELECT COALESCE(SUM(total_nominal), 0) FROM keuangan_pembayaran WHERE akun_id = :aid AND tanggal_bayar BETWEEN :aw AND :ak');
            $st->execute(['aid' => $aid, 'aw' => $bulanAwal, 'ak' => $bulanAkhir]);
            $masukBulan += (int) round((float) ($st->fetchColumn() ?: 0));
        }
        if (table_exists($pdo, 'keuangan_pemasukan')) {
            $st = $pdo->prepare('SELECT COALESCE(SUM(nominal), 0) FROM keuangan_pemasukan WHERE akun_id = :aid AND tanggal BETWEEN :aw AND :ak');
            $st->execute(['aid' => $aid, 'aw' => $bulanAwal, 'ak' => $bulanAkhir]);
            $masukBulan += (int) round((float) ($st->fetchColumn() ?: 0));
        }
        if (table_exists($pdo, 'keuangan_pengeluaran')) {
            $st = $pdo->prepare('SELECT COALESCE(SUM(nominal), 0) FROM keuangan_pengeluaran WHERE akun_id = :aid AND tanggal BETWEEN :aw AND :ak');
            $st->execute(['aid' => $aid, 'aw' => $bulanAwal, 'ak' => $bulanAkhir]);
            $keluarBulan = (int) round((float) ($st->fetchColumn() ?: 0));
        }

        $masukSub = keuangan_sql_subquery_masuk_per_akun($pdo);
        $stSaldo = $pdo->prepare("
            SELECT ({$openingExpr} + COALESCE(inc.total_masuk, 0) - COALESCE(exp.total_keluar, 0)) AS saldo
            FROM keuangan_akun a
            LEFT JOIN ( {$masukSub} ) inc ON inc.akun_id = a.id
            LEFT JOIN (
                SELECT akun_id, SUM(nominal) AS total_keluar
                FROM keuangan_pengeluaran
                WHERE akun_id IS NOT NULL AND tanggal <= :as_of2
                GROUP BY akun_id
            ) exp ON exp.akun_id = a.id
            WHERE a.id = :aid
        ");
        $stSaldo->execute(['as_of' => $asOf, 'as_of2' => $asOf, 'aid' => $aid]);
        $saldo = (int) round((float) ($stSaldo->fetchColumn() ?: 0));

        $jenis = strtoupper(trim((string) ($row['jenis_akun'] ?? 'KAS')));
        if ($jenis === 'BANK') {
            $totalBank += $saldo;
        } else {
            $totalKas += $saldo;
        }
        $nomor = trim((string) ($row['no_rekening'] ?? ''));
        if ($nomor === '' && $jenis === 'BANK') {
            $nomor = trim((string) ($row['nama_bank'] ?? ''));
        }
        $akunOut[] = [
            'id' => $aid,
            'jenis' => $jenis,
            'nama' => (string) ($row['nama_akun'] ?? '-'),
            'nomor' => $nomor,
            'saldo' => $saldo,
            'masuk_bulan' => $masukBulan,
            'keluar_bulan' => $keluarBulan,
        ];
    }

    $bulanId = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $ts = strtotime($asOf) ?: time();

    return [
        'total' => $totalKas + $totalBank,
        'total_kas' => $totalKas,
        'total_bank' => $totalBank,
        'akun' => $akunOut,
        'as_of_label' => (int) date('j', $ts) . ' ' . ($bulanId[(int) date('n', $ts)] ?? '') . ' ' . date('Y', $ts),
        'bulan_awal' => $bulanAwal,
        'bulan_akhir' => $bulanAkhir,
    ];
}

/** Saran akun default berdasarkan metode bayar transaksi. */
function keuangan_diagnostik_saran_akun_id(PDO $pdo, string $tipe, array $row, array $akunRows): int
{
    $default = keuangan_perbaikan_kas_default_akun_id($pdo);
    $metode = '';
    if ($tipe === 'pembayaran' && table_exists($pdo, 'keuangan_pembayaran')) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0 && column_exists($pdo, 'keuangan_pembayaran', 'metode_bayar')) {
            $st = $pdo->prepare('SELECT metode_bayar FROM keuangan_pembayaran WHERE id = :id LIMIT 1');
            $st->execute(['id' => $id]);
            $metode = strtoupper(trim((string) ($st->fetchColumn() ?: '')));
        }
    }
    $preferBank = $metode === 'TRANSFER';
    foreach ($akunRows as $ar) {
        $jenis = strtoupper(trim((string) ($ar['jenis_akun'] ?? 'KAS')));
        $aid = (int) ($ar['id'] ?? 0);
        if ($aid <= 0) {
            continue;
        }
        if ($preferBank && $jenis === 'BANK') {
            return $aid;
        }
        if (!$preferBank && $jenis !== 'BANK') {
            return $aid;
        }
    }

    return $default;
}
