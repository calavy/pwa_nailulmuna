<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_defs.php';
require_once __DIR__ . '/santri_operasional.php';
require_once __DIR__ . '/santri_list_sort.php';
require_once __DIR__ . '/pondok_kalender.php';

/** @return array<int, string> */
function keuangan_bulan_map(PDO $pdo = null): array
{
    if ($pdo instanceof PDO) {
        return pondok_bulan_nama_map($pdo);
    }

    return [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];
}

/** Tahun ajaran dari tanggal (Juli Masehi atau tahun H. jika kalender pondok Hijriyah). */
function keuangan_tahun_ajaran_from_date(?string $dateYmd = null, ?PDO $pdo = null): array
{
    if ($pdo instanceof PDO) {
        return pondok_tahun_ajaran_from_date($pdo, $dateYmd);
    }

    return pondok_tahun_ajaran_masehi_dari_tanggal($dateYmd, null);
}

/** Bulan tagihan slot yang sedang berjalan (1–12, mengikuti kalender pondok). */
function keuangan_bulan_berjalan(?string $dateYmd = null, ?PDO $pdo = null): int
{
    if ($pdo instanceof PDO) {
        return (int) (pondok_periode_berjalan($pdo, $dateYmd)['bulan'] ?? 1);
    }

    return keuangan_bulan_berjalan_masehi($dateYmd);
}

/**
 * Periode keuangan aktif: tahun ajaran + bulan kalender sesuai waktu sekarang.
 *
 * @return array{mulai:int,selesai:int,bulan:int,bulan_label:string,ta_label:string,tahun_kalender:int}
 */
function keuangan_periode_berjalan(PDO $pdo, ?string $dateYmd = null): array
{
    return pondok_periode_berjalan($pdo, $dateYmd);
}

/** @return array{mulai:int,selesai:int} */
function keuangan_tahun_ajaran_aktif(PDO $pdo, ?string $dateYmd = null): array
{
    return pondok_tahun_ajaran_aktif($pdo, $dateYmd);
}

/**
 * Baris tagihan wajib 12 bulan untuk satu santri (TA aktif + penanda bulan berjalan).
 *
 * @return list<array<string, mixed>>
 */
function keuangan_tagihan_bulanan_rows(PDO $pdo, int $santriId, string $kelasKategori, ?int $bulanBerjalan = null): array
{
    if ($santriId <= 0) {
        return [];
    }
    $periode = keuangan_tahun_ajaran_aktif($pdo);
    $bulanIni = $bulanBerjalan ?? keuangan_bulan_berjalan(null, $pdo);
    $slots = pondok_bulan_slots_tahun_ajaran($pdo, $periode['mulai'], $periode['selesai']);
    $rows = [];
    foreach ($slots as $slot) {
        $m = (int) ($slot['bulan_tagihan'] ?? 0);
        if ($m < 1 || $m > 12) {
            continue;
        }
        if (!function_exists('tagihan_bulanan_page_context')) {
            require_once __DIR__ . '/tagihan_bulanan.php';
        }
        $ctx = tagihan_bulanan_page_context($pdo, $m, $periode['mulai'], $periode['selesai']);
        $paidMap = $ctx['paid_map'];
        $syCtx = $ctx['sy_ctx'];
        $st = tagihan_wajib_status_for_month_bulk(
            $pdo,
            $santriId,
            $m,
            $periode['mulai'],
            $periode['selesai'],
            $kelasKategori,
            $paidMap,
            $syCtx
        );
        $perPos = (array) ($st['per_pos'] ?? []);
        $paidSantri = $paidMap[$santriId] ?? [];
        $ops = tagihan_opsional_pos_for_month_bulk($pdo, $kelasKategori, $paidSantri, $santriId);
        $rows[] = [
            'bulan' => $m,
            'label' => pondok_bulan_slot_label_tampilan($pdo, $slot),
            'masehi_awal' => (string) ($slot['masehi_awal'] ?? ''),
            'masehi_akhir' => (string) ($slot['masehi_akhir'] ?? ''),
            'kalender_hijriyah' => $slot['kalender_hijriyah'] ?? null,
            'tagihan' => (int) ($st['expected_total'] ?? 0),
            'bayar' => (int) ($st['paid_total'] ?? 0),
            'sisa' => (int) ($st['sisa_total'] ?? 0),
            'status' => (string) ($st['status'] ?? '—'),
            'badge' => (string) ($st['statusClass'] ?? 'secondary'),
            'is_bulan_ini' => $m === $bulanIni,
            'sy_expected' => (int) (($perPos['syahriyah']['expected'] ?? 0)),
            'sy_paid' => (int) (($perPos['syahriyah']['paid'] ?? 0)),
            'mk_expected' => (int) (($ops['makan']['expected'] ?? 0)),
            'mk_paid' => (int) (($ops['makan']['paid'] ?? 0)),
        ];
    }

    return $rows;
}

function ensure_keuangan_transaksi_tables(PDO $pdo): void
{
    if (!empty($_SESSION['keuangan_schema_ready_v1'])) {
        return;
    }
    static $ranThisRequest = false;
    if ($ranThisRequest) {
        return;
    }
    $ranThisRequest = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS keuangan_pembayaran (
            id INT AUTO_INCREMENT PRIMARY KEY,
            santri_id INT NOT NULL,
            jenis_periode ENUM('BULANAN','AWAL_TAHUN') NOT NULL DEFAULT 'BULANAN',
            tahun_ajaran_mulai SMALLINT NOT NULL,
            tahun_ajaran_selesai SMALLINT NOT NULL,
            bulan_tagihan TINYINT NULL,
            tanggal_bayar DATE NOT NULL,
            total_nominal DECIMAL(12,2) NOT NULL DEFAULT 0,
            keterangan TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_keu_bayar_santri (santri_id),
            INDEX idx_keu_bayar_periode (jenis_periode, tahun_ajaran_mulai, tahun_ajaran_selesai, bulan_tagihan)
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS keuangan_pembayaran_detail (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pembayaran_id INT NOT NULL,
            pos_slug VARCHAR(80) NOT NULL,
            pos_nama VARCHAR(150) NOT NULL,
            nominal DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_keu_detail_pembayaran (pembayaran_id)
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS keuangan_akun (
            id INT AUTO_INCREMENT PRIMARY KEY,
            jenis_akun ENUM('KAS','BANK','E-WALLET') NOT NULL DEFAULT 'KAS',
            nama_akun VARCHAR(120) NOT NULL,
            nama_bank VARCHAR(120) NULL,
            no_rekening VARCHAR(80) NULL,
            atas_nama VARCHAR(120) NULL,
            opening_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS keuangan_alokasi (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nama_komponen VARCHAR(150) NOT NULL,
            kategori VARCHAR(120) NOT NULL,
            jenis_dana ENUM('SYAHRIYAH','AWAL_TAHUN') NOT NULL DEFAULT 'SYAHRIYAH',
            persen DECIMAL(6,2) NOT NULL DEFAULT 0,
            urutan INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS keuangan_pengeluaran (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tanggal DATE NOT NULL,
            penanggung_jawab VARCHAR(120) NOT NULL,
            pos VARCHAR(120) NOT NULL,
            alokasi_nama VARCHAR(150) NULL,
            nominal DECIMAL(12,2) NOT NULL DEFAULT 0,
            keterangan TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS keuangan_pemasukan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tanggal DATE NOT NULL,
            sumber VARCHAR(120) NOT NULL,
            dari_pihak VARCHAR(150) NULL,
            metode_bayar ENUM('KAS','TRANSFER') NOT NULL DEFAULT 'KAS',
            akun_id INT NULL,
            no_bukti VARCHAR(120) NULL,
            nominal DECIMAL(12,2) NOT NULL DEFAULT 0,
            keterangan TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_keu_pemasukan_tanggal (tanggal),
            INDEX idx_keu_pemasukan_akun (akun_id)
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cashless_accounts (
            santri_id INT PRIMARY KEY,
            pin_hash VARCHAR(255) NULL,
            balance DECIMAL(12,2) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS cashless_transactions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            santri_id INT NOT NULL,
            tanggal DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            jenis ENUM('TOPUP','DEBIT') NOT NULL,
            nominal DECIMAL(12,2) NOT NULL DEFAULT 0,
            keterangan VARCHAR(255) NULL,
            ref_pembayaran_id INT NULL,
            created_by INT NULL,
            INDEX idx_cashless_santri_tanggal (santri_id, tanggal)
        )
    ");

    if (table_exists($pdo, 'keuangan_pembayaran')) {
        $pdo->exec("ALTER TABLE keuangan_pembayaran ADD COLUMN IF NOT EXISTS metode_bayar ENUM('KAS','TRANSFER') NOT NULL DEFAULT 'KAS'");
        $pdo->exec("ALTER TABLE keuangan_pembayaran ADD COLUMN IF NOT EXISTS akun_id INT NULL");
        $pdo->exec("ALTER TABLE keuangan_pembayaran ADD COLUMN IF NOT EXISTS no_referensi VARCHAR(100) NULL");
        $pdo->exec("ALTER TABLE keuangan_pembayaran ADD COLUMN IF NOT EXISTS status_lunas ENUM('LUNAS','CICILAN') NOT NULL DEFAULT 'LUNAS'");
    }
    if (table_exists($pdo, 'keuangan_pengeluaran')) {
        $pdo->exec("ALTER TABLE keuangan_pengeluaran ADD COLUMN IF NOT EXISTS metode_keluar ENUM('KAS','TRANSFER') NOT NULL DEFAULT 'KAS'");
        $pdo->exec("ALTER TABLE keuangan_pengeluaran ADD COLUMN IF NOT EXISTS akun_id INT NULL");
        $pdo->exec("ALTER TABLE keuangan_pengeluaran ADD COLUMN IF NOT EXISTS no_bukti VARCHAR(120) NULL");
    }

    keuangan_seed_akun_default($pdo);
    keuangan_seed_alokasi_default($pdo);
    require_once __DIR__ . '/keuangan_alokasi.php';
    ensure_keuangan_alokasi_jenis_dana($pdo);
    ensure_keuangan_pembayaran_kalender_hijriyah($pdo);
    keuangan_transaksi_bootstrap_jurnal();
    ensure_keuangan_jurnal_tables($pdo);
    keuangan_ensure_performance_indexes($pdo);
}

/** Indeks untuk filter tanggal / laporan arus kas (aman dijalankan berulang). */
function keuangan_ensure_performance_indexes(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $indexes = [
        ['keuangan_pembayaran', 'idx_keu_bayar_tanggal', 'tanggal_bayar'],
        ['keuangan_pengeluaran', 'idx_keu_pengeluaran_tanggal', 'tanggal'],
        ['keuangan_pembayaran_detail', 'idx_keu_detail_pos_slug', 'pos_slug'],
    ];
    foreach ($indexes as [$table, $indexName, $column]) {
        if (!table_exists($pdo, $table)) {
            continue;
        }
        try {
            $pdo->exec("ALTER TABLE {$table} ADD INDEX IF NOT EXISTS {$indexName} ({$column})");
        } catch (Throwable $e) {
            // Versi MySQL lama tanpa IF NOT EXISTS — abaikan jika indeks sudah ada.
        }
    }
}

function keuangan_transaksi_bootstrap_jurnal(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    require_once __DIR__ . '/keuangan_jurnal.php';
    $loaded = true;
}

function keuangan_transaksi_bootstrap_rekap(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    require_once __DIR__ . '/keuangan_rekap.php';
    $loaded = true;
}

/**
 * Migrasi skema modul keuangan — sekali per sesi login (hindari CREATE/ALTER tiap klik menu).
 */
function keuangan_ensure_schema_deferred(PDO $pdo): void
{
    if (!isset($_SESSION['user'])) {
        return;
    }
    if (!empty($_SESSION['keuangan_schema_ready_v1'])) {
        return;
    }

    ensure_keuangan_transaksi_tables($pdo);

    if (!function_exists('ensure_keuangan_syahriyah_potongan_table')) {
        require_once __DIR__ . '/keuangan_syahriyah_potongan.php';
    }
    ensure_keuangan_syahriyah_potongan_table($pdo);
    ensure_keuangan_syahriyah_potongan_jeda_table($pdo);

    if (!function_exists('ensure_keuangan_pembayaran_audit_table')) {
        require_once __DIR__ . '/keuangan_pembayaran_admin.php';
    }
    ensure_keuangan_pembayaran_audit_table($pdo);

    ensure_cashless_nominal_qr_map_table($pdo);
    ensure_cashless_nominal_tokens_table($pdo);

    if (!function_exists('cashless_koperasi_ensure_schema')) {
        require_once __DIR__ . '/cashless_koperasi.php';
    }
    cashless_koperasi_ensure_schema($pdo);

    if (!function_exists('ensure_keuangan_talangan_tables')) {
        require_once __DIR__ . '/keuangan_talangan.php';
    }
    ensure_keuangan_talangan_tables($pdo);

    if (!function_exists('ensure_keuangan_inventaris_tables')) {
        require_once __DIR__ . '/keuangan_inventaris.php';
    }
    ensure_keuangan_inventaris_tables($pdo);

    if (function_exists('ensure_keuangan_neraca_tables')) {
        ensure_keuangan_neraca_tables($pdo);
    }

    $_SESSION['keuangan_schema_ready_v1'] = 1;
}

/** Reset flag skema keuangan setelah migrasi manual / deploy skema baru. */
function keuangan_schema_cache_clear(): void
{
    global $pdo;
    if (!function_exists('app_performance_cache_clear')) {
        require_once __DIR__ . '/app_cache.php';
    }
    if ($pdo instanceof PDO) {
        app_performance_cache_clear($pdo, ['schema_flags' => true, 'opcache' => false, 'all_users_acl' => true]);
        return;
    }
    unset($_SESSION['keuangan_schema_ready_v1'], $_SESSION['keuangan_dash_snap_cache'], $_SESSION['pondok_ta_options_cache_v1']);
    foreach (array_keys($_SESSION) as $sk) {
        if (is_string($sk) && str_starts_with($sk, 'keu_alokasi_real_')) {
            unset($_SESSION[$sk]);
        }
    }
}

function keuangan_seed_akun_default(PDO $pdo): void
{
    if (!table_exists($pdo, 'keuangan_akun')) {
        return;
    }
    $cnt = (int) $pdo->query('SELECT COUNT(*) FROM keuangan_akun')->fetchColumn();
    if ($cnt > 0) {
        return;
    }
    $pdo->exec("
        INSERT INTO keuangan_akun (jenis_akun, nama_akun, is_default, is_active) VALUES
        ('KAS', 'Kas Bendahara', 1, 1),
        ('BANK', 'Rekening Operasional', 0, 1)
    ");
}

function keuangan_seed_alokasi_default(PDO $pdo): void
{
    if (!table_exists($pdo, 'keuangan_alokasi')) {
        return;
    }
    $cnt = (int) $pdo->query('SELECT COUNT(*) FROM keuangan_alokasi')->fetchColumn();
    if ($cnt > 0) {
        return;
    }
    $defaults = [
        ['Gaji mudaris (Pagu 15 guru)', 'Pendidikan', 37, 1],
        ['Gaji karyawan utama (2 orang)', 'Admin/Ops', 5, 2],
        ['Gaji karyawan tambahan (3 orang)', 'Pendukung', 7, 3],
        ['Listrik', 'Utilitas', 10, 4],
        ['Air bersih', 'Utilitas', 1, 5],
        ['WiFi', 'Digital', 1, 6],
        ['Kebersihan', 'Sarpras', 2, 8],
        ['Kesehatan', 'Sarpras', 2, 9],
    ];
    $ins = $pdo->prepare('
        INSERT INTO keuangan_alokasi (nama_komponen, kategori, jenis_dana, persen, urutan, is_active)
        VALUES (:nama, :kat, :jenis, :persen, :urutan, 1)
    ');
    foreach ($defaults as [$nama, $kat, $persen, $urutan]) {
        $ins->execute([
            'nama' => $nama,
            'kat' => $kat,
            'jenis' => 'SYAHRIYAH',
            'persen' => $persen,
            'urutan' => $urutan,
        ]);
    }
}

/** @return list<array<string, mixed>> */
function keuangan_fetch_akun_aktif(PDO $pdo): array
{
    if (!table_exists($pdo, 'keuangan_akun')) {
        return [];
    }

    return $pdo->query('
        SELECT id, jenis_akun, nama_akun, nama_bank, no_rekening, is_default
        FROM keuangan_akun
        WHERE is_active = 1
        ORDER BY is_default DESC, jenis_akun ASC, id ASC
    ')->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array<string, mixed>> */
function keuangan_fetch_santri_aktif(PDO $pdo): array
{
    if (!table_exists($pdo, 'santri')) {
        return [];
    }
    ensure_santri_identity_columns($pdo);
    $aktif = function_exists('santri_sql_aktif_only') ? santri_sql_aktif_only('s') : '1=1';
    $cols = ['id', 'nis', 'nama_santri'];
    if (column_exists($pdo, 'santri', 'kategori_kelas')) {
        $cols[] = 'kategori_kelas';
    }
    if (column_exists($pdo, 'santri', 'tingkatan')) {
        $cols[] = 'tingkatan';
    }

    return $pdo->query('SELECT ' . implode(', ', $cols) . ' FROM santri s WHERE ' . $aktif . ' ORDER BY ' . santri_list_order_sql('s'))->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * @param list<array<string, mixed>>|null $santriRows baris dari keuangan_fetch_santri_aktif (hindari query ganda)
 * @return array<string, array{kelas_label:string,tier_key:string,tier_label:string,fees:array<string,int>}>
 */
function keuangan_build_santri_keuangan_map(PDO $pdo, array $biayaDefinitions, ?array $santriRows = null): array
{
    if (!function_exists('keuangan_fee_matrix_from_settings')) {
        require_once __DIR__ . '/keuangan_defs.php';
    }
    $feeMatrix = keuangan_fee_matrix_from_settings($pdo, $biayaDefinitions);
    $tierByKelas = [];
    $map = [];
    foreach ($santriRows ?? keuangan_fetch_santri_aktif($pdo) as $s) {
        $id = (int) ($s['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $kat = trim((string) ($s['kategori_kelas'] ?? $s['tingkatan'] ?? ''));
        if (!array_key_exists($kat, $tierByKelas)) {
            $tierByKelas[$kat] = keuangan_tier_key_from_kelas($kat, $pdo);
        }
        $tier = $tierByKelas[$kat];
        $tierLabel = $tier === 'muadalah' ? 'Muadalah' : ($tier === 'ulya' ? 'Ulya' : 'Wustho');
        $fees = [];
        foreach ($biayaDefinitions as $def) {
            $slug = (string) ($def['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $fees[$slug] = (int) ($feeMatrix[$slug][$tier] ?? 0);
        }
        $map[(string) $id] = [
            'kelas_label' => $kat !== '' ? $kat : 'Belum diset',
            'tier_key' => $tier,
            'tier_label' => $tierLabel,
            'fees' => $fees,
        ];
    }

    return $map;
}

/**
 * Peta ringan untuk form pembayaran (tanpa matriks tarif per santri).
 *
 * @param list<array<string, mixed>>|null $santriRows
 * @return array<string, array{kelas_label:string,tier_key:string,tier_label:string}>
 */
function keuangan_build_santri_tier_label_map(PDO $pdo, ?array $santriRows = null): array
{
    $tierByKelas = [];
    $map = [];
    foreach ($santriRows ?? keuangan_fetch_santri_aktif($pdo) as $s) {
        $id = (int) ($s['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $kat = trim((string) ($s['kategori_kelas'] ?? $s['tingkatan'] ?? ''));
        if (!array_key_exists($kat, $tierByKelas)) {
            $tierByKelas[$kat] = keuangan_tier_key_from_kelas($kat, $pdo);
        }
        $tier = $tierByKelas[$kat];
        $tierLabel = $tier === 'muadalah' ? 'Muadalah' : ($tier === 'ulya' ? 'Ulya' : 'Wustho');
        $map[(string) $id] = [
            'kelas_label' => $kat !== '' ? $kat : 'Belum diset',
            'tier_key' => $tier,
            'tier_label' => $tierLabel,
        ];
    }

    return $map;
}

/**
 * @param array<string, mixed> $post
 * @return array{ok:bool,message:string,id?:int}
 */
function keuangan_save_pembayaran(PDO $pdo, array $post, int $userId): array
{
    ensure_keuangan_transaksi_tables($pdo);
    $biayaDefinitions = keuangan_biaya_definitions();
    $periode = keuangan_tahun_ajaran_aktif($pdo);

    $santriId = (int) ($post['santri_id'] ?? 0);
    $jenisPeriode = strtoupper(trim((string) ($post['jenis_periode'] ?? 'BULANAN')));
    $bulanTagihan = (int) ($post['bulan_tagihan'] ?? 0);
    $taInput = pondok_normalisasi_tahun_ajaran_input(
        $pdo,
        (int) ($post['tahun_ajaran_mulai'] ?? $periode['mulai']),
        (int) ($post['tahun_ajaran_selesai'] ?? $periode['selesai'])
    );
    $tahunMulai = $taInput['mulai'];
    $tahunSelesai = $taInput['selesai'];
    $tanggalBayar = trim((string) ($post['tanggal_bayar'] ?? date('Y-m-d')));
    $keterangan = trim((string) ($post['keterangan'] ?? ''));
    $metodeBayar = strtoupper(trim((string) ($post['metode_bayar'] ?? 'KAS')));
    $akunId = (int) ($post['akun_id'] ?? 0);
    $noReferensi = trim((string) ($post['no_referensi'] ?? ''));
    if (!in_array($jenisPeriode, ['BULANAN', 'AWAL_TAHUN'], true)) {
        $jenisPeriode = 'BULANAN';
    }
    if ($jenisPeriode !== 'BULANAN') {
        $bulanTagihan = 0;
    } elseif ($bulanTagihan < 1 || $bulanTagihan > 12) {
        $bulanTagihan = keuangan_bulan_berjalan(null, $pdo);
    }
    $kalenderHijriyahBayar = $jenisPeriode === 'BULANAN'
        ? pondok_kalender_hijriyah_untuk_simpan_pembayaran($pdo, $tahunMulai, $tahunSelesai, $bulanTagihan)
        : null;
    if (!in_array($metodeBayar, ['KAS', 'TRANSFER'], true)) {
        $metodeBayar = 'KAS';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalBayar)) {
        $tanggalBayar = date('Y-m-d');
    }

    $pickedPos = $post['bayar_pos'] ?? [];
    if (!is_array($pickedPos)) {
        $pickedPos = [];
    }

    if ($santriId <= 0 || $pickedPos === []) {
        return ['ok' => false, 'message' => 'Pilih santri dan minimal satu komponen pembayaran.'];
    }
    if ($akunId <= 0) {
        return ['ok' => false, 'message' => 'Pilih akun kas/bank penerimaan.'];
    }
    if ($metodeBayar === 'TRANSFER' && $noReferensi === '') {
        return ['ok' => false, 'message' => 'Nomor referensi transfer wajib diisi.'];
    }

    $kategoriFilter = $jenisPeriode === 'BULANAN' ? 'Bulanan' : 'Awal Tahun';
    keuangan_transaksi_bootstrap_rekap();
    $tagihanBreakdown = keuangan_tagihan_breakdown_for_santri(
        $pdo,
        $santriId,
        $jenisPeriode,
        $bulanTagihan,
        $tahunMulai,
        $tahunSelesai,
        $biayaDefinitions
    );
    $wajibSlugs = $jenisPeriode === 'BULANAN' ? keuangan_tagihan_wajib_slugs() : [];

    $totalNominal = 0;
    $detailRows = [];
    $stillHasSisaWajib = false;
    foreach ($biayaDefinitions as $def) {
        if (($def['kategori'] ?? '') !== $kategoriFilter) {
            continue;
        }
        $slug = (string) ($def['slug'] ?? '');
        if (!in_array($slug, $pickedPos, true)) {
            continue;
        }
        $nominal = keuangan_money_input_to_int((string) ($post['nominal_' . $slug] ?? '0'));
        if ($nominal <= 0) {
            continue;
        }
        $posInfo = $tagihanBreakdown[$slug] ?? null;
        if (is_array($posInfo) && $slug !== 'saku') {
            $sisa = (int) ($posInfo['sisa'] ?? 0);
            $expected = (int) ($posInfo['expected'] ?? 0);
            $isWajibBulanan = $jenisPeriode === 'BULANAN' && in_array($slug, $wajibSlugs, true);
            if ($expected > 0 && $nominal > $sisa && ($isWajibBulanan || $jenisPeriode === 'AWAL_TAHUN')) {
                return [
                    'ok' => false,
                    'message' => 'Nominal ' . ($def['nama'] ?? $slug) . ' melebihi sisa tagihan (Rp ' . number_format($sisa, 0, ',', '.') . ').',
                ];
            }
        }
        $detailRows[] = ['slug' => $slug, 'nama' => $def['nama'], 'nominal' => $nominal];
        $totalNominal += $nominal;
    }

    if ($detailRows === []) {
        return ['ok' => false, 'message' => 'Nominal pembayaran tidak valid.'];
    }

    foreach ($detailRows as $dr) {
        if ($dr['slug'] === 'saku') {
            continue;
        }
        $info = $tagihanBreakdown[$dr['slug']] ?? null;
        if (!is_array($info)) {
            continue;
        }
        $paidBefore = (int) ($info['paid'] ?? 0);
        $expected = (int) ($info['expected'] ?? 0);
        if ($expected > 0 && ($paidBefore + (int) $dr['nominal']) < $expected) {
            $stillHasSisaWajib = true;
            break;
        }
    }
    $statusLunas = $stillHasSisaWajib ? 'CICILAN' : 'LUNAS';

    $hasStatusCol = column_exists($pdo, 'keuangan_pembayaran', 'status_lunas');
    $hasMetodeCol = column_exists($pdo, 'keuangan_pembayaran', 'metode_bayar');

    $cols = ['santri_id', 'jenis_periode', 'tahun_ajaran_mulai', 'tahun_ajaran_selesai', 'bulan_tagihan', 'tanggal_bayar', 'total_nominal', 'keterangan', 'created_by'];
    $vals = [':santri_id', ':jenis_periode', ':mulai', ':selesai', ':bulan_tagihan', ':tanggal_bayar', ':total_nominal', ':keterangan', ':created_by'];
    $params = [
        'santri_id' => $santriId,
        'jenis_periode' => $jenisPeriode,
        'mulai' => $tahunMulai,
        'selesai' => $tahunSelesai,
        'bulan_tagihan' => $bulanTagihan > 0 ? $bulanTagihan : null,
        'tanggal_bayar' => $tanggalBayar,
        'total_nominal' => $totalNominal,
        'keterangan' => $keterangan,
        'created_by' => $userId > 0 ? $userId : null,
    ];
    if ($hasMetodeCol) {
        $cols[] = 'metode_bayar';
        $vals[] = ':metode_bayar';
        $params['metode_bayar'] = $metodeBayar;
    }
    if (column_exists($pdo, 'keuangan_pembayaran', 'akun_id')) {
        $cols[] = 'akun_id';
        $vals[] = ':akun_id';
        $params['akun_id'] = $akunId;
    }
    if (column_exists($pdo, 'keuangan_pembayaran', 'no_referensi')) {
        $cols[] = 'no_referensi';
        $vals[] = ':no_referensi';
        $params['no_referensi'] = $noReferensi !== '' ? $noReferensi : null;
    }
    if (column_exists($pdo, 'keuangan_pembayaran', 'kalender_hijriyah')) {
        $cols[] = 'kalender_hijriyah';
        $vals[] = ':kalender_hijriyah';
        $params['kalender_hijriyah'] = $kalenderHijriyahBayar;
    }
    if ($hasStatusCol) {
        $cols[] = 'status_lunas';
        $vals[] = ':status_lunas';
        $params['status_lunas'] = $statusLunas;
    }

    $sql = 'INSERT INTO keuangan_pembayaran (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
    $pdo->prepare($sql)->execute($params);
    $pembayaranId = (int) $pdo->lastInsertId();

    $insertDetail = $pdo->prepare('
        INSERT INTO keuangan_pembayaran_detail (pembayaran_id, pos_slug, pos_nama, nominal)
        VALUES (:pembayaran_id, :pos_slug, :pos_nama, :nominal)
    ');
    foreach ($detailRows as $dr) {
        $insertDetail->execute([
            'pembayaran_id' => $pembayaranId,
            'pos_slug' => $dr['slug'],
            'pos_nama' => $dr['nama'],
            'nominal' => $dr['nominal'],
        ]);
    }

    $hasSaku = array_filter($detailRows, static fn(array $r): bool => $r['slug'] === 'saku');
    if ($hasSaku) {
        $topupNominal = (int) array_sum(array_map(static fn(array $r): int => (int) $r['nominal'], $hasSaku));
        $pdo->prepare('INSERT IGNORE INTO cashless_accounts (santri_id, balance) VALUES (:santri_id, 0)')->execute(['santri_id' => $santriId]);
        $pdo->prepare('UPDATE cashless_accounts SET balance = balance + :nominal WHERE santri_id = :santri_id')->execute([
            'nominal' => $topupNominal,
            'santri_id' => $santriId,
        ]);
        $pdo->prepare("
            INSERT INTO cashless_transactions (santri_id, jenis, nominal, keterangan, ref_pembayaran_id, created_by)
            VALUES (:santri_id, 'TOPUP', :nominal, :keterangan, :ref_pembayaran_id, :created_by)
        ")->execute([
            'santri_id' => $santriId,
            'nominal' => $topupNominal,
            'keterangan' => 'Topup otomatis dari pembayaran pos Saku',
            'ref_pembayaran_id' => $pembayaranId,
            'created_by' => $userId > 0 ? $userId : null,
        ]);
    }

    keuangan_transaksi_bootstrap_jurnal();
    keuangan_jurnal_pembayaran(
        $pdo,
        $pembayaranId,
        $tanggalBayar,
        $akunId,
        $totalNominal,
        $detailRows,
        $kategoriFilter,
        $userId
    );

    if (!function_exists('keuangan_dashboard_cache_invalidate')) {
        require_once __DIR__ . '/keuangan_dashboard.php';
    }
    keuangan_dashboard_cache_invalidate();

    return [
        'ok' => true,
        'message' => 'Pembayaran berhasil disimpan. Total ' . keuangan_format_rupiah($totalNominal) . '.',
        'id' => $pembayaranId,
    ];
}

/**
 * @param array<string, mixed> $post
 * @return array{ok:bool,message:string}
 */
function keuangan_save_pengeluaran(PDO $pdo, array $post, int $userId): array
{
    ensure_keuangan_transaksi_tables($pdo);

    $tanggal = trim((string) ($post['tanggal_pengeluaran'] ?? date('Y-m-d')));
    $penanggungJawab = trim((string) ($post['penanggung_jawab'] ?? ''));
    $pos = trim((string) ($post['pos_pengeluaran'] ?? ''));
    $alokasiNama = trim((string) ($post['alokasi_nama'] ?? ''));
    $metodeKeluar = strtoupper(trim((string) ($post['metode_keluar'] ?? 'KAS')));
    $akunId = (int) ($post['akun_id'] ?? 0);
    $noBukti = trim((string) ($post['no_bukti'] ?? ''));
    $nominal = keuangan_money_input_to_int((string) ($post['nominal_pengeluaran'] ?? '0'));
    $keterangan = trim((string) ($post['keterangan_pengeluaran'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        $tanggal = date('Y-m-d');
    }
    if (!in_array($metodeKeluar, ['KAS', 'TRANSFER'], true)) {
        $metodeKeluar = 'KAS';
    }
    if ($penanggungJawab === '' || $pos === '' || $nominal <= 0) {
        return ['ok' => false, 'message' => 'Form pengeluaran belum lengkap (penanggung jawab, pos, nominal).'];
    }
    if ($akunId <= 0) {
        return ['ok' => false, 'message' => 'Pilih akun kas/bank sumber dana.'];
    }

    $cols = ['tanggal', 'penanggung_jawab', 'pos', 'alokasi_nama', 'nominal', 'keterangan', 'created_by'];
    $vals = [':tanggal', ':penanggung_jawab', ':pos', ':alokasi_nama', ':nominal', ':keterangan', ':created_by'];
    $params = [
        'tanggal' => $tanggal,
        'penanggung_jawab' => $penanggungJawab,
        'pos' => $pos,
        'alokasi_nama' => $alokasiNama !== '' ? $alokasiNama : null,
        'nominal' => $nominal,
        'keterangan' => $keterangan,
        'created_by' => $userId > 0 ? $userId : null,
    ];
    if (column_exists($pdo, 'keuangan_pengeluaran', 'metode_keluar')) {
        $cols[] = 'metode_keluar';
        $vals[] = ':metode_keluar';
        $params['metode_keluar'] = $metodeKeluar;
    }
    if (column_exists($pdo, 'keuangan_pengeluaran', 'akun_id')) {
        $cols[] = 'akun_id';
        $vals[] = ':akun_id';
        $params['akun_id'] = $akunId;
    }
    if (column_exists($pdo, 'keuangan_pengeluaran', 'no_bukti')) {
        $cols[] = 'no_bukti';
        $vals[] = ':no_bukti';
        $params['no_bukti'] = $noBukti !== '' ? $noBukti : null;
    }

    $sql = 'INSERT INTO keuangan_pengeluaran (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')';
    $pdo->prepare($sql)->execute($params);
    $pengeluaranId = (int) $pdo->lastInsertId();

    keuangan_transaksi_bootstrap_jurnal();
    keuangan_jurnal_pengeluaran($pdo, $pengeluaranId, $tanggal, $akunId, $nominal, $pos, $userId);

    if (!function_exists('keuangan_dashboard_cache_invalidate')) {
        require_once __DIR__ . '/keuangan_dashboard.php';
    }
    keuangan_dashboard_cache_invalidate();

    return ['ok' => true, 'message' => 'Pengeluaran berhasil dicatat (' . keuangan_format_rupiah($nominal) . ').'];
}

/** @return list<string> */
function keuangan_pemasukan_sumber_suggest(): array
{
    return [
        'Donasi umum',
        'Hibah / bantuan',
        'Wakaf',
        'Bantuan lembaga / yayasan',
        'Bunga bank',
        'Penjualan aset',
        'Retur / pengembalian dana',
        'Lainnya',
    ];
}

/**
 * @param array<string, mixed> $post
 * @return array{ok:bool,message:string}
 */
function keuangan_save_pemasukan(PDO $pdo, array $post, int $userId): array
{
    ensure_keuangan_transaksi_tables($pdo);

    $tanggal = trim((string) ($post['tanggal_pemasukan'] ?? date('Y-m-d')));
    $sumber = trim((string) ($post['sumber_pemasukan'] ?? ''));
    $dariPihak = trim((string) ($post['dari_pihak'] ?? ''));
    $metodeBayar = strtoupper(trim((string) ($post['metode_bayar'] ?? 'KAS')));
    $akunId = (int) ($post['akun_id'] ?? 0);
    $noBukti = trim((string) ($post['no_bukti'] ?? ''));
    $nominal = keuangan_money_input_to_int((string) ($post['nominal_pemasukan'] ?? '0'));
    $keterangan = trim((string) ($post['keterangan_pemasukan'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        $tanggal = date('Y-m-d');
    }
    if (!in_array($metodeBayar, ['KAS', 'TRANSFER'], true)) {
        $metodeBayar = 'KAS';
    }
    if ($sumber === '' || $nominal <= 0) {
        return ['ok' => false, 'message' => 'Form pemasukan belum lengkap (sumber, nominal).'];
    }
    if ($akunId <= 0) {
        return ['ok' => false, 'message' => 'Pilih akun kas/bank penerimaan.'];
    }

    $pdo->prepare('
        INSERT INTO keuangan_pemasukan (tanggal, sumber, dari_pihak, metode_bayar, akun_id, no_bukti, nominal, keterangan, created_by)
        VALUES (:tanggal, :sumber, :dari_pihak, :metode_bayar, :akun_id, :no_bukti, :nominal, :keterangan, :created_by)
    ')->execute([
        'tanggal' => $tanggal,
        'sumber' => $sumber,
        'dari_pihak' => $dariPihak !== '' ? $dariPihak : null,
        'metode_bayar' => $metodeBayar,
        'akun_id' => $akunId,
        'no_bukti' => $noBukti !== '' ? $noBukti : null,
        'nominal' => $nominal,
        'keterangan' => $keterangan !== '' ? $keterangan : null,
        'created_by' => $userId > 0 ? $userId : null,
    ]);
    $pemasukanId = (int) $pdo->lastInsertId();

    keuangan_transaksi_bootstrap_jurnal();
    keuangan_jurnal_pemasukan($pdo, $pemasukanId, $tanggal, $akunId, $nominal, $sumber, $userId);

    if (!function_exists('keuangan_dashboard_cache_invalidate')) {
        require_once __DIR__ . '/keuangan_dashboard.php';
    }
    keuangan_dashboard_cache_invalidate();

    return ['ok' => true, 'message' => 'Pemasukan berhasil dicatat (' . keuangan_format_rupiah($nominal) . ').'];
}

/**
 * Daftar POS pembayaran untuk filter (cache sesi 10 menit — jarang berubah).
 *
 * @return list<array{pos_slug:string,pos_nama:string}>
 */
function keuangan_pembayaran_pos_options(PDO $pdo): array
{
    static $requestCache = null;
    if (is_array($requestCache)) {
        return $requestCache;
    }

    $sessionKey = 'keuangan_pos_options_v1';
    $cached = $_SESSION[$sessionKey] ?? null;
    if (
        is_array($cached)
        && (int) ($cached['expires'] ?? 0) > time()
        && is_array($cached['data'] ?? null)
    ) {
        $requestCache = $cached['data'];

        return $requestCache;
    }

    $requestCache = [];
    if (table_exists($pdo, 'keuangan_pembayaran_detail')) {
        $requestCache = $pdo->query(
            'SELECT DISTINCT pos_slug, pos_nama FROM keuangan_pembayaran_detail ORDER BY pos_nama ASC, pos_slug ASC'
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    $_SESSION[$sessionKey] = [
        'expires' => time() + 600,
        'data' => $requestCache,
    ];

    return $requestCache;
}

/** @return list<array<string, mixed>> */
function keuangan_recent_pemasukan(PDO $pdo, int $limit = 15): array
{
    if (!table_exists($pdo, 'keuangan_pemasukan')) {
        return [];
    }
    $limit = max(5, min(50, $limit));
    $join = table_exists($pdo, 'keuangan_akun') ? 'LEFT JOIN keuangan_akun a ON a.id = p.akun_id' : '';
    $akunCol = table_exists($pdo, 'keuangan_akun') ? ', a.nama_akun AS akun_nama' : '';

    return $pdo->query("
        SELECT p.id, p.tanggal, p.sumber, p.dari_pihak, p.metode_bayar, p.nominal, p.keterangan, p.no_bukti{$akunCol}
        FROM keuangan_pemasukan p
        {$join}
        ORDER BY p.id DESC
        LIMIT {$limit}
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array<string, mixed>> */
function keuangan_recent_pembayaran(PDO $pdo, int $limit = 15): array
{
    if (!table_exists($pdo, 'keuangan_pembayaran') || !table_exists($pdo, 'santri')) {
        return [];
    }
    ensure_santri_identity_columns($pdo);
    $limit = max(5, min(50, $limit));
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 's.nama_santri' : 's.nama';

    return $pdo->query("
        SELECT p.id, p.tanggal_bayar, p.jenis_periode, p.bulan_tagihan, p.total_nominal, p.metode_bayar,
               s.nis, {$nameCol} AS nama_santri
        FROM keuangan_pembayaran p
        INNER JOIN santri s ON s.id = p.santri_id
        ORDER BY p.id DESC
        LIMIT {$limit}
    ")->fetchAll(PDO::FETCH_ASSOC);
}

/** @return list<array<string, mixed>> */
function keuangan_recent_pengeluaran(PDO $pdo, int $limit = 15): array
{
    if (!table_exists($pdo, 'keuangan_pengeluaran')) {
        return [];
    }
    $limit = max(5, min(50, $limit));
    $join = table_exists($pdo, 'keuangan_akun') ? 'LEFT JOIN keuangan_akun a ON a.id = p.akun_id' : '';
    $akunCol = table_exists($pdo, 'keuangan_akun') ? ', a.nama_akun AS akun_nama' : '';

    return $pdo->query("
        SELECT p.id, p.tanggal, p.penanggung_jawab, p.pos, p.alokasi_nama, p.metode_keluar, p.nominal, p.keterangan, p.no_bukti{$akunCol}
        FROM keuangan_pengeluaran p
        {$join}
        ORDER BY p.id DESC
        LIMIT {$limit}
    ")->fetchAll(PDO::FETCH_ASSOC);
}
