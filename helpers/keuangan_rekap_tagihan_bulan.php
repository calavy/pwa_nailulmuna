<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_rekap.php';
require_once __DIR__ . '/keuangan_transaksi.php';
require_once __DIR__ . '/tagihan_santri_masuk.php';

function keuangan_rekap_tagihan_cache_invalidate(): void
{
    if (isset($_SESSION['keuangan_rekap_pos_cache_v1'])) {
        unset($_SESSION['keuangan_rekap_pos_cache_v1']);
    }
    if (function_exists('keuangan_dashboard_cache_invalidate')) {
        require_once __DIR__ . '/keuangan_dashboard.php';
        keuangan_dashboard_cache_invalidate();
    }
}

/** Invalidasi cache dashboard + rekap tagihan setelah mutasi keuangan. */
function keuangan_mutasi_cache_invalidate(): void
{
    keuangan_rekap_tagihan_cache_invalidate();
}

/**
 * Ringkasan pengaturan wajib bayar santri baru vs lama.
 *
 * @return array<string, mixed>
 */
function keuangan_pengaturan_wajib_ringkas(PDO $pdo): array
{
    $biayaDefs = keuangan_biaya_definitions();
    $awalTahunDefs = [];
    foreach ($biayaDefs as $def) {
        if ((string) ($def['kategori'] ?? '') === 'Awal Tahun') {
            $awalTahunDefs[] = $def;
        }
    }

    $posAktif = keuangan_awal_tahun_pos_aktif_matrix($pdo, $awalTahunDefs);
    $komponenAwal = [];
    foreach ($awalTahunDefs as $def) {
        $slug = (string) ($def['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        $komponenAwal[] = [
            'slug' => $slug,
            'nama' => (string) ($def['nama'] ?? $slug),
            'baru' => !empty($posAktif['baru'][$slug]),
            'lama' => !empty($posAktif['lama'][$slug]),
        ];
    }

    $wajibBulanan = [];
    foreach (keuangan_tagihan_wajib_slugs() as $slug) {
        foreach ($biayaDefs as $def) {
            if ((string) ($def['slug'] ?? '') === $slug) {
                $wajibBulanan[] = (string) ($def['nama'] ?? $slug);
                break;
            }
        }
    }
    $opsBulanan = [];
    foreach (keuangan_tagihan_opsional_bulanan_slugs() as $slug) {
        foreach ($biayaDefs as $def) {
            if ((string) ($def['slug'] ?? '') === $slug) {
                $opsBulanan[] = (string) ($def['nama'] ?? $slug);
                break;
            }
        }
    }

    return [
        'tagihan_mulai_masuk' => keuangan_tagihan_mulai_masuk_enabled($pdo),
        'bedakan_awal_tahun' => keuangan_awal_tahun_bedakan_baru_lama($pdo),
        'wajib_bulanan' => $wajibBulanan,
        'opsional_bulanan' => $opsBulanan,
        'komponen_awal_tahun' => $komponenAwal,
    ];
}

/**
 * @return array{expected:int,paid:int,sisa:int,pct:int}
 */
function keuangan_rekap_tagihan_agregat_pos(
    PDO $pdo,
    string $jenisPeriode,
    int $bulanTagihan,
    int $tahunMulai,
    int $tahunSelesai,
    array $biayaDefinitions
): array {
    $rows = keuangan_rekap_pos_with_expected_cached(
        $pdo,
        $jenisPeriode,
        $bulanTagihan,
        $tahunMulai,
        $tahunSelesai,
        $biayaDefinitions
    );
    $expected = 0;
    $paid = 0;
    foreach ($rows as $r) {
        $expected += (int) ($r['expected'] ?? 0);
        $paid += (int) ($r['paid'] ?? 0);
    }
    $sisa = max(0, $expected - $paid);
    $pct = $expected > 0 ? min(100, (int) round(($paid / $expected) * 100)) : 0;

    return [
        'expected' => $expected,
        'paid' => $paid,
        'sisa' => $sisa,
        'pct' => $pct,
    ];
}

/**
 * @return array{
 *   baris:list<array<string,mixed>>,
 *   awal_tahun:array{expected:int,paid:int,sisa:int,pct:int},
 *   total:array{expected:int,paid:int,sisa:int,pct:int},
 *   pengaturan:array<string,mixed>
 * }
 */
function keuangan_rekap_tagihan_bulanan_ta(
    PDO $pdo,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    int $sampaiBulan,
    ?array $biayaDefinitions = null
): array {
    $biayaDefinitions ??= keuangan_biaya_definitions();
    $sampaiBulan = max(1, min(12, $sampaiBulan));
    $baris = [];
    $totExpected = 0;
    $totPaid = 0;

    for ($m = 1; $m <= $sampaiBulan; $m++) {
        $agg = keuangan_rekap_tagihan_agregat_pos(
            $pdo,
            'BULANAN',
            $m,
            $tahunAjaranMulai,
            $tahunAjaranSelesai,
            $biayaDefinitions
        );
        $baris[] = [
            'bulan' => $m,
            'expected' => (int) $agg['expected'],
            'paid' => (int) $agg['paid'],
            'sisa' => (int) $agg['sisa'],
            'pct' => (int) $agg['pct'],
        ];
        $totExpected += (int) $agg['expected'];
        $totPaid += (int) $agg['paid'];
    }

    $awal = keuangan_rekap_tagihan_agregat_pos(
        $pdo,
        'AWAL_TAHUN',
        1,
        $tahunAjaranMulai,
        $tahunAjaranSelesai,
        $biayaDefinitions
    );
    $totExpected += (int) $awal['expected'];
    $totPaid += (int) $awal['paid'];
    $totSisa = max(0, $totExpected - $totPaid);
    $totPct = $totExpected > 0 ? min(100, (int) round(($totPaid / $totExpected) * 100)) : 0;

    return [
        'baris' => $baris,
        'awal_tahun' => $awal,
        'total' => [
            'expected' => $totExpected,
            'paid' => $totPaid,
            'sisa' => $totSisa,
            'pct' => $totPct,
        ],
        'pengaturan' => keuangan_pengaturan_wajib_ringkas($pdo),
    ];
}

/**
 * @param list<array<string,mixed>> $barisKas
 * @param list<array<string,mixed>> $barisTagihan
 * @return list<array<string,mixed>>
 */
/**
 * Subtotal tagihan untuk rekonsiliasi UI (bulanan vs Awal Tahun vs total TA).
 *
 * @param array{baris?:list<array<string,mixed>>,tagihan?:array<string,mixed>} $rekap
 * @return array{
 *   bulanan_expected:int,
 *   bulanan_paid:int,
 *   bulanan_sisa:int,
 *   awal_tahun_expected:int,
 *   awal_tahun_paid:int,
 *   awal_tahun_sisa:int,
 *   total_expected:int,
 *   total_paid:int,
 *   total_sisa:int
 * }
 */
function keuangan_rekap_kas_tagihan_subtotals(array $rekap): array
{
    $tagihan = is_array($rekap['tagihan'] ?? null) ? $rekap['tagihan'] : [];
    $barisTagihan = is_array($tagihan['baris'] ?? null) ? $tagihan['baris'] : [];
    $bulananExpected = 0;
    $bulananPaid = 0;
    $bulananSisa = 0;
    foreach ($barisTagihan as $t) {
        $bulananExpected += (int) ($t['expected'] ?? 0);
        $bulananPaid += (int) ($t['paid'] ?? 0);
        $bulananSisa += (int) ($t['sisa'] ?? 0);
    }

    $awal = is_array($tagihan['awal_tahun'] ?? null) ? $tagihan['awal_tahun'] : [];
    $total = is_array($tagihan['total'] ?? null) ? $tagihan['total'] : [];

    return [
        'bulanan_expected' => $bulananExpected,
        'bulanan_paid' => $bulananPaid,
        'bulanan_sisa' => $bulananSisa,
        'awal_tahun_expected' => (int) ($awal['expected'] ?? 0),
        'awal_tahun_paid' => (int) ($awal['paid'] ?? 0),
        'awal_tahun_sisa' => (int) ($awal['sisa'] ?? 0),
        'total_expected' => (int) ($total['expected'] ?? 0),
        'total_paid' => (int) ($total['paid'] ?? 0),
        'total_sisa' => (int) ($total['sisa'] ?? 0),
    ];
}

function keuangan_rekap_kas_gabung_tagihan(array $barisKas, array $barisTagihan): array
{
    $byBulan = [];
    foreach ($barisTagihan as $t) {
        $byBulan[(int) ($t['bulan'] ?? 0)] = $t;
    }
    foreach ($barisKas as &$row) {
        $m = (int) ($row['bulan'] ?? 0);
        $t = $byBulan[$m] ?? null;
        $row['tagihan_target'] = is_array($t) ? (int) ($t['expected'] ?? 0) : 0;
        $row['tagihan_terbayar'] = is_array($t) ? (int) ($t['paid'] ?? 0) : 0;
        $row['tagihan_sisa'] = is_array($t) ? (int) ($t['sisa'] ?? 0) : 0;
        $row['tagihan_pct'] = is_array($t) ? (int) ($t['pct'] ?? 0) : 0;
    }
    unset($row);

    return $barisKas;
}

/**
 * Matriks tagihan per POS × bulan (sumber sama dengan rekap POS / agregat TA).
 *
 * @return array{
 *   pos:list<array{slug:string,nama:string,kategori:string,wajib:bool}>,
 *   by_slug:array<string,array{
 *     slug:string,
 *     nama:string,
 *     kategori:string,
 *     wajib:bool,
 *     bulanan:array<int,array{expected:int,paid:int,sisa:int,pct:int,paid_lebih:int}>,
 *     awal_tahun:array{expected:int,paid:int,sisa:int,pct:int,paid_lebih:int},
 *     subtotal_bulanan:array{expected:int,paid:int,sisa:int,pct:int},
 *     subtotal_ta:array{expected:int,paid:int,sisa:int,pct:int}
 *   }>,
 *   bulanan_slugs:list<string>,
 *   awal_tahun_slugs:list<string>
 * }
 */
function keuangan_rekap_tagihan_matrix_by_pos(
    PDO $pdo,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    int $sampaiBulan,
    ?array $biayaDefinitions = null
): array {
    $biayaDefinitions ??= keuangan_biaya_definitions();
    $sampaiBulan = max(1, min(12, $sampaiBulan));
    $wajibFlip = array_flip(keuangan_tagihan_wajib_slugs());

    $bySlug = [];
    $posList = [];
    $bulananSlugs = [];
    $awalSlugs = [];

    foreach ($biayaDefinitions as $def) {
        $slug = strtolower(trim((string) ($def['slug'] ?? '')));
        if ($slug === '') {
            continue;
        }
        $kat = (string) ($def['kategori'] ?? '');
        $entry = [
            'slug' => $slug,
            'nama' => (string) ($def['nama'] ?? $slug),
            'kategori' => $kat,
            'wajib' => isset($wajibFlip[$slug]),
            'bulanan' => [],
            'awal_tahun' => ['expected' => 0, 'paid' => 0, 'sisa' => 0, 'pct' => 0, 'paid_lebih' => 0],
            'subtotal_bulanan' => ['expected' => 0, 'paid' => 0, 'sisa' => 0, 'pct' => 0],
            'subtotal_ta' => ['expected' => 0, 'paid' => 0, 'sisa' => 0, 'pct' => 0],
        ];
        $bySlug[$slug] = $entry;
        $posList[] = [
            'slug' => $slug,
            'nama' => $entry['nama'],
            'kategori' => $kat,
            'wajib' => $entry['wajib'],
        ];
        if ($kat === 'Bulanan') {
            $bulananSlugs[] = $slug;
        } elseif ($kat === 'Awal Tahun') {
            $awalSlugs[] = $slug;
        }
    }

    for ($m = 1; $m <= $sampaiBulan; $m++) {
        $rows = keuangan_rekap_pos_with_expected_cached(
            $pdo,
            'BULANAN',
            $m,
            $tahunAjaranMulai,
            $tahunAjaranSelesai,
            $biayaDefinitions
        );
        foreach ($rows as $r) {
            $slug = strtolower(trim((string) ($r['pos_slug'] ?? '')));
            if ($slug === '' || !isset($bySlug[$slug])) {
                continue;
            }
            $exp = (int) ($r['expected'] ?? 0);
            $paid = (int) ($r['paid'] ?? 0);
            $sisa = max(0, $exp - $paid);
            $pct = $exp > 0 ? min(100, (int) round(($paid / $exp) * 100)) : 0;
            $bySlug[$slug]['bulanan'][$m] = [
                'expected' => $exp,
                'paid' => $paid,
                'sisa' => $sisa,
                'pct' => $pct,
                'paid_lebih' => (int) ($r['paid_lebih'] ?? 0),
            ];
        }
    }

    $awalRows = keuangan_rekap_pos_with_expected_cached(
        $pdo,
        'AWAL_TAHUN',
        1,
        $tahunAjaranMulai,
        $tahunAjaranSelesai,
        $biayaDefinitions
    );
    foreach ($awalRows as $r) {
        $slug = strtolower(trim((string) ($r['pos_slug'] ?? '')));
        if ($slug === '' || !isset($bySlug[$slug])) {
            continue;
        }
        $exp = (int) ($r['expected'] ?? 0);
        $paid = (int) ($r['paid'] ?? 0);
        $sisa = max(0, $exp - $paid);
        $pct = $exp > 0 ? min(100, (int) round(($paid / $exp) * 100)) : 0;
        $bySlug[$slug]['awal_tahun'] = [
            'expected' => $exp,
            'paid' => $paid,
            'sisa' => $sisa,
            'pct' => $pct,
            'paid_lebih' => (int) ($r['paid_lebih'] ?? 0),
        ];
    }

    foreach ($bySlug as $slug => &$entry) {
        $sumExp = 0;
        $sumPaid = 0;
        foreach ($entry['bulanan'] as $cell) {
            $sumExp += (int) ($cell['expected'] ?? 0);
            $sumPaid += (int) ($cell['paid'] ?? 0);
        }
        $sumSisa = max(0, $sumExp - $sumPaid);
        $sumPct = $sumExp > 0 ? min(100, (int) round(($sumPaid / $sumExp) * 100)) : 0;
        $entry['subtotal_bulanan'] = [
            'expected' => $sumExp,
            'paid' => $sumPaid,
            'sisa' => $sumSisa,
            'pct' => $sumPct,
        ];
        $at = $entry['awal_tahun'];
        $taExp = $sumExp + (int) ($at['expected'] ?? 0);
        $taPaid = $sumPaid + (int) ($at['paid'] ?? 0);
        $taSisa = max(0, $taExp - $taPaid);
        $taPct = $taExp > 0 ? min(100, (int) round(($taPaid / $taExp) * 100)) : 0;
        $entry['subtotal_ta'] = [
            'expected' => $taExp,
            'paid' => $taPaid,
            'sisa' => $taSisa,
            'pct' => $taPct,
        ];
    }
    unset($entry);

    usort($posList, static fn (array $a, array $b): int => strcmp((string) $a['slug'], (string) $b['slug']));
    sort($bulananSlugs);
    sort($awalSlugs);

    return [
        'pos' => $posList,
        'by_slug' => $bySlug,
        'bulanan_slugs' => $bulananSlugs,
        'awal_tahun_slugs' => $awalSlugs,
    ];
}
