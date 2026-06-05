<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/akademik.php';
require_once __DIR__ . '/hijri_kalender.php';
require_once __DIR__ . '/santri_operasional.php';
require_once __DIR__ . '/presensi_jadwal.php';

/** Urutan bulan TA hijriyah: Syawal → … → Ramadhan (tahun berikutnya). */
function skbt_bulan_urutan_ta(): array
{
    return [10, 11, 12, 1, 2, 3, 4, 5, 6, 7, 8, 9];
}

/** Label bulan cetak (contoh laporan: SYAWAL 1444). */
function skbt_bulan_label_cetak(int $bulan, int $tahun): string
{
    $labels = [
        1 => 'MUHARAM', 2 => 'SAFAR', 3 => 'RABIUL AWAL', 4 => 'RABIUL AKHIR',
        5 => 'JUMADIL AWAL', 6 => 'JUMADIL AKHIR', 7 => 'RAJAB', 8 => 'SYAKBAN',
        9 => 'RAMADHAN', 10 => 'SYAWAL', 11 => 'ZULKAIDAH', 12 => 'ZULHIJAH',
    ];
    $b = max(1, min(12, $bulan));

    return ($labels[$b] ?? 'BULAN ' . $b) . ' ' . $tahun;
}

/**
 * @return list<array{month:int,year:int,label:string,start_date:string,end_date:string,ym:string}>
 */
function skbt_periode_bulan_list(int $tahunSyawal): array
{
    $out = [];
    foreach (skbt_bulan_urutan_ta() as $m) {
        $y = $m >= 10 ? $tahunSyawal : $tahunSyawal + 1;
        $out[] = [
            'month' => $m,
            'year' => $y,
            'ym' => sprintf('%04d-%02d', $y, $m),
            'label' => skbt_bulan_label_cetak($m, $y),
        ];
    }

    return $out;
}

/** Rentang masehi seluruh periode TA dari tahun Syawal (cache per request). */
function skbt_periode_rentang_masehi(PDO $pdo, int $tahunSyawal): array
{
    static $cache = [];
    if (isset($cache[$tahunSyawal])) {
        return $cache[$tahunSyawal];
    }

    $bulanList = skbt_periode_bulan_list($tahunSyawal);
    $first = $bulanList[0];
    $last = $bulanList[count($bulanList) - 1];
    [$start] = akademik_gregorian_range_from_hijri_month($pdo, (int) $first['year'], (int) $first['month']);
    [, $end] = akademik_gregorian_range_from_hijri_month($pdo, (int) $last['year'], (int) $last['month']);

    $cache[$tahunSyawal] = [
        'tahun_syawal' => $tahunSyawal,
        'bulan_list' => $bulanList,
        'start_date' => $start,
        'end_date' => $end,
        'label' => 'Syawal ' . $tahunSyawal . ' — Ramadhan ' . ($tahunSyawal + 1),
    ];

    return $cache[$tahunSyawal];
}

function skbt_nomor_surat(int $santriId, int $tahunSyawal, int $periodeKe = 0): string
{
    $pk = $periodeKe > 0 ? $periodeKe : 1;

    return 'SKBT/' . $santriId . '/P' . $pk . '/' . $tahunSyawal . '-' . ($tahunSyawal + 1);
}

/**
 * @param bool $full Sertakan riwayat tingkatan & wali kelas (untuk cetak).
 * @return array<string,mixed>|null
 */
function skbt_santri_profil(PDO $pdo, int $santriId, bool $full = false): ?array
{
    if ($santriId <= 0 || !table_exists($pdo, 'santri')) {
        return null;
    }
    ensure_santri_identity_columns($pdo);
    $namaCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $cols = ['id', 'nis', $namaCol . ' AS nama_santri', 'tingkatan', 'tanggal_masuk', 'nama_ayah',
        'dusun', 'rt_rw', 'desa_kelurahan', 'kecamatan', 'kabupaten', 'propinsi', 'nama_kamar'];
    $st = $pdo->prepare('SELECT ' . implode(', ', $cols) . ' FROM santri WHERE id = :id LIMIT 1');
    $st->execute(['id' => $santriId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }

    $parts = array_filter([
        trim((string) ($row['dusun'] ?? '')),
        trim((string) ($row['rt_rw'] ?? '')),
        trim((string) ($row['desa_kelurahan'] ?? '')),
        trim((string) ($row['kecamatan'] ?? '')),
        trim((string) ($row['kabupaten'] ?? '')),
        trim((string) ($row['propinsi'] ?? '')),
    ], static fn (string $v): bool => $v !== '');
    $row['alamat_gabung'] = implode(', ', $parts);
    $tglMasuk = trim((string) ($row['tanggal_masuk'] ?? ''));
    $row['tahun_masuk'] = $tglMasuk !== '' && preg_match('/^(\d{4})/', $tglMasuk, $m) ? (int) $m[1] : null;

    if ($full && function_exists('santri_riwayat_ringkasan')) {
        require_once __DIR__ . '/santri_riwayat.php';
        $ringkas = santri_riwayat_ringkasan($pdo, $row);
        $row['tahun_ke'] = (int) ($ringkas['jumlah_tahun_tingkatan'] ?? 0);
    } else {
        $row['tahun_ke'] = $row['tahun_masuk'] ? max(1, (int) date('Y') - (int) $row['tahun_masuk'] + 1) : 1;
    }
    if ($row['tahun_ke'] <= 0) {
        $row['tahun_ke'] = 1;
    }

    if ($full) {
        require_once __DIR__ . '/akademik_rapor.php';
        $row['wali_kelas'] = rapor_wali_kelas_santri($pdo, $santriId, trim((string) ($row['tingkatan'] ?? '')));
    } else {
        $row['wali_kelas'] = '';
    }

    return $row;
}

function skbt_santri_tingkatan(PDO $pdo, int $santriId): string
{
    if ($santriId <= 0 || !table_exists($pdo, 'santri')) {
        return '';
    }
    $st = $pdo->prepare('SELECT tingkatan FROM santri WHERE id = :id LIMIT 1');
    $st->execute(['id' => $santriId]);

    return trim((string) ($st->fetchColumn() ?: ''));
}

/**
 * Kegiatan terjadwal untuk tingkatan santri (santri → tingkatan → kegiatan), sama seperti scan/jadwal.
 *
 * @return array<int, array{kegiatan_id:int,nama_kegiatan:string,kategori_kegiatan:string}>
 */
function skbt_kegiatan_jadwal_untuk_tingkatan(PDO $pdo, string $tingkatan): array
{
    $tingkatan = trim($tingkatan);
    if ($tingkatan === '' || !table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan')) {
        return [];
    }
    ensure_kegiatan_kategori_column($pdo);

    static $cache = [];
    $cacheKey = strtolower($tingkatan);
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }

    $st = $pdo->prepare('
        SELECT DISTINCT
            k.id AS kegiatan_id,
            k.nama_kegiatan,
            COALESCE(k.kategori_kegiatan, "TAALIM") AS kategori_kegiatan
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id AND COALESCE(k.is_active, 1) = 1
        WHERE j.tingkatan = "Semua Tingkatan"
           OR LOWER(TRIM(j.tingkatan)) = LOWER(TRIM(:tk))
        ORDER BY k.kategori_kegiatan ASC, k.nama_kegiatan ASC
    ');
    $st->execute(['tk' => $tingkatan]);
    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $id = (int) ($row['kegiatan_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $map[$id] = [
            'kegiatan_id' => $id,
            'nama_kegiatan' => trim((string) ($row['nama_kegiatan'] ?? '')),
            'kategori_kegiatan' => strtoupper(trim((string) ($row['kategori_kegiatan'] ?? 'TAALIM'))),
        ];
    }
    $cache[$cacheKey] = $map;

    return $map;
}

/** Baris presensi valid untuk tingkatan santri pada tanggal kegiatan tersebut. */
function skbt_presensi_baris_cocok_tingkatan(PDO $pdo, array $row, string $santriTingkatan, array $jadwalKegiatan): bool
{
    $kegiatanId = (int) ($row['kegiatan_id'] ?? 0);
    $tanggal = (string) ($row['tanggal_presensi'] ?? '');
    if ($kegiatanId <= 0 || $tanggal === '') {
        return false;
    }

    $santriTingkatan = trim($santriTingkatan);
    if ($jadwalKegiatan !== [] && !isset($jadwalKegiatan[$kegiatanId])) {
        return false;
    }
    if ($santriTingkatan === '') {
        return true;
    }

    return presensi_tingkatan_terjadwal($pdo, $santriTingkatan, $kegiatanId, $tanggal);
}

/**
 * Ambil baris presensi santri: santri → tingkatan → kegiatan jadwal → presensi.
 *
 * @return list<array<string,mixed>>
 */
function skbt_fetch_presensi_filtered(
    PDO $pdo,
    int $santriId,
    string $startDate,
    string $endDate,
    string $santriTingkatan
): array {
    if ($santriId <= 0 || !table_exists($pdo, 'presensi')) {
        return [];
    }

    $santriTingkatan = trim($santriTingkatan);
    $jadwalKegiatan = skbt_kegiatan_jadwal_untuk_tingkatan($pdo, $santriTingkatan);

    $st = $pdo->prepare('
        SELECT
            p.kegiatan_id,
            p.tanggal_presensi,
            TRIM(COALESCE(p.kalender_hijriyah, "")) AS kalender_hijriyah,
            UPPER(TRIM(p.status_presensi)) AS status_presensi,
            COALESCE(k.nama_kegiatan, CONCAT("Kegiatan #", p.kegiatan_id)) AS nama_kegiatan,
            COALESCE(k.kategori_kegiatan, "TAALIM") AS kategori_kegiatan
        FROM presensi p
        LEFT JOIN kegiatan k ON k.id = p.kegiatan_id
        WHERE p.santri_id = :sid
          AND p.tanggal_presensi BETWEEN :start AND :end
          AND p.kegiatan_id IS NOT NULL
          AND p.kegiatan_id > 0
        ORDER BY p.tanggal_presensi ASC
    ');
    $st->execute(['sid' => $santriId, 'start' => $startDate, 'end' => $endDate]);

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        if (!skbt_presensi_baris_cocok_tingkatan($pdo, $row, $santriTingkatan, $jadwalKegiatan)) {
            continue;
        }
        $kid = (int) ($row['kegiatan_id'] ?? 0);
        if (isset($jadwalKegiatan[$kid])) {
            $row['nama_kegiatan'] = $jadwalKegiatan[$kid]['nama_kegiatan'];
            $row['kategori_kegiatan'] = $jadwalKegiatan[$kid]['kategori_kegiatan'];
        }
        $out[] = $row;
    }

    return $out;
}

/**
 * Template blok kegiatan kosong per jadwal tingkatan.
 *
 * @param array<int, array<string,mixed>> $kegiatanJadwal
 * @param array<string, array<string,mixed>> $ymKeys
 * @return array<string, array<string,mixed>>
 */
function skbt_kegmap_dari_jadwal(array $kegiatanJadwal, array $ymKeys): array
{
    $kegMap = [];
    foreach ($kegiatanJadwal as $jk) {
        $kid = (int) ($jk['kegiatan_id'] ?? 0);
        $nama = trim((string) ($jk['nama_kegiatan'] ?? ''));
        $kat = strtoupper(trim((string) ($jk['kategori_kegiatan'] ?? 'TAALIM')));
        if ($kid <= 0 || $nama === '') {
            continue;
        }
        $key = (string) $kid;
        $kegMap[$key] = [
            'kegiatan_id' => $kid,
            'nama_kegiatan' => $nama,
            'kategori' => $kat,
            'bulan' => [],
            'total_hadir' => 0,
            'total_izin' => 0,
            'total_sakit' => 0,
            'total_ghoib' => 0,
            'total' => 0,
        ];
        foreach ($ymKeys as $ym => $bl) {
            $kegMap[$key]['bulan'][$ym] = [
                'label' => $bl['label'],
                'hadir' => 0,
                'izin' => 0,
                'sakit' => 0,
                'ghoib' => 0,
                'total' => 0,
                'nilai' => 'BAIK',
            ];
        }
    }

    return $kegMap;
}

/**
 * Ringkasan penilaian otomatis dari agregasi presensi.
 *
 * @param list<array<string,mixed>> $kegiatanList
 * @return array<string,mixed>
 */
function skbt_ringkasan_penilaian(array $kegiatanList, int $goodMax, int $mediumMax): array
{
    $tot = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'ghoib' => 0, 'kuota' => 0];
    $perKat = [];
    foreach ($kegiatanList as $kg) {
        $kat = (string) ($kg['kategori'] ?? 'TAALIM');
        if (!isset($perKat[$kat])) {
            $perKat[$kat] = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'ghoib' => 0, 'kuota' => 0, 'jumlah_kegiatan' => 0];
        }
        $perKat[$kat]['jumlah_kegiatan']++;
        $perKat[$kat]['hadir'] += (int) ($kg['total_hadir'] ?? 0);
        $perKat[$kat]['izin'] += (int) ($kg['total_izin'] ?? 0);
        $perKat[$kat]['sakit'] += (int) ($kg['total_sakit'] ?? 0);
        $perKat[$kat]['ghoib'] += (int) ($kg['total_ghoib'] ?? 0);
        $perKat[$kat]['kuota'] += (int) ($kg['total'] ?? 0);
        $tot['hadir'] += (int) ($kg['total_hadir'] ?? 0);
        $tot['izin'] += (int) ($kg['total_izin'] ?? 0);
        $tot['sakit'] += (int) ($kg['total_sakit'] ?? 0);
        $tot['ghoib'] += (int) ($kg['total_ghoib'] ?? 0);
        $tot['kuota'] += (int) ($kg['total'] ?? 0);
    }
    foreach ($perKat as $kat => &$row) {
        $row['nilai'] = skbt_nilai_form_kode((int) $row['ghoib'], $goodMax, $mediumMax);
        $row['label_nilai'] = skbt_nilai_label_human((string) $row['nilai']);
    }
    unset($row);
    $tot['nilai'] = skbt_nilai_form_kode((int) $tot['ghoib'], $goodMax, $mediumMax);
    $tot['label_nilai'] = skbt_nilai_label_human((string) $tot['nilai']);

    return ['total' => $tot, 'per_kategori' => $perKat];
}

function skbt_nilai_label_human(string $kode): string
{
    return match (strtoupper(trim($kode))) {
        'BAIK' => 'Baik',
        'SEDANG' => 'Sedang',
        default => 'Buruk',
    };
}

/** Nilai form penilaian keaktifan: BAIK / SEDANG / BURUK (dari jumlah GHOIB). */
function skbt_nilai_form_kode(int $ghoib, int $goodMax, int $mediumMax): string
{
    $cat = strtolower(santri_category($ghoib, $goodMax, $mediumMax));

    return match ($cat) {
        'bagus', 'baik' => 'BAIK',
        'sedang' => 'SEDANG',
        default => 'BURUK',
    };
}

/** @return array{baik_max:int,sedang_max:int,legend:string} */
function skbt_penilaian_legend(PDO $pdo): array
{
    $baikMax = (int) app_setting($pdo, 'kategori_baik_max', '1');
    $sedangMax = (int) app_setting($pdo, 'kategori_sedang_max', '3');
    $legend = sprintf(
        'BAIK: 0–%d GHOIB · SEDANG: %d–%d GHOIB · BURUK: lebih dari %d GHOIB',
        $baikMax,
        $baikMax + 1,
        $sedangMax,
        $sedangMax
    );

    return ['baik_max' => $baikMax, 'sedang_max' => $sedangMax, 'legend' => $legend];
}

/**
 * Ringkasan jumlah kegiatan per kategori (halaman index, tanpa agregasi penuh).
 *
 * @return array{periode:array<string,mixed>,disiplin_kelas:int,presensi_jamaah:int,lainnya:int}
 */
function skbt_preview_counts(PDO $pdo, int $santriId, int $tahunSyawal): array
{
    $periode = skbt_periode_rentang_masehi($pdo, $tahunSyawal);
    $tingkatan = skbt_santri_tingkatan($pdo, $santriId);
    $out = [
        'periode' => $periode,
        'tingkatan' => $tingkatan,
        'disiplin_kelas' => 0,
        'presensi_jamaah' => 0,
        'lainnya' => 0,
    ];
    if ($santriId <= 0) {
        return $out;
    }

    $kegByKat = [];
    foreach (skbt_fetch_presensi_filtered($pdo, $santriId, (string) $periode['start_date'], (string) $periode['end_date'], $tingkatan) as $row) {
        $kat = strtoupper(trim((string) ($row['kategori_kegiatan'] ?? 'TAALIM')));
        $nama = trim((string) ($row['nama_kegiatan'] ?? ''));
        if ($nama === '') {
            continue;
        }
        $kegByKat[$kat][$nama] = true;
    }
    foreach ($kegByKat as $kat => $names) {
        $n = count($names);
        if ($kat === 'JAMAAH') {
            $out['presensi_jamaah'] = $n;
        } elseif ($kat === 'TAALIM') {
            $out['disiplin_kelas'] = $n;
        } else {
            $out['lainnya'] += $n;
        }
    }

    return $out;
}

/**
 * Agregasi presensi santri per kegiatan × bulan hijriyah.
 *
 * @return array{
 *   periode:array<string,mixed>,
 *   kegiatan:list<array<string,mixed>>,
 *   disiplin_kelas:list<array<string,mixed>>,
 *   presensi_jamaah:list<array<string,mixed>>,
 *   lainnya:list<array<string,mixed>>
 * }
 */
/** Cache sesi hasil agregasi (cetak ulang santri+TA yang sama). */
function skbt_build_laporan_cached(PDO $pdo, int $santriId, int $tahunSyawal, bool $forceRefresh = false): array
{
    $tingkatan = skbt_santri_tingkatan($pdo, $santriId);
    $cacheKey = 'skbt_laporan_v10_' . $santriId . '_' . $tahunSyawal . '_' . md5(strtolower($tingkatan));
    if (!$forceRefresh && !empty($_SESSION[$cacheKey]) && is_array($_SESSION[$cacheKey])) {
        return $_SESSION[$cacheKey];
    }
    $laporan = skbt_build_laporan($pdo, $santriId, $tahunSyawal, $tingkatan);
    $_SESSION[$cacheKey] = $laporan;

    return $laporan;
}

function skbt_build_laporan(PDO $pdo, int $santriId, int $tahunSyawal, ?string $tingkatan = null): array
{
    $periode = skbt_periode_rentang_masehi($pdo, $tahunSyawal);
    $tingkatan = trim($tingkatan ?? skbt_santri_tingkatan($pdo, $santriId));
    $kegiatanJadwal = skbt_kegiatan_jadwal_untuk_tingkatan($pdo, $tingkatan);
    $penilaian = skbt_penilaian_legend($pdo);
    $goodMax = $penilaian['baik_max'];
    $mediumMax = $penilaian['sedang_max'];
    $ymKeys = [];
    foreach ($periode['bulan_list'] as $bl) {
        $ymKeys[$bl['ym']] = $bl;
    }

    $rawRows = skbt_fetch_presensi_filtered(
        $pdo,
        $santriId,
        (string) $periode['start_date'],
        (string) $periode['end_date'],
        $tingkatan
    );

    /** @var array<string, string> $hijriByDate */
    $hijriByDate = [];

    $kegMap = skbt_kegmap_dari_jadwal($kegiatanJadwal, $ymKeys);
    if ($kegMap === [] && $rawRows !== []) {
        foreach ($rawRows as $row) {
            $kid = (int) ($row['kegiatan_id'] ?? 0);
            if ($kid <= 0) {
                continue;
            }
            $kegiatanJadwal[$kid] = [
                'kegiatan_id' => $kid,
                'nama_kegiatan' => trim((string) ($row['nama_kegiatan'] ?? '')),
                'kategori_kegiatan' => strtoupper(trim((string) ($row['kategori_kegiatan'] ?? 'TAALIM'))),
            ];
        }
        $kegMap = skbt_kegmap_dari_jadwal($kegiatanJadwal, $ymKeys);
    }

    foreach ($rawRows as $row) {
        $kid = (int) ($row['kegiatan_id'] ?? 0);
        if ($kid <= 0 || !isset($kegMap[(string) $kid])) {
            continue;
        }
        $key = (string) $kid;
        $ym = trim((string) ($row['kalender_hijriyah'] ?? ''));
        if ($ym === '' || !isset($ymKeys[$ym])) {
            $tgl = (string) ($row['tanggal_presensi'] ?? '');
            if ($tgl !== '') {
                if (!isset($hijriByDate[$tgl])) {
                    $h = konversiKeHijriah($pdo, $tgl);
                    $hijriByDate[$tgl] = is_array($h)
                        ? sprintf('%04d-%02d', (int) ($h['tahun_hijriah'] ?? 0), (int) ($h['bulan_hijriyah'] ?? 0))
                        : '';
                }
                $ym = $hijriByDate[$tgl];
            }
        }
        if ($ym === '' || !isset($kegMap[$key]['bulan'][$ym])) {
            continue;
        }
        $stPres = strtoupper(trim((string) ($row['status_presensi'] ?? '')));
        if (!in_array($stPres, ['HADIR', 'IZIN', 'SAKIT', 'ALPA'], true)) {
            continue;
        }
        $bucket = $stPres === 'ALPA' ? 'ghoib' : strtolower($stPres);
        $kegMap[$key]['bulan'][$ym][$bucket]++;
        $kegMap[$key]['bulan'][$ym]['total']++;
        $kegMap[$key]['total']++;
        if ($stPres === 'HADIR') {
            $kegMap[$key]['total_hadir']++;
            $kegMap[$key]['bulan'][$ym]['hadir']++;
        } elseif ($stPres === 'IZIN') {
            $kegMap[$key]['total_izin']++;
        } elseif ($stPres === 'SAKIT') {
            $kegMap[$key]['total_sakit']++;
        } else {
            $kegMap[$key]['total_ghoib']++;
        }
    }

    foreach ($kegMap as &$kg) {
        $kg['bulan_aktif'] = [];
        foreach ($kg['bulan'] as $ym => &$bm) {
            $bm['nilai'] = skbt_nilai_form_kode((int) $bm['ghoib'], $goodMax, $mediumMax);
            $bm['label_nilai'] = skbt_nilai_label_human((string) $bm['nilai']);
            if ((int) ($bm['total'] ?? 0) > 0) {
                $kg['bulan_aktif'][$ym] = $bm;
            }
        }
        unset($bm);
        $kg['nilai_keseluruhan'] = skbt_nilai_form_kode((int) $kg['total_ghoib'], $goodMax, $mediumMax);
        $kg['label_nilai'] = skbt_nilai_label_human((string) $kg['nilai_keseluruhan']);
        $kg['subjudul'] = sprintf(
            'Hadir %d · Ijin %d · Sakit %d · Ghoib %d · Nilai %s',
            (int) $kg['total_hadir'],
            (int) $kg['total_izin'],
            (int) $kg['total_sakit'],
            (int) $kg['total_ghoib'],
            (string) $kg['nilai_keseluruhan']
        );
    }
    unset($kg);

    $kegMap = array_filter($kegMap, static fn (array $kg): bool => (int) ($kg['total'] ?? 0) > 0);

    uasort($kegMap, static function (array $a, array $b): int {
        $oa = skbt_urutan_kegiatan((string) $a['nama_kegiatan'], (string) $a['kategori']);
        $ob = skbt_urutan_kegiatan((string) $b['nama_kegiatan'], (string) $b['kategori']);

        return $oa <=> $ob ?: strcmp((string) $a['nama_kegiatan'], (string) $b['nama_kegiatan']);
    });

    $disiplin = [];
    $jamaah = [];
    $lainnya = [];
    foreach ($kegMap as $kg) {
        $kat = (string) ($kg['kategori'] ?? 'TAALIM');
        if ($kat === 'JAMAAH') {
            $jamaah[] = $kg;
        } elseif ($kat === 'TAALIM') {
            $disiplin[] = $kg;
        } else {
            $lainnya[] = $kg;
        }
    }

    $semuaKegiatan = array_values($kegMap);
    $ringkasan = skbt_ringkasan_penilaian($semuaKegiatan, $goodMax, $mediumMax);

    return [
        'periode' => $periode,
        'tingkatan' => $tingkatan,
        'kegiatan_jadwal' => array_values($kegiatanJadwal),
        'penilaian' => $penilaian,
        'ringkasan_penilaian' => $ringkasan,
        'kegiatan' => $semuaKegiatan,
        'disiplin_kelas' => $disiplin,
        'presensi_jamaah' => $jamaah,
        'lainnya' => $lainnya,
    ];
}

/** Urutan tampilan kegiatan jamaah. */
function skbt_urutan_kegiatan(string $nama, string $kategori): int
{
    if ($kategori !== 'JAMAAH') {
        return 100;
    }
    $n = strtolower($nama);
    $order = ['subuh' => 1, 'dhuhur' => 2, 'dhuha' => 2, 'asar' => 3, 'magrib' => 4, 'maghrib' => 4, 'isya' => 5];
    foreach ($order as $needle => $prio) {
        if (str_contains($n, $needle)) {
            return $prio;
        }
    }

    return 50;
}

/** Baris teks satu bulan untuk blok disiplin/jamaah. */
function skbt_format_bulan_presensi_line(array $bulanRow): string
{
    return sprintf(
        '%s : HADIR %d, IJIN %d, SAKIT %d, GHOIB %d, NILAI %s.',
        (string) ($bulanRow['label'] ?? ''),
        (int) ($bulanRow['hadir'] ?? 0),
        (int) ($bulanRow['izin'] ?? 0),
        (int) ($bulanRow['sakit'] ?? 0),
        (int) ($bulanRow['ghoib'] ?? 0),
        (string) ($bulanRow['nilai'] ?? '—')
    );
}

/** Tahun Syawal default: tahun hijri bulan Syawal dari hari ini. */
function skbt_tahun_syawal_default(PDO $pdo): int
{
    $anchor = akademik_hijri_anchor_hari_ini($pdo);

    return (int) ($anchor['m'] ?? 10) >= 10 ? (int) $anchor['y'] : (int) $anchor['y'] - 1;
}
