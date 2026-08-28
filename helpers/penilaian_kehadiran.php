<?php

declare(strict_types=1);

require_once __DIR__ . '/rekap_telat.php';

/**
 * Rumus PRESNA (spreadsheet PRESNA 52).
 * Spek draft 24 Agu 2026 (dasar 100, Alpa×3, Baik >94%) diarsipkan — tidak dipakai.
 *
 * Penalti = Alpa×A + Izin×I + Sakit×S + Telat×T (default 4, 2, 1, 3)
 * Nilai   = Hadir×H + (N.HARI − Hadir) − penalti (Hadir default ×1 = N.HARI − penalti)
 * Persen  = Nilai ÷ N.HARI
 */
const PENILAIAN_KEHADIRAN_BOBOT_ALPA = 4;
const PENILAIAN_KEHADIRAN_BOBOT_IZIN = 2;
const PENILAIAN_KEHADIRAN_BOBOT_SAKIT = 1;
const PENILAIAN_KEHADIRAN_BOBOT_TELAT = 3;
const PENILAIAN_KEHADIRAN_BOBOT_HADIR = 1;

function penilaian_kehadiran_bobot_clamp(int $n): int
{
    return max(0, min(10, $n));
}

/**
 * @return array{alpa:int,izin:int,sakit:int,telat:int,hadir:int}
 */
function penilaian_kehadiran_bobot(?PDO $pdo = null): array
{
    if (!($pdo instanceof PDO)) {
        $pdo = $GLOBALS['pdo'] ?? null;
    }
    $defaults = [
        'alpa' => PENILAIAN_KEHADIRAN_BOBOT_ALPA,
        'izin' => PENILAIAN_KEHADIRAN_BOBOT_IZIN,
        'sakit' => PENILAIAN_KEHADIRAN_BOBOT_SAKIT,
        'telat' => PENILAIAN_KEHADIRAN_BOBOT_TELAT,
        'hadir' => PENILAIAN_KEHADIRAN_BOBOT_HADIR,
    ];
    if (!($pdo instanceof PDO)) {
        return $defaults;
    }

    return [
        'alpa' => penilaian_kehadiran_bobot_clamp((int) app_setting($pdo, 'penilaian_bobot_alpa', (string) $defaults['alpa'])),
        'izin' => penilaian_kehadiran_bobot_clamp((int) app_setting($pdo, 'penilaian_bobot_izin', (string) $defaults['izin'])),
        'sakit' => penilaian_kehadiran_bobot_clamp((int) app_setting($pdo, 'penilaian_bobot_sakit', (string) $defaults['sakit'])),
        'telat' => penilaian_kehadiran_bobot_clamp((int) app_setting($pdo, 'penilaian_bobot_telat', (string) $defaults['telat'])),
        'hadir' => penilaian_kehadiran_bobot_clamp((int) app_setting($pdo, 'penilaian_bobot_hadir', (string) $defaults['hadir'])),
    ];
}

function penilaian_kehadiran_bobot_fingerprint(?PDO $pdo = null): string
{
    $b = penilaian_kehadiran_bobot($pdo);

    return $b['alpa'] . ',' . $b['izin'] . ',' . $b['sakit'] . ',' . $b['telat'] . ',' . $b['hadir'];
}

/** Teks rumus ABSENSI sesuai bobot tersimpan (Hadir ×1 = N.HARI − penalti). */
function penilaian_kehadiran_rumus_absensi(?PDO $pdo = null): string
{
    $b = penilaian_kehadiran_bobot($pdo);
    $penalti = sprintf('Alpa×%d + Izin×%d + Sakit×%d + Telat×%d', $b['alpa'], $b['izin'], $b['sakit'], $b['telat']);
    if ($b['hadir'] === 1) {
        return 'ABSENSI = N.HARI − (' . $penalti . '), minimum 0';
    }

    return sprintf('ABSENSI = Hadir×%d + (N.HARI − Hadir) − (%s), minimum 0', $b['hadir'], $penalti);
}

/** @return list<string> */
function penilaian_kehadiran_predikat_urutan(): array
{
    return ['Baik', 'Cukup', 'Sedang', 'Kurang', 'Buruk'];
}

/**
 * Predikat dari persen kehadiran (1 desimal).
 * Celah 20,1–20,9 masuk Kurang (spek 21–40).
 */
/** Kode ENUM/form dari label predikat PRESNA. */
function penilaian_kehadiran_kode_dari_predikat(string $predikat): string
{
    return match (trim($predikat)) {
        'Baik', 'Bagus' => 'BAIK',
        'Cukup' => 'CUKUP',
        'Sedang' => 'SEDANG',
        'Kurang' => 'KURANG',
        'Buruk' => 'BURUK',
        default => '',
    };
}

function penilaian_kehadiran_predikat(float $persen): string
{
    if ($persen <= 20.0) {
        return 'Buruk';
    }
    if ($persen <= 40.0) {
        return 'Kurang';
    }
    if ($persen <= 60.0) {
        return 'Sedang';
    }
    if ($persen <= 80.0) {
        return 'Cukup';
    }

    return 'Baik';
}

/**
 * @param array{alpa?:int,izin?:int,sakit?:int,telat?:int,hadir?:int}|null $bobot
 * @return array{akumulasi:int,penalti:int,n_hari:int,nilai:int,persen:float,predikat:string}
 */
function penilaian_kehadiran_hitung(int $alpa, int $izin, int $telat, int $sakit, int $nHari = 0, int $hadir = 0, ?array $bobot = null): array
{
    $bobot = $bobot ?? penilaian_kehadiran_bobot();
    $wAlpa = penilaian_kehadiran_bobot_clamp((int) ($bobot['alpa'] ?? PENILAIAN_KEHADIRAN_BOBOT_ALPA));
    $wIzin = penilaian_kehadiran_bobot_clamp((int) ($bobot['izin'] ?? PENILAIAN_KEHADIRAN_BOBOT_IZIN));
    $wSakit = penilaian_kehadiran_bobot_clamp((int) ($bobot['sakit'] ?? PENILAIAN_KEHADIRAN_BOBOT_SAKIT));
    $wTelat = penilaian_kehadiran_bobot_clamp((int) ($bobot['telat'] ?? PENILAIAN_KEHADIRAN_BOBOT_TELAT));
    $wHadir = penilaian_kehadiran_bobot_clamp((int) ($bobot['hadir'] ?? PENILAIAN_KEHADIRAN_BOBOT_HADIR));
    $akumulasi = ($alpa * $wAlpa) + ($izin * $wIzin) + ($sakit * $wSakit) + ($telat * $wTelat);
    $nHari = max(0, $nHari);
    $hadir = max(0, $hadir);
    $nilai = $nHari > 0 ? max(0, ($hadir * $wHadir) + ($nHari - $hadir) - $akumulasi) : 0;
    $persen = $nHari > 0 ? round(($nilai / $nHari) * 100, 1) : 0.0;

    return [
        'akumulasi' => $akumulasi,
        'penalti' => $akumulasi,
        'n_hari' => $nHari,
        'nilai' => $nilai,
        'persen' => $persen,
        'predikat' => $nHari > 0 ? penilaian_kehadiran_predikat($persen) : '',
    ];
}

/** Kelas Bootstrap penuh untuk badge predikat. */
function penilaian_kehadiran_badge_class(string $predikat): string
{
    return match (trim($predikat)) {
        'Baik', 'Bagus' => 'text-bg-success',
        'Cukup' => 'text-bg-info',
        'Sedang' => 'text-bg-warning',
        'Kurang' => 'text-bg-kurang',
        'Buruk' => 'text-bg-danger',
        default => 'text-bg-secondary',
    };
}

/** Nama warna singkat untuk `text-bg-<?= $x ?>`. */
function penilaian_kehadiran_badge_tone(string $predikat): string
{
    return match (trim($predikat)) {
        'Baik', 'Bagus' => 'success',
        'Cukup' => 'info',
        'Sedang' => 'warning',
        'Kurang' => 'kurang',
        'Buruk' => 'danger',
        default => 'secondary',
    };
}

function penilaian_kehadiran_batas_telat(PDO $pdo): int
{
    return max(0, (int) app_setting($pdo, 'batas_telat_menit', '15'));
}

/** Saklar penilaian: HADIR lewat batas dihitung Hadir (bukan Telat berpenalti). Default OFF. */
function penilaian_kehadiran_telat_dihitung_hadir(?PDO $pdo = null): bool
{
    if (!($pdo instanceof PDO)) {
        $pdo = $GLOBALS['pdo'] ?? null;
    }
    if (!($pdo instanceof PDO)) {
        return false;
    }

    return trim((string) app_setting($pdo, 'keaktifan_telat_dihitung_hadir', '0')) === '1';
}

/** HADIR lewat batas telat (catatan atau jam vs jadwal). Tidak dipengaruhi saklar penilaian. */
function penilaian_kehadiran_row_is_telat(array $row, int $lateTolerance): bool
{
    $status = strtoupper(trim((string) ($row['status_presensi'] ?? '')));
    if ($status !== 'HADIR') {
        return false;
    }

    return rekap_telat_kegiatan_hitung_menit($row, $lateTolerance) > 0;
}

/**
 * Bucket hitungan: hadir|telat|izin|sakit|alpa|''.
 */
function penilaian_kehadiran_status_bucket(array $row, int $lateTolerance): string
{
    $status = strtoupper(trim((string) ($row['status_presensi'] ?? '')));
    if ($status === 'HADIR') {
        if (penilaian_kehadiran_telat_dihitung_hadir()) {
            return 'hadir';
        }

        return penilaian_kehadiran_row_is_telat($row, $lateTolerance) ? 'telat' : 'hadir';
    }
    if ($status === 'IZIN') {
        return 'izin';
    }
    if ($status === 'SAKIT') {
        return 'sakit';
    }
    if ($status === 'ALPA') {
        return 'alpa';
    }

    return '';
}

function penilaian_kehadiran_row_bucket(array $row, int $lateTolerance = 15): string
{
    $cached = $row['_bucket'] ?? null;
    if (is_string($cached) && $cached !== '') {
        return $cached;
    }

    return penilaian_kehadiran_status_bucket($row, $lateTolerance);
}

/** @return array{hadir:int,izin:int,sakit:int,alpa:int,telat:int,total:int} */
function penilaian_kehadiran_counts_empty(): array
{
    return ['hadir' => 0, 'izin' => 0, 'sakit' => 0, 'alpa' => 0, 'telat' => 0, 'total' => 0];
}

/**
 * @param array<string, mixed> $counts
 * @return array<string, mixed>
 */
function penilaian_kehadiran_apply_to_stats(array $stats): array
{
    $hit = penilaian_kehadiran_hitung(
        (int) ($stats['alpa'] ?? 0),
        (int) ($stats['izin'] ?? 0),
        (int) ($stats['telat'] ?? 0),
        (int) ($stats['sakit'] ?? 0),
        (int) ($stats['total'] ?? 0),
        (int) ($stats['hadir'] ?? 0)
    );
    $stats['akumulasi'] = $hit['akumulasi'];
    $stats['penalti'] = $hit['penalti'];
    $stats['n_hari'] = $hit['n_hari'];
    $stats['nilai'] = $hit['nilai'];
    $stats['persen_hadir'] = $hit['persen'];
    $stats['kategori'] = $hit['predikat'];
    $stats['skor'] = $hit['nilai'];

    return $stats;
}

/**
 * @param array<string, int> $counts
 */
function penilaian_kehadiran_keterangan(array $counts, float $persen, int $nilai, string $satuan = 'jadwal terhitung'): string
{
    $nHari = (int) ($counts['n_hari'] ?? $counts['total'] ?? 0);

    return sprintf(
        'Kehadiran %s%% (ABSENSI %d / N.HARI %d) · Hadir %d · Telat %d · Izin %d · Sakit %d · ALPA %d (dari %d %s)',
        number_format($persen, 1, ',', '.'),
        $nilai,
        $nHari,
        (int) ($counts['hadir'] ?? 0),
        (int) ($counts['telat'] ?? 0),
        (int) ($counts['izin'] ?? 0),
        (int) ($counts['sakit'] ?? 0),
        (int) ($counts['alpa'] ?? 0),
        (int) ($counts['total'] ?? 0),
        $satuan
    );
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function penilaian_kehadiran_annotate_rows(array $rows, int $lateTolerance): array
{
    foreach ($rows as &$row) {
        $row['_bucket'] = penilaian_kehadiran_status_bucket($row, $lateTolerance);
    }
    unset($row);

    return $rows;
}
