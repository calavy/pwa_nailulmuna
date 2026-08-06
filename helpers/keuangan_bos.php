<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/pkpps.php';

const BOS_JENJANG_WUSTHO = 'wustho';
const BOS_JENJANG_ULYA = 'ulya';
const BOS_JENJANG_UMUM = 'umum';

const BOS_SUMBER_BOS_WUSTHO = 'bos_wustho';
const BOS_SUMBER_BOS_ULYA = 'bos_ulya';
const BOS_SUMBER_SPP = 'spp';
const BOS_SUMBER_INFAQ = 'infaq';

/** @return array<int, string> */
function bos_bulan_masehi_map(): array
{
    return [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];
}

function bos_bulan_masehi_nama(int $bulan): string
{
    $bulan = max(1, min(12, $bulan));

    return bos_bulan_masehi_map()[$bulan] ?? ('Bulan ' . $bulan);
}

function bos_bulan_label_masehi(int $bulan, int $tahun): string
{
    return bos_bulan_masehi_nama($bulan) . ' ' . $tahun;
}

/** @return array{bulan:int,tahun:int,label:string} */
function bos_periode_masehi_berjalan(?string $dateYmd = null): array
{
    $dateYmd = trim((string) ($dateYmd ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd)) {
        $dateYmd = date('Y-m-d');
    }
    $ts = strtotime($dateYmd) ?: time();
    $bulan = max(1, min(12, (int) date('n', $ts)));
    $tahun = (int) date('Y', $ts);

    return [
        'bulan' => $bulan,
        'tahun' => $tahun,
        'label' => bos_bulan_label_masehi($bulan, $tahun),
    ];
}

/**
 * @param array<string, mixed>|null $input
 * @return array{bulan:int,tahun:int,label:string}
 */
function bos_resolve_periode_masehi(?array $input = null): array
{
    $def = bos_periode_masehi_berjalan();
    $input = $input ?? [];
    if (array_key_exists('bulan', $input) && (int) $input['bulan'] === 0) {
        $bulan = 0;
    } else {
        $bulan = max(1, min(12, (int) ($input['bulan'] ?? $def['bulan'])));
    }
    $tahun = (int) ($input['tahun'] ?? $def['tahun']);
    if ($tahun < 2000 || $tahun > 2105) {
        $tahun = $def['tahun'];
    }

    $label = $bulan >= 1
        ? bos_bulan_label_masehi($bulan, $tahun)
        : ('Tahun Masehi ' . $tahun);

    return [
        'bulan' => $bulan,
        'tahun' => $tahun,
        'label' => $label,
    ];
}

function bos_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bos_chart_of_accounts (
            kode_akun VARCHAR(30) PRIMARY KEY,
            nama_akun VARCHAR(150) NOT NULL,
            kelompok_laporan VARCHAR(40) NOT NULL DEFAULT 'ASET',
            sifat_akun ENUM('DEBIT','KREDIT') NOT NULL DEFAULT 'DEBIT',
            tag_jenjang VARCHAR(20) NULL,
            tag_sumber_dana VARCHAR(20) NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bos_akun (
            id INT AUTO_INCREMENT PRIMARY KEY,
            jenis_akun ENUM('KAS','BANK') NOT NULL DEFAULT 'BANK',
            nama_akun VARCHAR(120) NOT NULL,
            kode_coa VARCHAR(30) NOT NULL,
            nama_bank VARCHAR(120) NULL,
            no_rekening VARCHAR(80) NULL,
            atas_nama VARCHAR(120) NULL,
            opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0,
            is_default TINYINT(1) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_bos_akun_coa (kode_coa)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bos_transaksi (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tanggal DATE NOT NULL,
            jenis ENUM('PENERIMAAN_BULK','PENGELUARAN','PENERIMAAN_MANUAL') NOT NULL,
            jenjang ENUM('wustho','ulya','umum') NOT NULL DEFAULT 'umum',
            sumber_dana ENUM('bos_wustho','bos_ulya','spp','infaq') NOT NULL,
            bulan_tagihan TINYINT UNSIGNED NULL,
            tahun_ajaran_mulai SMALLINT UNSIGNED NULL,
            tahun_ajaran_selesai SMALLINT UNSIGNED NULL,
            nominal DECIMAL(14,2) NOT NULL DEFAULT 0,
            jumlah_santri INT UNSIGNED NOT NULL DEFAULT 0,
            nominal_per_santri DECIMAL(14,2) NOT NULL DEFAULT 0,
            kode_akun_beban VARCHAR(30) NULL,
            bos_akun_id INT NULL,
            keterangan TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_bos_bulk (jenjang, sumber_dana, bulan_tagihan, tahun_ajaran_mulai, tahun_ajaran_selesai, jenis),
            KEY idx_bos_trx_tanggal (tanggal),
            KEY idx_bos_trx_jenis (jenis)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bos_jurnal (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tanggal DATE NOT NULL,
            kode_akun VARCHAR(30) NOT NULL,
            nama_akun VARCHAR(150) NOT NULL,
            debit DECIMAL(14,2) NOT NULL DEFAULT 0,
            kredit DECIMAL(14,2) NOT NULL DEFAULT 0,
            keterangan TEXT NULL,
            jenjang ENUM('wustho','ulya','umum') NOT NULL DEFAULT 'umum',
            sumber_dana ENUM('bos_wustho','bos_ulya','spp','infaq') NOT NULL,
            ref_type VARCHAR(40) NULL,
            ref_id INT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_bos_jurnal_tanggal (tanggal),
            KEY idx_bos_jurnal_akun (kode_akun),
            KEY idx_bos_jurnal_ref (ref_type, ref_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    bos_migrate_masehi_columns($pdo);
    bos_migrate_pos_saldo_schema($pdo);

    bos_seed_chart_of_accounts($pdo);
    bos_seed_default_akun($pdo);
    bos_seed_default_pos($pdo);
    bos_init_default_settings($pdo);
}

function bos_migrate_pos_saldo_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bos_pos_pengeluaran (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nama_pos VARCHAR(120) NOT NULL,
            tag_jenjang ENUM('wustho','ulya','umum') NOT NULL DEFAULT 'umum',
            kode_coa VARCHAR(30) NOT NULL DEFAULT '5199',
            urutan INT NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_bos_pos_aktif (is_active, urutan)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS bos_saldo_awal (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tahun_masehi SMALLINT UNSIGNED NOT NULL,
            bos_akun_id INT UNSIGNED NOT NULL,
            nominal DECIMAL(14,2) NOT NULL DEFAULT 0,
            keterangan TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_bos_saldo_tahun_akun (tahun_masehi, bos_akun_id),
            KEY idx_bos_saldo_tahun (tahun_masehi)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    if (table_exists($pdo, 'bos_transaksi')) {
        try {
            $pdo->exec('ALTER TABLE bos_transaksi ADD COLUMN IF NOT EXISTS pos_pengeluaran_id INT UNSIGNED NULL AFTER kode_akun_beban');
        } catch (Throwable $e) {
        }
    }
}

function bos_migrate_masehi_columns(PDO $pdo): void
{
    if (!table_exists($pdo, 'bos_transaksi')) {
        return;
    }

    try {
        $pdo->exec('ALTER TABLE bos_transaksi ADD COLUMN IF NOT EXISTS tahun_masehi SMALLINT UNSIGNED NULL AFTER bulan_tagihan');
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec('
            UPDATE bos_transaksi
            SET tahun_masehi = YEAR(tanggal),
                bulan_tagihan = MONTH(tanggal)
            WHERE tahun_masehi IS NULL AND tanggal IS NOT NULL
        ');
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec('ALTER TABLE bos_transaksi DROP INDEX uq_bos_bulk');
    } catch (Throwable $e) {
    }

    try {
        $pdo->exec('
            ALTER TABLE bos_transaksi
            ADD UNIQUE KEY uq_bos_bulk_masehi (jenjang, sumber_dana, bulan_tagihan, tahun_masehi, jenis)
        ');
    } catch (Throwable $e) {
    }
}

function bos_init_default_settings(PDO $pdo): void
{
    if ((int) app_setting($pdo, 'bos_akun_id_wustho', '0') <= 0) {
        $id = bos_default_akun_id($pdo, BOS_JENJANG_WUSTHO);
        if ($id > 0) {
            save_setting($pdo, 'bos_akun_id_wustho', (string) $id);
        }
    }
    if ((int) app_setting($pdo, 'bos_akun_id_ulya', '0') <= 0) {
        $id = bos_default_akun_id($pdo, BOS_JENJANG_ULYA);
        if ($id > 0) {
            save_setting($pdo, 'bos_akun_id_ulya', (string) $id);
        }
    }
}

function bos_seed_chart_of_accounts(PDO $pdo): void
{
    if (!table_exists($pdo, 'bos_chart_of_accounts')) {
        return;
    }

    $accounts = [
        ['1100', 'Kas Tunai Operasional', 'ASET', 'DEBIT', null, null],
        ['1200', 'Bank BOS Wustho', 'ASET', 'DEBIT', null, BOS_SUMBER_BOS_WUSTHO],
        ['1210', 'Bank BOS Ulya', 'ASET', 'DEBIT', null, BOS_SUMBER_BOS_ULYA],
        ['1220', 'Bank Operasional Syahriyah/SPP', 'ASET', 'DEBIT', null, BOS_SUMBER_SPP],
        ['1300', 'Piutang Syahriyah Santri', 'ASET', 'DEBIT', null, null],
        ['1400', 'Aset Tetap / Peralatan Pembelajaran', 'ASET', 'DEBIT', null, null],
        ['2100', 'Utang Operasional / Pihak Ketiga', 'LIABILITAS', 'KREDIT', null, null],
        ['2200', 'Utang Insentif / Gaji Ustadz', 'LIABILITAS', 'KREDIT', null, null],
        ['3100', 'Saldo Awal Dana Terikat (BOS)', 'EKUITAS', 'KREDIT', null, null],
        ['3200', 'Saldo Awal Dana Tidak Terikat (Mandiri)', 'EKUITAS', 'KREDIT', null, null],
        ['4110', 'Pendapatan BOS Wustho', 'PENDAPATAN', 'KREDIT', BOS_JENJANG_WUSTHO, BOS_SUMBER_BOS_WUSTHO],
        ['4120', 'Pendapatan BOS Ulya', 'PENDAPATAN', 'KREDIT', BOS_JENJANG_ULYA, BOS_SUMBER_BOS_ULYA],
        ['4200', 'Pendapatan Syahriyah / SPP Santri', 'PENDAPATAN', 'KREDIT', null, BOS_SUMBER_SPP],
        ['4300', 'Pendapatan Infaq, Donasi & Usaha Pesantren', 'PENDAPATAN', 'KREDIT', null, BOS_SUMBER_INFAQ],
        ['5110', 'Beban Pembelajaran & Modul Kesetaraan Wustho', 'BEBAN', 'DEBIT', BOS_JENJANG_WUSTHO, null],
        ['5120', 'Beban Pembelajaran & Modul Kesetaraan Ulya', 'BEBAN', 'DEBIT', BOS_JENJANG_ULYA, null],
        ['5210', 'Beban Honorarium Ustadz Wustho', 'BEBAN', 'DEBIT', BOS_JENJANG_WUSTHO, null],
        ['5220', 'Beban Honorarium Ustadz Ulya', 'BEBAN', 'DEBIT', BOS_JENJANG_ULYA, null],
        ['5300', 'Beban Pemeliharaan Sarana & Prasarana', 'BEBAN', 'DEBIT', BOS_JENJANG_UMUM, null],
        ['5400', 'Beban Daya & Jasa (Listrik, Air, Internet)', 'BEBAN', 'DEBIT', BOS_JENJANG_UMUM, null],
        ['5510', 'Beban Ujian & Asesmen Wustho', 'BEBAN', 'DEBIT', BOS_JENJANG_WUSTHO, null],
        ['5520', 'Beban Ujian & Asesmen Ulya', 'BEBAN', 'DEBIT', BOS_JENJANG_ULYA, null],
        ['5199', 'Beban Operasional Lain-lain', 'BEBAN', 'DEBIT', BOS_JENJANG_UMUM, null],
    ];

    $ins = $pdo->prepare('
        INSERT IGNORE INTO bos_chart_of_accounts
            (kode_akun, nama_akun, kelompok_laporan, sifat_akun, tag_jenjang, tag_sumber_dana, is_active)
        VALUES (:kode, :nama, :kelompok, :sifat, :tag_j, :tag_s, 1)
    ');
    foreach ($accounts as [$kode, $nama, $kelompok, $sifat, $tagJ, $tagS]) {
        $ins->execute([
            'kode' => $kode,
            'nama' => $nama,
            'kelompok' => $kelompok,
            'sifat' => $sifat,
            'tag_j' => $tagJ,
            'tag_s' => $tagS,
        ]);
    }
}

function bos_seed_default_akun(PDO $pdo): void
{
    if (!table_exists($pdo, 'bos_akun')) {
        return;
    }
    $count = (int) $pdo->query('SELECT COUNT(*) FROM bos_akun')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $defaults = [
        ['BANK', 'Bank BOS Wustho', '1200', 1],
        ['BANK', 'Bank BOS Ulya', '1210', 0],
        ['BANK', 'Bank Operasional SPP', '1220', 0],
        ['KAS', 'Kas Tunai Operasional BOS', '1100', 0],
    ];
    $ins = $pdo->prepare('
        INSERT INTO bos_akun (jenis_akun, nama_akun, kode_coa, is_default, is_active)
        VALUES (:jenis, :nama, :coa, :def, 1)
    ');
    foreach ($defaults as [$jenis, $nama, $coa, $def]) {
        $ins->execute(['jenis' => $jenis, 'nama' => $nama, 'coa' => $coa, 'def' => $def]);
    }
}

function bos_seed_default_pos(PDO $pdo): void
{
    if (!table_exists($pdo, 'bos_pos_pengeluaran')) {
        return;
    }
    $count = (int) $pdo->query('SELECT COUNT(*) FROM bos_pos_pengeluaran')->fetchColumn();
    if ($count > 0) {
        return;
    }
    $defaults = [
        ['ATK & Alat Tulis', BOS_JENJANG_UMUM, 1],
        ['Transport & Perjalanan Dinas', BOS_JENJANG_UMUM, 2],
        ['Konsumsi Kegiatan', BOS_JENJANG_UMUM, 3],
    ];
    $ins = $pdo->prepare('
        INSERT INTO bos_pos_pengeluaran (nama_pos, tag_jenjang, kode_coa, urutan, is_active)
        VALUES (:nama, :j, \'5199\', :u, 1)
    ');
    foreach ($defaults as [$nama, $j, $u]) {
        $ins->execute(['nama' => $nama, 'j' => $j, 'u' => $u]);
    }
}

/** @return list<string> */
function bos_jenjang_options(): array
{
    return [BOS_JENJANG_WUSTHO, BOS_JENJANG_ULYA, BOS_JENJANG_UMUM];
}

/** @return list<string> */
function bos_sumber_dana_options(): array
{
    return [BOS_SUMBER_BOS_WUSTHO, BOS_SUMBER_BOS_ULYA, BOS_SUMBER_SPP, BOS_SUMBER_INFAQ];
}

function bos_label_jenjang(string $jenjang): string
{
    return match ($jenjang) {
        BOS_JENJANG_WUSTHO => 'Wustho',
        BOS_JENJANG_ULYA => 'Ulya',
        default => 'Umum',
    };
}

function bos_label_sumber_dana(string $sumber): string
{
    return match ($sumber) {
        BOS_SUMBER_BOS_WUSTHO => 'BOS Wustho',
        BOS_SUMBER_BOS_ULYA => 'BOS Ulya',
        BOS_SUMBER_SPP => 'SPP / Syahriyah',
        BOS_SUMBER_INFAQ => 'Infaq / Donasi',
        default => $sumber,
    };
}

function bos_nominal_per_santri(PDO $pdo, string $jenjang): int
{
    $key = $jenjang === BOS_JENJANG_ULYA ? 'bos_nominal_per_santri_ulya' : 'bos_nominal_per_santri_wustho';

    return max(0, (int) app_setting($pdo, $key, '0'));
}

function bos_default_akun_id(PDO $pdo, string $jenjang): int
{
    $key = $jenjang === BOS_JENJANG_ULYA ? 'bos_akun_id_ulya' : 'bos_akun_id_wustho';
    $id = max(0, (int) app_setting($pdo, $key, '0'));
    if ($id > 0) {
        return $id;
    }

    $coa = $jenjang === BOS_JENJANG_ULYA ? '1210' : '1200';
    $st = $pdo->prepare('SELECT id FROM bos_akun WHERE kode_coa = :c AND is_active = 1 ORDER BY is_default DESC, id ASC LIMIT 1');
    $st->execute(['c' => $coa]);

    return (int) ($st->fetchColumn() ?: 0);
}

function bos_coa_nama(PDO $pdo, string $kodeAkun): string
{
    if (!table_exists($pdo, 'bos_chart_of_accounts')) {
        return $kodeAkun;
    }
    $st = $pdo->prepare('SELECT nama_akun FROM bos_chart_of_accounts WHERE kode_akun = :k LIMIT 1');
    $st->execute(['k' => $kodeAkun]);

    return (string) ($st->fetchColumn() ?: $kodeAkun);
}

/** @return list<array<string, mixed>> */
function bos_fetch_akun_aktif(PDO $pdo): array
{
    if (!table_exists($pdo, 'bos_akun')) {
        return [];
    }

    return $pdo->query('
        SELECT id, jenis_akun, nama_akun, kode_coa, nama_bank, no_rekening, is_default
        FROM bos_akun
        WHERE is_active = 1
        ORDER BY is_default DESC, jenis_akun ASC, id ASC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<array<string, mixed>> */
function bos_fetch_coa_beban(PDO $pdo): array
{
    if (!table_exists($pdo, 'bos_chart_of_accounts')) {
        return [];
    }
    $st = $pdo->query("
        SELECT kode_akun, nama_akun, tag_jenjang
        FROM bos_chart_of_accounts
        WHERE kelompok_laporan = 'BEBAN' AND is_active = 1 AND kode_akun <> '5199'
        ORDER BY kode_akun ASC
    ");

    return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

/** @return list<array<string, mixed>> */
function bos_fetch_coa_pendapatan(PDO $pdo): array
{
    if (!table_exists($pdo, 'bos_chart_of_accounts')) {
        return [];
    }
    $st = $pdo->query("
        SELECT kode_akun, nama_akun, tag_jenjang, tag_sumber_dana
        FROM bos_chart_of_accounts
        WHERE kelompok_laporan = 'PENDAPATAN' AND is_active = 1
        ORDER BY kode_akun ASC
    ");

    return $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
}

/**
 * Rekap santri aktif PKPPS per tingkatan (read-only dari data existing).
 *
 * @return array{
 *   rows: list<array<string,mixed>>,
 *   subtotals: array<string,array{jumlah_santri:int,nominal_per_santri:int,total:int}>,
 *   grand_total: int,
 *   grand_count: int
 * }
 */
function bos_rekap_santri_per_tingkatan(PDO $pdo): array
{
    $empty = [
        'rows' => [],
        'subtotals' => [
            BOS_JENJANG_WUSTHO => ['jumlah_santri' => 0, 'nominal_per_santri' => 0, 'total' => 0],
            BOS_JENJANG_ULYA => ['jumlah_santri' => 0, 'nominal_per_santri' => 0, 'total' => 0],
        ],
        'grand_total' => 0,
        'grand_count' => 0,
    ];

    if (!table_exists($pdo, 'pkpps_santri')) {
        return $empty;
    }

    pkpps_ensure_schema($pdo);
    ensure_kelas_keuangan_table($pdo);

    $st = $pdo->query('
        SELECT t.id AS tingkatan_id, t.nama_tingkatan, t.sub_level, t.urutan,
               kk.tarif_keuangan_tier,
               COUNT(ps.santri_id) AS jumlah_santri
        FROM pkpps_santri ps
        INNER JOIN pkpps_tingkatan t ON t.id = ps.pkpps_tingkatan_id
        LEFT JOIN kelas_keuangan kk ON kk.id = t.kelas_keuangan_id
        WHERE ps.is_aktif = 1
        GROUP BY t.id
        ORDER BY t.urutan ASC, t.sub_level ASC
    ');
    $rawRows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $nomWustho = bos_nominal_per_santri($pdo, BOS_JENJANG_WUSTHO);
    $nomUlya = bos_nominal_per_santri($pdo, BOS_JENJANG_ULYA);

    $rows = [];
    $subtotals = [
        BOS_JENJANG_WUSTHO => ['jumlah_santri' => 0, 'nominal_per_santri' => $nomWustho, 'total' => 0],
        BOS_JENJANG_ULYA => ['jumlah_santri' => 0, 'nominal_per_santri' => $nomUlya, 'total' => 0],
    ];

    foreach ($rawRows as $r) {
        $tier = strtolower(trim((string) ($r['tarif_keuangan_tier'] ?? '')));
        if ($tier !== BOS_JENJANG_WUSTHO && $tier !== BOS_JENJANG_ULYA) {
            $nama = strtolower((string) ($r['nama_tingkatan'] ?? ''));
            if (str_contains($nama, 'ulya')) {
                $tier = BOS_JENJANG_ULYA;
            } elseif (str_contains($nama, 'wustho')) {
                $tier = BOS_JENJANG_WUSTHO;
            } else {
                continue;
            }
        }
        $jumlah = (int) ($r['jumlah_santri'] ?? 0);
        $nominal = $tier === BOS_JENJANG_ULYA ? $nomUlya : $nomWustho;
        $total = $jumlah * $nominal;

        $rows[] = [
            'tingkatan_id' => (int) ($r['tingkatan_id'] ?? 0),
            'nama_tingkatan' => (string) ($r['nama_tingkatan'] ?? ''),
            'sub_level' => (int) ($r['sub_level'] ?? 0),
            'jenjang' => $tier,
            'jenjang_label' => bos_label_jenjang($tier),
            'jumlah_santri' => $jumlah,
            'nominal_per_santri' => $nominal,
            'total' => $total,
        ];

        $subtotals[$tier]['jumlah_santri'] += $jumlah;
        $subtotals[$tier]['total'] += $total;
    }

    return [
        'rows' => $rows,
        'subtotals' => $subtotals,
        'grand_total' => $subtotals[BOS_JENJANG_WUSTHO]['total'] + $subtotals[BOS_JENJANG_ULYA]['total'],
        'grand_count' => $subtotals[BOS_JENJANG_WUSTHO]['jumlah_santri'] + $subtotals[BOS_JENJANG_ULYA]['jumlah_santri'],
    ];
}

function bos_bulk_sudah_dicatat(
    PDO $pdo,
    string $jenjang,
    string $sumberDana,
    int $bulanMasehi,
    int $tahunMasehi
): bool {
    if (!table_exists($pdo, 'bos_transaksi')) {
        return false;
    }
    $st = $pdo->prepare('
        SELECT 1 FROM bos_transaksi
        WHERE jenis = \'PENERIMAAN_BULK\'
          AND jenjang = :j
          AND sumber_dana = :s
          AND bulan_tagihan = :b
          AND tahun_masehi = :t
        LIMIT 1
    ');
    $st->execute([
        'j' => $jenjang,
        's' => $sumberDana,
        'b' => $bulanMasehi,
        't' => $tahunMasehi,
    ]);

    return (bool) $st->fetchColumn();
}

/** @return array{ok:bool,message:string,transaksi_id?:int} */
function bos_validasi_jenjang_sumber(string $jenjang, string $sumberDana): array
{
    if ($jenjang === BOS_JENJANG_WUSTHO && $sumberDana === BOS_SUMBER_BOS_ULYA) {
        return ['ok' => false, 'message' => 'Beban/jenjang Wustho tidak boleh memakai sumber dana BOS Ulya.'];
    }
    if ($jenjang === BOS_JENJANG_ULYA && $sumberDana === BOS_SUMBER_BOS_WUSTHO) {
        return ['ok' => false, 'message' => 'Beban/jenjang Ulya tidak boleh memakai sumber dana BOS Wustho.'];
    }

    return ['ok' => true, 'message' => ''];
}

function bos_jurnal_post_lines(PDO $pdo, int $transaksiId, string $tanggal, array $lines, int $userId): void
{
    if ($transaksiId <= 0 || !table_exists($pdo, 'bos_jurnal')) {
        return;
    }
    $ins = $pdo->prepare('
        INSERT INTO bos_jurnal
            (tanggal, kode_akun, nama_akun, debit, kredit, keterangan, jenjang, sumber_dana, ref_type, ref_id, created_by)
        VALUES
            (:tgl, :kode, :nama, :deb, :kre, :ket, :j, :s, :rt, :rid, :uid)
    ');
    foreach ($lines as $line) {
        $ins->execute([
            'tgl' => $tanggal,
            'kode' => (string) ($line['kode_akun'] ?? ''),
            'nama' => (string) ($line['nama_akun'] ?? bos_coa_nama($pdo, (string) ($line['kode_akun'] ?? ''))),
            'deb' => (float) ($line['debit'] ?? 0),
            'kre' => (float) ($line['kredit'] ?? 0),
            'ket' => (string) ($line['keterangan'] ?? ''),
            'j' => (string) ($line['jenjang'] ?? BOS_JENJANG_UMUM),
            's' => (string) ($line['sumber_dana'] ?? BOS_SUMBER_BOS_WUSTHO),
            'rt' => 'bos_transaksi',
            'rid' => $transaksiId,
            'uid' => $userId > 0 ? $userId : null,
        ]);
    }
}

function bos_akun_kode_coa(PDO $pdo, int $akunId): string
{
    if ($akunId <= 0) {
        return '1100';
    }
    $st = $pdo->prepare('SELECT kode_coa FROM bos_akun WHERE id = :id LIMIT 1');
    $st->execute(['id' => $akunId]);

    return (string) ($st->fetchColumn() ?: '1100');
}

/**
 * Catat penerimaan BOS bulk satu klik per jenjang.
 *
 * @return array{ok:bool,message:string}
 */
function bos_catat_penerimaan_bulk(
    PDO $pdo,
    string $jenjang,
    int $bulanMasehi,
    int $tahunMasehi,
    int $userId,
    ?string $tanggalOverride = null,
    int $taMulai = 0,
    int $taSelesai = 0
): array {
    bos_ensure_schema($pdo);

    if ($jenjang !== BOS_JENJANG_WUSTHO && $jenjang !== BOS_JENJANG_ULYA) {
        return ['ok' => false, 'message' => 'Jenjang tidak valid.'];
    }
    if ($bulanMasehi < 1 || $bulanMasehi > 12) {
        return ['ok' => false, 'message' => 'Bulan Masehi tidak valid.'];
    }
    if ($tahunMasehi < 2000 || $tahunMasehi > 2105) {
        return ['ok' => false, 'message' => 'Tahun Masehi tidak valid.'];
    }

    $sumberDana = $jenjang === BOS_JENJANG_ULYA ? BOS_SUMBER_BOS_ULYA : BOS_SUMBER_BOS_WUSTHO;
    $validasi = bos_validasi_jenjang_sumber($jenjang, $sumberDana);
    if (!$validasi['ok']) {
        return $validasi;
    }

    if (bos_bulk_sudah_dicatat($pdo, $jenjang, $sumberDana, $bulanMasehi, $tahunMasehi)) {
        return ['ok' => false, 'message' => 'Penerimaan BOS ' . bos_label_jenjang($jenjang) . ' untuk ' . bos_bulan_label_masehi($bulanMasehi, $tahunMasehi) . ' sudah dicatat.'];
    }

    $rekap = bos_rekap_santri_per_tingkatan($pdo);
    $sub = $rekap['subtotals'][$jenjang] ?? ['jumlah_santri' => 0, 'nominal_per_santri' => 0, 'total' => 0];
    $jumlahSantri = (int) ($sub['jumlah_santri'] ?? 0);
    $nominalPerSantri = (int) ($sub['nominal_per_santri'] ?? 0);
    $total = (int) ($sub['total'] ?? 0);

    if ($jumlahSantri <= 0) {
        return ['ok' => false, 'message' => 'Tidak ada santri PKPPS aktif untuk jenjang ' . bos_label_jenjang($jenjang) . '.'];
    }
    if ($nominalPerSantri <= 0) {
        return ['ok' => false, 'message' => 'Nominal BOS per santri belum diatur di Pengaturan BOS.'];
    }

    $akunId = bos_default_akun_id($pdo, $jenjang);
    if ($akunId <= 0) {
        return ['ok' => false, 'message' => 'Akun kas/bank BOS belum diatur di Pengaturan BOS.'];
    }

    $kodeBank = bos_akun_kode_coa($pdo, $akunId);
    $kodePendapatan = $jenjang === BOS_JENJANG_ULYA ? '4120' : '4110';
    $tanggal = $tanggalOverride ?: sprintf('%04d-%02d-01', $tahunMasehi, $bulanMasehi);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        $tanggal = date('Y-m-d');
    }

    $bulanLabel = bos_bulan_label_masehi($bulanMasehi, $tahunMasehi);
    $keterangan = sprintf(
        'Penerimaan BOS %s — %s — %d santri × Rp %s',
        bos_label_jenjang($jenjang),
        $bulanLabel,
        $jumlahSantri,
        number_format($nominalPerSantri, 0, ',', '.')
    );

    try {
        $pdo->beginTransaction();

        $insTrx = $pdo->prepare('
            INSERT INTO bos_transaksi
                (tanggal, jenis, jenjang, sumber_dana, bulan_tagihan, tahun_masehi, tahun_ajaran_mulai, tahun_ajaran_selesai,
                 nominal, jumlah_santri, nominal_per_santri, bos_akun_id, keterangan, created_by)
            VALUES
                (:tgl, \'PENERIMAAN_BULK\', :j, :s, :b, :th, :tm, :ts, :nom, :js, :nps, :aid, :ket, :uid)
        ');
        $insTrx->execute([
            'tgl' => $tanggal,
            'j' => $jenjang,
            's' => $sumberDana,
            'b' => $bulanMasehi,
            'th' => $tahunMasehi,
            'tm' => $taMulai > 0 ? $taMulai : null,
            'ts' => $taSelesai > 0 ? $taSelesai : null,
            'nom' => $total,
            'js' => $jumlahSantri,
            'nps' => $nominalPerSantri,
            'aid' => $akunId,
            'ket' => $keterangan,
            'uid' => $userId > 0 ? $userId : null,
        ]);
        $trxId = (int) $pdo->lastInsertId();

        bos_jurnal_post_lines($pdo, $trxId, $tanggal, [
            [
                'kode_akun' => $kodeBank,
                'debit' => $total,
                'kredit' => 0,
                'keterangan' => $keterangan,
                'jenjang' => $jenjang,
                'sumber_dana' => $sumberDana,
            ],
            [
                'kode_akun' => $kodePendapatan,
                'debit' => 0,
                'kredit' => $total,
                'keterangan' => $keterangan,
                'jenjang' => $jenjang,
                'sumber_dana' => $sumberDana,
            ],
        ], $userId);

        $pdo->commit();

        return ['ok' => true, 'message' => 'Penerimaan BOS ' . bos_label_jenjang($jenjang) . ' berhasil dicatat: ' . $keterangan . '.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (str_contains($e->getMessage(), 'uq_bos_bulk')) {
            return ['ok' => false, 'message' => 'Penerimaan untuk periode Masehi ini sudah pernah dicatat.'];
        }

        return ['ok' => false, 'message' => 'Gagal mencatat: ' . $e->getMessage()];
    }
}

/** @return array{ok:bool,message:string} */
function bos_save_pengaturan(PDO $pdo, array $post): array
{
    bos_ensure_schema($pdo);

    $nomWustho = max(0, (int) ($post['bos_nominal_wustho'] ?? 0));
    $nomUlya = max(0, (int) ($post['bos_nominal_ulya'] ?? 0));
    $akunWustho = max(0, (int) ($post['bos_akun_wustho'] ?? 0));
    $akunUlya = max(0, (int) ($post['bos_akun_ulya'] ?? 0));
    $akunSpp = max(0, (int) ($post['bos_akun_spp'] ?? 0));

    save_setting($pdo, 'bos_nominal_per_santri_wustho', (string) $nomWustho);
    save_setting($pdo, 'bos_nominal_per_santri_ulya', (string) $nomUlya);
    save_setting($pdo, 'bos_akun_id_wustho', (string) $akunWustho);
    save_setting($pdo, 'bos_akun_id_ulya', (string) $akunUlya);
    save_setting($pdo, 'bos_akun_id_spp', (string) $akunSpp);

    save_setting($pdo, 'bos_akun_id_spp', (string) $akunSpp);

    return ['ok' => true, 'message' => 'Pengaturan Keuangan BOS disimpan.'];
}

/** @return array{tgl_mulai:string,tgl_selesai:string} */
function bos_masehi_bulan_to_range(int $bulan, int $tahun): array
{
    $bulan = max(1, min(12, $bulan));
    $tahun = max(2000, min(2105, $tahun));
    $tglMulai = sprintf('%04d-%02d-01', $tahun, $bulan);

    return [
        'tgl_mulai' => $tglMulai,
        'tgl_selesai' => date('Y-m-t', strtotime($tglMulai) ?: time()),
    ];
}

/**
 * @param array<string, mixed>|null $input
 * @return array{
 *   bulan_mulai:int,tahun_mulai:int,bulan_selesai:int,tahun_selesai:int,
 *   tgl_mulai:string,tgl_selesai:string,label:string
 * }
 */
function bos_resolve_periode_range(?array $input = null): array
{
    $now = bos_periode_masehi_berjalan();
    $input = $input ?? [];

    $bulanMulai = max(1, min(12, (int) ($input['bulan_mulai'] ?? $input['bulan_dari'] ?? 1)));
    $tahunMulai = (int) ($input['tahun_mulai'] ?? $input['tahun_dari'] ?? $now['tahun']);
    $bulanSelesai = max(1, min(12, (int) ($input['bulan_selesai'] ?? $input['bulan_sampai'] ?? $now['bulan'])));
    $tahunSelesai = (int) ($input['tahun_selesai'] ?? $input['tahun_sampai'] ?? $now['tahun']);

    if ($tahunMulai < 2000 || $tahunMulai > 2105) {
        $tahunMulai = $now['tahun'];
    }
    if ($tahunSelesai < 2000 || $tahunSelesai > 2105) {
        $tahunSelesai = $now['tahun'];
    }

    $start = bos_masehi_bulan_to_range($bulanMulai, $tahunMulai);
    $end = bos_masehi_bulan_to_range($bulanSelesai, $tahunSelesai);
    if ($start['tgl_mulai'] > $end['tgl_selesai']) {
        [$bulanMulai, $bulanSelesai] = [$bulanSelesai, $bulanMulai];
        [$tahunMulai, $tahunSelesai] = [$tahunSelesai, $tahunMulai];
        $start = bos_masehi_bulan_to_range($bulanMulai, $tahunMulai);
        $end = bos_masehi_bulan_to_range($bulanSelesai, $tahunSelesai);
    }

    $label = bos_bulan_label_masehi($bulanMulai, $tahunMulai) . ' s/d ' . bos_bulan_label_masehi($bulanSelesai, $tahunSelesai);

    return [
        'bulan_mulai' => $bulanMulai,
        'tahun_mulai' => $tahunMulai,
        'bulan_selesai' => $bulanSelesai,
        'tahun_selesai' => $tahunSelesai,
        'tgl_mulai' => $start['tgl_mulai'],
        'tgl_selesai' => $end['tgl_selesai'],
        'label' => $label,
    ];
}

/** @return list<array<string,mixed>> */
function bos_fetch_pos_pengeluaran(PDO $pdo, bool $aktifOnly = true): array
{
    if (!table_exists($pdo, 'bos_pos_pengeluaran')) {
        return [];
    }
    $sql = 'SELECT * FROM bos_pos_pengeluaran';
    if ($aktifOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY urutan ASC, nama_pos ASC';

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function bos_pos_by_id(PDO $pdo, int $id): ?array
{
    if ($id <= 0 || !table_exists($pdo, 'bos_pos_pengeluaran')) {
        return null;
    }
    $st = $pdo->prepare('SELECT * FROM bos_pos_pengeluaran WHERE id = :id LIMIT 1');
    $st->execute(['id' => $id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/** @return array{ok:bool,message:string} */
function bos_save_pos_pengeluaran_form(PDO $pdo, array $post): array
{
    bos_ensure_schema($pdo);
    $action = trim((string) ($post['pos_action'] ?? 'save'));
    $id = max(0, (int) ($post['pos_id'] ?? 0));

    if ($action === 'delete' && $id > 0) {
        $st = $pdo->prepare('UPDATE bos_pos_pengeluaran SET is_active = 0 WHERE id = :id');
        $st->execute(['id' => $id]);

        return ['ok' => true, 'message' => 'Pos pengeluaran dinonaktifkan.'];
    }

    $nama = trim((string) ($post['nama_pos'] ?? ''));
    $jenjang = strtolower(trim((string) ($post['tag_jenjang'] ?? BOS_JENJANG_UMUM)));
    $urutan = max(0, (int) ($post['urutan'] ?? 0));
    if ($nama === '') {
        return ['ok' => false, 'message' => 'Nama pos wajib diisi.'];
    }
    if (!in_array($jenjang, bos_jenjang_options(), true)) {
        $jenjang = BOS_JENJANG_UMUM;
    }

    if ($id > 0) {
        $st = $pdo->prepare('
            UPDATE bos_pos_pengeluaran SET nama_pos = :n, tag_jenjang = :j, urutan = :u, is_active = 1 WHERE id = :id
        ');
        $st->execute(['n' => $nama, 'j' => $jenjang, 'u' => $urutan, 'id' => $id]);
    } else {
        $st = $pdo->prepare('
            INSERT INTO bos_pos_pengeluaran (nama_pos, tag_jenjang, kode_coa, urutan, is_active)
            VALUES (:n, :j, \'5199\', :u, 1)
        ');
        $st->execute(['n' => $nama, 'j' => $jenjang, 'u' => $urutan]);
    }

    return ['ok' => true, 'message' => 'Pos pengeluaran disimpan.'];
}

/** @return array<int,array{bos_akun_id:int,nama_akun:string,nominal:int,keterangan:string}> */
function bos_fetch_saldo_awal_tahun(PDO $pdo, int $tahunMasehi): array
{
    if (!table_exists($pdo, 'bos_saldo_awal')) {
        return [];
    }
    $st = $pdo->prepare('
        SELECT s.bos_akun_id, s.nominal, s.keterangan, a.nama_akun
        FROM bos_saldo_awal s
        INNER JOIN bos_akun a ON a.id = s.bos_akun_id
        WHERE s.tahun_masehi = :t
        ORDER BY a.is_default DESC, a.id ASC
    ');
    $st->execute(['t' => $tahunMasehi]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $aid = (int) ($r['bos_akun_id'] ?? 0);
        if ($aid <= 0) {
            continue;
        }
        $out[$aid] = [
            'bos_akun_id' => $aid,
            'nama_akun' => (string) ($r['nama_akun'] ?? ''),
            'nominal' => (int) round((float) ($r['nominal'] ?? 0)),
            'keterangan' => (string) ($r['keterangan'] ?? ''),
        ];
    }

    return $out;
}

/** @return array{ok:bool,message:string} */
function bos_save_saldo_awal(PDO $pdo, array $post): array
{
    bos_ensure_schema($pdo);
    $tahun = (int) ($post['saldo_tahun'] ?? 0);
    if ($tahun < 2000 || $tahun > 2105) {
        return ['ok' => false, 'message' => 'Tahun tidak valid.'];
    }

    $akunRows = bos_fetch_akun_aktif($pdo);
    if ($akunRows === []) {
        return ['ok' => false, 'message' => 'Belum ada akun BOS.'];
    }

    $ins = $pdo->prepare('
        INSERT INTO bos_saldo_awal (tahun_masehi, bos_akun_id, nominal, keterangan)
        VALUES (:t, :aid, :nom, :ket)
        ON DUPLICATE KEY UPDATE nominal = VALUES(nominal), keterangan = VALUES(keterangan)
    ');

    foreach ($akunRows as $ar) {
        $aid = (int) ($ar['id'] ?? 0);
        if ($aid <= 0) {
            continue;
        }
        $key = 'saldo_akun_' . $aid;
        $nom = max(0, (int) ($post[$key] ?? 0));
        $ket = trim((string) ($post['saldo_ket_' . $aid] ?? ''));
        $ins->execute([
            't' => $tahun,
            'aid' => $aid,
            'nom' => $nom,
            'ket' => $ket !== '' ? $ket : null,
        ]);
    }

    return ['ok' => true, 'message' => 'Saldo awal 1 Januari ' . $tahun . ' disimpan.'];
}

/** Net mutasi kas/bank per akun dari jurnal sebelum tanggal (exclusive). */
function bos_mutasi_akun_sebelum_tanggal(PDO $pdo, string $sebelumTanggal): array
{
    if (!table_exists($pdo, 'bos_jurnal') || !table_exists($pdo, 'bos_akun')) {
        return [];
    }
    $st = $pdo->prepare('
        SELECT a.id AS bos_akun_id, a.kode_coa,
               COALESCE(SUM(j.debit), 0) AS total_debit,
               COALESCE(SUM(j.kredit), 0) AS total_kredit
        FROM bos_akun a
        LEFT JOIN bos_jurnal j ON j.kode_akun = a.kode_coa AND j.tanggal < :tgl
        WHERE a.is_active = 1
        GROUP BY a.id, a.kode_coa
    ');
    $st->execute(['tgl' => $sebelumTanggal]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $aid = (int) ($r['bos_akun_id'] ?? 0);
        $deb = (float) ($r['total_debit'] ?? 0);
        $kre = (float) ($r['total_kredit'] ?? 0);
        $out[$aid] = (int) round($deb - $kre);
    }

    return $out;
}

function bos_saldo_awal_periode(PDO $pdo, string $tglMulai): int
{
    $ts = strtotime($tglMulai) ?: time();
    $tahun = (int) date('Y', $ts);
    $saldoRows = bos_fetch_saldo_awal_tahun($pdo, $tahun);
    $total = 0;
    foreach ($saldoRows as $row) {
        $total += (int) ($row['nominal'] ?? 0);
    }
    if ($tglMulai === sprintf('%04d-01-01', $tahun)) {
        return $total;
    }
    foreach (bos_mutasi_akun_sebelum_tanggal($pdo, $tglMulai) as $net) {
        $total += $net;
    }

    return $total;
}

/** @return array<string, mixed> */
function bos_dashboard_keuangan(PDO $pdo, array $periodeRange): array
{
    bos_ensure_schema($pdo);
    $tglMulai = (string) ($periodeRange['tgl_mulai'] ?? date('Y-m-01'));
    $tglSelesai = (string) ($periodeRange['tgl_selesai'] ?? date('Y-m-t'));
    $tahunMulai = (int) ($periodeRange['tahun_mulai'] ?? (int) date('Y'));

    $empty = [
        'saldo_awal' => 0,
        'total_masuk' => 0,
        'total_keluar' => 0,
        'saldo_akhir' => 0,
        'per_sumber_dana' => [],
        'per_kategori' => [],
        'per_akun' => [],
    ];

    if (!table_exists($pdo, 'bos_transaksi')) {
        return $empty;
    }

    $saldoAwal = bos_saldo_awal_periode($pdo, $tglMulai);

    $stMasuk = $pdo->prepare('
        SELECT COALESCE(SUM(nominal), 0) FROM bos_transaksi
        WHERE jenis IN (\'PENERIMAAN_BULK\', \'PENERIMAAN_MANUAL\')
          AND tanggal BETWEEN :d1 AND :d2
    ');
    $stMasuk->execute(['d1' => $tglMulai, 'd2' => $tglSelesai]);
    $totalMasuk = (int) round((float) ($stMasuk->fetchColumn() ?: 0));

    $stKeluar = $pdo->prepare('
        SELECT COALESCE(SUM(nominal), 0) FROM bos_transaksi
        WHERE jenis = \'PENGELUARAN\'
          AND tanggal BETWEEN :d1 AND :d2
    ');
    $stKeluar->execute(['d1' => $tglMulai, 'd2' => $tglSelesai]);
    $totalKeluar = (int) round((float) ($stKeluar->fetchColumn() ?: 0));

    $saldoAkhir = $saldoAwal + $totalMasuk - $totalKeluar;

    $perSumber = [];
    foreach (bos_sumber_dana_options() as $s) {
        $perSumber[$s] = ['masuk' => 0, 'keluar' => 0, 'saldo' => 0, 'label' => bos_label_sumber_dana($s)];
    }
    $stSd = $pdo->prepare('
        SELECT sumber_dana, jenis, COALESCE(SUM(nominal), 0) AS total
        FROM bos_transaksi
        WHERE tanggal BETWEEN :d1 AND :d2
        GROUP BY sumber_dana, jenis
    ');
    $stSd->execute(['d1' => $tglMulai, 'd2' => $tglSelesai]);
    foreach ($stSd->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $s = (string) ($r['sumber_dana'] ?? '');
        if (!isset($perSumber[$s])) {
            continue;
        }
        $nom = (int) round((float) ($r['total'] ?? 0));
        $jenis = (string) ($r['jenis'] ?? '');
        if (str_contains($jenis, 'PENERIMAAN')) {
            $perSumber[$s]['masuk'] += $nom;
        } elseif ($jenis === 'PENGELUARAN') {
            $perSumber[$s]['keluar'] += $nom;
        }
    }
    foreach ($perSumber as $s => &$row) {
        $row['saldo'] = $row['masuk'] - $row['keluar'];
    }
    unset($row);

    $perKategori = [];
    $stKat = $pdo->prepare('
        SELECT t.kode_akun_beban, t.pos_pengeluaran_id, COALESCE(SUM(t.nominal), 0) AS total,
               c.nama_akun, p.nama_pos
        FROM bos_transaksi t
        LEFT JOIN bos_chart_of_accounts c ON c.kode_akun = t.kode_akun_beban
        LEFT JOIN bos_pos_pengeluaran p ON p.id = t.pos_pengeluaran_id
        WHERE t.jenis = \'PENGELUARAN\' AND t.tanggal BETWEEN :d1 AND :d2
        GROUP BY t.kode_akun_beban, t.pos_pengeluaran_id, c.nama_akun, p.nama_pos
        ORDER BY total DESC
    ');
    $stKat->execute(['d1' => $tglMulai, 'd2' => $tglSelesai]);
    foreach ($stKat->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $posId = (int) ($r['pos_pengeluaran_id'] ?? 0);
        $nama = $posId > 0
            ? (string) ($r['nama_pos'] ?? 'Pos lain')
            : (string) (($r['kode_akun_beban'] ?? '') . ' — ' . ($r['nama_akun'] ?? ''));
        $perKategori[] = [
            'nama' => $nama,
            'jenis' => $posId > 0 ? 'Pos lain' : 'Standar COA',
            'total' => (int) round((float) ($r['total'] ?? 0)),
        ];
    }

    $saldoTahun = bos_fetch_saldo_awal_tahun($pdo, $tahunMulai);
    $mutasiSebelum = bos_mutasi_akun_sebelum_tanggal($pdo, $tglMulai);
    $perAkun = [];
    foreach (bos_fetch_akun_aktif($pdo) as $ar) {
        $aid = (int) ($ar['id'] ?? 0);
        $kodeCoa = (string) ($ar['kode_coa'] ?? '');
        $saldoAwalAkun = (int) ($saldoTahun[$aid]['nominal'] ?? 0) + (int) ($mutasiSebelum[$aid] ?? 0);

        $stIn = $pdo->prepare('
            SELECT COALESCE(SUM(debit), 0) - COALESCE(SUM(kredit), 0) AS net
            FROM bos_jurnal WHERE kode_akun = :k AND tanggal BETWEEN :d1 AND :d2
        ');
        $stIn->execute(['k' => $kodeCoa, 'd1' => $tglMulai, 'd2' => $tglSelesai]);
        $mutasiRange = (int) round((float) ($stIn->fetchColumn() ?: 0));

        $perAkun[] = [
            'bos_akun_id' => $aid,
            'nama_akun' => (string) ($ar['nama_akun'] ?? ''),
            'saldo_awal' => $saldoAwalAkun,
            'mutasi' => $mutasiRange,
            'saldo_akhir' => $saldoAwalAkun + $mutasiRange,
        ];
    }

    return [
        'saldo_awal' => $saldoAwal,
        'total_masuk' => $totalMasuk,
        'total_keluar' => $totalKeluar,
        'saldo_akhir' => $saldoAkhir,
        'per_sumber_dana' => array_values($perSumber),
        'per_kategori' => $perKategori,
        'per_akun' => $perAkun,
    ];
}

/** @return list<array<string,mixed>> */
function bos_laporan_bku_rows_range(PDO $pdo, string $tglMulai, string $tglSelesai): array
{
    if (!table_exists($pdo, 'bos_jurnal')) {
        return [];
    }

    $st = $pdo->prepare('
        SELECT j.tanggal, j.kode_akun, j.nama_akun, j.keterangan, j.jenjang, j.sumber_dana, j.debit, j.kredit
        FROM bos_jurnal j
        INNER JOIN bos_transaksi t ON t.id = j.ref_id AND j.ref_type = \'bos_transaksi\'
        WHERE j.tanggal BETWEEN :d1 AND :d2
        ORDER BY j.tanggal ASC, j.id ASC
    ');
    $st->execute(['d1' => $tglMulai, 'd2' => $tglSelesai]);

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $rows[] = [
            'tanggal' => (string) ($r['tanggal'] ?? ''),
            'kode_akun' => (string) ($r['kode_akun'] ?? ''),
            'uraian' => (string) ($r['keterangan'] ?? $r['nama_akun'] ?? ''),
            'jenjang' => bos_label_jenjang((string) ($r['jenjang'] ?? '')),
            'sumber_dana' => bos_label_sumber_dana((string) ($r['sumber_dana'] ?? '')),
            'debit' => (int) round((float) ($r['debit'] ?? 0)),
            'kredit' => (int) round((float) ($r['kredit'] ?? 0)),
        ];
    }

    return $rows;
}

/** @return array<string, mixed> */
function bos_laporan_lra_range(PDO $pdo, string $tglMulai, string $tglSelesai): array
{
    $emptySections = [
        'pendapatan' => [],
        'beban_wustho' => [],
        'beban_ulya' => [],
        'beban_umum' => [],
        'beban_lain' => [],
    ];

    if (!table_exists($pdo, 'bos_jurnal')) {
        return [
            'sections' => $emptySections,
            'saldo_awal_periode' => 0,
            'total_pendapatan' => 0,
            'subtotal_wustho' => 0,
            'subtotal_ulya' => 0,
            'subtotal_umum' => 0,
            'subtotal_lain' => 0,
            'total_pengeluaran' => 0,
            'surplus' => 0,
        ];
    }

    $saldoAwal = bos_saldo_awal_periode($pdo, $tglMulai);

    $st = $pdo->prepare('
        SELECT j.kode_akun, c.nama_akun, c.kelompok_laporan,
               SUM(j.debit) AS total_debit, SUM(j.kredit) AS total_kredit
        FROM bos_jurnal j
        INNER JOIN bos_chart_of_accounts c ON c.kode_akun = j.kode_akun
        INNER JOIN bos_transaksi t ON t.id = j.ref_id AND j.ref_type = \'bos_transaksi\'
        WHERE j.tanggal BETWEEN :d1 AND :d2
        GROUP BY j.kode_akun, c.nama_akun, c.kelompok_laporan
        ORDER BY j.kode_akun ASC
    ');
    $st->execute(['d1' => $tglMulai, 'd2' => $tglSelesai]);

    $pendapatanKeys = ['4110', '4120', '4200', '4300'];
    $bebanWusthoKeys = ['5110', '5210', '5510'];
    $bebanUlyaKeys = ['5120', '5220', '5520'];
    $bebanUmumKeys = ['5300', '5400'];
    $bebanLainKeys = ['5199'];

    $sections = $emptySections;
    $totals = ['pendapatan' => 0, 'wustho' => 0, 'ulya' => 0, 'umum' => 0, 'lain' => 0];

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $kode = (string) ($r['kode_akun'] ?? '');
        $nama = (string) ($r['nama_akun'] ?? '');
        $kelompok = (string) ($r['kelompok_laporan'] ?? '');
        $deb = (int) round((float) ($r['total_debit'] ?? 0));
        $kre = (int) round((float) ($r['total_kredit'] ?? 0));

        if ($kelompok === 'PENDAPATAN') {
            $nilai = $kre - $deb;
            $sections['pendapatan'][] = ['kode' => $kode, 'nama' => $nama, 'nilai' => $nilai];
            $totals['pendapatan'] += $nilai;
        } elseif ($kelompok === 'BEBAN') {
            $nilai = $deb - $kre;
            if (in_array($kode, $bebanWusthoKeys, true)) {
                $sections['beban_wustho'][] = ['kode' => $kode, 'nama' => $nama, 'nilai' => $nilai];
                $totals['wustho'] += $nilai;
            } elseif (in_array($kode, $bebanUlyaKeys, true)) {
                $sections['beban_ulya'][] = ['kode' => $kode, 'nama' => $nama, 'nilai' => $nilai];
                $totals['ulya'] += $nilai;
            } elseif (in_array($kode, $bebanLainKeys, true)) {
                $sections['beban_lain'][] = ['kode' => $kode, 'nama' => $nama, 'nilai' => $nilai];
                $totals['lain'] += $nilai;
            } elseif (in_array($kode, $bebanUmumKeys, true)) {
                $sections['beban_umum'][] = ['kode' => $kode, 'nama' => $nama, 'nilai' => $nilai];
                $totals['umum'] += $nilai;
            }
        }
    }

    $ensure = static function (array &$section, array $keys, PDO $pdo): void {
        $existing = array_column($section, 'kode');
        foreach ($keys as $k) {
            if (!in_array($k, $existing, true)) {
                $section[] = ['kode' => $k, 'nama' => bos_coa_nama($pdo, $k), 'nilai' => 0];
            }
        }
        usort($section, static fn(array $a, array $b): int => strcmp($a['kode'], $b['kode']));
    };
    $ensure($sections['pendapatan'], $pendapatanKeys, $pdo);
    $ensure($sections['beban_wustho'], $bebanWusthoKeys, $pdo);
    $ensure($sections['beban_ulya'], $bebanUlyaKeys, $pdo);
    $ensure($sections['beban_umum'], $bebanUmumKeys, $pdo);
    $ensure($sections['beban_lain'], $bebanLainKeys, $pdo);

    $totalPengeluaran = $totals['wustho'] + $totals['ulya'] + $totals['umum'] + $totals['lain'];

    return [
        'sections' => $sections,
        'saldo_awal_periode' => $saldoAwal,
        'total_pendapatan' => $totals['pendapatan'],
        'subtotal_wustho' => $totals['wustho'],
        'subtotal_ulya' => $totals['ulya'],
        'subtotal_umum' => $totals['umum'],
        'subtotal_lain' => $totals['lain'],
        'total_pengeluaran' => $totalPengeluaran,
        'surplus' => $saldoAwal + $totals['pendapatan'] - $totalPengeluaran,
    ];
}

/** @return array{ok:bool,message:string} */
function bos_save_pengeluaran(PDO $pdo, array $post, int $userId): array
{
    bos_ensure_schema($pdo);

    $tanggal = trim((string) ($post['tanggal'] ?? ''));
    $akunId = max(0, (int) ($post['bos_akun_id'] ?? 0));
    $jalur = strtolower(trim((string) ($post['jalur_pengeluaran'] ?? 'standar')));
    $kodeBeban = trim((string) ($post['kode_akun_beban'] ?? ''));
    $posId = max(0, (int) ($post['pos_pengeluaran_id'] ?? 0));
    $jenjang = strtolower(trim((string) ($post['jenjang'] ?? BOS_JENJANG_UMUM)));
    $sumberDana = strtolower(trim((string) ($post['sumber_dana'] ?? '')));
    $nominal = max(0, (int) ($post['nominal'] ?? 0));
    $keterangan = trim((string) ($post['keterangan'] ?? ''));

    if ($tanggal === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return ['ok' => false, 'message' => 'Tanggal tidak valid.'];
    }
    if ($akunId <= 0) {
        return ['ok' => false, 'message' => 'Pilih akun sumber dana.'];
    }
    if ($jalur === 'lain') {
        $pos = bos_pos_by_id($pdo, $posId);
        if ($pos === null || (int) ($pos['is_active'] ?? 0) !== 1) {
            return ['ok' => false, 'message' => 'Pilih pos pengeluaran lain yang valid.'];
        }
        $kodeBeban = (string) ($pos['kode_coa'] ?? '5199');
        if ($jenjang === BOS_JENJANG_UMUM && ($pos['tag_jenjang'] ?? '') !== BOS_JENJANG_UMUM) {
            $jenjang = (string) ($pos['tag_jenjang'] ?? BOS_JENJANG_UMUM);
        }
    } elseif ($kodeBeban === '') {
        return ['ok' => false, 'message' => 'Pilih akun beban.'];
    } else {
        $posId = 0;
    }
    if ($nominal <= 0) {
        return ['ok' => false, 'message' => 'Nominal harus lebih dari nol.'];
    }
    if (!in_array($jenjang, bos_jenjang_options(), true)) {
        return ['ok' => false, 'message' => 'Jenjang tidak valid.'];
    }
    if (!in_array($sumberDana, bos_sumber_dana_options(), true)) {
        return ['ok' => false, 'message' => 'Sumber dana tidak valid.'];
    }

    $validasi = bos_validasi_jenjang_sumber($jenjang, $sumberDana);
    if (!$validasi['ok']) {
        return $validasi;
    }

    $kodeBank = bos_akun_kode_coa($pdo, $akunId);
    if ($jalur === 'lain' && isset($pos)) {
        $ket = $keterangan !== '' ? $keterangan : 'Pengeluaran BOS — ' . (string) ($pos['nama_pos'] ?? 'Pos lain');
    } else {
        $ket = $keterangan !== '' ? $keterangan : 'Pengeluaran BOS — ' . bos_coa_nama($pdo, $kodeBeban);
    }

    try {
        $pdo->beginTransaction();

        $bulanMasehi = max(1, min(12, (int) date('n', strtotime($tanggal) ?: time())));
        $tahunMasehi = (int) date('Y', strtotime($tanggal) ?: time());

        $insTrx = $pdo->prepare('
            INSERT INTO bos_transaksi
                (tanggal, jenis, jenjang, sumber_dana, bulan_tagihan, tahun_masehi, nominal, kode_akun_beban, pos_pengeluaran_id, bos_akun_id, keterangan, created_by)
            VALUES
                (:tgl, \'PENGELUARAN\', :j, :s, :bm, :th, :nom, :kb, :pid, :aid, :ket, :uid)
        ');
        $insTrx->execute([
            'tgl' => $tanggal,
            'j' => $jenjang,
            's' => $sumberDana,
            'bm' => $bulanMasehi,
            'th' => $tahunMasehi,
            'nom' => $nominal,
            'kb' => $kodeBeban,
            'pid' => $posId > 0 ? $posId : null,
            'aid' => $akunId,
            'ket' => $ket,
            'uid' => $userId > 0 ? $userId : null,
        ]);
        $trxId = (int) $pdo->lastInsertId();

        bos_jurnal_post_lines($pdo, $trxId, $tanggal, [
            [
                'kode_akun' => $kodeBeban,
                'debit' => $nominal,
                'kredit' => 0,
                'keterangan' => $ket,
                'jenjang' => $jenjang,
                'sumber_dana' => $sumberDana,
            ],
            [
                'kode_akun' => $kodeBank,
                'debit' => 0,
                'kredit' => $nominal,
                'keterangan' => $ket,
                'jenjang' => $jenjang,
                'sumber_dana' => $sumberDana,
            ],
        ], $userId);

        $pdo->commit();

        return ['ok' => true, 'message' => 'Pengeluaran BOS berhasil dicatat.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal menyimpan: ' . $e->getMessage()];
    }
}

/**
 * @return list<array<string,mixed>>
 */
function bos_fetch_riwayat(PDO $pdo, int $bulan = 0, int $tahun = 0, string $jenjang = '', ?string $tglMulai = null, ?string $tglSelesai = null): array
{
    if (!table_exists($pdo, 'bos_transaksi')) {
        return [];
    }

    $where = ['1=1'];
    $params = [];
    if ($tglMulai !== null && $tglSelesai !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglMulai) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $tglSelesai)) {
        $where[] = 'tanggal BETWEEN :d1 AND :d2';
        $params['d1'] = $tglMulai;
        $params['d2'] = $tglSelesai;
    } else {
        if ($bulan >= 1 && $bulan <= 12) {
            $where[] = '(bulan_tagihan = :bulan OR (bulan_tagihan IS NULL AND MONTH(tanggal) = :bulan2))';
            $params['bulan'] = $bulan;
            $params['bulan2'] = $bulan;
        }
        if ($tahun >= 2000 && $tahun <= 2105) {
            $where[] = '(tahun_masehi = :tahun OR (tahun_masehi IS NULL AND YEAR(tanggal) = :tahun2))';
            $params['tahun'] = $tahun;
            $params['tahun2'] = $tahun;
        }
    }
    if ($jenjang !== '' && in_array($jenjang, bos_jenjang_options(), true)) {
        $where[] = 'jenjang = :jenjang';
        $params['jenjang'] = $jenjang;
    }

    $sql = '
        SELECT t.*, a.nama_akun AS akun_nama, p.nama_pos
        FROM bos_transaksi t
        LEFT JOIN bos_akun a ON a.id = t.bos_akun_id
        LEFT JOIN bos_pos_pengeluaran p ON p.id = t.pos_pengeluaran_id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY t.tanggal DESC, t.id DESC
        LIMIT 500
    ';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return list<array<string,mixed>>
 */
function bos_laporan_bku_rows(PDO $pdo, int $bulan, int $tahun): array
{
    if (!table_exists($pdo, 'bos_jurnal')) {
        return [];
    }

    $st = $pdo->prepare('
        SELECT j.tanggal, j.kode_akun, j.nama_akun, j.keterangan, j.jenjang, j.sumber_dana, j.debit, j.kredit
        FROM bos_jurnal j
        INNER JOIN bos_transaksi t ON t.id = j.ref_id AND j.ref_type = \'bos_transaksi\'
        WHERE (
            (t.bulan_tagihan = :bulan AND t.tahun_masehi = :tahun)
            OR (t.bulan_tagihan IS NULL AND MONTH(j.tanggal) = :bulan2 AND YEAR(j.tanggal) = :tahun2)
        )
        ORDER BY j.tanggal ASC, j.id ASC
    ');
    $st->execute([
        'bulan' => $bulan,
        'tahun' => $tahun,
        'bulan2' => $bulan,
        'tahun2' => $tahun,
    ]);

    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $rows[] = [
            'tanggal' => (string) ($r['tanggal'] ?? ''),
            'kode_akun' => (string) ($r['kode_akun'] ?? ''),
            'uraian' => (string) ($r['keterangan'] ?? $r['nama_akun'] ?? ''),
            'jenjang' => bos_label_jenjang((string) ($r['jenjang'] ?? '')),
            'sumber_dana' => bos_label_sumber_dana((string) ($r['sumber_dana'] ?? '')),
            'debit' => (int) round((float) ($r['debit'] ?? 0)),
            'kredit' => (int) round((float) ($r['kredit'] ?? 0)),
        ];
    }

    return $rows;
}

/**
 * @return array<string, mixed>
 */
function bos_laporan_lra(PDO $pdo, int $bulan, int $tahun): array
{
    $emptySections = [
        'pendapatan' => [],
        'beban_wustho' => [],
        'beban_ulya' => [],
        'beban_umum' => [],
    ];

    if (!table_exists($pdo, 'bos_jurnal')) {
        return [
            'sections' => $emptySections,
            'total_pendapatan' => 0,
            'subtotal_wustho' => 0,
            'subtotal_ulya' => 0,
            'subtotal_umum' => 0,
            'total_pengeluaran' => 0,
            'surplus' => 0,
        ];
    }

    $st = $pdo->prepare('
        SELECT j.kode_akun, c.nama_akun, c.kelompok_laporan, c.tag_jenjang,
               SUM(j.debit) AS total_debit, SUM(j.kredit) AS total_kredit
        FROM bos_jurnal j
        INNER JOIN bos_chart_of_accounts c ON c.kode_akun = j.kode_akun
        INNER JOIN bos_transaksi t ON t.id = j.ref_id AND j.ref_type = \'bos_transaksi\'
        WHERE (
            (t.bulan_tagihan = :bulan AND t.tahun_masehi = :tahun)
            OR (t.bulan_tagihan IS NULL AND MONTH(j.tanggal) = :bulan2 AND YEAR(j.tanggal) = :tahun2)
        )
        GROUP BY j.kode_akun, c.nama_akun, c.kelompok_laporan, c.tag_jenjang
        ORDER BY j.kode_akun ASC
    ');
    $st->execute([
        'bulan' => $bulan,
        'tahun' => $tahun,
        'bulan2' => $bulan,
        'tahun2' => $tahun,
    ]);

    $pendapatanKeys = ['4110', '4120', '4200', '4300'];
    $bebanWusthoKeys = ['5110', '5210', '5510'];
    $bebanUlyaKeys = ['5120', '5220', '5520'];
    $bebanUmumKeys = ['5300', '5400'];

    $sections = [
        'pendapatan' => [],
        'beban_wustho' => [],
        'beban_ulya' => [],
        'beban_umum' => [],
    ];

    $totals = [
        'pendapatan' => 0,
        'wustho' => 0,
        'ulya' => 0,
        'umum' => 0,
    ];

    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $kode = (string) ($r['kode_akun'] ?? '');
        $nama = (string) ($r['nama_akun'] ?? '');
        $kelompok = (string) ($r['kelompok_laporan'] ?? '');
        $deb = (int) round((float) ($r['total_debit'] ?? 0));
        $kre = (int) round((float) ($r['total_kredit'] ?? 0));

        if ($kelompok === 'PENDAPATAN') {
            $nilai = $kre - $deb;
            $sections['pendapatan'][] = ['kode' => $kode, 'nama' => $nama, 'nilai' => $nilai];
            $totals['pendapatan'] += $nilai;
        } elseif ($kelompok === 'BEBAN') {
            $nilai = $deb - $kre;
            if (in_array($kode, $bebanWusthoKeys, true)) {
                $sections['beban_wustho'][] = ['kode' => $kode, 'nama' => $nama, 'nilai' => $nilai];
                $totals['wustho'] += $nilai;
            } elseif (in_array($kode, $bebanUlyaKeys, true)) {
                $sections['beban_ulya'][] = ['kode' => $kode, 'nama' => $nama, 'nilai' => $nilai];
                $totals['ulya'] += $nilai;
            } elseif (in_array($kode, $bebanUmumKeys, true)) {
                $sections['beban_umum'][] = ['kode' => $kode, 'nama' => $nama, 'nilai' => $nilai];
                $totals['umum'] += $nilai;
            }
        }
    }

    // Pastikan semua akun SRS muncul meski nilai 0
    $ensure = static function (array &$section, array $keys, PDO $pdo): void {
        $existing = array_column($section, 'kode');
        foreach ($keys as $k) {
            if (!in_array($k, $existing, true)) {
                $section[] = ['kode' => $k, 'nama' => bos_coa_nama($pdo, $k), 'nilai' => 0];
            }
        }
        usort($section, static fn(array $a, array $b): int => strcmp($a['kode'], $b['kode']));
    };
    $ensure($sections['pendapatan'], $pendapatanKeys, $pdo);
    $ensure($sections['beban_wustho'], $bebanWusthoKeys, $pdo);
    $ensure($sections['beban_ulya'], $bebanUlyaKeys, $pdo);
    $ensure($sections['beban_umum'], $bebanUmumKeys, $pdo);

    $totalPengeluaran = $totals['wustho'] + $totals['ulya'] + $totals['umum'];

    return [
        'sections' => $sections,
        'total_pendapatan' => $totals['pendapatan'],
        'subtotal_wustho' => $totals['wustho'],
        'subtotal_ulya' => $totals['ulya'],
        'subtotal_umum' => $totals['umum'],
        'total_pengeluaran' => $totalPengeluaran,
        'surplus' => $totals['pendapatan'] - $totalPengeluaran,
    ];
}

/** @deprecated Gunakan bos_bulan_label_masehi() — modul BOS selalu Masehi. */
function bos_bulan_label(PDO $pdo, int $bulan, int $taMulai, int $taSelesai): string
{
    unset($pdo, $taMulai, $taSelesai);

    return bos_bulan_masehi_nama($bulan);
}
