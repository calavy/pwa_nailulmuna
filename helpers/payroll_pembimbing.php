<?php

declare(strict_types=1);

/**
 * Modul payroll pembimbing.
 *
 * Master tarif global per kriteria beban kerja (Berat/Sedang/Ringan/Khusus)
 * disimpan di tabel `tarif_payroll_pembimbing`. Per pembimbing punya
 * `gaji_pokok` (tunjangan tetap bulanan) dan `tarif_kriteria` (pilih salah
 * satu dari 4 kriteria).
 *
 * Formula akhir bulanan:
 *   total_gaji = gaji_pokok + (total_jam_kerja * tarif_per_jam[kriteria])
 *
 * total_jam_kerja dihitung di rekap/pembimbing.php dari presensi_pembimbing
 * di-join ke jadwal_kegiatan (fallback 1 jam per scan jika tidak ada jadwal).
 */

const PAYROLL_PEMBIMBING_KRITERIA = ['BERAT', 'SEDANG', 'RINGAN', 'KHUSUS'];
const PAYROLL_PEMBIMBING_DEFAULT_KRITERIA = 'RINGAN';

/** @return array<string,string> kriteria => label tampilan */
function payroll_pembimbing_kriteria_labels(): array
{
    return [
        'BERAT' => 'Berat',
        'SEDANG' => 'Sedang',
        'RINGAN' => 'Ringan',
        'KHUSUS' => 'Khusus/Lainnya',
    ];
}

/** Pastikan tabel/kolom payroll ada. Idempotent + once-per-session guard. */
function payroll_pembimbing_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    if (isset($_SESSION['payroll_pembimbing_v1'])) {
        $done = true;
        return;
    }
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tarif_payroll_pembimbing (
                kriteria ENUM('BERAT','SEDANG','RINGAN','KHUSUS') NOT NULL PRIMARY KEY,
                nominal_per_jam DECIMAL(12,2) NOT NULL DEFAULT 0,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $pdo->exec("
            INSERT IGNORE INTO tarif_payroll_pembimbing (kriteria, nominal_per_jam) VALUES
                ('BERAT', 50000),
                ('SEDANG', 35000),
                ('RINGAN', 25000),
                ('KHUSUS', 40000)
        ");

        if (function_exists('table_exists') && table_exists($pdo, 'pembimbing')) {
            if (function_exists('column_exists')) {
                if (!column_exists($pdo, 'pembimbing', 'gaji_pokok')) {
                    $pdo->exec("ALTER TABLE pembimbing ADD COLUMN gaji_pokok DECIMAL(12,2) NOT NULL DEFAULT 0");
                }
                if (!column_exists($pdo, 'pembimbing', 'tarif_kriteria')) {
                    $pdo->exec("ALTER TABLE pembimbing ADD COLUMN tarif_kriteria ENUM('BERAT','SEDANG','RINGAN','KHUSUS') NOT NULL DEFAULT 'RINGAN'");
                }
            } else {
                @$pdo->exec("ALTER TABLE pembimbing ADD COLUMN gaji_pokok DECIMAL(12,2) NOT NULL DEFAULT 0");
                @$pdo->exec("ALTER TABLE pembimbing ADD COLUMN tarif_kriteria ENUM('BERAT','SEDANG','RINGAN','KHUSUS') NOT NULL DEFAULT 'RINGAN'");
            }
        }
        $_SESSION['payroll_pembimbing_v1'] = 1;
    } catch (Throwable $e) {
        // Jangan fatal — schema akan dicoba lagi navigasi berikutnya.
    }
    $done = true;
}

/**
 * Ambil tarif per jam untuk setiap kriteria. Mengembalikan map lengkap dengan
 * fallback 0 jika baris seed belum ada (defensif).
 *
 * @return array<string,float>
 */
function payroll_pembimbing_tarif_map(PDO $pdo): array
{
    $map = array_fill_keys(PAYROLL_PEMBIMBING_KRITERIA, 0.0);
    try {
        $rows = $pdo->query('SELECT kriteria, nominal_per_jam FROM tarif_payroll_pembimbing')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $k = strtoupper((string) ($r['kriteria'] ?? ''));
            if (isset($map[$k])) {
                $map[$k] = (float) ($r['nominal_per_jam'] ?? 0);
            }
        }
    } catch (Throwable $e) {
        // tabel belum siap — kembalikan map default 0
    }

    return $map;
}

/**
 * Hitung komponen gaji bulanan satu pembimbing.
 *
 * @param array<string,float> $tarifMap output payroll_pembimbing_tarif_map()
 * @return array{
 *     gaji_pokok: float,
 *     tarif_per_jam: float,
 *     total_jam: float,
 *     gaji_per_jam: float,
 *     total_gaji: float,
 *     kriteria: string,
 *     kriteria_label: string
 * }
 */
function payroll_pembimbing_compute(float $totalJam, float $gajiPokok, string $kriteria, array $tarifMap): array
{
    $kriteria = strtoupper(trim($kriteria));
    if (!in_array($kriteria, PAYROLL_PEMBIMBING_KRITERIA, true)) {
        $kriteria = PAYROLL_PEMBIMBING_DEFAULT_KRITERIA;
    }
    $tarifPerJam = (float) ($tarifMap[$kriteria] ?? 0);
    $gajiPokok = max(0.0, $gajiPokok);
    $totalJam = max(0.0, $totalJam);
    $gajiPerJam = $totalJam * $tarifPerJam;
    $labels = payroll_pembimbing_kriteria_labels();

    return [
        'gaji_pokok' => $gajiPokok,
        'tarif_per_jam' => $tarifPerJam,
        'total_jam' => $totalJam,
        'gaji_per_jam' => $gajiPerJam,
        'total_gaji' => $gajiPokok + $gajiPerJam,
        'kriteria' => $kriteria,
        'kriteria_label' => $labels[$kriteria] ?? $kriteria,
    ];
}

/** Validasi kriteria — kembalikan kriteria valid atau default. */
function payroll_pembimbing_normalize_kriteria(?string $kriteria): string
{
    $k = strtoupper(trim((string) $kriteria));
    return in_array($k, PAYROLL_PEMBIMBING_KRITERIA, true) ? $k : PAYROLL_PEMBIMBING_DEFAULT_KRITERIA;
}
