<?php

declare(strict_types=1);

require_once __DIR__ . '/datetime_display.php';

require_once __DIR__ . '/app.php';

function jadwal_tampilan_grup(PDO $pdo): string
{
    $g = strtolower(trim((string) app_setting($pdo, 'jadwal_tampilan_grup', 'kegiatan')));

    return in_array($g, ['kegiatan', 'tingkatan', 'pembimbing'], true) ? $g : 'kegiatan';
}

function jadwal_simpan_tampilan_grup(PDO $pdo, string $grup): void
{
    $grup = strtolower(trim($grup));
    if (!in_array($grup, ['kegiatan', 'tingkatan', 'pembimbing'], true)) {
        $grup = 'kegiatan';
    }
    save_setting($pdo, 'jadwal_tampilan_grup', $grup);
}

/** Kunci slot jam untuk pengelompokan (jam berbeda = baris grup terpisah). */
function jadwal_slot_jam_key(array $row): string
{
    return jadwal_jam_ringkas($row);
}

/**
 * @param list<array<string, mixed>> $jadwalList
 * @return array<string, array<int, list<array<string, mixed>>>>
 */
function jadwal_kelompokkan_per_tingkatan(array $jadwalList): array
{
    $flat = jadwal_kelompokkan_dengan_slot_jam($jadwalList, static function (array $row): string {
        return (string) ($row['tingkatan'] ?? '-');
    });
    $out = [];
    foreach ($flat as $tg => $byJam) {
        foreach ($byJam as $byHari) {
            foreach ($byHari as $hk => $items) {
                if (!isset($out[$tg][$hk])) {
                    $out[$tg][$hk] = [];
                }
                foreach ($items as $item) {
                    $out[$tg][$hk][] = $item;
                }
            }
        }
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $jadwalList
 * @return array<string, array<int, list<array<string, mixed>>>>
 */
function jadwal_kelompokkan_per_kegiatan(array $jadwalList): array
{
    return jadwal_kelompokkan_dengan_hari_jam($jadwalList, static function (array $row): string {
        $nama = trim((string) ($row['nama_kegiatan'] ?? ''));

        return $nama !== '' ? $nama : '—';
    });
}

/**
 * @param list<array<string, mixed>> $jadwalList
 * @return array<string, array<string, array<int, list<array<string, mixed>>>>>
 */
function jadwal_kelompokkan_per_pembimbing(array $jadwalList): array
{
    return jadwal_kelompokkan_dengan_hari_jam($jadwalList, static function (array $row): string {
        $nama = trim((string) ($row['nama_pembimbing'] ?? ''));
        if ($nama === '' || $nama === '-') {
            return 'Belum ada pembimbing';
        }

        return $nama;
    });
}

/**
 * Grup utama → hari → slot jam → baris jadwal.
 *
 * @param list<array<string, mixed>> $jadwalList
 * @param callable(array<string, mixed>): string $grupLabelFn
 * @return array<string, array<int, array<string, list<array<string, mixed>>>>>
 */
function jadwal_kelompokkan_dengan_hari_jam(array $jadwalList, callable $grupLabelFn): array
{
    $out = [];
    foreach ($jadwalList as $row) {
        $grup = $grupLabelFn($row);
        $hk = (int) ($row['hari_ke'] ?? 0);
        $jamKey = jadwal_slot_jam_key($row);
        if (!isset($out[$grup])) {
            $out[$grup] = [];
        }
        if (!isset($out[$grup][$hk])) {
            $out[$grup][$hk] = [];
        }
        if (!isset($out[$grup][$hk][$jamKey])) {
            $out[$grup][$hk][$jamKey] = [];
        }
        $out[$grup][$hk][$jamKey][] = $row;
    }

    return $out;
}

/**
 * Gabung baris dengan kegiatan/hari/jam/pembimbing/tempat sama → satu baris (tingkatan digabung).
 *
 * @param list<array<string, mixed>> $items
 * @return list<array<string, mixed>>
 */
function jadwal_gabung_baris_serupa(array $items): array
{
    $map = [];
    foreach ($items as $item) {
        $key = implode('|', [
            (int) ($item['kegiatan_id'] ?? 0),
            (int) ($item['hari_ke'] ?? 0),
            jadwal_norm_jam((string) ($item['jam_mulai'] ?? '')),
            jadwal_norm_jam((string) ($item['jam_selesai'] ?? '')),
            (int) ($item['pembimbing_id'] ?? 0),
            trim((string) ($item['tempat'] ?? '')),
        ]);
        if (!isset($map[$key])) {
            $map[$key] = $item;
            $map[$key]['_merge_ids'] = [(int) ($item['id'] ?? 0)];
            $tk = trim((string) ($item['tingkatan'] ?? ''));
            $map[$key]['_tingkatan_list'] = $tk !== '' ? [$tk] : [];
        } else {
            $id = (int) ($item['id'] ?? 0);
            if ($id > 0 && !in_array($id, $map[$key]['_merge_ids'], true)) {
                $map[$key]['_merge_ids'][] = $id;
            }
            $tk = trim((string) ($item['tingkatan'] ?? ''));
            if ($tk !== '' && !in_array($tk, $map[$key]['_tingkatan_list'], true)) {
                $map[$key]['_tingkatan_list'][] = $tk;
            }
        }
    }
    $out = array_values($map);
    usort($out, static function (array $a, array $b): int {
        $c = strcmp((string) ($a['jam_mulai'] ?? ''), (string) ($b['jam_mulai'] ?? ''));
        if ($c !== 0) {
            return $c;
        }

        return strcmp((string) ($a['tingkatan'] ?? ''), (string) ($b['tingkatan'] ?? ''));
    });

    return $out;
}

/**
 * @param array<string, array<int, array<string, list<array<string, mixed>>>>> $grouped
 */
function jadwal_urutkan_grup_hari_jam(array &$grouped): void
{
    foreach ($grouped as &$byHari) {
        ksort($byHari, SORT_NUMERIC);
        foreach ($byHari as &$byJam) {
            uksort($byJam, static fn (string $a, string $b): int => strcmp($a, $b));
            foreach ($byJam as &$items) {
                usort($items, static function (array $x, array $y): int {
                    return strcmp((string) ($x['tingkatan'] ?? ''), (string) ($y['tingkatan'] ?? ''));
                });
            }
            unset($items);
        }
        unset($byJam);
    }
    unset($byHari);
}

/**
 * Grup utama → slot jam → hari → baris jadwal (hari langsung masuk per blok).
 *
 * @param list<array<string, mixed>> $jadwalList
 * @param callable(array<string, mixed>): string $grupLabelFn
 * @return array<string, array<string, array<int, list<array<string, mixed>>>>>
 */
function jadwal_kelompokkan_dengan_slot_jam(array $jadwalList, callable $grupLabelFn): array
{
    $out = [];
    foreach ($jadwalList as $row) {
        $grup = $grupLabelFn($row);
        $jamKey = jadwal_slot_jam_key($row);
        $hk = (int) ($row['hari_ke'] ?? 0);
        if (!isset($out[$grup])) {
            $out[$grup] = [];
        }
        if (!isset($out[$grup][$jamKey])) {
            $out[$grup][$jamKey] = [];
        }
        if (!isset($out[$grup][$jamKey][$hk])) {
            $out[$grup][$jamKey][$hk] = [];
        }
        $out[$grup][$jamKey][$hk][] = $row;
    }

    return $out;
}

/**
 * @param array<string, array<string, array<int, list<array<string, mixed>>>>> $grouped
 */
function jadwal_urutkan_grup_slot_jam(array &$grouped): void
{
    foreach ($grouped as &$byJam) {
        uksort($byJam, static function (string $a, string $b): int {
            return strcmp($a, $b);
        });
        foreach ($byJam as &$byHari) {
            ksort($byHari, SORT_NUMERIC);
            foreach ($byHari as &$items) {
                usort($items, static function (array $x, array $y): int {
                    $c = strcmp((string) ($x['jam_mulai'] ?? ''), (string) ($y['jam_mulai'] ?? ''));
                    if ($c !== 0) {
                        return $c;
                    }

                    return strcmp((string) ($x['tingkatan'] ?? ''), (string) ($y['tingkatan'] ?? ''));
                });
            }
            unset($items);
        }
        unset($byHari);
    }
    unset($byJam);
}

/**
 * @param array<string, array<int, list<array<string, mixed>>>> $grouped
 */
function jadwal_urutkan_grup_hari(array &$grouped): void
{
    foreach ($grouped as &$byHari) {
        ksort($byHari, SORT_NUMERIC);
        foreach ($byHari as &$items) {
            usort($items, static function (array $a, array $b): int {
                $c = strcmp((string) ($a['jam_mulai'] ?? ''), (string) ($b['jam_mulai'] ?? ''));
                if ($c !== 0) {
                    return $c;
                }

                return strcmp((string) ($a['tingkatan'] ?? ''), (string) ($b['tingkatan'] ?? ''));
            });
        }
        unset($items);
    }
    unset($byHari);
}

/**
 * @param list<array<string, mixed>> $kegiatanAktif
 * @return array<string, list<array<string, mixed>>>
 */
function jadwal_kelompokkan_kegiatan_aktif(array $kegiatanAktif): array
{
    $out = [];
    foreach ($kegiatanAktif as $row) {
        $nama = trim((string) ($row['nama_kegiatan'] ?? ''));
        if ($nama === '') {
            $nama = '—';
        }
        $out[$nama][] = $row;
    }
    foreach ($out as &$rows) {
        usort($rows, static function (array $a, array $b): int {
            $c = strcmp((string) ($a['jam_mulai'] ?? ''), (string) ($b['jam_mulai'] ?? ''));
            if ($c !== 0) {
                return $c;
            }

            return strcmp((string) ($a['tingkatan'] ?? ''), (string) ($b['tingkatan'] ?? ''));
        });
    }
    unset($rows);

    return $out;
}

/**
 * Matriks jadwal per kegiatan × hari (tampilan ringkas sekali lihat).
 *
 * @param list<array<string,mixed>> $jadwalList
 * @param array<int,string> $hariLabels
 * @return array{order:list<string>,matrix:array<string,array<int,list<array<string,mixed>>>>,hari_cols:list<int>}
 */
function jadwal_matrix_per_kegiatan(array $jadwalList, array $hariLabels): array
{
    $matrix = [];
    $order = [];
    foreach ($jadwalList as $row) {
        $nama = trim((string) ($row['nama_kegiatan'] ?? ''));
        if ($nama === '') {
            $nama = '—';
        }
        $hk = (int) ($row['hari_ke'] ?? 0);
        if (!isset($matrix[$nama])) {
            $matrix[$nama] = [];
            $order[] = $nama;
        }
        if (!isset($matrix[$nama][$hk])) {
            $matrix[$nama][$hk] = [];
        }
        $matrix[$nama][$hk][] = $row;
    }

    foreach ($matrix as &$byHari) {
        foreach ($byHari as &$items) {
            usort($items, static function (array $a, array $b): int {
                $c = strcmp((string) ($a['jam_mulai'] ?? ''), (string) ($b['jam_mulai'] ?? ''));
                if ($c !== 0) {
                    return $c;
                }

                return strcmp((string) ($a['tingkatan'] ?? ''), (string) ($b['tingkatan'] ?? ''));
            });
        }
        unset($items);
    }
    unset($byHari);

    sort($order, SORT_NATURAL | SORT_FLAG_CASE);

    $hariCols = [];
    foreach (array_keys($hariLabels) as $k) {
        $hariCols[] = (int) $k;
    }
    usort($hariCols, static function (int $a, int $b): int {
        if ($a === 0) {
            return 1;
        }
        if ($b === 0) {
            return -1;
        }

        return $a <=> $b;
    });

    return ['order' => $order, 'matrix' => $matrix, 'hari_cols' => $hariCols];
}

/**
 * Baris jadwal untuk peta tabel: urut kegiatan → hari → jam → tingkatan.
 *
 * @param list<array<string,mixed>> $jadwalList
 * @return list<array<string,mixed>>
 */
function jadwal_peta_rows_sorted(array $jadwalList): array
{
    $rows = $jadwalList;
    usort($rows, static function (array $a, array $b): int {
        $c = strcasecmp((string) ($a['nama_kegiatan'] ?? ''), (string) ($b['nama_kegiatan'] ?? ''));
        if ($c !== 0) {
            return $c;
        }
        $ha = (int) ($a['hari_ke'] ?? 0);
        $hb = (int) ($b['hari_ke'] ?? 0);
        if ($ha !== $hb) {
            if ($ha === 0) {
                return -1;
            }
            if ($hb === 0) {
                return 1;
            }

            return $ha <=> $hb;
        }
        $c = strcmp((string) ($a['jam_mulai'] ?? ''), (string) ($b['jam_mulai'] ?? ''));
        if ($c !== 0) {
            return $c;
        }

        return strcmp((string) ($a['tingkatan'] ?? ''), (string) ($b['tingkatan'] ?? ''));
    });

    return $rows;
}

/**
 * Peta jadwal dengan baris digabung: kegiatan + hari + jam sama → satu baris (tingkatan digabung).
 *
 * @param list<array<string,mixed>> $jadwalList
 * @return list<array<string,mixed>>
 */
function jadwal_peta_rows_gabung(array $jadwalList): array
{
    $sorted = jadwal_peta_rows_sorted($jadwalList);
    $out = [];
    foreach ($sorted as $row) {
        $key = implode('|', [
            trim((string) ($row['nama_kegiatan'] ?? '')),
            (int) ($row['hari_ke'] ?? 0),
            jadwal_norm_jam((string) ($row['jam_mulai'] ?? '')),
            jadwal_norm_jam((string) ($row['jam_selesai'] ?? '')),
            (int) ($row['pembimbing_id'] ?? 0),
            trim((string) ($row['tempat'] ?? '')),
        ]);
        if (!isset($out[$key])) {
            $out[$key] = $row;
            $out[$key]['_merge_ids'] = [(int) ($row['id'] ?? 0)];
            $tk = trim((string) ($row['tingkatan'] ?? ''));
            $out[$key]['_tingkatan_list'] = $tk !== '' ? [$tk] : [];
            $out[$key]['tingkatan'] = $tk;
        } else {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0 && !in_array($id, $out[$key]['_merge_ids'], true)) {
                $out[$key]['_merge_ids'][] = $id;
            }
            $tk = trim((string) ($row['tingkatan'] ?? ''));
            if ($tk !== '' && !in_array($tk, $out[$key]['_tingkatan_list'], true)) {
                $out[$key]['_tingkatan_list'][] = $tk;
            }
            $out[$key]['tingkatan'] = implode(', ', $out[$key]['_tingkatan_list']);
        }
    }

    return array_values($out);
}

/** Slug warna badge hari (0–7). */
function jadwal_hari_badge_slug(int $hariKe): string
{
    return match ($hariKe) {
        0 => 'semua',
        1 => 'sen',
        2 => 'sel',
        3 => 'rab',
        4 => 'kam',
        5 => 'jum',
        6 => 'sab',
        7 => 'min',
        default => 'lain',
    };
}

/** Format jam jadwal singkat: 07:00–08:30 (24 jam). */
function jadwal_jam_ringkas(array $row): string
{
    return app_format_jam_rentang(
        (string) ($row['jam_mulai'] ?? ''),
        (string) ($row['jam_selesai'] ?? '')
    );
}

function jadwal_norm_jam(string $jam): string
{
    $jam = trim($jam);
    if (preg_match('/^\d{1,2}:\d{2}/', $jam)) {
        return substr($jam, 0, 5) . ':00';
    }

    return '00:00:00';
}

/** Apakah dua rentang jam saling tumpang (bukan hanya tepat sama). */
function jadwal_waktu_bentrok(string $mulai1, string $selesai1, string $mulai2, string $selesai2): bool
{
    $a1 = jadwal_norm_jam($mulai1);
    $b1 = jadwal_norm_jam($selesai1);
    $a2 = jadwal_norm_jam($mulai2);
    $b2 = jadwal_norm_jam($selesai2);
    if ($b1 <= $a1 || $b2 <= $a2) {
        return false;
    }

    return $a1 < $b2 && $a2 < $b1;
}

function jadwal_hari_bentrok(int $hariA, int $hariB): bool
{
    return $hariA === 0 || $hariB === 0 || $hariA === $hariB;
}

function jadwal_tingkatan_bentrok(string $tingkatanA, string $tingkatanB): bool
{
    $a = trim($tingkatanA);
    $b = trim($tingkatanB);
    if ($a === '' || $b === '') {
        return false;
    }
    if (strcasecmp($a, 'Semua Tingkatan') === 0 || strcasecmp($b, 'Semua Tingkatan') === 0) {
        return true;
    }

    return strcasecmp($a, $b) === 0;
}

/**
 * Cari jadwal yang bentrok (tingkatan + hari + jam tumpang).
 *
 * @return array<string,mixed>|null baris jadwal bentrok
 */
function jadwal_cek_bentrok(PDO $pdo, string $tingkatan, int $hariKe, string $jamMulai, string $jamSelesai, int $excludeId = 0): ?array
{
    if (!table_exists($pdo, 'jadwal_kegiatan') || !table_exists($pdo, 'kegiatan')) {
        return null;
    }
    $sql = '
        SELECT j.id, j.tingkatan, j.hari_ke, j.jam_mulai, j.jam_selesai, k.nama_kegiatan
        FROM jadwal_kegiatan j
        INNER JOIN kegiatan k ON k.id = j.kegiatan_id
    ';
    if ($excludeId > 0) {
        $sql .= ' WHERE j.id <> :xid';
        $st = $pdo->prepare($sql);
        $st->execute(['xid' => $excludeId]);
    } else {
        $st = $pdo->query($sql);
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        if (!jadwal_tingkatan_bentrok($tingkatan, (string) ($row['tingkatan'] ?? ''))) {
            continue;
        }
        if (!jadwal_hari_bentrok($hariKe, (int) ($row['hari_ke'] ?? 0))) {
            continue;
        }
        if (jadwal_waktu_bentrok($jamMulai, $jamSelesai, (string) ($row['jam_mulai'] ?? ''), (string) ($row['jam_selesai'] ?? ''))) {
            return $row;
        }
    }

    return null;
}

/** @param array<int,string> $hariLabels */
/**
 * Slot jadwal sejenis (kegiatan + pembimbing + jam sama) untuk edit massal hari/tingkatan.
 *
 * @return list<array<string, mixed>>
 */
function jadwal_slot_sejenis(PDO $pdo, int $jadwalId): array
{
    if ($jadwalId <= 0 || !table_exists($pdo, 'jadwal_kegiatan')) {
        return [];
    }
    $st = $pdo->prepare('SELECT kegiatan_id, pembimbing_id, jam_mulai, jam_selesai FROM jadwal_kegiatan WHERE id = :id LIMIT 1');
    $st->execute(['id' => $jadwalId]);
    $base = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($base)) {
        return [];
    }
    $st2 = $pdo->prepare('
        SELECT id, tingkatan, hari_ke, jam_mulai, jam_selesai, pembimbing_id, kegiatan_id, tempat
        FROM jadwal_kegiatan
        WHERE kegiatan_id = :kg
          AND COALESCE(pembimbing_id, 0) = COALESCE(:pb, 0)
          AND jam_mulai = :jm
          AND jam_selesai = :js
        ORDER BY hari_ke ASC, tingkatan ASC, id ASC
    ');
    $st2->execute([
        'kg' => (int) ($base['kegiatan_id'] ?? 0),
        'pb' => $base['pembimbing_id'] ?? null,
        'jm' => (string) ($base['jam_mulai'] ?? ''),
        'js' => (string) ($base['jam_selesai'] ?? ''),
    ]);

    return $st2->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** @param list<array<string, mixed>> $slots */
function jadwal_slot_sejenis_ids(array $slots): array
{
    $ids = [];
    foreach ($slots as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }

    return array_values($ids);
}

function jadwal_pesan_bentrok(array $bentrok, array $hariLabels): string
{
    $hk = (int) ($bentrok['hari_ke'] ?? 0);
    $hari = $hariLabels[$hk] ?? ('Hari ' . $hk);
    $jam = jadwal_jam_ringkas($bentrok);

    return sprintf(
        'Bentrok dengan jadwal "%s" (%s, %s, %s). Santri tingkatan sama tidak boleh dua kegiatan bersamaan.',
        (string) ($bentrok['nama_kegiatan'] ?? '—'),
        (string) ($bentrok['tingkatan'] ?? '—'),
        $hari,
        $jam
    );
}
