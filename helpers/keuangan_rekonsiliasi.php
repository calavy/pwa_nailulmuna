<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/**
 * Klasifikasi sumber pemasukan: donasi/infaq vs lain-lain.
 */
function keuangan_pemasukan_kategori_sumber(string $sumber): string
{
    $s = strtolower(trim($sumber));
    if ($s === '') {
        return 'lain';
    }
    if (preg_match('/\b(donasi|infaq|infak|sedekah|wakaf|hibah|zakat|sumbangan|bantuan)\b/u', $s)) {
        return 'donasi';
    }

    return 'lain';
}

/**
 * @return list<array{tipe:string,id:int,tanggal:string,nominal:int,keterangan:string}>
 */
function keuangan_rekonsiliasi_transaksi_tanpa_jurnal(PDO $pdo, string $dateFrom, string $dateTo): array
{
    if (!table_exists($pdo, 'akuntansi_jurnal_umum')) {
        return [];
    }

    $pending = [];

    if (table_exists($pdo, 'keuangan_pembayaran')) {
        $st = $pdo->prepare("
            SELECT p.id, p.tanggal_bayar AS tanggal, p.total_nominal AS nominal, p.keterangan
            FROM keuangan_pembayaran p
            WHERE p.tanggal_bayar BETWEEN :dari AND :sampai
              AND NOT EXISTS (
                  SELECT 1 FROM akuntansi_jurnal_umum j
                  WHERE j.ref_type = 'pembayaran' AND j.ref_id = p.id
              )
            ORDER BY p.tanggal_bayar ASC, p.id ASC
            LIMIT 50
        ");
        $st->execute(['dari' => $dateFrom, 'sampai' => $dateTo]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pending[] = [
                'tipe' => 'pembayaran',
                'id' => (int) ($row['id'] ?? 0),
                'tanggal' => (string) ($row['tanggal'] ?? ''),
                'nominal' => (int) round((float) ($row['nominal'] ?? 0)),
                'keterangan' => trim((string) ($row['keterangan'] ?? '')) !== ''
                    ? (string) $row['keterangan']
                    : 'Pembayaran santri #' . (int) ($row['id'] ?? 0),
            ];
        }
    }

    if (table_exists($pdo, 'keuangan_pemasukan')) {
        $st = $pdo->prepare("
            SELECT p.id, p.tanggal, p.nominal, p.sumber, p.dari_pihak
            FROM keuangan_pemasukan p
            WHERE p.tanggal BETWEEN :dari AND :sampai
              AND NOT EXISTS (
                  SELECT 1 FROM akuntansi_jurnal_umum j
                  WHERE j.ref_type = 'pemasukan' AND j.ref_id = p.id
              )
            ORDER BY p.tanggal ASC, p.id ASC
            LIMIT 50
        ");
        $st->execute(['dari' => $dateFrom, 'sampai' => $dateTo]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pending[] = [
                'tipe' => 'pemasukan',
                'id' => (int) ($row['id'] ?? 0),
                'tanggal' => (string) ($row['tanggal'] ?? ''),
                'nominal' => (int) round((float) ($row['nominal'] ?? 0)),
                'keterangan' => trim((string) ($row['sumber'] ?? ''))
                    . (trim((string) ($row['dari_pihak'] ?? '')) !== '' ? ' — ' . $row['dari_pihak'] : ''),
            ];
        }
    }

    if (table_exists($pdo, 'keuangan_pengeluaran')) {
        $st = $pdo->prepare("
            SELECT p.id, p.tanggal, p.nominal, p.pos, p.keterangan
            FROM keuangan_pengeluaran p
            WHERE p.tanggal BETWEEN :dari AND :sampai
              AND (p.pos IS NULL OR p.pos NOT LIKE 'Belanja Modal%')
              AND NOT EXISTS (
                  SELECT 1 FROM akuntansi_jurnal_umum j
                  WHERE j.ref_type = 'pengeluaran' AND j.ref_id = p.id
              )
            ORDER BY p.tanggal ASC, p.id ASC
            LIMIT 50
        ");
        $st->execute(['dari' => $dateFrom, 'sampai' => $dateTo]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $pending[] = [
                'tipe' => 'pengeluaran',
                'id' => (int) ($row['id'] ?? 0),
                'tanggal' => (string) ($row['tanggal'] ?? ''),
                'nominal' => (int) round((float) ($row['nominal'] ?? 0)),
                'keterangan' => trim((string) ($row['pos'] ?? ''))
                    . (trim((string) ($row['keterangan'] ?? '')) !== '' ? ' — ' . $row['keterangan'] : ''),
            ];
        }
    }

    return $pending;
}

/**
 * Mutasi kas operasional per rentang tanggal (masuk/keluar terklasifikasi).
 *
 * @return array{
 *   masuk_iuran:int,
 *   masuk_saku:int,
 *   masuk_donasi:int,
 *   masuk_lain:int,
 *   masuk_total:int,
 *   keluar:int,
 *   bersih:int
 * }
 */
function keuangan_rekap_kas_mutasi_periode(PDO $pdo, string $dateFrom, string $dateTo): array
{
    if ($dateFrom > $dateTo) {
        [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
    }

    $totalIuran = 0;
    $totalSaku = 0;
    $totalDonasi = 0;
    $totalPemasukanLain = 0;
    $totalKeluar = 0;

    if (table_exists($pdo, 'keuangan_pembayaran_detail')) {
        $st = $pdo->prepare("
            SELECT LOWER(TRIM(d.pos_slug)) AS slug, COALESCE(SUM(d.nominal), 0) AS total
            FROM keuangan_pembayaran_detail d
            INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
            WHERE p.tanggal_bayar BETWEEN :dari AND :sampai
            GROUP BY LOWER(TRIM(d.pos_slug))
        ");
        $st->execute(['dari' => $dateFrom, 'sampai' => $dateTo]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $nom = (int) round((float) ($row['total'] ?? 0));
            if ($nom === 0) {
                continue;
            }
            if ((string) ($row['slug'] ?? '') === 'saku') {
                $totalSaku += $nom;
            } else {
                $totalIuran += $nom;
            }
        }
    } elseif (table_exists($pdo, 'keuangan_pembayaran')) {
        $st = $pdo->prepare('
            SELECT COALESCE(SUM(total_nominal), 0) FROM keuangan_pembayaran
            WHERE tanggal_bayar BETWEEN :dari AND :sampai
        ');
        $st->execute(['dari' => $dateFrom, 'sampai' => $dateTo]);
        $totalIuran = (int) round((float) ($st->fetchColumn() ?: 0));
    }

    if (table_exists($pdo, 'keuangan_pemasukan')) {
        $st = $pdo->prepare('
            SELECT sumber, COALESCE(SUM(nominal), 0) AS total
            FROM keuangan_pemasukan
            WHERE tanggal BETWEEN :dari AND :sampai
            GROUP BY sumber
        ');
        $st->execute(['dari' => $dateFrom, 'sampai' => $dateTo]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $nom = (int) round((float) ($row['total'] ?? 0));
            if ($nom === 0) {
                continue;
            }
            if (keuangan_pemasukan_kategori_sumber((string) ($row['sumber'] ?? '')) === 'donasi') {
                $totalDonasi += $nom;
            } else {
                $totalPemasukanLain += $nom;
            }
        }
    }

    $stKeluar = $pdo->prepare("
        SELECT COALESCE(SUM(nominal), 0) FROM keuangan_pengeluaran
        WHERE tanggal BETWEEN :dari AND :sampai
          AND NOT (
              LOWER(COALESCE(pos, '')) LIKE '%aset%'
              OR LOWER(COALESCE(pos, '')) LIKE '%invent%'
              OR LOWER(COALESCE(pos, '')) LIKE '%invest%'
              OR pos LIKE 'Belanja Modal%'
          )
    ");
    $stKeluar->execute(['dari' => $dateFrom, 'sampai' => $dateTo]);
    $totalKeluar = (int) round((float) ($stKeluar->fetchColumn() ?: 0));

    if (table_exists($pdo, 'keuangan_gaji_pembimbing')) {
        $stGaji = $pdo->prepare('
            SELECT COALESCE(SUM(total_bayar), 0) FROM keuangan_gaji_pembimbing
            WHERE tanggal_bayar BETWEEN :dari AND :sampai
        ');
        $stGaji->execute(['dari' => $dateFrom, 'sampai' => $dateTo]);
        $totalKeluar += (int) round((float) ($stGaji->fetchColumn() ?: 0));
    }

    $totalMasuk = $totalIuran + $totalSaku + $totalDonasi + $totalPemasukanLain;

    return [
        'masuk_iuran' => $totalIuran,
        'masuk_saku' => $totalSaku,
        'masuk_donasi' => $totalDonasi,
        'masuk_lain' => $totalPemasukanLain,
        'masuk_total' => $totalMasuk,
        'keluar' => $totalKeluar,
        'bersih' => $totalMasuk - $totalKeluar,
    ];
}

/**
 * Ringkasan sinkronisasi kas fisik vs arus operasional.
 *
 * @return array{
 *   total_iuran:int,
 *   total_titipan_saku:int,
 *   total_donasi:int,
 *   total_pemasukan_lain:int,
 *   total_masuk:int,
 *   total_keluar:int,
 *   kas_bersih_operasi:int,
 *   kas_awal:int,
 *   kas_akhir:int,
 *   kas_delta_fisik:int,
 *   selisih:int,
 *   transaksi_tanpa_jurnal:list<array{tipe:string,id:int,tanggal:string,nominal:int,keterangan:string}>
 * }
 */
function keuangan_rekonsiliasi_kas_ringkas(
    PDO $pdo,
    string $dateFrom,
    string $dateTo,
    int $kasAwal,
    int $kasAkhir
): array {
    $mutasi = keuangan_rekap_kas_mutasi_periode($pdo, $dateFrom, $dateTo);
    $kasBersihFormula = (int) $mutasi['bersih'];
    $kasDeltaFisik = $kasAkhir - $kasAwal;

    return [
        'total_iuran' => (int) $mutasi['masuk_iuran'],
        'total_titipan_saku' => (int) $mutasi['masuk_saku'],
        'total_donasi' => (int) $mutasi['masuk_donasi'],
        'total_pemasukan_lain' => (int) $mutasi['masuk_lain'],
        'total_masuk' => (int) $mutasi['masuk_total'],
        'total_keluar' => (int) $mutasi['keluar'],
        'kas_bersih_operasi' => $kasBersihFormula,
        'kas_awal' => $kasAwal,
        'kas_akhir' => $kasAkhir,
        'kas_delta_fisik' => $kasDeltaFisik,
        'selisih' => $kasDeltaFisik - $kasBersihFormula,
        'transaksi_tanpa_jurnal' => keuangan_rekonsiliasi_transaksi_tanpa_jurnal($pdo, $dateFrom, $dateTo),
    ];
}
