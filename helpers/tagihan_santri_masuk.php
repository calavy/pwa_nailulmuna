<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
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

/**
 * Bulan tagihan pertama yang dikenakan untuk santri pada TA (1–12).
 * Santri masuk TA sebelumnya → bulan 1. Tanpa tanggal masuk → bulan 1.
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

    $slot = pondok_slot_untuk_tanggal($pdo, $tahunAjaranMulai, $tahunAjaranSelesai, $tgl);

    return max(1, min(12, (int) ($slot['bulan_tagihan'] ?? 1)));
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

    if ($santriId > 0 && table_exists($pdo, 'santri_riwayat_tingkatan')) {
        $st = $pdo->prepare('
            SELECT 1 FROM santri_riwayat_tingkatan
            WHERE santri_id = :sid AND tahun_ajaran_mulai < :tm
            LIMIT 1
        ');
        $st->execute(['sid' => $santriId, 'tm' => $tahunAjaranMulai]);
        if ($st->fetchColumn()) {
            return 'lama';
        }
    }

    $tgl = $tanggalMasuk;
    if ($tgl === null && $santriId > 0 && column_exists($pdo, 'santri', 'tanggal_masuk')) {
        $st = $pdo->prepare('SELECT tanggal_masuk FROM santri WHERE id = :id LIMIT 1');
        $st->execute(['id' => $santriId]);
        $tgl = trim((string) ($st->fetchColumn() ?: ''));
    }
    if ($tgl === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) {
        return 'lama';
    }

    $taMasuk = pondok_tahun_ajaran_from_date($pdo, $tgl);

    return (int) ($taMasuk['mulai'] ?? 0) === $tahunAjaranMulai ? 'baru' : 'lama';
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
    if (function_exists('keuangan_dashboard_cache_invalidate')) {
        require_once __DIR__ . '/keuangan_dashboard.php';
        keuangan_dashboard_cache_invalidate();
    }

    return [
        'ok' => true,
        'message' => 'Pengaturan tagihan masuk santri disimpan. Tagihan bulanan mengikuti tanggal masuk; awal tahun membedakan santri baru dan lama.',
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

    return ['ok' => true, 'message' => 'Tarif awal tahun (santri baru & lama) disimpan.'];
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
