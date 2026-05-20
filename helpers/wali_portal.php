<?php

declare(strict_types=1);

/** Salam sesuai jam (WIB server). */
function wali_portal_salam_waktu(): string
{
    $h = (int) date('G');
    if ($h >= 3 && $h < 11) {
        return 'Selamat pagi';
    }
    if ($h >= 11 && $h < 15) {
        return 'Selamat siang';
    }
    if ($h >= 15 && $h < 18) {
        return 'Selamat sore';
    }

    return 'Selamat malam';
}

/** Nama wali dari tabel wali_santri atau nama kafil di santri. */
function wali_portal_resolve_nama_wali(PDO $pdo, array $santriRow): string
{
    $wid = (int) ($santriRow['wali_santri_id'] ?? 0);
    if ($wid > 0 && table_exists($pdo, 'wali_santri')) {
        $st = $pdo->prepare('SELECT nama FROM wali_santri WHERE id = :id LIMIT 1');
        $st->execute(['id' => $wid]);
        $nama = trim((string) ($st->fetchColumn() ?: ''));
        if ($nama !== '') {
            return $nama;
        }
    }

    return trim((string) ($santriRow['nama_kafil'] ?? ''));
}

/**
 * @return array{salam:string,nama_wali:string,nama_anak:string,line:string,subline:string}
 */
function wali_portal_build_greeting(PDO $pdo, array $santriRow): array
{
    $salam = wali_portal_salam_waktu();
    $namaAnak = trim((string) ($santriRow['nama_tampil'] ?? $santriRow['nama_santri'] ?? ''));
    $namaWali = wali_portal_resolve_nama_wali($pdo, $santriRow);

    if ($namaWali !== '' && $namaAnak !== '') {
        $line = $salam . ', Bapak/Ibu ' . $namaWali . ' wali dari ' . $namaAnak . '.';
        $subline = 'Berikut ringkasan untuk putra/putri Anda.';
    } elseif ($namaAnak !== '') {
        $line = $salam . ', Bapak/Ibu wali santri ' . $namaAnak . '.';
        $subline = 'Berikut ringkasan untuk putra/putri Anda.';
    } else {
        $line = $salam . ', Bapak/Ibu wali santri.';
        $subline = 'Berikut ringkasan keuangan dan keaktifan.';
    }

    return [
        'salam' => $salam,
        'nama_wali' => $namaWali,
        'nama_anak' => $namaAnak,
        'line' => $line,
        'subline' => $subline,
    ];
}

function wali_portal_format_rupiah(int $nominal): string
{
    return 'Rp ' . number_format($nominal, 0, ',', '.');
}

/** Label periode pembayaran untuk tampilan wali. */
function wali_portal_label_periode(array $row): string
{
    $bulanMap = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April', 5 => 'Mei', 6 => 'Juni',
        7 => 'Juli', 8 => 'Agustus', 9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];
    $jenis = (string) ($row['jenis_periode'] ?? '');
    $bl = (int) ($row['bulan_tagihan'] ?? 0);
    $ta = (string) ($row['tahun_ajaran_mulai'] ?? '') . '/' . (string) ($row['tahun_ajaran_selesai'] ?? '');
    if ($jenis === 'BULANAN' && $bl >= 1 && $bl <= 12) {
        return ($bulanMap[$bl] ?? (string) $bl) . ' · TA ' . $ta;
    }
    if ($jenis === 'AWAL_TAHUN') {
        return 'Awal tahun · TA ' . $ta;
    }

    return trim($jenis . ($ta !== '/' ? ' · TA ' . $ta : ''));
}

/**
 * Daftar pembayaran santri + rincian POS.
 *
 * @return list<array<string,mixed>>
 */
function wali_portal_fetch_pembayaran_list(PDO $pdo, int $santriId, int $limit = 60): array
{
    if ($santriId <= 0 || !table_exists($pdo, 'keuangan_pembayaran')) {
        return [];
    }
    $limit = max(1, min(120, $limit));
    $detailOk = table_exists($pdo, 'keuangan_pembayaran_detail');

    $metodeCol = column_exists($pdo, 'keuangan_pembayaran', 'metode_bayar') ? 'p.metode_bayar' : "'KAS' AS metode_bayar";
    $refCol = column_exists($pdo, 'keuangan_pembayaran', 'no_referensi') ? 'p.no_referensi' : "'' AS no_referensi";

    $st = $pdo->prepare("
        SELECT p.id, p.jenis_periode, p.tahun_ajaran_mulai, p.tahun_ajaran_selesai, p.bulan_tagihan,
               p.tanggal_bayar, p.total_nominal, {$metodeCol}, {$refCol}, p.keterangan
        FROM keuangan_pembayaran p
        WHERE p.santri_id = :sid
        ORDER BY p.tanggal_bayar DESC, p.id DESC
        LIMIT {$limit}
    ");
    $st->execute(['sid' => $santriId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows === [] || !$detailOk) {
        foreach ($rows as &$r) {
            $r['details'] = [];
        }
        unset($r);

        return $rows;
    }

    $ids = array_map(static fn(array $r): int => (int) ($r['id'] ?? 0), $rows);
    $ids = array_values(array_filter($ids, static fn(int $v): bool => $v > 0));
    $detailMap = [];
    if ($ids !== []) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $det = $pdo->prepare("SELECT pembayaran_id, pos_slug, pos_nama, nominal FROM keuangan_pembayaran_detail WHERE pembayaran_id IN ($in) ORDER BY pembayaran_id ASC, id ASC");
        $det->execute($ids);
        foreach ($det->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $pid = (int) $d['pembayaran_id'];
            $detailMap[$pid][] = $d;
        }
    }

    foreach ($rows as &$r) {
        $pid = (int) ($r['id'] ?? 0);
        $r['details'] = $detailMap[$pid] ?? [];
        $r['periode_label'] = wali_portal_label_periode($r);
    }
    unset($r);

    return $rows;
}

/** Ringkasan total terbayar per komponen POS (tahun ajaran aktif opsional). */
function wali_portal_ringkasan_pos(PDO $pdo, int $santriId, ?int $tm = null, ?int $ts = null): array
{
    if ($santriId <= 0 || !table_exists($pdo, 'keuangan_pembayaran') || !table_exists($pdo, 'keuangan_pembayaran_detail')) {
        return [];
    }
    $sql = '
        SELECT d.pos_slug, d.pos_nama, COALESCE(SUM(d.nominal), 0) AS total
        FROM keuangan_pembayaran_detail d
        INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
        WHERE p.santri_id = :sid';
    $params = ['sid' => $santriId];
    if ($tm !== null && $ts !== null) {
        $sql .= ' AND p.tahun_ajaran_mulai = :tm AND p.tahun_ajaran_selesai = :ts';
        $params['tm'] = $tm;
        $params['ts'] = $ts;
    }
    $sql .= ' GROUP BY d.pos_slug, d.pos_nama ORDER BY d.pos_nama ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** Pastikan pembayaran milik santri sesi wali; kembalikan baris atau null. */
function wali_portal_fetch_pembayaran_for_wali(PDO $pdo, int $pembayaranId, int $santriId): ?array
{
    if ($pembayaranId <= 0 || $santriId <= 0 || !table_exists($pdo, 'keuangan_pembayaran')) {
        return null;
    }
    $nameCol = column_exists($pdo, 'santri', 'nama_santri') ? 's.nama_santri' : 's.nama';
    $st = $pdo->prepare("
        SELECT p.*, s.nis, {$nameCol} AS nama_santri, s.tingkatan
        FROM keuangan_pembayaran p
        INNER JOIN santri s ON s.id = p.santri_id
        WHERE p.id = :id AND p.santri_id = :sid
        LIMIT 1
    ");
    $st->execute(['id' => $pembayaranId, 'sid' => $santriId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

/** @return list<array<string, mixed>> */
function wali_portal_cashless_transactions(PDO $pdo, int $santriId, int $limit = 80): array
{
    if ($santriId <= 0 || !table_exists($pdo, 'cashless_transactions')) {
        return [];
    }
    $limit = max(10, min(200, $limit));
    $st = $pdo->prepare("
        SELECT id, tanggal, jenis, nominal, keterangan, ref_pembayaran_id
        FROM cashless_transactions
        WHERE santri_id = :sid
        ORDER BY tanggal DESC, id DESC
        LIMIT {$limit}
    ");
    $st->execute(['sid' => $santriId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function wali_portal_cashless_saldo(PDO $pdo, int $santriId): ?float
{
    if ($santriId <= 0 || !table_exists($pdo, 'cashless_accounts')) {
        return null;
    }
    $st = $pdo->prepare('SELECT balance FROM cashless_accounts WHERE santri_id = :id LIMIT 1');
    $st->execute(['id' => $santriId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return 0.0;
    }

    return (float) ($row['balance'] ?? 0);
}

/** Total belanja (DEBIT) hari ini untuk santri. */
function wali_portal_cashless_debit_hari_ini(PDO $pdo, int $santriId): int
{
    if ($santriId <= 0 || !table_exists($pdo, 'cashless_transactions')) {
        return 0;
    }
    $st = $pdo->prepare("
        SELECT COALESCE(SUM(nominal), 0)
        FROM cashless_transactions
        WHERE santri_id = :sid AND jenis = 'DEBIT' AND DATE(tanggal) = CURDATE()
    ");
    $st->execute(['sid' => $santriId]);

    return (int) round((float) ($st->fetchColumn() ?: 0));
}
