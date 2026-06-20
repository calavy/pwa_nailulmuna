<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/pondok_kalender.php';
require_once __DIR__ . '/keuangan_syahriyah_potongan.php';
require_once __DIR__ . '/keuangan_tarif_bulanan.php';
require_once __DIR__ . '/santri_ta.php';
require_once __DIR__ . '/santri_list_sort.php';
require_once __DIR__ . '/santri_wa.php';
require_once __DIR__ . '/tagihan_santri_masuk.php';
require_once __DIR__ . '/keuangan_pkpps_syahriyah.php';

/**
 * Tarif pos bulanan per tier — sekali per request.
 *
 * @return array<string, array<string, int>>
 */
function tagihan_wajib_tarif_cache_by_tier(
    PDO $pdo,
    int $bulanTagihan = 0,
    int $tahunAjaranMulai = 0,
    int $tahunAjaranSelesai = 0
): array {
    static $cache = [];
    $ts = max($tahunAjaranMulai, $tahunAjaranSelesai);
    $key = ($bulanTagihan >= 1 && $tahunAjaranMulai > 0)
        ? $tahunAjaranMulai . ':' . $ts . ':' . $bulanTagihan
        : '_default';
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $sy = keuangan_syahriyah_tarif_cache_by_tier($pdo, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai);
    $makan = [];
    $sakuDefaults = ['muadalah' => 300000, 'wustho' => 300000, 'ulya' => 300000];
    $saku = [];
    foreach (['muadalah', 'wustho', 'ulya'] as $tier) {
        $makan[$tier] = keuangan_tarif_bulanan_resolve($pdo, 'makan', $tier, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai);
        $saku[$tier] = (int) app_setting($pdo, 'keuangan_fee_saku_' . $tier, (string) ($sakuDefaults[$tier] ?? 0));
    }

    $cache[$key] = ['syahriyah' => $sy, 'makan' => $makan, 'saku' => $saku];

    return $cache[$key];
}

/**
 * @param list<string> $slugs
 * @return array<int, array<string, int>>
 */
function tagihan_paid_map_for_month(
    PDO $pdo,
    int $bulanTagihan,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    array $slugs
): array {
    $slugs = array_values(array_unique(array_filter(array_map('strval', $slugs))));
    if (
        $bulanTagihan < 1
        || $bulanTagihan > 12
        || $slugs === []
        || !table_exists($pdo, 'keuangan_pembayaran')
        || !table_exists($pdo, 'keuangan_pembayaran_detail')
    ) {
        return [];
    }

    $bulanMatch = pondok_sql_match_bulan_tagihan($pdo, $tahunAjaranMulai, $tahunAjaranSelesai, $bulanTagihan, 'p');
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
    return tagihan_paid_map_for_month(
        $pdo,
        $bulanTagihan,
        $tahunAjaranMulai,
        $tahunAjaranSelesai,
        keuangan_tagihan_wajib_slugs()
    );
}

/**
 * Status pos opsional (makan, saku) untuk satu santri.
 *
 * @param array<string, int> $paidSantri
 * @return array<string, array{expected:int,paid:int,sisa:int,status:string,statusClass:string}>
 */
function ensure_keuangan_santri_opsional_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS keuangan_santri_opsional (
                santri_id INT UNSIGNED NOT NULL,
                slug VARCHAR(20) NOT NULL,
                aktif TINYINT(1) NOT NULL DEFAULT 1,
                nominal INT NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (santri_id, slug),
                KEY idx_slug_aktif (slug, aktif)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $done = true;
    } catch (Throwable $e) {
        $done = true;
    }
}

/**
 * Peta opt-in/override opsional per santri dari tabel keuangan_santri_opsional.
 *
 * @return array<int, array<string, array{aktif:bool, nominal:?int}>>
 */
function keuangan_santri_opsional_map_cached(PDO $pdo): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    ensure_keuangan_santri_opsional_table($pdo);
    $cache = [];
    try {
        $rows = $pdo->query('SELECT santri_id, slug, aktif, nominal FROM keuangan_santri_opsional')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $r) {
            $sid = (int) ($r['santri_id'] ?? 0);
            $slug = strtolower(trim((string) ($r['slug'] ?? '')));
            if ($sid <= 0 || $slug === '') {
                continue;
            }
            $nominal = $r['nominal'] === null ? null : max(0, (int) $r['nominal']);
            $cache[$sid][$slug] = [
                'aktif' => (int) ($r['aktif'] ?? 1) === 1,
                'nominal' => $nominal,
            ];
        }
    } catch (Throwable $e) {
        $cache = [];
    }

    return $cache;
}

function keuangan_santri_opsional_cache_invalidate(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        unset($_SESSION['tagihan_syahriyah_list_v1']);
    }
    if (function_exists('keuangan_dashboard_cache_invalidate')) {
        keuangan_dashboard_cache_invalidate();
    }
}

/**
 * Resolve override opsional per santri (gunakan default tier bila tidak ada entri).
 *
 * @return array{aktif:bool, nominal_override:?int}
 */
function keuangan_santri_opsional_for(PDO $pdo, int $santriId, string $slug): array
{
    $map = keuangan_santri_opsional_map_cached($pdo);
    if (isset($map[$santriId][$slug])) {
        return [
            'aktif' => (bool) $map[$santriId][$slug]['aktif'],
            'nominal_override' => $map[$santriId][$slug]['nominal'],
        ];
    }

    return ['aktif' => true, 'nominal_override' => null];
}

function tagihan_opsional_pos_for_month_bulk(
    PDO $pdo,
    string $kelasKategori,
    array $paidSantri,
    int $santriId = 0,
    int $bulanTagihan = 0,
    int $tahunAjaranMulai = 0,
    int $tahunAjaranSelesai = 0
): array {
    if (
        $santriId > 0
        && $bulanTagihan >= 1
        && !tagihan_bulan_dibebankan($pdo, $santriId, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai)
    ) {
        $perPos = [];
        foreach (keuangan_tagihan_opsional_bulanan_slugs() as $slug) {
            $perPos[$slug] = [
                'expected' => 0,
                'paid' => (int) ($paidSantri[$slug] ?? 0),
                'sisa' => 0,
                'status' => '—',
                'statusClass' => 'secondary',
            ];
        }

        return $perPos;
    }

    $tarif = tagihan_wajib_tarif_cache_by_tier($pdo, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai);
    $tier = keuangan_tier_key_from_kelas($kelasKategori, $pdo);
    $perPos = [];
    $overridesMap = $santriId > 0 ? keuangan_santri_opsional_map_cached($pdo) : [];
    foreach (keuangan_tagihan_opsional_bulanan_slugs() as $slug) {
        if ($slug === 'makan') {
            if (!function_exists('keuangan_makan_nominal_for_kelas')) {
                require_once __DIR__ . '/keuangan_kelas_makan.php';
            }
            $expected = keuangan_makan_nominal_for_kelas(
                $pdo,
                $kelasKategori,
                $bulanTagihan,
                $tahunAjaranMulai,
                $tahunAjaranSelesai
            );
        } else {
            $expected = max(0, (int) ($tarif[$slug][$tier] ?? 0));
        }
        if ($santriId > 0 && isset($overridesMap[$santriId][$slug])) {
            $ov = $overridesMap[$santriId][$slug];
            if (!$ov['aktif']) {
                $expected = 0;
            } elseif ($ov['nominal'] !== null) {
                $expected = max(0, (int) $ov['nominal']);
            }
        }
        $paid = (int) ($paidSantri[$slug] ?? 0);
        $sisa = max(0, $expected - $paid);
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
            'paid' => $paid,
            'sisa' => $sisa,
            'status' => $st,
            'statusClass' => $stClass,
        ];
    }

    return $perPos;
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
    if (keuangan_tagihan_mulai_masuk_enabled($pdo)) {
        $bulanMulaiMap = (array) ($syCtx['masuk_bulan_mulai'] ?? []);
        $bulanMulai = (int) ($bulanMulaiMap[$santriId] ?? 1);
        if ($bulanTagihan < $bulanMulai) {
            return tagihan_wajib_status_kosong();
        }
    }

    $tarif = tagihan_wajib_tarif_cache_by_tier($pdo, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai);
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

        $pkppsTambahan = 0;
        $expectedSetelahPotongan = $expected;
        if ($slug === 'syahriyah') {
            $pkppsTambahan = (int) ($syPot['pkpps_tambahan'] ?? 0);
            $expectedSetelahPotongan = max(0, $expected - $pkppsTambahan);
        }

        $perPos[$slug] = [
            'expected' => $expected,
            'expected_dasar' => $expectedDasar,
            'expected_setelah_potongan' => $expectedSetelahPotongan,
            'pkpps_tambahan' => $pkppsTambahan,
            'kelas_syahriyah_tambahan' => 0,
            'tier_key' => $slug === 'syahriyah' ? $tier : '',
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
 * Total kekurangan tagihan wajib dari bulan pertama TA s.d. bulan akhir (inklusif).
 *
 * @return array{
 *   expected_total:int,
 *   paid_total:int,
 *   sisa_total:int,
 *   status:string,
 *   statusClass:string,
 *   per_pos:array<string, array<string, mixed>>,
 *   per_bulan:list<array{bulan:int,label:string,sisa_total:int}>,
 *   bulan_akhir:int,
 *   tahun_mulai:int,
 *   tahun_selesai:int
 * }
 */
function tagihan_wajib_status_kumulatif_ta(
    PDO $pdo,
    int $santriId,
    int $bulanAkhir,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    string $kelasKategori
): array {
    $bulanAkhir = max(1, min(12, $bulanAkhir));
    $slots = pondok_bulan_slots_tahun_ajaran($pdo, $tahunAjaranMulai, $tahunAjaranSelesai);
    $components = keuangan_tagihan_wajib_components($pdo, $kelasKategori);

    $perPosAgg = [];
    foreach ($components as $c) {
        $slug = (string) ($c['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        $perPosAgg[$slug] = [
            'expected' => 0,
            'paid' => 0,
            'sisa' => 0,
            'status' => '—',
            'statusClass' => 'secondary',
        ];
    }

    $perBulan = [];
    $sisaTotal = 0;
    $expectedTotal = 0;
    $paidTotal = 0;
    $anyExpected = false;
    $anyPaid = false;
    $allLunas = true;

    foreach ($slots as $slot) {
        $bulan = (int) ($slot['bulan_tagihan'] ?? 0);
        if ($bulan < 1 || $bulan > $bulanAkhir) {
            continue;
        }
        $ctx = tagihan_bulanan_page_context($pdo, $bulan, $tahunAjaranMulai, $tahunAjaranSelesai);
        $st = tagihan_wajib_status_for_month_bulk(
            $pdo,
            $santriId,
            $bulan,
            $tahunAjaranMulai,
            $tahunAjaranSelesai,
            $kelasKategori,
            $ctx['paid_map'],
            $ctx['sy_ctx']
        );
        $bulanSisa = (int) ($st['sisa_total'] ?? 0);
        if ($bulanSisa > 0) {
            $perBulan[] = [
                'bulan' => $bulan,
                'label' => pondok_bulan_slot_label_tampilan($pdo, $slot),
                'sisa_total' => $bulanSisa,
            ];
        }
        foreach ((array) ($st['per_pos'] ?? []) as $slug => $pos) {
            if (!isset($perPosAgg[$slug])) {
                $perPosAgg[$slug] = [
                    'expected' => 0,
                    'paid' => 0,
                    'sisa' => 0,
                    'status' => '—',
                    'statusClass' => 'secondary',
                ];
            }
            $perPosAgg[$slug]['expected'] += (int) ($pos['expected'] ?? 0);
            $perPosAgg[$slug]['paid'] += (int) ($pos['paid'] ?? 0);
            $perPosAgg[$slug]['sisa'] += (int) ($pos['sisa'] ?? 0);
        }
        $sisaTotal += $bulanSisa;
        $expectedTotal += (int) ($st['expected_total'] ?? 0);
        $paidTotal += (int) ($st['paid_total'] ?? 0);
        if ((int) ($st['expected_total'] ?? 0) > 0) {
            $anyExpected = true;
        }
        if ((int) ($st['paid_total'] ?? 0) > 0) {
            $anyPaid = true;
        }
        if ($bulanSisa > 0) {
            $allLunas = false;
        }
    }

    foreach ($perPosAgg as $slug => &$agg) {
        $expected = (int) ($agg['expected'] ?? 0);
        $paid = (int) ($agg['paid'] ?? 0);
        $sisa = (int) ($agg['sisa'] ?? 0);
        if ($expected <= 0) {
            $agg['status'] = '—';
            $agg['statusClass'] = 'secondary';
        } elseif ($paid >= $expected) {
            $agg['status'] = 'Lunas';
            $agg['statusClass'] = 'success';
        } elseif ($paid <= 0) {
            $agg['status'] = 'Belum';
            $agg['statusClass'] = 'danger';
        } else {
            $agg['status'] = 'Sebagian';
            $agg['statusClass'] = 'warning';
        }
        $agg['sisa'] = $sisa;
    }
    unset($agg);

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
        'per_pos' => $perPosAgg,
        'per_bulan' => $perBulan,
        'bulan_akhir' => $bulanAkhir,
        'tahun_mulai' => $tahunAjaranMulai,
        'tahun_selesai' => $tahunAjaranSelesai,
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
    if (keuangan_tagihan_mulai_masuk_enabled($pdo)) {
        $santriRows = tagihan_santri_aktif_rows_cached($pdo, false);
        $masukMaps = tagihan_santri_masuk_maps_build($pdo, $tahunAjaranMulai, $tahunAjaranSelesai, $santriRows);
        $syCtx['masuk_bulan_mulai'] = $masukMaps['bulan_mulai'];
        $syCtx['masuk_jenis'] = $masukMaps['jenis'];
    }

    $pageCache[$key] = [
        'sy_ctx' => $syCtx,
        'paid_map' => tagihan_paid_map_for_month(
            $pdo,
            $bulanTagihan,
            $tahunAjaranMulai,
            $tahunAjaranSelesai,
            keuangan_tagihan_bulanan_slugs()
        ),
        'kelas_labels' => kelas_keuangan_label_map($pdo),
        'tingkatan_map' => santri_tingkatan_map_for_ta($pdo, $tahunAjaranMulai, $tahunAjaranSelesai),
    ];

    return $pageCache[$key];
}

/**
 * Total tagihan syahriyah semua santri untuk satu bulan (tarif per bulan + potongan).
 *
 * @param list<array<string, mixed>> $santriRows
 */
function tagihan_wajib_expected_all_santri_for_month(
    PDO $pdo,
    array $santriRows,
    int $bulanTagihan,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai
): int {
    if ($bulanTagihan < 1 || $bulanTagihan > 12 || $santriRows === [] || $tahunAjaranMulai <= 0) {
        return 0;
    }

    $syCtx = keuangan_syahriyah_bulk_context($pdo, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai);
    $total = 0;
    foreach ($santriRows as $s) {
        $sid = (int) ($s['id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $kat = trim((string) ($s['kategori_kelas'] ?? ''));
        if ($kat === '' && !empty($s['tingkatan'])) {
            $kat = (string) $s['tingkatan'];
        }
        $syPot = keuangan_syahriyah_simulasi(
            $pdo,
            $sid,
            $kat,
            $bulanTagihan,
            $tahunAjaranMulai,
            $tahunAjaranSelesai,
            $syCtx
        );
        $total += max(0, (int) ($syPot['expected'] ?? 0));
    }

    return $total;
}

/**
 * Total tagihan wajib (syahriyah) semua santri aktif — selaras daftar tagihan bulanan.
 *
 * @param list<array<string, mixed>> $santriRows
 */
function tagihan_wajib_total_expected_all_santri(
    PDO $pdo,
    array $santriRows,
    int $bulanTagihan = 0,
    int $tahunAjaranMulai = 0,
    int $tahunAjaranSelesai = 0
): int {
    if ($bulanTagihan >= 1 && $bulanTagihan <= 12 && $tahunAjaranMulai > 0) {
        return tagihan_wajib_expected_all_santri_for_month($pdo, $santriRows, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai);
    }

    $tarif = tagihan_wajib_tarif_cache_by_tier($pdo);
    $total = 0;
    foreach ($santriRows as $s) {
        $kat = trim((string) ($s['kategori_kelas'] ?? ''));
        if ($kat === '' && !empty($s['tingkatan'])) {
            $kat = (string) $s['tingkatan'];
        }
        $tier = keuangan_tier_key_from_kelas($kat, $pdo);
        $total += (int) ($tarif['syahriyah'][$tier] ?? 0);
    }

    return $total;
}

/**
 * Hitung expected + terbayar syahriyah per bulan (satu pass konteks per bulan).
 *
 * @param list<int> $bulanList
 * @param list<array<string, mixed>> $bulanSlots
 * @return array{expected_by_month: array<int, int>, paid_by_month: array<int, int>, tables_ok: bool}
 */
function tagihan_laporan_12bulan_compute(
    PDO $pdo,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    array $bulanSlots,
    array $bulanList,
    array $santriRows
): array {
    $expectedByMonth = array_fill(1, 12, 0);
    $paidByMonth = array_fill(1, 12, 0);
    $tablesOk = table_exists($pdo, 'keuangan_pembayaran') && table_exists($pdo, 'keuangan_pembayaran_detail');

    if (!$tablesOk || $tahunAjaranMulai <= 0 || $bulanList === []) {
        return [
            'expected_by_month' => $expectedByMonth,
            'paid_by_month' => $paidByMonth,
            'tables_ok' => $tablesOk,
        ];
    }

    if ($santriRows !== []) {
        if (!function_exists('keuangan_syahriyah_bulk_context')) {
            require_once __DIR__ . '/keuangan_syahriyah_potongan.php';
        }
        $ctxByMonth = [];
        foreach ($bulanList as $b) {
            $ctxByMonth[$b] = keuangan_syahriyah_bulk_context($pdo, $b, $tahunAjaranMulai, $tahunAjaranSelesai);
        }
        foreach ($santriRows as $s) {
            $sid = (int) ($s['id'] ?? 0);
            if ($sid <= 0) {
                continue;
            }
            $kat = trim((string) ($s['kategori_kelas'] ?? ''));
            if ($kat === '' && !empty($s['tingkatan'])) {
                $kat = (string) $s['tingkatan'];
            }
            foreach ($bulanList as $b) {
                $syPot = keuangan_syahriyah_simulasi(
                    $pdo,
                    $sid,
                    $kat,
                    $b,
                    $tahunAjaranMulai,
                    $tahunAjaranSelesai,
                    $ctxByMonth[$b]
                );
                $expectedByMonth[$b] += max(0, (int) ($syPot['expected'] ?? 0));
            }
        }
    }

    if (!function_exists('pondok_sql_match_bulan_tagihan')) {
        require_once __DIR__ . '/pondok_kalender.php';
    }
    foreach ($bulanSlots as $slot) {
        $b = (int) ($slot['bulan_tagihan'] ?? 0);
        if ($b < 1 || $b > 12) {
            continue;
        }
        $bulanMatch = pondok_sql_match_bulan_tagihan($pdo, $tahunAjaranMulai, $tahunAjaranSelesai, $b, 'p');
        $st = $pdo->prepare('
            SELECT COALESCE(SUM(d.nominal), 0) AS total
            FROM keuangan_pembayaran_detail d
            INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
            WHERE p.jenis_periode = \'BULANAN\'
              AND p.tahun_ajaran_mulai = :tm
              AND p.tahun_ajaran_selesai = :ts
              AND LOWER(TRIM(d.pos_slug)) = \'syahriyah\'
              AND ' . $bulanMatch['sql'] . '
        ');
        $st->execute(array_merge(['tm' => $tahunAjaranMulai, 'ts' => $tahunAjaranSelesai], $bulanMatch['params']));
        $paidByMonth[$b] = (int) ((float) ($st->fetchColumn() ?: 0));
    }

    return [
        'expected_by_month' => $expectedByMonth,
        'paid_by_month' => $paidByMonth,
        'tables_ok' => true,
    ];
}

/**
 * Ringkas tagihan syahriyah 12 bulan TA — cache sesi (halaman Laporan Syahriyah).
 *
 * @param list<array<string, mixed>> $bulanSlots
 * @return array{expected_by_month: array<int, int>, paid_by_month: array<int, int>, tables_ok: bool}
 */
function tagihan_laporan_12bulan_cached(
    PDO $pdo,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    array $bulanSlots,
    int $ttlSec = 600
): array {
    $empty = [
        'expected_by_month' => array_fill(1, 12, 0),
        'paid_by_month' => array_fill(1, 12, 0),
        'tables_ok' => false,
    ];
    if ($tahunAjaranMulai <= 0) {
        return $empty;
    }

    $bulanList = [];
    foreach ($bulanSlots as $slot) {
        $b = (int) ($slot['bulan_tagihan'] ?? 0);
        if ($b >= 1 && $b <= 12) {
            $bulanList[$b] = true;
        }
    }
    $bulanList = array_map('intval', array_keys($bulanList));
    sort($bulanList);
    if ($bulanList === []) {
        return $empty;
    }

    if (!function_exists('pondok_ta_bulan_awal')) {
        require_once __DIR__ . '/pondok_ta.php';
    }
    $cacheKey = 'v1:' . $tahunAjaranMulai . ':' . $tahunAjaranSelesai . ':'
        . implode(',', $bulanList) . ':'
        . (pondok_kalender_hijriyah($pdo) ? 'h' : 'm') . ':'
        . pondok_ta_bulan_awal($pdo);

    if (isset($_SESSION['user'])) {
        $bucket = $_SESSION['tagihan_laporan_12bulan_v1'] ?? null;
        if (is_array($bucket)) {
            $entry = $bucket[$cacheKey] ?? null;
            if (is_array($entry) && (int) ($entry['expires'] ?? 0) > time() && is_array($entry['data'] ?? null)) {
                return $entry['data'];
            }
        }
    }

    $sqlSantri = 'SELECT id, tingkatan, kategori_kelas FROM santri';
    if (column_exists($pdo, 'santri', 'is_aktif')) {
        $sqlSantri .= ' WHERE COALESCE(is_aktif, 1) = 1';
    }
    $santriRows = table_exists($pdo, 'santri') ? ($pdo->query($sqlSantri)->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $data = tagihan_laporan_12bulan_compute(
        $pdo,
        $tahunAjaranMulai,
        $tahunAjaranSelesai,
        $bulanSlots,
        $bulanList,
        $santriRows
    );

    if (isset($_SESSION['user'])) {
        if (!isset($_SESSION['tagihan_laporan_12bulan_v1']) || !is_array($_SESSION['tagihan_laporan_12bulan_v1'])) {
            $_SESSION['tagihan_laporan_12bulan_v1'] = [];
        }
        $bucket = $_SESSION['tagihan_laporan_12bulan_v1'];
        $bucket[$cacheKey] = [
            'expires' => time() + max(60, $ttlSec),
            'data' => $data,
        ];
        if (count($bucket) > 4) {
            uasort($bucket, static fn (array $a, array $b): int => (int) ($b['expires'] ?? 0) <=> (int) ($a['expires'] ?? 0));
            $bucket = array_slice($bucket, 0, 4, true);
        }
        $_SESSION['tagihan_laporan_12bulan_v1'] = $bucket;
    }

    return $data;
}

function tagihan_syahriyah_cache_invalidate(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        unset($_SESSION['tagihan_syahriyah_list_v1'], $_SESSION['tagihan_laporan_12bulan_v1']);
    }
}

/**
 * Hitung daftar tagihan bulanan semua santri aktif (tanpa filter pencarian).
 *
 * @return array{
 *   body: list<array<string, mixed>>,
 *   sum_tagihan: int,
 *   sum_bayar: int,
 *   count_lunas: int,
 *   count_belum: int,
 *   count_sebagian: int,
 *   tables_ok: bool
 * }
 */
/**
 * Cache daftar santri aktif untuk perhitungan keuangan (per request).
 *
 * @return list<array<string, mixed>>
 */
function tagihan_santri_aktif_rows_cached(PDO $pdo, bool $withWa = false): array
{
    static $cache = [];
    $key = $withWa ? 'wa' : 'plain';
    if (isset($cache[$key])) {
        return $cache[$key];
    }
    $cols = ['id', 'nis', 'nama_santri', 'tingkatan', 'kategori_kelas'];
    if (column_exists($pdo, 'santri', 'tanggal_masuk')) {
        $cols[] = 'tanggal_masuk';
    }
    if ($withWa) {
        if (column_exists($pdo, 'santri', 'no_wa_wali')) {
            $cols[] = 'no_wa_wali';
        }
        if (column_exists($pdo, 'santri', 'wali_santri_id')) {
            $cols[] = 'wali_santri_id';
        }
        if (column_exists($pdo, 'santri', 'no_kontak_ayah')) {
            $cols[] = 'no_kontak_ayah';
        }
        if (column_exists($pdo, 'santri', 'no_kontak_ibu')) {
            $cols[] = 'no_kontak_ibu';
        }
    }
    $sql = 'SELECT ' . implode(', ', $cols) . ' FROM santri';
    if (column_exists($pdo, 'santri', 'is_aktif')) {
        $sql .= ' WHERE COALESCE(is_aktif, 1) = 1';
    }
    $sql .= ' ORDER BY ' . santri_list_order_sql('santri');
    $cache[$key] = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    return $cache[$key];
}

function tagihan_syahriyah_list_compute(PDO $pdo, int $bulanTagihan, int $tahunAjaranMulai, int $tahunAjaranSelesai, string $sortMode): array
{
    keuangan_pkpps_syahriyah_warm_kelas_cache($pdo);
    keuangan_pkpps_syahriyah_warm_aktif_cache($pdo);
    $tablesOk = table_exists($pdo, 'keuangan_pembayaran') && table_exists($pdo, 'keuangan_pembayaran_detail');
    $tagihanCtx = $tablesOk
        ? tagihan_bulanan_page_context($pdo, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai)
        : ['sy_ctx' => [], 'paid_map' => [], 'tingkatan_map' => []];
    $syCtx = $tagihanCtx['sy_ctx'];
    $paidMap = $tagihanCtx['paid_map'];
    $tingkatanMap = $tagihanCtx['tingkatan_map'];

    $rows = $tablesOk ? tagihan_santri_aktif_rows_cached($pdo, true) : [];

    $body = [];
    $sumTagihan = 0;
    $sumBayar = 0;
    $countLunas = 0;
    $countBelum = 0;
    $countSebagian = 0;
    $tierByKelas = [];

    foreach ($rows as $s) {
        $kelasKategori = santri_kelas_untuk_ta(
            $pdo,
            (int) $s['id'],
            $tahunAjaranMulai,
            $tahunAjaranSelesai,
            $s,
            $tingkatanMap
        );
        $st = $tablesOk
            ? tagihan_wajib_status_for_month_bulk(
                $pdo,
                (int) $s['id'],
                $bulanTagihan,
                $tahunAjaranMulai,
                $tahunAjaranSelesai,
                $kelasKategori,
                $paidMap,
                $syCtx
            )
            : [
                'expected_total' => 0,
                'paid_total' => 0,
                'sisa_total' => 0,
                'status' => '—',
                'statusClass' => 'secondary',
                'per_pos' => [],
            ];
        $expected = (int) ($st['expected_total'] ?? 0);
        $paid = (int) ($st['paid_total'] ?? 0);
        $sisa = (int) ($st['sisa_total'] ?? 0);
        $status = (string) ($st['status'] ?? '—');
        $statusClass = (string) ($st['statusClass'] ?? 'secondary');
        if ($status === 'Lunas') {
            $countLunas++;
        } elseif ($status === 'Belum') {
            $countBelum++;
        } elseif ($status === 'Sebagian') {
            $countSebagian++;
        }
        $sumTagihan += $expected;
        $sumBayar += min($paid, $expected);

        $perPos = (array) ($st['per_pos'] ?? []);
        $paidSantri = $paidMap[(int) $s['id']] ?? [];
        $opsPos = $tablesOk
            ? tagihan_opsional_pos_for_month_bulk(
                $pdo,
                $kelasKategori,
                $paidSantri,
                (int) $s['id'],
                $bulanTagihan,
                $tahunAjaranMulai,
                $tahunAjaranSelesai
            )
            : [];
        if (!array_key_exists($kelasKategori, $tierByKelas)) {
            $tierByKelas[$kelasKategori] = keuangan_tier_key_from_kelas($kelasKategori, $pdo);
        }
        $body[] = [
            'id' => (int) $s['id'],
            'nis' => (string) ($s['nis'] ?? ''),
            'nama' => (string) ($s['nama_santri'] ?? ''),
            'no_wa_wali' => santri_resolve_no_wa_wali($pdo, $s),
            'tingkatan' => trim((string) ($s['tingkatan'] ?? '')),
            'kategori' => trim((string) ($s['kategori_kelas'] ?? '')),
            'tier' => $tierByKelas[$kelasKategori],
            'tagihan' => $expected,
            'bayar' => $paid,
            'sisa' => $sisa,
            'status' => $status,
            'statusClass' => $statusClass,
            'sy_expected' => (int) (($perPos['syahriyah']['expected'] ?? 0)),
            'sy_dasar' => (int) (($perPos['syahriyah']['expected_dasar'] ?? $perPos['syahriyah']['expected'] ?? 0)),
            'sy_persen' => (float) (($perPos['syahriyah']['persen_potongan'] ?? 0)),
            'sy_ket_potongan' => (string) (($perPos['syahriyah']['keterangan_potongan'] ?? '')),
            'sy_dijeda' => !empty($perPos['syahriyah']['potongan_dijeda']),
            'sy_paid' => (int) (($perPos['syahriyah']['paid'] ?? 0)),
            'sy_sisa' => (int) (($perPos['syahriyah']['sisa'] ?? 0)),
            'sy_pkpps' => (int) (($perPos['syahriyah']['pkpps_tambahan'] ?? 0)),
            'mk_expected' => (int) (($opsPos['makan']['expected'] ?? 0)),
            'mk_paid' => (int) (($opsPos['makan']['paid'] ?? 0)),
            'mk_sisa' => (int) (($opsPos['makan']['sisa'] ?? 0)),
            'sk_expected' => (int) (($opsPos['saku']['expected'] ?? 0)),
            'sk_paid' => (int) (($opsPos['saku']['paid'] ?? 0)),
            'sk_sisa' => (int) (($opsPos['saku']['sisa'] ?? 0)),
        ];
    }

    $body = santri_list_sort_rows($body, $sortMode);

    return [
        'body' => $body,
        'sum_tagihan' => $sumTagihan,
        'sum_bayar' => $sumBayar,
        'count_lunas' => $countLunas,
        'count_belum' => $countBelum,
        'count_sebagian' => $countSebagian,
        'tables_ok' => $tablesOk,
    ];
}

/**
 * Cache hasil tagihan bulanan per TA + bulan (pergantian dropdown cepat).
 *
 * @return array{
 *   body: list<array<string, mixed>>,
 *   sum_tagihan: int,
 *   sum_bayar: int,
 *   count_lunas: int,
 *   count_belum: int,
 *   count_sebagian: int,
 *   tables_ok: bool
 * }
 */
function tagihan_syahriyah_list_cached(
    PDO $pdo,
    int $bulanTagihan,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    string $sortMode,
    int $ttlSec = 600
): array {
    static $requestCache = [];
    if (!function_exists('pondok_ta_bulan_awal')) {
        require_once __DIR__ . '/pondok_ta.php';
    }
    $cacheKey = 'v2:' . $bulanTagihan . ':' . $tahunAjaranMulai . ':' . $tahunAjaranSelesai . ':'
        . $sortMode . ':' . (pondok_kalender_hijriyah($pdo) ? 'h' : 'm') . ':' . pondok_ta_bulan_awal($pdo);
    if (isset($requestCache[$cacheKey])) {
        return $requestCache[$cacheKey];
    }

    if (isset($_SESSION['user'])) {
        $bucket = $_SESSION['tagihan_syahriyah_list_v1'] ?? null;
        if (is_array($bucket)) {
            $entry = $bucket[$cacheKey] ?? null;
            if (is_array($entry) && (int) ($entry['expires'] ?? 0) > time() && is_array($entry['data'] ?? null)) {
                $requestCache[$cacheKey] = $entry['data'];

                return $requestCache[$cacheKey];
            }
        }
    }

    $data = tagihan_syahriyah_list_compute($pdo, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai, $sortMode);
    $requestCache[$cacheKey] = $data;
    if (isset($_SESSION['user'])) {
        if (!isset($_SESSION['tagihan_syahriyah_list_v1']) || !is_array($_SESSION['tagihan_syahriyah_list_v1'])) {
            $_SESSION['tagihan_syahriyah_list_v1'] = [];
        }
        $bucket = $_SESSION['tagihan_syahriyah_list_v1'];
        $bucket[$cacheKey] = [
            'expires' => time() + max(60, $ttlSec),
            'data' => $data,
        ];
        if (count($bucket) > 6) {
            uasort($bucket, static fn(array $a, array $b): int => (int) ($b['expires'] ?? 0) <=> (int) ($a['expires'] ?? 0));
            $bucket = array_slice($bucket, 0, 6, true);
        }
        $_SESSION['tagihan_syahriyah_list_v1'] = $bucket;
    }

    return $data;
}
