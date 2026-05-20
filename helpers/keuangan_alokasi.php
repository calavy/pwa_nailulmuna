<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_transaksi.php';

/** Total persentase alokasi aktif (opsional kecuali satu id). */
function keuangan_alokasi_sum_persen_aktif(PDO $pdo, int $excludeId = 0): float
{
    if (!table_exists($pdo, 'keuangan_alokasi')) {
        return 0.0;
    }
    $sql = 'SELECT COALESCE(SUM(persen), 0) FROM keuangan_alokasi WHERE is_active = 1';
    $params = [];
    if ($excludeId > 0) {
        $sql .= ' AND id <> :exclude';
        $params['exclude'] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (float) ($stmt->fetchColumn() ?: 0);
}

/**
 * Validasi total persen aktif tidak melebihi 100%.
 *
 * @return array{ok:bool,message:string,total:float}
 */
function keuangan_alokasi_validate_persen(PDO $pdo, float $persenBaru, int $alokasiId, bool $akanAktif): array
{
    $lain = keuangan_alokasi_sum_persen_aktif($pdo, $alokasiId > 0 ? $alokasiId : 0);
    $total = $lain + ($akanAktif ? max(0.0, $persenBaru) : 0.0);
    if ($total > 100.0001) {
        return [
            'ok' => false,
            'message' => 'Total alokasi aktif menjadi ' . round($total, 2) . '% — tidak boleh melebihi 100%. Kurangi persen komponen lain.',
            'total' => $total,
        ];
    }

    return ['ok' => true, 'message' => '', 'total' => $total];
}

/** Realisasi pembayaran pos syahriyah (bulanan) pada tahun ajaran aktif. */
function keuangan_syahriyah_realisasi_ta(PDO $pdo, ?int $tahunMulai = null, ?int $tahunSelesai = null): int
{
    if (!table_exists($pdo, 'keuangan_pembayaran') || !table_exists($pdo, 'keuangan_pembayaran_detail')) {
        return 0;
    }
    $periode = keuangan_tahun_ajaran_aktif($pdo);
    $mulai = $tahunMulai ?? $periode['mulai'];
    $selesai = $tahunSelesai ?? $periode['selesai'];

    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(d.nominal), 0)
        FROM keuangan_pembayaran_detail d
        INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
        WHERE LOWER(TRIM(d.pos_slug)) = 'syahriyah'
          AND p.jenis_periode = 'BULANAN'
          AND p.tahun_ajaran_mulai = :mulai
          AND p.tahun_ajaran_selesai = :selesai
    ");
    $stmt->execute(['mulai' => $mulai, 'selesai' => $selesai]);

    return (int) round((float) ($stmt->fetchColumn() ?: 0));
}

/**
 * Simulasi pembagian dana syahriyah riil berdasarkan persen (what-if).
 *
 * @param array<int, float> $persenMap id alokasi => persen simulasi
 * @return array{
 *   ok:bool,
 *   total_persen:float,
 *   sisa_persen:float,
 *   realisasi_syahriyah:int,
 *   baris:list<array{id:int,nama:string,kategori:string,persen:float,nominal:int}>,
 *   message:string
 * }
 */
function keuangan_alokasi_simulasi(PDO $pdo, array $persenMap = []): array
{
    $rows = keuangan_fetch_alokasi_aktif($pdo);
    $realisasi = keuangan_syahriyah_realisasi_ta($pdo);
    $baris = [];
    $totalPersen = 0.0;

    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $persen = array_key_exists($id, $persenMap)
            ? (float) $persenMap[$id]
            : (float) ($row['persen'] ?? 0);
        $persen = max(0.0, $persen);
        $totalPersen += $persen;
        $nominal = $realisasi > 0 ? (int) floor($realisasi * $persen / 100) : 0;
        $baris[] = [
            'id' => $id,
            'nama' => (string) ($row['nama_komponen'] ?? ''),
            'kategori' => (string) ($row['kategori'] ?? ''),
            'persen' => round($persen, 2),
            'nominal' => $nominal,
        ];
    }

    $ok = $totalPersen <= 100.0001;
    $message = $ok
        ? ''
        : 'Total simulasi ' . round($totalPersen, 2) . '% melebihi 100%. Sesuaikan persentase sebelum menyimpan.';

    return [
        'ok' => $ok,
        'total_persen' => round($totalPersen, 2),
        'sisa_persen' => round(max(0.0, 100 - $totalPersen), 2),
        'realisasi_syahriyah' => $realisasi,
        'baris' => $baris,
        'message' => $message,
    ];
}
