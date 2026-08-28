<?php

declare(strict_types=1);

require_once __DIR__ . '/hijri_kalender.php';

function akademik_add_column(PDO $pdo, string $table, string $col, string $ddl): void
{
    if (!table_exists($pdo, $table) || column_exists($pdo, $table, $col)) {
        return;
    }
    try {
        $pdo->exec('ALTER TABLE `' . str_replace('`', '', $table) . '` ADD COLUMN `' . str_replace('`', '', $col) . '` ' . $ddl);
    } catch (PDOException $e) {
        $m = strtolower($e->getMessage());
        if (!str_contains($m, 'duplicate') && !str_contains($m, '1060')) {
            throw $e;
        }
    }
}

function ensure_akademik_bait_kitab_table(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS akademik_bait_kitab (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nama_kitab VARCHAR(200) NOT NULL,
            jumlah_baris INT UNSIGNED NOT NULL DEFAULT 0,
            estimasi_hari_selesai INT UNSIGNED NOT NULL DEFAULT 30,
            target_baris_per_hari INT UNSIGNED NOT NULL DEFAULT 1,
            urutan INT NOT NULL DEFAULT 0,
            is_aktif TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_abk_aktif (is_aktif, urutan)
        )
    ');
}

function ensure_akademik_libur_table(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS akademik_libur (
            id INT AUTO_INCREMENT PRIMARY KEY,
            tanggal_mulai DATE NOT NULL,
            tanggal_selesai DATE NOT NULL,
            nama VARCHAR(200) NOT NULL,
            catatan TEXT NULL,
            affects_presensi TINYINT(1) NOT NULL DEFAULT 1,
            affects_setoran TINYINT(1) NOT NULL DEFAULT 1,
            affects_penilaian TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_libur_mulai (tanggal_mulai),
            INDEX idx_libur_selesai (tanggal_selesai)
        )
    ');
}

/** Libur tetap per hari dalam seminggu (1=Senin … 7=Minggu, sama dengan date N). */
function ensure_akademik_libur_mingguan_table(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS akademik_libur_mingguan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            hari_ke TINYINT UNSIGNED NOT NULL COMMENT "1=Senin … 7=Minggu",
            nama VARCHAR(200) NOT NULL,
            catatan TEXT NULL,
            affects_presensi TINYINT(1) NOT NULL DEFAULT 1,
            affects_setoran TINYINT(1) NOT NULL DEFAULT 1,
            affects_penilaian TINYINT(1) NOT NULL DEFAULT 1,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_libur_mingguan_hari (hari_ke, nama)
        )
    ');
}

/**
 * @return list<array<string, mixed>>
 */
function akademik_libur_mingguan_rows(PDO $pdo, bool $activeOnly = true): array
{
    ensure_akademik_libur_mingguan_table($pdo);
    $sql = 'SELECT * FROM akademik_libur_mingguan';
    if ($activeOnly) {
        $sql .= ' WHERE is_active = 1';
    }
    $sql .= ' ORDER BY hari_ke ASC, id ASC';

    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return array<int, string> */
function akademik_nama_hari_minggu(): array
{
    return [
        1 => 'Senin',
        2 => 'Selasa',
        3 => 'Rabu',
        4 => 'Kamis',
        5 => 'Jumat',
        6 => 'Sabtu',
        7 => 'Minggu',
    ];
}

/**
 * @return array{h:int,b:int,t:int,bulan_nama:string,label:string,ymd:string}|null
 */
function akademik_hijri_komponen_dari_ymd(string $hijriYmd, array $hijriBulanNama): ?array
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $hijriYmd, $m)) {
        return null;
    }
    $t = (int) $m[1];
    $b = (int) $m[2];
    $h = (int) $m[3];
    $nama = $hijriBulanNama[$b] ?? ('Bulan ' . $b);

    return [
        'h' => $h,
        'b' => $b,
        't' => $t,
        'bulan_nama' => $nama,
        'label' => sprintf('%d %s %d', $h, $nama, $t),
        'ymd' => $hijriYmd,
    ];
}

/** Label hijriyah untuk tanggal Masehi (pakai pemetaan DB). */
function akademik_hijri_label_dari_masehi(PDO $pdo, string $tanggalMasehi, array $hijriBulanNama): string
{
    $hijri = akademik_hijri_tanggal_sistem($pdo, $tanggalMasehi);
    $k = akademik_hijri_komponen_dari_ymd($hijri, $hijriBulanNama);
    if ($k === null) {
        return '';
    }
    // Hindari label palsu: fallback Intl yang gagal mengembalikan Y-m-d Masehi (tahun > 1600).
    if ($k['t'] < 1300 || $k['t'] > 1600) {
        return '';
    }

    return $k['label'];
}

/**
 * Label hijriyah dengan sufiks H: "16 Sya'ban 1447 H".
 * Mengabaikan hasil yang ternyata tanggal Masehi (tahun di luar 1300–1600).
 */
function akademik_hijri_label_h(PDO $pdo, string $tanggalMasehi, array $hijriBulanNama): string
{
    $candidates = [
        akademik_hijri_tanggal_sistem($pdo, $tanggalMasehi),
        get_hijri_full_date($tanggalMasehi),
    ];
    foreach ($candidates as $ymd) {
        $try = akademik_hijri_komponen_dari_ymd((string) $ymd, $hijriBulanNama);
        if ($try !== null && $try['t'] >= 1300 && $try['t'] <= 1600) {
            return sprintf('%d %s %d H', $try['h'], $try['bulan_nama'], $try['t']);
        }
    }

    return '';
}

/**
 * Badge dashboard mockup: "16 Sya'ban 1447 H / 2026 M".
 */
function akademik_hijri_badge_dashboard(PDO $pdo, string $tanggalMasehi, array $hijriBulanNama): string
{
    $gy = (int) substr($tanggalMasehi, 0, 4);
    if ($gy < 1) {
        $gy = (int) date('Y');
    }
    $hijriH = akademik_hijri_label_h($pdo, $tanggalMasehi, $hijriBulanNama);
    if ($hijriH === '') {
        return $gy . ' M';
    }

    return $hijriH . ' / ' . $gy . ' M';
}

/** Ringkas H/B/T untuk UI: "16 / Ramadan / 1447" */
function akademik_hijri_hbt_ringkas(PDO $pdo, string $tanggalMasehi, array $hijriBulanNama): string
{
    $hijri = akademik_hijri_tanggal_sistem($pdo, $tanggalMasehi);
    $k = akademik_hijri_komponen_dari_ymd($hijri, $hijriBulanNama);
    if ($k === null) {
        return '—';
    }

    return sprintf('%d / %s / %d', $k['h'], $k['bulan_nama'], $k['t']);
}

/**
 * Override hijriyah per tanggal masehi + tanda libur harian (centang).
 * hijri_override: yyyy-MM-dd (hijriyah tampilan); kosong = pakai perhitungan sistem (Intl).
 */
function ensure_akademik_kalender_hari_table(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS akademik_kalender_hari (
            tanggal_masehi DATE NOT NULL PRIMARY KEY,
            hijri_override VARCHAR(12) NULL,
            is_libur TINYINT(1) NOT NULL DEFAULT 0,
            affects_presensi TINYINT(1) NOT NULL DEFAULT 1,
            affects_setoran TINYINT(1) NOT NULL DEFAULT 1,
            affects_penilaian TINYINT(1) NOT NULL DEFAULT 1,
            nama_libur VARCHAR(160) NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ');
}

/**
 * Tanggal Masehi (hari ke-1) per bulan hijriyah untuk satu tahun H.; kosong = pakai hisab Intl (Um al-Qura / islamic).
 */
function ensure_akademik_hijri_awal_bulan_table(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS akademik_hijri_awal_bulan (
            tahun_hijriyah SMALLINT UNSIGNED NOT NULL,
            bulan_hijriyah TINYINT UNSIGNED NOT NULL,
            tanggal_awal_masehi DATE NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (tahun_hijriyah, bulan_hijriyah)
        )
    ');
}

function akademik_hijri_valid_format(string $s): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        return false;
    }
    $y = (int) $m[1];
    $mo = (int) $m[2];
    $d = (int) $m[3];

    return $y >= 1200 && $y <= 2000 && $mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31;
}

/**
 * Hijriyah yyyy-MM-dd dari Masehi: tetapan awal bulan (jika ada) lalu hisab Intl.
 * Tidak memakai hijri_override harian di akademik_kalender_hari.
 */
function akademik_hijri_tanggal_sistem(PDO $pdo, string $tanggalMasehi): string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalMasehi)) {
        return get_hijri_full_date($tanggalMasehi);
    }
    $db = konversiKeHijriah($pdo, $tanggalMasehi);
    if ($db !== null) {
        return (string) $db['tanggal_hijriah'];
    }
    $h = akademik_hijri_dari_pemetaan_awal_legacy($pdo, $tanggalMasehi);

    return $h ?? get_hijri_full_date($tanggalMasehi);
}

/** Tanggal hijriyah penuh (yyyy-MM-dd) untuk tampilan / setoran — tetapan awal bulan + hisab, tanpa suntingan per hari. */
function akademik_hijri_tanggal_penuh(PDO $pdo, string $tanggalMasehi): string
{
    return akademik_hijri_tanggal_sistem($pdo, $tanggalMasehi);
}

/** yyyy-MM hijriyah (untuk kolom presensi / rekap). */
function akademik_hijri_ym_untuk_masehi(PDO $pdo, string $tanggalMasehi): string
{
    $full = akademik_hijri_tanggal_penuh($pdo, $tanggalMasehi);
    if (preg_match('/^(\d{4}-\d{2})/', $full, $m)) {
        return $m[1];
    }

    return get_hijri_year_month($tanggalMasehi);
}

/**
 * Tahun hijriyah terkecil dan terbesar yang dilalui rentang 1 Jan–31 Des satu tahun Masehi,
 * menurut akademik_hijri_tanggal_penuh() (tetapan awal bulan & hisab di titik ujung).
 *
 * @return array{0:int,1:int}
 */
function akademik_hijri_tahun_range_untuk_tahun_masehi(PDO $pdo, int $gregorianYear): array
{
    $y = max(1, min(9999, $gregorianYear));
    $a = sprintf('%04d-01-01', $y);
    $b = sprintf('%04d-12-31', $y);
    $ha = akademik_hijri_tanggal_penuh($pdo, $a);
    $hb = akademik_hijri_tanggal_penuh($pdo, $b);
    $ya = 0;
    $yb = 0;
    if (preg_match('/^(\d{4})-/', $ha, $m)) {
        $ya = (int) $m[1];
    }
    if (preg_match('/^(\d{4})-/', $hb, $m)) {
        $yb = (int) $m[1];
    }
    if ($ya === 0) {
        $ya = $yb;
    }
    if ($yb === 0) {
        $yb = $ya;
    }

    return [min($ya, $yb), max($ya, $yb)];
}

/**
 * Tahun & bulan Hijriyah untuk anchor UI "hari ini" (kalender, redirect).
 * Jika Intl tidak aktif, get_hijri_* mengembalikan komponen seperti tahun Masehi (>1600);
 * itu bukan tahun H. valid di form kami — gunakan fallback agar tidak ter-clamp ke 1600 H.
 *
 * @return array{y:int,m:int}
 */
function akademik_hijri_anchor_hari_ini(PDO $pdo): array
{
    $minY = 1300;
    $maxY = 1500;
    $fallbackY = 1447;
    $fallbackM = 1;

    $h = akademik_hijri_tanggal_penuh($pdo, date('Y-m-d'));
    if (preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $h, $p)) {
        $y = (int) $p[1];
        $m = max(1, min(12, (int) $p[2]));
        if ($y >= $minY && $y <= $maxY) {
            return ['y' => $y, 'm' => $m];
        }
    }

    $ym = get_hijri_year_month(date('Y-m-d'));
    $parts = explode('-', $ym);
    if (count($parts) >= 2) {
        $y2 = (int) $parts[0];
        $m2 = max(1, min(12, (int) $parts[1]));
        if ($y2 >= $minY && $y2 <= $maxY) {
            return ['y' => $y2, 'm' => $m2];
        }
    }

    return ['y' => $fallbackY, 'm' => $fallbackM];
}

/**
 * @return array<string, array<string, mixed>>
 */
function akademik_kalender_hari_map_range(PDO $pdo, string $tanggalMulai, string $tanggalSelesai): array
{
    ensure_akademik_kalender_hari_table($pdo);
    if ($tanggalMulai > $tanggalSelesai) {
        $t = $tanggalMulai;
        $tanggalMulai = $tanggalSelesai;
        $tanggalSelesai = $t;
    }
    $st = $pdo->prepare('SELECT * FROM akademik_kalender_hari WHERE tanggal_masehi BETWEEN :a AND :b');
    $st->execute(['a' => $tanggalMulai, 'b' => $tanggalSelesai]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $k = (string) ($r['tanggal_masehi'] ?? '');
        if ($k !== '') {
            $out[$k] = $r;
        }
    }

    return $out;
}

/**
 * @return list<array{tahun_hijriyah:int,bulan_hijriyah:int,tanggal_awal_masehi:string}>
 */
function akademik_hijri_awal_bulan_rows(PDO $pdo, bool $forceRefresh = false): array
{
    static $cache = null;
    if ($forceRefresh) {
        $cache = null;
    }
    if ($cache !== null) {
        return $cache;
    }
    ensure_akademik_hijri_awal_bulan_table($pdo);
    $cache = $pdo->query('SELECT tahun_hijriyah, bulan_hijriyah, tanggal_awal_masehi FROM akademik_hijri_awal_bulan')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return $cache;
}

/** Hijriyah yyyy-MM-dd — tabel lama akademik_hijri_awal_bulan (fallback). */
function akademik_hijri_dari_pemetaan_awal_legacy(PDO $pdo, string $tanggalMasehi): ?string
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalMasehi)) {
        return null;
    }
    $best = null;
    $bestAwal = '';
    foreach (akademik_hijri_awal_bulan_rows($pdo) as $r) {
        $hy = (int) ($r['tahun_hijriyah'] ?? 0);
        $hm = (int) ($r['bulan_hijriyah'] ?? 0);
        $u = trim((string) ($r['tanggal_awal_masehi'] ?? ''));
        if (!hijri_tahun_valid($hy) || $hm < 1 || $hm > 12 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $u)) {
            continue;
        }
        if ($tanggalMasehi < $u || $u < $bestAwal) {
            continue;
        }
        $bestAwal = $u;
        $best = ['y' => $hy, 'm' => $hm, 'u' => $u];
    }
    if ($best === null) {
        return null;
    }
    $off = hijri_selisih_hari($best['u'], $tanggalMasehi);
    if ($off < 0 || $off > 30) {
        return null;
    }

    return sprintf('%04d-%02d-%02d', $best['y'], $best['m'], $off + 1);
}

/**
 * Daftar tanggal Masehi dalam satu bulan H.: pakai tetapan awal (geser) bila diisi, selain itu sama dengan get_masehi_days_in_hijri_month.
 *
 * @return list<string>
 */
function akademik_masehi_days_in_hijri_month(PDO $pdo, int $hijriYear, int $hijriMonth): array
{
    $dbDays = hijri_masehi_days_in_bulan($pdo, $hijriYear, $hijriMonth);
    if ($dbDays !== []) {
        return $dbDays;
    }

    ensure_akademik_hijri_awal_bulan_table($pdo);
    $base = get_masehi_days_in_hijri_month($hijriYear, $hijriMonth);
    $st = $pdo->prepare('SELECT tanggal_awal_masehi FROM akademik_hijri_awal_bulan WHERE tahun_hijriyah = :y AND bulan_hijriyah = :m LIMIT 1');
    $st->execute(['y' => $hijriYear, 'm' => $hijriMonth]);
    $u = trim((string) ($st->fetchColumn() ?: ''));
    if ($u === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $u)) {
        return $base;
    }
    $L = count($base);
    if ($L === 0) {
        return masehi_linear_days_between($u, date('Y-m-d', strtotime($u . ' +29 days')));
    }
    $out = [];
    for ($i = 0; $i < $L; $i++) {
        $ts = strtotime($u . ' +' . $i . ' days');
        if ($ts === false) {
            return $base;
        }
        $out[] = date('Y-m-d', $ts);
    }

    return $out;
}

/**
 * @return array{0: string, 1: string}
 */
function akademik_gregorian_range_from_hijri_month(PDO $pdo, int $hijriYear, int $hijriMonth): array
{
    $rentang = hijri_rentang_masehi_bulan($pdo, $hijriYear, $hijriMonth);
    if ($rentang[0] !== '' && $rentang[1] !== '') {
        return $rentang;
    }

    $days = akademik_masehi_days_in_hijri_month($pdo, $hijriYear, $hijriMonth);
    if ($days !== []) {
        return [$days[0], $days[count($days) - 1]];
    }

    return get_gregorian_range_from_hijri_month($hijriYear, $hijriMonth);
}

/**
 * Batas tanggal Masehi paling awal dan paling akhir yang dilalui satu tahun H. penuh (12 bulan, menurut hisab + tetapan awal bulan).
 *
 * @return array{0:string,1:string} [tanggal_min, tanggal_max] format yyyy-mm-dd
 */
function akademik_gregorian_bounds_for_hijri_year(PDO $pdo, int $hijriYear): array
{
    $starts = [];
    $ends = [];
    for ($m = 1; $m <= 12; $m++) {
        [$a, $b] = akademik_gregorian_range_from_hijri_month($pdo, $hijriYear, $m);
        $starts[] = $a;
        $ends[] = $b;
    }
    if ($starts === []) {
        return ['1970-01-01', '1970-12-31'];
    }

    return [min($starts), max($ends)];
}

/** Target baris/hari = ceil(total baris / hari), minimal 1. */
function akademik_hitung_target_bait_per_hari(int $jumlahBaris, int $estimasiHari): int
{
    $hari = max(1, $estimasiHari);
    $baris = max(0, $jumlahBaris);

    return max(1, (int) ceil($baris / $hari));
}

function ensure_akademik_hafalan_setoran_table(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS akademik_hafalan_setoran (
            id INT AUTO_INCREMENT PRIMARY KEY,
            santri_id INT NOT NULL,
            tanggal_setoran DATE NOT NULL,
            target_hafalan VARCHAR(255) NOT NULL,
            juz_halaman VARCHAR(120) NULL,
            nilai_skor TINYINT UNSIGNED NULL,
            predikat VARCHAR(40) NULL,
            catatan TEXT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ahs_santri (santri_id),
            INDEX idx_ahs_tgl (tanggal_setoran),
            CONSTRAINT fk_ahs_santri FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
        )
    ');
    akademik_add_column($pdo, 'akademik_hafalan_setoran', 'kategori_setoran', "VARCHAR(12) NOT NULL DEFAULT 'ALQURAN'");
    akademik_add_column($pdo, 'akademik_hafalan_setoran', 'bait_kitab_id', 'INT UNSIGNED NULL');
    akademik_add_column($pdo, 'akademik_hafalan_setoran', 'baris_setor', 'INT UNSIGNED NULL');
    akademik_add_column($pdo, 'akademik_hafalan_setoran', 'kalender_hijriyah', 'VARCHAR(12) NULL');
}

function ensure_akademik_rapor_table(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS akademik_rapor (
            id INT AUTO_INCREMENT PRIMARY KEY,
            santri_id INT NOT NULL,
            judul_periode VARCHAR(160) NOT NULL,
            tanggal_terbit DATE NOT NULL,
            narasi TEXT NULL,
            predikat_akhlak VARCHAR(100) NULL,
            catatan_pondok TEXT NULL,
            is_published TINYINT(1) NOT NULL DEFAULT 0,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_ar_santri (santri_id),
            INDEX idx_ar_terbit (tanggal_terbit),
            CONSTRAINT fk_ar_santri FOREIGN KEY (santri_id) REFERENCES santri(id) ON DELETE CASCADE
        )
    ');
}

/**
 * @param 'presensi'|'setoran'|'penilaian' $jenis
 * @return null|array{id:int,nama:string,catatan:?string}
 */
function akademik_libur_info(PDO $pdo, string $tanggal, string $jenis): ?array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return null;
    }
    ensure_akademik_libur_table($pdo);
    ensure_akademik_kalender_hari_table($pdo);
    $col = match ($jenis) {
        'presensi' => 'affects_presensi',
        'setoran' => 'affects_setoran',
        'penilaian' => 'affects_penilaian',
        default => '',
    };
    if ($col === '') {
        return null;
    }
    $stH = $pdo->prepare("
        SELECT tanggal_masehi, nama_libur
        FROM akademik_kalender_hari
        WHERE tanggal_masehi = :d AND is_libur = 1 AND {$col} = 1
        LIMIT 1
    ");
    $stH->execute(['d' => $tanggal]);
    $hRow = $stH->fetch(PDO::FETCH_ASSOC);
    if ($hRow) {
        $nama = trim((string) ($hRow['nama_libur'] ?? ''));

        return [
            'id' => 0,
            'nama' => $nama !== '' ? $nama : 'Libur (kalender harian)',
            'catatan' => null,
            'sumber' => 'harian',
        ];
    }

    $st = $pdo->prepare("
        SELECT id, nama, catatan
        FROM akademik_libur
        WHERE tanggal_mulai <= :d AND tanggal_selesai >= :d AND {$col} = 1
        ORDER BY tanggal_mulai ASC, id ASC
        LIMIT 1
    ");
    $st->execute(['d' => $tanggal]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        return [
            'id' => (int) $r['id'],
            'nama' => (string) $r['nama'],
            'catatan' => isset($r['catatan']) && trim((string) $r['catatan']) !== '' ? (string) $r['catatan'] : null,
            'sumber' => 'rentang',
        ];
    }

    $ts = strtotime($tanggal);
    if ($ts === false) {
        return null;
    }
    $hariKe = (int) date('N', $ts);
    foreach (akademik_libur_mingguan_rows($pdo) as $lm) {
        if ((int) ($lm['hari_ke'] ?? 0) !== $hariKe) {
            continue;
        }
        if (empty($lm[$col])) {
            continue;
        }

        return [
            'id' => (int) ($lm['id'] ?? 0),
            'nama' => (string) ($lm['nama'] ?? 'Libur mingguan'),
            'catatan' => isset($lm['catatan']) && trim((string) $lm['catatan']) !== '' ? (string) $lm['catatan'] : null,
            'sumber' => 'mingguan',
        ];
    }

    return null;
}

/**
 * Libur untuk banner portal wali: sama dengan sel oranye di grid kalender.
 * Tidak bergantung saklar blokir presensi (itu hanya menahan scan).
 *
 * @return array{
 *   nama:string,
 *   sumber:string,
 *   tanggal_mulai:?string,
 *   tanggal_selesai:?string,
 *   hari_ke:?int,
 *   mode:string,
 *   blokir_presensi:bool
 * }|null
 */
function akademik_libur_presensi_tampilan(PDO $pdo, ?string $tanggal = null): ?array
{
    $tanggal = $tanggal ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return null;
    }

    $mode = function_exists('akademik_libur_presensi_mode')
        ? akademik_libur_presensi_mode($pdo)
        : 'ALL_BLOCKED';
    $blokir = akademik_blokir_presensi_libur($pdo);

    ensure_akademik_libur_table($pdo);
    ensure_akademik_kalender_hari_table($pdo);

    $st = $pdo->prepare('
        SELECT nama, tanggal_mulai, tanggal_selesai
        FROM akademik_libur
        WHERE tanggal_mulai <= :d AND tanggal_selesai >= :d
        ORDER BY tanggal_mulai ASC, id ASC
        LIMIT 1
    ');
    $st->execute(['d' => $tanggal]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r) {
        $mulai = (string) ($r['tanggal_mulai'] ?? $tanggal);
        $selesai = (string) ($r['tanggal_selesai'] ?? $tanggal);

        return [
            'nama' => (string) ($r['nama'] ?? 'Hari libur'),
            'sumber' => 'rentang',
            'tanggal_mulai' => $mulai,
            'tanggal_selesai' => $selesai,
            'hari_ke' => null,
            'mode' => $mode,
            'blokir_presensi' => $blokir,
        ];
    }

    $ts = strtotime($tanggal);
    $hariKe = $ts !== false ? (int) date('N', $ts) : 0;
    foreach (akademik_libur_mingguan_rows($pdo) as $lm) {
        if ((int) ($lm['hari_ke'] ?? 0) !== $hariKe) {
            continue;
        }

        return [
            'nama' => (string) ($lm['nama'] ?? 'Libur mingguan'),
            'sumber' => 'mingguan',
            'tanggal_mulai' => null,
            'tanggal_selesai' => null,
            'hari_ke' => $hariKe,
            'mode' => $mode,
            'blokir_presensi' => $blokir,
        ];
    }

    $stH = $pdo->prepare('
        SELECT tanggal_masehi, nama_libur
        FROM akademik_kalender_hari
        WHERE tanggal_masehi = :d AND is_libur = 1
        LIMIT 1
    ');
    $stH->execute(['d' => $tanggal]);
    $hRow = $stH->fetch(PDO::FETCH_ASSOC);
    if ($hRow) {
        $nama = trim((string) ($hRow['nama_libur'] ?? ''));
        $tgl = (string) ($hRow['tanggal_masehi'] ?? $tanggal);

        return [
            'nama' => $nama !== '' ? $nama : 'Libur (kalender harian)',
            'sumber' => 'harian',
            'tanggal_mulai' => $tgl,
            'tanggal_selesai' => $tgl,
            'hari_ke' => null,
            'mode' => $mode,
            'blokir_presensi' => $blokir,
        ];
    }

    return null;
}

function akademik_blokir_presensi_libur(PDO $pdo): bool
{
    return app_setting($pdo, 'akademik_blokir_presensi_libur', '1') !== '0';
}

function akademik_blokir_setoran_libur(PDO $pdo): bool
{
    return app_setting($pdo, 'akademik_blokir_setoran_libur', '1') !== '0';
}

function akademik_blokir_penilaian_libur(PDO $pdo): bool
{
    return app_setting($pdo, 'akademik_blokir_penilaian_libur', '0') !== '0';
}

/** Angka Latin → angka Arab Timur (Hindi) ٠–٩ */
function akademik_digit_latin_ke_arab_timur(string $latinDigits): string
{
    static $map = [
        '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
        '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
    ];

    return strtr($latinDigits, $map);
}

/**
 * Contoh: 1447-09-15 + nama bulan → "١٥ Ramadan ١٤٤٧" (angka hijriyah Arab, nama bulan Latin/Indonesia).
 *
 * @param array<int, string> $namaBulanHijriTerindeks1
 */
function akademik_format_hijri_ke_arab_dengan_nama(string $hijriYmd, array $namaBulanHijriTerindeks1): string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $hijriYmd, $m)) {
        return '';
    }
    $y = (int) $m[1];
    $mo = (int) $m[2];
    $d = (int) $m[3];
    $nama = $namaBulanHijriTerindeks1[$mo] ?? ('Bulan ' . $mo);

    return trim(akademik_digit_latin_ke_arab_timur((string) $d)) . ' ' . $nama . ' ' . akademik_digit_latin_ke_arab_timur(sprintf('%04d', $y));
}

/** Label masehi singkat: "14 Mei 2026" */
function akademik_masehi_label_pendek(string $tanggalMasehi): string
{
    $ts = strtotime($tanggalMasehi);
    if ($ts === false) {
        return $tanggalMasehi;
    }
    $bulan = [1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'];
    $n = (int) date('n', $ts);

    return (int) date('j', $ts) . ' ' . ($bulan[$n] ?? date('F', $ts)) . ' ' . date('Y', $ts);
}

/** Label alamat lengkap untuk tampilan daftar alumni. */
function alumni_format_alamat_label(array $row): string
{
    $parts = array_filter([
        trim((string) ($row['dusun'] ?? '')),
        trim((string) ($row['rt_rw'] ?? '')) !== '' ? 'RT/RW ' . $row['rt_rw'] : '',
        trim((string) ($row['desa_kelurahan'] ?? '')),
        trim((string) ($row['kecamatan'] ?? '')),
        trim((string) ($row['kabupaten'] ?? '')),
        trim((string) ($row['propinsi'] ?? '')),
    ]);

    return $parts ? implode(', ', $parts) : '—';
}

/**
 * @param array<string, string> $filters
 * @return array{sql: string, params: array<string, mixed>}
 */
function alumni_list_query_parts(array $filters): array
{
    $where = [];
    $params = [];
    $cari = trim((string) ($filters['cari'] ?? ''));
    if ($cari !== '') {
        $where[] = '(nis LIKE :cari OR nama LIKE :cari)';
        $params['cari'] = '%' . $cari . '%';
    }
    foreach (['dusun', 'desa_kelurahan', 'kecamatan', 'kabupaten'] as $col) {
        $val = trim((string) ($filters[$col] ?? ''));
        if ($val !== '') {
            $where[] = '`' . $col . '` = :' . $col;
            $params[$col] = $val;
        }
    }
    $thMasuk = trim((string) ($filters['th_masuk'] ?? ''));
    if ($thMasuk !== '' && ctype_digit($thMasuk)) {
        $where[] = 'th_masuk = :th_masuk';
        $params['th_masuk'] = (int) $thMasuk;
    }
    $thKeluar = trim((string) ($filters['th_keluar'] ?? ''));
    if ($thKeluar !== '' && ctype_digit($thKeluar)) {
        $where[] = 'th_keluar = :th_keluar';
        $params['th_keluar'] = (int) $thKeluar;
    }
    $keterangan = trim((string) ($filters['keterangan'] ?? ''));
    if ($keterangan !== '') {
        $where[] = 'keterangan LIKE :keterangan';
        $params['keterangan'] = '%' . $keterangan . '%';
    }

    return [
        'sql' => $where ? ' WHERE ' . implode(' AND ', $where) : '',
        'params' => $params,
    ];
}

/**
 * @param array{cari?: string, alamat?: string, th_masuk?: string, th_keluar?: string} $filters
 * @return list<array<string, mixed>>
 */
function alumni_order_by_sql(): string
{
    return 'urutan ASC, CAST(nis AS UNSIGNED) ASC, LENGTH(nis) ASC, nis ASC';
}

function alumni_next_urutan(PDO $pdo): int
{
    ensure_akademik_alumni_table($pdo);

    return ((int) $pdo->query('SELECT COALESCE(MAX(urutan), 0) FROM akademik_alumni')->fetchColumn()) + 1;
}

function alumni_fetch_rows(PDO $pdo, array $filters = []): array
{
    ensure_akademik_alumni_table($pdo);
    $parts = alumni_list_query_parts($filters);
    $sql = 'SELECT * FROM akademik_alumni' . $parts['sql'] . ' ORDER BY ' . alumni_order_by_sql();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($parts['params']);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @return list<int> */
function alumni_distinct_tahun(PDO $pdo, string $column): array
{
    ensure_akademik_alumni_table($pdo);
    if ($column !== 'th_masuk' && $column !== 'th_keluar') {
        return [];
    }
    $rows = $pdo->query('SELECT DISTINCT ' . $column . ' AS th FROM akademik_alumni WHERE ' . $column . ' IS NOT NULL ORDER BY ' . $column . ' DESC')->fetchAll(PDO::FETCH_COLUMN) ?: [];

    return array_map('intval', $rows);
}

/** @return list<string> */
function alumni_distinct_alamat(PDO $pdo, string $column): array
{
    ensure_akademik_alumni_table($pdo);
    $allowed = ['dusun', 'desa_kelurahan', 'kecamatan', 'kabupaten'];
    if (!in_array($column, $allowed, true)) {
        return [];
    }
    $sql = 'SELECT DISTINCT `' . $column . '` AS v FROM akademik_alumni WHERE `' . $column . '` IS NOT NULL AND TRIM(`' . $column . '`) <> \'\' ORDER BY v ASC';
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: [];

    return array_values(array_filter(array_map(static fn($v): string => trim((string) $v), $rows)));
}

function ensure_akademik_alumni_table(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS akademik_alumni (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nis VARCHAR(32) NOT NULL,
            nama VARCHAR(200) NOT NULL,
            dusun VARCHAR(120) NULL,
            rt_rw VARCHAR(20) NULL,
            desa_kelurahan VARCHAR(120) NULL,
            kecamatan VARCHAR(120) NULL,
            kabupaten VARCHAR(120) NULL,
            propinsi VARCHAR(120) NULL,
            th_masuk SMALLINT UNSIGNED NULL,
            th_keluar SMALLINT UNSIGNED NULL,
            keterangan TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_akademik_alumni_nis (nis),
            INDEX idx_akademik_alumni_nama (nama)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ');
    akademik_add_column($pdo, 'akademik_alumni', 'urutan', 'INT UNSIGNED NULL');
    try {
        $pdo->exec('UPDATE akademik_alumni SET urutan = id WHERE urutan IS NULL');
    } catch (PDOException $e) {
        // abaikan jika tabel belum ada data
    }
}
