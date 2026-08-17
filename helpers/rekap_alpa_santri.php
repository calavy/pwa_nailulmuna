<?php

declare(strict_types=1);

require_once __DIR__ . '/jadwal_jamaah.php';
require_once __DIR__ . '/rekap_periode.php';
require_once __DIR__ . '/rekap_keaktifan.php';
require_once __DIR__ . '/presensi_jadwal.php';
require_once __DIR__ . '/santri_list_sort.php';

/** @return list<string> */
function rekap_alpa_kelompok_valid(): array
{
    return ['putra', 'putri'];
}

function rekap_alpa_kelompok_label(string $kelompok): string
{
    return match (strtolower(trim($kelompok))) {
        'putri' => 'Putri',
        default => 'Putra',
    };
}

function rekap_alpa_kelompok_normalize(string $kelompok): string
{
    $kelompok = strtolower(trim($kelompok));

    return in_array($kelompok, rekap_alpa_kelompok_valid(), true) ? $kelompok : 'putra';
}

function rekap_alpa_row_matches_kelompok(array $row, string $kelompok): bool
{
    $kelompok = rekap_alpa_kelompok_normalize($kelompok);
    $tingkatan = trim((string) ($row['tingkatan'] ?? ''));
    if ($tingkatan === '') {
        return $kelompok === 'putra';
    }

    return jadwal_tingkatan_kelompok_dari_nama($tingkatan) === $kelompok;
}

/** @param list<array<string, mixed>> $rows */
function rekap_alpa_filter_rows(array $rows, string $kelompok): array
{
    return array_values(array_filter(
        $rows,
        static fn (array $row): bool => rekap_alpa_row_matches_kelompok($row, $kelompok)
    ));
}

/** @return list<string> */
function rekap_alpa_tingkatan_options(PDO $pdo, string $kelompok): array
{
    $kelompok = rekap_alpa_kelompok_normalize($kelompok);
    $map = jadwal_jamaah_tingkatan_kelompok_map($pdo);

    return $map[$kelompok] ?? [];
}

/** @return list<array<string, mixed>> */
function rekap_alpa_santri_options(PDO $pdo, string $kelompok): array
{
    $kelompok = rekap_alpa_kelompok_normalize($kelompok);
    santri_list_sort_mode(null);
    $rows = $pdo->query(
        'SELECT id, nama_santri, nis, tingkatan FROM santri ORDER BY ' . santri_list_order_sql('santri')
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return array_values(array_filter(
        $rows,
        static fn (array $row): bool => rekap_alpa_row_matches_kelompok($row, $kelompok)
    ));
}

/** @return array{page:string,sibling:string,sibling_label:string,sibling_kelompok:string} */
function rekap_alpa_page_paths(string $kelompok): array
{
    $kelompok = rekap_alpa_kelompok_normalize($kelompok);
    $sibling = $kelompok === 'putri' ? 'putra' : 'putri';

    return [
        'page' => app_href($kelompok === 'putri' ? '/rekap/alpa_santri_putri.php' : '/rekap/alpa_santri_putra.php'),
        'sibling' => app_href($sibling === 'putri' ? '/rekap/alpa_santri_putri.php' : '/rekap/alpa_santri_putra.php'),
        'sibling_label' => rekap_alpa_kelompok_label($sibling),
        'sibling_kelompok' => $sibling,
    ];
}

/**
 * Muat data halaman rekap ALPA per kelompok.
 *
 * @return array<string, mixed>
 */
function rekap_alpa_santri_load(PDO $pdo, string $kelompok, array $get): array
{
    $kelompok = rekap_alpa_kelompok_normalize($kelompok);
    $paths = rekap_alpa_page_paths($kelompok);
    $periode = rekap_resolve_periode($pdo, $get);
    $startDate = $periode['start_date'];
    $endDate = $periode['end_date'];
    $periodeLabel = $periode['label'];
    $tingkatan = trim((string) ($get['tingkatan'] ?? ''));
    $santriId = (int) ($get['santri_id'] ?? 0);

    $goodMax = (int) app_setting($pdo, 'kategori_baik_max', '1');
    $mediumMax = (int) app_setting($pdo, 'kategori_sedang_max', '3');

    rekap_keaktifan_prepare_periode_presensi($pdo, $startDate, $endDate);
    $rawRows = presensi_fetch_rows_rekap_periode($pdo, $periode, 0, false);
    $rawRows = rekap_alpa_filter_rows($rawRows, $kelompok);

    if ($tingkatan !== '') {
        $rawRows = array_values(array_filter($rawRows, static function (array $row) use ($tingkatan): bool {
            return strtolower((string) ($row['tingkatan'] ?? '')) === strtolower($tingkatan);
        }));
    }
    if ($santriId > 0) {
        $rawRows = array_values(array_filter($rawRows, static function (array $row) use ($santriId): bool {
            return (int) ($row['santri_id'] ?? 0) === $santriId;
        }));
    }

    $ranked = rekap_keaktifan_build_per_santri($rawRows, $goodMax, $mediumMax);
    usort($ranked, static fn (array $a, array $b): int => ((int) ($b['alpa'] ?? 0)) <=> ((int) ($a['alpa'] ?? 0))
        ?: strcmp((string) ($a['nama_santri'] ?? ''), (string) ($b['nama_santri'] ?? '')));

    santri_list_sort_mode($get['santri_sort'] ?? null);

    return [
        'rekapAlpaKelompok' => $kelompok,
        'rekapAlpaKelompokLabel' => rekap_alpa_kelompok_label($kelompok),
        'rekapAlpaPagePath' => $paths['page'],
        'rekapAlpaSiblingPath' => $paths['sibling'],
        'rekapAlpaSiblingLabel' => $paths['sibling_label'],
        'periode' => $periode,
        'periodeLabel' => $periodeLabel,
        'tingkatan' => $tingkatan,
        'santriId' => $santriId,
        'goodMax' => $goodMax,
        'mediumMax' => $mediumMax,
        'ranked' => $ranked,
        'tingkatanList' => rekap_alpa_tingkatan_options($pdo, $kelompok),
        'santriList' => rekap_alpa_santri_options($pdo, $kelompok),
        'pageTitle' => 'Laporan ALPA ' . rekap_alpa_kelompok_label($kelompok),
    ];
}
