<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/santri_list_sort.php';
require_once __DIR__ . '/keuangan_transaksi.php';
require_once __DIR__ . '/keuangan_neraca.php';

function ensure_keuangan_syahriyah_potongan_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ensure_santri_identity_columns($pdo);
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS keuangan_santri_syahriyah_potongan (
            id INT AUTO_INCREMENT PRIMARY KEY,
            santri_id INT NOT NULL,
            persen DECIMAL(5,2) NOT NULL DEFAULT 0,
            keterangan VARCHAR(255) NOT NULL DEFAULT \'\',
            is_aktif TINYINT(1) NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_santri_potongan (santri_id),
            INDEX idx_potongan_aktif (is_aktif)
        )
    ');
    ensure_keuangan_syahriyah_potongan_jeda_table($pdo);
}

function ensure_keuangan_syahriyah_potongan_jeda_table(PDO $pdo): void
{
    if (table_exists($pdo, 'keuangan_syahriyah_potongan_jeda')) {
        return;
    }
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS keuangan_syahriyah_potongan_jeda (
            id INT AUTO_INCREMENT PRIMARY KEY,
            santri_id INT NOT NULL,
            tahun_ajaran_mulai INT NOT NULL,
            tahun_ajaran_selesai INT NOT NULL,
            bulan_tagihan TINYINT NOT NULL,
            keterangan VARCHAR(255) NOT NULL DEFAULT \'\',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_jeda_bulan (santri_id, tahun_ajaran_mulai, tahun_ajaran_selesai, bulan_tagihan),
            INDEX idx_jeda_santri (santri_id)
        )
    ');
}

/** Apakah potongan dihentikan (tarif penuh) untuk bulan tagihan tertentu? */
function keuangan_syahriyah_potongan_dijeda_untuk_bulan(
    PDO $pdo,
    int $santriId,
    int $bulanTagihan,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai
): bool {
    if ($santriId <= 0 || $bulanTagihan < 1 || $bulanTagihan > 12 || $tahunAjaranMulai <= 0) {
        return false;
    }
    ensure_keuangan_syahriyah_potongan_jeda_table($pdo);
    if (!table_exists($pdo, 'keuangan_syahriyah_potongan_jeda')) {
        return false;
    }
    $stmt = $pdo->prepare('
        SELECT 1 FROM keuangan_syahriyah_potongan_jeda
        WHERE santri_id = :sid
          AND tahun_ajaran_mulai = :tm
          AND tahun_ajaran_selesai = :ts
          AND bulan_tagihan = :bulan
        LIMIT 1
    ');
    $stmt->execute([
        'sid' => $santriId,
        'tm' => $tahunAjaranMulai,
        'ts' => max($tahunAjaranMulai, $tahunAjaranSelesai),
        'bulan' => $bulanTagihan,
    ]);

    return (bool) $stmt->fetchColumn();
}

/**
 * @return list<array<string, mixed>>
 */
function keuangan_syahriyah_potongan_jeda_list(PDO $pdo, int $santriId): array
{
    ensure_keuangan_syahriyah_potongan_jeda_table($pdo);
    if ($santriId <= 0 || !table_exists($pdo, 'keuangan_syahriyah_potongan_jeda')) {
        return [];
    }
    $stmt = $pdo->prepare('
        SELECT id, santri_id, tahun_ajaran_mulai, tahun_ajaran_selesai, bulan_tagihan, keterangan, created_at
        FROM keuangan_syahriyah_potongan_jeda
        WHERE santri_id = :sid
        ORDER BY tahun_ajaran_mulai DESC, bulan_tagihan DESC
    ');
    $stmt->execute(['sid' => $santriId]);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array{ok:bool,message:string}
 */
function keuangan_syahriyah_potongan_jeda_tambah(PDO $pdo, array $post): array
{
    ensure_keuangan_syahriyah_potongan_jeda_table($pdo);

    $santriId = (int) ($post['santri_id'] ?? 0);
    $bulan = (int) ($post['bulan_tagihan'] ?? 0);
    $tm = (int) ($post['tahun_ajaran_mulai'] ?? 0);
    $ts = (int) ($post['tahun_ajaran_selesai'] ?? 0);
    $ket = trim((string) ($post['keterangan_jeda'] ?? ''));

    if ($santriId <= 0) {
        return ['ok' => false, 'message' => 'Santri wajib dipilih.'];
    }
    if ($bulan < 1 || $bulan > 12) {
        return ['ok' => false, 'message' => 'Bulan tagihan tidak valid.'];
    }
    $taNorm = pondok_normalisasi_tahun_ajaran_input($pdo, $tm, $ts);
    if ($taNorm['mulai'] < pondok_ta_tahun_min($pdo)) {
        return ['ok' => false, 'message' => 'Tahun ajaran tidak valid.'];
    }

    $stmt = $pdo->prepare('
        INSERT INTO keuangan_syahriyah_potongan_jeda
            (santri_id, tahun_ajaran_mulai, tahun_ajaran_selesai, bulan_tagihan, keterangan)
        VALUES (:sid, :tm, :ts, :bulan, :ket)
        ON DUPLICATE KEY UPDATE keterangan = VALUES(keterangan)
    ');
    $stmt->execute([
        'sid' => $santriId,
        'tm' => $taNorm['mulai'],
        'ts' => $taNorm['selesai'],
        'bulan' => $bulan,
        'ket' => $ket !== '' ? $ket : 'Potongan dihentikan sementara',
    ]);
    $labelBulan = pondok_bulan_label($pdo, $bulan, $taNorm['mulai'], $taNorm['selesai']);

    return [
        'ok' => true,
        'message' => 'Potongan dihentikan untuk '
            . $labelBulan
            . ' · TA ' . pondok_tahun_ajaran_label($pdo, $taNorm)
            . ' (tagihan tarif penuh).',
    ];
}

/**
 * @return array{ok:bool,message:string}
 */
function keuangan_syahriyah_potongan_jeda_hapus(PDO $pdo, int $jedaId, int $santriId = 0): array
{
    ensure_keuangan_syahriyah_potongan_jeda_table($pdo);
    if ($jedaId <= 0) {
        return ['ok' => false, 'message' => 'Data jeda tidak valid.'];
    }
    $sql = 'DELETE FROM keuangan_syahriyah_potongan_jeda WHERE id = :id';
    $params = ['id' => $jedaId];
    if ($santriId > 0) {
        $sql .= ' AND santri_id = :sid';
        $params['sid'] = $santriId;
    }
    $pdo->prepare($sql)->execute($params);

    return ['ok' => true, 'message' => 'Jeda potongan dihapus; potongan aktif kembali untuk bulan tersebut.'];
}

/**
 * @return array{id:int,santri_id:int,persen:float,keterangan:string,is_aktif:int}|null
 */
function keuangan_syahriyah_potongan_for_santri(PDO $pdo, int $santriId): ?array
{
    if ($santriId <= 0) {
        return null;
    }
    ensure_keuangan_syahriyah_potongan_table($pdo);
    if (!table_exists($pdo, 'keuangan_santri_syahriyah_potongan')) {
        return null;
    }
    $stmt = $pdo->prepare('
        SELECT id, santri_id, persen, keterangan, is_aktif
        FROM keuangan_santri_syahriyah_potongan
        WHERE santri_id = :sid
        LIMIT 1
    ');
    $stmt->execute(['sid' => $santriId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return is_array($row) ? $row : null;
}

/** Tarif syahriyah dasar per tier (cache statis per request; opsional per bulan tagihan). */
function keuangan_syahriyah_tarif_cache_by_tier(
    PDO $pdo,
    int $bulanTagihan = 0,
    int $tahunAjaranMulai = 0,
    int $tahunAjaranSelesai = 0
): array {
    static $cache = [];
    $ts = max($tahunAjaranMulai, $tahunAjaranSelesai);
    $key = ($bulanTagihan >= 1 && $tahunAjaranMulai > 0)
        ? $tahunAjaranMulai . ':' . $ts . ':' . $bulanTagihan
        : '_default';
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    if (!function_exists('keuangan_tarif_bulanan_resolve')) {
        require_once __DIR__ . '/keuangan_tarif_bulanan.php';
    }
    $out = [];
    foreach (['muadalah', 'wustho', 'ulya'] as $tier) {
        $out[$tier] = keuangan_tarif_bulanan_resolve(
            $pdo,
            'syahriyah',
            $tier,
            $bulanTagihan,
            $tahunAjaranMulai,
            $tahunAjaranSelesai
        );
    }
    $cache[$key] = $out;

    return $out;
}

/** Tarif dasar syahriyah dari kategori kelas (pakai cache tier). */
function keuangan_syahriyah_dasar_for_kategori(PDO $pdo, string $kelasKategori, ?array $tarifByTier = null): int
{
    $tarifByTier ??= keuangan_syahriyah_tarif_cache_by_tier($pdo);
    $tier = keuangan_tier_key_from_kelas($kelasKategori, $pdo);

    return max(0, (int) ($tarifByTier[$tier] ?? 0));
}

/**
 * Muat potongan + jeda bulan ini sekali (untuk halaman daftar).
 *
 * @return array{
 *   potongan: array<int, array{persen:float,keterangan:string,is_aktif:int}>,
 *   jeda: array<int, bool>,
 *   tarifByTier: array<string, int>
 * }
 */
function keuangan_syahriyah_bulk_context(
    PDO $pdo,
    int $bulanTagihan = 0,
    int $tahunAjaranMulai = 0,
    int $tahunAjaranSelesai = 0
): array {
    static $requestCache = [];
    $cacheKey = $bulanTagihan . ':' . $tahunAjaranMulai . ':' . max($tahunAjaranMulai, $tahunAjaranSelesai);
    if (isset($requestCache[$cacheKey])) {
        return $requestCache[$cacheKey];
    }

    ensure_keuangan_syahriyah_potongan_table($pdo);

    $potongan = [];
    if (table_exists($pdo, 'keuangan_santri_syahriyah_potongan')) {
        foreach ($pdo->query('SELECT santri_id, persen, keterangan, is_aktif FROM keuangan_santri_syahriyah_potongan')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $sid = (int) ($row['santri_id'] ?? 0);
            if ($sid > 0) {
                $potongan[$sid] = $row;
            }
        }
    }

    $jeda = [];
    if (
        $bulanTagihan >= 1 && $bulanTagihan <= 12 && $tahunAjaranMulai > 0
        && table_exists($pdo, 'keuangan_syahriyah_potongan_jeda')
    ) {
        $ts = max($tahunAjaranMulai, $tahunAjaranSelesai);
        $stmt = $pdo->prepare('
            SELECT santri_id FROM keuangan_syahriyah_potongan_jeda
            WHERE tahun_ajaran_mulai = :tm AND tahun_ajaran_selesai = :ts AND bulan_tagihan = :bulan
        ');
        $stmt->execute(['tm' => $tahunAjaranMulai, 'ts' => $ts, 'bulan' => $bulanTagihan]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            $jeda[(int) $sid] = true;
        }
    }

    $requestCache[$cacheKey] = [
        'potongan' => $potongan,
        'potongan_preloaded' => true,
        'jeda' => $jeda,
        'jeda_preloaded' => $bulanTagihan >= 1 && $tahunAjaranMulai > 0,
        'tarifByTier' => keuangan_syahriyah_tarif_cache_by_tier($pdo, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai),
    ];

    return $requestCache[$cacheKey];
}

/**
 * Hitung simulasi tagihan (tanpa query per santri bila $ctx disediakan).
 *
 * @param array{potongan:array<int,array>,jeda:array<int,bool>,tarifByTier:array<string,int>}|null $ctx
 */
function keuangan_syahriyah_simulasi(
    PDO $pdo,
    int $santriId,
    string $kelasKategori,
    int $bulanTagihan = 0,
    int $tahunAjaranMulai = 0,
    int $tahunAjaranSelesai = 0,
    ?array $ctx = null
): array {
    if ($ctx === null) {
        $ctx = keuangan_syahriyah_bulk_context($pdo, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai);
    }
    if ($santriId > 0 && !isset($ctx['potongan'][$santriId]) && empty($ctx['potongan_preloaded'])) {
        $pot = keuangan_syahriyah_potongan_for_santri($pdo, $santriId);
        if ($pot) {
            $ctx['potongan'][$santriId] = $pot;
        }
    }
    if ($santriId > 0 && $bulanTagihan >= 1 && $tahunAjaranMulai > 0 && !isset($ctx['jeda'][$santriId])) {
        if (empty($ctx['jeda_preloaded'])
            && keuangan_syahriyah_potongan_dijeda_untuk_bulan($pdo, $santriId, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai)) {
            $ctx['jeda'][$santriId] = true;
        }
    }

    $dasar = keuangan_syahriyah_dasar_for_kategori($pdo, $kelasKategori, $ctx['tarifByTier']);
    $pot = $santriId > 0 ? ($ctx['potongan'][$santriId] ?? null) : null;

    if (!$pot || (int) ($pot['is_aktif'] ?? 0) !== 1) {
        $base = [
            'expected_dasar' => $dasar,
            'expected' => $dasar,
            'persen' => 0.0,
            'keterangan' => '',
            'potongan_nominal' => 0,
            'punya_potongan' => false,
            'potongan_dijeda' => false,
        ];

        return keuangan_syahriyah_apply_pkpps_tambahan($pdo, $base, $santriId, $kelasKategori, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai);
    }

    $persen = (float) ($pot['persen'] ?? 0);
    $keterangan = trim((string) ($pot['keterangan'] ?? ''));
    $dijeda = $santriId > 0 && !empty($ctx['jeda'][$santriId]);

    if ($dijeda || $persen <= 0) {
        $paused = [
            'expected_dasar' => $dasar,
            'expected' => $dasar,
            'persen' => $persen,
            'keterangan' => $keterangan,
            'potongan_nominal' => 0,
            'punya_potongan' => false,
            'potongan_dijeda' => $dijeda,
        ];

        return keuangan_syahriyah_apply_pkpps_tambahan($pdo, $paused, $santriId, $kelasKategori, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai);
    }

    $expected = keuangan_syahriyah_nominal_setelah_potongan($dasar, $persen);

    $result = [
        'expected_dasar' => $dasar,
        'expected' => $expected,
        'persen' => $persen,
        'keterangan' => $keterangan,
        'potongan_nominal' => max(0, $dasar - $expected),
        'punya_potongan' => true,
        'potongan_dijeda' => false,
    ];

    return keuangan_syahriyah_apply_pkpps_tambahan($pdo, $result, $santriId, $kelasKategori, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai);
}

/** Gabungkan tambahan syahriyah PKPPS saja ke simulasi tagihan. */
function keuangan_syahriyah_apply_pkpps_tambahan(
    PDO $pdo,
    array $sim,
    int $santriId,
    string $kelasKategori,
    int $bulanTagihan = 0,
    int $tahunAjaranMulai = 0,
    int $tahunAjaranSelesai = 0
): array {
    if (!function_exists('keuangan_pkpps_syahriyah_apply_to_simulasi')) {
        require_once __DIR__ . '/keuangan_pkpps_syahriyah.php';
    }

    $sim['kelas_syahriyah_tambahan'] = 0;

    return keuangan_pkpps_syahriyah_apply_to_simulasi($pdo, $sim, $santriId, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai);
}

/** Terapkan potongan persen ke nominal dasar syahriyah. */
function keuangan_syahriyah_nominal_setelah_potongan(int $nominalDasar, float $persen): int
{
    if ($nominalDasar <= 0) {
        return 0;
    }
    $p = max(0.0, min(100.0, $persen));

    return max(0, (int) round($nominalDasar * (100.0 - $p) / 100.0));
}

/**
 * Hitung tagihan syahriyah santri setelah potongan (jika aktif).
 *
 * @return array{
 *   expected_dasar:int,
 *   expected:int,
 *   persen:float,
 *   keterangan:string,
 *   potongan_nominal:int,
 *   punya_potongan:bool,
 *   potongan_dijeda:bool
 * }
 */
function keuangan_syahriyah_expected_dengan_potongan(
    PDO $pdo,
    int $santriId,
    string $kelasKategori,
    int $bulanTagihan = 0,
    int $tahunAjaranMulai = 0,
    int $tahunAjaranSelesai = 0
): array {
    return keuangan_syahriyah_simulasi($pdo, $santriId, $kelasKategori, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai);
}

/** Contoh keterangan potongan untuk datalist. */
function keuangan_syahriyah_potongan_keterangan_suggest(): array
{
    return [
        'Berprestasi (juara/hafalan)',
        'Kaka beradik di pondok',
        'Adik beradik di pondok',
        'Anak staf/pengurus pondok',
        'Yatim piatu / dhuafa',
        'Beasiswa khusus',
        'Keringanan sosial',
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function keuangan_syahriyah_potongan_list_rows(
    PDO $pdo,
    string $q = '',
    bool $hanyaAktif = false,
    bool $hanyaPunyaPotongan = false
): array {
    ensure_keuangan_syahriyah_potongan_table($pdo);
    if (!table_exists($pdo, 'santri')) {
        return [];
    }

    $nameExpr = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $katExpr = column_exists($pdo, 'santri', 'kategori_kelas') ? 'kategori_kelas' : (column_exists($pdo, 'santri', 'tingkatan') ? 'tingkatan' : "''");
    $activeExpr = column_exists($pdo, 'santri', 'is_aktif') ? ' AND COALESCE(s.is_aktif, 1) = 1 ' : '';

    $sql = "
        SELECT s.id, s.nis, s.tingkatan, {$nameExpr} AS nama_santri, {$katExpr} AS kategori_kelas,
               p.id AS potongan_id, p.persen, p.keterangan, p.is_aktif AS potongan_aktif,
               p.updated_at, p.created_at
        FROM santri s
        LEFT JOIN keuangan_santri_syahriyah_potongan p ON p.santri_id = s.id
        WHERE 1=1 {$activeExpr}
    ";
    $params = [];
    if ($hanyaAktif) {
        $sql .= ' AND p.is_aktif = 1 AND COALESCE(p.persen, 0) > 0 ';
    }
    if ($hanyaPunyaPotongan) {
        $sql .= ' AND p.id IS NOT NULL ';
    }
    if ($q !== '') {
        $sql .= " AND (LOWER({$nameExpr}) LIKE :q OR LOWER(s.nis) LIKE :q2) ";
        $params['q'] = '%' . strtolower($q) . '%';
        $params['q2'] = '%' . strtolower($q) . '%';
    }
    $sql .= ' ORDER BY ' . santri_list_order_sql('s') . ' LIMIT 500';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array{ok:bool,message:string}
 */
function keuangan_syahriyah_potongan_simpan(PDO $pdo, array $post, int $userId): array
{
    ensure_keuangan_syahriyah_potongan_table($pdo);

    $santriId = (int) ($post['santri_id'] ?? 0);
    $persenRaw = str_replace(',', '.', trim((string) ($post['persen'] ?? '0')));
    $persen = is_numeric($persenRaw) ? (float) $persenRaw : -1.0;
    $keterangan = trim((string) ($post['keterangan'] ?? ''));
    $isAktif = isset($post['is_aktif']) ? 1 : 0;

    if ($santriId <= 0) {
        return ['ok' => false, 'message' => 'Santri wajib dipilih.'];
    }
    if ($persen < 0 || $persen > 100) {
        return ['ok' => false, 'message' => 'Persentase potongan harus antara 0 dan 100.'];
    }
    if ($isAktif === 1 && $persen > 0 && $keterangan === '') {
        return ['ok' => false, 'message' => 'Keterangan wajib diisi bila potongan aktif dan persen > 0 (mis. berprestasi, kaka beradik).'];
    }

    $chk = $pdo->prepare('SELECT id FROM santri WHERE id = :id LIMIT 1');
    $chk->execute(['id' => $santriId]);
    if (!$chk->fetch()) {
        return ['ok' => false, 'message' => 'Santri tidak ditemukan.'];
    }

    $stmt = $pdo->prepare('
        INSERT INTO keuangan_santri_syahriyah_potongan (santri_id, persen, keterangan, is_aktif, created_by)
        VALUES (:sid, :persen, :ket, :aktif, :uid)
        ON DUPLICATE KEY UPDATE
            persen = VALUES(persen),
            keterangan = VALUES(keterangan),
            is_aktif = VALUES(is_aktif),
            updated_at = CURRENT_TIMESTAMP
    ');
    $stmt->execute([
        'sid' => $santriId,
        'persen' => round($persen, 2),
        'ket' => $keterangan,
        'aktif' => $isAktif,
        'uid' => $userId > 0 ? $userId : null,
    ]);

    $nama = '';
    $nameExpr = column_exists($pdo, 'santri', 'nama_santri') ? 'nama_santri' : 'nama';
    $n = $pdo->prepare("SELECT {$nameExpr} FROM santri WHERE id = :id LIMIT 1");
    $n->execute(['id' => $santriId]);
    $nama = (string) ($n->fetchColumn() ?: '');

    if (!function_exists('tagihan_syahriyah_cache_invalidate')) {
        require_once __DIR__ . '/tagihan_bulanan.php';
    }
    tagihan_syahriyah_cache_invalidate();
    if (function_exists('keuangan_dashboard_cache_invalidate')) {
        keuangan_dashboard_cache_invalidate();
    }

    return [
        'ok' => true,
        'message' => 'Potongan syahriyah untuk ' . ($nama !== '' ? $nama : 'santri') . ' disimpan'
            . ($isAktif && $persen > 0 ? ' (' . rtrim(rtrim(number_format($persen, 2, ',', '.'), '0'), ',') . '% — ' . $keterangan . ')' : '.'),
    ];
}

/**
 * @return array{ok:bool,message:string}
 */
function keuangan_syahriyah_potongan_hapus(PDO $pdo, int $santriId): array
{
    ensure_keuangan_syahriyah_potongan_table($pdo);
    if ($santriId <= 0) {
        return ['ok' => false, 'message' => 'Santri tidak valid.'];
    }
    $pdo->prepare('DELETE FROM keuangan_santri_syahriyah_potongan WHERE santri_id = :sid')->execute(['sid' => $santriId]);
    if (table_exists($pdo, 'keuangan_syahriyah_potongan_jeda')) {
        $pdo->prepare('DELETE FROM keuangan_syahriyah_potongan_jeda WHERE santri_id = :sid')->execute(['sid' => $santriId]);
    }

    if (!function_exists('tagihan_syahriyah_cache_invalidate')) {
        require_once __DIR__ . '/tagihan_bulanan.php';
    }
    tagihan_syahriyah_cache_invalidate();
    if (function_exists('keuangan_dashboard_cache_invalidate')) {
        keuangan_dashboard_cache_invalidate();
    }

    return ['ok' => true, 'message' => 'Potongan syahriyah dihapus; tagihan kembali tarif penuh.'];
}
