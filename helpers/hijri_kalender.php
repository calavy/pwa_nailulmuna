<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/**
 * Daftar nama bulan hijriyah standar (indeks 1–12).
 *
 * @return array<int, string>
 */
function hijri_nama_bulan_list(): array
{
    return [
        1 => 'Muharram',
        2 => 'Safar',
        3 => "Rabi' I",
        4 => "Rabi' II",
        5 => 'Jumadil Awal',
        6 => 'Jumadil Akhir',
        7 => 'Rajab',
        8 => "Sya'ban",
        9 => 'Ramadan',
        10 => 'Syawal',
        11 => "Dzulqa'dah",
        12 => 'Dzulhijah',
    ];
}

/** Normalisasi nama bulan untuk pencocokan. */
function hijri_normalisasi_nama_bulan(string $nama): string
{
    $s = trim($nama);
    $aliases = [
        'muharram' => 'Muharram',
        'safar' => 'Safar',
        'rabiul awal' => "Rabi' I",
        'rabiul akhir' => "Rabi' II",
        'rabi i' => "Rabi' I",
        'rabi ii' => "Rabi' II",
        'jumadil awal' => 'Jumadil Awal',
        'jumadil akhir' => 'Jumadil Akhir',
        'rajab' => 'Rajab',
        'syaban' => "Sya'ban",
        'sya ban' => "Sya'ban",
        'ramadhan' => 'Ramadan',
        'ramadan' => 'Ramadan',
        'syawal' => 'Syawal',
        'dzulqadah' => "Dzulqa'dah",
        'dzul qadah' => "Dzulqa'dah",
        'dzulhijah' => 'Dzulhijah',
        'dzul hijah' => 'Dzulhijah',
    ];
    $key = strtolower(preg_replace('/\s+/', ' ', $s) ?? $s);
    if (isset($aliases[$key])) {
        return $aliases[$key];
    }
    foreach (hijri_nama_bulan_list() as $canonical) {
        if (strcasecmp($canonical, $s) === 0) {
            return $canonical;
        }
    }

    return $s;
}

/** Indeks bulan 1–12 dari nama; 0 jika tidak dikenali. */
function hijri_nama_ke_indeks(string $nama): int
{
    $norm = hijri_normalisasi_nama_bulan($nama);
    foreach (hijri_nama_bulan_list() as $idx => $label) {
        if ($label === $norm) {
            return $idx;
        }
    }

    return 0;
}

function hijri_indeks_ke_nama(int $bulan): string
{
    $bulan = max(1, min(12, $bulan));

    return hijri_nama_bulan_list()[$bulan] ?? ('Bulan ' . $bulan);
}

/** Tahun hijriyah yang masuk akal untuk pemetaan (bukan tahun Masehi / clamp lama). */
function hijri_tahun_valid(int $tahun): bool
{
    return $tahun >= 1300 && $tahun <= 1500;
}

function ensure_hijri_mappings_table(PDO $pdo): void
{
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS hijri_mappings (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nama_bulan VARCHAR(40) NOT NULL,
            tahun_hijriah SMALLINT UNSIGNED NOT NULL,
            tanggal_masehi_awal_bulan DATE NOT NULL,
            total_hari TINYINT UNSIGNED NOT NULL DEFAULT 30,
            created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_hijri_tahun_bulan (tahun_hijriah, nama_bulan),
            KEY idx_hijri_awal_masehi (tanggal_masehi_awal_bulan),
            KEY idx_hijri_tahun (tahun_hijriah)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
    hijri_bersihkan_data_tidak_valid($pdo);
}

/** Hapus baris tahun H. tidak valid (mis. 1600 dari fallback Intl mati). */
function hijri_bersihkan_data_tidak_valid(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo->exec('DELETE FROM hijri_mappings WHERE tahun_hijriah < 1300 OR tahun_hijriah > 1500');
    if (table_exists($pdo, 'akademik_hijri_awal_bulan')) {
        $pdo->exec('DELETE FROM akademik_hijri_awal_bulan WHERE tahun_hijriyah < 1300 OR tahun_hijriyah > 1500');
    }
    hijri_mappings_rows($pdo, true);
}

/**
 * Tanggal Masehi Y-m-d → H (hari), B (bulan), T (tahun).
 *
 * @return array{h:int,b:int,t:int}
 */
function hijri_masehi_ke_hbt(string $ymd): array
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) {
        return ['h' => 0, 'b' => 0, 't' => 0];
    }

    return ['h' => (int) $m[3], 'b' => (int) $m[2], 't' => (int) $m[1]];
}

/** Susun tanggal Masehi dari H/B/T (Hari / Bulan / Tahun). */
function hijri_masehi_dari_hbt(int $hari, int $bulan, int $tahun): ?string
{
    if ($hari < 1 || $hari > 31 || $bulan < 1 || $bulan > 12 || $tahun < 1970 || $tahun > 2100) {
        return null;
    }
    if (!checkdate($bulan, $hari, $tahun)) {
        return null;
    }

    return sprintf('%04d-%02d-%02d', $tahun, $bulan, $hari);
}

/**
 * Baca tanggal Masehi dari POST: {prefix}_h/b/t[indeks] atau {prefix}[indeks] (legacy).
 *
 * @param array<string, mixed> $post
 */
function hijri_masehi_hbt_dari_post(array $post, string $prefix, int|string $indeks): string
{
    $keyH = $prefix . '_h';
    $keyB = $prefix . '_b';
    $keyT = $prefix . '_t';
    $valH = $post[$keyH] ?? null;
    $valB = $post[$keyB] ?? null;
    $valT = $post[$keyT] ?? null;

    if (is_array($valH) && array_key_exists($indeks, $valH)) {
        $h = (int) $valH[$indeks];
        $b = (int) (is_array($valB) ? ($valB[$indeks] ?? 0) : 0);
        $t = (int) (is_array($valT) ? ($valT[$indeks] ?? 0) : 0);
        if ($h === 0 && $b === 0 && $t === 0) {
            return '';
        }
        $ymd = hijri_masehi_dari_hbt($h, $b, $t);

        return $ymd ?? '';
    }
    if (isset($post[$keyH], $post[$keyB], $post[$keyT]) && !is_array($post[$keyH])) {
        $h = (int) $post[$keyH];
        $b = (int) $post[$keyB];
        $t = (int) $post[$keyT];
        if ($h === 0 && $b === 0 && $t === 0) {
            return '';
        }
        $ymd = hijri_masehi_dari_hbt($h, $b, $t);

        return $ymd ?? '';
    }
    $legacy = $post[$prefix] ?? null;
    if (is_array($legacy)) {
        return trim((string) ($legacy[$indeks] ?? ''));
    }

    return trim((string) $legacy);
}

/** @param array<string, mixed> $post */
function hijri_masehi_awal_dari_post(array $post, int|string $indeks): string
{
    return hijri_masehi_hbt_dari_post($post, 'awal', $indeks);
}

/**
 * Selisih hari kalender: $dari → $ke (0 jika sama).
 */
function hijri_selisih_hari(string $dari, string $ke): int
{
    $ta = strtotime($dari);
    $tb = strtotime($ke);
    if ($ta === false || $tb === false) {
        return 0;
    }

    return (int) round(($tb - $ta) / 86400);
}

/**
 * @return list<array<string, mixed>>
 */
function hijri_mappings_rows(PDO $pdo, bool $forceRefresh = false): array
{
    static $cache = null;
    if ($forceRefresh) {
        $cache = null;
    }
    if (is_array($cache)) {
        return $cache;
    }
    ensure_hijri_mappings_table($pdo);
    $cache = $pdo->query('
        SELECT id, nama_bulan, tahun_hijriah, tanggal_masehi_awal_bulan, total_hari
        FROM hijri_mappings
        ORDER BY tanggal_masehi_awal_bulan ASC, id ASC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return $cache;
}

/**
 * Impor sekali dari tabel lama akademik_hijri_awal_bulan (jika ada).
 */
function hijri_sync_from_akademik_awal_bulan(PDO $pdo): void
{
    static $synced = false;
    if ($synced) {
        return;
    }
    $synced = true;

    if (!function_exists('ensure_akademik_hijri_awal_bulan_table') || !table_exists($pdo, 'akademik_hijri_awal_bulan')) {
        return;
    }
    ensure_akademik_hijri_awal_bulan_table($pdo);
    ensure_hijri_mappings_table($pdo);
    $legacy = $pdo->query('SELECT tahun_hijriyah, bulan_hijriyah, tanggal_awal_masehi FROM akademik_hijri_awal_bulan')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($legacy === []) {
        return;
    }
    $ins = $pdo->prepare('
        INSERT INTO hijri_mappings (nama_bulan, tahun_hijriah, tanggal_masehi_awal_bulan, total_hari)
        VALUES (:nama, :tahun, :awal, :total)
        ON DUPLICATE KEY UPDATE
            tanggal_masehi_awal_bulan = VALUES(tanggal_masehi_awal_bulan),
            total_hari = VALUES(total_hari)
    ');
    foreach ($legacy as $r) {
        $hm = (int) ($r['bulan_hijriyah'] ?? 0);
        $hy = (int) ($r['tahun_hijriyah'] ?? 0);
        $awal = trim((string) ($r['tanggal_awal_masehi'] ?? ''));
        if ($hm < 1 || $hm > 12 || !hijri_tahun_valid($hy) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $awal)) {
            continue;
        }
        $ins->execute([
            'nama' => hijri_indeks_ke_nama($hm),
            'tahun' => $hy,
            'awal' => $awal,
            'total' => 30,
        ]);
    }
    hijri_mappings_rows($pdo, true);
}

/**
 * Simpan / perbarui satu pemetaan bulan.
 */
function hijri_simpan_mapping(
    PDO $pdo,
    int $tahunHijriah,
    string $namaBulan,
    string $tanggalMasehiAwal,
    int $totalHari
): void {
    ensure_hijri_mappings_table($pdo);
    $nama = hijri_normalisasi_nama_bulan($namaBulan);
    $totalHari = $totalHari === 29 ? 29 : 30;
    if (!hijri_tahun_valid($tahunHijriah) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalMasehiAwal)) {
        throw new InvalidArgumentException('Data pemetaan hijriyah tidak valid.');
    }
    $st = $pdo->prepare('
        INSERT INTO hijri_mappings (nama_bulan, tahun_hijriah, tanggal_masehi_awal_bulan, total_hari)
        VALUES (:nama, :tahun, :awal, :total)
        ON DUPLICATE KEY UPDATE
            tanggal_masehi_awal_bulan = VALUES(tanggal_masehi_awal_bulan),
            total_hari = VALUES(total_hari)
    ');
    $st->execute([
        'nama' => $nama,
        'tahun' => $tahunHijriah,
        'awal' => $tanggalMasehiAwal,
        'total' => $totalHari,
    ]);
    hijri_mappings_rows($pdo, true);
}

function hijri_hapus_mapping_tahun_bulan(PDO $pdo, int $tahunHijriah, string $namaBulan): void
{
    ensure_hijri_mappings_table($pdo);
    $pdo->prepare('DELETE FROM hijri_mappings WHERE tahun_hijriah = :t AND nama_bulan = :n')
        ->execute(['t' => $tahunHijriah, 'n' => hijri_normalisasi_nama_bulan($namaBulan)]);
    hijri_mappings_rows($pdo, true);
}

/**
 * Pemetaan berikutnya setelah baris $current (urut tanggal awal).
 *
 * @param array<string, mixed> $current
 * @return array<string, mixed>|null
 */
function hijri_mapping_berikutnya(PDO $pdo, array $current): ?array
{
    $awalCur = (string) ($current['tanggal_masehi_awal_bulan'] ?? '');
    $tahunCur = (int) ($current['tahun_hijriah'] ?? 0);
    $namaCur = (string) ($current['nama_bulan'] ?? '');
    $bulanIdx = hijri_nama_ke_indeks($namaCur);

    $candidates = [];
    foreach (hijri_mappings_rows($pdo) as $row) {
        $awal = (string) ($row['tanggal_masehi_awal_bulan'] ?? '');
        if ($awal === '' || $awal <= $awalCur) {
            continue;
        }
        $candidates[] = $row;
    }
    if ($candidates !== []) {
        usort($candidates, static fn(array $a, array $b): int => strcmp((string) $a['tanggal_masehi_awal_bulan'], (string) $b['tanggal_masehi_awal_bulan']));

        return $candidates[0];
    }

    if ($bulanIdx >= 1 && $bulanIdx < 12) {
        $nextNama = hijri_indeks_ke_nama($bulanIdx + 1);
        foreach (hijri_mappings_rows($pdo) as $row) {
            if ((int) ($row['tahun_hijriah'] ?? 0) === $tahunCur && (string) ($row['nama_bulan'] ?? '') === $nextNama) {
                return $row;
            }
        }
        $lastDay = (string) ($current['tanggal_masehi_awal_bulan'] ?? '');
        $total = (int) ($current['total_hari'] ?? 30);
        $ts = strtotime($lastDay . ' +' . $total . ' days');
        if ($ts !== false) {
            return [
                'nama_bulan' => $nextNama,
                'tahun_hijriah' => $tahunCur,
                'tanggal_masehi_awal_bulan' => date('Y-m-d', $ts),
                'total_hari' => 30,
                'id' => 0,
            ];
        }
    }
    if ($bulanIdx === 12) {
        $nextNama = hijri_indeks_ke_nama(1);
        foreach (hijri_mappings_rows($pdo) as $row) {
            if ((int) ($row['tahun_hijriah'] ?? 0) === $tahunCur + 1 && (string) ($row['nama_bulan'] ?? '') === $nextNama) {
                return $row;
            }
        }
    }

    return null;
}

/**
 * Konversi tanggal Masehi (Y-m-d) ke Hijriyah berbasis database.
 *
 * Logika:
 * 1. Ambil baris dengan tanggal_masehi_awal_bulan <= tanggal, urut DESC, baris pertama.
 * 2. Hari H. = selisih hari + 1.
 * 3. Jika hari > total_hari, lanjut ke bulan berikutnya (isbat / 29–30 hari).
 *
 * @return array{
 *   tanggal:int,
 *   nama_bulan:string,
 *   tahun_hijriah:int,
 *   bulan_hijriyah:int,
 *   tanggal_hijriah:string,
 *   tanggal_masehi:string,
 *   mapping_id:int,
 *   total_hari:int,
 *   sumber:string
 * }|null
 */
function konversiKeHijriah(PDO $pdo, string $tanggalMasehi): ?array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalMasehi)) {
        return null;
    }

    static $cache = [];
    if (array_key_exists($tanggalMasehi, $cache)) {
        return $cache[$tanggalMasehi];
    }

    ensure_hijri_mappings_table($pdo);
    hijri_sync_from_akademik_awal_bulan($pdo);

    $st = $pdo->prepare('
        SELECT id, nama_bulan, tahun_hijriah, tanggal_masehi_awal_bulan, total_hari
        FROM hijri_mappings
        WHERE tanggal_masehi_awal_bulan <= :tgl
        ORDER BY tanggal_masehi_awal_bulan DESC, id DESC
        LIMIT 1
    ');
    $st->execute(['tgl' => $tanggalMasehi]);
    $mapping = $st->fetch(PDO::FETCH_ASSOC);
    if (!$mapping || !hijri_tahun_valid((int) ($mapping['tahun_hijriah'] ?? 0))) {
        $cache[$tanggalMasehi] = null;

        return null;
    }

    $cursor = $mapping;
    $sisaHari = hijri_selisih_hari((string) $cursor['tanggal_masehi_awal_bulan'], $tanggalMasehi) + 1;
    $guard = 0;

    while ($guard < 24) {
        $guard++;
        $totalHari = (int) ($cursor['total_hari'] ?? 30);
        if ($totalHari < 29) {
            $totalHari = 30;
        }
        if ($totalHari > 30) {
            $totalHari = 30;
        }

        if ($sisaHari <= $totalHari) {
            $bulanIdx = hijri_nama_ke_indeks((string) $cursor['nama_bulan']);
            if ($bulanIdx < 1) {
                $bulanIdx = 1;
            }
            $tahunH = (int) $cursor['tahun_hijriah'];

            $hasil = [
                'tanggal' => $sisaHari,
                'nama_bulan' => (string) $cursor['nama_bulan'],
                'tahun_hijriah' => $tahunH,
                'bulan_hijriyah' => $bulanIdx,
                'tanggal_hijriah' => sprintf('%04d-%02d-%02d', $tahunH, $bulanIdx, $sisaHari),
                'tanggal_masehi' => $tanggalMasehi,
                'mapping_id' => (int) ($cursor['id'] ?? 0),
                'total_hari' => $totalHari,
                'sumber' => 'hijri_mappings',
            ];
            $cache[$tanggalMasehi] = $hasil;

            return $hasil;
        }

        $sisaHari -= $totalHari;
        $next = hijri_mapping_berikutnya($pdo, $cursor);
        if ($next === null) {
            $cache[$tanggalMasehi] = null;

            return null;
        }
        $cursor = $next;
    }

    $cache[$tanggalMasehi] = null;

    return null;
}

/**
 * yyyy-MM-dd hijriyah untuk integrasi modul lain.
 */
function hijri_tanggal_penuh_dari_masehi(PDO $pdo, string $tanggalMasehi): string
{
    $k = konversiKeHijriah($pdo, $tanggalMasehi);
    if ($k !== null) {
        return (string) $k['tanggal_hijriah'];
    }

    return get_hijri_full_date($tanggalMasehi);
}

/**
 * Daftar tanggal Masehi dalam satu bulan H. menurut pemetaan DB.
 *
 * @return list<string>
 */
function hijri_masehi_days_in_bulan(PDO $pdo, int $tahunHijriah, int $bulanHijriyah): array
{
    $nama = hijri_indeks_ke_nama($bulanHijriyah);
    ensure_hijri_mappings_table($pdo);
    $st = $pdo->prepare('
        SELECT tanggal_masehi_awal_bulan, total_hari
        FROM hijri_mappings
        WHERE tahun_hijriah = :y AND nama_bulan = :n
        LIMIT 1
    ');
    $st->execute(['y' => $tahunHijriah, 'n' => $nama]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($row['tanggal_masehi_awal_bulan'] ?? ''))) {
        return [];
    }
    $awal = (string) $row['tanggal_masehi_awal_bulan'];
    $total = (int) ($row['total_hari'] ?? 30);
    $total = max(29, min(30, $total));
    $out = [];
    for ($i = 0; $i < $total; $i++) {
        $ts = strtotime($awal . ' +' . $i . ' days');
        if ($ts === false) {
            break;
        }
        $out[] = date('Y-m-d', $ts);
    }

    return $out;
}

/**
 * @return array{0:string,1:string}
 */
function hijri_rentang_masehi_bulan(PDO $pdo, int $tahunHijriah, int $bulanHijriyah): array
{
    $days = hijri_masehi_days_in_bulan($pdo, $tahunHijriah, $bulanHijriyah);
    if ($days === []) {
        $fallback = get_gregorian_range_from_hijri_month($tahunHijriah, $bulanHijriyah);

        return $fallback;
    }

    return [$days[0], $days[count($days) - 1]];
}
