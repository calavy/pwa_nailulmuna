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

/** Tahun ajaran keuangan aktif (sama seperti halaman administrasi keluar). */
function santri_keluar_periode_ta(PDO $pdo): array
{
    require_once __DIR__ . '/pondok_kalender.php';
    $taAktif = pondok_tahun_ajaran_aktif($pdo);
    $periodeMulai = (int) app_setting($pdo, 'keuangan_periode_mulai', (string) $taAktif['mulai']);
    $periodeSelesai = (int) app_setting($pdo, 'keuangan_periode_selesai', (string) $taAktif['selesai']);
    if ($periodeMulai < pondok_ta_tahun_min($pdo)) {
        $periodeMulai = $taAktif['mulai'];
        $periodeSelesai = $taAktif['selesai'];
    }
    if ($periodeSelesai < $periodeMulai) {
        $periodeSelesai = $periodeMulai + 1;
    }

    return ['mulai' => $periodeMulai, 'selesai' => $periodeSelesai];
}

function santri_kelas_kategori_from_row(array $row): string
{
    $kelasKategori = trim((string) ($row['kategori_kelas'] ?? ''));
    if ($kelasKategori === '' && trim((string) ($row['tingkatan'] ?? '')) !== '') {
        $kelasKategori = (string) $row['tingkatan'];
    }

    return $kelasKategori;
}

/**
 * @return array{
 *   santri_id:int,
 *   total_sisa_tagihan:int,
 *   cashless_saldo:int,
 *   sisa_uang_cashless:int,
 *   outstanding_count:int,
 *   outstanding_rows:list<array{bulan:int,slug:string,nama:string,expected:int,paid:int,sisa:int}>,
 *   periode_mulai:int,
 *   periode_selesai:int,
 *   proyeksi_cashless_ke_tagihan:int,
 *   proyeksi_sisa_tagihan_setelah_cashless:int,
 *   proyeksi_sisa_uang_setelah_tagihan:int,
 *   nominal_harus_dibayar:int,
 *   nominal_harus_dikembalikan:int,
 *   ada_sisa:bool,
 *   keluar_settled_at:?string,
 *   keluar_ringkasan_keuangan:string,
 *   status_label:string,
 *   badge_label:string,
 *   badge_variant:string
 * }
 */
function santri_keuangan_ringkasan_exit(PDO $pdo, int $santriId, bool $includeDetail = true): array
{
    ensure_santri_keluar_columns($pdo);
    $empty = [
        'santri_id' => $santriId,
        'total_sisa_tagihan' => 0,
        'cashless_saldo' => 0,
        'sisa_uang_cashless' => 0,
        'outstanding_count' => 0,
        'outstanding_rows' => [],
        'periode_mulai' => 0,
        'periode_selesai' => 0,
        'proyeksi_cashless_ke_tagihan' => 0,
        'proyeksi_sisa_tagihan_setelah_cashless' => 0,
        'proyeksi_sisa_uang_setelah_tagihan' => 0,
        'nominal_harus_dibayar' => 0,
        'nominal_harus_dikembalikan' => 0,
        'ada_sisa' => false,
        'keluar_settled_at' => null,
        'keluar_ringkasan_keuangan' => '',
        'status_label' => 'tidak_ditemukan',
        'badge_label' => '',
        'badge_variant' => 'secondary',
    ];
    if ($santriId <= 0 || !table_exists($pdo, 'santri')) {
        return $empty;
    }
    $st = $pdo->prepare('SELECT * FROM santri WHERE id = :id LIMIT 1');
    $st->execute(['id' => $santriId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return $empty;
    }

    $ta = santri_keluar_periode_ta($pdo);
    $kelas = santri_kelas_kategori_from_row($row);
    $outstanding = santri_outstanding_bulanan_rows($pdo, $santriId, $kelas, $ta['mulai'], $ta['selesai']);
    $totalSisa = 0;
    foreach ($outstanding as $o) {
        $totalSisa += (int) ($o['sisa'] ?? 0);
    }
    $cashless = santri_cashless_balance($pdo, $santriId);
    $settledAt = trim((string) ($row['keluar_settled_at'] ?? ''));
    $ringkasan = trim((string) ($row['keluar_ringkasan_keuangan'] ?? ''));
    $adaSisa = $totalSisa > 0 || $cashless > 0;

    if ($settledAt !== '') {
        $status = 'selesai';
        $badge = 'Keuangan selesai';
        $variant = 'success';
    } elseif ($totalSisa > 0) {
        $status = 'menunggu_administrasi';
        $badge = 'Tunggakan';
        $variant = 'warning';
    } elseif ($cashless > 0) {
        $status = 'lunas_belum_settle';
        $badge = 'Saldo cashless';
        $variant = 'info';
    } else {
        $status = 'lunas_belum_settle';
        $badge = 'Belum administrasi keluar';
        $variant = 'secondary';
    }

    $proyeksiCashless = min($cashless, $totalSisa);
    $proyeksiSisaTagihan = max(0, $totalSisa - $cashless);
    $proyeksiSisaUang = max(0, $cashless - $totalSisa);

    return [
        'santri_id' => $santriId,
        'total_sisa_tagihan' => $totalSisa,
        'cashless_saldo' => $cashless,
        'sisa_uang_cashless' => $cashless,
        'outstanding_count' => count($outstanding),
        'outstanding_rows' => $includeDetail ? $outstanding : [],
        'periode_mulai' => (int) $ta['mulai'],
        'periode_selesai' => (int) $ta['selesai'],
        'proyeksi_cashless_ke_tagihan' => $proyeksiCashless,
        'proyeksi_sisa_tagihan_setelah_cashless' => $proyeksiSisaTagihan,
        'proyeksi_sisa_uang_setelah_tagihan' => $proyeksiSisaUang,
        'nominal_harus_dibayar' => $totalSisa,
        'nominal_harus_dikembalikan' => $proyeksiSisaUang,
        'ada_sisa' => $adaSisa,
        'keluar_settled_at' => $settledAt !== '' ? $settledAt : null,
        'keluar_ringkasan_keuangan' => $ringkasan,
        'status_label' => $status,
        'badge_label' => $badge,
        'badge_variant' => $variant,
    ];
}

function mukimin_santri_id_by_nis(PDO $pdo, string $nis): int
{
    $nis = trim($nis);
    if ($nis === '' || !table_exists($pdo, 'santri')) {
        return 0;
    }
    ensure_santri_identity_columns($pdo);
    $st = $pdo->prepare('SELECT id FROM santri WHERE nis = :nis ORDER BY id DESC LIMIT 1');
    $st->execute(['nis' => $nis]);
    $id = (int) ($st->fetchColumn() ?: 0);

    return $id > 0 ? $id : 0;
}

/**
 * Ringkasan keuangan keluar per NIS (untuk daftar mukimin).
 *
 * @param list<string> $nisList
 * @return array<string, array<string, mixed>>
 */
function santri_keuangan_ringkasan_by_nis_batch(PDO $pdo, array $nisList): array
{
    $out = [];
    $uniq = [];
    foreach ($nisList as $nis) {
        $n = trim((string) $nis);
        if ($n !== '') {
            $uniq[$n] = true;
        }
    }
    if ($uniq === [] || !table_exists($pdo, 'santri')) {
        return $out;
    }
    $keys = array_keys($uniq);
    if (count($keys) > 80) {
        $keys = array_slice($keys, 0, 80);
    }
    $placeholders = implode(',', array_fill(0, count($keys), '?'));
    $st = $pdo->prepare('SELECT id, nis FROM santri WHERE nis IN (' . $placeholders . ')');
    $st->execute($keys);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $nis = trim((string) ($r['nis'] ?? ''));
        $sid = (int) ($r['id'] ?? 0);
        if ($nis === '' || $sid <= 0) {
            continue;
        }
        $out[$nis] = santri_keuangan_ringkasan_exit($pdo, $sid, false);
    }

    return $out;
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
