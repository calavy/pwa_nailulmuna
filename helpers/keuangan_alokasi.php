<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_transaksi.php';

const KEUNGAN_ALOKASI_JENIS_SYAHRIYAH = 'SYAHRIYAH';
const KEUNGAN_ALOKASI_JENIS_AWAL_TAHUN = 'AWAL_TAHUN';

/** @return list<string> */
function keuangan_alokasi_jenis_valid(): array
{
    return [KEUNGAN_ALOKASI_JENIS_SYAHRIYAH, KEUNGAN_ALOKASI_JENIS_AWAL_TAHUN];
}

function keuangan_alokasi_normalize_jenis(string $jenis): string
{
    $j = strtoupper(trim($jenis));

    return in_array($j, keuangan_alokasi_jenis_valid(), true) ? $j : KEUNGAN_ALOKASI_JENIS_SYAHRIYAH;
}

function keuangan_alokasi_label_jenis(string $jenis): string
{
    return keuangan_alokasi_normalize_jenis($jenis) === KEUNGAN_ALOKASI_JENIS_AWAL_TAHUN
        ? 'dana awal tahun'
        : 'syahriyah';
}

function ensure_keuangan_alokasi_jenis_dana(PDO $pdo): void
{
    if (!table_exists($pdo, 'keuangan_alokasi')) {
        return;
    }
    if (!column_exists($pdo, 'keuangan_alokasi', 'jenis_dana')) {
        $pdo->exec("
            ALTER TABLE keuangan_alokasi
            ADD COLUMN jenis_dana ENUM('SYAHRIYAH','AWAL_TAHUN') NOT NULL DEFAULT 'SYAHRIYAH'
            AFTER kategori
        ");
    }
    keuangan_seed_alokasi_awal_tahun_default($pdo);
}

function keuangan_seed_alokasi_awal_tahun_default(PDO $pdo): void
{
    if (!table_exists($pdo, 'keuangan_alokasi') || !column_exists($pdo, 'keuangan_alokasi', 'jenis_dana')) {
        return;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM keuangan_alokasi WHERE jenis_dana = :jenis');
    $stmt->execute(['jenis' => KEUNGAN_ALOKASI_JENIS_AWAL_TAHUN]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }
    $defaults = [
        ['Dana bangunan & sarpras', 'Sarpras', 30, 1],
        ['Seragam & perlengkapan santri', 'Perlengkapan', 25, 2],
        ['Pendaftaran & administrasi', 'Administrasi', 15, 3],
        ['LKS, HIS, raport & kartu', 'Pendidikan', 15, 4],
        ['Koperasi & cadangan operasional', 'Operasional', 15, 5],
    ];
    $ins = $pdo->prepare('
        INSERT INTO keuangan_alokasi (nama_komponen, kategori, jenis_dana, persen, urutan, is_active)
        VALUES (:nama, :kat, :jenis, :persen, :urutan, 1)
    ');
    foreach ($defaults as [$nama, $kat, $persen, $urutan]) {
        $ins->execute([
            'nama' => $nama,
            'kat' => $kat,
            'jenis' => KEUNGAN_ALOKASI_JENIS_AWAL_TAHUN,
            'persen' => $persen,
            'urutan' => $urutan,
        ]);
    }
}

/** Total persentase alokasi aktif per jenis dana. */
function keuangan_alokasi_sum_persen_aktif(PDO $pdo, int $excludeId = 0, string $jenisDana = KEUNGAN_ALOKASI_JENIS_SYAHRIYAH): float
{
    if (!table_exists($pdo, 'keuangan_alokasi')) {
        return 0.0;
    }
    ensure_keuangan_alokasi_jenis_dana($pdo);
    $jenisDana = keuangan_alokasi_normalize_jenis($jenisDana);

    $sql = 'SELECT COALESCE(SUM(persen), 0) FROM keuangan_alokasi WHERE is_active = 1 AND jenis_dana = :jenis';
    $params = ['jenis' => $jenisDana];
    if ($excludeId > 0) {
        $sql .= ' AND id <> :exclude';
        $params['exclude'] = $excludeId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return (float) ($stmt->fetchColumn() ?: 0);
}

/**
 * Validasi total persen aktif tidak melebihi 100% (per jenis dana).
 *
 * @return array{ok:bool,message:string,total:float}
 */
function keuangan_alokasi_validate_persen(
    PDO $pdo,
    float $persenBaru,
    int $alokasiId,
    bool $akanAktif,
    string $jenisDana = KEUNGAN_ALOKASI_JENIS_SYAHRIYAH
): array {
    $jenisDana = keuangan_alokasi_normalize_jenis($jenisDana);
    $lain = keuangan_alokasi_sum_persen_aktif($pdo, $alokasiId > 0 ? $alokasiId : 0, $jenisDana);
    $total = $lain + ($akanAktif ? max(0.0, $persenBaru) : 0.0);
    if ($total > 100.0001) {
        $label = keuangan_alokasi_label_jenis($jenisDana);

        return [
            'ok' => false,
            'message' => 'Total alokasi ' . $label . ' aktif menjadi ' . round($total, 2) . '% — tidak boleh melebihi 100%. Kurangi persen komponen lain.',
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

/** Realisasi pembayaran santri periode awal tahun (semua komponen) pada TA aktif. */
function keuangan_awal_tahun_realisasi_ta(PDO $pdo, ?int $tahunMulai = null, ?int $tahunSelesai = null): int
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
        WHERE p.jenis_periode = 'AWAL_TAHUN'
          AND p.tahun_ajaran_mulai = :mulai
          AND p.tahun_ajaran_selesai = :selesai
    ");
    $stmt->execute(['mulai' => $mulai, 'selesai' => $selesai]);

    return (int) round((float) ($stmt->fetchColumn() ?: 0));
}

function keuangan_alokasi_realisasi_ta(PDO $pdo, string $jenisDana, ?int $tahunMulai = null, ?int $tahunSelesai = null): int
{
    return keuangan_alokasi_normalize_jenis($jenisDana) === KEUNGAN_ALOKASI_JENIS_AWAL_TAHUN
        ? keuangan_awal_tahun_realisasi_ta($pdo, $tahunMulai, $tahunSelesai)
        : keuangan_syahriyah_realisasi_ta($pdo, $tahunMulai, $tahunSelesai);
}

/**
 * Simulasi pembagian dana berdasarkan persen (what-if).
 *
 * @param array<int, float> $persenMap id alokasi => persen simulasi
 * @return array{
 *   ok:bool,
 *   jenis_dana:string,
 *   total_persen:float,
 *   sisa_persen:float,
 *   realisasi:int,
 *   baris:list<array{id:int,nama:string,kategori:string,persen:float,nominal:int}>,
 *   message:string
 * }
 */
function keuangan_alokasi_simulasi(PDO $pdo, array $persenMap = [], string $jenisDana = KEUNGAN_ALOKASI_JENIS_SYAHRIYAH): array
{
    $jenisDana = keuangan_alokasi_normalize_jenis($jenisDana);
    $rows = keuangan_fetch_alokasi_aktif($pdo, $jenisDana);
    $realisasi = keuangan_alokasi_realisasi_ta($pdo, $jenisDana);
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
        'jenis_dana' => $jenisDana,
        'total_persen' => round($totalPersen, 2),
        'sisa_persen' => round(max(0.0, 100 - $totalPersen), 2),
        'realisasi' => $realisasi,
        'baris' => $baris,
        'message' => $message,
    ];
}

/** @param list<array<string, mixed>> $alokasiRows */
function keuangan_alokasi_rows_for_jenis(array $alokasiRows, string $jenisDana): array
{
    $jenisDana = keuangan_alokasi_normalize_jenis($jenisDana);

    return array_values(array_filter(
        $alokasiRows,
        static fn(array $row): bool => keuangan_alokasi_normalize_jenis((string) ($row['jenis_dana'] ?? KEUNGAN_ALOKASI_JENIS_SYAHRIYAH)) === $jenisDana
    ));
}

/** @param array<string, mixed>|null $editAlokasi */
function keuangan_alokasi_edit_for_jenis(?array $editAlokasi, string $jenisDana): ?array
{
    if ($editAlokasi === null) {
        return null;
    }

    return keuangan_alokasi_normalize_jenis((string) ($editAlokasi['jenis_dana'] ?? KEUNGAN_ALOKASI_JENIS_SYAHRIYAH))
        === keuangan_alokasi_normalize_jenis($jenisDana)
        ? $editAlokasi
        : null;
}

function keuangan_alokasi_section_for_jenis(string $jenisDana): string
{
    return keuangan_alokasi_normalize_jenis($jenisDana) === KEUNGAN_ALOKASI_JENIS_AWAL_TAHUN
        ? 'alokasi_awal'
        : 'alokasi';
}
