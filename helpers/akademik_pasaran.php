<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/** Urutan pasaran Jawa (siklus 5 hari). */
function akademik_pasaran_urutan(): array
{
    return ['Legi', 'Pahing', 'Pon', 'Wage', 'Kliwon'];
}

/**
 * Acuan baku: 17 Agustus 1945 = Jumat Legi (umum di Indonesia).
 *
 * @return array{tanggal:string,pasaran_idx:int}
 */
function akademik_pasaran_anchor_default(): array
{
    return ['tanggal' => '1945-08-17', 'pasaran_idx' => 0];
}

/**
 * @return array{tanggal:string,pasaran_idx:int}
 */
function akademik_pasaran_anchor(PDO $pdo): array
{
    $def = akademik_pasaran_anchor_default();
    $tanggal = trim((string) app_setting($pdo, 'akademik_pasaran_anchor_tanggal', ''));
    $nama = trim((string) app_setting($pdo, 'akademik_pasaran_anchor_pasaran', ''));
    if ($tanggal === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
        return $def;
    }
    $urut = akademik_pasaran_urutan();
    $idx = array_search($nama, $urut, true);
    if ($idx === false) {
        return ['tanggal' => $tanggal, 'pasaran_idx' => $def['pasaran_idx']];
    }

    return ['tanggal' => $tanggal, 'pasaran_idx' => (int) $idx];
}

/** Indeks pasaran 0–4 pada tanggal Masehi (tanpa PDO = acuan baku). */
function akademik_pasaran_idx_pada_tanggal(string $tanggalMasehi, ?PDO $pdo = null): int
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalMasehi)) {
        return 0;
    }
    $anchor = $pdo !== null ? akademik_pasaran_anchor($pdo) : akademik_pasaran_anchor_default();
    $ts = strtotime($tanggalMasehi);
    $anchorTs = strtotime($anchor['tanggal']);
    if ($ts === false || $anchorTs === false) {
        return 0;
    }
    $days = (int) floor(($ts - $anchorTs) / 86400);
    $n = count(akademik_pasaran_urutan());

    return (($days + $anchor['pasaran_idx']) % $n + $n) % $n;
}

/** Nama pasaran: Legi, Pahing, Pon, Wage, atau Kliwon. */
function akademik_pasaran_pada_tanggal(string $tanggalMasehi, ?PDO $pdo = null): string
{
    $urut = akademik_pasaran_urutan();
    $idx = akademik_pasaran_idx_pada_tanggal($tanggalMasehi, $pdo);

    return $urut[$idx] ?? 'Legi';
}

/** Kelas CSS untuk warna pasaran di kalender. */
function akademik_pasaran_kelas_css(string $tanggalMasehi, ?PDO $pdo = null): string
{
    $idx = akademik_pasaran_idx_pada_tanggal($tanggalMasehi, $pdo);

    return 'akad-cal-pasaran--' . ($idx + 1);
}

/** Tampilkan pasaran di kalender? (default ya, tanpa setting). */
function akademik_pasaran_tampilkan(PDO $pdo): bool
{
    return app_setting($pdo, 'akademik_pasaran_tampil', '1') !== '0';
}
