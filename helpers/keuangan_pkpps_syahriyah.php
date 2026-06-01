<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/pkpps.php';

/** Nama komponen alokasi untuk bagian tambahan PKPPS (kategori Umum). */
function keuangan_pkpps_alokasi_umum_label(): string
{
    return 'Dana Umum (PKPPS)';
}

function keuangan_pkpps_syahriyah_normalize_kode(string $raw): string
{
    $k = strtoupper(trim($raw));

    return preg_replace('/[^A-Z0-9_]/', '', $k) ?? '';
}

function keuangan_pkpps_syahriyah_setting_key(int $bulanTagihan = 0, string $kelasKeuanganKode = ''): string
{
    $kk = keuangan_pkpps_syahriyah_normalize_kode($kelasKeuanganKode);
    if ($kk !== '') {
        if ($bulanTagihan >= 1 && $bulanTagihan <= 12) {
            return 'keuangan_pkpps_syahriyah_k_' . $kk . '_b_' . $bulanTagihan;
        }

        return 'keuangan_pkpps_syahriyah_k_' . $kk;
    }
    if ($bulanTagihan >= 1 && $bulanTagihan <= 12) {
        return 'keuangan_pkpps_syahriyah_bulan_' . $bulanTagihan;
    }

    return 'keuangan_pkpps_syahriyah_default';
}

/** Key lama per id tingkatan PKPPS (Wustho 1, 2, 3 terpisah). */
function keuangan_pkpps_syahriyah_legacy_tingkatan_key(int $bulanTagihan, int $pkppsTingkatanId): string
{
    if ($pkppsTingkatanId <= 0) {
        return keuangan_pkpps_syahriyah_setting_key($bulanTagihan);
    }
    if ($bulanTagihan >= 1 && $bulanTagihan <= 12) {
        return 'keuangan_pkpps_syahriyah_t_' . $pkppsTingkatanId . '_b_' . $bulanTagihan;
    }

    return 'keuangan_pkpps_syahriyah_t_' . $pkppsTingkatanId;
}

/** Kode kelas keuangan induk dari satu baris tingkatan PKPPS. */
function pkpps_tingkatan_kelas_keuangan_kode(PDO $pdo, int $pkppsTingkatanId): string
{
    if ($pkppsTingkatanId <= 0) {
        return '';
    }
    pkpps_ensure_schema($pdo);
    ensure_kelas_keuangan_table($pdo);
    $st = $pdo->prepare('
        SELECT kk.kode
        FROM pkpps_tingkatan t
        LEFT JOIN kelas_keuangan kk ON kk.id = t.kelas_keuangan_id
        WHERE t.id = :id
        LIMIT 1
    ');
    $st->execute(['id' => $pkppsTingkatanId]);
    $kode = keuangan_pkpps_syahriyah_normalize_kode((string) ($st->fetchColumn() ?: ''));
    if ($kode !== '') {
        return $kode;
    }
    $row = pkpps_tingkatan_by_id($pdo, $pkppsTingkatanId);
    if ($row === null) {
        return '';
    }
    $tier = keuangan_tier_key_from_kelas((string) ($row['nama_tingkatan'] ?? ''), $pdo);
    $st2 = $pdo->prepare('
        SELECT kode FROM kelas_keuangan
        WHERE LOWER(TRIM(tarif_keuangan_tier)) = :t AND is_aktif = 1
        ORDER BY urutan ASC, id ASC
        LIMIT 1
    ');
    $st2->execute(['t' => $tier]);
    $kode2 = keuangan_pkpps_syahriyah_normalize_kode((string) ($st2->fetchColumn() ?: ''));

    return $kode2;
}

/**
 * Kode kelas keuangan untuk nominal tambahan PKPPS (Wustho 1/2/3 → Wustho).
 */
function pkpps_kelas_keuangan_kode_for_santri(PDO $pdo, int $santriId): string
{
    if ($santriId <= 0) {
        return '';
    }
    $st = $pdo->prepare('SELECT kategori_kelas FROM santri WHERE id = :id LIMIT 1');
    $st->execute(['id' => $santriId]);
    $kat = santri_normalize_kategori_kelas($pdo, (string) ($st->fetchColumn() ?: ''));
    if ($kat !== '') {
        return $kat;
    }
    if (!table_exists($pdo, 'pkpps_santri')) {
        return '';
    }
    pkpps_ensure_schema($pdo);
    ensure_kelas_keuangan_table($pdo);
    $st2 = $pdo->prepare('
        SELECT kk.kode
        FROM pkpps_santri ps
        INNER JOIN pkpps_tingkatan t ON t.id = ps.pkpps_tingkatan_id
        LEFT JOIN kelas_keuangan kk ON kk.id = t.kelas_keuangan_id
        WHERE ps.santri_id = :sid AND ps.is_aktif = 1
        LIMIT 1
    ');
    $st2->execute(['sid' => $santriId]);
    $kode = keuangan_pkpps_syahriyah_normalize_kode((string) ($st2->fetchColumn() ?: ''));
    if ($kode !== '') {
        return $kode;
    }
    $tid = pkpps_tingkatan_id_for_santri($pdo, $santriId);

    return pkpps_tingkatan_kelas_keuangan_kode($pdo, $tid);
}

/** Kelas keuangan yang punya program PKPPS (Wustho/Ulya; Muadalah bukan PKPPS). */
function kelas_keuangan_list_for_pkpps_syahriyah(PDO $pdo): array
{
    ensure_kelas_keuangan_table($pdo);
    $out = [];
    foreach (kelas_keuangan_list_active($pdo) as $row) {
        $tier = strtolower(trim((string) ($row['tarif_keuangan_tier'] ?? '')));
        if ($tier === 'wustho' || $tier === 'ulya') {
            $out[] = $row;
        }
    }

    return $out;
}

/** Salin pengaturan lama per tingkatan PKPPS ke kode kelas keuangan induk. */
function keuangan_pkpps_syahriyah_migrate_tingkatan_to_kelas(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    pkpps_ensure_schema($pdo);
    $cache = app_settings_cache($pdo);
    foreach (pkpps_tingkatan_list($pdo, false) as $t) {
        $tid = (int) ($t['id'] ?? 0);
        if ($tid <= 0) {
            continue;
        }
        $kk = pkpps_tingkatan_kelas_keuangan_kode($pdo, $tid);
        if ($kk === '') {
            continue;
        }
        foreach ([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12] as $bulan) {
            $legacyKey = keuangan_pkpps_syahriyah_legacy_tingkatan_key($bulan, $tid);
            if (!array_key_exists($legacyKey, $cache)) {
                continue;
            }
            $newKey = keuangan_pkpps_syahriyah_setting_key($bulan, $kk);
            if (!array_key_exists($newKey, $cache)) {
                save_setting($pdo, $newKey, (string) $cache[$legacyKey]);
            }
        }
    }
    if (function_exists('app_settings_cache_reset')) {
        app_settings_cache_reset($pdo);
    }
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
        keuangan_pkpps_syahriyah_migrate_tingkatan_to_kelas($pdo);

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
        keuangan_pkpps_syahriyah_migrate_tingkatan_to_kelas($pdo);

        return;
    }

    for ($b = 1; $b <= 12; $b++) {
        $k = keuangan_pkpps_syahriyah_setting_key($b);
        if (array_key_exists($k, $cache) && (int) $cache[$k] === 0) {
            keuangan_pkpps_syahriyah_delete_setting($pdo, $k);
        }
    }
    keuangan_pkpps_syahriyah_migrate_tingkatan_to_kelas($pdo);
}

/**
 * Nominal tambahan PKPPS per kelas keuangan (bukan per Wustho 1 / 2 / 3).
 */
function keuangan_pkpps_syahriyah_nominal(
    PDO $pdo,
    int $bulanTagihan = 0,
    int $tahunAjaranMulai = 0,
    int $tahunAjaranSelesai = 0,
    string $kelasKeuanganKode = ''
): int {
    keuangan_pkpps_syahriyah_cleanup_legacy_global_bulan($pdo);
    $cache = app_settings_cache($pdo);
    $globalDefault = max(0, (int) ($cache['keuangan_pkpps_syahriyah_default'] ?? '0'));
    $kk = keuangan_pkpps_syahriyah_normalize_kode($kelasKeuanganKode);

    if ($kk !== '') {
        if ($bulanTagihan >= 1 && $bulanTagihan <= 12) {
            $monthKey = keuangan_pkpps_syahriyah_setting_key($bulanTagihan, $kk);
            if (array_key_exists($monthKey, $cache)) {
                return max(0, (int) $cache[$monthKey]);
            }
        }
        $defaultKey = keuangan_pkpps_syahriyah_setting_key(0, $kk);
        if (array_key_exists($defaultKey, $cache)) {
            return max(0, (int) $cache[$defaultKey]);
        }

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
    $kk = pkpps_kelas_keuangan_kode_for_santri($pdo, $santriId);
    $tambahan = keuangan_pkpps_syahriyah_nominal($pdo, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai, $kk);
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

    for ($b = 1; $b <= 12; $b++) {
        keuangan_pkpps_syahriyah_delete_setting($pdo, keuangan_pkpps_syahriyah_setting_key($b));
    }

    $kelasNominals = $post['pkpps_syahriyah_kelas'] ?? [];
    if (is_array($kelasNominals)) {
        foreach ($kelasNominals as $kodeRaw => $kelasPost) {
            $kk = keuangan_pkpps_syahriyah_normalize_kode((string) $kodeRaw);
            if ($kk === '' || !is_array($kelasPost)) {
                continue;
            }
            $kelasDefault = max(0, (int) ($kelasPost['default'] ?? $default));
            if ($kelasDefault === $default) {
                keuangan_pkpps_syahriyah_delete_setting($pdo, keuangan_pkpps_syahriyah_setting_key(0, $kk));
            } else {
                save_setting($pdo, keuangan_pkpps_syahriyah_setting_key(0, $kk), (string) $kelasDefault);
            }
            for ($b = 1; $b <= 12; $b++) {
                $val = max(0, (int) ($kelasPost['bulan'][$b] ?? $kelasDefault));
                $monthKey = keuangan_pkpps_syahriyah_setting_key($b, $kk);
                if ($val === $kelasDefault) {
                    keuangan_pkpps_syahriyah_delete_setting($pdo, $monthKey);
                } else {
                    save_setting($pdo, $monthKey, (string) $val);
                }
            }
        }
    }

    // Terima form lama per tingkatan → simpan ke kelas keuangan induk
    $tierNominals = $post['pkpps_syahriyah_tingkatan'] ?? [];
    if (is_array($tierNominals)) {
        foreach ($tierNominals as $tidRaw => $tierPost) {
            $tid = (int) $tidRaw;
            if ($tid <= 0 || !is_array($tierPost)) {
                continue;
            }
            $kk = pkpps_tingkatan_kelas_keuangan_kode($pdo, $tid);
            if ($kk === '') {
                continue;
            }
            $tierDefault = max(0, (int) ($tierPost['default'] ?? $default));
            if ($tierDefault === $default) {
                keuangan_pkpps_syahriyah_delete_setting($pdo, keuangan_pkpps_syahriyah_setting_key(0, $kk));
            } else {
                save_setting($pdo, keuangan_pkpps_syahriyah_setting_key(0, $kk), (string) $tierDefault);
            }
            for ($b = 1; $b <= 12; $b++) {
                $val = max(0, (int) ($tierPost['bulan'][$b] ?? $tierDefault));
                $monthKey = keuangan_pkpps_syahriyah_setting_key($b, $kk);
                if ($val === $tierDefault) {
                    keuangan_pkpps_syahriyah_delete_setting($pdo, $monthKey);
                } else {
                    save_setting($pdo, $monthKey, (string) $val);
                }
            }
        }
    }

    return ['ok' => true, 'message' => 'Pengaturan tambahan syahriyah PKPPS (per kelas keuangan) disimpan.'];
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
    $kk = pkpps_kelas_keuangan_kode_for_santri($pdo, $santriId);
    $pkppsNom = keuangan_pkpps_syahriyah_nominal($pdo, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai, $kk);
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
