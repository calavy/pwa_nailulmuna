<?php

declare(strict_types=1);

require_once __DIR__ . '/keuangan_alokasi.php';
require_once __DIR__ . '/santri_list_sort.php';

/**
 * Perhitungan target dana masuk per pos (rekap) dari santri aktif × tarif pengaturan.
 *
 * @return list<array{pos_slug:string,pos_nama:string,expected:int,paid:int}>
 */
function keuangan_rekap_pos_with_expected(
    PDO $pdo,
    string $jenisPeriode,
    int $bulanTagihan,
    int $tahunMulai,
    int $tahunSelesai,
    array $biayaDefinitions
): array {
    if (!table_exists($pdo, 'santri')) {
        return [];
    }

    $paidBySlug = [];
    if (table_exists($pdo, 'keuangan_pembayaran') && table_exists($pdo, 'keuangan_pembayaran_detail')) {
        $bulanSql = '1=1';
        $bulanParams = [];
        if ($jenisPeriode === 'BULANAN' && $bulanTagihan > 0) {
            if (!function_exists('pondok_sql_match_bulan_tagihan')) {
                require_once __DIR__ . '/pondok_kalender.php';
            }
            $bulanMatch = pondok_sql_match_bulan_tagihan($pdo, $tahunMulai, $tahunSelesai, $bulanTagihan, 'p');
            $bulanSql = $bulanMatch['sql'];
            $bulanParams = $bulanMatch['params'];
        }
        $paidStmt = $pdo->prepare('
            SELECT d.pos_slug, d.pos_nama, COALESCE(SUM(d.nominal), 0) AS total_nominal
            FROM keuangan_pembayaran p
            INNER JOIN keuangan_pembayaran_detail d ON d.pembayaran_id = p.id
            WHERE p.jenis_periode = :jenis_periode
              AND p.tahun_ajaran_mulai = :tahun_mulai
              AND p.tahun_ajaran_selesai = :tahun_selesai
              AND (' . ($jenisPeriode === 'BULANAN' && $bulanTagihan > 0 ? $bulanSql : '1=1') . ')
            GROUP BY d.pos_slug, d.pos_nama
        ');
        $paidStmt->execute(array_merge([
            'jenis_periode' => $jenisPeriode,
            'tahun_mulai' => $tahunMulai,
            'tahun_selesai' => $tahunSelesai,
        ], $bulanParams));
        foreach ($paidStmt->fetchAll(PDO::FETCH_ASSOC) as $pr) {
            $slug = strtolower(trim((string) ($pr['pos_slug'] ?? '')));
            if ($slug === '') {
                continue;
            }
            $paidBySlug[$slug] = [
                'nama' => (string) ($pr['pos_nama'] ?? $slug),
                'paid' => (int) ((float) ($pr['total_nominal'] ?? 0)),
            ];
        }
    }

    $expectedBySlug = [];
    $kategoriFilter = $jenisPeriode === 'BULANAN' ? 'Bulanan' : 'Awal Tahun';
    foreach ($biayaDefinitions as $def) {
        if (($def['kategori'] ?? '') !== $kategoriFilter) {
            continue;
        }
        $slug = strtolower(trim((string) ($def['slug'] ?? '')));
        if ($slug === '') {
            continue;
        }
        $expectedBySlug[$slug] = [
            'nama' => (string) ($def['nama'] ?? $slug),
            'expected' => 0,
        ];
    }

    $aktifSql = santri_sql_aktif_only('s');
    $levelExpr = column_exists($pdo, 'santri', 'kategori_kelas') ? 's.kategori_kelas' : (column_exists($pdo, 'santri', 'tingkatan') ? 's.tingkatan' : "''");
    $santriRows = $pdo->query('SELECT s.id, ' . $levelExpr . ' AS kategori_kelas, s.nis, s.nama_santri, s.tingkatan FROM santri s WHERE ' . $aktifSql . ' ORDER BY ' . santri_list_order_sql('s'))->fetchAll(PDO::FETCH_ASSOC);

    $useSyahriyahPotongan = $jenisPeriode === 'BULANAN'
        && isset($expectedBySlug['syahriyah']);
    $syCtx = null;
    $tarifWajib = null;
    if ($useSyahriyahPotongan) {
        if (!function_exists('keuangan_syahriyah_bulk_context')) {
            require_once __DIR__ . '/keuangan_syahriyah_potongan.php';
        }
        $syCtx = keuangan_syahriyah_bulk_context($pdo, $bulanTagihan, $tahunMulai, $tahunSelesai);
        if (!function_exists('tagihan_wajib_tarif_cache_by_tier')) {
            require_once __DIR__ . '/tagihan_bulanan.php';
        }
        $tarifWajib = tagihan_wajib_tarif_cache_by_tier($pdo, $bulanTagihan, $tahunMulai, $tahunSelesai);
    }

    foreach ($santriRows as $sr) {
        $sid = (int) ($sr['id'] ?? 0);
        $kat = trim((string) ($sr['kategori_kelas'] ?? ''));
        $tier = keuangan_tier_key_from_kelas($kat, $pdo);
        foreach ($biayaDefinitions as $def) {
            if (($def['kategori'] ?? '') !== $kategoriFilter) {
                continue;
            }
            $slug = strtolower(trim((string) ($def['slug'] ?? '')));
            if ($slug === '' || !isset($expectedBySlug[$slug])) {
                continue;
            }
            if ($useSyahriyahPotongan && $slug === 'syahriyah' && $sid > 0 && is_array($syCtx)) {
                $syPot = keuangan_syahriyah_simulasi(
                    $pdo,
                    $sid,
                    $kat,
                    $bulanTagihan,
                    $tahunMulai,
                    $tahunSelesai,
                    $syCtx
                );
                $expectedBySlug[$slug]['expected'] += max(0, (int) ($syPot['expected'] ?? 0));
                continue;
            }
            if ($jenisPeriode === 'BULANAN' && is_array($tarifWajib) && isset($tarifWajib[$slug][$tier])) {
                $expectedBySlug[$slug]['expected'] += max(0, (int) $tarifWajib[$slug][$tier]);
                continue;
            }
            $fallback = (int) ($def['default'][$tier] ?? 0);
            $fee = (int) app_setting($pdo, 'keuangan_fee_' . $slug . '_' . $tier, (string) $fallback);
            $expectedBySlug[$slug]['expected'] += max(0, $fee);
        }
    }

    $allSlugs = array_unique(array_merge(array_keys($expectedBySlug), array_keys($paidBySlug)));
    sort($allSlugs);
    $rows = [];
    foreach ($allSlugs as $slug) {
        $rows[] = [
            'pos_slug' => $slug,
            'pos_nama' => (string) ($expectedBySlug[$slug]['nama'] ?? $paidBySlug[$slug]['nama'] ?? $slug),
            'expected' => (int) ($expectedBySlug[$slug]['expected'] ?? 0),
            'paid' => (int) ($paidBySlug[$slug]['paid'] ?? 0),
        ];
    }

    return $rows;
}

/**
 * Rekap POS + expected dengan cache sesi (halaman laporan syahriyah).
 *
 * @return list<array{pos_slug:string,pos_nama:string,expected:int,paid:int}>
 */
function keuangan_rekap_pos_with_expected_cached(
    PDO $pdo,
    string $jenisPeriode,
    int $bulanTagihan,
    int $tahunMulai,
    int $tahunSelesai,
    array $biayaDefinitions,
    int $ttlSec = 600
): array {
    if (!function_exists('pondok_kalender_hijriyah')) {
        require_once __DIR__ . '/pondok_kalender.php';
    }
    $cacheKey = 'pos_' . $jenisPeriode . '_' . $bulanTagihan . '_' . $tahunMulai . '_' . $tahunSelesai
        . (pondok_kalender_hijriyah($pdo) ? '_h' : '_m');
    if (isset($_SESSION['user'])) {
        $bucket = $_SESSION['keuangan_rekap_pos_cache_v1'] ?? null;
        if (is_array($bucket)) {
            $entry = $bucket[$cacheKey] ?? null;
            if (is_array($entry) && (int) ($entry['expires'] ?? 0) > time() && is_array($entry['data'] ?? null)) {
                return $entry['data'];
            }
        }
    }
    $data = keuangan_rekap_pos_with_expected(
        $pdo,
        $jenisPeriode,
        $bulanTagihan,
        $tahunMulai,
        $tahunSelesai,
        $biayaDefinitions
    );
    if (isset($_SESSION['user'])) {
        if (!isset($_SESSION['keuangan_rekap_pos_cache_v1']) || !is_array($_SESSION['keuangan_rekap_pos_cache_v1'])) {
            $_SESSION['keuangan_rekap_pos_cache_v1'] = [];
        }
        $bucket = $_SESSION['keuangan_rekap_pos_cache_v1'];
        $bucket[$cacheKey] = ['expires' => time() + max(60, $ttlSec), 'data' => $data];
        if (count($bucket) > 8) {
            uasort($bucket, static fn (array $a, array $b): int => (int) ($b['expires'] ?? 0) <=> (int) ($a['expires'] ?? 0));
            $bucket = array_slice($bucket, 0, 8, true);
        }
        $_SESSION['keuangan_rekap_pos_cache_v1'] = $bucket;
    }

    return $data;
}

/**
 * @return array<string, int> slug => sudah dibayar
 */
function keuangan_paid_pos_map_for_santri_month(
    PDO $pdo,
    int $santriId,
    string $jenisPeriode,
    int $bulanTagihan,
    int $tahunMulai,
    int $tahunSelesai
): array {
    if ($santriId <= 0 || !table_exists($pdo, 'keuangan_pembayaran') || !table_exists($pdo, 'keuangan_pembayaran_detail')) {
        return [];
    }

    $params = [
        'sid' => $santriId,
        'jenis' => $jenisPeriode,
        'tm' => $tahunMulai,
        'ts' => $tahunSelesai,
    ];
    if ($jenisPeriode === 'BULANAN' && $bulanTagihan >= 1 && $bulanTagihan <= 12) {
        if (!function_exists('pondok_sql_match_bulan_tagihan')) {
            require_once __DIR__ . '/pondok_kalender.php';
        }
        $bulanMatch = pondok_sql_match_bulan_tagihan($pdo, $tahunMulai, $tahunSelesai, $bulanTagihan, 'p');
        $bulanSql = '(' . $bulanMatch['sql'] . ')';
        $params = array_merge($params, $bulanMatch['params']);
    } else {
        $bulanSql = '(:bulan = 0 OR p.bulan_tagihan = :bulan)';
        $params['bulan'] = $jenisPeriode === 'BULANAN' ? max(1, min(12, $bulanTagihan)) : 0;
    }

    $stmt = $pdo->prepare('
        SELECT LOWER(TRIM(d.pos_slug)) AS pos_slug, COALESCE(SUM(d.nominal), 0) AS total
        FROM keuangan_pembayaran p
        INNER JOIN keuangan_pembayaran_detail d ON d.pembayaran_id = p.id
        WHERE p.santri_id = :sid
          AND p.jenis_periode = :jenis
          AND p.tahun_ajaran_mulai = :tm
          AND p.tahun_ajaran_selesai = :ts
          AND ' . $bulanSql . '
        GROUP BY d.pos_slug
    ');
    $stmt->execute($params);
    $map = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $map[(string) $r['pos_slug']] = (int) ((float) ($r['total'] ?? 0));
    }

    return $map;
}

/**
 * Rincian tagihan per pos untuk form pembayaran (sinkron cicilan ↔ tagihan).
 *
 * @return array<string, array{expected:int,paid:int,sisa:int,status:string}>
 */
function keuangan_tagihan_breakdown_for_santri(
    PDO $pdo,
    int $santriId,
    string $jenisPeriode,
    int $bulanTagihan,
    int $tahunMulai,
    int $tahunSelesai,
    array $biayaDefinitions
): array {
    if ($santriId <= 0) {
        return [];
    }

    $kat = '';
    if (table_exists($pdo, 'santri')) {
        $cols = ['id'];
        if (column_exists($pdo, 'santri', 'kategori_kelas')) {
            $cols[] = 'kategori_kelas';
        }
        if (column_exists($pdo, 'santri', 'tingkatan')) {
            $cols[] = 'tingkatan';
        }
        $stmt = $pdo->prepare('SELECT ' . implode(', ', $cols) . ' FROM santri WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $santriId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row)) {
            $kat = trim((string) ($row['kategori_kelas'] ?? $row['tingkatan'] ?? ''));
        }
    }

    $tier = keuangan_tier_key_from_kelas($kat, $pdo);
    $paidMap = keuangan_paid_pos_map_for_santri_month($pdo, $santriId, $jenisPeriode, $bulanTagihan, $tahunMulai, $tahunSelesai);
    $kategoriFilter = $jenisPeriode === 'BULANAN' ? 'Bulanan' : 'Awal Tahun';
    $wajibSlugs = $jenisPeriode === 'BULANAN' ? keuangan_tagihan_wajib_slugs() : [];
    $perPosWajib = [];
    if ($jenisPeriode === 'BULANAN' && $bulanTagihan >= 1 && $bulanTagihan <= 12) {
        $st = tagihan_wajib_status_for_month($pdo, $santriId, $bulanTagihan, $tahunMulai, $tahunSelesai, $kat);
        $perPosWajib = is_array($st['per_pos'] ?? null) ? $st['per_pos'] : [];
    }

    $out = [];
    foreach ($biayaDefinitions as $def) {
        if (($def['kategori'] ?? '') !== $kategoriFilter) {
            continue;
        }
        $slug = (string) ($def['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        if (isset($perPosWajib[$slug])) {
            $expected = (int) ($perPosWajib[$slug]['expected'] ?? 0);
            $paid = (int) ($perPosWajib[$slug]['paid'] ?? 0);
            $sisa = (int) ($perPosWajib[$slug]['sisa'] ?? 0);
            $status = (string) ($perPosWajib[$slug]['status'] ?? '—');
        } else {
            $expected = keuangan_fee_nominal_for_tier($pdo, $def, $tier);
            if (
                $jenisPeriode === 'BULANAN'
                && $bulanTagihan >= 1
                && $bulanTagihan <= 12
                && $tahunMulai > 0
            ) {
                if (!function_exists('keuangan_tarif_bulanan_resolve')) {
                    require_once __DIR__ . '/keuangan_tarif_bulanan.php';
                }
                $slugLower = strtolower($slug);
                if (in_array($slugLower, keuangan_tarif_bulanan_pos_slugs(), true)) {
                    $expected = keuangan_tarif_bulanan_resolve(
                        $pdo,
                        $slugLower,
                        $tier,
                        $bulanTagihan,
                        $tahunMulai,
                        $tahunSelesai
                    );
                }
            }
            $expectedDefault = $expected;
            $overrideAktif = true;
            $overrideNominal = null;
            $isOpsionalBulanan = $jenisPeriode === 'BULANAN'
                && in_array($slug, keuangan_tagihan_opsional_bulanan_slugs(), true);
            if ($isOpsionalBulanan && function_exists('keuangan_santri_opsional_for')) {
                $ov = keuangan_santri_opsional_for($pdo, $santriId, $slug);
                $overrideAktif = (bool) $ov['aktif'];
                $overrideNominal = $ov['nominal_override'];
                if (!$overrideAktif) {
                    $expected = 0;
                } elseif ($overrideNominal !== null) {
                    $expected = max(0, (int) $overrideNominal);
                }
            }
            $paid = (int) ($paidMap[$slug] ?? 0);
            $sisa = max(0, $expected - $paid);
            if ($expected <= 0) {
                $status = '—';
            } elseif ($paid >= $expected) {
                $status = 'Lunas';
            } elseif ($paid <= 0) {
                $status = 'Belum';
            } else {
                $status = 'Sebagian';
            }
        }
        $row = [
            'expected' => $expected,
            'paid' => $paid,
            'sisa' => $sisa,
            'status' => $status,
            'is_wajib' => in_array($slug, $wajibSlugs, true),
        ];
        if (!isset($perPosWajib[$slug]) && isset($expectedDefault, $overrideAktif, $isOpsionalBulanan) && $isOpsionalBulanan) {
            $row['is_opsional'] = true;
            $row['expected_default'] = (int) $expectedDefault;
            $row['override_aktif'] = (bool) $overrideAktif;
            $row['override_nominal'] = $overrideNominal;
        }
        if (isset($perPosWajib[$slug])) {
            $persenPot = (float) ($perPosWajib[$slug]['persen_potongan'] ?? 0);
            if ($persenPot > 0) {
                $row['expected_dasar'] = (int) ($perPosWajib[$slug]['expected_dasar'] ?? $expected);
                $row['persen_potongan'] = $persenPot;
                $row['keterangan_potongan'] = (string) ($perPosWajib[$slug]['keterangan_potongan'] ?? '');
            }
            if ($slug === 'syahriyah') {
                $row['pkpps_tambahan'] = (int) ($perPosWajib[$slug]['pkpps_tambahan'] ?? 0);
                $row['kelas_syahriyah_tambahan'] = (int) ($perPosWajib[$slug]['kelas_syahriyah_tambahan'] ?? 0);
                $row['expected_setelah_potongan'] = (int) ($perPosWajib[$slug]['expected_setelah_potongan'] ?? $expected);
                $tierKey = trim((string) ($perPosWajib[$slug]['tier_key'] ?? $tier));
                if ($tierKey !== '') {
                    $row['tier_key'] = $tierKey;
                    $row['tier_label'] = $tierKey === 'muadalah' ? 'Muadalah' : ($tierKey === 'ulya' ? 'Ulya' : 'Wustho');
                }
            }
        }
        $out[$slug] = $row;
    }

    return $out;
}

/** Total pembayaran pos syahriyah (slug) untuk satu bulan tagihan. */
function keuangan_syahriyah_terbayar_bulan(
    PDO $pdo,
    int $bulanTagihan,
    int $tahunMulai,
    int $tahunSelesai
): int {
    if (
        $bulanTagihan < 1
        || $bulanTagihan > 12
        || !table_exists($pdo, 'keuangan_pembayaran')
        || !table_exists($pdo, 'keuangan_pembayaran_detail')
    ) {
        return 0;
    }

    $bulanMatch = pondok_sql_match_bulan_tagihan($pdo, $tahunMulai, $tahunSelesai, $bulanTagihan, 'p');
    $st = $pdo->prepare('
        SELECT COALESCE(SUM(d.nominal), 0)
        FROM keuangan_pembayaran_detail d
        INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
        WHERE p.jenis_periode = \'BULANAN\'
          AND p.tahun_ajaran_mulai = :tm
          AND p.tahun_ajaran_selesai = :ts
          AND LOWER(TRIM(d.pos_slug)) = \'syahriyah\'
          AND ' . $bulanMatch['sql'] . '
    ');
    $st->execute(array_merge(['tm' => $tahunMulai, 'ts' => $tahunSelesai], $bulanMatch['params']));

    return (int) round((float) ($st->fetchColumn() ?: 0));
}

/**
 * Rekap alokasi syahriyah per komponen: harus masuk, masuk, pengeluaran, saldo.
 *
 * @param int $syahriyahHarusMasuk Total tagihan syahriyah seharusnya (target bulan); 0 = dihitung dari rekap POS.
 * @return list<array{nama:string,kategori:string,persen:float,harus_masuk:int,masuk:int,pengeluaran:int,saldo:int}>
 */
function keuangan_rekap_alokasi_syahriyah_bulan(
    PDO $pdo,
    int $bulanTagihan,
    int $tahunMulai,
    int $tahunSelesai,
    int $syahriyahHarusMasuk = 0
): array {
    $rows = keuangan_fetch_alokasi_aktif($pdo, KEUNGAN_ALOKASI_JENIS_SYAHRIYAH);
    if ($rows === []) {
        return [];
    }

    if ($syahriyahHarusMasuk <= 0) {
        $syahriyahHarusMasuk = keuangan_syahriyah_harus_masuk_bulan($pdo, $bulanTagihan, $tahunMulai, $tahunSelesai);
    }

    $paguBulan = keuangan_syahriyah_terbayar_bulan($pdo, $bulanTagihan, $tahunMulai, $tahunSelesai);
    [$tglAwal, $tglAkhir] = pondok_rentang_masehi_bulan_tagihan($pdo, $tahunMulai, $tahunSelesai, $bulanTagihan);

    $keluarMap = [];
    if (
        $tglAwal !== ''
        && $tglAkhir !== ''
        && table_exists($pdo, 'keuangan_pengeluaran')
        && column_exists($pdo, 'keuangan_pengeluaran', 'alokasi_nama')
    ) {
        $stKeluar = $pdo->prepare('
            SELECT TRIM(alokasi_nama) AS nama, COALESCE(SUM(nominal), 0) AS total
            FROM keuangan_pengeluaran
            WHERE alokasi_nama IS NOT NULL
              AND TRIM(alokasi_nama) <> \'\'
              AND tanggal BETWEEN :awal AND :akhir
            GROUP BY TRIM(alokasi_nama)
        ');
        $stKeluar->execute(['awal' => $tglAwal, 'akhir' => $tglAkhir]);
        foreach ($stKeluar->fetchAll(PDO::FETCH_ASSOC) as $kr) {
            $keluarMap[(string) ($kr['nama'] ?? '')] = (int) round((float) ($kr['total'] ?? 0));
        }
    }

    $out = [];
    foreach ($rows as $row) {
        $nama = trim((string) ($row['nama_komponen'] ?? ''));
        if ($nama === '') {
            continue;
        }
        $persen = (float) ($row['persen'] ?? 0);
        $harusMasuk = $syahriyahHarusMasuk > 0 ? (int) floor($syahriyahHarusMasuk * $persen / 100) : 0;
        $masuk = $paguBulan > 0 ? (int) floor($paguBulan * $persen / 100) : 0;
        $pengeluaran = (int) ($keluarMap[$nama] ?? 0);
        $out[] = [
            'nama' => $nama,
            'kategori' => (string) ($row['kategori'] ?? ''),
            'persen' => round($persen, 2),
            'harus_masuk' => $harusMasuk,
            'masuk' => $masuk,
            'pengeluaran' => $pengeluaran,
            'saldo' => $masuk - $pengeluaran,
        ];
    }

    return $out;
}

/** Total tagihan syahriyah seharusnya (santri aktif × tarif, termasuk potongan) untuk satu bulan tagihan. */
function keuangan_syahriyah_harus_masuk_bulan(
    PDO $pdo,
    int $bulanTagihan,
    int $tahunMulai,
    int $tahunSelesai
): int {
    if ($bulanTagihan < 1 || $bulanTagihan > 12 || !table_exists($pdo, 'santri')) {
        return 0;
    }

    if (!function_exists('keuangan_biaya_definitions')) {
        require_once __DIR__ . '/keuangan_defs.php';
    }
    $biayaDefs = keuangan_biaya_definitions();
    $rekap = keuangan_rekap_pos_with_expected(
        $pdo,
        'BULANAN',
        $bulanTagihan,
        $tahunMulai,
        $tahunSelesai,
        $biayaDefs
    );
    foreach ($rekap as $row) {
        if (strtolower(trim((string) ($row['pos_slug'] ?? ''))) === 'syahriyah') {
            return (int) ($row['expected'] ?? 0);
        }
    }

    return 0;
}

/**
 * Ringkasan laporan syahriyah satu bulan: target, terbayar, alokasi per persen.
 *
 * @return array{
 *   bulan:int,
 *   label:string,
 *   harus_masuk:int,
 *   terbayar:int,
 *   sisa:int,
 *   capai_persen:float,
 *   alokasi:list<array{nama:string,kategori:string,persen:float,harus_masuk:int,masuk:int,pengeluaran:int,saldo:int}>
 * }
 */
function keuangan_laporan_syahriyah_bulan(
    PDO $pdo,
    int $bulanTagihan,
    int $tahunMulai,
    int $tahunSelesai
): array {
    $empty = [
        'bulan' => $bulanTagihan,
        'label' => '',
        'harus_masuk' => 0,
        'terbayar' => 0,
        'sisa' => 0,
        'capai_persen' => 0.0,
        'alokasi' => [],
    ];
    if ($bulanTagihan < 1 || $bulanTagihan > 12) {
        return $empty;
    }

    $harusMasuk = keuangan_syahriyah_harus_masuk_bulan($pdo, $bulanTagihan, $tahunMulai, $tahunSelesai);
    $terbayar = keuangan_syahriyah_terbayar_bulan($pdo, $bulanTagihan, $tahunMulai, $tahunSelesai);
    $sisa = max(0, $harusMasuk - $terbayar);
    $capai = $harusMasuk > 0 ? min(100.0, round($terbayar / $harusMasuk * 100, 1)) : 0.0;

    return [
        'bulan' => $bulanTagihan,
        'label' => '',
        'harus_masuk' => $harusMasuk,
        'terbayar' => $terbayar,
        'sisa' => $sisa,
        'capai_persen' => $capai,
        'alokasi' => keuangan_rekap_alokasi_syahriyah_bulan(
            $pdo,
            $bulanTagihan,
            $tahunMulai,
            $tahunSelesai,
            $harusMasuk
        ),
    ];
}
