<?php

declare(strict_types=1);

require_once __DIR__ . '/payroll_pembimbing.php';
require_once __DIR__ . '/pkpps.php';

/** @return array<int, string> */
function rekap_pembimbing_kehadiran_hari_labels(): array
{
    return [
        1 => 'Sen',
        2 => 'Sel',
        3 => 'Rab',
        4 => 'Kam',
        5 => 'Jum',
        6 => 'Sab',
        7 => 'Min',
    ];
}

/**
 * Baris rekap kehadiran pembimbing per slot jadwal (kegiatan × tanggal).
 *
 * @return list<array{
 *   pembimbing_id:int,
 *   nama_pembimbing:string,
 *   nip:string,
 *   kegiatan_id:int,
 *   nama_kegiatan:string,
 *   tanggal:string,
 *   tanggal_tampil:string,
 *   hari_label:string,
 *   status:string,
 *   status_label:string
 * }>
 */
function rekap_pembimbing_kehadiran_rows(
    PDO $pdo,
    string $startDate,
    string $endDate,
    int $pembimbingId = 0,
    int $kegiatanId = 0
): array {
    payroll_pembimbing_ensure_schema($pdo);
    pkpps_ensure_schema($pdo);

    $expectedSlotsMap = payroll_pembimbing_expected_slots_by_pembimbing($pdo, $startDate, $endDate, $kegiatanId);
    $hadirSlotMap = payroll_pembimbing_hadir_slot_keys_map($pdo, $startDate, $endDate, $kegiatanId);
    $izinDatesMap = payroll_pembimbing_izin_dates_by_pembimbing($pdo, $startDate, $endDate);

    if ($pembimbingId > 0) {
        $expectedSlotsMap = isset($expectedSlotsMap[$pembimbingId])
            ? [$pembimbingId => $expectedSlotsMap[$pembimbingId]]
            : [];
        $hadirSlotMap = isset($hadirSlotMap[$pembimbingId])
            ? [$pembimbingId => $hadirSlotMap[$pembimbingId]]
            : [];
        $izinDatesMap = isset($izinDatesMap[$pembimbingId])
            ? [$pembimbingId => $izinDatesMap[$pembimbingId]]
            : [];
    }

    if ($expectedSlotsMap === []) {
        return [];
    }

    $pembimbingIds = array_keys($expectedSlotsMap);
    $kegiatanIds = [];
    foreach ($expectedSlotsMap as $slots) {
        foreach ((array) $slots as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $kid = (int) ($slot['kegiatan_id'] ?? 0);
            if ($kid > 0) {
                $kegiatanIds[$kid] = true;
            }
        }
    }

    $pembimbingMap = rekap_pembimbing_kehadiran_pembimbing_map($pdo, $pembimbingIds);
    $kegiatanMap = rekap_pembimbing_kehadiran_kegiatan_map($pdo, array_keys($kegiatanIds));
    $hariLabels = rekap_pembimbing_kehadiran_hari_labels();

    $rows = [];
    foreach ($expectedSlotsMap as $pid => $slots) {
        $pid = (int) $pid;
        if ($pid <= 0) {
            continue;
        }
        $pb = $pembimbingMap[$pid] ?? ['nama_pembimbing' => '-', 'nip' => ''];
        $hadirKeys = (array) ($hadirSlotMap[$pid] ?? []);
        $izinDates = (array) ($izinDatesMap[$pid] ?? ['IZIN' => [], 'SAKIT' => []]);
        $izinSet = array_fill_keys(array_merge(
            (array) ($izinDates['IZIN'] ?? []),
            (array) ($izinDates['SAKIT'] ?? [])
        ), true);

        foreach ((array) $slots as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $tanggal = (string) ($slot['tanggal'] ?? '');
            $kid = (int) ($slot['kegiatan_id'] ?? 0);
            if ($tanggal === '' || $kid <= 0) {
                continue;
            }

            $key = $tanggal . '|' . $kid;
            if (isset($hadirKeys[$key])) {
                $status = 'H';
                $statusLabel = 'Hadir';
            } elseif (isset($izinSet[$tanggal])) {
                $status = 'I';
                $statusLabel = 'Izin';
            } else {
                $status = '';
                $statusLabel = '—';
            }

            $ts = strtotime($tanggal) ?: false;
            $rows[] = [
                'pembimbing_id' => $pid,
                'nama_pembimbing' => (string) ($pb['nama_pembimbing'] ?? '-'),
                'nip' => (string) ($pb['nip'] ?? ''),
                'kegiatan_id' => $kid,
                'nama_kegiatan' => (string) ($kegiatanMap[$kid] ?? '-'),
                'tanggal' => $tanggal,
                'tanggal_tampil' => $ts !== false ? date('d/m/Y', $ts) : $tanggal,
                'hari_label' => $ts !== false ? ($hariLabels[(int) date('N', $ts)] ?? '') : '',
                'status' => $status,
                'status_label' => $statusLabel,
            ];
        }
    }

    usort($rows, static function (array $a, array $b): int {
        $cmp = strcmp((string) ($a['nama_pembimbing'] ?? ''), (string) ($b['nama_pembimbing'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }
        $cmp = strcmp((string) ($a['tanggal'] ?? ''), (string) ($b['tanggal'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }

        return strcmp((string) ($a['nama_kegiatan'] ?? ''), (string) ($b['nama_kegiatan'] ?? ''));
    });

    return $rows;
}

/**
 * @param list<array<string,mixed>> $rows
 * @return array{hadir:int,izin:int,tanpa_scan:int,total:int}
 */
function rekap_pembimbing_kehadiran_summary(array $rows): array
{
    $hadir = 0;
    $izin = 0;
    $tanpaScan = 0;
    foreach ($rows as $row) {
        $status = (string) ($row['status'] ?? '');
        if ($status === 'H') {
            $hadir++;
        } elseif ($status === 'I') {
            $izin++;
        } else {
            $tanpaScan++;
        }
    }

    return [
        'hadir' => $hadir,
        'izin' => $izin,
        'tanpa_scan' => $tanpaScan,
        'total' => count($rows),
    ];
}

/**
 * @param list<array<string,mixed>> $rows
 * @return list<array<string,mixed>>
 */
function rekap_pembimbing_kehadiran_filter_tanpa_scan(array $rows): array
{
    return array_values(array_filter($rows, static function (array $row): bool {
        return (string) ($row['status'] ?? '') === '';
    }));
}

/** @param list<int> $ids */
function rekap_pembimbing_kehadiran_pembimbing_map(PDO $pdo, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
    if ($ids === [] || !table_exists($pdo, 'pembimbing')) {
        return [];
    }

    require_once __DIR__ . '/entity_list_sort.php';
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare('
        SELECT id, nama_pembimbing, COALESCE(nip, "") AS nip
        FROM pembimbing
        WHERE id IN (' . $placeholders . ')
        ORDER BY ' . pembimbing_list_order_sql('pembimbing') . '
    ');
    $st->execute($ids);

    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $map[$id] = [
            'nama_pembimbing' => (string) ($row['nama_pembimbing'] ?? '-'),
            'nip' => (string) ($row['nip'] ?? ''),
        ];
    }

    return $map;
}

/** @param list<int> $ids */
function rekap_pembimbing_kehadiran_kegiatan_map(PDO $pdo, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
    if ($ids === [] || !table_exists($pdo, 'kegiatan')) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare('SELECT id, nama_kegiatan FROM kegiatan WHERE id IN (' . $placeholders . ')');
    $st->execute($ids);

    $map = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $map[$id] = (string) ($row['nama_kegiatan'] ?? '-');
    }

    return $map;
}
