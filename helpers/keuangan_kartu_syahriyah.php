<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/pondok_kalender.php';
require_once __DIR__ . '/tagihan_bulanan.php';
require_once __DIR__ . '/keuangan_pkpps_syahriyah.php';
require_once __DIR__ . '/santri_ta.php';
require_once __DIR__ . '/keuangan_rekap.php';
require_once __DIR__ . '/keuangan_defs.php';

/**
 * @return array{paid:int,expected:int,sisa:int,status:string}
 */
function keuangan_kartu_pembayaran_pos_cell(array $info): array
{
    $expected = (int) ($info['expected'] ?? 0);
    $paid = (int) ($info['paid'] ?? 0);
    $sisa = (int) ($info['sisa'] ?? max(0, $expected - $paid));
    $status = (string) ($info['status'] ?? '—');
    if ($status === '' || $status === '—') {
        if ($expected <= 0) {
            $status = '—';
        } elseif ($sisa <= 0) {
            $status = 'Lunas';
        } elseif ($paid <= 0) {
            $status = 'Belum';
        } else {
            $status = 'Sebagian';
        }
    }

    return [
        'paid' => $paid,
        'expected' => $expected,
        'sisa' => $sisa,
        'status' => $status,
    ];
}

/**
 * Kartu pembayaran bulanan: saku, makan, syahriyah per bulan TA.
 *
 * @return list<array{
 *   bulan_tagihan:int,
 *   label:string,
 *   status:string,
 *   keterangan:string,
 *   saku:array{paid:int,expected:int,sisa:int,status:string},
 *   makan:array{paid:int,expected:int,sisa:int,status:string},
 *   syahriyah:array{paid:int,expected:int,sisa:int,status:string},
 *   harus:int,
 *   bayar:int,
 *   sisa:int,
 *   pkpps:int
 * }>
 */
function keuangan_kartu_pembayaran_bulan_rows(
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
    $biayaDefs = keuangan_biaya_definitions();
    $emptyPos = ['paid' => 0, 'expected' => 0, 'sisa' => 0, 'status' => '—'];
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
                'keterangan' => 'Belum',
                'saku' => $emptyPos,
                'makan' => $emptyPos,
                'syahriyah' => $emptyPos,
                'harus' => 0,
                'bayar' => 0,
                'sisa' => 0,
                'pkpps' => 0,
            ];
            continue;
        }

        $bd = keuangan_tagihan_breakdown_for_santri(
            $pdo,
            $santriId,
            'BULANAN',
            $b,
            $tahunAjaranMulai,
            $tahunAjaranSelesai,
            $biayaDefs
        );
        $saku = keuangan_kartu_pembayaran_pos_cell(is_array($bd['saku'] ?? null) ? $bd['saku'] : []);
        $makan = keuangan_kartu_pembayaran_pos_cell(is_array($bd['makan'] ?? null) ? $bd['makan'] : []);
        $sy = keuangan_kartu_pembayaran_pos_cell(is_array($bd['syahriyah'] ?? null) ? $bd['syahriyah'] : []);
        $pkpps = (int) (($bd['syahriyah']['pkpps_tambahan'] ?? 0));

        $harus = $saku['expected'] + $makan['expected'] + $sy['expected'];
        $bayar = min($saku['paid'], $saku['expected'])
            + min($makan['paid'], $makan['expected'])
            + min($sy['paid'], $sy['expected']);
        $sisa = max(0, $harus - $bayar);

        $status = 'lunas';
        $ket = 'Lunas';
        if ($harus <= 0) {
            $status = 'tanpa_tagihan';
            $ket = 'Tanpa tagihan';
        } elseif ($sisa > 0) {
            $status = $bayar > 0 ? 'sebagian' : 'belum_bayar';
            $ket = $bayar > 0 ? 'Kurang Rp ' . number_format($sisa, 0, ',', '.') : 'Belum bayar';
        }

        $out[] = [
            'bulan_tagihan' => $b,
            'label' => $label,
            'status' => $status,
            'keterangan' => $ket,
            'saku' => $saku,
            'makan' => $makan,
            'syahriyah' => $sy,
            'harus' => $harus,
            'bayar' => $bayar,
            'sisa' => $sisa,
            'pkpps' => $pkpps,
        ];
    }

    return $out;
}

/**
 * Komponen pembayaran awal tahun untuk satu santri.
 *
 * @return list<array{
 *   slug:string,
 *   nama:string,
 *   paid:int,
 *   expected:int,
 *   sisa:int,
 *   status:string
 * }>
 */
function keuangan_kartu_pembayaran_awal_tahun_rows(
    PDO $pdo,
    int $santriId,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai
): array {
    if ($santriId <= 0) {
        return [];
    }
    $biayaDefs = keuangan_biaya_definitions();
    $bd = keuangan_tagihan_breakdown_for_santri(
        $pdo,
        $santriId,
        'AWAL_TAHUN',
        0,
        $tahunAjaranMulai,
        $tahunAjaranSelesai,
        $biayaDefs
    );
    $namaBySlug = [];
    foreach ($biayaDefs as $def) {
        if ((string) ($def['kategori'] ?? '') !== 'Awal Tahun') {
            continue;
        }
        $slug = (string) ($def['slug'] ?? '');
        if ($slug !== '') {
            $namaBySlug[$slug] = (string) ($def['nama'] ?? $slug);
        }
    }

    $out = [];
    foreach ($bd as $slug => $info) {
        if (!is_array($info)) {
            continue;
        }
        $cell = keuangan_kartu_pembayaran_pos_cell($info);
        if ($cell['expected'] <= 0 && $cell['paid'] <= 0) {
            continue;
        }
        $out[] = [
            'slug' => (string) $slug,
            'nama' => $namaBySlug[(string) $slug] ?? (string) $slug,
            'paid' => $cell['paid'],
            'expected' => $cell['expected'],
            'sisa' => $cell['sisa'],
            'status' => $cell['status'],
        ];
    }

    return $out;
}

/**
 * @deprecated Gunakan keuangan_kartu_pembayaran_bulan_rows (tetap ada agar pemanggilan lama tidak rusak).
 * @return list<array<string, mixed>>
 */
function keuangan_kartu_syahriyah_bulan_rows(
    PDO $pdo,
    int $santriId,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    int $bulanBerjalan
): array {
    return keuangan_kartu_pembayaran_bulan_rows(
        $pdo,
        $santriId,
        $tahunAjaranMulai,
        $tahunAjaranSelesai,
        $bulanBerjalan
    );
}

/**
 * Format singkat nominal di sel tabel kartu.
 */
function keuangan_kartu_pembayaran_format_cell(array $cell, bool $future = false): string
{
    if ($future) {
        return '—';
    }
    $paid = (int) ($cell['paid'] ?? 0);
    $expected = (int) ($cell['expected'] ?? 0);
    if ($expected <= 0 && $paid <= 0) {
        return '—';
    }
    $paidTxt = 'Rp ' . number_format($paid, 0, ',', '.');
    if ($expected > 0 && $paid !== $expected) {
        return $paidTxt . ' / ' . number_format($expected, 0, ',', '.');
    }

    return $paidTxt;
}
