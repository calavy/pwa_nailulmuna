<?php

declare(strict_types=1);

/**
 * Penyelesaian administrasi santri keluar: tagihan bulanan, saldo cashless, surat.
 */

function ensure_santri_keluar_columns(PDO $pdo): void
{
    if (!table_exists($pdo, 'santri')) {
        return;
    }
    if (function_exists('ensure_santri_identity_columns')) {
        ensure_santri_identity_columns($pdo);
    }
    $cols = [
        'keluar_kategori' => "VARCHAR(40) NULL COMMENT 'TAMAT|KELUAR_PINDAH'",
        'keluar_settled_at' => 'DATETIME NULL',
        'nomor_surat_keluar' => 'VARCHAR(180) NULL',
        'nomor_surat_tanggungan' => 'VARCHAR(180) NULL',
        'keluar_ringkasan_keuangan' => 'TEXT NULL',
    ];
    foreach ($cols as $col => $def) {
        if (!column_exists($pdo, 'santri', $col)) {
            try {
                $pdo->exec('ALTER TABLE santri ADD COLUMN ' . $col . ' ' . $def);
            } catch (PDOException $e) {
                $m = strtolower($e->getMessage());
                if (!str_contains($m, 'duplicate') && !str_contains($m, '1060')) {
                    throw $e;
                }
            }
        }
    }
}

function keluar_kategori_label(string $k): string
{
    $kat = strtoupper(trim($k));
    if ($kat === 'TAMAT' || $kat === 'MUQIM') {
        return 'Tamat / alumni';
    }
    if (in_array($kat, ['KELUAR_PINDAH', 'BOYONG', 'KELUAR'], true)) {
        return 'Keluar (belum tamat)';
    }

    return $kat !== '' ? $kat : '—';
}

/** Jumlah pos terbayar per bulan & tahun ajaran untuk satu slug komponen. */
function keuangan_paid_component_month(PDO $pdo, int $santriId, string $slug, int $bulanTagihan, int $tahunMulai, int $tahunSelesai): int
{
    if ($santriId <= 0 || $bulanTagihan < 1 || $bulanTagihan > 12) {
        return 0;
    }
    if (!table_exists($pdo, 'keuangan_pembayaran') || !table_exists($pdo, 'keuangan_pembayaran_detail')) {
        return 0;
    }
    $slug = strtolower(trim($slug));
    $stmt = $pdo->prepare('
        SELECT COALESCE(SUM(d.nominal), 0)
        FROM keuangan_pembayaran_detail d
        INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
        WHERE p.santri_id = :sid
          AND p.jenis_periode = \'BULANAN\'
          AND p.bulan_tagihan = :bulan
          AND p.tahun_ajaran_mulai = :tm
          AND p.tahun_ajaran_selesai = :ts
          AND LOWER(TRIM(d.pos_slug)) = :slug
    ');
    $stmt->execute([
        'sid' => $santriId,
        'bulan' => $bulanTagihan,
        'tm' => $tahunMulai,
        'ts' => $tahunSelesai,
        'slug' => $slug,
    ]);

    return (int) ((float) ($stmt->fetchColumn() ?: 0));
}

/**
 * @return list<array{bulan:int,slug:string,nama:string,expected:int,paid:int,sisa:int}>
 */
function santri_outstanding_bulanan_rows(PDO $pdo, int $santriId, string $kelasKategori, int $tahunMulai, int $tahunSelesai): array
{
    $out = [];
    $comps = keuangan_tagihan_wajib_components($pdo, $kelasKategori);
    for ($bulan = 1; $bulan <= 12; $bulan++) {
        foreach ($comps as $c) {
            $slug = strtolower(trim((string) ($c['slug'] ?? '')));
            if ($slug === '') {
                continue;
            }
            $expected = max(0, (int) ($c['nominal'] ?? 0));
            if ($expected <= 0) {
                continue;
            }
            $paid = keuangan_paid_component_month($pdo, $santriId, $slug, $bulan, $tahunMulai, $tahunSelesai);
            $sisa = max(0, $expected - $paid);
            if ($sisa > 0) {
                $out[] = [
                    'bulan' => $bulan,
                    'slug' => $slug,
                    'nama' => (string) ($c['nama'] ?? $slug),
                    'expected' => $expected,
                    'paid' => $paid,
                    'sisa' => $sisa,
                ];
            }
        }
    }

    return $out;
}

function santri_cashless_balance(PDO $pdo, int $santriId): int
{
    if (!table_exists($pdo, 'cashless_accounts') || $santriId <= 0) {
        return 0;
    }
    $st = $pdo->prepare('SELECT COALESCE(balance,0) FROM cashless_accounts WHERE santri_id = :id LIMIT 1');
    $st->execute(['id' => $santriId]);

    return (int) ((float) ($st->fetchColumn() ?: 0));
}

/**
 * @return array{lines: list<string>, cashless_used: int, waiver_total: int, cashless_cleared: int}
 */
function santri_settle_keuangan_on_exit(
    PDO $pdo,
    int $santriId,
    string $kelasKategori,
    int $tahunMulai,
    int $tahunSelesai,
    string $tanggalBayar,
    int $createdBy,
    string $nomorSuratRef
): array {
    if (!table_exists($pdo, 'keuangan_pembayaran') || !table_exists($pdo, 'keuangan_pembayaran_detail')) {
        return ['lines' => ['Modul keuangan belum tersedia.'], 'cashless_used' => 0, 'waiver_total' => 0, 'cashless_cleared' => 0];
    }

    $lines = [];
    $cashlessUsed = 0;
    $waiverTotal = 0;
    $queue = santri_outstanding_bulanan_rows($pdo, $santriId, $kelasKategori, $tahunMulai, $tahunSelesai);
    $balance = santri_cashless_balance($pdo, $santriId);

    $insP = $pdo->prepare('
        INSERT INTO keuangan_pembayaran
        (santri_id, jenis_periode, tahun_ajaran_mulai, tahun_ajaran_selesai, bulan_tagihan, tanggal_bayar, metode_bayar, akun_id, no_referensi, total_nominal, keterangan, created_by)
        VALUES
        (:santri_id, \'BULANAN\', :tm, :ts, :bulan, :tanggal, :metode, NULL, :noref, :total, :ket, :cb)
    ');
    $insD = $pdo->prepare('INSERT INTO keuangan_pembayaran_detail (pembayaran_id, pos_slug, pos_nama, nominal) VALUES (:pid, :slug, :nama, :nom)');

    $slugToNama = [];
    foreach (keuangan_monthly_bill_components($pdo, $kelasKategori) as $c) {
        $slugToNama[strtolower(trim((string) ($c['slug'] ?? '')))] = (string) ($c['nama'] ?? '');
    }

    foreach ($queue as $row) {
        $sisa = (int) $row['sisa'];
        if ($sisa <= 0) {
            continue;
        }
        $slug = (string) $row['slug'];
        $nama = (string) ($row['nama'] ?? ($slugToNama[$slug] ?? $slug));
        $bulan = (int) $row['bulan'];
        $payFromCashless = min($sisa, $balance);
        if ($payFromCashless > 0) {
            $ket = 'Pemotongan saldo cashless saat keluar santri — ' . $nomorSuratRef . ' (bulan ' . $bulan . ', ' . $nama . ')';
            $insP->execute([
                'santri_id' => $santriId,
                'tm' => $tahunMulai,
                'ts' => $tahunSelesai,
                'bulan' => $bulan,
                'tanggal' => $tanggalBayar,
                'metode' => 'KAS',
                'noref' => 'CASHLESS-KELUAR-' . $santriId . '-' . $bulan . '-' . $slug,
                'total' => $payFromCashless,
                'ket' => $ket,
                'cb' => $createdBy,
            ]);
            $pid = (int) $pdo->lastInsertId();
            $insD->execute(['pid' => $pid, 'slug' => $slug, 'nama' => $nama, 'nom' => $payFromCashless]);
            if (table_exists($pdo, 'cashless_accounts')) {
                $pdo->prepare('UPDATE cashless_accounts SET balance = balance - :n WHERE santri_id = :sid')->execute(['n' => $payFromCashless, 'sid' => $santriId]);
            }
            if (table_exists($pdo, 'cashless_transactions')) {
                $pdo->prepare("INSERT INTO cashless_transactions (santri_id, jenis, nominal, keterangan, ref_pembayaran_id, created_by) VALUES (:sid,'DEBIT',:nom,:ket,:pid,:cb)")
                    ->execute([
                        'sid' => $santriId,
                        'nom' => $payFromCashless,
                        'ket' => $ket,
                        'pid' => $pid,
                        'cb' => $createdBy,
                    ]);
            }
            $balance -= $payFromCashless;
            $cashlessUsed += $payFromCashless;
            $lines[] = 'Cashless Rp ' . number_format($payFromCashless, 0, ',', '.') . ' untuk ' . $nama . ' bulan ke-' . $bulan . '.';
            $sisa -= $payFromCashless;
        }
        if ($sisa > 0) {
            $ketW = 'Pelunasan administratif penutupan tanggihan saat keluar santri — ' . $nomorSuratRef . ' (bulan ' . $bulan . ', ' . $nama . ')';
            $insP->execute([
                'santri_id' => $santriId,
                'tm' => $tahunMulai,
                'ts' => $tahunSelesai,
                'bulan' => $bulan,
                'tanggal' => $tanggalBayar,
                'metode' => 'KAS',
                'noref' => 'WAIVER-KELUAR-' . $santriId . '-' . $bulan . '-' . $slug,
                'total' => $sisa,
                'ket' => $ketW,
                'cb' => $createdBy,
            ]);
            $pid2 = (int) $pdo->lastInsertId();
            $insD->execute(['pid' => $pid2, 'slug' => $slug, 'nama' => $nama, 'nom' => $sisa]);
            $waiverTotal += $sisa;
            $lines[] = 'Penyesuaian administratif (lunas) Rp ' . number_format($sisa, 0, ',', '.') . ' untuk ' . $nama . ' bulan ke-' . $bulan . '.';
        }
    }

    $balanceAfter = santri_cashless_balance($pdo, $santriId);
    $cleared = 0;
    if ($balanceAfter > 0 && table_exists($pdo, 'cashless_accounts')) {
        $cleared = $balanceAfter;
        $pdo->prepare('UPDATE cashless_accounts SET balance = 0 WHERE santri_id = :sid')->execute(['sid' => $santriId]);
        if (table_exists($pdo, 'cashless_transactions')) {
            $pdo->prepare("INSERT INTO cashless_transactions (santri_id, jenis, nominal, keterangan, ref_pembayaran_id, created_by) VALUES (:sid,'DEBIT',:nom,:ket,NULL,:cb)")
                ->execute([
                    'sid' => $santriId,
                    'nom' => $cleared,
                    'ket' => 'Penutupan saldo cashless saat keluar santri — ' . $nomorSuratRef,
                    'cb' => $createdBy,
                ]);
        }
        $lines[] = 'Sisa saldo cashless Rp ' . number_format($cleared, 0, ',', '.') . ' dinolkan (penutupan akun).';
    }

    if (function_exists('keuangan_dashboard_cache_invalidate')) {
        keuangan_dashboard_cache_invalidate();
    } else {
        if (is_file(__DIR__ . '/keuangan_dashboard.php')) {
            require_once __DIR__ . '/keuangan_dashboard.php';
            if (function_exists('keuangan_dashboard_cache_invalidate')) {
                keuangan_dashboard_cache_invalidate();
            }
        }
    }

    return [
        'lines' => $lines,
        'cashless_used' => $cashlessUsed,
        'waiver_total' => $waiverTotal,
        'cashless_cleared' => $cleared,
    ];
}

/** Gabung baris alamat santri untuk kop surat wali cadangan. */
function santri_alamat_garis(array $s): string
{
    $parts = array_filter([
        trim((string) ($s['dusun'] ?? '')),
        trim((string) ($s['rt_rw'] ?? '')),
        trim((string) ($s['desa_kelurahan'] ?? '')),
        trim((string) ($s['kecamatan'] ?? '')),
        trim((string) ($s['kabupaten'] ?? '')),
        trim((string) ($s['propinsi'] ?? '')),
    ], static fn(string $x): bool => $x !== '');

    return $parts === [] ? '—' : implode(', ', $parts);
}

/**
 * @return array{nama:string,no_wa:string,alamat:string,nomor_id:string}|null
 */
function santri_wali_display_row(PDO $pdo, array $santri): ?array
{
    require_once __DIR__ . '/santri_status.php';
    $non = (int) ($santri['is_aktif'] ?? 1) === 0
        || santri_status_is_nonaktif(santri_status_from_row($santri));
    if ($non) {
        return null;
    }

    $wid = (int) ($santri['wali_santri_id'] ?? 0);
    if ($wid > 0 && table_exists($pdo, 'wali_santri')) {
        ensure_wali_santri_table($pdo);
        $st = $pdo->prepare('SELECT nama, no_wa, alamat, nomor_id FROM wali_santri WHERE id = :id LIMIT 1');
        $st->execute(['id' => $wid]);
        $w = $st->fetch(PDO::FETCH_ASSOC);
        if (is_array($w)) {
            return [
                'nama' => trim((string) ($w['nama'] ?? '')),
                'no_wa' => trim((string) ($w['no_wa'] ?? '')),
                'alamat' => trim((string) ($w['alamat'] ?? '')),
                'nomor_id' => trim((string) ($w['nomor_id'] ?? '')),
            ];
        }
    }

    $nama = trim((string) ($santri['nama_kafil'] ?? ''));
    if ($nama === '') {
        return null;
    }

    return [
        'nama' => $nama,
        'no_wa' => trim((string) ($santri['no_kontak_kafil'] ?? ($santri['no_wa_wali'] ?? ''))),
        'alamat' => santri_alamat_garis($santri),
        'nomor_id' => '—',
    ];
}
