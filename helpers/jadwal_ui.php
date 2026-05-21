<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

function jadwal_tampilan_grup(PDO $pdo): string
{
    $g = strtolower(trim((string) app_setting($pdo, 'jadwal_tampilan_grup', 'kegiatan')));

    return in_array($g, ['kegiatan', 'tingkatan'], true) ? $g : 'kegiatan';
}

function jadwal_simpan_tampilan_grup(PDO $pdo, string $grup): void
{
    $grup = strtolower(trim($grup));
    if (!in_array($grup, ['kegiatan', 'tingkatan'], true)) {
        $grup = 'kegiatan';
    }
    save_setting($pdo, 'jadwal_tampilan_grup', $grup);
}

/**
 * @param list<array<string, mixed>> $jadwalList
 * @return array<string, array<int, list<array<string, mixed>>>>
 */
function jadwal_kelompokkan_per_tingkatan(array $jadwalList): array
{
    $out = [];
    foreach ($jadwalList as $row) {
        $tg = (string) ($row['tingkatan'] ?? '-');
        $hk = (int) ($row['hari_ke'] ?? 0);
        if (!isset($out[$tg])) {
            $out[$tg] = [];
        }
        if (!isset($out[$tg][$hk])) {
            $out[$tg][$hk] = [];
        }
        $out[$tg][$hk][] = $row;
    }

    return $out;
}

/**
 * @param list<array<string, mixed>> $jadwalList
 * @return array<string, array<int, list<array<string, mixed>>>>
 */
function jadwal_kelompokkan_per_kegiatan(array $jadwalList): array
{
    $out = [];
    foreach ($jadwalList as $row) {
        $nama = trim((string) ($row['nama_kegiatan'] ?? ''));
        if ($nama === '') {
            $nama = '—';
        }
        $hk = (int) ($row['hari_ke'] ?? 0);
        if (!isset($out[$nama])) {
            $out[$nama] = [];
        }
        if (!isset($out[$nama][$hk])) {
            $out[$nama][$hk] = [];
        }
        $out[$nama][$hk][] = $row;
    }

    return $out;
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
