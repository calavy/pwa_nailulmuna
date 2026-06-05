<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/pondok_kalender.php';
require_once __DIR__ . '/tagihan_bulanan.php';
require_once __DIR__ . '/keuangan_pkpps_syahriyah.php';
require_once __DIR__ . '/santri_ta.php';

/**
 * Kartu pembayaran syahriyah per bulan dalam satu tahun ajaran (hingga bulan berjalan).
 *
 * @return list<array{
 *   bulan_tagihan:int,
 *   label:string,
 *   status:string,
 *   harus:int,
 *   bayar:int,
 *   sisa:int,
 *   pkpps:int,
 *   keterangan:string
 * }>
 */
function keuangan_kartu_syahriyah_bulan_rows(
    PDO $pdo,
    int $santriId,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    int $bulanBerjalan
): array {
    if ($santriId <= 0) {
        return [];
    }
    $slots = pondok_bulan_slots_tahun_ajaran($pdo, $tahunAjaranMulai, $tahunAjaranSelesai);
    $kat = keuangan_santri_kelas_tagihan($pdo, $santriId, $tahunAjaranMulai, $tahunAjaranSelesai);
    $out = [];

    foreach ($slots as $slot) {
        $b = (int) ($slot['bulan_tagihan'] ?? 0);
        if ($b < 1 || $b > 12) {
            continue;
        }
        $label = pondok_bulan_slot_label_tampilan($pdo, $slot);
        if ($b > $bulanBerjalan) {
            $out[] = [
                'bulan_tagihan' => $b,
                'label' => $label,
                'status' => 'belum',
                'harus' => 0,
                'bayar' => 0,
                'sisa' => 0,
                'pkpps' => 0,
                'keterangan' => 'Belum',
            ];
            continue;
        }

        $st = tagihan_wajib_status_for_month($pdo, $santriId, $b, $tahunAjaranMulai, $tahunAjaranSelesai, $kat);
        $sy = $st['per_pos']['syahriyah'] ?? [];
        $harus = (int) ($sy['expected'] ?? 0);
        $bayar = (int) ($sy['paid'] ?? 0);
        $sisa = max(0, $harus - $bayar);
        $pkpps = 0;
        if (function_exists('keuangan_pkpps_syahriyah_berlaku_untuk_santri')
            && keuangan_pkpps_syahriyah_berlaku_untuk_santri($pdo, $santriId)) {
            $kk = pkpps_kelas_keuangan_kode_for_santri($pdo, $santriId, $tahunAjaranMulai, $tahunAjaranSelesai);
            $pkpps = keuangan_pkpps_syahriyah_nominal($pdo, $b, $tahunAjaranMulai, $tahunAjaranSelesai, $kk);
        }

        $status = 'lunas';
        $ket = 'Lunas';
        if ($sisa > 0) {
            $status = $bayar > 0 ? 'sebagian' : 'belum_bayar';
            $ket = $bayar > 0 ? 'Kurang Rp ' . number_format($sisa, 0, ',', '.') : 'Belum bayar';
        } elseif ($harus <= 0) {
            $status = 'tanpa_tagihan';
            $ket = 'Tanpa tagihan';
        }

        $out[] = [
            'bulan_tagihan' => $b,
            'label' => $label,
            'status' => $status,
            'harus' => $harus,
            'bayar' => $bayar,
            'sisa' => $sisa,
            'pkpps' => $pkpps,
            'keterangan' => $ket,
        ];
    }

    return $out;
}
