<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/rekap_keaktifan_hari.php';
require_once __DIR__ . '/jadwal_ui.php';
require_once __DIR__ . '/santri_operasional.php';
require_once __DIR__ . '/entity_list_sort.php';

/**
 * Jadwal kegiatan yang sedang berlangsung (seluruh pondok).
 *
 * @return list<array<string, mixed>>
 */
function pengasuh_dashboard_kegiatan_aktif(PDO $pdo, ?string $jamNow = null): array
{
    if (!table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan')) {
        return [];
    }
    ensure_jadwal_kegiatan_tempat($pdo);
    $jamNow = $jamNow ?? date('H:i:s');
    $hariKe = (int) date('N');

    $pbSelect = '';
    $pbJoin = '';
    if (column_exists($pdo, 'jadwal_kegiatan', 'pembimbing_id') && table_exists($pdo, 'pembimbing')) {
        $pbSelect = ', j.pembimbing_id, p.nama_pembimbing';
        $pbJoin = ' LEFT JOIN pembimbing p ON p.id = j.pembimbing_id';
    }

    $st = $pdo->prepare(
        'SELECT k.id AS kegiatan_id, k.nama_kegiatan, COALESCE(k.kategori_kegiatan, \'TAALIM\') AS kategori_kegiatan, j.tingkatan, j.jam_mulai, j.jam_selesai, j.tempat'
        . $pbSelect . '
         FROM jadwal_kegiatan j
         INNER JOIN kegiatan k ON k.id = j.kegiatan_id'
        . $pbJoin . '
         WHERE (j.hari_ke = 0 OR j.hari_ke = :hari_ke)
           AND :jam_now BETWEEN j.jam_mulai AND j.jam_selesai
           AND k.is_active = 1
         ORDER BY j.jam_mulai ASC, j.tingkatan ASC'
    );
    $st->execute(['hari_ke' => $hariKe, 'jam_now' => $jamNow]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Jadwal kegiatan yang sudah dimulai hari ini (termasuk yang sudah selesai).
 *
 * @return list<array<string, mixed>>
 */
function pengasuh_dashboard_kegiatan_sudah_berjalan(PDO $pdo, ?string $jamNow = null): array
{
    if (!table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan')) {
        return [];
    }
    ensure_jadwal_kegiatan_tempat($pdo);
    $jamNow = $jamNow ?? date('H:i:s');
    $hariKe = (int) date('N');

    $pbSelect = '';
    $pbJoin = '';
    if (column_exists($pdo, 'jadwal_kegiatan', 'pembimbing_id') && table_exists($pdo, 'pembimbing')) {
        $pbSelect = ', j.pembimbing_id, p.nama_pembimbing';
        $pbJoin = ' LEFT JOIN pembimbing p ON p.id = j.pembimbing_id';
    }

    $st = $pdo->prepare(
        'SELECT k.id AS kegiatan_id, k.nama_kegiatan, COALESCE(k.kategori_kegiatan, \'TAALIM\') AS kategori_kegiatan, j.tingkatan, j.jam_mulai, j.jam_selesai, j.tempat'
        . $pbSelect . '
         FROM jadwal_kegiatan j
         INNER JOIN kegiatan k ON k.id = j.kegiatan_id'
        . $pbJoin . '
         WHERE (j.hari_ke = 0 OR j.hari_ke = :hari_ke)
           AND j.jam_mulai <= :jam_now
           AND k.is_active = 1
         ORDER BY j.jam_mulai ASC, j.tingkatan ASC'
    );
    $st->execute(['hari_ke' => $hariKe, 'jam_now' => $jamNow]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @param list<array<string, mixed>> $kegiatanAktif
 * @return array<string, array{kegiatan_id:int,tingkatan:?string,semua:bool}>
 */
function pengasuh_dashboard_live_slot_keys(array $kegiatanAktif): array
{
    $keys = [];
    foreach ($kegiatanAktif as $row) {
        $kid = (int) ($row['kegiatan_id'] ?? 0);
        if ($kid <= 0) {
            continue;
        }
        $tk = trim((string) ($row['tingkatan'] ?? ''));
        if ($tk === '' || strcasecmp($tk, 'Semua Tingkatan') === 0) {
            $keys['k:' . $kid] = ['kegiatan_id' => $kid, 'tingkatan' => null, 'semua' => true];
            continue;
        }
        $keys[$kid . '|' . $tk] = ['kegiatan_id' => $kid, 'tingkatan' => $tk, 'semua' => false];
    }

    return $keys;
}

/**
 * @param array<string, array{kegiatan_id:int,tingkatan:?string,semua:bool}> $liveKeys
 */
function pengasuh_dashboard_row_is_live(array $row, array $liveKeys): bool
{
    $kid = (int) ($row['kegiatan_id'] ?? 0);
    $tk = trim((string) ($row['tingkatan'] ?? ''));
    if ($kid <= 0) {
        return false;
    }
    if (isset($liveKeys['k:' . $kid])) {
        return true;
    }

    return isset($liveKeys[$kid . '|' . $tk]);
}

/**
 * @param list<array<string, mixed>> $rows
 * @param list<array<string, mixed>> $kegiatanAktif
 * @return list<array<string, mixed>>
 */
function pengasuh_dashboard_filter_rows_berlangsung(array $rows, array $kegiatanAktif): array
{
    if ($kegiatanAktif === []) {
        return [];
    }
    $liveKeys = pengasuh_dashboard_live_slot_keys($kegiatanAktif);

    return array_values(array_filter(
        $rows,
        static fn (array $r): bool => pengasuh_dashboard_row_is_live($r, $liveKeys)
    ));
}

/**
 * Urutkan kegiatan: alpa + izin terbanyak di atas.
 *
 * @param list<array<string, mixed>> $detailKeg
 * @return list<array<string, mixed>>
 */
function pengasuh_dashboard_urutkan_kegiatan(array $detailKeg): array
{
    usort(
        $detailKeg,
        static function (array $a, array $b): int {
            $scoreA = (int) ($a['alpa'] ?? 0) + (int) ($a['izin'] ?? 0);
            $scoreB = (int) ($b['alpa'] ?? 0) + (int) ($b['izin'] ?? 0);
            if ($scoreA !== $scoreB) {
                return $scoreB <=> $scoreA;
            }
            $alpaCmp = ((int) ($b['alpa'] ?? 0)) <=> ((int) ($a['alpa'] ?? 0));
            if ($alpaCmp !== 0) {
                return $alpaCmp;
            }

            return strcmp((string) ($a['nama_kegiatan'] ?? ''), (string) ($b['nama_kegiatan'] ?? ''));
        }
    );

    return $detailKeg;
}

/**
 * Keaktivan kegiatan berlangsung, dikelompokkan per tingkatan.
 *
 * @param list<array<string, mixed>> $rows
 * @param list<array<string, mixed>> $kegiatanAktif
 * @return list<array{tingkatan:string,alpa_izin:int,kegiatan:list<array<string,mixed>>}>
 */
/**
 * @param list<array<string, mixed>> $filteredRows
 * @return list<array{tingkatan:string,alpa_izin:int,kegiatan:list<array<string,mixed>>}>
 */
function pengasuh_dashboard_group_keaktivan_by_tingkatan(array $filteredRows): array
{
    if ($filteredRows === []) {
        return [];
    }

    /** @var array<string, list<array<string, mixed>>> $byTk */
    $byTk = [];
    foreach ($filteredRows as $r) {
        $tk = trim((string) ($r['tingkatan'] ?? ''));
        if ($tk === '') {
            $tk = '-';
        }
        $byTk[$tk][] = $r;
    }

    $out = [];
    foreach ($byTk as $tk => $tkRows) {
        $detailKeg = pengasuh_dashboard_urutkan_kegiatan(rekap_keaktifan_hari_detail_by_kegiatan($tkRows));
        $alpaIzin = 0;
        foreach ($detailKeg as $dk) {
            $alpaIzin += (int) ($dk['alpa'] ?? 0) + (int) ($dk['izin'] ?? 0);
        }
        $out[] = [
            'tingkatan' => $tk,
            'alpa_izin' => $alpaIzin,
            'kegiatan' => $detailKeg,
        ];
    }

    usort(
        $out,
        static function (array $a, array $b): int {
            if ($a['alpa_izin'] !== $b['alpa_izin']) {
                return $b['alpa_izin'] <=> $a['alpa_izin'];
            }

            return strcmp((string) $a['tingkatan'], (string) $b['tingkatan']);
        }
    );

    return $out;
}

function pengasuh_dashboard_keaktivan_by_tingkatan(array $rows, array $kegiatanAktif): array
{
    return pengasuh_dashboard_group_keaktivan_by_tingkatan(
        pengasuh_dashboard_filter_rows_berlangsung($rows, $kegiatanAktif)
    );
}

/**
 * Ringkasan keaktivan sepanjang hari (bukan hanya slot berlangsung).
 *
 * @param list<array<string, mixed>> $rowsKat
 * @return list<array{tingkatan:string,alpa_izin:int,kegiatan:list<array<string,mixed>>}>
 */
function pengasuh_dashboard_keaktivan_by_tingkatan_hari_penuh(array $rowsKat): array
{
    return pengasuh_dashboard_group_keaktivan_by_tingkatan($rowsKat);
}

/** @return 'TAALIM'|'JAMAAH'|'EXTRA' */
function pengasuh_dashboard_normalize_kegiatan_kategori(?string $raw): string
{
    if (!function_exists('kegiatan_kategori_normalize')) {
        require_once __DIR__ . '/kegiatan_kategori.php';
    }

    return kegiatan_kategori_normalize($raw);
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function pengasuh_dashboard_filter_rows_kategori(array $rows, string $kategori): array
{
    $target = pengasuh_dashboard_normalize_kegiatan_kategori($kategori);

    return array_values(array_filter(
        $rows,
        static fn (array $r): bool => pengasuh_dashboard_normalize_kegiatan_kategori($r['kategori_kegiatan'] ?? null) === $target
    ));
}

/**
 * @param list<array<string, mixed>> $slots
 * @return list<array<string, mixed>>
 */
function pengasuh_dashboard_filter_kegiatan_aktif_kategori(array $slots, string $kategori): array
{
    $target = pengasuh_dashboard_normalize_kegiatan_kategori($kategori);

    return array_values(array_filter(
        $slots,
        static fn (array $s): bool => pengasuh_dashboard_normalize_kegiatan_kategori($s['kategori_kegiatan'] ?? null) === $target
    ));
}

/**
 * Paket data keaktivan berlangsung per kategori (Ta'lim / Jama'ah).
 *
 * @param list<array<string, mixed>> $rowsHari
 * @param list<array<string, mixed>> $kegiatanAktif
 * @return array{
 *   mode: string,
 *   kegiatanAktif: list<array<string,mixed>>,
 *   keaktivanByTingkatan: list<array<string,mixed>>,
 *   detailLive: list<array<string,mixed>>,
 *   totalsLive: array{hadir:int,izin:int,sakit:int,alpa:int,total:int,persen:float},
 *   sdmByTingkatan: array{pembimbing:list,munawib:list}
 * }
 */
function pengasuh_dashboard_keaktivan_bundle(
    PDO $pdo,
    string $today,
    array $rowsHari,
    array $kegiatanAktif,
    string $kategori
): array {
    $jamNow = date('H:i:s');
    $aktif = pengasuh_dashboard_filter_kegiatan_aktif_kategori($kegiatanAktif, $kategori);
    $sudahBerjalan = pengasuh_dashboard_filter_kegiatan_aktif_kategori(
        pengasuh_dashboard_kegiatan_sudah_berjalan($pdo, $jamNow),
        $kategori
    );
    $rowsKat = pengasuh_dashboard_filter_rows_kategori($rowsHari, $kategori);
    $isLive = $aktif !== [];
    $hasStarted = $sudahBerjalan !== [];

    if ($hasStarted) {
        $rowsDetail = pengasuh_dashboard_filter_rows_berlangsung($rowsKat, $sudahBerjalan);
        $keaktivanByTingkatan = pengasuh_dashboard_keaktivan_by_tingkatan($rowsKat, $sudahBerjalan);
        $sdmByTingkatan = pengasuh_dashboard_sdm_by_tingkatan($pdo, $today, $sudahBerjalan);
        $mode = $isLive ? 'live' : 'progress';
    } else {
        $rowsDetail = $rowsKat;
        $keaktivanByTingkatan = pengasuh_dashboard_keaktivan_by_tingkatan_hari_penuh($rowsKat);
        $sdmByTingkatan = ['pembimbing' => [], 'munawib' => []];
        $mode = 'hari';
    }

    $detailLive = pengasuh_dashboard_urutkan_kegiatan(rekap_keaktifan_hari_detail_by_kegiatan($rowsDetail));
    $totalsLive = rekap_keaktifan_hari_totals(rekap_keaktifan_hari_ringkasan_from_detail($detailLive));

    return [
        'mode' => $mode,
        'kegiatanAktif' => $isLive ? $aktif : $sudahBerjalan,
        'keaktivanByTingkatan' => $keaktivanByTingkatan,
        'detailLive' => $detailLive,
        'totalsLive' => $totalsLive,
        'sdmByTingkatan' => $sdmByTingkatan,
    ];
}

/**
 * Cari slot jadwal live untuk kegiatan + tingkatan kartu.
 *
 * @param list<array<string, mixed>> $kegiatanAktif
 * @return array<string, mixed>|null
 */
function pengasuh_dashboard_find_live_slot(array $kegiatanAktif, int $kegiatanId, string $tingkatan): ?array
{
    if ($kegiatanId <= 0 || $kegiatanAktif === []) {
        return null;
    }
    $tkNorm = trim($tingkatan);
    $fallback = null;
    foreach ($kegiatanAktif as $slot) {
        if ((int) ($slot['kegiatan_id'] ?? 0) !== $kegiatanId) {
            continue;
        }
        $slotTk = trim((string) ($slot['tingkatan'] ?? ''));
        if ($slotTk === '' || strcasecmp($slotTk, 'Semua Tingkatan') === 0) {
            $fallback = $slot;
            continue;
        }
        if ($slotTk === $tkNorm) {
            return $slot;
        }
    }

    return $fallback;
}

/**
 * @return array{pembimbing_hadir:bool,munawib_hadir:bool}
 */
function pengasuh_dashboard_check_sdm_hadir(PDO $pdo, string $tanggal, int $kegiatanId, int $pembimbingId): array
{
    $out = ['pembimbing_hadir' => false, 'munawib_hadir' => false];
    if ($kegiatanId <= 0) {
        return $out;
    }

    if (table_exists($pdo, 'presensi_pembimbing')) {
        $st = $pdo->prepare('
            SELECT 1 FROM presensi_pembimbing
            WHERE tanggal = :t AND kegiatan_id = :k AND (:pb = 0 OR pembimbing_id = :pb)
            LIMIT 1
        ');
        $st->execute(['t' => $tanggal, 'k' => $kegiatanId, 'pb' => $pembimbingId]);
        $out['pembimbing_hadir'] = (bool) $st->fetchColumn();
    }

    if (table_exists($pdo, 'presensi_munawib')) {
        $st = $pdo->prepare('
            SELECT 1 FROM presensi_munawib
            WHERE tanggal = :t AND kegiatan_id = :k
            LIMIT 1
        ');
        $st->execute(['t' => $tanggal, 'k' => $kegiatanId]);
        $out['munawib_hadir'] = (bool) $st->fetchColumn();
    }

    return $out;
}

/**
 * Label keterangan SDM untuk badge kartu kegiatan.
 *
 * @return list<array{text:string,variant:string}>
 */
function pengasuh_dashboard_sdm_status_labels(bool $pembimbingHadir, bool $munawibHadir): array
{
    return [
        [
            'text' => $pembimbingHadir ? 'Pembimbing hadir' : 'Pembimbing belum hadir',
            'variant' => $pembimbingHadir ? 'pb' : 'danger',
        ],
        [
            'text' => $munawibHadir ? 'Munawib hadir' : 'Munawib belum hadir',
            'variant' => $munawibHadir ? 'mw' : 'danger',
        ],
    ];
}

/**
 * Pembimbing terjadwal untuk kartu kegiatan (slot live atau jadwal hari ini).
 */
function pengasuh_dashboard_pembimbing_id_for_card(
    PDO $pdo,
    string $tanggal,
    int $kegiatanId,
    string $tingkatan
): int {
    if ($kegiatanId <= 0 || !table_exists($pdo, 'jadwal_kegiatan')) {
        return 0;
    }
    if (!column_exists($pdo, 'jadwal_kegiatan', 'pembimbing_id')) {
        return 0;
    }

    $hariKe = (int) date('N', strtotime($tanggal));
    $tkNorm = trim($tingkatan);

    $st = $pdo->prepare('
        SELECT COALESCE(j.pembimbing_id, 0) AS pembimbing_id
        FROM jadwal_kegiatan j
        WHERE j.kegiatan_id = :kid
          AND (j.hari_ke = 0 OR j.hari_ke = :hk)
          AND (
              j.tingkatan = :tk
              OR j.tingkatan = ""
              OR j.tingkatan = "Semua Tingkatan"
          )
        ORDER BY
          CASE
            WHEN j.tingkatan = :tk2 THEN 0
            WHEN j.tingkatan IN ("", "Semua Tingkatan") THEN 1
            ELSE 2
          END,
          j.jam_mulai ASC
        LIMIT 1
    ');
    $st->execute([
        'kid' => $kegiatanId,
        'hk' => $hariKe,
        'tk' => $tkNorm,
        'tk2' => $tkNorm,
    ]);

    return (int) ($st->fetchColumn() ?: 0);
}

/**
 * @param list<array<string, mixed>> $kegiatanAktif
 * @return list<array{text:string,variant:string}>
 */
function pengasuh_dashboard_sdm_status_for_card(
    PDO $pdo,
    string $tanggal,
    int $kegiatanId,
    string $tingkatan,
    array $kegiatanAktif
): array {
    if ($kegiatanId <= 0) {
        return [];
    }

    $slot = pengasuh_dashboard_find_live_slot($kegiatanAktif, $kegiatanId, $tingkatan);
    $pbId = $slot !== null
        ? (int) ($slot['pembimbing_id'] ?? 0)
        : pengasuh_dashboard_pembimbing_id_for_card($pdo, $tanggal, $kegiatanId, $tingkatan);

    $sdm = pengasuh_dashboard_check_sdm_hadir($pdo, $tanggal, $kegiatanId, $pbId);

    return pengasuh_dashboard_sdm_status_labels(
        (bool) $sdm['pembimbing_hadir'],
        (bool) $sdm['munawib_hadir']
    );
}

/**
 * SDM pembimbing & munawib pada slot berlangsung, dikelompokkan per tingkatan.
 *
 * @param list<array<string, mixed>> $kegiatanAktif
 * @return array{
 *   pembimbing: list<array{tingkatan:string,masalah:int,items:list<array<string,mixed>>}>,
 *   munawib: list<array{tingkatan:string,masalah:int,items:list<array<string,mixed>>}>
 * }
 */
function pengasuh_dashboard_sdm_by_tingkatan(PDO $pdo, string $tanggal, array $kegiatanAktif): array
{
    $empty = ['pembimbing' => [], 'munawib' => []];
    if ($kegiatanAktif === [] || !table_exists($pdo, 'jadwal_kegiatan')) {
        return $empty;
    }

    /** @var array<string, list<array<string, mixed>>> $pbByTk */
    $pbByTk = [];
    /** @var array<string, list<array<string, mixed>>> $mwByTk */
    $mwByTk = [];

    foreach ($kegiatanAktif as $slot) {
        $tk = trim((string) ($slot['tingkatan'] ?? ''));
        if ($tk === '' || strcasecmp($tk, 'Semua Tingkatan') === 0) {
            $tk = 'Semua Tingkatan';
        }
        $kid = (int) ($slot['kegiatan_id'] ?? 0);
        if ($kid <= 0) {
            continue;
        }
        $namaKeg = (string) ($slot['nama_kegiatan'] ?? 'Kegiatan');
        $jam = substr((string) ($slot['jam_mulai'] ?? ''), 0, 5) . '–' . substr((string) ($slot['jam_selesai'] ?? ''), 0, 5);

        $pbId = (int) ($slot['pembimbing_id'] ?? 0);
        $pbNama = trim((string) ($slot['nama_pembimbing'] ?? ''));
        if ($pbId > 0 || $pbNama !== '') {
            $sdm = pengasuh_dashboard_check_sdm_hadir($pdo, $tanggal, $kid, $pbId);
            $pbHadir = (bool) $sdm['pembimbing_hadir'];
            $pbByTk[$tk][] = [
                'id' => $pbId,
                'nama' => $pbNama !== '' ? $pbNama : 'Pembimbing',
                'kegiatan' => $namaKeg,
                'jam' => $jam,
                'hadir' => $pbHadir,
                'masalah' => $pbHadir ? 0 : 1,
            ];
        }

        if (table_exists($pdo, 'munawib_penugasan') && table_exists($pdo, 'munawib')) {
            require_once __DIR__ . '/munawib.php';
            munawib_ensure_schema($pdo);
            $stMw = $pdo->prepare('
                SELECT m.id, m.nama
                FROM munawib_penugasan mp
                INNER JOIN munawib m ON m.id = mp.munawib_id
                WHERE mp.status = "AKTIF"
                  AND mp.kegiatan_id = :k
                  AND mp.tanggal_mulai <= :t AND mp.tanggal_selesai >= :t
                  AND COALESCE(m.is_aktif, 1) = 1
                ORDER BY ' . munawib_list_order_by_induk_sql('m') . '
            ');
            $stMw->execute(['k' => $kid, 't' => $tanggal]);
            foreach ($stMw->fetchAll(PDO::FETCH_ASSOC) ?: [] as $mw) {
                $mid = (int) ($mw['id'] ?? 0);
                $sdm = pengasuh_dashboard_check_sdm_hadir($pdo, $tanggal, $kid, $pbId);
                $mwHadir = (bool) $sdm['munawib_hadir'];
                $mwByTk[$tk][] = [
                    'id' => $mid,
                    'nama' => (string) ($mw['nama'] ?? 'Munawib'),
                    'kegiatan' => $namaKeg,
                    'jam' => $jam,
                    'hadir' => $mwHadir,
                    'masalah' => $mwHadir ? 0 : 1,
                ];
            }
        } elseif (table_exists($pdo, 'presensi_munawib')) {
            $sdm = pengasuh_dashboard_check_sdm_hadir($pdo, $tanggal, $kid, $pbId);
            $mwHadir = (bool) $sdm['munawib_hadir'];
            if (!$mwHadir) {
                $mwByTk[$tk][] = [
                    'id' => 0,
                    'nama' => 'Munawib belum scan',
                    'kegiatan' => $namaKeg,
                    'jam' => $jam,
                    'hadir' => false,
                    'masalah' => 1,
                ];
            }
        }
    }

    $groupFn = static function (array $byTk): array {
        $groups = [];
        foreach ($byTk as $tk => $items) {
            usort(
                $items,
                static fn (array $a, array $b): int => ($b['masalah'] <=> $a['masalah']) ?: strcmp((string) $a['nama'], (string) $b['nama'])
            );
            $masalah = 0;
            foreach ($items as $it) {
                $masalah += (int) ($it['masalah'] ?? 0);
            }
            $groups[] = [
                'tingkatan' => (string) $tk,
                'masalah' => $masalah,
                'items' => $items,
            ];
        }
        usort(
            $groups,
            static fn (array $a, array $b): int => ($b['masalah'] <=> $a['masalah']) ?: strcmp((string) $a['tingkatan'], (string) $b['tingkatan'])
        );

        return $groups;
    };

    return [
        'pembimbing' => $groupFn($pbByTk),
        'munawib' => $groupFn($mwByTk),
    ];
}
