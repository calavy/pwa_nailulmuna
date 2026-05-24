<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

function keuangan_money_input_to_int(string $raw): int
{
    $digits = preg_replace('/[^\d]/', '', $raw) ?? '';

    return $digits === '' ? 0 : (int) $digits;
}

/**
 * Definisi pos pembayaran (tarif per tier muadalah/wustho/ulya).
 *
 * @return list<array{slug:string,nama:string,kategori:string,default:array<string,int>}>
 */
function keuangan_biaya_definitions(): array
{
    return [
        ['slug' => 'syahriyah', 'nama' => 'Syahriyah', 'kategori' => 'Bulanan', 'default' => ['muadalah' => 200000, 'wustho' => 210000, 'ulya' => 215000]],
        ['slug' => 'makan', 'nama' => 'Makan', 'kategori' => 'Bulanan', 'default' => ['muadalah' => 220000, 'wustho' => 220000, 'ulya' => 220000]],
        ['slug' => 'saku', 'nama' => 'Saku', 'kategori' => 'Bulanan', 'default' => ['muadalah' => 300000, 'wustho' => 300000, 'ulya' => 300000]],
        ['slug' => 'pendaftaran', 'nama' => 'Pendaftaran Pondok', 'kategori' => 'Awal Tahun', 'default' => ['muadalah' => 150000, 'wustho' => 150000, 'ulya' => 150000]],
        ['slug' => 'bangunan', 'nama' => 'Bangunan', 'kategori' => 'Awal Tahun', 'default' => ['muadalah' => 200000, 'wustho' => 200000, 'ulya' => 200000]],
        ['slug' => 'seragam', 'nama' => 'Seragam', 'kategori' => 'Awal Tahun', 'default' => ['muadalah' => 350000, 'wustho' => 350000, 'ulya' => 350000]],
        ['slug' => 'koperasi', 'nama' => 'Uang Pokok Koperasi', 'kategori' => 'Awal Tahun', 'default' => ['muadalah' => 100000, 'wustho' => 100000, 'ulya' => 100000]],
        ['slug' => 'rak_lemari', 'nama' => 'Rak & Lemari', 'kategori' => 'Awal Tahun', 'default' => ['muadalah' => 700000, 'wustho' => 700000, 'ulya' => 700000]],
        ['slug' => 'lks', 'nama' => 'LKS', 'kategori' => 'Awal Tahun', 'default' => ['muadalah' => 0, 'wustho' => 150000, 'ulya' => 150000]],
        ['slug' => 'his', 'nama' => 'HIS', 'kategori' => 'Awal Tahun', 'default' => ['muadalah' => 150000, 'wustho' => 150000, 'ulya' => 150000]],
        ['slug' => 'raport', 'nama' => 'Raport', 'kategori' => 'Awal Tahun', 'default' => ['muadalah' => 55000, 'wustho' => 55000, 'ulya' => 55000]],
        ['slug' => 'kis', 'nama' => 'KIS (Kartu Identitas Santri)', 'kategori' => 'Awal Tahun', 'default' => ['muadalah' => 15000, 'wustho' => 15000, 'ulya' => 15000]],
    ];
}

function keuangan_fee_nominal_for_tier(PDO $pdo, array $def, string $tier): int
{
    if (!in_array($tier, ['muadalah', 'wustho', 'ulya'], true)) {
        $tier = 'wustho';
    }
    $fallback = (int) ($def['default'][$tier] ?? 0);

    return max(0, (int) app_setting($pdo, 'keuangan_fee_' . $def['slug'] . '_' . $tier, (string) $fallback));
}

/**
 * Matriks tarif slug×tier dari cache app_settings (satu SELECT, untuk form pengaturan).
 *
 * @param list<array{slug:string,default:array<string,int>}> $biayaDefs
 * @return array<string, array<string, int>>
 */
function keuangan_fee_matrix_from_settings(PDO $pdo, array $biayaDefs): array
{
    $settings = app_settings_cache($pdo);
    $matrix = [];
    foreach ($biayaDefs as $def) {
        $slug = (string) ($def['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        $matrix[$slug] = [];
        foreach (['muadalah', 'wustho', 'ulya'] as $tier) {
            $key = 'keuangan_fee_' . $slug . '_' . $tier;
            $fallback = (int) ($def['default'][$tier] ?? 0);
            $matrix[$slug][$tier] = max(0, (int) ($settings[$key] ?? $fallback));
        }
    }

    return $matrix;
}
