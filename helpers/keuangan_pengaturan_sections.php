<?php

declare(strict_types=1);

require_once __DIR__ . '/keuangan_alokasi.php';

/** @return list<string> */
function keuangan_pengaturan_valid_sections(): array
{
    return ['umum', 'tarif', 'alokasi', 'santri_bulanan', 'akun'];
}

/**
 * Normalisasi bagian lama → bagian baru (tanpa redirect).
 */
function keuangan_pengaturan_normalize_bagian(string $bagian): string
{
    $bagian = trim($bagian);
    $map = [
        'syahriyah_makan' => 'tarif',
        'makan' => 'tarif',
        'tarif_bulan' => 'tarif',
        'alokasi_awal' => 'alokasi',
        'alokasi_makan' => 'alokasi',
    ];

    return $map[$bagian] ?? $bagian;
}

/**
 * Redirect permanen dari URL lama (query hash dipertahankan).
 */
function keuangan_pengaturan_legacy_redirect(string $bagian, array $query): ?string
{
    $bagian = trim($bagian);
    $hash = '';
    if (str_contains($bagian, '#')) {
        [$bagian, $hashPart] = explode('#', $bagian, 2);
        $hash = '#' . $hashPart;
    }
    $normalized = keuangan_pengaturan_normalize_bagian($bagian);
    if ($normalized === $bagian && !in_array($bagian, ['syahriyah_makan', 'makan', 'tarif_bulan', 'alokasi_awal', 'alokasi_makan'], true)) {
        return null;
    }

    unset($query['bagian']);
    $query['bagian'] = $normalized;

    if (in_array($bagian, ['alokasi_awal', 'alokasi_makan'], true)) {
        $query['alokasi_jenis'] = $bagian === 'alokasi_awal' ? 'awal_tahun' : 'makan';
    }
    if ($bagian === 'syahriyah_makan' && $hash === '') {
        $hash = '#syahriyah-pokok';
    }
    if ($bagian === 'makan' && $hash === '') {
        $hash = '#makan-kelas';
    }
    if ($bagian === 'tarif_bulan' && $hash === '') {
        $hash = '#tarif-per-bulan';
    }

    $qs = http_build_query($query);

    return '/keuangan/pengaturan.php' . ($qs !== '' ? '?' . $qs : '') . $hash;
}

/** @return array{key:string,label:string} */
function keuangan_pengaturan_alokasi_jenis_option(string $key): array
{
    return match ($key) {
        'awal_tahun' => ['key' => 'awal_tahun', 'label' => 'Awal tahun'],
        'makan' => ['key' => 'makan', 'label' => 'Makan'],
        default => ['key' => 'syahriyah', 'label' => 'Syahriyah'],
    };
}

function keuangan_pengaturan_alokasi_jenis_dana(string $jenisKey): string
{
    return match ($jenisKey) {
        'awal_tahun' => KEUNGAN_ALOKASI_JENIS_AWAL_TAHUN,
        'makan' => KEUNGAN_ALOKASI_JENIS_MAKAN,
        default => KEUNGAN_ALOKASI_JENIS_SYAHRIYAH,
    };
}

function keuangan_pengaturan_alokasi_bagian_for_jenis(string $jenisKey): string
{
    return 'alokasi';
}

function keuangan_pengaturan_url(string $bagian, array $extra = [], string $hash = ''): string
{
    $params = array_merge(['bagian' => $bagian], $extra);
    $qs = http_build_query($params);

    return app_href('/keuangan/pengaturan.php?' . $qs . $hash);
}
