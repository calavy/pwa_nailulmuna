<?php

declare(strict_types=1);

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
        $paidStmt = $pdo->prepare('
            SELECT d.pos_slug, d.pos_nama, COALESCE(SUM(d.nominal), 0) AS total_nominal
            FROM keuangan_pembayaran p
            INNER JOIN keuangan_pembayaran_detail d ON d.pembayaran_id = p.id
            WHERE p.jenis_periode = :jenis_periode
              AND p.tahun_ajaran_mulai = :tahun_mulai
              AND p.tahun_ajaran_selesai = :tahun_selesai
              AND (:bulan_tagihan = 0 OR p.bulan_tagihan = :bulan_tagihan)
            GROUP BY d.pos_slug, d.pos_nama
        ');
        $paidStmt->execute([
            'jenis_periode' => $jenisPeriode,
            'tahun_mulai' => $tahunMulai,
            'tahun_selesai' => $tahunSelesai,
            'bulan_tagihan' => $jenisPeriode === 'BULANAN' ? $bulanTagihan : 0,
        ]);
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
    $santriRows = $pdo->query('SELECT s.id, ' . $levelExpr . ' AS kategori_kelas FROM santri s WHERE ' . $aktifSql)->fetchAll(PDO::FETCH_ASSOC);

    $useSyahriyahPotongan = $jenisPeriode === 'BULANAN'
        && isset($expectedBySlug['syahriyah']);
    if ($useSyahriyahPotongan && !function_exists('keuangan_syahriyah_expected_dengan_potongan')) {
        require_once __DIR__ . '/keuangan_syahriyah_potongan.php';
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
            if ($useSyahriyahPotongan && $slug === 'syahriyah' && $sid > 0) {
                $syPot = keuangan_syahriyah_expected_dengan_potongan(
                    $pdo,
                    $sid,
                    $kat,
                    $jenisPeriode === 'BULANAN' ? $bulanTagihan : 0,
                    $tahunMulai,
                    $tahunSelesai
                );
                $expectedBySlug[$slug]['expected'] += max(0, (int) ($syPot['expected'] ?? 0));
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
    $stmt = $pdo->prepare('
        SELECT LOWER(TRIM(d.pos_slug)) AS pos_slug, COALESCE(SUM(d.nominal), 0) AS total
        FROM keuangan_pembayaran p
        INNER JOIN keuangan_pembayaran_detail d ON d.pembayaran_id = p.id
        WHERE p.santri_id = :sid
          AND p.jenis_periode = :jenis
          AND p.tahun_ajaran_mulai = :tm
          AND p.tahun_ajaran_selesai = :ts
          AND (:bulan = 0 OR p.bulan_tagihan = :bulan)
        GROUP BY d.pos_slug
    ');
    $stmt->execute([
        'sid' => $santriId,
        'jenis' => $jenisPeriode,
        'tm' => $tahunMulai,
        'ts' => $tahunSelesai,
        'bulan' => $jenisPeriode === 'BULANAN' ? max(1, min(12, $bulanTagihan)) : 0,
    ]);
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
        if (isset($perPosWajib[$slug])) {
            $persenPot = (float) ($perPosWajib[$slug]['persen_potongan'] ?? 0);
            if ($persenPot > 0) {
                $row['expected_dasar'] = (int) ($perPosWajib[$slug]['expected_dasar'] ?? $expected);
                $row['persen_potongan'] = $persenPot;
                $row['keterangan_potongan'] = (string) ($perPosWajib[$slug]['keterangan_potongan'] ?? '');
            }
        }
        $out[$slug] = $row;
    }

    return $out;
}
