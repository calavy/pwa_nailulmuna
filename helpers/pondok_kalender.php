<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/akademik.php';
require_once __DIR__ . '/hijri_kalender.php';

/** Mode kalender operasional (sama dengan pengaturan tagihan pondok). */
function pondok_kalender_mode(PDO $pdo): string
{
    $m = strtoupper(trim((string) app_setting($pdo, 'wa_tagihan_calendar', 'HIJRIYAH')));

    return $m === 'MASEHI' ? 'masehi' : 'hijriyah';
}

function pondok_kalender_hijriyah(PDO $pdo): bool
{
    return pondok_kalender_mode($pdo) === 'hijriyah';
}

/** Aktifkan kalender Hijriyah di pengaturan pondok + tampilan kalender akademik per bulan H. */
function pondok_aktifkan_kalender_hijriyah(PDO $pdo): void
{
    save_setting($pdo, 'wa_tagihan_calendar', 'HIJRIYAH');
    save_setting($pdo, 'akademik_kalender_default_view', 'bulan');
}

/** @return array{aktif:bool,mode:string,label:string} */
function pondok_kalender_status(PDO $pdo): array
{
    $mode = strtoupper(trim((string) app_setting($pdo, 'wa_tagihan_calendar', 'HIJRIYAH')));
    if (!in_array($mode, ['MASEHI', 'HIJRIYAH'], true)) {
        $mode = 'HIJRIYAH';
    }

    return [
        'aktif' => $mode === 'HIJRIYAH',
        'mode' => $mode,
        'label' => $mode === 'HIJRIYAH' ? 'Hijriyah (Muharram–Dzulhijjah)' : 'Masehi (Januari–Desember)',
    ];
}

/** TA tersimpan masih format tahun Masehi (2025/2026) padahal mode Hijriyah. */
function pondok_ta_legacy_masehi_disimpan(int $tahunMulai): bool
{
    return $tahunMulai >= 1900 && $tahunMulai < 2100;
}

/** Label bulan untuk dropdown/tabel (Hijriyah = Muharram … Dzulhijjah, tanpa "Mei (M)"). */
function pondok_bulan_slot_label_tampilan(PDO $pdo, array $slot): string
{
    if (pondok_kalender_hijriyah($pdo)) {
        $nama = trim((string) ($slot['label_ringkas'] ?? ''));
        $th = (int) ($slot['tahun_hijri'] ?? 0);

        return $nama !== '' ? ($th > 0 ? $nama . ' ' . $th . ' H' : $nama) : (string) ($slot['label'] ?? '');
    }

    return trim((string) ($slot['label_ringkas'] ?? $slot['label'] ?? ''));
}

function pondok_ta_tahun_min(PDO $pdo): int
{
    return pondok_kalender_hijriyah($pdo) ? 1300 : 2000;
}

function pondok_ta_tahun_max(PDO $pdo): int
{
    return pondok_kalender_hijriyah($pdo) ? 1500 : 2105;
}

/** @return array{mulai:int,selesai:int} */
function pondok_tahun_ajaran_masehi_dari_tanggal(?string $dateYmd = null): array
{
    $dateYmd = trim((string) ($dateYmd ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd)) {
        $dateYmd = date('Y-m-d');
    }
    $ts = strtotime($dateYmd) ?: time();
    $y = (int) date('Y', $ts);
    $m = (int) date('n', $ts);
    $mulai = $m >= 7 ? $y : $y - 1;

    return ['mulai' => $mulai, 'selesai' => $mulai + 1];
}

/** Tahun ajaran Hijriyah: tahun H. berjalan (TA Y/Y+1), 12 bulan = Muharram–Dzulhijjah tahun Y. */
function pondok_tahun_ajaran_hijri_dari_tanggal(PDO $pdo, ?string $dateYmd = null): array
{
    $dateYmd = trim((string) ($dateYmd ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd)) {
        $dateYmd = date('Y-m-d');
    }
    $h = akademik_hijri_tanggal_penuh($pdo, $dateYmd);
    if (!preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $h, $mh)) {
        $anchor = akademik_hijri_anchor_hari_ini($pdo);
        $mulai = (int) $anchor['y'];
    } else {
        $mulai = (int) $mh[1];
    }
    if (!hijri_tahun_valid($mulai)) {
        $mulai = akademik_hijri_anchor_hari_ini($pdo)['y'];
    }

    return ['mulai' => $mulai, 'selesai' => $mulai + 1];
}

/** @return array{mulai:int,selesai:int} */
function pondok_tahun_ajaran_from_date(PDO $pdo, ?string $dateYmd = null): array
{
    return pondok_kalender_hijriyah($pdo)
        ? pondok_tahun_ajaran_hijri_dari_tanggal($pdo, $dateYmd)
        : pondok_tahun_ajaran_masehi_dari_tanggal($dateYmd);
}

/**
 * Tahun ajaran aktif: dari pengaturan pondok, atau hitung dari tanggal hari ini.
 *
 * @return array{mulai:int,selesai:int}
 */
function pondok_tahun_ajaran_aktif(PDO $pdo, ?string $dateYmd = null): array
{
    $auto = pondok_tahun_ajaran_from_date($pdo, $dateYmd);
    $min = pondok_ta_tahun_min($pdo);
    $max = pondok_ta_tahun_max($pdo);
    $mulai = (int) app_setting($pdo, 'keuangan_periode_mulai', '0');
    $selesai = (int) app_setting($pdo, 'keuangan_periode_selesai', '0');
    if ($mulai >= $min && $mulai <= $max) {
        if (pondok_kalender_hijriyah($pdo) && pondok_ta_legacy_masehi_disimpan($mulai)) {
            return $auto;
        }
        if ($selesai < $mulai || $selesai > $max) {
            $selesai = $mulai + 1;
        }

        return ['mulai' => $mulai, 'selesai' => $selesai];
    }

    return $auto;
}

/** @param array{mulai?:int,selesai?:int} $ta */
function pondok_tahun_ajaran_label(PDO $pdo, array $ta): string
{
    $mulai = (int) ($ta['mulai'] ?? 0);
    $selesai = (int) ($ta['selesai'] ?? 0);
    if ($selesai <= 0) {
        $selesai = $mulai + 1;
    }
    $label = $mulai . '/' . $selesai;

    return pondok_kalender_hijriyah($pdo) ? $label . ' H' : $label;
}

/** @return array{mulai:int,selesai:int} */
function pondok_normalisasi_tahun_ajaran_input(PDO $pdo, int $mulai, int $selesai): array
{
    if (pondok_kalender_hijriyah($pdo) && pondok_ta_legacy_masehi_disimpan($mulai)) {
        $auto = pondok_tahun_ajaran_hijri_dari_tanggal($pdo);
        $mulai = $auto['mulai'];
        $selesai = $auto['selesai'];
    }
    $min = pondok_ta_tahun_min($pdo);
    $max = pondok_ta_tahun_max($pdo);
    $mulai = max($min, min($max, $mulai));
    $selesai = max($min, min($max, $selesai));
    if (pondok_kalender_hijriyah($pdo)) {
        $selesai = $mulai + 1;
    } elseif ($selesai < $mulai) {
        $selesai = $mulai + 1;
    }

    return ['mulai' => $mulai, 'selesai' => $selesai];
}

/** @return array{min:int,max:int,suffix:string,label_mulai:string,label_selesai:string} */
function pondok_ta_form_meta(PDO $pdo): array
{
    $hijri = pondok_kalender_hijriyah($pdo);

    return [
        'min' => pondok_ta_tahun_min($pdo),
        'max' => pondok_ta_tahun_max($pdo),
        'suffix' => $hijri ? ' H' : '',
        'label_mulai' => $hijri ? 'Tahun Hijriyah mulai' : 'Tahun Masehi mulai',
        'label_selesai' => $hijri ? 'Tahun Hijriyah selesai' : 'Tahun Masehi selesai',
    ];
}

/** Rentang tanggal Masehi untuk satu tahun ajaran. */
function pondok_tahun_ajaran_gregorian_range(PDO $pdo, int $tahunAjaranMulai, int $tahunAjaranSelesai): array
{
    if (pondok_kalender_hijriyah($pdo)) {
        $hMulai = max(1300, min(1500, $tahunAjaranMulai));
        [$masehiAwal] = akademik_gregorian_range_from_hijri_month($pdo, $hMulai, 1);
        [, $masehiAkhir] = akademik_gregorian_range_from_hijri_month($pdo, $hMulai, 12);

        return [$masehiAwal, $masehiAkhir];
    }

    return [
        sprintf('%04d-07-01', $tahunAjaranMulai),
        sprintf('%04d-06-30', $tahunAjaranSelesai),
    ];
}

/** @return array{0:int,1:int} */
function pondok_hijri_month_step(int $tahunH, int $bulanH): array
{
    if ($bulanH < 12) {
        return [$tahunH, $bulanH + 1];
    }

    return [$tahunH + 1, 1];
}

/** @return array<int, string> */
function pondok_bulan_nama_map(PDO $pdo): array
{
    if (!pondok_kalender_hijriyah($pdo)) {
        return [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
            7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];
    }

    return hijri_nama_bulan_list();
}

function pondok_kalender_hijriyah_ym(PDO $pdo, string $tanggalMasehi): string
{
    return akademik_hijri_ym_untuk_masehi($pdo, $tanggalMasehi);
}

/**
 * 12 slot bulan dalam satu tahun ajaran (indeks tagihan 1–12).
 *
 * @return list<array{
 *   slot:int,
 *   bulan_tagihan:int,
 *   label:string,
 *   label_ringkas:string,
 *   kalender_hijriyah:?string,
 *   bulan_hijri:int,
 *   tahun_hijri:int,
 *   masehi_awal:string,
 *   masehi_akhir:string
 * }>
 */
function pondok_bulan_slots_tahun_ajaran(PDO $pdo, int $tahunAjaranMulai, int $tahunAjaranSelesai): array
{
    $ta = pondok_normalisasi_tahun_ajaran_input($pdo, $tahunAjaranMulai, $tahunAjaranSelesai);
    $tahunAjaranMulai = $ta['mulai'];
    $tahunAjaranSelesai = $ta['selesai'];

    if (pondok_kalender_hijriyah($pdo)) {
        $slots = [];
        for ($m = 1; $m <= 12; $m++) {
            [$masehiAwal, $masehiAkhir] = akademik_gregorian_range_from_hijri_month($pdo, $tahunAjaranMulai, $m);
            $kh = sprintf('%04d-%02d', $tahunAjaranMulai, $m);
            $namaBulan = hijri_indeks_ke_nama($m);
            $slots[] = [
                'slot' => $m,
                'bulan_tagihan' => $m,
                'label' => $namaBulan . ' ' . $tahunAjaranMulai . ' H',
                'label_ringkas' => $namaBulan,
                'kalender_hijriyah' => $kh,
                'bulan_hijri' => $m,
                'tahun_hijri' => $tahunAjaranMulai,
                'masehi_awal' => $masehiAwal,
                'masehi_akhir' => $masehiAkhir,
            ];
        }

        return $slots;
    }

    $map = pondok_bulan_nama_map($pdo);
    $slots = [];
    for ($b = 1; $b <= 12; $b++) {
        $y = $b >= 7 ? $tahunAjaranMulai : $tahunAjaranSelesai;
        $masehiAwal = sprintf('%04d-%02d-01', $y, $b);
        $masehiAkhir = date('Y-m-t', strtotime($masehiAwal) ?: time());
        $slots[] = [
            'slot' => $b,
            'bulan_tagihan' => $b,
            'label' => ($map[$b] ?? (string) $b),
            'label_ringkas' => $map[$b] ?? (string) $b,
            'kalender_hijriyah' => null,
            'bulan_hijri' => $b,
            'tahun_hijri' => 0,
            'masehi_awal' => $masehiAwal,
            'masehi_akhir' => $masehiAkhir,
        ];
    }

    return $slots;
}

/**
 * @param list<array<string, mixed>> $slots
 * @return array<string, mixed>|null
 */
function pondok_slot_dari_bulan_tagihan(array $slots, int $bulanTagihan): ?array
{
    if ($bulanTagihan < 1 || $bulanTagihan > 12) {
        return null;
    }
    foreach ($slots as $slot) {
        if ((int) ($slot['bulan_tagihan'] ?? 0) === $bulanTagihan) {
            return $slot;
        }
    }

    return $slots[$bulanTagihan - 1] ?? null;
}

/** @return array<string, mixed>|null */
function pondok_slot_dari_kalender_hijriyah(array $slots, string $kalenderHijriyah): ?array
{
    $kh = trim($kalenderHijriyah);
    if ($kh === '') {
        return null;
    }
    foreach ($slots as $slot) {
        if ((string) ($slot['kalender_hijriyah'] ?? '') === $kh) {
            return $slot;
        }
    }

    return null;
}

/** Slot aktif untuk tanggal (dalam TA tertentu). */
function pondok_slot_untuk_tanggal(PDO $pdo, int $tahunAjaranMulai, int $tahunAjaranSelesai, ?string $tanggalMasehi = null): ?array
{
    $tanggalMasehi = trim((string) ($tanggalMasehi ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalMasehi)) {
        $tanggalMasehi = date('Y-m-d');
    }
    $slots = pondok_bulan_slots_tahun_ajaran($pdo, $tahunAjaranMulai, $tahunAjaranSelesai);
    if ($slots === []) {
        return null;
    }

    if (pondok_kalender_hijriyah($pdo)) {
        $kh = pondok_kalender_hijriyah_ym($pdo, $tanggalMasehi);
        $byKh = pondok_slot_dari_kalender_hijriyah($slots, $kh);
        if ($byKh !== null) {
            return $byKh;
        }
        foreach ($slots as $slot) {
            $a = (string) ($slot['masehi_awal'] ?? '');
            $b = (string) ($slot['masehi_akhir'] ?? '');
            if ($a !== '' && $b !== '' && $tanggalMasehi >= $a && $tanggalMasehi <= $b) {
                return $slot;
            }
        }
    } else {
        $bulan = max(1, min(12, (int) date('n', strtotime($tanggalMasehi) ?: time())));

        return pondok_slot_dari_bulan_tagihan($slots, $bulan);
    }

    return $slots[0];
}

/**
 * Periode berjalan: tahun ajaran + bulan/slot tagihan sesuai kalender pondok.
 *
 * @return array{
 *   mulai:int,selesai:int,bulan:int,bulan_label:string,ta_label:string,
 *   tahun_kalender:int,kalender_mode:string,kalender_hijriyah:?string,
 *   masehi_awal:?string,masehi_akhir:?string,periode_tampilan:string
 * }
 */
function pondok_periode_berjalan(PDO $pdo, ?string $dateYmd = null): array
{
    $dateYmd = trim((string) ($dateYmd ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd)) {
        $dateYmd = date('Y-m-d');
    }
    $ta = pondok_tahun_ajaran_from_date($pdo, $dateYmd);
    $slot = pondok_slot_untuk_tanggal($pdo, $ta['mulai'], $ta['selesai'], $dateYmd);
    $bulan = (int) ($slot['bulan_tagihan'] ?? keuangan_bulan_berjalan_masehi($dateYmd));
    $label = (string) ($slot['label_ringkas'] ?? $slot['label'] ?? (string) $bulan);
    $mode = pondok_kalender_mode($pdo);
    $periodeTampilan = $mode === 'hijriyah'
        ? ((string) ($slot['label'] ?? $label))
        : ($label . ' ' . (int) date('Y', strtotime(trim((string) ($dateYmd ?? date('Y-m-d')))) ?: time()));

    return [
        'mulai' => $ta['mulai'],
        'selesai' => $ta['selesai'],
        'bulan' => $bulan,
        'bulan_label' => $label,
        'ta_label' => pondok_tahun_ajaran_label($pdo, $ta),
        'tahun_kalender' => (int) date('Y', strtotime(trim((string) ($dateYmd ?? date('Y-m-d')))) ?: time()),
        'kalender_mode' => $mode,
        'kalender_hijriyah' => $slot['kalender_hijriyah'] ?? null,
        'masehi_awal' => $slot['masehi_awal'] ?? null,
        'masehi_akhir' => $slot['masehi_akhir'] ?? null,
        'periode_tampilan' => $periodeTampilan,
    ];
}

function keuangan_bulan_berjalan_masehi(?string $dateYmd = null): int
{
    $dateYmd = trim((string) ($dateYmd ?? date('Y-m-d')));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateYmd)) {
        $dateYmd = date('Y-m-d');
    }

    return max(1, min(12, (int) date('n', strtotime($dateYmd) ?: time())));
}

function pondok_bulan_label(PDO $pdo, int $bulanTagihan, int $tahunAjaranMulai, int $tahunAjaranSelesai): string
{
    $slot = pondok_slot_dari_bulan_tagihan(
        pondok_bulan_slots_tahun_ajaran($pdo, $tahunAjaranMulai, $tahunAjaranSelesai),
        $bulanTagihan
    );
    if ($slot !== null) {
        return pondok_bulan_slot_label_tampilan($pdo, $slot);
    }

    return (string) $bulanTagihan;
}

/**
 * Klausa SQL + parameter untuk mencocokkan pembayaran bulanan pada slot tagihan.
 *
 * @return array{sql:string,params:array<string, mixed>}
 */
function pondok_sql_match_bulan_tagihan(
    PDO $pdo,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    int $bulanTagihan,
    string $tableAlias = 'p'
): array {
    $slot = pondok_slot_dari_bulan_tagihan(
        pondok_bulan_slots_tahun_ajaran($pdo, $tahunAjaranMulai, $tahunAjaranSelesai),
        $bulanTagihan
    );
    $kh = (string) ($slot['kalender_hijriyah'] ?? '');
    $hasKhCol = table_exists($pdo, 'keuangan_pembayaran')
        && column_exists($pdo, 'keuangan_pembayaran', 'kalender_hijriyah');

    if ($hasKhCol && $kh !== '' && pondok_kalender_hijriyah($pdo)) {
        $parts = [
            $tableAlias . '.kalender_hijriyah = :pondok_kh',
            '(' . $tableAlias . '.kalender_hijriyah IS NULL AND ' . $tableAlias . '.bulan_tagihan = :pondok_bulan)',
        ];
        $params = ['pondok_kh' => $kh, 'pondok_bulan' => $bulanTagihan];
        $mAwal = (string) ($slot['masehi_awal'] ?? '');
        $mAkhir = (string) ($slot['masehi_akhir'] ?? '');
        if ($mAwal !== '' && $mAkhir !== '' && column_exists($pdo, 'keuangan_pembayaran', 'tanggal_bayar')) {
            $parts[] = '(' . $tableAlias . '.kalender_hijriyah IS NULL AND ' . $tableAlias . '.tanggal_bayar BETWEEN :pondok_m_awal AND :pondok_m_akhir)';
            $params['pondok_m_awal'] = $mAwal;
            $params['pondok_m_akhir'] = $mAkhir;
        }

        return [
            'sql' => '(' . implode(' OR ', $parts) . ')',
            'params' => $params,
        ];
    }

    return [
        'sql' => $tableAlias . '.bulan_tagihan = :pondok_bulan',
        'params' => ['pondok_bulan' => $bulanTagihan],
    ];
}

function ensure_keuangan_pembayaran_kalender_hijriyah(PDO $pdo): void
{
    if (!table_exists($pdo, 'keuangan_pembayaran')) {
        return;
    }
    if (!column_exists($pdo, 'keuangan_pembayaran', 'kalender_hijriyah')) {
        $pdo->exec('ALTER TABLE keuangan_pembayaran ADD COLUMN kalender_hijriyah VARCHAR(7) NULL AFTER bulan_tagihan');
        $pdo->exec('ALTER TABLE keuangan_pembayaran ADD INDEX idx_keu_bayar_kalender_h (kalender_hijriyah)');
    }
}

/** Isi kalender_hijriyah dari slot TA bila kolom ada. */
function pondok_kalender_hijriyah_untuk_simpan_pembayaran(
    PDO $pdo,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    int $bulanTagihan
): ?string {
    ensure_keuangan_pembayaran_kalender_hijriyah($pdo);
    if (!pondok_kalender_hijriyah($pdo) || $bulanTagihan < 1) {
        return null;
    }
    $slot = pondok_slot_dari_bulan_tagihan(
        pondok_bulan_slots_tahun_ajaran($pdo, $tahunAjaranMulai, $tahunAjaranSelesai),
        $bulanTagihan
    );

    return isset($slot['kalender_hijriyah']) ? (string) $slot['kalender_hijriyah'] : null;
}

/** Rentang Masehi awal–akhir untuk laporan presensi / rekap per bulan tagihan. */
function pondok_rentang_masehi_bulan_tagihan(
    PDO $pdo,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    int $bulanTagihan
): array {
    $slot = pondok_slot_dari_bulan_tagihan(
        pondok_bulan_slots_tahun_ajaran($pdo, $tahunAjaranMulai, $tahunAjaranSelesai),
        $bulanTagihan
    );
    if ($slot !== null && !empty($slot['masehi_awal']) && !empty($slot['masehi_akhir'])) {
        return [(string) $slot['masehi_awal'], (string) $slot['masehi_akhir']];
    }

    if (pondok_kalender_hijriyah($pdo)) {
        return akademik_gregorian_range_from_hijri_month($pdo, $tahunAjaranMulai, max(1, min(12, $bulanTagihan)));
    }

    $y = $bulanTagihan >= 7 ? $tahunAjaranMulai : $tahunAjaranSelesai;
    $start = sprintf('%04d-%02d-01', $y, $bulanTagihan);
    $end = date('Y-m-t', strtotime($start) ?: time());

    return [$start, $end];
}

/** Kalender hijriyah YYYY-MM untuk filter presensi (rekap). */
function pondok_kalender_hijriyah_dari_bulan_tagihan(
    PDO $pdo,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    int $bulanTagihan
): string {
    $slot = pondok_slot_dari_bulan_tagihan(
        pondok_bulan_slots_tahun_ajaran($pdo, $tahunAjaranMulai, $tahunAjaranSelesai),
        $bulanTagihan
    );
    if (!empty($slot['kalender_hijriyah'])) {
        return (string) $slot['kalender_hijriyah'];
    }

    return sprintf('%04d-%02d', $tahunAjaranMulai, max(1, min(12, $bulanTagihan)));
}

/** Label periode pembayaran/tagihan untuk tampilan (keuangan, wali, riwayat). */
function pondok_label_periode_pembayaran(PDO $pdo, array $row): string
{
    $jenis = strtoupper(trim((string) ($row['jenis_periode'] ?? '')));
    $tm = (int) ($row['tahun_ajaran_mulai'] ?? 0);
    $ts = (int) ($row['tahun_ajaran_selesai'] ?? 0);
    if ($ts <= 0 && $tm > 0) {
        $ts = $tm + 1;
    }
    $taLabel = pondok_tahun_ajaran_label($pdo, ['mulai' => $tm, 'selesai' => $ts]);
    $bl = (int) ($row['bulan_tagihan'] ?? 0);
    $kh = trim((string) ($row['kalender_hijriyah'] ?? ''));

    if ($jenis === 'AWAL_TAHUN') {
        return 'Awal tahun · TA ' . $taLabel;
    }
    if ($jenis === 'BULANAN' && $bl >= 1 && $bl <= 12 && $tm > 0) {
        if ($kh !== '') {
            $slot = pondok_slot_dari_kalender_hijriyah(
                pondok_bulan_slots_tahun_ajaran($pdo, $tm, $ts),
                $kh
            );
            if ($slot !== null) {
                return (string) ($slot['label'] ?? $slot['label_ringkas'] ?? (string) $bl) . ' · TA ' . $taLabel;
            }
        }

        return pondok_bulan_label($pdo, $bl, $tm, $ts) . ' · TA ' . $taLabel;
    }

    return trim($jenis . ($taLabel !== '0/0' && $taLabel !== '0/1' ? ' · TA ' . $taLabel : ''));
}

/**
 * Sesuaikan satu baris pembayaran dari tanggal_bayar ke kalender pondok aktif.
 *
 * @param array<string, mixed> $row
 * @return array{mulai:int,selesai:int,bulan:?int,kalender_hijriyah:?string}|null
 */
function pondok_derive_pembayaran_dari_tanggal(PDO $pdo, array $row): ?array
{
    $tgl = trim((string) ($row['tanggal_bayar'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) {
        return null;
    }
    $jenis = strtoupper(trim((string) ($row['jenis_periode'] ?? 'BULANAN')));
    $ta = pondok_tahun_ajaran_from_date($pdo, $tgl);
    $ta = pondok_normalisasi_tahun_ajaran_input($pdo, $ta['mulai'], $ta['selesai']);

    if ($jenis === 'AWAL_TAHUN') {
        return [
            'mulai' => $ta['mulai'],
            'selesai' => $ta['selesai'],
            'bulan' => null,
            'kalender_hijriyah' => null,
        ];
    }

    $slot = pondok_slot_untuk_tanggal($pdo, $ta['mulai'], $ta['selesai'], $tgl);
    if ($slot === null) {
        return null;
    }

    return [
        'mulai' => $ta['mulai'],
        'selesai' => $ta['selesai'],
        'bulan' => (int) ($slot['bulan_tagihan'] ?? 0) ?: null,
        'kalender_hijriyah' => isset($slot['kalender_hijriyah']) ? (string) $slot['kalender_hijriyah'] : null,
    ];
}

/**
 * Backfill pembayaran & presensi agar selaras kalender Hijriyah (data lama Masehi).
 *
 * @return array{pembayaran:int,presensi:int,jeda:int,skipped:int}
 */
function pondok_backfill_kalender_hijriyah(PDO $pdo, bool $force = false): array
{
    ensure_keuangan_pembayaran_kalender_hijriyah($pdo);
    $stats = ['pembayaran' => 0, 'presensi' => 0, 'jeda' => 0, 'skipped' => 0];

    if (table_exists($pdo, 'keuangan_pembayaran')) {
        $st = $pdo->query('
            SELECT id, jenis_periode, tahun_ajaran_mulai, tahun_ajaran_selesai, bulan_tagihan,
                   tanggal_bayar, kalender_hijriyah
            FROM keuangan_pembayaran
            ORDER BY id ASC
        ');
        $upd = $pdo->prepare('
            UPDATE keuangan_pembayaran
            SET tahun_ajaran_mulai = :tm, tahun_ajaran_selesai = :ts,
                bulan_tagihan = :bl, kalender_hijriyah = :kh
            WHERE id = :id
        ');
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $derived = pondok_derive_pembayaran_dari_tanggal($pdo, $row);
            if ($derived === null) {
                $stats['skipped']++;

                continue;
            }
            $same = (int) ($row['tahun_ajaran_mulai'] ?? 0) === $derived['mulai']
                && (int) ($row['tahun_ajaran_selesai'] ?? 0) === $derived['selesai']
                && (int) ($row['bulan_tagihan'] ?? 0) === (int) ($derived['bulan'] ?? 0)
                && trim((string) ($row['kalender_hijriyah'] ?? '')) === trim((string) ($derived['kalender_hijriyah'] ?? ''));
            if (!$force && $same) {
                $stats['skipped']++;

                continue;
            }
            $upd->execute([
                'tm' => $derived['mulai'],
                'ts' => $derived['selesai'],
                'bl' => $derived['bulan'],
                'kh' => $derived['kalender_hijriyah'],
                'id' => (int) $row['id'],
            ]);
            $stats['pembayaran']++;
        }
    }

    if (table_exists($pdo, 'presensi') && column_exists($pdo, 'presensi', 'kalender_hijriyah')) {
        $stP = $pdo->query('SELECT id, tanggal_presensi, kalender_hijriyah FROM presensi ORDER BY id ASC');
        $updP = $pdo->prepare('UPDATE presensi SET kalender_hijriyah = :kh WHERE id = :id');
        while ($row = $stP->fetch(PDO::FETCH_ASSOC)) {
            $tgl = trim((string) ($row['tanggal_presensi'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) {
                $stats['skipped']++;

                continue;
            }
            $kh = pondok_kalender_hijriyah_ym($pdo, $tgl);
            if (!$force && trim((string) ($row['kalender_hijriyah'] ?? '')) === $kh) {
                $stats['skipped']++;

                continue;
            }
            $updP->execute(['kh' => $kh, 'id' => (int) $row['id']]);
            $stats['presensi']++;
        }
    }

    if (table_exists($pdo, 'keuangan_syahriyah_potongan_jeda') && pondok_kalender_hijriyah($pdo)) {
        $stJ = $pdo->query('
            SELECT id, tahun_ajaran_mulai, tahun_ajaran_selesai, bulan_tagihan
            FROM keuangan_syahriyah_potongan_jeda
            ORDER BY id ASC
        ');
        $updJ = $pdo->prepare('
            UPDATE keuangan_syahriyah_potongan_jeda
            SET tahun_ajaran_mulai = :tm, tahun_ajaran_selesai = :ts, bulan_tagihan = :bl
            WHERE id = :id
        ');
        $hijriMin = pondok_ta_tahun_min($pdo);
        while ($row = $stJ->fetch(PDO::FETCH_ASSOC)) {
            $bl = (int) ($row['bulan_tagihan'] ?? 0);
            $tm = (int) ($row['tahun_ajaran_mulai'] ?? 0);
            $ts = (int) ($row['tahun_ajaran_selesai'] ?? 0);
            if ($bl < 1 || $bl > 12) {
                $stats['skipped']++;

                continue;
            }
            if ($tm >= $hijriMin) {
                $stats['skipped']++;

                continue;
            }
            $yMasehi = $bl >= 7 ? $tm : ($ts > $tm ? $ts : $tm + 1);
            $tengahBulan = sprintf('%04d-%02d-15', $yMasehi, $bl);
            $derived = pondok_derive_pembayaran_dari_tanggal($pdo, [
                'jenis_periode' => 'BULANAN',
                'tanggal_bayar' => $tengahBulan,
            ]);
            if ($derived === null || ($derived['bulan'] ?? 0) === null) {
                $stats['skipped']++;

                continue;
            }
            if (!$force
                && $tm === $derived['mulai']
                && $ts === $derived['selesai']
                && $bl === (int) $derived['bulan']
            ) {
                $stats['skipped']++;

                continue;
            }
            $updJ->execute([
                'tm' => $derived['mulai'],
                'ts' => $derived['selesai'],
                'bl' => (int) $derived['bulan'],
                'id' => (int) $row['id'],
            ]);
            $stats['jeda']++;
        }
    }

    return $stats;
}
