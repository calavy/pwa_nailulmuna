<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/datetime_display.php';
require_once __DIR__ . '/pondok_kalender.php';
require_once __DIR__ . '/keuangan_defs.php';

function keuangan_tagihan_mulai_masuk_enabled(PDO $pdo): bool
{
    return trim((string) app_setting($pdo, 'keuangan_tagihan_mulai_masuk', '1')) === '1';
}

function keuangan_awal_tahun_bedakan_baru_lama(PDO $pdo): bool
{
    return trim((string) app_setting($pdo, 'keuangan_awal_tahun_bedakan_baru_lama', '1')) === '1';
}

function tagihan_santri_masuk_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (table_exists($pdo, 'santri_tagihan_masuk_riwayat')) {
        return;
    }
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS santri_tagihan_masuk_riwayat (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            santri_id INT NOT NULL,
            tahun_ajaran_mulai INT NOT NULL,
            tahun_ajaran_selesai INT NOT NULL,
            tanggal_masuk DATE NOT NULL,
            bulan_mulai_tagihan TINYINT UNSIGNED NOT NULL DEFAULT 1,
            jenis_santri VARCHAR(10) NOT NULL DEFAULT 'baru',
            catatan TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_santri_ta_masuk (santri_id, tahun_ajaran_mulai),
            KEY idx_santri (santri_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function tagihan_santri_masuk_bulan_label(PDO $pdo, int $taMulai, int $taSelesai, int $bulanMulai): string
{
    $slots = pondok_bulan_slots_tahun_ajaran($pdo, $taMulai, $taSelesai);
    $slot = pondok_slot_dari_bulan_tagihan($slots, $bulanMulai);

    return $slot ? pondok_bulan_slot_label_tampilan($pdo, $slot) : ('Bulan ' . $bulanMulai);
}

function tagihan_santri_masuk_build_catatan(
    PDO $pdo,
    string $tanggalMasuk,
    int $taMulai,
    int $taSelesai,
    int $bulanMulai,
    string $jenis
): string {
    $tglLabel = app_format_tanggal_id($tanggalMasuk);
    $taLabel = pondok_tahun_ajaran_label($pdo, ['mulai' => $taMulai, 'selesai' => $taSelesai]);
    $bulanLabel = tagihan_santri_masuk_bulan_label($pdo, $taMulai, $taSelesai, $bulanMulai);

    if ($jenis === 'baru' && $bulanMulai > 1) {
        return 'Santri baru masuk ' . $tglLabel . ' (TA ' . $taLabel . '). '
            . 'Tagihan bulanan mulai ' . $bulanLabel . ' (bulan ke-' . $bulanMulai . '). '
            . 'Bulan sebelumnya tidak ditagih. Catatan ini tetap tersimpan; TA berikutnya tagihan penuh dari bulan 1.';
    }
    if ($jenis === 'baru') {
        return 'Santri baru masuk ' . $tglLabel . ' (TA ' . $taLabel . '). Tagihan bulanan mulai bulan pertama TA.';
    }

    return 'Santri lama — tanggal masuk ' . $tglLabel . '. Tagihan bulanan TA ' . $taLabel . ' penuh dari bulan 1.';
}

/** @return list<array<string,mixed>> */
function tagihan_santri_masuk_riwayat_list(PDO $pdo, int $santriId): array
{
    tagihan_santri_masuk_ensure_schema($pdo);
    if ($santriId <= 0) {
        return [];
    }
    $st = $pdo->prepare('
        SELECT id, santri_id, tahun_ajaran_mulai, tahun_ajaran_selesai, tanggal_masuk,
               bulan_mulai_tagihan, jenis_santri, catatan, created_at, updated_at
        FROM santri_tagihan_masuk_riwayat
        WHERE santri_id = :sid
        ORDER BY tahun_ajaran_mulai ASC, id ASC
    ');
    $st->execute(['sid' => $santriId]);

    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function tagihan_santri_masuk_riwayat_bulan_mulai(PDO $pdo, int $santriId, int $tahunAjaranMulai): ?int
{
    tagihan_santri_masuk_ensure_schema($pdo);
    if ($santriId <= 0 || $tahunAjaranMulai <= 0) {
        return null;
    }
    $st = $pdo->prepare('
        SELECT bulan_mulai_tagihan
        FROM santri_tagihan_masuk_riwayat
        WHERE santri_id = :sid AND tahun_ajaran_mulai = :tm
        LIMIT 1
    ');
    $st->execute(['sid' => $santriId, 'tm' => $tahunAjaranMulai]);
    $val = $st->fetchColumn();
    if ($val === false) {
        return null;
    }

    return max(1, min(12, (int) $val));
}

function tagihan_santri_masuk_riwayat_sync(PDO $pdo, int $santriId, ?string $tanggalMasuk = null): void
{
    tagihan_santri_masuk_ensure_schema($pdo);
    if ($santriId <= 0) {
        return;
    }
    if ($tanggalMasuk === null && column_exists($pdo, 'santri', 'tanggal_masuk')) {
        $st = $pdo->prepare('SELECT tanggal_masuk FROM santri WHERE id = :id LIMIT 1');
        $st->execute(['id' => $santriId]);
        $tanggalMasuk = trim((string) ($st->fetchColumn() ?: ''));
    }
    if ($tanggalMasuk === null || $tanggalMasuk === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalMasuk)) {
        return;
    }

    $taMasuk = pondok_tahun_ajaran_from_date($pdo, $tanggalMasuk);
    $taMulai = (int) ($taMasuk['mulai'] ?? 0);
    $taSelesai = (int) ($taMasuk['selesai'] ?? 0);
    if ($taMulai <= 0) {
        return;
    }
    if ($taSelesai <= 0) {
        $taSelesai = $taMulai + 1;
    }

    $baruDiTa = tagihan_santri_baru_di_ta($pdo, $santriId, $taMulai, $tanggalMasuk);
    if ($baruDiTa) {
        $slot = pondok_slot_untuk_tanggal($pdo, $taMulai, $taSelesai, $tanggalMasuk);
        $bulanMulai = max(1, min(12, (int) ($slot['bulan_tagihan'] ?? 1)));
    } else {
        $bulanMulai = 1;
    }
    $jenis = tagihan_santri_jenis_ta($pdo, $santriId, $taMulai, $tanggalMasuk);
    if ($jenis === 'semua') {
        $jenis = $baruDiTa ? 'baru' : 'lama';
    }
    $catatan = tagihan_santri_masuk_build_catatan($pdo, $tanggalMasuk, $taMulai, $taSelesai, $bulanMulai, $jenis);

    $st = $pdo->prepare('
        INSERT INTO santri_tagihan_masuk_riwayat
            (santri_id, tahun_ajaran_mulai, tahun_ajaran_selesai, tanggal_masuk, bulan_mulai_tagihan, jenis_santri, catatan)
        VALUES
            (:sid, :tm, :ts, :tgl, :bulan, :jenis, :catatan)
        ON DUPLICATE KEY UPDATE
            tahun_ajaran_selesai = VALUES(tahun_ajaran_selesai),
            tanggal_masuk = VALUES(tanggal_masuk),
            bulan_mulai_tagihan = VALUES(bulan_mulai_tagihan),
            jenis_santri = VALUES(jenis_santri),
            catatan = VALUES(catatan)
    ');
    $st->execute([
        'sid' => $santriId,
        'tm' => $taMulai,
        'ts' => $taSelesai,
        'tgl' => $tanggalMasuk,
        'bulan' => $bulanMulai,
        'jenis' => $jenis,
        'catatan' => $catatan,
    ]);
}

/** @return list<array<string,mixed>> */
function tagihan_santri_masuk_riwayat_get_or_sync(PDO $pdo, int $santriId, ?string $tanggalMasuk = null): array
{
    $list = tagihan_santri_masuk_riwayat_list($pdo, $santriId);
    if ($list === []) {
        tagihan_santri_masuk_riwayat_sync($pdo, $santriId, $tanggalMasuk);
        $list = tagihan_santri_masuk_riwayat_list($pdo, $santriId);
    }

    return $list;
}

/**
 * Info tagihan masuk untuk TA aktif (bulan mulai, catatan, riwayat).
 *
 * @return array{
 *   jenis_santri:string,
 *   bulan_mulai:int,
 *   catatan_ta_ini:?string,
 *   riwayat:list<array<string,mixed>>,
 *   riwayat_teks:list<string>
 * }
 */
function tagihan_santri_masuk_info_for_ta(
    PDO $pdo,
    int $santriId,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    ?string $tanggalMasuk = null
): array {
    if ($tanggalMasuk === null && $santriId > 0 && column_exists($pdo, 'santri', 'tanggal_masuk')) {
        $st = $pdo->prepare('SELECT tanggal_masuk FROM santri WHERE id = :id LIMIT 1');
        $st->execute(['id' => $santriId]);
        $tanggalMasuk = trim((string) ($st->fetchColumn() ?: ''));
    }
    $riwayat = tagihan_santri_masuk_riwayat_get_or_sync(
        $pdo,
        $santriId,
        ($tanggalMasuk !== null && $tanggalMasuk !== '') ? $tanggalMasuk : null
    );
    $catatanTaIni = null;
    foreach ($riwayat as $row) {
        if ((int) ($row['tahun_ajaran_mulai'] ?? 0) === $tahunAjaranMulai) {
            $catatanTaIni = trim((string) ($row['catatan'] ?? ''));
            if ($catatanTaIni === '') {
                $catatanTaIni = null;
            }
            break;
        }
    }
    $riwayatTeks = [];
    foreach ($riwayat as $row) {
        $txt = trim((string) ($row['catatan'] ?? ''));
        if ($txt !== '') {
            $riwayatTeks[] = $txt;
        }
    }

    return [
        'jenis_santri' => tagihan_santri_jenis_ta($pdo, $santriId, $tahunAjaranMulai, $tanggalMasuk !== '' ? $tanggalMasuk : null),
        'bulan_mulai' => tagihan_santri_bulan_mulai($pdo, $santriId, $tahunAjaranMulai, $tahunAjaranSelesai, $tanggalMasuk !== '' ? $tanggalMasuk : null),
        'catatan_ta_ini' => $catatanTaIni,
        'riwayat' => $riwayat,
        'riwayat_teks' => $riwayatTeks,
    ];
}

/**
 * Bulan tagihan pertama yang dikenakan untuk santri pada TA (1–12).
 * Hanya santri baru di TA masuk yang mulai dari bulan tanggal masuk.
 * Santri lama / TA sebelumnya → bulan 1. Tanpa tanggal masuk → bulan 1.
 */
function tagihan_santri_bulan_mulai(
    PDO $pdo,
    int $santriId,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    ?string $tanggalMasuk = null
): int {
    if (!keuangan_tagihan_mulai_masuk_enabled($pdo) || $tahunAjaranMulai <= 0) {
        return 1;
    }

    $tgl = $tanggalMasuk;
    if ($tgl === null && $santriId > 0 && column_exists($pdo, 'santri', 'tanggal_masuk')) {
        $st = $pdo->prepare('SELECT tanggal_masuk FROM santri WHERE id = :id LIMIT 1');
        $st->execute(['id' => $santriId]);
        $tgl = trim((string) ($st->fetchColumn() ?: ''));
    }
    if ($tgl === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) {
        return 1;
    }

    $taMasuk = pondok_tahun_ajaran_from_date($pdo, $tgl);
    $mulaiMasuk = (int) ($taMasuk['mulai'] ?? 0);
    if ($mulaiMasuk <= 0) {
        return 1;
    }
    if ($mulaiMasuk < $tahunAjaranMulai) {
        return 1;
    }
    if ($mulaiMasuk > $tahunAjaranMulai) {
        return 13;
    }

    if (!tagihan_santri_baru_di_ta($pdo, $santriId, $tahunAjaranMulai, $tgl)) {
        return 1;
    }

    $frozen = tagihan_santri_masuk_riwayat_bulan_mulai($pdo, $santriId, $tahunAjaranMulai);
    if ($frozen !== null) {
        return $frozen;
    }

    $slot = pondok_slot_untuk_tanggal($pdo, $tahunAjaranMulai, $tahunAjaranSelesai, $tgl);

    return max(1, min(12, (int) ($slot['bulan_tagihan'] ?? 1)));
}

/**
 * Santri baru di TA aktif: masuk pada TA ini, belum punya riwayat tingkatan TA sebelumnya.
 * Tidak bergantung pada pengaturan bedakan awal tahun (khusus aturan bulan masuk bulanan).
 */
function tagihan_santri_baru_di_ta(
    PDO $pdo,
    int $santriId,
    int $tahunAjaranMulai,
    ?string $tanggalMasuk = null
): bool {
    if ($tahunAjaranMulai <= 0) {
        return false;
    }

    if ($santriId > 0 && table_exists($pdo, 'santri_riwayat_tingkatan')) {
        $st = $pdo->prepare('
            SELECT 1 FROM santri_riwayat_tingkatan
            WHERE santri_id = :sid AND tahun_ajaran_mulai < :tm
            LIMIT 1
        ');
        $st->execute(['sid' => $santriId, 'tm' => $tahunAjaranMulai]);
        if ($st->fetchColumn()) {
            return false;
        }
    }

    $tgl = $tanggalMasuk;
    if ($tgl === null && $santriId > 0 && column_exists($pdo, 'santri', 'tanggal_masuk')) {
        $st = $pdo->prepare('SELECT tanggal_masuk FROM santri WHERE id = :id LIMIT 1');
        $st->execute(['id' => $santriId]);
        $tgl = trim((string) ($st->fetchColumn() ?: ''));
    }
    if ($tgl === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) {
        return false;
    }

    $taMasuk = pondok_tahun_ajaran_from_date($pdo, $tgl);

    return (int) ($taMasuk['mulai'] ?? 0) === $tahunAjaranMulai;
}

/** @return 'baru'|'lama'|'semua' */
function tagihan_santri_jenis_ta(
    PDO $pdo,
    int $santriId,
    int $tahunAjaranMulai,
    ?string $tanggalMasuk = null
): string {
    if (!keuangan_awal_tahun_bedakan_baru_lama($pdo)) {
        return 'semua';
    }

    return tagihan_santri_baru_di_ta($pdo, $santriId, $tahunAjaranMulai, $tanggalMasuk) ? 'baru' : 'lama';
}

function tagihan_bulan_dibebankan(
    PDO $pdo,
    int $santriId,
    int $bulanTagihan,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    ?string $tanggalMasuk = null
): bool {
    if ($bulanTagihan < 1 || $bulanTagihan > 12) {
        return false;
    }
    if (!keuangan_tagihan_mulai_masuk_enabled($pdo)) {
        return true;
    }

    return $bulanTagihan >= tagihan_santri_bulan_mulai($pdo, $santriId, $tahunAjaranMulai, $tahunAjaranSelesai, $tanggalMasuk);
}

/**
 * @param list<array<string, mixed>> $santriRows
 * @return array{bulan_mulai: array<int, int>, jenis: array<int, string>, tanggal: array<int, string>}
 */
function tagihan_santri_masuk_maps_build(
    PDO $pdo,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai,
    array $santriRows
): array {
    static $cache = [];
    $key = $tahunAjaranMulai . ':' . $tahunAjaranSelesai . ':' . count($santriRows);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $bulanMulai = [];
    $jenis = [];
    $tanggal = [];
    foreach ($santriRows as $s) {
        $sid = (int) ($s['id'] ?? 0);
        if ($sid <= 0) {
            continue;
        }
        $tgl = trim((string) ($s['tanggal_masuk'] ?? ''));
        $tanggal[$sid] = $tgl;
        $bulanMulai[$sid] = tagihan_santri_bulan_mulai($pdo, $sid, $tahunAjaranMulai, $tahunAjaranSelesai, $tgl !== '' ? $tgl : null);
        $jenis[$sid] = tagihan_santri_jenis_ta($pdo, $sid, $tahunAjaranMulai, $tgl !== '' ? $tgl : null);
    }

    $cache[$key] = ['bulan_mulai' => $bulanMulai, 'jenis' => $jenis, 'tanggal' => $tanggal];

    return $cache[$key];
}

function keuangan_awal_tahun_pos_setting_key(string $slug, string $jenis): string
{
    return 'keuangan_awal_tahun_pos_' . $slug . '_' . $jenis;
}

/** Apakah komponen POS awal tahun berlaku untuk jenis santri (baru/lama). */
function keuangan_awal_tahun_pos_berlaku(PDO $pdo, string $slug, string $jenisSantri): bool
{
    if ($slug === '' || $jenisSantri === 'semua') {
        return true;
    }
    if (!keuangan_awal_tahun_bedakan_baru_lama($pdo)) {
        return true;
    }
    if (!in_array($jenisSantri, ['baru', 'lama'], true)) {
        return true;
    }
    $stored = trim((string) app_setting($pdo, keuangan_awal_tahun_pos_setting_key($slug, $jenisSantri), ''));

    return $stored === '' || $stored === '1';
}

/**
 * @param list<array<string,mixed>> $awalTahunDefs
 * @return array{baru: array<string,bool>, lama: array<string,bool>}
 */
function keuangan_awal_tahun_pos_aktif_matrix(PDO $pdo, array $awalTahunDefs): array
{
    $out = ['baru' => [], 'lama' => []];
    foreach (['baru', 'lama'] as $jenis) {
        foreach ($awalTahunDefs as $def) {
            $slug = (string) ($def['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $out[$jenis][$slug] = keuangan_awal_tahun_pos_berlaku($pdo, $slug, $jenis);
        }
    }

    return $out;
}

/** @return array{ok:bool,message:string} */
function keuangan_save_awal_tahun_pos_aktif_settings(PDO $pdo, array $post): array
{
    $posBaru = (array) ($post['pos_aktif_baru'] ?? []);
    $posLama = (array) ($post['pos_aktif_lama'] ?? []);
    $defs = keuangan_biaya_filter_syahriyah_makan(keuangan_biaya_definitions(), false);
    foreach ($defs as $def) {
        if ((string) ($def['kategori'] ?? '') !== 'Awal Tahun') {
            continue;
        }
        $slug = (string) ($def['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        save_setting($pdo, keuangan_awal_tahun_pos_setting_key($slug, 'baru'), !empty($posBaru[$slug]) ? '1' : '0');
        save_setting($pdo, keuangan_awal_tahun_pos_setting_key($slug, 'lama'), !empty($posLama[$slug]) ? '1' : '0');
    }
    app_settings_cache($pdo, true);
    require_once __DIR__ . '/keuangan_rekap_tagihan_bulan.php';
    keuangan_rekap_tagihan_cache_invalidate();

    return ['ok' => true, 'message' => 'Komponen pembayaran awal tahun (baru/lama) disimpan.'];
}

function keuangan_fee_nominal_awal_tahun(PDO $pdo, array $def, string $tier, string $jenisSantri): int
{
    if (!in_array($tier, ['muadalah', 'wustho', 'ulya'], true)) {
        $tier = 'wustho';
    }
    $slug = (string) ($def['slug'] ?? '');
    if ($slug === '') {
        return 0;
    }

    if (keuangan_awal_tahun_bedakan_baru_lama($pdo) && in_array($jenisSantri, ['baru', 'lama'], true)) {
        if (!keuangan_awal_tahun_pos_berlaku($pdo, $slug, $jenisSantri)) {
            return 0;
        }
        $key = 'keuangan_fee_' . $slug . '_' . $tier . '_' . $jenisSantri;
        $stored = trim((string) app_setting($pdo, $key, ''));
        if ($stored !== '') {
            return max(0, (int) $stored);
        }
    }

    return keuangan_fee_nominal_for_tier($pdo, $def, $tier);
}

/**
 * @return array{
 *   expected_total:int,
 *   paid_total:int,
 *   sisa_total:int,
 *   status:string,
 *   statusClass:string,
 *   per_pos:array<string, mixed>,
 *   belum_dibebankan?:bool
 * }
 */
function tagihan_wajib_status_kosong(): array
{
    return [
        'expected_total' => 0,
        'paid_total' => 0,
        'sisa_total' => 0,
        'status' => '—',
        'statusClass' => 'secondary',
        'per_pos' => [],
        'belum_dibebankan' => true,
    ];
}

/** @return array{ok:bool,message:string} */
function keuangan_save_tagihan_masuk_settings(PDO $pdo, array $post): array
{
    save_setting($pdo, 'keuangan_tagihan_mulai_masuk', !empty($post['keuangan_tagihan_mulai_masuk']) ? '1' : '0');
    save_setting($pdo, 'keuangan_awal_tahun_bedakan_baru_lama', !empty($post['keuangan_awal_tahun_bedakan_baru_lama']) ? '1' : '0');
    app_settings_cache($pdo, true);
    require_once __DIR__ . '/keuangan_rekap_tagihan_bulan.php';
    keuangan_rekap_tagihan_cache_invalidate();

    return [
        'ok' => true,
        'message' => 'Pengaturan tagihan masuk santri disimpan. Santri baru ditagih dari bulan masuk; catatan tersimpan untuk setiap TA.',
    ];
}

/** @return array{ok:bool,message:string} */
function keuangan_save_tarif_awal_tahun_jenis_settings(PDO $pdo, array $post): array
{
    $feesBaru = $post['fee_baru'] ?? [];
    $feesLama = $post['fee_lama'] ?? [];
    if (!is_array($feesBaru) || !is_array($feesLama)) {
        return ['ok' => false, 'message' => 'Data tarif tidak valid.'];
    }

    $tiers = ['muadalah', 'wustho', 'ulya'];
    $defs = keuangan_biaya_filter_syahriyah_makan(keuangan_biaya_definitions(), false);
    $slugValid = [];
    foreach ($defs as $def) {
        if ((string) ($def['kategori'] ?? '') === 'Awal Tahun') {
            $slugValid[(string) $def['slug']] = true;
        }
    }

    foreach (['baru' => $feesBaru, 'lama' => $feesLama] as $jenis => $feeRows) {
        foreach ($feeRows as $slug => $tierRows) {
            if (!isset($slugValid[$slug]) || !is_array($tierRows)) {
                continue;
            }
            foreach ($tiers as $tier) {
                if (!array_key_exists($tier, $tierRows)) {
                    continue;
                }
                $nom = keuangan_money_input_to_int((string) $tierRows[$tier]);
                save_setting($pdo, 'keuangan_fee_' . $slug . '_' . $tier . '_' . $jenis, (string) max(0, $nom));
            }
        }
    }

    app_settings_cache($pdo, true);
    require_once __DIR__ . '/keuangan_rekap_tagihan_bulan.php';
    keuangan_rekap_tagihan_cache_invalidate();

    return ['ok' => true, 'message' => 'Tarif awal tahun (santri baru & lama) disimpan.'];
}

/**
 * Status pengaturan tagihan masuk + santri aktif tanpa tanggal masuk.
 *
 * @return array{
 *   mulai_masuk:bool,
 *   bedakan_awal_tahun:bool,
 *   siap:bool,
 *   santri_tanpa_tanggal_masuk:int
 * }
 */
function keuangan_tagihan_masuk_pengaturan_status(PDO $pdo): array
{
    $mulaiMasuk = keuangan_tagihan_mulai_masuk_enabled($pdo);
    $bedakanAwal = keuangan_awal_tahun_bedakan_baru_lama($pdo);
    $tanpaTgl = 0;

    if (table_exists($pdo, 'santri') && column_exists($pdo, 'santri', 'tanggal_masuk')) {
        if (!function_exists('santri_sql_aktif_only')) {
            require_once __DIR__ . '/santri_operasional.php';
        }
        $aktifSql = santri_sql_aktif_only('s');
        $st = $pdo->query("
            SELECT COUNT(*) FROM santri s
            WHERE {$aktifSql}
              AND (s.tanggal_masuk IS NULL OR TRIM(s.tanggal_masuk) = '' OR s.tanggal_masuk = '0000-00-00')
        ");
        $tanpaTgl = (int) ($st ? $st->fetchColumn() : 0);
    }

    return [
        'mulai_masuk' => $mulaiMasuk,
        'bedakan_awal_tahun' => $bedakanAwal,
        'siap' => $mulaiMasuk && $bedakanAwal && $tanpaTgl === 0,
        'santri_tanpa_tanggal_masuk' => $tanpaTgl,
    ];
}

/**
 * @param list<array{slug:string,default:array<string,int>}> $awalTahunDefs
 * @return array{baru: array<string, array<string, int>>, lama: array<string, array<string, int>>}
 */
function keuangan_fee_matrix_awal_tahun_jenis(PDO $pdo, array $awalTahunDefs): array
{
    $settings = app_settings_cache($pdo);
    $out = ['baru' => [], 'lama' => []];
    foreach (['baru', 'lama'] as $jenis) {
        foreach ($awalTahunDefs as $def) {
            $slug = (string) ($def['slug'] ?? '');
            if ($slug === '') {
                continue;
            }
            $out[$jenis][$slug] = [];
            foreach (['muadalah', 'wustho', 'ulya'] as $tier) {
                $key = 'keuangan_fee_' . $slug . '_' . $tier . '_' . $jenis;
                $fallback = (int) ($def['default'][$tier] ?? 0);
                $baseKey = 'keuangan_fee_' . $slug . '_' . $tier;
                if (!isset($settings[$key]) || trim((string) $settings[$key]) === '') {
                    $out[$jenis][$slug][$tier] = max(0, (int) ($settings[$baseKey] ?? $fallback));
                } else {
                    $out[$jenis][$slug][$tier] = max(0, (int) $settings[$key]);
                }
            }
        }
    }

    return $out;
}
