<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/santri_operasional.php';

/**
 * Laporan keaktifan santri hari ini per kegiatan (seluruh pondok atau filter tingkatan).
 *
 * @return list<array<string, mixed>>
 */
function rekap_keaktifan_hari_data(PDO $pdo, string $tanggal, ?string $tingkatanFilter = null): array
{
    if (!table_exists($pdo, 'presensi') || !table_exists($pdo, 'santri') || !table_exists($pdo, 'kegiatan')) {
        return [];
    }
    $aktifSql = santri_sql_aktif_only('s');
    $params = ['tgl' => $tanggal];
    $tkWhere = '';
    if ($tingkatanFilter !== null && $tingkatanFilter !== '') {
        $tkWhere = ' AND s.tingkatan = :tk';
        $params['tk'] = $tingkatanFilter;
    }

    $sql = "
        SELECT
            k.id AS kegiatan_id,
            k.nama_kegiatan,
            s.id AS santri_id,
            s.nis,
            s.nama_santri,
            s.tingkatan,
            COALESCE(p.status_presensi, 'BELUM') AS status_hari_ini,
            p.jam_presensi,
            p.catatan
        FROM kegiatan k
        CROSS JOIN santri s
        LEFT JOIN presensi p ON p.santri_id = s.id
            AND p.kegiatan_id = k.id
            AND p.tanggal_presensi = :tgl
        WHERE k.is_active = 1
          AND {$aktifSql}
          {$tkWhere}
        ORDER BY k.nama_kegiatan ASC, s.tingkatan ASC, s.nama_santri ASC
    ";
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Ringkasan per kegiatan: jumlah hadir, izin, alpa, belum.
 *
 * @return list<array<string, mixed>>
 */
function rekap_keaktifan_hari_ringkasan_kegiatan(PDO $pdo, string $tanggal, ?string $tingkatanFilter = null): array
{
    $rows = rekap_keaktifan_hari_data($pdo, $tanggal, $tingkatanFilter);
    $byKeg = [];
    foreach ($rows as $r) {
        $kid = (int) ($r['kegiatan_id'] ?? 0);
        if (!isset($byKeg[$kid])) {
            $byKeg[$kid] = [
                'kegiatan_id' => $kid,
                'nama_kegiatan' => (string) ($r['nama_kegiatan'] ?? ''),
                'hadir' => 0,
                'izin' => 0,
                'sakit' => 0,
                'alpa' => 0,
                'belum' => 0,
                'total' => 0,
            ];
        }
        $st = strtoupper((string) ($r['status_hari_ini'] ?? 'BELUM'));
        $byKeg[$kid]['total']++;
        match ($st) {
            'HADIR' => $byKeg[$kid]['hadir']++,
            'IZIN' => $byKeg[$kid]['izin']++,
            'SAKIT' => $byKeg[$kid]['sakit']++,
            'ALPA' => $byKeg[$kid]['alpa']++,
            default => $byKeg[$kid]['belum']++,
        };
    }

    return array_values($byKeg);
}

/**
 * Total agregat dari ringkasan per kegiatan.
 *
 * @param list<array<string, mixed>> $ringkasan
 * @return array{hadir:int,izin:int,sakit:int,alpa:int,belum:int,total:int,persen:float}
 */
function rekap_keaktifan_hari_totals(array $ringkasan): array
{
    $tot = ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'belum' => 0, 'total' => 0];
    foreach ($ringkasan as $rg) {
        $tot['hadir'] += (int) ($rg['hadir'] ?? 0);
        $tot['izin'] += (int) ($rg['izin'] ?? 0);
        $tot['sakit'] += (int) ($rg['sakit'] ?? 0);
        $tot['alpa'] += (int) ($rg['alpa'] ?? 0);
        $tot['belum'] += (int) ($rg['belum'] ?? 0);
        $tot['total'] += (int) ($rg['total'] ?? 0);
    }
    $tot['persen'] = $tot['total'] > 0
        ? round(100 * $tot['hadir'] / $tot['total'], 1)
        : 0.0;

    return $tot;
}

/**
 * Detail per kegiatan: hitungan + daftar santri per status.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function rekap_keaktifan_hari_detail_by_kegiatan(array $rows): array
{
    $byKeg = [];
    foreach ($rows as $r) {
        $kid = (int) ($r['kegiatan_id'] ?? 0);
        if (!isset($byKeg[$kid])) {
            $byKeg[$kid] = [
                'kegiatan_id' => $kid,
                'nama_kegiatan' => (string) ($r['nama_kegiatan'] ?? ''),
                'hadir' => 0,
                'izin' => 0,
                'sakit' => 0,
                'alpa' => 0,
                'belum' => 0,
                'total' => 0,
                'santri' => [
                    'HADIR' => [],
                    'IZIN' => [],
                    'SAKIT' => [],
                    'ALPA' => [],
                    'BELUM' => [],
                ],
            ];
        }
        $st = strtoupper((string) ($r['status_hari_ini'] ?? 'BELUM'));
        if (!isset($byKeg[$kid]['santri'][$st])) {
            $st = 'BELUM';
        }
        $byKeg[$kid]['total']++;
        match ($st) {
            'HADIR' => $byKeg[$kid]['hadir']++,
            'IZIN' => $byKeg[$kid]['izin']++,
            'SAKIT' => $byKeg[$kid]['sakit']++,
            'ALPA' => $byKeg[$kid]['alpa']++,
            default => $byKeg[$kid]['belum']++,
        };
        $jam = $r['jam_presensi'] ?? null;
        $byKeg[$kid]['santri'][$st][] = [
            'nama_santri' => (string) ($r['nama_santri'] ?? ''),
            'nis' => (string) ($r['nis'] ?? ''),
            'tingkatan' => (string) ($r['tingkatan'] ?? ''),
            'jam_presensi' => $jam !== null && $jam !== '' ? substr((string) $jam, 0, 5) : null,
        ];
    }

    return array_values($byKeg);
}
