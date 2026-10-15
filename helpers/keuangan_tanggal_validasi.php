<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_ta_context.php';
require_once __DIR__ . '/pondok_kalender.php';

/**
 * Batas tanggal transaksi keuangan (TA aktif ± toleransi).
 *
 * @return array{min:string,max:string,mulai:int,selesai:int}
 */
function keuangan_tanggal_ta_bounds(PDO $pdo): array
{
    $ta = keuangan_ta_resolve($pdo);
    $mulai = (int) ($ta['mulai'] ?? (int) date('Y'));
    $selesai = (int) ($ta['selesai'] ?? $mulai + 1);
    $slots = pondok_bulan_slots_tahun_ajaran($pdo, $mulai, $selesai);
    $min = sprintf('%04d-07-01', $mulai);
    $max = sprintf('%04d-06-30', $selesai);
    if ($slots !== []) {
        $first = (string) ($slots[0]['masehi_awal'] ?? '');
        $last = (string) ($slots[count($slots) - 1]['masehi_akhir'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $first)) {
            $min = $first;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $last)) {
            $max = $last;
        }
    }
    $maxTs = strtotime($max . ' +1 year');
    $maxExtended = $maxTs !== false ? date('Y-m-d', $maxTs) : sprintf('%04d-06-30', $selesai + 1);

    return [
        'min' => $min,
        'max' => $maxExtended,
        'mulai' => $mulai,
        'selesai' => $selesai,
    ];
}

/** Normalisasi ke Y-m-d; tolak tahun < 2000 dan tanggal kalender invalid. */
function keuangan_tanggal_normalize_ymd(string $raw): ?string
{
    $raw = trim($raw);
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m)) {
        $y = (int) $m[1];
        $mo = (int) $m[2];
        $d = (int) $m[3];
        if ($y < 2000 || !checkdate($mo, $d, $y)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    return null;
}

function keuangan_tanggal_in_ta_window(PDO $pdo, string $ymd): bool
{
    $norm = keuangan_tanggal_normalize_ymd($ymd);
    if ($norm === null) {
        return false;
    }
    $bounds = keuangan_tanggal_ta_bounds($pdo);

    return $norm >= $bounds['min'] && $norm <= $bounds['max'];
}

/**
 * @return array{ok:bool,ymd:?string,message:string}
 */
function keuangan_tanggal_validasi_input(PDO $pdo, string $raw): array
{
    $norm = keuangan_tanggal_normalize_ymd($raw);
    if ($norm === null) {
        return ['ok' => false, 'ymd' => null, 'message' => 'Tanggal tidak valid (format YYYY-MM-DD, tahun ≥ 2000).'];
    }
    if (!keuangan_tanggal_in_ta_window($pdo, $norm)) {
        $bounds = keuangan_tanggal_ta_bounds($pdo);

        return [
            'ok' => false,
            'ymd' => null,
            'message' => 'Tanggal di luar periode tahun ajaran (' . $bounds['min'] . ' s/d ' . $bounds['max'] . ').',
        ];
    }

    return ['ok' => true, 'ymd' => $norm, 'message' => ''];
}

function keuangan_tanggal_suggest_from_bulan_tagihan(PDO $pdo, int $bulanTagihan, int $tahunAjaranMulai, int $tahunAjaranSelesai): ?string
{
    if ($bulanTagihan < 1 || $bulanTagihan > 12) {
        return null;
    }
    $slots = pondok_bulan_slots_tahun_ajaran($pdo, $tahunAjaranMulai, $tahunAjaranSelesai);
    $slot = pondok_slot_dari_bulan_tagihan($slots, $bulanTagihan);
    if ($slot === null) {
        return null;
    }
    $awal = (string) ($slot['masehi_awal'] ?? '');
    $akhir = (string) ($slot['masehi_akhir'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $awal)) {
        return $awal;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $akhir)) {
        return $akhir;
    }

    return null;
}

/**
 * @param array<string, mixed> $row Baris keuangan_pembayaran
 */
function keuangan_tanggal_suggest_pembayaran(PDO $pdo, array $row): ?string
{
    $bulan = (int) ($row['bulan_tagihan'] ?? 0);
    $tm = (int) ($row['tahun_ajaran_mulai'] ?? 0);
    $ts = (int) ($row['tahun_ajaran_selesai'] ?? 0);
    if ($bulan >= 1 && $bulan <= 12 && $tm > 0 && $ts > 0) {
        $s = keuangan_tanggal_suggest_from_bulan_tagihan($pdo, $bulan, $tm, $ts);
        if ($s !== null && keuangan_tanggal_normalize_ymd($s) !== null) {
            return $s;
        }
    }
    $created = trim((string) ($row['created_at'] ?? ''));
    if ($created !== '' && preg_match('/^(\d{4}-\d{2}-\d{2})/', $created, $m)) {
        $c = keuangan_tanggal_normalize_ymd($m[1]);
        if ($c !== null && keuangan_tanggal_in_ta_window($pdo, $c)) {
            return $c;
        }
    }

    return null;
}

/**
 * @param array<string, mixed> $row keuangan_pemasukan|pengeluaran
 */
function keuangan_tanggal_suggest_transaksi_umum(PDO $pdo, array $row): ?string
{
    $created = trim((string) ($row['created_at'] ?? ''));
    if ($created !== '' && preg_match('/^(\d{4}-\d{2}-\d{2})/', $created, $m)) {
        $c = keuangan_tanggal_normalize_ymd($m[1]);
        if ($c !== null && keuangan_tanggal_in_ta_window($pdo, $c)) {
            return $c;
        }
    }
    $bounds = keuangan_tanggal_ta_bounds($pdo);

    return keuangan_tanggal_normalize_ymd($bounds['min']);
}
