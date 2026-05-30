<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_tarif_bulanan.php';

/** Kunci pengaturan nominal tambahan syahriyah khusus tier muadalah. */
function keuangan_muadalah_tambahan_setting_key(int $bulanTagihan = 0): string
{
    if ($bulanTagihan >= 1 && $bulanTagihan <= 12) {
        return 'keuangan_muadalah_tambahan_bulan_' . $bulanTagihan;
    }

    return 'keuangan_muadalah_tambahan_default';
}

function keuangan_muadalah_tambahan_nominal(
    PDO $pdo,
    int $bulanTagihan = 0,
    int $tahunAjaranMulai = 0,
    int $tahunAjaranSelesai = 0
): int {
    if ($bulanTagihan >= 1 && $bulanTagihan <= 12 && $tahunAjaranMulai > 0) {
        $map = keuangan_tarif_bulanan_map($pdo, $tahunAjaranMulai, $tahunAjaranSelesai);
        $key = 'muadalah_tambahan:' . $bulanTagihan;
        if (isset($map[$key]['muadalah'])) {
            return max(0, (int) $map[$key]['muadalah']);
        }
    }

    $key = keuangan_muadalah_tambahan_setting_key($bulanTagihan);

    return max(0, (int) app_setting($pdo, $key, (string) app_setting($pdo, 'keuangan_muadalah_tambahan_default', '0')));
}

function keuangan_muadalah_tambahan_apply_to_simulasi(array $sim, string $tier, int $tambahan): array
{
    $sim['muadalah_tambahan'] = $tier === 'muadalah' ? max(0, $tambahan) : 0;
    if ($sim['muadalah_tambahan'] > 0) {
        $sim['expected'] = max(0, (int) ($sim['expected'] ?? 0)) + $sim['muadalah_tambahan'];
    }

    return $sim;
}

function keuangan_muadalah_tambahan_save_settings(PDO $pdo, array $post): array
{
    $default = max(0, (int) ($post['muadalah_tambahan_default'] ?? 0));
    save_setting($pdo, 'keuangan_muadalah_tambahan_default', (string) $default);

    for ($b = 1; $b <= 12; $b++) {
        $val = max(0, (int) ($post['muadalah_tambahan_bulan'][$b] ?? $post['muadalah_tambahan_bulan_' . $b] ?? $default));
        save_setting($pdo, keuangan_muadalah_tambahan_setting_key($b), (string) $val);
    }

    return ['ok' => true, 'message' => 'Pengaturan tambahan syahriyah muadalah disimpan.'];
}
