<?php

declare(strict_types=1);

require_once __DIR__ . '/akademik.php';

/** Hari besar Islam menurut tanggal hijriyah (bulan, hari) => nama. */
function akademik_hari_besar_islam_rules(): array
{
    return [
        '1-1' => 'Tahun Baru Hijriyah',
        '1-10' => 'Asyura',
        '3-12' => 'Maulid Nabi Muhammad SAW',
        '7-27' => 'Isra Mi\'raj Nabi Muhammad SAW',
        '9-1' => 'Awal Ramadan',
        '10-1' => 'Idul Fitri',
        '10-2' => 'Idul Fitri (hari ke-2)',
        '12-9' => 'Hari Arafah',
        '12-10' => 'Idul Adha',
    ];
}

/** Libur nasional tetap (bulan Masehi, hari) => nama. */
function akademik_libur_nasional_tetap_rules(): array
{
    return [
        '1-1' => 'Tahun Baru Masehi',
        '5-1' => 'Hari Buruh Internasional',
        '6-1' => 'Hari Lahir Pancasila',
        '8-17' => 'Hari Kemerdekaan RI',
        '12-25' => 'Hari Natal',
    ];
}

/**
 * @return array{nama:string,jenis:string,otomatis:bool}|null
 */
function akademik_hari_khusus_pada_tanggal(PDO $pdo, string $tanggalMasehi, array $hijriBulanNama): ?array
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalMasehi)) {
        return null;
    }

    $ts = strtotime($tanggalMasehi);
    if ($ts !== false) {
        $gm = (int) date('n', $ts);
        $gd = (int) date('j', $ts);
        $keyNas = $gm . '-' . $gd;
        $nasional = akademik_libur_nasional_tetap_rules();
        if (isset($nasional[$keyNas])) {
            return ['nama' => $nasional[$keyNas], 'jenis' => 'nasional', 'otomatis' => true];
        }
    }

    $hijri = akademik_hijri_tanggal_sistem($pdo, $tanggalMasehi);
    $k = akademik_hijri_komponen_dari_ymd($hijri, $hijriBulanNama);
    if ($k === null) {
        return null;
    }
    $keyHij = $k['b'] . '-' . $k['h'];
    $islam = akademik_hari_besar_islam_rules();
    if (isset($islam[$keyHij])) {
        return ['nama' => $islam[$keyHij], 'jenis' => 'islam', 'otomatis' => true];
    }

    return null;
}

/**
 * @return list<array{mulai:string,selesai:string,nama:string,jenis:string}>
 */
function akademik_hari_khusus_daftar_tahun_masehi(PDO $pdo, int $gregYear, array $hijriBulanNama): array
{
    $gregYear = max(1970, min(2100, $gregYear));
    $out = [];
    $seen = [];

    foreach (akademik_libur_nasional_tetap_rules() as $md => $nama) {
        [$m, $d] = array_map('intval', explode('-', $md, 2));
        $ymd = sprintf('%04d-%02d-%02d', $gregYear, $m, $d);
        $key = $ymd . '|' . $nama;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = ['mulai' => $ymd, 'selesai' => $ymd, 'nama' => $nama, 'jenis' => 'nasional'];
    }

    $awal = sprintf('%04d-01-01', $gregYear);
    $akhir = sprintf('%04d-12-31', $gregYear);
    $ts = strtotime($awal);
    $endTs = strtotime($akhir);
    if ($ts === false || $endTs === false) {
        return $out;
    }

    for ($t = $ts; $t <= $endTs; $t += 86400) {
        $ymd = date('Y-m-d', $t);
        $ev = akademik_hari_khusus_pada_tanggal($pdo, $ymd, $hijriBulanNama);
        if ($ev === null || $ev['jenis'] !== 'islam') {
            continue;
        }
        $key = $ymd . '|' . $ev['nama'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = ['mulai' => $ymd, 'selesai' => $ymd, 'nama' => $ev['nama'], 'jenis' => 'islam'];
    }

    usort($out, static fn (array $a, array $b): int => strcmp($a['mulai'], $b['mulai']));

    return $out;
}

/** Sinkron libur otomatis (hari besar + libur nasional) ke tabel libur — idempoten per tahun. */
function akademik_libur_sinkron_hari_khusus_tahun(PDO $pdo, int $gregYear, array $hijriBulanNama): int
{
    ensure_akademik_libur_table($pdo);
    $daftar = akademik_hari_khusus_daftar_tahun_masehi($pdo, $gregYear, $hijriBulanNama);
    $cek = $pdo->prepare('
        SELECT id FROM akademik_libur
        WHERE tanggal_mulai = :d1 AND tanggal_selesai = :d2 AND nama = :nama
        LIMIT 1
    ');
    $ins = $pdo->prepare('
        INSERT INTO akademik_libur (tanggal_mulai, tanggal_selesai, nama, catatan, affects_presensi, affects_setoran, affects_penilaian)
        VALUES (:d1, :d2, :nama, :cat, 1, 1, 1)
    ');
    $added = 0;
    foreach ($daftar as $row) {
        $cek->execute(['d1' => $row['mulai'], 'd2' => $row['selesai'], 'nama' => $row['nama']]);
        if ($cek->fetchColumn()) {
            continue;
        }
        $jenis = $row['jenis'] === 'islam' ? 'hari besar Islam' : 'libur nasional';
        $ins->execute([
            'd1' => $row['mulai'],
            'd2' => $row['selesai'],
            'nama' => $row['nama'],
            'cat' => 'otomatis:' . $row['jenis'] . ' (' . $jenis . ')',
        ]);
        $added++;
    }

    return $added;
}

/** Label hijriyah tampilan (tanpa singkatan H/B/T). */
function akademik_hijri_label_tampilan(PDO $pdo, string $tanggalMasehi, array $hijriBulanNama): string
{
    $hijri = akademik_hijri_tanggal_sistem($pdo, $tanggalMasehi);
    $k = akademik_hijri_komponen_dari_ymd($hijri, $hijriBulanNama);
    if ($k === null) {
        return '';
    }

    return sprintf('%d %s %d', $k['h'], $k['bulan_nama'], $k['t']);
}

/** Satu baris ringkas untuk sel kalender: "16 Ramadan". */
function akademik_hijri_ringkas_sel(PDO $pdo, string $tanggalMasehi, array $hijriBulanNama): string
{
    $hijri = akademik_hijri_tanggal_sistem($pdo, $tanggalMasehi);
    $k = akademik_hijri_komponen_dari_ymd($hijri, $hijriBulanNama);
    if ($k === null || $k['h'] < 1) {
        return '';
    }

    return sprintf('%d %s', $k['h'], $k['bulan_nama']);
}
