<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/**
 * Pesan validasi terstandar untuk modul kas (input & koreksi).
 *
 * @param array<string, scalar|null> $ctx
 */
function keuangan_validasi_pesan(string $kode, array $ctx = []): string
{
    $fmt = static fn(int $n): string => function_exists('keuangan_format_rupiah')
        ? keuangan_format_rupiah($n)
        : number_format($n, 0, ',', '.');

    $messages = [
        'AKUN_KOSONG' => 'Akun kas/bank wajib dipilih. Tanpa akun, uang tidak masuk saldo fisik dan rekap kas tidak selaras.',
        'AKUN_TIDAK_AKTIF' => 'Akun kas/bank tidak valid atau sudah nonaktif. Pilih akun aktif di Pengaturan Keuangan.',
        'AKUN_BELUM_ADA' => 'Belum ada akun kas/bank aktif. Buat minimal satu akun di Pengaturan Keuangan sebelum mencatat transaksi.',
        'TRANSFER_TANPA_REF' => 'Metode transfer wajib diisi nomor referensi/bukti transfer agar transaksi dapat diverifikasi.',
        'NOMINAL_KOSONG' => 'Nominal harus lebih dari nol.',
        'SANTRI_KOSONG' => 'Pilih santri terlebih dahulu.',
        'POS_KOSONG' => 'Pilih minimal satu komponen pembayaran dengan nominal lebih dari nol.',
        'FORM_PENGELUARAN_KOSONG' => 'Form pengeluaran belum lengkap: penanggung jawab, pos beban, dan nominal wajib diisi.',
        'FORM_PEMASUKAN_KOSONG' => 'Form pemasukan belum lengkap: sumber dan nominal wajib diisi.',
        'DOBEL_LUNAS' => (string) ($ctx['detail'] ?? 'Komponen tagihan sudah lunas — input dobel ditolak untuk mencegah entri ganda.'),
        'BULAN_DIBLOKIR' => (string) ($ctx['detail'] ?? 'Bulan tagihan ini belum ditagih atau tidak dapat dipilih untuk santri ini.'),
    ];

    return $messages[$kode] ?? 'Data transaksi tidak valid. Periksa kembali formulir.';
}

/**
 * Definisi jenis kesalahan kas (data sudah tersimpan).
 *
 * @return array{kode:string,judul:string,penjelasan:string,dampak:string,solusi:string}
 */
function keuangan_kesalahan_kas_def(string $jenis): array
{
    $defs = [
        'pembayaran_tanpa_akun' => [
            'kode' => 'pembayaran_tanpa_akun',
            'judul' => 'Pembayaran tanpa akun penerimaan',
            'penjelasan' => 'Pembayaran santri tercatat, tetapi tidak terhubung ke akun kas/bank.',
            'dampak' => 'Tagihan santri terhitung lunas, namun saldo kas fisik tidak bertambah — rekap kas bisa selisih.',
            'solusi' => 'Pilih akun kas penerima di Perbaikan Kas, atau edit penuh jika ada kesalahan lain.',
        ],
        'pemasukan_tanpa_akun' => [
            'kode' => 'pemasukan_tanpa_akun',
            'judul' => 'Pemasukan tanpa akun penerimaan',
            'penjelasan' => 'Uang masuk (donasi/hibah/dll.) tercatat tanpa akun kas/bank.',
            'dampak' => 'Pendapatan tercatat di buku, tetapi saldo kas fisik tidak naik.',
            'solusi' => 'Hubungkan ke akun kas yang benar-benar menerima uang.',
        ],
        'pengeluaran_tanpa_akun' => [
            'kode' => 'pengeluaran_tanpa_akun',
            'judul' => 'Pengeluaran tanpa akun sumber',
            'penjelasan' => 'Beban/pengeluaran tercatat tanpa akun kas/bank sumber dana.',
            'dampak' => 'Beban tercatat, tetapi saldo kas fisik tidak berkurang — neraca dan rekap bisa selisih.',
            'solusi' => 'Pilih akun kas yang benar-benar dipakai membayar.',
        ],
        'pembayaran_dobel' => [
            'kode' => 'pembayaran_dobel',
            'judul' => 'Kemungkinan pembayaran dobel',
            'penjelasan' => 'Ada lebih dari satu baris pembayaran dengan santri, tanggal, dan nominal yang sama.',
            'dampak' => 'Saldo kas dan tagihan santri bisa terhitung dua kali untuk transaksi yang sebenarnya satu kali.',
            'solusi' => 'Periksa kuitansi/bukti fisik. Hapus baris duplikat (super admin + alasan) jika memang entri ganda.',
        ],
        'gaji_tanpa_pengeluaran' => [
            'kode' => 'gaji_tanpa_pengeluaran',
            'judul' => 'Gaji tanpa baris pengeluaran kas',
            'penjelasan' => 'Gaji pembimbing tercatat tanpa baris pengeluaran terkait di kas.',
            'dampak' => 'Rekap kas menghitung keluar, tetapi saldo fisik tidak berkurang.',
            'solusi' => 'Buat atau hubungkan baris pengeluaran gaji dengan akun kas yang dipakai bayar.',
        ],
    ];

    return $defs[$jenis] ?? [
        'kode' => $jenis,
        'judul' => 'Kesalahan data kas',
        'penjelasan' => 'Transaksi perlu diperiksa.',
        'dampak' => 'Saldo hitung dan saldo fisik bisa tidak selaras.',
        'solusi' => 'Perbaiki lewat menu Perbaikan Kas.',
    ];
}

/**
 * Analisis penyebab selisih rekap kas (hitung vs fisik).
 *
 * @return list<array<string, mixed>>
 */
function keuangan_rekap_kas_analisis_selisih(PDO $pdo, array $rekap): array
{
    $selisih = (int) ($rekap['selisih_saldo'] ?? 0);
    if (abs($selisih) < 1000) {
        return [];
    }

    require_once __DIR__ . '/keuangan_neraca_perbaikan.php';
    require_once __DIR__ . '/keuangan_rekonsiliasi.php';

    $baris = $rekap['baris'] ?? [];
    $asOf = date('Y-m-d');
    if ($baris !== []) {
        $last = $baris[count($baris) - 1];
        $asOf = trim((string) ($last['tanggal_sampai'] ?? $asOf));
    }

    $penyebab = [];
    $fmt = static fn(int $n): string => keuangan_format_rupiah($n);
    $arah = $selisih > 0 ? 'fisik_lebih' : 'hitung_lebih';

    foreach (
        [
            'keuangan_pembayaran' => ['pembayaran_tanpa_akun', 'tanggal_bayar'],
            'keuangan_pemasukan' => ['pemasukan_tanpa_akun', 'tanggal'],
            'keuangan_pengeluaran' => ['pengeluaran_tanpa_akun', 'tanggal'],
        ] as $table => [$jenis, $dateCol]
    ) {
        if (!table_exists($pdo, $table) || !column_exists($pdo, $table, 'akun_id')) {
            continue;
        }
        $agg = keuangan_neraca_hitung_transaksi_tanpa_akun($pdo, $table, $dateCol, $asOf);
        $jumlah = (int) ($agg['jumlah'] ?? 0);
        if ($jumlah <= 0) {
            continue;
        }
        $def = keuangan_kesalahan_kas_def($jenis);
        $penyebab[] = [
            'jenis' => $jenis,
            'judul' => $def['judul'],
            'keterangan' => $def['penjelasan'] . ' ' . $def['dampak'],
            'jumlah' => $jumlah,
            'nominal' => (int) ($agg['nominal'] ?? 0),
            'nominal_fmt' => $fmt((int) ($agg['nominal'] ?? 0)),
        ];
    }

    if (table_exists($pdo, 'keuangan_gaji_pembimbing') && column_exists($pdo, 'keuangan_gaji_pembimbing', 'pengeluaran_id')) {
        $gajiWhere = keuangan_sql_gaji_belum_di_pengeluaran_where($pdo);
        $st = $pdo->query("
            SELECT COUNT(*) AS c, COALESCE(SUM(total_bayar), 0) AS n
            FROM keuangan_gaji_pembimbing
            WHERE {$gajiWhere}
        ");
        $g = $st ? ($st->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        $gj = (int) ($g['c'] ?? 0);
        if ($gj > 0) {
            $def = keuangan_kesalahan_kas_def('gaji_tanpa_pengeluaran');
            $penyebab[] = [
                'jenis' => 'gaji_tanpa_pengeluaran',
                'judul' => $def['judul'],
                'keterangan' => $def['penjelasan'] . ' ' . $def['dampak'],
                'jumlah' => $gj,
                'nominal' => (int) round((float) ($g['n'] ?? 0)),
                'nominal_fmt' => $fmt((int) round((float) ($g['n'] ?? 0))),
            ];
        }
    }

    require_once __DIR__ . '/keuangan_perbaikan_kas.php';
    $duplikat = keuangan_perbaikan_kas_list_duplikat_mungkin($pdo, 5);
    if ($duplikat !== []) {
        $def = keuangan_kesalahan_kas_def('pembayaran_dobel');
        $penyebab[] = [
            'jenis' => 'pembayaran_dobel',
            'judul' => $def['judul'],
            'keterangan' => $def['penjelasan'] . ' ' . $def['dampak'],
            'jumlah' => count($duplikat),
            'nominal' => 0,
            'nominal_fmt' => count($duplikat) . ' pasang',
        ];
    }

    if ($penyebab === []) {
        $penyebab[] = [
            'jenis' => 'umum',
            'judul' => 'Selisih saldo hitung vs fisik',
            'keterangan' => $arah === 'fisik_lebih'
                ? 'Saldo fisik lebih besar ' . $fmt(abs($selisih)) . ' dari perhitungan rekap. Periksa transaksi lama, saldo awal akun, atau gaji tanpa akun.'
                : 'Saldo hitung lebih besar ' . $fmt(abs($selisih)) . ' dari saldo fisik. Biasanya ada uang masuk tercatat tanpa akun kas atau entri ganda.',
            'jumlah' => 0,
            'nominal' => abs($selisih),
            'nominal_fmt' => $fmt(abs($selisih)),
        ];
    }

    return $penyebab;
}
