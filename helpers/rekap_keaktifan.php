<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/**
 * @param list<array<string, mixed>> $rows presensi terfilter (eligible)
 * @return list<array<string, mixed>>
 */
function rekap_keaktifan_build_per_santri(array $rows, int $goodMax, int $mediumMax): array
{
    $bySantri = [];
    foreach ($rows as $row) {
        $sid = (int) ($row['santri_id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        if (!isset($bySantri[$sid])) {
            $bySantri[$sid] = [
                'santri_id' => $sid,
                'nis' => (string) ($row['nis'] ?? ''),
                'nama_santri' => (string) ($row['nama_santri'] ?? ''),
                'tingkatan' => (string) ($row['tingkatan'] ?? ''),
                'hadir' => 0,
                'izin' => 0,
                'sakit' => 0,
                'alpa' => 0,
                'total' => 0,
                'per_kegiatan' => [],
            ];
        }
        $status = strtoupper((string) ($row['status_presensi'] ?? ''));
        $kgLabel = trim((string) ($row['nama_kegiatan'] ?? '')) !== '' ? (string) $row['nama_kegiatan'] : 'Tanpa Kegiatan';
        if (!isset($bySantri[$sid]['per_kegiatan'][$kgLabel])) {
            $bySantri[$sid]['per_kegiatan'][$kgLabel] = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'total' => 0];
        }
        $bySantri[$sid]['total']++;
        $bySantri[$sid]['per_kegiatan'][$kgLabel]['total']++;
        if ($status === 'HADIR') {
            $bySantri[$sid]['hadir']++;
            $bySantri[$sid]['per_kegiatan'][$kgLabel]['hadir']++;
        } elseif ($status === 'IZIN') {
            $bySantri[$sid]['izin']++;
            $bySantri[$sid]['per_kegiatan'][$kgLabel]['izin']++;
        } elseif ($status === 'SAKIT') {
            $bySantri[$sid]['sakit']++;
            $bySantri[$sid]['per_kegiatan'][$kgLabel]['sakit']++;
        } elseif ($status === 'ALPA') {
            $bySantri[$sid]['alpa']++;
            $bySantri[$sid]['per_kegiatan'][$kgLabel]['alpa']++;
        }
    }

    $ranked = [];
    foreach ($bySantri as $item) {
        $total = (int) $item['total'];
        $hadir = (int) $item['hadir'];
        $alpa = (int) $item['alpa'];
        $persen = $total > 0 ? round(($hadir / $total) * 100, 2) : 0;
        $kategori = santri_category($alpa, $goodMax, $mediumMax);
        $skor = ($persen * 10) - ($alpa * 5);
        $item['persen_hadir'] = $persen;
        $item['kategori'] = $kategori;
        $item['skor'] = $skor;
        foreach ($item['per_kegiatan'] as $kgName => $kgStats) {
            $kgTotal = (int) $kgStats['total'];
            $kgHadir = (int) $kgStats['hadir'];
            $kgAlpa = (int) $kgStats['alpa'];
            $kgPersen = $kgTotal > 0 ? round(($kgHadir / $kgTotal) * 100, 2) : 0.0;
            $item['per_kegiatan'][$kgName]['persen_hadir'] = $kgPersen;
            $item['per_kegiatan'][$kgName]['kategori'] = santri_category($kgAlpa, $goodMax, $mediumMax);
        }
        ksort($item['per_kegiatan']);
        $ranked[] = $item;
    }

    usort($ranked, static function (array $a, array $b): int {
        if ($a['skor'] === $b['skor']) {
            return strcmp((string) $a['nama_santri'], (string) $b['nama_santri']);
        }

        return $b['skor'] <=> $a['skor'];
    });

    return $ranked;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array<string, array{hadir:int,izin:int,sakit:int,alpa:int,total:int,santri_count:int}>
 */
function rekap_keaktifan_build_per_kegiatan(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        $kgLabel = trim((string) ($row['nama_kegiatan'] ?? '')) !== '' ? (string) $row['nama_kegiatan'] : 'Tanpa Kegiatan';
        if (!isset($out[$kgLabel])) {
            $out[$kgLabel] = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'total' => 0, 'santri_ids' => []];
        }
        $status = strtoupper((string) ($row['status_presensi'] ?? ''));
        $out[$kgLabel]['total']++;
        $sid = (int) ($row['santri_id'] ?? 0);
        if ($sid > 0) {
            $out[$kgLabel]['santri_ids'][$sid] = true;
        }
        if ($status === 'HADIR') {
            $out[$kgLabel]['hadir']++;
        } elseif ($status === 'IZIN') {
            $out[$kgLabel]['izin']++;
        } elseif ($status === 'SAKIT') {
            $out[$kgLabel]['sakit']++;
        } elseif ($status === 'ALPA') {
            $out[$kgLabel]['alpa']++;
        }
    }
    foreach ($out as $label => $data) {
        $out[$label]['santri_count'] = count($data['santri_ids'] ?? []);
        unset($out[$label]['santri_ids']);
    }
    ksort($out);

    return $out;
}

/**
 * @param list<array<string, mixed>> $ranked dari rekap_keaktifan_build_per_santri
 * @return array<string, array<string, mixed>>
 */
function rekap_keaktifan_build_per_tingkatan(array $ranked): array
{
    $kategoriKeys = ['Bagus', 'Baik', 'Sedang', 'Buruk'];
    $out = [];
    foreach ($ranked as $row) {
        $tg = trim((string) ($row['tingkatan'] ?? '')) !== '' ? (string) $row['tingkatan'] : '-';
        if (!isset($out[$tg])) {
            $out[$tg] = [
                'hadir' => 0,
                'izin' => 0,
                'sakit' => 0,
                'alpa' => 0,
                'total' => 0,
                'santri_count' => 0,
                'kategori' => array_fill_keys($kategoriKeys, 0),
                'santri_by_kategori' => array_fill_keys($kategoriKeys, []),
            ];
        }
        $out[$tg]['hadir'] += (int) $row['hadir'];
        $out[$tg]['izin'] += (int) $row['izin'];
        $out[$tg]['sakit'] += (int) $row['sakit'];
        $out[$tg]['alpa'] += (int) $row['alpa'];
        $out[$tg]['total'] += (int) $row['total'];
        $out[$tg]['santri_count']++;

        $kat = (string) ($row['kategori'] ?? 'Buruk');
        if (!isset($out[$tg]['kategori'][$kat])) {
            $out[$tg]['kategori'][$kat] = 0;
            $out[$tg]['santri_by_kategori'][$kat] = [];
        }
        $out[$tg]['kategori'][$kat]++;
        $out[$tg]['santri_by_kategori'][$kat][] = [
            'santri_id' => (int) ($row['santri_id'] ?? 0),
            'nis' => (string) ($row['nis'] ?? ''),
            'nama_santri' => (string) ($row['nama_santri'] ?? ''),
            'hadir' => (int) $row['hadir'],
            'alpa' => (int) $row['alpa'],
            'izin' => (int) $row['izin'],
            'sakit' => (int) $row['sakit'],
            'total' => (int) $row['total'],
            'persen_hadir' => (float) ($row['persen_hadir'] ?? 0),
            'kategori' => $kat,
        ];
    }
    foreach ($out as $tg => $data) {
        $total = (int) $data['total'];
        $out[$tg]['persen_hadir'] = $total > 0 ? round(((int) $data['hadir'] / $total) * 100, 2) : 0.0;
        foreach ($kategoriKeys as $katKey) {
            usort($out[$tg]['santri_by_kategori'][$katKey], static function (array $a, array $b): int {
                return strcmp((string) $a['nama_santri'], (string) $b['nama_santri']);
            });
        }
    }
    ksort($out);

    return $out;
}

function rekap_keaktifan_kategori_badge_class(string $kategori): string
{
    return match ($kategori) {
        'Bagus' => 'success',
        'Baik' => 'info',
        'Sedang' => 'warning',
        'Buruk' => 'danger',
        default => 'secondary',
    };
}

/** @return list<string> */
function rekap_keaktifan_kategori_urutan(): array
{
    return ['Bagus', 'Baik', 'Sedang', 'Buruk'];
}
