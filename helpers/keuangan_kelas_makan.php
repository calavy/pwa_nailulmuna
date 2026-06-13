<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_defs.php';
require_once __DIR__ . '/keuangan_tarif_bulanan.php';

function keuangan_makan_pos_nama(PDO $pdo): string
{
    $nama = trim((string) app_setting($pdo, 'keuangan_pos_nama_makan', 'Makan'));

    return $nama !== '' ? $nama : 'Makan';
}

/** Nama tampilan komponen POS (override setting untuk slug tertentu). */
function keuangan_pos_display_nama(PDO $pdo, string $slug, ?string $fallback = null): string
{
    $slug = strtolower(trim($slug));
    if ($slug === 'makan') {
        return keuangan_makan_pos_nama($pdo);
    }
    if ($fallback !== null && $fallback !== '') {
        return $fallback;
    }
    foreach (keuangan_biaya_definitions() as $def) {
        if (strtolower((string) ($def['slug'] ?? '')) === $slug) {
            return (string) ($def['nama'] ?? $slug);
        }
    }

    return $slug !== '' ? ucfirst($slug) : '—';
}

function keuangan_makan_kelas_setting_key(string $kelasKeuanganKode, int $bulanTagihan = 0): string
{
    $k = strtoupper(preg_replace('/[^A-Z0-9_-]/', '', $kelasKeuanganKode) ?? '');
    if ($bulanTagihan >= 1 && $bulanTagihan <= 12) {
        return 'keuangan_makan_kelas_' . $k . '_b' . $bulanTagihan;
    }

    return 'keuangan_makan_kelas_' . $k . '_default';
}

/** null = tidak di-set (pakai tarif tier); int = override eksplisit (boleh 0). */
function keuangan_makan_kelas_override_nominal(
    PDO $pdo,
    string $kelasKeuanganKode,
    int $bulanTagihan = 0
): ?int {
    $kode = strtoupper(trim($kelasKeuanganKode));
    if ($kode === '') {
        return null;
    }
    if ($bulanTagihan >= 1 && $bulanTagihan <= 12) {
        $keyBulan = keuangan_makan_kelas_setting_key($kode, $bulanTagihan);
        if (array_key_exists($keyBulan, app_settings_cache($pdo))) {
            return max(0, (int) app_setting($pdo, $keyBulan, '0'));
        }
    }
    $keyDefault = keuangan_makan_kelas_setting_key($kode, 0);
    if (!array_key_exists($keyDefault, app_settings_cache($pdo))) {
        return null;
    }

    return max(0, (int) app_setting($pdo, $keyDefault, '0'));
}

function keuangan_makan_nominal_for_kelas(
    PDO $pdo,
    string $kelasKategori,
    int $bulanTagihan,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai
): int {
    $kode = kelas_keuangan_resolve_kode($pdo, $kelasKategori) ?? strtoupper(trim($kelasKategori));
    if ($kode !== '') {
        $override = keuangan_makan_kelas_override_nominal($pdo, $kode, $bulanTagihan);
        if ($override !== null) {
            return $override;
        }
    }

    $tier = keuangan_tier_key_from_kelas($kelasKategori, $pdo);
    if ($bulanTagihan >= 1 && $bulanTagihan <= 12 && $tahunAjaranMulai > 0) {
        return keuangan_tarif_bulanan_resolve($pdo, 'makan', $tier, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai);
    }

    return keuangan_fee_nominal_for_tier($pdo, ['slug' => 'makan', 'default' => ['muadalah' => 220000, 'wustho' => 220000, 'ulya' => 220000]], $tier);
}

/** @return array{ok:bool,message:string} */
function keuangan_makan_save_pengaturan(PDO $pdo, array $post): array
{
    ensure_kelas_keuangan_table($pdo);
    $namaPos = trim((string) ($post['keuangan_pos_nama_makan'] ?? ''));
    if ($namaPos === '') {
        return ['ok' => false, 'message' => 'Nama tampilan komponen makan wajib diisi.'];
    }
    save_setting($pdo, 'keuangan_pos_nama_makan', $namaPos);

    $rows = kelas_keuangan_list_active($pdo);
    $validKodes = [];
    foreach ($rows as $row) {
        $kode = strtoupper(trim((string) ($row['kode'] ?? '')));
        if ($kode !== '') {
            $validKodes[$kode] = true;
        }
    }

    $input = (array) ($post['makan_kelas'] ?? []);
    foreach ($validKodes as $kode => $_) {
        $keyDefault = keuangan_makan_kelas_setting_key($kode, 0);
        $rawDefault = trim((string) ($input[$kode]['default'] ?? ''));
        if ($rawDefault === '') {
            $st = $pdo->prepare('DELETE FROM app_settings WHERE setting_key = :k LIMIT 1');
            $st->execute(['k' => $keyDefault]);
        } else {
            save_setting($pdo, $keyDefault, (string) max(0, keuangan_money_input_to_int($rawDefault)));
        }
        for ($b = 1; $b <= 12; $b++) {
            $keyBulan = keuangan_makan_kelas_setting_key($kode, $b);
            $rawBulan = trim((string) ($input[$kode]['bulan'][$b] ?? ''));
            if ($rawBulan === '') {
                $st = $pdo->prepare('DELETE FROM app_settings WHERE setting_key = :k LIMIT 1');
                $st->execute(['k' => $keyBulan]);
            } else {
                save_setting($pdo, $keyBulan, (string) max(0, keuangan_money_input_to_int($rawBulan)));
            }
        }
    }

    app_settings_cache($pdo, true);
    if (function_exists('keuangan_santri_opsional_cache_invalidate')) {
        require_once __DIR__ . '/tagihan_bulanan.php';
        keuangan_santri_opsional_cache_invalidate();
    }
    if (function_exists('keuangan_dashboard_cache_invalidate')) {
        require_once __DIR__ . '/keuangan_dashboard.php';
        keuangan_dashboard_cache_invalidate();
    }

    return ['ok' => true, 'message' => 'Pengaturan makan per kelas dan nama tampilan disimpan.'];
}

/** Tier fallback untuk preview di form pengaturan. */
function keuangan_makan_tier_fallback_nominal(PDO $pdo, string $tier): int
{
    return keuangan_fee_nominal_for_tier(
        $pdo,
        ['slug' => 'makan', 'default' => ['muadalah' => 220000, 'wustho' => 220000, 'ulya' => 220000]],
        $tier
    );
}
