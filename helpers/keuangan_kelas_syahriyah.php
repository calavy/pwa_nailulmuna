<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

function ensure_kelas_syahriyah_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    ensure_kelas_keuangan_table($pdo);

    $pdo->exec('
        CREATE TABLE IF NOT EXISTS kelas_syahriyah (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            kode VARCHAR(40) NOT NULL,
            nama_tampilan VARCHAR(120) NOT NULL,
            kelas_keuangan_kode VARCHAR(40) NOT NULL,
            urutan INT NOT NULL DEFAULT 0,
            is_aktif TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_kelas_syahriyah_kode (kode),
            UNIQUE KEY uk_kelas_syahriyah_keuangan (kelas_keuangan_kode)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');

    $cnt = (int) $pdo->query('SELECT COUNT(*) FROM kelas_syahriyah')->fetchColumn();
    if ($cnt === 0) {
        $kkRows = kelas_keuangan_list_active($pdo);
        if ($kkRows !== []) {
            $ins = $pdo->prepare('
                INSERT INTO kelas_syahriyah (kode, nama_tampilan, kelas_keuangan_kode, urutan, is_aktif)
                VALUES (:k, :n, :kk, :u, 1)
            ');
            foreach ($kkRows as $i => $row) {
                $kodeKk = strtoupper(trim((string) ($row['kode'] ?? '')));
                if ($kodeKk === '') {
                    continue;
                }
                $ins->execute([
                    'k' => 'SY-' . $kodeKk,
                    'n' => 'Syahriyah ' . trim((string) ($row['nama_tampilan'] ?? $kodeKk)),
                    'kk' => $kodeKk,
                    'u' => $i + 1,
                ]);
            }
        }
    }

    $done = true;
}

/** @return list<array<string, mixed>> */
function kelas_syahriyah_all_rows(PDO $pdo): array
{
    ensure_kelas_syahriyah_table($pdo);

    return $pdo->query('
        SELECT id, kode, nama_tampilan, kelas_keuangan_kode, urutan, is_aktif
        FROM kelas_syahriyah
        ORDER BY urutan ASC, nama_tampilan ASC
    ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function keuangan_kelas_syahriyah_tambahan_setting_key(string $kelasSyahriyahKode, int $bulanTagihan = 0): string
{
    $k = strtoupper(preg_replace('/[^A-Z0-9_-]/', '', $kelasSyahriyahKode) ?? '');
    if ($bulanTagihan >= 1 && $bulanTagihan <= 12) {
        return 'keuangan_ks_tambahan_' . $k . '_b' . $bulanTagihan;
    }

    return 'keuangan_ks_tambahan_' . $k . '_default';
}

/** Tambahan syahriyah per kelas (default bulan). */
function keuangan_kelas_syahriyah_tambahan_nominal_by_kode(
    PDO $pdo,
    string $kelasSyahriyahKode,
    int $bulanTagihan = 0
): int {
    $kode = strtoupper(trim($kelasSyahriyahKode));
    if ($kode === '') {
        return 0;
    }
    $key = keuangan_kelas_syahriyah_tambahan_setting_key($kode, $bulanTagihan);

    return max(0, (int) app_setting($pdo, $key, (string) app_setting(
        $pdo,
        keuangan_kelas_syahriyah_tambahan_setting_key($kode, 0),
        '0'
    )));
}

/** Resolve kode kelas syahriyah dari kategori/kelas keuangan santri. */
function kelas_syahriyah_kode_for_kelas_keuangan(PDO $pdo, string $kelasKeuanganKode): string
{
    ensure_kelas_syahriyah_table($pdo);
    $kk = strtoupper(trim($kelasKeuanganKode));
    if ($kk === '') {
        return '';
    }
    $st = $pdo->prepare('
        SELECT kode FROM kelas_syahriyah
        WHERE UPPER(TRIM(kelas_keuangan_kode)) = :kk AND is_aktif = 1
        ORDER BY urutan ASC
        LIMIT 1
    ');
    $st->execute(['kk' => $kk]);

    return strtoupper(trim((string) ($st->fetchColumn() ?: '')));
}

/** Tambahan syahriyah menurut kelas keuangan santri (kategori_kelas). */
function keuangan_kelas_syahriyah_tambahan_for_kelas_keuangan(
    PDO $pdo,
    string $kelasKeuanganKode,
    int $bulanTagihan = 0
): int {
    $ksKode = kelas_syahriyah_kode_for_kelas_keuangan($pdo, $kelasKeuanganKode);
    if ($ksKode === '') {
        return 0;
    }

    return keuangan_kelas_syahriyah_tambahan_nominal_by_kode($pdo, $ksKode, $bulanTagihan);
}

/**
 * @param array<string, mixed> $sim
 * @return array<string, mixed>
 */
function keuangan_kelas_syahriyah_apply_to_simulasi(
    PDO $pdo,
    array $sim,
    string $kelasKategori,
    int $bulanTagihan = 0
): array {
    $sim['kelas_syahriyah_tambahan'] = 0;
    $tambahan = keuangan_kelas_syahriyah_tambahan_for_kelas_keuangan($pdo, $kelasKategori, $bulanTagihan);
    $sim['kelas_syahriyah_tambahan'] = $tambahan;
    if ($tambahan > 0) {
        $sim['expected'] = max(0, (int) ($sim['expected'] ?? 0)) + $tambahan;
    }

    return $sim;
}

function keuangan_kelas_syahriyah_save_tambahan_settings(PDO $pdo, array $post): array
{
    ensure_kelas_syahriyah_table($pdo);
    foreach (kelas_syahriyah_all_rows($pdo) as $row) {
        $kode = strtoupper(trim((string) ($row['kode'] ?? '')));
        if ($kode === '') {
            continue;
        }
        $default = max(0, (int) ($post['ks_tambahan'][$kode]['default'] ?? $post['ks_tambahan_default'][$kode] ?? 0));
        save_setting($pdo, keuangan_kelas_syahriyah_tambahan_setting_key($kode, 0), (string) $default);
        for ($b = 1; $b <= 12; $b++) {
            $val = max(0, (int) ($post['ks_tambahan'][$kode]['bulan'][$b] ?? $default));
            save_setting($pdo, keuangan_kelas_syahriyah_tambahan_setting_key($kode, $b), (string) $val);
        }
    }

    return ['ok' => true, 'message' => 'Nominal tambahan syahriyah per kelas disimpan.'];
}

/** Label alokasi untuk tambahan kelas syahriyah. */
function keuangan_kelas_syahriyah_alokasi_umum_label(): string
{
    return 'Dana Umum (Tambahan Syahriyah)';
}
