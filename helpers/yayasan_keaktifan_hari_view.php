<?php

declare(strict_types=1);

/**
 * Siapkan variabel tampilan keaktifan hari dari pack data.
 *
 * @param array<string, mixed> $pack
 * @return array<string, mixed>
 */
function yayasan_keaktifan_hari_view_vars(
    array $pack,
    string $tanggal,
    string $tingkatan,
    ?string $kategori,
    int $kegiatanId = 0
): array {
    $detailKeg = (array) ($pack['detail_keg'] ?? []);
    if ($kegiatanId > 0) {
        $detailKeg = array_values(array_filter(
            $detailKeg,
            static fn (array $d): bool => (int) ($d['kegiatan_id'] ?? 0) === $kegiatanId
        ));
    }

    $totals = (array) ($pack['totals'] ?? []);
    $byTingkatan = (array) ($pack['by_tingkatan'] ?? []);
    $sdm = (array) ($pack['sdm'] ?? []);
    $riwayatPembimbingMasuk = (array) ($pack['riwayat_pembimbing'] ?? []);
    $pb = $sdm['pembimbing'] ?? ['masuk' => 0, 'total' => 0];
    $mw = $sdm['munawib'] ?? ['masuk' => 0, 'total' => 0];
    $pbPct = (int) $pb['total'] > 0 ? (int) round(100 * (int) $pb['masuk'] / (int) $pb['total']) : 0;
    $mwPct = (int) $mw['total'] > 0 ? (int) round(100 * (int) $mw['masuk'] / (int) $mw['total']) : 0;

    $bulanId = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];
    $ts = strtotime($tanggal);
    $tglLabel = $ts !== false
        ? (int) date('j', $ts) . ' ' . ($bulanId[(int) date('n', $ts)] ?? '') . ' ' . date('Y', $ts)
        : $tanggal;
    $kategoriLabel = match ($kategori) {
        'JAMAAH' => "Jama'ah",
        'TAALIM' => "Ta'lim",
        default => 'Semua kategori',
    };

    return compact(
        'detailKeg',
        'totals',
        'byTingkatan',
        'sdm',
        'riwayatPembimbingMasuk',
        'tanggal',
        'tingkatan',
        'kategori',
        'kategoriLabel',
        'tglLabel',
        'pb',
        'mw',
        'pbPct',
        'mwPct'
    );
}
