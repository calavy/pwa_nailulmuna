<?php

declare(strict_types=1);

require_once __DIR__ . '/keuangan_alokasi.php';
require_once __DIR__ . '/santri_list_sort.php';

/**
 * Perhitungan target dana masuk per pos (rekap) dari santri aktif × tarif pengaturan.
 * Terbayar = akumulasi valid (dibatasi tagihan per santri, tanpa kelebihan bayar dobel).
 *
 * @return list<array{pos_slug:string,pos_nama:string,expected:int,paid:int,paid_raw?:int,paid_lebih?:int}>
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

    if (!function_exists('santri_sql_aktif_only')) {
        require_once __DIR__ . '/santri_operasional.php';
    }

    $kategoriFilter = $jenisPeriode === 'BULANAN' ? 'Bulanan' : 'Awal Tahun';
    $agg = [];
    foreach ($biayaDefinitions as $def) {
        if (($def['kategori'] ?? '') !== $kategoriFilter) {
            continue;
        }
        $slug = strtolower(trim((string) ($def['slug'] ?? '')));
        if ($slug === '') {
            continue;
        }
        $agg[$slug] = [
            'nama' => (string) ($def['nama'] ?? $slug),
            'expected' => 0,
            'paid_valid' => 0,
            'paid_raw' => 0,
        ];
    }

    $aktifSql = santri_sql_aktif_only('s');
    $levelExpr = column_exists($pdo, 'santri', 'kategori_kelas') ? 's.kategori_kelas' : (column_exists($pdo, 'santri', 'tingkatan') ? 's.tingkatan' : "''");
    $santriRows = $pdo->query('SELECT s.id FROM santri s WHERE ' . $aktifSql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($santriRows as $sr) {
        $sid = (int) ($sr['id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $breakdown = keuangan_tagihan_breakdown_for_santri(
            $pdo,
            $sid,
            $jenisPeriode,
            $bulanTagihan,
            $tahunMulai,
            $tahunSelesai,
            $biayaDefinitions
        );
        foreach ($breakdown as $slugRaw => $info) {
            $slug = strtolower(trim((string) $slugRaw));
            if ($slug === '') {
                continue;
            }
            if (!isset($agg[$slug])) {
                $agg[$slug] = [
                    'nama' => $slug,
                    'expected' => 0,
                    'paid_valid' => 0,
                    'paid_raw' => 0,
                ];
            }
            $expected = max(0, (int) ($info['expected'] ?? 0));
            $paid = max(0, (int) ($info['paid'] ?? 0));
            $paidValid = $expected > 0 ? min($paid, $expected) : $paid;
            $agg[$slug]['expected'] += $expected;
            $agg[$slug]['paid_raw'] += $paid;
            $agg[$slug]['paid_valid'] += $paidValid;
        }
    }

    ksort($agg);
    $rows = [];
    foreach ($agg as $slug => $a) {
        $paidValid = (int) ($a['paid_valid'] ?? 0);
        $paidRaw = (int) ($a['paid_raw'] ?? 0);
        $rows[] = [
            'pos_slug' => $slug,
            'pos_nama' => (string) ($a['nama'] ?? $slug),
            'expected' => (int) ($a['expected'] ?? 0),
            'paid' => $paidValid,
            'paid_raw' => $paidRaw,
            'paid_lebih' => max(0, $paidRaw - $paidValid),
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

    if (!function_exists('keuangan_santri_kelas_tagihan')) {
        require_once __DIR__ . '/santri_ta.php';
    }
    $kat = keuangan_santri_kelas_tagihan($pdo, $santriId, $tahunMulai, $tahunSelesai);

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
            if ($jenisPeriode === 'AWAL_TAHUN') {
                if (!function_exists('keuangan_fee_nominal_awal_tahun')) {
                    require_once __DIR__ . '/tagihan_santri_masuk.php';
                }
                $jenisSantri = tagihan_santri_jenis_ta($pdo, $santriId, $tahunMulai);
                $expected = keuangan_fee_nominal_awal_tahun($pdo, $def, $tier, $jenisSantri);
            } else {
                $expected = keuangan_fee_nominal_for_tier($pdo, $def, $tier);
            }
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
                if ($slugLower === 'makan') {
                    if (!function_exists('keuangan_makan_nominal_for_kelas')) {
                        require_once __DIR__ . '/keuangan_kelas_makan.php';
                    }
                    $expected = keuangan_makan_nominal_for_kelas(
                        $pdo,
                        $kat,
                        $bulanTagihan,
                        $tahunMulai,
                        $tahunSelesai
                    );
                } elseif (in_array($slugLower, keuangan_tarif_bulanan_pos_slugs(), true)) {
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
            $pkppsTambahan = 0;
            $expectedSetelahPotongan = $expected;
            if (
                $slug === 'syahriyah'
                && $jenisPeriode === 'BULANAN'
                && $santriId > 0
                && $bulanTagihan >= 1
                && $bulanTagihan <= 12
            ) {
                if (!function_exists('keuangan_syahriyah_expected_dengan_potongan')) {
                    require_once __DIR__ . '/keuangan_syahriyah_potongan.php';
                }
                $syPot = keuangan_syahriyah_expected_dengan_potongan(
                    $pdo,
                    $santriId,
                    $kat,
                    $bulanTagihan,
                    $tahunMulai,
                    $tahunSelesai
                );
                $expected = max(0, (int) ($syPot['expected'] ?? $expected));
                $expectedDefault = $expected;
                $pkppsTambahan = (int) ($syPot['pkpps_tambahan'] ?? 0);
                $expectedSetelahPotongan = max(0, (int) ($syPot['expected_setelah_potongan'] ?? ($expected - $pkppsTambahan)));
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
        if (!isset($perPosWajib[$slug]) && $slug === 'syahriyah' && ($pkppsTambahan ?? 0) > 0) {
            $row['pkpps_tambahan'] = (int) $pkppsTambahan;
            $row['expected_setelah_potongan'] = (int) ($expectedSetelahPotongan ?? max(0, $expected - $pkppsTambahan));
            $row['tier_key'] = $tier;
            $row['tier_label'] = $tier === 'muadalah' ? 'Muadalah' : ($tier === 'ulya' ? 'Ulya' : 'Wustho');
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

/**
 * Cegah input pembayaran dobel: komponen yang sudah lunas tidak boleh dibayar lagi.
 *
 * @param list<array{slug:string,nama?:string,nominal:int}> $detailRows
 * @param array<string, int> $paidAdjustBySlug Kurangi paid (mis. nominal lama saat edit pembayaran)
 * @return array{ok:bool,message:string}
 */
function keuangan_pembayaran_validasi_anti_dobel(
    PDO $pdo,
    int $santriId,
    string $jenisPeriode,
    int $bulanTagihan,
    int $tahunMulai,
    int $tahunSelesai,
    array $detailRows,
    array $biayaDefinitions,
    array $paidAdjustBySlug = []
): array {
    if ($santriId <= 0 || $detailRows === []) {
        return ['ok' => true, 'message' => ''];
    }

    $breakdown = keuangan_tagihan_breakdown_for_santri(
        $pdo,
        $santriId,
        $jenisPeriode,
        $bulanTagihan,
        $tahunMulai,
        $tahunSelesai,
        $biayaDefinitions
    );
    $wajibSlugs = $jenisPeriode === 'BULANAN' ? keuangan_tagihan_wajib_slugs() : [];

    foreach ($detailRows as $dr) {
        $slug = (string) ($dr['slug'] ?? '');
        $nominal = (int) ($dr['nominal'] ?? 0);
        if ($slug === '' || $nominal <= 0) {
            continue;
        }
        if ($slug === 'saku') {
            continue;
        }
        $info = $breakdown[$slug] ?? null;
        if (!is_array($info)) {
            continue;
        }
        $nama = (string) ($dr['nama'] ?? $slug);
        $expected = (int) ($info['expected'] ?? 0);
        $paid = max(0, (int) ($info['paid'] ?? 0) - (int) ($paidAdjustBySlug[$slug] ?? 0));
        $sisa = max(0, $expected - $paid);
        $isWajibBulanan = $jenisPeriode === 'BULANAN' && in_array($slug, $wajibSlugs, true);

        if ($expected > 0 && $sisa <= 0) {
            return [
                'ok' => false,
                'message' => 'Komponen ' . $nama . ' sudah lunas untuk periode ini. Input dobel tidak diizinkan.',
            ];
        }
        if ($expected > 0 && $nominal > $sisa && ($isWajibBulanan || $jenisPeriode === 'AWAL_TAHUN')) {
            return [
                'ok' => false,
                'message' => 'Nominal ' . $nama . ' melebihi sisa tagihan (Rp ' . number_format($sisa, 0, ',', '.') . ').',
            ];
        }
    }

    return ['ok' => true, 'message' => ''];
}

/** Total pembayaran pos syahriyah (slug) untuk satu bulan tagihan — terbayar valid (tanpa kelebihan dobel). */
function keuangan_syahriyah_terbayar_bulan(
    PDO $pdo,
    int $bulanTagihan,
    int $tahunMulai,
    int $tahunSelesai
): int {
    if ($bulanTagihan < 1 || $bulanTagihan > 12) {
        return 0;
    }
    if (!function_exists('keuangan_biaya_definitions')) {
        require_once __DIR__ . '/keuangan_defs.php';
    }
    $rekap = keuangan_rekap_pos_with_expected(
        $pdo,
        'BULANAN',
        $bulanTagihan,
        $tahunMulai,
        $tahunSelesai,
        keuangan_biaya_definitions()
    );
    foreach ($rekap as $row) {
        if (strtolower(trim((string) ($row['pos_slug'] ?? ''))) === 'syahriyah') {
            return (int) ($row['paid'] ?? 0);
        }
    }

    return 0;
}

/**
 * Rekap alokasi syahriyah per komponen: harus masuk, masuk, pengeluaran, saldo.
 *
 * Model selaras dengan laporan alokasi per santri: tambahan PKPPS → baris dana umum;
 * persen komponen (gizi, KOPSA, …) hanya dari dasar syahriyah (setelah slice PKPPS).
 * Pembayaran masuk: PKPPS diambil dulu dari cicilan, sisanya ke dasar × %.
 *
 * @param int $syahriyahHarusMasuk Diabaikan (dipertahankan untuk kompatibilitas pemanggil).
 * @return list<array{nama:string,kategori:string,persen:float,harus_masuk:int,masuk:int,pengeluaran:int,saldo:int,is_pkpps_gaji?:bool}>
 */
function keuangan_rekap_alokasi_syahriyah_bulan(
    PDO $pdo,
    int $bulanTagihan,
    int $tahunMulai,
    int $tahunSelesai,
    int $syahriyahHarusMasuk = 0
): array {
    unset($syahriyahHarusMasuk);

    $rows = keuangan_fetch_alokasi_aktif($pdo, KEUNGAN_ALOKASI_JENIS_SYAHRIYAH);
    if ($rows === [] || $bulanTagihan < 1 || $bulanTagihan > 12 || !table_exists($pdo, 'santri')) {
        return [];
    }

    if (!function_exists('keuangan_syahriyah_expected_dengan_potongan')) {
        require_once __DIR__ . '/keuangan_syahriyah_potongan.php';
    }
    if (!function_exists('keuangan_syahriyah_split_pembayaran_tambahan')) {
        require_once __DIR__ . '/keuangan_pkpps_syahriyah.php';
    }
    if (!function_exists('tagihan_paid_map_for_month')) {
        require_once __DIR__ . '/tagihan_bulanan.php';
    }
    if (!function_exists('santri_sql_aktif_only')) {
        require_once __DIR__ . '/santri_operasional.php';
    }
    if (!function_exists('pondok_rentang_masehi_bulan_tagihan')) {
        require_once __DIR__ . '/pondok_kalender.php';
    }

    $pkppsTarget = keuangan_pkpps_alokasi_komponen_nama($pdo);
    $harusKomponen = [];
    $masukKomponen = [];
    foreach ($rows as $row) {
        $nama = trim((string) ($row['nama_komponen'] ?? ''));
        if ($nama !== '') {
            $harusKomponen[$nama] = 0;
            $masukKomponen[$nama] = 0;
        }
    }
    if (!isset($harusKomponen[$pkppsTarget])) {
        $harusKomponen[$pkppsTarget] = 0;
        $masukKomponen[$pkppsTarget] = 0;
    }

    $aktifSql = santri_sql_aktif_only('s');
    $stSantri = $pdo->query('
        SELECT s.id, s.kategori_kelas
        FROM santri s
        WHERE ' . $aktifSql
    );
    $santriList = $stSantri ? ($stSantri->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    foreach ($santriList as $s) {
        $sid = (int) ($s['id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $kat = function_exists('keuangan_santri_kelas_tagihan')
            ? keuangan_santri_kelas_tagihan($pdo, $sid, $tahunMulai, $tahunSelesai, $s)
            : trim((string) ($s['kategori_kelas'] ?? ''));
        $sim = keuangan_syahriyah_expected_dengan_potongan($pdo, $sid, $kat, $bulanTagihan, $tahunMulai, $tahunSelesai);
        $pkppsHarus = (int) ($sim['pkpps_tambahan'] ?? 0);
        $expected = (int) ($sim['expected'] ?? 0);
        $dasarHarus = max(0, $expected - $pkppsHarus);
        keuangan_pkpps_alokasi_tambah_ke_komponen($pdo, $pkppsHarus, $harusKomponen);
        foreach ($rows as $row) {
            $nama = trim((string) ($row['nama_komponen'] ?? ''));
            if ($nama === '') {
                continue;
            }
            $persen = (float) ($row['persen'] ?? 0);
            $harusKomponen[$nama] += (int) floor($dasarHarus * $persen / 100);
        }
    }

    $paidMap = tagihan_paid_map_for_month($pdo, $bulanTagihan, $tahunMulai, $tahunSelesai, ['syahriyah']);
    foreach ($santriList as $s) {
        $sid = (int) ($s['id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $bayar = (int) ($paidMap[$sid]['syahriyah'] ?? 0);
        if ($bayar <= 0) {
            continue;
        }
        $kat = function_exists('keuangan_santri_kelas_tagihan')
            ? keuangan_santri_kelas_tagihan($pdo, $sid, $tahunMulai, $tahunSelesai, $s)
            : trim((string) ($s['kategori_kelas'] ?? ''));
        $split = keuangan_syahriyah_split_pembayaran_tambahan(
            $pdo,
            $sid,
            $kat,
            $bayar,
            $bulanTagihan,
            $tahunMulai,
            $tahunSelesai
        );
        $dasarBayar = (int) ($split['dasar'] ?? $bayar);
        keuangan_pkpps_alokasi_tambah_ke_komponen($pdo, (int) ($split['umum'] ?? 0), $masukKomponen);
        foreach ($rows as $row) {
            $nama = trim((string) ($row['nama_komponen'] ?? ''));
            if ($nama === '') {
                continue;
            }
            $persen = (float) ($row['persen'] ?? 0);
            $masukKomponen[$nama] += (int) floor($dasarBayar * $persen / 100);
        }
    }

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
        $harusMasuk = (int) ($harusKomponen[$nama] ?? 0);
        $masuk = (int) ($masukKomponen[$nama] ?? 0);
        $pengeluaran = (int) ($keluarMap[$nama] ?? 0);
        $out[] = [
            'nama' => $nama,
            'kategori' => (string) ($row['kategori'] ?? ''),
            'persen' => round($persen, 2),
            'harus_masuk' => $harusMasuk,
            'masuk' => $masuk,
            'pengeluaran' => $pengeluaran,
            'saldo' => $masuk - $pengeluaran,
            'is_pkpps_gaji' => $nama === $pkppsTarget,
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
