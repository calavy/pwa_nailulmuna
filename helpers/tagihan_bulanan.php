<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/pondok_kalender.php';
require_once __DIR__ . '/keuangan_syahriyah_potongan.php';
require_once __DIR__ . '/santri_ta.php';

/**
 * Tarif tagihan wajib per tier (syahriyah + makan) — sekali per request.
 *
 * @return array{syahriyah: array<string,int>, makan: array<string,int>}
 */
function tagihan_wajib_tarif_cache_by_tier(PDO $pdo): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }

    $sy = keuangan_syahriyah_tarif_cache_by_tier($pdo);
    $makanDefaults = ['muadalah' => 220000, 'wustho' => 220000, 'ulya' => 220000];
    $makan = [];
    foreach ($makanDefaults as $tier => $fallback) {
        $makan[$tier] = (int) app_setting($pdo, 'keuangan_fee_makan_' . $tier, (string) $fallback);
    }

    $cache = ['syahriyah' => $sy, 'makan' => $makan];

    return $cache;
}

/**
 * Pembayaran wajib bulan ini: [santri_id][pos_slug] => nominal.
 *
 * @return array<int, array<string, int>>
 */
function tagihan_paid_wajib_map_for_month(
    PDO $pdo,
    int $bulanTagihan,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai
): array {
    if (
        $bulanTagihan < 1
        || $bulanTagihan > 12
        || !table_exists($pdo, 'keuangan_pembayaran')
        || !table_exists($pdo, 'keuangan_pembayaran_detail')
    ) {
        return [];
    }

    $bulanMatch = pondok_sql_match_bulan_tagihan($pdo, $tahunAjaranMulai, $tahunAjaranSelesai, $bulanTagihan, 'p');
    $slugs = keuangan_tagihan_wajib_slugs();
    $slugBinds = [];
    $slugParams = [];
    foreach ($slugs as $i => $slug) {
        $key = 'slug_' . $i;
        $slugBinds[] = ':' . $key;
        $slugParams[$key] = $slug;
    }

    $stmt = $pdo->prepare('
        SELECT p.santri_id, LOWER(TRIM(d.pos_slug)) AS pos_slug, COALESCE(SUM(d.nominal), 0) AS total
        FROM keuangan_pembayaran_detail d
        INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
        WHERE p.jenis_periode = \'BULANAN\'
          AND p.tahun_ajaran_mulai = :tm
          AND p.tahun_ajaran_selesai = :ts
          AND (' . $bulanMatch['sql'] . ')
          AND LOWER(TRIM(d.pos_slug)) IN (' . implode(',', $slugBinds) . ')
        GROUP BY p.santri_id, LOWER(TRIM(d.pos_slug))
    ');

    $stmt->execute(array_merge([
        'tm' => $tahunAjaranMulai,
        'ts' => $tahunAjaranSelesai,
    ], $bulanMatch['params'], $slugParams));

    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $sid = (int) ($row['santri_id'] ?? 0);
        $slug = (string) ($row['pos_slug'] ?? '');
        if ($sid <= 0 || $slug === '') {
            continue;
        }
        $map[$sid][$slug] = (int) round((float) ($row['total'] ?? 0));
    }

    return $map;
}

/**
 * Status tagihan wajib satu santri (tanpa query DB bila konteks bulk disediakan).
 *
 * @param array<int, array<string, int>> $paidMap
 * @param array{potongan:array,jeda:array,jeda_preloaded:bool,tarifByTier:array} $syCtx
 */
function tagihan_wajib_status_for_month_bulk(
    PDO $pdo,
    int $santriId,
    int $bulanTagihan,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    string $kelasKategori,
    array $paidMap,
    array $syCtx
): array {
    $tarif = tagihan_wajib_tarif_cache_by_tier($pdo);
    $tier = keuangan_tier_key_from_kelas($kelasKategori, $pdo);
    $paidSantri = $paidMap[$santriId] ?? [];

    $perPos = [];
    $expectedTotal = 0;
    $paidTotal = 0;
    $sisaTotal = 0;
    $allLunas = true;
    $anyPaid = false;
    $anyExpected = false;

    foreach (keuangan_tagihan_wajib_slugs() as $slug) {
        if ($slug === 'syahriyah') {
            $syPot = keuangan_syahriyah_simulasi(
                $pdo,
                $santriId,
                $kelasKategori,
                $bulanTagihan,
                $tahunAjaranMulai,
                $tahunAjaranSelesai,
                $syCtx
            );
            $expectedDasar = (int) ($syPot['expected_dasar'] ?? 0);
            $expected = (int) ($syPot['expected'] ?? 0);
            $persenPotongan = (float) ($syPot['persen'] ?? 0);
            $keteranganPotongan = (string) ($syPot['keterangan'] ?? '');
            $potonganNominal = (int) ($syPot['potongan_nominal'] ?? 0);
            $potonganDijeda = !empty($syPot['potongan_dijeda']);
        } else {
            $expectedDasar = max(0, (int) ($tarif[$slug][$tier] ?? 0));
            $expected = $expectedDasar;
            $persenPotongan = 0.0;
            $keteranganPotongan = '';
            $potonganNominal = 0;
            $potonganDijeda = false;
        }

        $paid = (int) ($paidSantri[$slug] ?? 0);
        $sisa = max(0, $expected - $paid);
        if ($expected > 0) {
            $anyExpected = true;
        }
        if ($paid > 0) {
            $anyPaid = true;
        }
        if ($expected > 0 && $paid < $expected) {
            $allLunas = false;
        }
        if ($expected <= 0) {
            $st = '—';
            $stClass = 'secondary';
        } elseif ($paid >= $expected) {
            $st = 'Lunas';
            $stClass = 'success';
        } elseif ($paid <= 0) {
            $st = 'Belum';
            $stClass = 'danger';
        } else {
            $st = 'Sebagian';
            $stClass = 'warning';
        }

        $perPos[$slug] = [
            'expected' => $expected,
            'expected_dasar' => $expectedDasar,
            'persen_potongan' => $persenPotongan,
            'keterangan_potongan' => $keteranganPotongan,
            'potongan_nominal' => $potonganNominal,
            'potongan_dijeda' => $potonganDijeda,
            'paid' => $paid,
            'sisa' => $sisa,
            'status' => $st,
            'statusClass' => $stClass,
        ];
        $expectedTotal += $expected;
        $paidTotal += $paid;
        $sisaTotal += $sisa;
    }

    if (!$anyExpected) {
        $status = '—';
        $statusClass = 'secondary';
    } elseif ($allLunas && $expectedTotal > 0) {
        $status = 'Lunas';
        $statusClass = 'success';
    } elseif (!$anyPaid) {
        $status = 'Belum';
        $statusClass = 'danger';
    } else {
        $status = 'Sebagian';
        $statusClass = 'warning';
    }

    return [
        'expected_total' => $expectedTotal,
        'paid_total' => $paidTotal,
        'sisa_total' => $sisaTotal,
        'status' => $status,
        'statusClass' => $statusClass,
        'per_pos' => $perPos,
    ];
}

/**
 * Konteks bulk untuk halaman daftar tagihan (potongan, jeda, tarif, pembayaran).
 *
 * @return array{
 *   sy_ctx: array,
 *   paid_map: array<int, array<string, int>>,
 *   kelas_labels: array<string, string>,
 *   tingkatan_map: array<int, array<string, string>>
 * }
 */
function tagihan_bulanan_page_context(
    PDO $pdo,
    int $bulanTagihan,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai
): array {
    static $pageCache = [];
    $key = $bulanTagihan . ':' . $tahunAjaranMulai . ':' . $tahunAjaranSelesai;
    if (isset($pageCache[$key])) {
        return $pageCache[$key];
    }

    $syCtx = keuangan_syahriyah_bulk_context($pdo, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai);
    $syCtx['jeda_preloaded'] = true;

    $pageCache[$key] = [
        'sy_ctx' => $syCtx,
        'paid_map' => tagihan_paid_wajib_map_for_month($pdo, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai),
        'kelas_labels' => kelas_keuangan_label_map($pdo),
        'tingkatan_map' => santri_tingkatan_map_for_ta($pdo, $tahunAjaranMulai, $tahunAjaranSelesai),
    ];

    return $pageCache[$key];
}

/**
 * Total tagihan wajib (syahriyah + makan) semua santri aktif — tanpa loop query per santri.
 *
 * @param list<array<string, mixed>> $santriRows
 */
function tagihan_wajib_total_expected_all_santri(PDO $pdo, array $santriRows): int
{
    $tarif = tagihan_wajib_tarif_cache_by_tier($pdo);
    $total = 0;
    foreach ($santriRows as $s) {
        $kat = trim((string) ($s['kategori_kelas'] ?? ''));
        if ($kat === '' && !empty($s['tingkatan'])) {
            $kat = (string) $s['tingkatan'];
        }
        $tier = keuangan_tier_key_from_kelas($kat, $pdo);
        $total += (int) ($tarif['syahriyah'][$tier] ?? 0) + (int) ($tarif['makan'][$tier] ?? 0);
    }

    return $total;
}
