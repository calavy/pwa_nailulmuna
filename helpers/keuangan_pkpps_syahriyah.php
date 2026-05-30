<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/pkpps.php';

/** Nama komponen alokasi untuk bagian tambahan PKPPS (kategori Umum). */
function keuangan_pkpps_alokasi_umum_label(): string
{
    return 'Dana Umum (PKPPS)';
}

function keuangan_pkpps_syahriyah_setting_key(int $bulanTagihan = 0, int $pkppsTingkatanId = 0): string
{
    if ($pkppsTingkatanId > 0) {
        if ($bulanTagihan >= 1 && $bulanTagihan <= 12) {
            return 'keuangan_pkpps_syahriyah_t_' . $pkppsTingkatanId . '_b_' . $bulanTagihan;
        }

        return 'keuangan_pkpps_syahriyah_t_' . $pkppsTingkatanId;
    }
    if ($bulanTagihan >= 1 && $bulanTagihan <= 12) {
        return 'keuangan_pkpps_syahriyah_bulan_' . $bulanTagihan;
    }

    return 'keuangan_pkpps_syahriyah_default';
}

/** Hapus key bulan global legacy (semua 0) yang menimpa default global. */
function keuangan_pkpps_syahriyah_cleanup_legacy_global_bulan(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $cache = app_settings_cache($pdo);
    $globalDefault = max(0, (int) ($cache['keuangan_pkpps_syahriyah_default'] ?? '0'));
    if ($globalDefault <= 0) {
        return;
    }

    $hasLegacyZeroMonths = false;
    for ($b = 1; $b <= 12; $b++) {
        $k = keuangan_pkpps_syahriyah_setting_key($b);
        if (array_key_exists($k, $cache) && (int) $cache[$k] === 0) {
            $hasLegacyZeroMonths = true;
            break;
        }
    }
    if (!$hasLegacyZeroMonths) {
        return;
    }

    for ($b = 1; $b <= 12; $b++) {
        $k = keuangan_pkpps_syahriyah_setting_key($b);
        if (array_key_exists($k, $cache) && (int) $cache[$k] === 0) {
            keuangan_pkpps_syahriyah_delete_setting($pdo, $k);
        }
    }
}

function keuangan_pkpps_syahriyah_nominal(
    PDO $pdo,
    int $bulanTagihan = 0,
    int $tahunAjaranMulai = 0,
    int $tahunAjaranSelesai = 0,
    int $pkppsTingkatanId = 0
): int {
    keuangan_pkpps_syahriyah_cleanup_legacy_global_bulan($pdo);
    $cache = app_settings_cache($pdo);
    $globalDefault = max(0, (int) ($cache['keuangan_pkpps_syahriyah_default'] ?? '0'));

    if ($pkppsTingkatanId > 0) {
        if ($bulanTagihan >= 1 && $bulanTagihan <= 12) {
            $tierMonthKey = keuangan_pkpps_syahriyah_setting_key($bulanTagihan, $pkppsTingkatanId);
            if (array_key_exists($tierMonthKey, $cache)) {
                return max(0, (int) $cache[$tierMonthKey]);
            }
        }
        $tierDefaultKey = keuangan_pkpps_syahriyah_setting_key(0, $pkppsTingkatanId);
        if (array_key_exists($tierDefaultKey, $cache)) {
            return max(0, (int) $cache[$tierDefaultKey]);
        }

        // Tingkatan PKPPS tanpa override: pakai default global (bukan key bulan global legacy).
        return $globalDefault;
    }

    if ($bulanTagihan >= 1 && $bulanTagihan <= 12) {
        $monthKey = keuangan_pkpps_syahriyah_setting_key($bulanTagihan);
        if (array_key_exists($monthKey, $cache)) {
            return max(0, (int) $cache[$monthKey]);
        }
    }

    return $globalDefault;
}

/** Apakah santri terdaftar PKPPS aktif. */
function keuangan_pkpps_syahriyah_berlaku_untuk_santri(PDO $pdo, int $santriId): bool
{
    if ($santriId <= 0 || !table_exists($pdo, 'pkpps_santri')) {
        return false;
    }
    pkpps_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT 1 FROM pkpps_santri WHERE santri_id = :sid AND is_aktif = 1 LIMIT 1');
    $st->execute(['sid' => $santriId]);

    return (bool) $st->fetchColumn();
}

/**
 * Tambahkan nominal PKPPS ke simulasi tagihan syahriyah (gabung ke total syahriyah).
 *
 * @param array<string, mixed> $sim
 * @return array<string, mixed>
 */
function keuangan_pkpps_syahriyah_apply_to_simulasi(
    PDO $pdo,
    array $sim,
    int $santriId,
    int $bulanTagihan = 0,
    int $tahunAjaranMulai = 0,
    int $tahunAjaranSelesai = 0
): array {
    $sim['pkpps_tambahan'] = 0;
    if (!keuangan_pkpps_syahriyah_berlaku_untuk_santri($pdo, $santriId)) {
        return $sim;
    }
    $pkppsTingkatanId = pkpps_tingkatan_id_for_santri($pdo, $santriId);
    $tambahan = keuangan_pkpps_syahriyah_nominal($pdo, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai, $pkppsTingkatanId);
    $sim['pkpps_tambahan'] = $tambahan;
    if ($tambahan > 0) {
        $sim['expected'] = max(0, (int) ($sim['expected'] ?? 0)) + $tambahan;
    }

    return $sim;
}

function keuangan_pkpps_syahriyah_delete_setting(PDO $pdo, string $key): void
{
    if ($key === '') {
        return;
    }
    $st = $pdo->prepare('DELETE FROM app_settings WHERE setting_key = :k LIMIT 1');
    $st->execute(['k' => $key]);
    if (function_exists('app_settings_cache_reset')) {
        app_settings_cache_reset($pdo);
    }
}

function keuangan_pkpps_syahriyah_save_settings(PDO $pdo, array $post): array
{
    $default = max(0, (int) ($post['pkpps_syahriyah_default'] ?? 0));
    save_setting($pdo, 'keuangan_pkpps_syahriyah_default', (string) $default);

    // Form tidak punya input global per-bulan; hapus key legacy agar tidak menimpa default.
    for ($b = 1; $b <= 12; $b++) {
        keuangan_pkpps_syahriyah_delete_setting($pdo, keuangan_pkpps_syahriyah_setting_key($b));
    }

    $tierNominals = $post['pkpps_syahriyah_tingkatan'] ?? [];
    if (is_array($tierNominals)) {
        foreach ($tierNominals as $tidRaw => $tierPost) {
            $tid = (int) $tidRaw;
            if ($tid <= 0 || !is_array($tierPost)) {
                continue;
            }
            $tierDefault = max(0, (int) ($tierPost['default'] ?? $default));
            if ($tierDefault === $default) {
                keuangan_pkpps_syahriyah_delete_setting($pdo, keuangan_pkpps_syahriyah_setting_key(0, $tid));
            } else {
                save_setting($pdo, keuangan_pkpps_syahriyah_setting_key(0, $tid), (string) $tierDefault);
            }
            for ($b = 1; $b <= 12; $b++) {
                $val = max(0, (int) ($tierPost['bulan'][$b] ?? $tierDefault));
                $monthKey = keuangan_pkpps_syahriyah_setting_key($b, $tid);
                if ($val === $tierDefault) {
                    keuangan_pkpps_syahriyah_delete_setting($pdo, $monthKey);
                } else {
                    save_setting($pdo, $monthKey, (string) $val);
                }
            }
        }
    }

    return ['ok' => true, 'message' => 'Pengaturan tambahan syahriyah PKPPS disimpan.'];
}

/**
 * Bagi pembayaran syahriyah: bagian PKPPS (alokasi Umum) vs dasar (alokasi persen biasa).
 *
 * @return array{pkpps:int, dasar:int}
 */
function keuangan_pkpps_syahriyah_split_pembayaran(
    PDO $pdo,
    int $santriId,
    int $bayarSyahriyah,
    int $bulanTagihan,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai
): array {
    if ($bayarSyahriyah <= 0 || !keuangan_pkpps_syahriyah_berlaku_untuk_santri($pdo, $santriId)) {
        return ['pkpps' => 0, 'dasar' => max(0, $bayarSyahriyah)];
    }
    $pkppsTingkatanId = pkpps_tingkatan_id_for_santri($pdo, $santriId);
    $pkppsNom = keuangan_pkpps_syahriyah_nominal($pdo, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai, $pkppsTingkatanId);
    if ($pkppsNom <= 0) {
        return ['pkpps' => 0, 'dasar' => $bayarSyahriyah];
    }
    $pkppsBagian = min($bayarSyahriyah, $pkppsNom);

    return [
        'pkpps' => $pkppsBagian,
        'dasar' => max(0, $bayarSyahriyah - $pkppsBagian),
    ];
}

/**
 * Bagi pembayaran syahriyah: PKPPS + tambahan kelas syahriyah (umum) vs dasar (alokasi %).
 *
 * @return array{pkpps:int, kelas_syahriyah:int, umum:int, dasar:int}
 */
function keuangan_syahriyah_split_pembayaran_tambahan(
    PDO $pdo,
    int $santriId,
    string $kelasKategori,
    int $bayarSyahriyah,
    int $bulanTagihan,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai
): array {
    if ($bayarSyahriyah <= 0) {
        return ['pkpps' => 0, 'kelas_syahriyah' => 0, 'umum' => 0, 'dasar' => 0];
    }

    if (!function_exists('keuangan_kelas_syahriyah_tambahan_for_kelas_keuangan')) {
        require_once __DIR__ . '/keuangan_kelas_syahriyah.php';
    }

    $pkppsSplit = keuangan_pkpps_syahriyah_split_pembayaran(
        $pdo,
        $santriId,
        $bayarSyahriyah,
        $bulanTagihan,
        $tahunAjaranMulai,
        $tahunAjaranSelesai
    );
    $pkpps = (int) ($pkppsSplit['pkpps'] ?? 0);
    $sisa = (int) ($pkppsSplit['dasar'] ?? $bayarSyahriyah);

    $kelasSyNom = keuangan_kelas_syahriyah_tambahan_for_kelas_keuangan($pdo, $kelasKategori, $bulanTagihan);
    $kelasSy = $kelasSyNom > 0 ? min($sisa, $kelasSyNom) : 0;
    $dasar = max(0, $sisa - $kelasSy);

    return [
        'pkpps' => $pkpps,
        'kelas_syahriyah' => $kelasSy,
        'umum' => $pkpps + $kelasSy,
        'dasar' => $dasar,
    ];
}
