<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/akademik.php';
require_once __DIR__ . '/hijri_kalender.php';
require_once __DIR__ . '/santri_operasional.php';
require_once __DIR__ . '/presensi_jadwal.php';
require_once __DIR__ . '/penilaian_kehadiran.php';

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

function skbt_nomor_surat(PDO $pdo, int $santriId, int $tahunSyawal, int $periodeKe = 0, ?string $taMasehiLabel = null): string
{
    require_once __DIR__ . '/skbt_settings.php';

    return skbt_nomor_surat_allocate($pdo, $santriId, $tahunSyawal, $periodeKe, $taMasehiLabel);
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
    if (column_exists($pdo, 'santri', 'wali_santri_id')) {
        $cols[] = 'wali_santri_id';
    }
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

    $selectExtra = column_exists($pdo, 'presensi', 'catatan') ? ', p.catatan' : ', "" AS catatan';
    $selectExtra .= column_exists($pdo, 'presensi', 'jam_presensi') ? ', p.jam_presensi' : ', NULL AS jam_presensi';
    $joinJadwal = '';
    if (column_exists($pdo, 'presensi', 'jadwal_kegiatan_id') && table_exists($pdo, 'jadwal_kegiatan')) {
        $selectExtra .= ', COALESCE(j.jam_mulai, "") AS jam_mulai_jadwal';
        $joinJadwal = ' LEFT JOIN jadwal_kegiatan j ON j.id = p.jadwal_kegiatan_id';
    } else {
        $selectExtra .= ', "" AS jam_mulai_jadwal';
    }

    $st = $pdo->prepare('
        SELECT
            p.kegiatan_id,
            p.tanggal_presensi,
            TRIM(COALESCE(p.kalender_hijriyah, "")) AS kalender_hijriyah,
            UPPER(TRIM(p.status_presensi)) AS status_presensi,
            COALESCE(k.nama_kegiatan, CONCAT("Kegiatan #", p.kegiatan_id)) AS nama_kegiatan,
            COALESCE(k.kategori_kegiatan, "TAALIM") AS kategori_kegiatan
            ' . $selectExtra . '
        FROM presensi p
        LEFT JOIN kegiatan k ON k.id = p.kegiatan_id
        ' . $joinJadwal . '
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
            'total_telat' => 0,
            'total_ghoib' => 0,
            'total' => 0,
        ];
        foreach ($ymKeys as $ym => $bl) {
            $kegMap[$key]['bulan'][$ym] = [
                'label' => $bl['label'],
                'hadir' => 0,
                'izin' => 0,
                'sakit' => 0,
                'telat' => 0,
                'ghoib' => 0,
                'total' => 0,
                'jml_hari_aktiv' => 0,
                'persen' => null,
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
function skbt_ringkasan_penilaian(array $kegiatanList): array
{
    $tot = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'telat' => 0, 'ghoib' => 0, 'kuota' => 0];
    $perKat = [];
    foreach ($kegiatanList as $kg) {
        $kat = (string) ($kg['kategori'] ?? 'TAALIM');
        if (!isset($perKat[$kat])) {
            $perKat[$kat] = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'telat' => 0, 'ghoib' => 0, 'kuota' => 0, 'jumlah_kegiatan' => 0];
        }
        $perKat[$kat]['jumlah_kegiatan']++;
        $perKat[$kat]['hadir'] += (int) ($kg['total_hadir'] ?? 0);
        $perKat[$kat]['izin'] += (int) ($kg['total_izin'] ?? 0);
        $perKat[$kat]['sakit'] += (int) ($kg['total_sakit'] ?? 0);
        $perKat[$kat]['telat'] += (int) ($kg['total_telat'] ?? 0);
        $perKat[$kat]['ghoib'] += (int) ($kg['total_ghoib'] ?? 0);
        $perKat[$kat]['kuota'] += (int) ($kg['total'] ?? 0);
        $tot['hadir'] += (int) ($kg['total_hadir'] ?? 0);
        $tot['izin'] += (int) ($kg['total_izin'] ?? 0);
        $tot['sakit'] += (int) ($kg['total_sakit'] ?? 0);
        $tot['telat'] += (int) ($kg['total_telat'] ?? 0);
        $tot['ghoib'] += (int) ($kg['total_ghoib'] ?? 0);
        $tot['kuota'] += (int) ($kg['total'] ?? 0);
    }
    foreach ($perKat as $kat => &$row) {
        $row['nilai'] = skbt_nilai_form_kode(
            (int) $row['ghoib'],
            (int) $row['izin'],
            (int) $row['telat'],
            (int) $row['sakit'],
            (int) $row['kuota'],
            (int) $row['hadir']
        );
        $row['label_nilai'] = skbt_nilai_label_human((string) $row['nilai']);
        $row['jml_hari_aktiv'] = (int) $row['kuota'];
        $row['persen'] = skbt_hitung_persen_presna(
            (int) $row['ghoib'],
            (int) $row['izin'],
            (int) $row['telat'],
            (int) $row['sakit'],
            (int) $row['kuota'],
            (int) $row['hadir']
        );
    }
    unset($row);
    $tot['nilai'] = skbt_nilai_form_kode(
        (int) $tot['ghoib'],
        (int) $tot['izin'],
        (int) $tot['telat'],
        (int) $tot['sakit'],
        (int) $tot['kuota'],
        (int) $tot['hadir']
    );
    $tot['label_nilai'] = skbt_nilai_label_human((string) $tot['nilai']);
    $tot['jml_hari_aktiv'] = (int) $tot['kuota'];
    $tot['persen'] = skbt_hitung_persen_presna(
        (int) $tot['ghoib'],
        (int) $tot['izin'],
        (int) $tot['telat'],
        (int) $tot['sakit'],
        (int) $tot['kuota'],
        (int) $tot['hadir']
    );

    return ['total' => $tot, 'per_kategori' => $perKat];
}

/** Persentase kehadiran lama (hadir / jumlah hari aktiv) — tidak dipakai predikat PRESNA. */
function skbt_hitung_persen_hadir(int $hadir, int $jmlHariAktiv): ?float
{
    if ($jmlHariAktiv <= 0) {
        return null;
    }

    return round($hadir / $jmlHariAktiv * 100, 1);
}

/** Persentase kehadiran PRESNA (ABSENSI ÷ N.HARI). */
function skbt_hitung_persen_presna(int $alpa, int $izin, int $telat, int $sakit, int $nHari, int $hadir = 0): ?float
{
    if ($nHari <= 0) {
        return null;
    }

    return penilaian_kehadiran_hitung($alpa, $izin, $telat, $sakit, $nHari, $hadir)['persen'];
}

/** @param array<string,mixed> $bm */
function skbt_enrich_metrik_bulan(array &$bm): void
{
    $jml = (int) ($bm['total'] ?? 0);
    $bm['jml_hari_aktiv'] = $jml;
    $bm['persen'] = skbt_hitung_persen_presna(
        (int) ($bm['ghoib'] ?? 0),
        (int) ($bm['izin'] ?? 0),
        (int) ($bm['telat'] ?? 0),
        (int) ($bm['sakit'] ?? 0),
        $jml,
        (int) ($bm['hadir'] ?? 0)
    );
}

function skbt_nilai_label_human(string $kode): string
{
    return match (strtoupper(trim($kode))) {
        'BAIK' => 'Baik',
        'CUKUP' => 'Cukup',
        'SEDANG' => 'Sedang',
        'KURANG' => 'Kurang',
        default => 'Buruk',
    };
}

/** Nilai form kehadiran SKBT: BAIK|CUKUP|SEDANG|KURANG|BURUK (rumus PRESNA). */
function skbt_nilai_form_kode(int $alpa, int $izin, int $telat, int $sakit, int $nHari, int $hadir = 0): string
{
    $hit = penilaian_kehadiran_hitung($alpa, $izin, $telat, $sakit, $nHari, $hadir);
    $kode = penilaian_kehadiran_kode_dari_predikat((string) $hit['predikat']);

    return $kode !== '' ? $kode : 'BAIK';
}

/** @return array{baik_max:int,sedang_max:int,legend:string} */
function skbt_penilaian_legend(PDO $pdo): array
{
    return [
        'baik_max' => 0,
        'sedang_max' => 0,
        'legend' => 'PRESNA: ' . penilaian_kehadiran_rumus_absensi($pdo) . '; % = ABSENSI ÷ N.HARI. '
            . 'Baik 81–100% · Cukup 61–80% · Sedang 41–60% · Kurang 21–40% · Buruk ≤20%.',
    ];
}

/**
 * Ringkasan jumlah kegiatan per kategori (halaman index, tanpa agregasi penuh).
 *
 * @return array{periode:array<string,mixed>,disiplin_kelas:int,presensi_jamaah:int,lainnya:int}
 */
function skbt_preview_counts(PDO $pdo, int $santriId, int $tahunSyawal, ?array $periodeResolved = null): array
{
    $periode = $periodeResolved ?? skbt_periode_rentang_masehi($pdo, $tahunSyawal);
    $tingkatan = skbt_santri_tingkatan($pdo, $santriId);
    $out = [
        'periode' => $periode,
        'tingkatan' => $tingkatan,
        'disiplin_kelas' => 0,
        'presensi_jamaah' => 0,
        'lainnya' => 0,
        'ikhtibar_jumlah' => 0,
        'ikhtibar_rata_nilai' => null,
        'manual_jumlah' => 0,
        'manual_rata_nilai' => null,
        'akademik_jumlah' => 0,
        'akademik_rata_nilai' => null,
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

    $ikhtibar = skbt_ikhtibar_nilai($pdo, $santriId, $periode);
    $manual = skbt_nilai_manual($pdo, $santriId, $periode);
    $out['ikhtibar_jumlah'] = (int) ($ikhtibar['jumlah'] ?? 0);
    $out['ikhtibar_rata_nilai'] = $ikhtibar['rata_nilai'] ?? null;
    $out['manual_jumlah'] = (int) ($manual['jumlah'] ?? 0);
    $out['manual_rata_nilai'] = $manual['rata_nilai'] ?? null;
    $gabung = skbt_akademik_nilai_gabung($ikhtibar, $manual);
    $out['akademik_jumlah'] = (int) ($gabung['jumlah'] ?? 0);
    $out['akademik_rata_nilai'] = $gabung['rata_nilai'] ?? null;

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
/** Cache sesi hasil agregasi (cetak ulang santri+periode yang sama). */
function skbt_build_laporan_cached(PDO $pdo, int $santriId, int $tahunSyawal, bool $forceRefresh = false, ?array $periodeResolved = null): array
{
    $periode = $periodeResolved ?? skbt_periode_rentang_masehi($pdo, $tahunSyawal);
    $tingkatan = skbt_santri_tingkatan($pdo, $santriId);
    $cacheKey = skbt_laporan_cache_key($santriId, $periode, $tingkatan);
    if (!$forceRefresh && !empty($_SESSION[$cacheKey]) && is_array($_SESSION[$cacheKey])) {
        return $_SESSION[$cacheKey];
    }
    $laporan = skbt_build_laporan($pdo, $santriId, $tahunSyawal, $tingkatan, $periode);
    $_SESSION[$cacheKey] = $laporan;

    return $laporan;
}

function skbt_build_laporan(PDO $pdo, int $santriId, int $tahunSyawal, ?string $tingkatan = null, ?array $periodeResolved = null): array
{
    $periode = $periodeResolved ?? skbt_periode_rentang_masehi($pdo, $tahunSyawal);
    $tingkatan = trim($tingkatan ?? skbt_santri_tingkatan($pdo, $santriId));
    $kegiatanJadwal = skbt_kegiatan_jadwal_untuk_tingkatan($pdo, $tingkatan);
    $penilaian = skbt_penilaian_legend($pdo);
    $lateTolerance = penilaian_kehadiran_batas_telat($pdo);
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
        $bucket = penilaian_kehadiran_status_bucket($row, $lateTolerance);
        if ($bucket === '') {
            continue;
        }
        $bulanKey = $bucket === 'alpa' ? 'ghoib' : $bucket;
        $kegMap[$key]['bulan'][$ym][$bulanKey]++;
        $kegMap[$key]['bulan'][$ym]['total']++;
        $kegMap[$key]['total']++;
        if ($bucket === 'hadir') {
            $kegMap[$key]['total_hadir']++;
        } elseif ($bucket === 'izin') {
            $kegMap[$key]['total_izin']++;
        } elseif ($bucket === 'sakit') {
            $kegMap[$key]['total_sakit']++;
        } elseif ($bucket === 'telat') {
            $kegMap[$key]['total_telat']++;
        } else {
            $kegMap[$key]['total_ghoib']++;
        }
    }

    foreach ($kegMap as &$kg) {
        $kg['bulan_aktif'] = [];
        foreach ($kg['bulan'] as $ym => &$bm) {
            skbt_enrich_metrik_bulan($bm);
            $bm['nilai'] = skbt_nilai_form_kode(
                (int) ($bm['ghoib'] ?? 0),
                (int) ($bm['izin'] ?? 0),
                (int) ($bm['telat'] ?? 0),
                (int) ($bm['sakit'] ?? 0),
                (int) ($bm['total'] ?? 0),
                (int) ($bm['hadir'] ?? 0)
            );
            $bm['label_nilai'] = skbt_nilai_label_human((string) $bm['nilai']);
            if ((int) ($bm['total'] ?? 0) > 0) {
                $kg['bulan_aktif'][$ym] = $bm;
            }
        }
        unset($bm);
        $kg['jml_hari_aktiv'] = (int) ($kg['total'] ?? 0);
        $kg['persen'] = skbt_hitung_persen_presna(
            (int) ($kg['total_ghoib'] ?? 0),
            (int) ($kg['total_izin'] ?? 0),
            (int) ($kg['total_telat'] ?? 0),
            (int) ($kg['total_sakit'] ?? 0),
            (int) ($kg['total'] ?? 0),
            (int) ($kg['total_hadir'] ?? 0)
        );
        $kg['nilai_keseluruhan'] = skbt_nilai_form_kode(
            (int) ($kg['total_ghoib'] ?? 0),
            (int) ($kg['total_izin'] ?? 0),
            (int) ($kg['total_telat'] ?? 0),
            (int) ($kg['total_sakit'] ?? 0),
            (int) ($kg['total'] ?? 0),
            (int) ($kg['total_hadir'] ?? 0)
        );
        $kg['label_nilai'] = skbt_nilai_label_human((string) $kg['nilai_keseluruhan']);
        $kg['subjudul'] = sprintf(
            'Aktiv %d · Hadir %d · Persen %s · Kriteria %s',
            (int) $kg['jml_hari_aktiv'],
            (int) $kg['total_hadir'],
            $kg['persen'] !== null ? number_format((float) $kg['persen'], 1, ',', '') . '%' : '—',
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
    $ringkasan = skbt_ringkasan_penilaian($semuaKegiatan);
    $ikhtibarNilai = skbt_ikhtibar_nilai($pdo, $santriId, $periode);
    $manualNilai = skbt_nilai_manual($pdo, $santriId, $periode);

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
        'ikhtibar_nilai' => $ikhtibarNilai,
        'manual_nilai' => $manualNilai,
        'akademik_nilai' => skbt_akademik_nilai_gabung($ikhtibarNilai, $manualNilai),
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
        '%s : HADIR %d, TELAT %d, IJIN %d, SAKIT %d, GHOIB %d, NILAI %s.',
        (string) ($bulanRow['label'] ?? ''),
        (int) ($bulanRow['hadir'] ?? 0),
        (int) ($bulanRow['telat'] ?? 0),
        (int) ($bulanRow['izin'] ?? 0),
        (int) ($bulanRow['sakit'] ?? 0),
        (int) ($bulanRow['ghoib'] ?? 0),
        (string) ($bulanRow['nilai'] ?? '—')
    );
}

/** Baris kontak kop SKBT (alamat, telp, website/email). */
function skbt_kop_kontak_baris(array $kop): array
{
    $lines = [];
    $alamat = trim((string) ($kop['alamat_ponpes'] ?? ''));
    if ($alamat !== '') {
        $lines[] = $alamat;
    }
    $telp = trim((string) ($kop['telp_ponpes'] ?? ''));
    if ($telp !== '') {
        $lines[] = $telp;
    }
    $web = trim((string) ($kop['website_ponpes'] ?? ''));
    if ($web !== '') {
        $lines[] = $web;
    }

    return $lines;
}

/** Label periode cetak dari bulan aktif kegiatan (contoh: ZULKAIDAH 1445 - RABIUL AWAL 1446). */
function skbt_kegiatan_periode_label_cetak(array $kg, string $fallbackPeriode = ''): string
{
    $bulanAktif = $kg['bulan_aktif'] ?? [];
    if ($bulanAktif === []) {
        return $fallbackPeriode !== '' ? strtoupper($fallbackPeriode) : '';
    }
    $keys = array_keys($bulanAktif);
    sort($keys, SORT_STRING);
    $first = strtoupper(trim((string) ($bulanAktif[$keys[0]]['label'] ?? '')));
    $last = strtoupper(trim((string) ($bulanAktif[$keys[count($keys) - 1]]['label'] ?? '')));
    if ($first === '' && $last === '') {
        return $fallbackPeriode !== '' ? strtoupper($fallbackPeriode) : '';
    }
    if ($first === $last || $last === '') {
        return $first;
    }

    return $first . ' - ' . $last;
}

/** Ringkasan presensi satu kegiatan untuk cetak formal (gaya laporan SKBT). */
function skbt_kegiatan_ringkasan_line_cetak(array $kg): string
{
    $nilai = strtoupper((string) ($kg['nilai_keseluruhan'] ?? 'BAIK'));
    if ($nilai === 'BAIK') {
        $nilai = 'BAGUS';
    }

    return sprintf(
        'jumlah hari %d, HADIR %d, TELAT %d, IJIN %d, SAKIT %d, GHOIB %d, NILAI %s.',
        (int) ($kg['jml_hari_aktiv'] ?? $kg['total'] ?? 0),
        (int) ($kg['total_hadir'] ?? 0),
        (int) ($kg['total_telat'] ?? 0),
        (int) ($kg['total_izin'] ?? 0),
        (int) ($kg['total_sakit'] ?? 0),
        (int) ($kg['total_ghoib'] ?? 0),
        $nilai
    );
}

/** HTML blok presensi (Disiplin Kelas / Presensi Kegiatan). */
function skbt_presensi_prose_html(array $items, string $fallbackPeriode = ''): string
{
    unset($fallbackPeriode);
    if ($items === []) {
        return '<p class="skbt-section-placeholder">—</p>';
    }

    ob_start();
    foreach ($items as $kg) {
        if ((int) ($kg['total'] ?? 0) <= 0 && ($kg['bulan_aktif'] ?? []) === []) {
            continue;
        }
        $nama = trim((string) ($kg['nama_kegiatan'] ?? ''));
        if ($nama === '') {
            continue;
        }
        ?>
        <div class="item-block">
            <div class="item-title"><?= htmlspecialchars($nama) ?></div>
            <div class="item-sub"><?= htmlspecialchars(skbt_kegiatan_ringkasan_line_cetak($kg)) ?></div>
        </div>
        <?php
    }
    $html = (string) ob_get_clean();

    return $html !== '' ? $html : '<p class="skbt-section-placeholder">—</p>';
}

/** HTML identitas santri (JATIDIRI) dua kolom. */
function skbt_jatidiri_cetak_html(array $santri): string
{
    $tahunMasuk = $santri['tahun_masuk'] ?? null;
    $tahunMasukTampil = $tahunMasuk !== null && (int) $tahunMasuk > 0 ? (string) (int) $tahunMasuk : '—';
    $tahunKe = (int) ($santri['tahun_ke'] ?? 0);
    $alamat = trim((string) ($santri['alamat_gabung'] ?? ''));

    ob_start();
    ?>
    <table class="tbl-jatidiri">
        <tbody>
            <tr>
                <td width="15%"><b>NIS</b></td><td width="2%">:</td><td width="33%"><?= htmlspecialchars((string) ($santri['nis'] ?? '—')) ?></td>
                <td width="18%"><b>Tahun masuk</b></td><td width="2%">:</td><td width="30%"><?= htmlspecialchars($tahunMasukTampil) ?></td>
            </tr>
            <tr>
                <td><b>Nama</b></td><td>:</td><td><?= htmlspecialchars((string) ($santri['nama_santri'] ?? '—')) ?></td>
                <td><b>Tahun ke</b></td><td>:</td><td><?= htmlspecialchars($tahunKe > 0 ? (string) $tahunKe : '—') ?></td>
            </tr>
            <tr>
                <td><b>Bin</b></td><td>:</td><td><?= htmlspecialchars((string) ($santri['nama_ayah'] ?? '—')) ?></td>
                <td><b>Tingkatan saat ini</b></td><td>:</td><td><?= htmlspecialchars((string) ($santri['tingkatan'] ?? '—')) ?></td>
            </tr>
            <?php if ($alamat !== ''): ?>
            <tr>
                <td><b>Alamat</b></td><td>:</td><td colspan="4"><?= htmlspecialchars($alamat) ?></td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php

    return (string) ob_get_clean();
}

/** HTML blok tanda tangan SKBT (2×2 + Mengetahui). */
function skbt_ttd_cetak_html(
    string $waliKamar,
    string $waliKelas,
    string $namaPengasuh,
    string $namaKepalaPondok
): string {
    ob_start();
    ?>
    <div class="skbt-ttd-wrap">
        <table class="ttd-grid">
            <tr>
                <td><b>Wali Kamar</b></td>
                <td><b>Wali Kelas</b></td>
            </tr>
            <tr>
                <td class="ttd-space"></td>
                <td class="ttd-space"></td>
            </tr>
            <tr>
                <td class="ttd-nama"><?= htmlspecialchars($waliKamar !== '' ? $waliKamar : '…………………………') ?></td>
                <td class="ttd-nama"><?= htmlspecialchars($waliKelas !== '' ? $waliKelas : '…………………………') ?></td>
            </tr>
            <tr>
                <td colspan="2" style="padding-top: 10px;"><b>Mengetahui,</b></td>
            </tr>
            <tr>
                <td><b>Pengasuh</b></td>
                <td><b>Kepala Pondok</b></td>
            </tr>
            <tr>
                <td class="ttd-space"></td>
                <td class="ttd-space"></td>
            </tr>
            <tr>
                <td class="ttd-nama"><?= htmlspecialchars($namaPengasuh !== '' ? $namaPengasuh : '…………………………') ?></td>
                <td class="ttd-nama"><?= htmlspecialchars($namaKepalaPondok !== '' ? $namaKepalaPondok : '…………………………') ?></td>
            </tr>
        </table>
    </div>
    <?php

    return (string) ob_get_clean();
}

/** Tahun Syawal default: tahun hijri bulan Syawal dari hari ini. */
function skbt_tahun_syawal_default(PDO $pdo): int
{
    $anchor = akademik_hijri_anchor_hari_ini($pdo);

    return (int) ($anchor['m'] ?? 10) >= 10 ? (int) $anchor['y'] : (int) $anchor['y'] - 1;
}

/**
 * Potong daftar bulan TA (contoh: Syawal s/d Rabiul Awal).
 *
 * @param array<string,mixed> $periode
 * @return array<string,mixed>
 */
function skbt_periode_potong_bulan_ta(PDO $pdo, array $periode, int $bulanDari, int $bulanSampai): array
{
    $list = $periode['bulan_list'] ?? [];
    if ($list === []) {
        return $periode;
    }
    $order = skbt_bulan_urutan_ta();
    if ($bulanDari <= 0) {
        $bulanDari = (int) $order[0];
    }
    if ($bulanSampai <= 0) {
        $bulanSampai = (int) $order[count($order) - 1];
    }
    $startIdx = array_search($bulanDari, $order, true);
    $endIdx = array_search($bulanSampai, $order, true);
    if ($startIdx === false || $endIdx === false) {
        return $periode;
    }
    $pick = [];
    if ($startIdx <= $endIdx) {
        for ($i = $startIdx; $i <= $endIdx; $i++) {
            $pick[(int) $order[$i]] = true;
        }
    } else {
        for ($i = $startIdx; $i < count($order); $i++) {
            $pick[(int) $order[$i]] = true;
        }
        for ($i = 0; $i <= $endIdx; $i++) {
            $pick[(int) $order[$i]] = true;
        }
    }
    $filtered = array_values(array_filter($list, static fn (array $bl): bool => isset($pick[(int) ($bl['month'] ?? 0)])));
    if ($filtered === []) {
        return $periode;
    }
    $first = $filtered[0];
    $last = $filtered[count($filtered) - 1];
    [$startDate] = akademik_gregorian_range_from_hijri_month($pdo, (int) $first['year'], (int) $first['month']);
    [, $endDate] = akademik_gregorian_range_from_hijri_month($pdo, (int) $last['year'], (int) $last['month']);
    $periode['bulan_list'] = $filtered;
    $periode['start_date'] = $startDate;
    $periode['end_date'] = $endDate;
    $periode['label'] = (string) ($first['label'] ?? '') . ' — ' . (string) ($last['label'] ?? '');

    return $periode;
}

/** Label singkat bulan hijriyah untuk filter TA. */
function skbt_bulan_hijri_singkat(): array
{
    return [
        1 => 'Muharam', 2 => 'Safar', 3 => 'R.Awal', 4 => 'R.Akhir',
        5 => 'J.Awal', 6 => 'J.Akhir', 7 => 'Rajab', 8 => 'Syakban',
        9 => 'Ramadhan', 10 => 'Syawal', 11 => 'Zulkaidah', 12 => 'Zulhijah',
    ];
}

function skbt_format_persen_tampilan(?float $persen): string
{
    if ($persen === null) {
        return '—';
    }

    return number_format($persen, 1, ',', '') . '%';
}

/** URL logo pondok absolut (andalkan saat cetak). */
function skbt_logo_abs_url(PDO $pdo, ?array $kop = null): string
{
    if ($kop === null) {
        require_once __DIR__ . '/pondok_cetak.php';
        $kop = pondok_kop_data($pdo);
    }
    $logo = trim((string) ($kop['logo_href'] ?? ''));
    if ($logo === '') {
        $logo = app_pondok_logo_href($pdo, true);
    }
    if ($logo !== '' && !preg_match('#^https?://#i', $logo)) {
        $logo = app_href(str_starts_with($logo, '/') ? $logo : '/' . ltrim($logo, '/'));
    }
    if ($logo !== '' && !preg_match('#^https?://#i', $logo) && !empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $logo = $scheme . '://' . $_SERVER['HTTP_HOST'] . $logo;
    }

    return $logo;
}

/** HTML kop formal khusus SKBT (F4) — garis hijau, logo kiri, judul SKBT, kontak. */
function skbt_kop_surat_html(PDO $pdo, array $kop, string $nomor = ''): string
{
    unset($nomor);
    $accent = trim((string) ($kop['kop_accent_color'] ?? '#38a169')) ?: '#38a169';
    $logo = skbt_logo_abs_url($pdo, $kop);
    $nama = trim((string) ($kop['nama_ponpes'] ?? 'Pondok Pesantren'));
    $judulUtama = 'SKBT ' . strtoupper($nama);
    $kontakBaris = skbt_kop_kontak_baris($kop);
    $alamatHtml = implode('<br>', array_map(static fn (string $b): string => htmlspecialchars($b), $kontakBaris));

    ob_start();
    ?>
    <div class="kop-container" style="--skbt-accent: <?= htmlspecialchars($accent, ENT_QUOTES) ?>">
        <div class="garis-atas"></div>
        <table class="tbl-kop">
            <tr>
                <td class="td-logo">
                    <?php if ($logo !== ''): ?>
                        <img src="<?= htmlspecialchars($logo) ?>" class="logo-img" alt="Logo">
                    <?php endif; ?>
                </td>
                <td>
                    <div class="judul-utama"><?= htmlspecialchars($judulUtama) ?></div>
                    <div class="sub-judul">Surat Keterangan Belajar dan Tingkatan</div>
                    <?php if ($alamatHtml !== ''): ?>
                        <div class="alamat-kop"><?= $alamatHtml ?></div>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
    </div>
    <?php

    return (string) ob_get_clean();
}

function skbt_nomor_surat_html(string $nomor): string
{
    return '<div class="doc-nomor">NOMOR: ' . htmlspecialchars($nomor) . '</div>';
}
/**
 * Resolve periode SKBT: TA penuh, per bulan, atau rentang tanggal.
 *
 * @return array<string,mixed>
 */
function skbt_resolve_periode(PDO $pdo, array $get): array
{
    require_once __DIR__ . '/rekap_periode.php';

    $mode = strtolower(trim((string) ($get['periode_mode'] ?? 'ta_penuh')));
    if (!in_array($mode, ['ta_penuh', 'bulan', 'rentang'], true)) {
        $mode = 'ta_penuh';
    }

    if ($mode === 'bulan') {
        $p = rekap_resolve_periode($pdo, $get);
        $ym = (string) ($p['kalender_hijriyah_key'] ?? '');
        if ($ym === '') {
            $ym = sprintf('%04d-%02d', (int) $p['year'], (int) $p['month']);
        }

        return [
            'mode' => 'bulan',
            'tahun_syawal' => null,
            'start_date' => (string) $p['start_date'],
            'end_date' => (string) $p['end_date'],
            'label' => (string) $p['label'],
            'rentang_tampilan' => (string) $p['rentang_tampilan'],
            'bulan_list' => [[
                'month' => (int) $p['month'],
                'year' => (int) $p['year'],
                'ym' => $ym,
                'label' => (string) ($p['hijri_label'] ?? $p['label']),
                'start_date' => (string) $p['start_date'],
                'end_date' => (string) $p['end_date'],
            ]],
            'calendar_mode' => (string) $p['mode'],
        ];
    }

    if ($mode === 'rentang') {
        $dari = trim((string) ($get['dari'] ?? ''));
        $sampai = trim((string) ($get['sampai'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dari)) {
            $dari = date('Y-m-01');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) {
            $sampai = date('Y-m-d');
        }
        if ($sampai < $dari) {
            [$dari, $sampai] = [$sampai, $dari];
        }
        $bulanList = skbt_bulan_list_dari_rentang($pdo, $dari, $sampai);

        return [
            'mode' => 'rentang',
            'tahun_syawal' => null,
            'start_date' => $dari,
            'end_date' => $sampai,
            'label' => rekap_format_rentang_tampilan($dari, $sampai),
            'rentang_tampilan' => rekap_format_rentang_tampilan($dari, $sampai),
            'bulan_list' => $bulanList,
            'calendar_mode' => 'masehi',
        ];
    }

    $tahunSyawal = (int) ($get['tahun_syawal'] ?? skbt_tahun_syawal_default($pdo));
    if ($tahunSyawal < 1300 || $tahunSyawal > 1500) {
        $tahunSyawal = skbt_tahun_syawal_default($pdo);
    }
    $periode = skbt_periode_rentang_masehi($pdo, $tahunSyawal);
    $bulanDari = max(0, min(12, (int) ($get['bulan_dari'] ?? 0)));
    $bulanSampai = max(0, min(12, (int) ($get['bulan_sampai'] ?? 0)));
    if ($bulanDari > 0 || $bulanSampai > 0) {
        $periode = skbt_periode_potong_bulan_ta($pdo, $periode, $bulanDari, $bulanSampai);
    }
    $periode['bulan_dari'] = $bulanDari;
    $periode['bulan_sampai'] = $bulanSampai;
    $periode['mode'] = 'ta_penuh';
    $periode['rentang_tampilan'] = rekap_format_rentang_tampilan(
        (string) $periode['start_date'],
        (string) $periode['end_date']
    );

    return $periode;
}

/**
 * @return list<array{month:int,year:int,label:string,start_date:string,end_date:string,ym:string}>
 */
function skbt_bulan_list_dari_rentang(PDO $pdo, string $startDate, string $endDate): array
{
    $seen = [];
    $out = [];
    $cur = strtotime($startDate);
    $endTs = strtotime($endDate);
    if ($cur === false || $endTs === false) {
        return [];
    }
    while ($cur <= $endTs) {
        $d = date('Y-m-d', $cur);
        $h = konversiKeHijriah($pdo, $d);
        if (is_array($h)) {
            $m = (int) ($h['bulan_hijriyah'] ?? 0);
            $y = (int) ($h['tahun_hijriah'] ?? 0);
            if ($m >= 1 && $m <= 12 && $y > 0) {
                $ym = sprintf('%04d-%02d', $y, $m);
                if (!isset($seen[$ym])) {
                    $seen[$ym] = true;
                    [$ms, $me] = akademik_gregorian_range_from_hijri_month($pdo, $y, $m);
                    $out[] = [
                        'month' => $m,
                        'year' => $y,
                        'ym' => $ym,
                        'label' => skbt_bulan_label_cetak($m, $y),
                        'start_date' => max($ms, $startDate),
                        'end_date' => min($me, $endDate),
                    ];
                }
            }
        }
        $cur = strtotime('+1 day', $cur) ?: false;
    }

    return $out;
}

function skbt_laporan_cache_key(int $santriId, array $periode, string $tingkatan): string
{
    $payload = ($periode['mode'] ?? 'ta_penuh') . '|'
        . ($periode['start_date'] ?? '') . '|'
        . ($periode['end_date'] ?? '') . '|'
        . strtolower($tingkatan);

    return 'skbt_laporan_v13_' . $santriId . '_' . md5($payload);
}

/**
 * Nilai ikhtibar santri pada periode tertentu (untuk SKBT).
 *
 * @return array{groups:list<array<string,mixed>>,flat:list<array<string,mixed>>,jumlah:int,rata_nilai:?float}
 */
function skbt_ikhtibar_nilai(PDO $pdo, int $santriId, array $periode): array
{
    $empty = ['groups' => [], 'flat' => [], 'jumlah' => 0, 'rata_nilai' => null];
    if ($santriId <= 0 || !table_exists($pdo, 'ikhtibar_tugas')) {
        return $empty;
    }
    require_once __DIR__ . '/akademik_rapor.php';
    require_once __DIR__ . '/akademik_ikhtibar.php';

    $raporPeriode = [
        'start_date' => (string) ($periode['start_date'] ?? ''),
        'end_date' => (string) ($periode['end_date'] ?? ''),
    ];
    if ($raporPeriode['start_date'] === '' || $raporPeriode['end_date'] === '') {
        return $empty;
    }

    $groups = rapor_tugas_bulan($pdo, $santriId, $raporPeriode, 'IKHTIBAR');
    $flat = [];
    $totalNilai = 0.0;
    $countNilai = 0;

    foreach ($groups as &$grp) {
        foreach ($grp['tugas'] ?? [] as &$t) {
            $nilai = $t['nilai_total'] !== null ? (float) $t['nilai_total'] : null;
            $pred = ikhtibar_predikat_nilai($nilai);
            $row = [
                'sumber' => 'Ikhtibar',
                'mapel_label' => (string) ($grp['mapel_label'] ?? ''),
                'pembimbing_nama' => (string) ($grp['pembimbing_nama'] ?? ''),
                'judul' => (string) ($t['judul'] ?? ''),
                'tanggal' => (string) ($t['tanggal'] ?? ''),
                'skor_pg' => $t['skor_pg'],
                'skor_esai' => $t['skor_esai'],
                'nilai_total' => $nilai,
                'predikat' => $pred['label'],
                'predikat_class' => $pred['class'],
                'sesi_status' => (string) ($t['sesi_status'] ?? ''),
            ];
            $flat[] = $row;
            if ($nilai !== null && in_array($row['sesi_status'], ['selesai', 'habis_waktu'], true)) {
                $totalNilai += $nilai;
                $countNilai++;
            }
        }
        unset($t);
    }
    unset($grp);

    return [
        'groups' => $groups,
        'flat' => $flat,
        'jumlah' => count($flat),
        'rata_nilai' => $countNilai > 0 ? round($totalNilai / $countNilai, 2) : null,
    ];
}

/**
 * Nilai manual pembimbing santri pada periode SKBT.
 *
 * @return array{flat:list<array<string,mixed>>,jumlah:int,rata_nilai:?float}
 */
function skbt_nilai_manual(PDO $pdo, int $santriId, array $periode): array
{
    $empty = ['flat' => [], 'jumlah' => 0, 'rata_nilai' => null];
    if ($santriId <= 0 || !table_exists($pdo, 'pembimbing_nilai_manual')) {
        return $empty;
    }

    require_once __DIR__ . '/pembimbing_nilai_manual.php';
    require_once __DIR__ . '/akademik_ikhtibar.php';
    pembimbing_nilai_manual_ensure_schema($pdo);

    $start = (string) ($periode['start_date'] ?? '');
    $end = (string) ($periode['end_date'] ?? '');
    if ($start === '' || $end === '') {
        return $empty;
    }

    $aspekLabels = [
        'murod' => 'Murod',
        'makna' => 'Makna',
        'hafalan' => 'Hafalan',
    ];

    $stmt = $pdo->prepare('
        SELECT
            n.id,
            n.nilai,
            n.catatan,
            n.tanggal,
            n.aspek,
            n.kegiatan_id,
            p.nama_pembimbing,
            t.judul AS target_judul,
            t.deskripsi AS target_deskripsi,
            t.aspek AS target_aspek,
            k.nama_kegiatan
        FROM pembimbing_nilai_manual n
        INNER JOIN pembimbing p ON p.id = n.pembimbing_id
        LEFT JOIN pembimbing_penilaian_target t ON t.id = n.target_id
        LEFT JOIN kegiatan k ON k.id = n.kegiatan_id AND n.kegiatan_id > 0
        WHERE n.santri_id = :sid
          AND n.tanggal BETWEEN :start AND :end
        ORDER BY n.tanggal DESC, n.id DESC
    ');
    $stmt->execute(['sid' => $santriId, 'start' => $start, 'end' => $end]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $flat = [];
    $totalNilai = 0.0;
    $countNilai = 0;
    foreach ($rows as $r) {
        $nilai = (float) ($r['nilai'] ?? 0);
        $pred = ikhtibar_predikat_nilai($nilai);
        $aspek = strtolower(trim((string) ($r['aspek'] ?? '')));
        $aspekLabel = $aspekLabels[$aspek] ?? ($aspek !== '' ? ucfirst($aspek) : 'Umum');
        $targetJudul = trim((string) ($r['target_judul'] ?? ''));
        $kegiatan = trim((string) ($r['nama_kegiatan'] ?? ''));
        $judul = $targetJudul;
        if ($judul === '') {
            $judul = $kegiatan !== '' ? $kegiatan : $aspekLabel;
        }
        $mapelLabel = trim((string) ($r['nama_pembimbing'] ?? 'Pembimbing'));
        if ($targetJudul !== '' && $aspekLabel !== '' && strcasecmp($targetJudul, $aspekLabel) !== 0) {
            $mapelLabel .= ' · ' . $aspekLabel;
        } elseif ($kegiatan !== '' && $aspekLabel !== 'Umum') {
            $mapelLabel .= ' · ' . $aspekLabel;
        }

        $flat[] = [
            'sumber' => 'Manual',
            'mapel_label' => $mapelLabel,
            'pembimbing_nama' => trim((string) ($r['nama_pembimbing'] ?? '')),
            'judul' => $judul,
            'tanggal' => (string) ($r['tanggal'] ?? ''),
            'aspek' => $aspekLabel,
            'catatan' => trim((string) ($r['catatan'] ?? '')),
            'nilai_total' => $nilai,
            'predikat' => $pred['label'],
            'predikat_class' => $pred['class'],
        ];
        $totalNilai += $nilai;
        $countNilai++;
    }

    return [
        'flat' => $flat,
        'jumlah' => count($flat),
        'rata_nilai' => $countNilai > 0 ? round($totalNilai / $countNilai, 2) : null,
    ];
}

/**
 * Gabung nilai ikhtibar + manual untuk tampilan SKBT.
 *
 * @param array{flat:list<array<string,mixed>>,jumlah:int,rata_nilai:?float} $ikhtibar
 * @param array{flat:list<array<string,mixed>>,jumlah:int,rata_nilai:?float} $manual
 * @return array{flat:list<array<string,mixed>>,jumlah:int,rata_nilai:?float,ikhtibar_jumlah:int,manual_jumlah:int}
 */
function skbt_akademik_nilai_gabung(array $ikhtibar, array $manual): array
{
    $flat = array_merge($ikhtibar['flat'] ?? [], $manual['flat'] ?? []);
    usort($flat, static function (array $a, array $b): int {
        $cmp = strcmp((string) ($b['tanggal'] ?? ''), (string) ($a['tanggal'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) ($a['sumber'] ?? ''), (string) ($b['sumber'] ?? ''));
    });

    $totalNilai = 0.0;
    $countNilai = 0;
    foreach ($flat as $row) {
        if (!isset($row['nilai_total']) || $row['nilai_total'] === null) {
            continue;
        }
        if (($row['sumber'] ?? '') === 'Ikhtibar'
            && !in_array((string) ($row['sesi_status'] ?? ''), ['selesai', 'habis_waktu'], true)) {
            continue;
        }
        $totalNilai += (float) $row['nilai_total'];
        $countNilai++;
    }

    return [
        'flat' => $flat,
        'jumlah' => count($flat),
        'rata_nilai' => $countNilai > 0 ? round($totalNilai / $countNilai, 2) : null,
        'ikhtibar_jumlah' => (int) ($ikhtibar['jumlah'] ?? 0),
        'manual_jumlah' => (int) ($manual['jumlah'] ?? 0),
    ];
}

/** Query string periode untuk URL cetak SKBT. */
function skbt_periode_query_params(PDO $pdo, array $periode, array $extra = []): array
{
    $qs = $extra;
    $mode = (string) ($periode['mode'] ?? 'ta_penuh');
    $qs['periode_mode'] = $mode;
    if ($mode === 'ta_penuh') {
        $qs['tahun_syawal'] = (int) ($periode['tahun_syawal'] ?? skbt_tahun_syawal_default($pdo));
        if (!empty($periode['bulan_dari'])) {
            $qs['bulan_dari'] = (int) $periode['bulan_dari'];
        }
        if (!empty($periode['bulan_sampai'])) {
            $qs['bulan_sampai'] = (int) $periode['bulan_sampai'];
        }
    } elseif ($mode === 'bulan') {
        $qs['mode'] = (string) ($periode['calendar_mode'] ?? 'hijriyah');
        if (!empty($periode['bulan_list'][0])) {
            $bl = $periode['bulan_list'][0];
            $qs['month'] = (int) ($bl['month'] ?? 0);
            $qs['year'] = (int) ($bl['year'] ?? 0);
        }
    } elseif ($mode === 'rentang') {
        $qs['dari'] = (string) ($periode['start_date'] ?? '');
        $qs['sampai'] = (string) ($periode['end_date'] ?? '');
    }

    return $qs;
}

/** Aspek nilai kelas standar SKBT. */
function skbt_aspek_nilai_kelas_order(): array
{
    return ['NAHWU', 'SHOROF', 'MAKNA', 'MUROD', 'HAFALAN', 'ASILAH'];
}

function skbt_map_aspek_nilai_kelas(string $aspek, string $targetJudul = ''): ?string
{
    $key = strtolower(trim($aspek));
    $judul = strtolower(trim($targetJudul));
    $map = [
        'murod' => 'MUROD',
        'makna' => 'MAKNA',
        'hafalan' => 'HAFALAN',
        'nahwu' => 'NAHWU',
        'shorof' => 'SHOROF',
        'sarf' => 'SHOROF',
        'asilah' => 'ASILAH',
        'usul' => 'ASILAH',
    ];
    if (isset($map[$key])) {
        return $map[$key];
    }
    foreach ($map as $needle => $label) {
        if ($judul !== '' && str_contains($judul, $needle)) {
            return $label;
        }
    }

    return null;
}

/**
 * Agregat nilai manual per mapel untuk section NILAI KELAS.
 *
 * @param list<array<string,mixed>> $disiplinKelas
 * @return list<array{mapel:string,aspek:array<string,float>}>
 */
function skbt_nilai_kelas_agregat(PDO $pdo, int $santriId, array $periode, array $disiplinKelas): array
{
    $mapelList = [];
    foreach ($disiplinKelas as $kg) {
        $nama = trim((string) ($kg['nama_kegiatan'] ?? ''));
        if ($nama !== '') {
            $mapelList[$nama] = ['mapel' => $nama, 'aspek' => array_fill_keys(skbt_aspek_nilai_kelas_order(), 0.0)];
        }
    }

    if ($santriId <= 0 || !table_exists($pdo, 'pembimbing_nilai_manual')) {
        return array_values($mapelList);
    }

    require_once __DIR__ . '/pembimbing_nilai_manual.php';
    pembimbing_nilai_manual_ensure_schema($pdo);

    $start = (string) ($periode['start_date'] ?? '');
    $end = (string) ($periode['end_date'] ?? '');
    if ($start === '' || $end === '') {
        return array_values($mapelList);
    }

    $stmt = $pdo->prepare('
        SELECT n.nilai, n.aspek, t.aspek AS target_aspek, t.judul AS target_judul, k.nama_kegiatan
        FROM pembimbing_nilai_manual n
        LEFT JOIN pembimbing_penilaian_target t ON t.id = n.target_id
        LEFT JOIN kegiatan k ON k.id = n.kegiatan_id AND n.kegiatan_id > 0
        WHERE n.santri_id = :sid AND n.tanggal BETWEEN :start AND :end
    ');
    $stmt->execute(['sid' => $santriId, 'start' => $start, 'end' => $end]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $sum = [];
    $cnt = [];
    foreach ($rows as $r) {
        $mapel = trim((string) ($r['nama_kegiatan'] ?? ''));
        if ($mapel === '') {
            continue;
        }
        if (!isset($mapelList[$mapel])) {
            $mapelList[$mapel] = ['mapel' => $mapel, 'aspek' => array_fill_keys(skbt_aspek_nilai_kelas_order(), 0.0)];
        }
        $aspekKey = skbt_map_aspek_nilai_kelas(
            (string) ($r['aspek'] ?? ''),
            (string) ($r['target_judul'] ?? '')
        );
        if ($aspekKey === null) {
            $aspekKey = skbt_map_aspek_nilai_kelas((string) ($r['target_aspek'] ?? ''), (string) ($r['target_judul'] ?? ''));
        }
        if ($aspekKey === null) {
            continue;
        }
        $nilai = (float) ($r['nilai'] ?? 0);
        $sum[$mapel][$aspekKey] = ($sum[$mapel][$aspekKey] ?? 0) + $nilai;
        $cnt[$mapel][$aspekKey] = ($cnt[$mapel][$aspekKey] ?? 0) + 1;
    }

    foreach ($mapelList as $mapel => &$item) {
        foreach (skbt_aspek_nilai_kelas_order() as $aspek) {
            if (($cnt[$mapel][$aspek] ?? 0) > 0) {
                $item['aspek'][$aspek] = round($sum[$mapel][$aspek] / $cnt[$mapel][$aspek], 0);
            }
        }
    }
    unset($item);

    return array_values($mapelList);
}

function skbt_nilai_kelas_line(array $aspek): string
{
    $parts = [];
    foreach (skbt_aspek_nilai_kelas_order() as $label) {
        $parts[] = $label . ' ' . (int) round((float) ($aspek[$label] ?? 0));
    }

    return implode(', ', $parts) . '.';
}

function skbt_nilai_kelas_html(PDO $pdo, int $santriId, array $periode, array $disiplinKelas): string
{
    $rows = skbt_nilai_kelas_agregat($pdo, $santriId, $periode, $disiplinKelas);
    if ($rows === []) {
        return '<p class="skbt-section-placeholder">—</p>';
    }

    ob_start();
    foreach ($rows as $row) {
        $mapel = (string) ($row['mapel'] ?? '');
        if ($mapel === '') {
            continue;
        }
        ?>
        <div class="item-block">
            <div class="item-title"><?= htmlspecialchars($mapel) ?></div>
            <div class="item-sub"><?= htmlspecialchars(skbt_nilai_kelas_line($row['aspek'] ?? [])) ?></div>
        </div>
        <?php
    }
    $html = (string) ob_get_clean();

    return $html !== '' ? $html : '<p class="skbt-section-placeholder">—</p>';
}

/** HTML ringkas nilai ikhtibar per mapel. */
function skbt_nilai_ikhtibar_ringkas_html(array $ikhtibarNilai): string
{
    $groups = $ikhtibarNilai['groups'] ?? [];
    if ($groups === []) {
        return '<p class="skbt-section-placeholder">—</p>';
    }

    require_once __DIR__ . '/akademik_ikhtibar.php';

    $parts = [];
    foreach ($groups as $grp) {
        $mapel = trim((string) ($grp['mapel_label'] ?? ''));
        if ($mapel === '') {
            continue;
        }
        $predikat = '—';
        $bestNilai = -1.0;
        foreach ($grp['tugas'] ?? [] as $t) {
            if (!in_array((string) ($t['sesi_status'] ?? ''), ['selesai', 'habis_waktu'], true)) {
                continue;
            }
            $nilai = $t['nilai_total'] !== null ? (float) $t['nilai_total'] : null;
            if ($nilai === null) {
                continue;
            }
            if ($nilai >= $bestNilai) {
                $bestNilai = $nilai;
                $predikat = ikhtibar_predikat_nilai($nilai)['label'] ?? '—';
            }
        }
        $parts[] = htmlspecialchars($mapel) . ': ' . htmlspecialchars($predikat);
    }

    if ($parts === []) {
        return '<p class="skbt-section-placeholder">—</p>';
    }

    return '<div class="item-block"><div class="item-sub">' . implode(' | ', $parts) . '</div></div>';
}

/**
 * @param array<string,string> $catatan
 */
function skbt_catatan_pendidikan_html(array $catatan): string
{
    $kr = trim((string) ($catatan['kelas_ramadhan'] ?? '-')) ?: '-';
    $km = trim((string) ($catatan['kuota_muhafadzoh'] ?? '-')) ?: '-';
    $kh = trim((string) ($catatan['khidmah'] ?? '-')) ?: '-';
    $re = trim((string) ($catatan['regresi'] ?? '-')) ?: '-';

    ob_start();
    ?>
    <table class="tbl-catatan">
        <tr>
            <td width="25%"><b>Kelas Ramadhan:</b> <?= htmlspecialchars($kr) ?></td>
            <td width="25%"><b>Kuota Muhafadzoh:</b> <?= htmlspecialchars($km) ?></td>
            <td width="25%"><b>Khidmah:</b> <?= htmlspecialchars($kh) ?></td>
            <td width="25%"><b>Regresi:</b> <?= htmlspecialchars($re) ?></td>
        </tr>
    </table>
    <?php

    return (string) ob_get_clean();
}

function skbt_angka_terbilang_id(int $n): string
{
    $n = max(0, $n);
    if ($n === 0) {
        return 'nol';
    }
    $satuan = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
    $toWords = static function (int $num) use (&$toWords, $satuan): string {
        if ($num < 12) {
            return $satuan[$num];
        }
        if ($num < 20) {
            return $toWords($num - 10) . ' belas';
        }
        if ($num < 100) {
            $puluh = (int) floor($num / 10);
            $sisa = $num % 10;

            return $toWords($puluh) . ' puluh' . ($sisa > 0 ? ' ' . $toWords($sisa) : '');
        }
        if ($num < 200) {
            return 'seratus' . ($num > 100 ? ' ' . $toWords($num - 100) : '');
        }
        if ($num < 1000) {
            $ratus = (int) floor($num / 100);
            $sisa = $num % 100;

            return $toWords($ratus) . ' ratus' . ($sisa > 0 ? ' ' . $toWords($sisa) : '');
        }
        if ($num < 2000) {
            return 'seribu' . ($num > 1000 ? ' ' . $toWords($num - 1000) : '');
        }
        if ($num < 1_000_000) {
            $ribu = (int) floor($num / 1000);
            $sisa = $num % 1000;

            return $toWords($ribu) . ' ribu' . ($sisa > 0 ? ' ' . $toWords($sisa) : '');
        }

        return (string) $num;
    };

    return trim($toWords($n));
}

function skbt_hari_nama_id(int $dow): string
{
    $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

    return $days[max(0, min(6, $dow))] ?? '—';
}

function skbt_bulan_masehi_nama(int $month): string
{
    $names = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    return $names[max(1, min(12, $month))] ?? '';
}

function skbt_tanggal_masehi_terbilang(string $ymd): string
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) {
        return '—';
    }
    $day = (int) $m[3];
    $month = (int) $m[2];
    $year = (int) $m[1];
    $bulan = skbt_bulan_masehi_nama($month);

    return skbt_angka_terbilang_id($day) . ' ' . $bulan . ' ' . skbt_angka_terbilang_id($year) . ' Masehi';
}

function skbt_tanggal_hijriyah_terbilang(PDO $pdo, string $ymd): string
{
    $h = konversiKeHijriah($pdo, $ymd);
    if (!is_array($h)) {
        return '—';
    }
    $day = (int) ($h['tanggal'] ?? 0);
    $bulan = strtoupper(trim((string) ($h['nama_bulan'] ?? '')));
    $tahun = (int) ($h['tahun_hijriah'] ?? 0);
    if ($day <= 0 || $bulan === '' || $tahun <= 0) {
        return '—';
    }

    return skbt_angka_terbilang_id($day) . ' ' . $bulan . ' ' . skbt_angka_terbilang_id($tahun) . ' Hijriyah';
}

function skbt_ta_masehi_label_from_periode(array $periode): string
{
    $start = (string) ($periode['start_date'] ?? '');
    $end = (string) ($periode['end_date'] ?? '');
    $y1 = preg_match('/^(\d{4})/', $start, $m1) ? (int) $m1[1] : (int) date('Y');
    $y2 = preg_match('/^(\d{4})/', $end, $m2) ? (int) $m2[1] : $y1;
    if ($y2 < $y1) {
        $y2 = $y1;
    }
    if ($y1 === $y2) {
        return (string) $y1;
    }

    return $y1 . '-' . $y2;
}

/**
 * @return array<string,mixed>
 */
function skbt_parse_cetak_meta(array $get, array $periode): array
{
    $tanggal = trim((string) ($get['tanggal_cetak'] ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        $tanggal = date('Y-m-d');
    }
    $periodeKe = max(1, (int) ($get['periode_ke'] ?? 1));
    $periodeKeLanjut = (int) ($get['periode_ke_lanjut'] ?? 0);
    if ($periodeKeLanjut <= 0) {
        $periodeKeLanjut = $periodeKe + 1;
    }
    $taLabel = trim((string) ($get['ta_masehi_label'] ?? ''));
    if ($taLabel === '') {
        $taLabel = skbt_ta_masehi_label_from_periode($periode);
    }

    return [
        'tanggal_cetak' => $tanggal,
        'periode_ke' => $periodeKe,
        'periode_ke_lanjut' => $periodeKeLanjut,
        'ta_masehi_label' => $taLabel,
        'catatan' => [
            'kelas_ramadhan' => trim((string) ($get['cat_kelas_ramadhan'] ?? '-')) ?: '-',
            'kuota_muhafadzoh' => trim((string) ($get['cat_kuota_muhafadzoh'] ?? '-')) ?: '-',
            'khidmah' => trim((string) ($get['cat_khidmah'] ?? '-')) ?: '-',
            'regresi' => trim((string) ($get['cat_regresi'] ?? '-')) ?: '-',
        ],
    ];
}

/**
 * @param array<string,mixed> $opts
 */
function skbt_narasi_kelangsungan_html(PDO $pdo, array $opts): string
{
    $tanggal = (string) ($opts['tanggal_cetak'] ?? date('Y-m-d'));
    $ts = strtotime($tanggal) ?: time();
    $hari = skbt_hari_nama_id((int) date('w', $ts));
    $hijri = skbt_tanggal_hijriyah_terbilang($pdo, $tanggal);
    $masehi = skbt_tanggal_masehi_terbilang($tanggal);
    $periodeKe = (int) ($opts['periode_ke'] ?? 1);
    $periodeKeLanjut = (int) ($opts['periode_ke_lanjut'] ?? $periodeKe + 1);
    $taLabel = (string) ($opts['ta_masehi_label'] ?? '');
    $nomor = (string) ($opts['nomor'] ?? '');
    $namaPonpes = trim((string) ($opts['nama_ponpes'] ?? 'API Nailul Muna'));

    ob_start();
    ?>
    <p class="narasi">
        Pada hari ini, <b><?= htmlspecialchars($hari . ' ' . $hijri) ?></b>, bertepatan
        <b><?= htmlspecialchars($masehi) ?></b>, telah ditandatangani oleh penanggung jawab pendidikan di
        <?= htmlspecialchars($namaPonpes) ?>, hasil belajar <b>Periode ke <?= (int) $periodeKe ?></b> dan kelangsungan
        pendidikan santri yang tertera dalam surat ini pada <b>Periode ke <?= (int) $periodeKeLanjut ?></b>
        tahun <b><?= htmlspecialchars($taLabel) ?></b><?php if ($nomor !== ''): ?> dengan nomor
        <b><?= htmlspecialchars($nomor) ?></b><?php endif; ?>.
    </p>
    <?php

    return (string) ob_get_clean();
}

/** Query params cetak SKBT termasuk meta pratinjau. */
function skbt_cetak_query_params(PDO $pdo, array $periode, array $extra = []): array
{
    return array_merge(skbt_periode_query_params($pdo, $periode, $extra), array_intersect_key(
        $extra,
        array_flip([
            'tanggal_cetak', 'periode_ke', 'periode_ke_lanjut', 'ta_masehi_label',
            'cat_kelas_ramadhan', 'cat_kuota_muhafadzoh', 'cat_khidmah', 'cat_regresi',
            'preview', 'embed',
        ])
    ));
}
