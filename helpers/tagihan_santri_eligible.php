<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/santri_status.php';
require_once __DIR__ . '/pondok_kalender.php';
require_once __DIR__ . '/santri_penepian_keaktifan.php';

function tagihan_santri_nis_shadow(string $nis): bool
{
    return preg_match('/^\d+\.0+$/', trim($nis)) === 1;
}

/**
 * Apakah santri layak muncul di daftar tagihan/tunggakan operasional bulan tagihan TA ini.
 *
 * @param array<string, mixed> $santriRow
 */
function tagihan_santri_layak_tagih_bulan(
    PDO $pdo,
    array $santriRow,
    int $bulanTagihan,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai
): bool {
    $santriId = (int) ($santriRow['id'] ?? 0);
    if ($santriId <= 0) {
        return false;
    }
    if (isset($santriRow['is_aktif']) && (int) $santriRow['is_aktif'] !== 1) {
        return false;
    }
    $nis = (string) ($santriRow['nis'] ?? '');
    if (tagihan_santri_nis_shadow($nis)) {
        return false;
    }
    $statusRaw = (string) ($santriRow['status_santri'] ?? $santriRow['status'] ?? '');
    $keluarKat = (string) ($santriRow['keluar_kategori'] ?? '');
    $status = santri_status_normalize($statusRaw !== '' ? $statusRaw : null, $keluarKat !== '' ? $keluarKat : null);
    if (santri_status_is_nonaktif($status)) {
        return false;
    }
    $slots = pondok_bulan_slots_tahun_ajaran($pdo, $tahunAjaranMulai, $tahunAjaranSelesai);
    $slot = pondok_slot_dari_bulan_tagihan($slots, $bulanTagihan);
    if (is_array($slot)) {
        $acuan = (string) ($slot['masehi_akhir'] ?? $slot['masehi_awal'] ?? '');
        if ($acuan !== '' && santri_penepian_is_active($pdo, $santriId, $acuan)) {
            return false;
        }
    }

    return true;
}
