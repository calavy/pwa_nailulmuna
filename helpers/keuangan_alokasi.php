<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/pondok_ta.php';

const KEUNGAN_ALOKASI_JENIS_SYAHRIYAH = 'SYAHRIYAH';
const KEUNGAN_ALOKASI_JENIS_AWAL_TAHUN = 'AWAL_TAHUN';
const KEUNGAN_ALOKASI_JENIS_MAKAN = 'MAKAN';

/** @return list<string> */
function keuangan_alokasi_jenis_valid(): array
{
    return [KEUNGAN_ALOKASI_JENIS_SYAHRIYAH, KEUNGAN_ALOKASI_JENIS_AWAL_TAHUN, KEUNGAN_ALOKASI_JENIS_MAKAN];
}

function keuangan_alokasi_normalize_jenis(string $jenis): string
{
    $j = strtoupper(trim($jenis));

    return in_array($j, keuangan_alokasi_jenis_valid(), true) ? $j : KEUNGAN_ALOKASI_JENIS_SYAHRIYAH;
}

function keuangan_alokasi_label_jenis(string $jenis): string
{
    return match (keuangan_alokasi_normalize_jenis($jenis)) {
        KEUNGAN_ALOKASI_JENIS_AWAL_TAHUN => 'dana awal tahun',
        KEUNGAN_ALOKASI_JENIS_MAKAN => 'dana makan',
        default => 'syahriyah',
    };
}

function ensure_keuangan_alokasi_jenis_dana(PDO $pdo): void
{
    if (!empty($_SESSION['keuangan_schema_ready_v1'])) {
        return;
    }
    if (!table_exists($pdo, 'keuangan_alokasi')) {
        return;
    }
    if (!column_exists($pdo, 'keuangan_alokasi', 'jenis_dana')) {
        $pdo->exec("
            ALTER TABLE keuangan_alokasi
            ADD COLUMN jenis_dana ENUM('SYAHRIYAH','AWAL_TAHUN','MAKAN') NOT NULL DEFAULT 'SYAHRIYAH'
            AFTER kategori
        ");
    } else {
        try {
            $pdo->exec("
                ALTER TABLE keuangan_alokasi
                MODIFY COLUMN jenis_dana ENUM('SYAHRIYAH','AWAL_TAHUN','MAKAN') NOT NULL DEFAULT 'SYAHRIYAH'
            ");
        } catch (PDOException $e) {
            /* abaikan jika ENUM sudah mendukung MAKAN */
        }
    }
    keuangan_seed_alokasi_awal_tahun_default($pdo);
    keuangan_seed_alokasi_makan_default($pdo);
}

function keuangan_seed_alokasi_makan_default(PDO $pdo): void
{
    if (!table_exists($pdo, 'keuangan_alokasi') || !column_exists($pdo, 'keuangan_alokasi', 'jenis_dana')) {
        return;
    }
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM keuangan_alokasi WHERE jenis_dana = :jenis');
    $stmt->execute(['jenis' => KEUNGAN_ALOKASI_JENIS_MAKAN]);
    if ((int) $stmt->fetchColumn() > 0) {
        return;
    }
    $defaults = [
        ['Bahan baku & konsumsi dapur', 'Bahan', 55, 1],
        ['Gaji karyawan dapur', 'Gaji', 25, 2],
        ['Operasional dapur', 'Operasional', 20, 3],
    ];
    $ins = $pdo->prepare('
        INSERT INTO keuangan_alokasi (nama_komponen, kategori, jenis_dana, persen, urutan, is_active)
        VALUES (:nama, :kat, :jenis, :persen, :urutan, 1)
    ');
    foreach ($defaults as [$nama, $kat, $persen, $urutan]) {
        $ins->execute([
            'nama' => $nama,
            'kat' => $kat,
            'jenis' => KEUNGAN_ALOKASI_JENIS_MAKAN,
            'persen' => $persen,
            'urutan' => $urutan,
        ]);
    }
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
/** @return list<array<string, mixed>> */
function keuangan_fetch_alokasi_aktif(PDO $pdo, ?string $jenisDana = null): array
{
    if (!table_exists($pdo, 'keuangan_alokasi')) {
        return [];
    }

    $sql = '
        SELECT id, nama_komponen, kategori, jenis_dana, persen, urutan
        FROM keuangan_alokasi
        WHERE is_active = 1
    ';
    $params = [];
    if ($jenisDana !== null && $jenisDana !== '') {
        $j = strtoupper(trim($jenisDana));
        if (!in_array($j, keuangan_alokasi_jenis_valid(), true)) {
            $j = 'SYAHRIYAH';
        }
        $sql .= ' AND jenis_dana = :jenis';
        $params['jenis'] = $j;
    }
    $sql .= ' ORDER BY urutan ASC, id ASC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

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

/**
 * Cache realisasi alokasi per TA (60 detik) — halaman pengaturan memanggil simulasi + realisasi berkali-kali.
 */
function keuangan_alokasi_realisasi_cached(
    PDO $pdo,
    string $jenisKey,
    int $mulai,
    int $selesai,
    callable $compute
): int {
    if (!isset($_SESSION['user'])) {
        return (int) $compute();
    }
    $cacheKey = 'keu_alokasi_real_' . $jenisKey . '_' . $mulai . '_' . $selesai;
    $cached = $_SESSION[$cacheKey] ?? null;
    if (is_array($cached) && (int) ($cached['expires'] ?? 0) > time()) {
        return (int) ($cached['value'] ?? 0);
    }
    $value = (int) $compute();
    $_SESSION[$cacheKey] = ['expires' => time() + 60, 'value' => $value];

    return $value;
}

/** Realisasi pembayaran pos syahriyah (bulanan) pada tahun ajaran aktif. */
function keuangan_syahriyah_realisasi_ta(PDO $pdo, ?int $tahunMulai = null, ?int $tahunSelesai = null): int
{
    if (!table_exists($pdo, 'keuangan_pembayaran') || !table_exists($pdo, 'keuangan_pembayaran_detail')) {
        return 0;
    }
    $periode = pondok_tahun_ajaran_aktif($pdo);
    $mulai = $tahunMulai ?? $periode['mulai'];
    $selesai = $tahunSelesai ?? $periode['selesai'];

    return keuangan_alokasi_realisasi_cached($pdo, 'syahriyah', $mulai, $selesai, static function () use ($pdo, $mulai, $selesai): int {
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
    });
}

/**
 * Realisasi bagian dana umum syahriyah (PKPPS + tambahan kelas) pada TA aktif.
 * Disinkronkan dengan pembagian di laporan alokasi per santri.
 */
function keuangan_syahriyah_realisasi_umum_ta(PDO $pdo, ?int $tahunMulai = null, ?int $tahunSelesai = null): int
{
    $total = keuangan_syahriyah_realisasi_ta($pdo, $tahunMulai, $tahunSelesai);
    if ($total <= 0) {
        return 0;
    }

    return max(0, $total - keuangan_syahriyah_realisasi_dasar_ta($pdo, $tahunMulai, $tahunSelesai));
}

/** Realisasi syahriyah untuk pembagian % alokasi (setelah dana umum PKPPS/kelas). */
function keuangan_syahriyah_realisasi_dasar_ta(PDO $pdo, ?int $tahunMulai = null, ?int $tahunSelesai = null): int
{
    if (!table_exists($pdo, 'santri') || !table_exists($pdo, 'keuangan_pembayaran')) {
        return keuangan_syahriyah_realisasi_ta($pdo, $tahunMulai, $tahunSelesai);
    }
    if (!function_exists('keuangan_syahriyah_split_pembayaran_tambahan')) {
        require_once __DIR__ . '/keuangan_pkpps_syahriyah.php';
    }
    if (!function_exists('tagihan_paid_map_for_month')) {
        require_once __DIR__ . '/tagihan_bulanan.php';
    }
    if (!function_exists('pondok_bulan_slots_tahun_ajaran')) {
        require_once __DIR__ . '/pondok_kalender.php';
    }
    if (!function_exists('santri_sql_aktif_only')) {
        require_once __DIR__ . '/santri_operasional.php';
    }

    $periode = pondok_tahun_ajaran_aktif($pdo);
    $mulai = $tahunMulai ?? $periode['mulai'];
    $selesai = $tahunSelesai ?? $periode['selesai'];

    return keuangan_alokasi_realisasi_cached($pdo, 'syahriyah_dasar', $mulai, $selesai, static function () use ($pdo, $mulai, $selesai): int {
        $slots = pondok_bulan_slots_tahun_ajaran($pdo, $mulai, $selesai);
        $dasar = 0;
        $namaCol = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
        $aktif = santri_sql_aktif_only('s');
        $st = $pdo->query('SELECT s.id, s.kategori_kelas FROM santri s WHERE ' . $aktif);
        $santriRows = $st ? ($st->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

        foreach ($slots as $slot) {
            $bulan = (int) ($slot['bulan'] ?? 0);
            if ($bulan < 1 || $bulan > 12) {
                continue;
            }
            $paidMap = tagihan_paid_map_for_month($pdo, $bulan, $mulai, $selesai, ['syahriyah']);
            foreach ($santriRows as $s) {
                $sid = (int) ($s['id'] ?? 0);
                if ($sid <= 0) {
                    continue;
                }
                $bayar = (int) ($paidMap[$sid]['syahriyah'] ?? 0);
                if ($bayar <= 0) {
                    continue;
                }
                $split = keuangan_syahriyah_split_pembayaran_tambahan(
                    $pdo,
                    $sid,
                    trim((string) ($s['kategori_kelas'] ?? '')),
                    $bayar,
                    $bulan,
                    $mulai,
                    $selesai
                );
                $dasar += (int) ($split['dasar'] ?? $bayar);
            }
        }

        return $dasar;
    });
}

/**
 * Opsi alokasi untuk formulir pengeluaran (komponen % + dana umum PKPPS).
 *
 * @return list<array{value:string,label:string,group:string}>
 */
function keuangan_pengeluaran_alokasi_options(PDO $pdo): array
{
    $out = [];
    foreach (keuangan_fetch_alokasi_aktif($pdo, KEUNGAN_ALOKASI_JENIS_SYAHRIYAH) as $ar) {
        $nama = trim((string) ($ar['nama_komponen'] ?? ''));
        if ($nama === '') {
            continue;
        }
        $out[] = [
            'value' => $nama,
            'label' => $nama . ' (' . (string) ($ar['persen'] ?? '0') . '%)',
            'group' => 'Dana syahriyah (alokasi %)',
        ];
    }
    foreach (keuangan_fetch_alokasi_aktif($pdo, KEUNGAN_ALOKASI_JENIS_AWAL_TAHUN) as $ar) {
        $nama = trim((string) ($ar['nama_komponen'] ?? ''));
        if ($nama === '') {
            continue;
        }
        $out[] = [
            'value' => $nama,
            'label' => $nama . ' (' . (string) ($ar['persen'] ?? '0') . '%)',
            'group' => 'Dana awal tahun',
        ];
    }
    foreach (keuangan_fetch_alokasi_aktif($pdo, KEUNGAN_ALOKASI_JENIS_MAKAN) as $ar) {
        $nama = trim((string) ($ar['nama_komponen'] ?? ''));
        if ($nama === '') {
            continue;
        }
        $out[] = [
            'value' => $nama,
            'label' => $nama . ' (' . (string) ($ar['persen'] ?? '0') . '%)',
            'group' => 'Dana makan',
        ];
    }
    if (!function_exists('keuangan_pkpps_alokasi_komponen_nama')) {
        require_once __DIR__ . '/keuangan_pkpps_syahriyah.php';
    }
    // PKPPS dialokasikan ke komponen gaji — tidak lagi sebagai "Dana Umum" terpisah di dropdown pengeluaran.

    return $out;
}

/** Realisasi pembayaran santri periode awal tahun (semua komponen) pada TA aktif. */
function keuangan_awal_tahun_realisasi_ta(PDO $pdo, ?int $tahunMulai = null, ?int $tahunSelesai = null): int
{
    if (!table_exists($pdo, 'keuangan_pembayaran') || !table_exists($pdo, 'keuangan_pembayaran_detail')) {
        return 0;
    }
    $periode = pondok_tahun_ajaran_aktif($pdo);
    $mulai = $tahunMulai ?? $periode['mulai'];
    $selesai = $tahunSelesai ?? $periode['selesai'];

    return keuangan_alokasi_realisasi_cached($pdo, 'awal_tahun', $mulai, $selesai, static function () use ($pdo, $mulai, $selesai): int {
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
    });
}

/** Realisasi pembayaran pos makan (bulanan) pada tahun ajaran aktif. */
function keuangan_makan_realisasi_ta(PDO $pdo, ?int $tahunMulai = null, ?int $tahunSelesai = null): int
{
    if (!table_exists($pdo, 'keuangan_pembayaran') || !table_exists($pdo, 'keuangan_pembayaran_detail')) {
        return 0;
    }
    $periode = pondok_tahun_ajaran_aktif($pdo);
    $mulai = $tahunMulai ?? $periode['mulai'];
    $selesai = $tahunSelesai ?? $periode['selesai'];

    return keuangan_alokasi_realisasi_cached($pdo, 'makan', $mulai, $selesai, static function () use ($pdo, $mulai, $selesai): int {
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(d.nominal), 0)
            FROM keuangan_pembayaran_detail d
            INNER JOIN keuangan_pembayaran p ON p.id = d.pembayaran_id
            WHERE LOWER(TRIM(d.pos_slug)) = 'makan'
              AND p.jenis_periode = 'BULANAN'
              AND p.tahun_ajaran_mulai = :mulai
              AND p.tahun_ajaran_selesai = :selesai
        ");
        $stmt->execute(['mulai' => $mulai, 'selesai' => $selesai]);

        return (int) round((float) ($stmt->fetchColumn() ?: 0));
    });
}

function keuangan_alokasi_realisasi_ta(PDO $pdo, string $jenisDana, ?int $tahunMulai = null, ?int $tahunSelesai = null): int
{
    return match (keuangan_alokasi_normalize_jenis($jenisDana)) {
        KEUNGAN_ALOKASI_JENIS_AWAL_TAHUN => keuangan_awal_tahun_realisasi_ta($pdo, $tahunMulai, $tahunSelesai),
        KEUNGAN_ALOKASI_JENIS_MAKAN => keuangan_makan_realisasi_ta($pdo, $tahunMulai, $tahunSelesai),
        default => keuangan_syahriyah_realisasi_ta($pdo, $tahunMulai, $tahunSelesai),
    };
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
    $realisasi = match ($jenisDana) {
        KEUNGAN_ALOKASI_JENIS_SYAHRIYAH => keuangan_syahriyah_realisasi_dasar_ta($pdo),
        default => keuangan_alokasi_realisasi_ta($pdo, $jenisDana),
    };
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
    return match (keuangan_alokasi_normalize_jenis($jenisDana)) {
        KEUNGAN_ALOKASI_JENIS_AWAL_TAHUN => 'alokasi_awal',
        KEUNGAN_ALOKASI_JENIS_MAKAN => 'alokasi_makan',
        default => 'alokasi',
    };
}
