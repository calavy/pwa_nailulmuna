<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_alokasi.php';
require_once __DIR__ . '/keuangan_pkpps_syahriyah.php';
require_once __DIR__ . '/keuangan_kelas_syahriyah.php';
require_once __DIR__ . '/tagihan_bulanan.php';
require_once __DIR__ . '/santri_operasional.php';

/** Komponen alokasi KOPSA (cicilan modal) dari pengaturan syahriyah. */
function keuangan_kopsa_komponen(PDO $pdo): ?array
{
    $prefer = trim((string) app_setting($pdo, 'keuangan_kopsa_nama_komponen', ''));
    $rows = keuangan_fetch_alokasi_aktif($pdo, KEUNGAN_ALOKASI_JENIS_SYAHRIYAH);

    if ($prefer !== '') {
        foreach ($rows as $row) {
            if (strcasecmp(trim((string) ($row['nama_komponen'] ?? '')), $prefer) === 0) {
                return $row;
            }
        }
    }

    foreach ($rows as $row) {
        $nama = strtoupper(trim((string) ($row['nama_komponen'] ?? '')));
        $kat = strtoupper(trim((string) ($row['kategori'] ?? '')));
        if (
            str_contains($nama, 'KOPSA')
            || str_contains($kat, 'KOPSA')
            || str_contains($nama, 'CICILAN MODAL')
            || str_contains($kat, 'CICILAN MODAL')
        ) {
            return $row;
        }
    }

    return null;
}

function keuangan_kopsa_persen(PDO $pdo): float
{
    $k = keuangan_kopsa_komponen($pdo);

    return $k !== null ? (float) ($k['persen'] ?? 0) : 0.0;
}

/**
 * Nominal bagian KOPSA dari pembayaran syahriyah satu santri satu bulan.
 */
function keuangan_kopsa_nominal_dari_pembayaran(
    PDO $pdo,
    int $santriId,
    string $kelasKategori,
    int $bayarSyahriyah,
    int $bulanTagihan,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai
): int {
    if ($bayarSyahriyah <= 0) {
        return 0;
    }
    $persen = keuangan_kopsa_persen($pdo);
    if ($persen <= 0) {
        return 0;
    }

    if (!function_exists('keuangan_syahriyah_split_pembayaran_tambahan')) {
        require_once __DIR__ . '/keuangan_pkpps_syahriyah.php';
    }

    $split = keuangan_syahriyah_split_pembayaran_tambahan(
        $pdo,
        $santriId,
        $kelasKategori,
        $bayarSyahriyah,
        $bulanTagihan,
        $tahunAjaranMulai,
        $tahunAjaranSelesai
    );
    $dasar = (int) ($split['dasar'] ?? $bayarSyahriyah);

    return (int) floor($dasar * $persen / 100);
}

/**
 * Matriks KOPSA per santri × bulan tagihan TA.
 *
 * @return array{
 *   komponen: ?array,
 *   persen: float,
 *   bulan_slots: list<array<string,mixed>>,
 *   rows: list<array{
 *     santri_id:int,
 *     nama_santri:string,
 *     nis:string,
 *     kategori_kelas:string,
 *     bulan: array<int,int>,
 *     total:int
 *   }>
 * }
 */
/**
 * @return array{komponen:?array,persen:float,bulan_slots:list<array<string,mixed>>,rows:list<array<string,mixed>>}
 */
function keuangan_kopsa_rekap_per_santri_bulan_cached(
    PDO $pdo,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    int $ttlSec = 300
): array {
    if ($tahunAjaranMulai <= 0) {
        return keuangan_kopsa_rekap_per_santri_bulan($pdo, $tahunAjaranMulai, $tahunAjaranSelesai);
    }
    if (!function_exists('pondok_ta_bulan_awal')) {
        require_once __DIR__ . '/pondok_ta.php';
    }
    require_once __DIR__ . '/pondok_kalender.php';
    $cacheKey = 'kopsa_rekap_v1:' . $tahunAjaranMulai . ':' . $tahunAjaranSelesai . ':'
        . (pondok_kalender_hijriyah($pdo) ? 'h' : 'm') . ':'
        . pondok_ta_bulan_awal($pdo);
    if (isset($_SESSION['user'])) {
        $bucket = $_SESSION['keuangan_kopsa_rekap_v1'] ?? null;
        if (is_array($bucket)) {
            $entry = $bucket[$cacheKey] ?? null;
            if (is_array($entry) && (int) ($entry['expires'] ?? 0) > time() && is_array($entry['data'] ?? null)) {
                return $entry['data'];
            }
        }
    }
    $data = keuangan_kopsa_rekap_per_santri_bulan($pdo, $tahunAjaranMulai, $tahunAjaranSelesai);
    if (isset($_SESSION['user'])) {
        if (!isset($_SESSION['keuangan_kopsa_rekap_v1']) || !is_array($_SESSION['keuangan_kopsa_rekap_v1'])) {
            $_SESSION['keuangan_kopsa_rekap_v1'] = [];
        }
        $bucket = $_SESSION['keuangan_kopsa_rekap_v1'];
        $bucket[$cacheKey] = ['expires' => time() + max(60, $ttlSec), 'data' => $data];
        if (count($bucket) > 3) {
            uasort($bucket, static fn (array $a, array $b): int => (int) ($b['expires'] ?? 0) <=> (int) ($a['expires'] ?? 0));
            $bucket = array_slice($bucket, 0, 3, true);
        }
        $_SESSION['keuangan_kopsa_rekap_v1'] = $bucket;
    }

    return $data;
}

function keuangan_kopsa_rekap_per_santri_bulan(
    PDO $pdo,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai
): array {
    require_once __DIR__ . '/pondok_kalender.php';

    $komponen = keuangan_kopsa_komponen($pdo);
    $persen = $komponen !== null ? (float) ($komponen['persen'] ?? 0) : 0.0;
    $bulanSlots = pondok_bulan_slots_tahun_ajaran($pdo, $tahunAjaranMulai, $tahunAjaranSelesai);

    $result = [
        'komponen' => $komponen,
        'persen' => $persen,
        'bulan_slots' => $bulanSlots,
        'rows' => [],
    ];

    if ($komponen === null || $persen <= 0 || !table_exists($pdo, 'santri')) {
        return $result;
    }

    $namaCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $aktifSql = santri_sql_aktif_only('s');
    $stSantri = $pdo->query('
        SELECT s.id, s.' . $namaCol . ' AS nama_santri, s.nis, s.kategori_kelas
        FROM santri s
        WHERE ' . $aktifSql . '
        ORDER BY s.' . $namaCol . ' ASC
    ');
    $santriList = $stSantri ? ($stSantri->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $paidByBulan = [];
    foreach ($bulanSlots as $slot) {
        $b = (int) ($slot['bulan_tagihan'] ?? 0);
        if ($b >= 1 && $b <= 12) {
            $paidByBulan[$b] = tagihan_paid_map_for_month($pdo, $b, $tahunAjaranMulai, $tahunAjaranSelesai, ['syahriyah']);
        }
    }

    foreach ($santriList as $s) {
        $sid = (int) ($s['id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $kat = trim((string) ($s['kategori_kelas'] ?? ''));
        $perBulan = [];
        $total = 0;
        foreach ($bulanSlots as $slot) {
            $b = (int) ($slot['bulan_tagihan'] ?? 0);
            if ($b < 1 || $b > 12) {
                continue;
            }
            $bayar = (int) (($paidByBulan[$b][$sid]['syahriyah'] ?? 0));
            $nom = keuangan_kopsa_nominal_dari_pembayaran(
                $pdo,
                $sid,
                $kat,
                $bayar,
                $b,
                $tahunAjaranMulai,
                $tahunAjaranSelesai
            );
            $perBulan[$b] = $nom;
            $total += $nom;
        }
        if ($total > 0 || array_sum($perBulan) > 0) {
            $result['rows'][] = [
                'santri_id' => $sid,
                'nama_santri' => (string) ($s['nama_santri'] ?? '-'),
                'nis' => (string) ($s['nis'] ?? ''),
                'kategori_kelas' => $kat,
                'bulan' => $perBulan,
                'total' => $total,
            ];
        }
    }

    return $result;
}
