<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/keuangan_defs.php';

/** Pos yang boleh punya tarif berbeda per bulan tagihan. */
function keuangan_tarif_bulanan_pos_slugs(): array
{
    return ['syahriyah', 'makan'];
}

function ensure_keuangan_tarif_bulanan_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS keuangan_tarif_bulanan (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                tahun_ajaran_mulai SMALLINT UNSIGNED NOT NULL,
                tahun_ajaran_selesai SMALLINT UNSIGNED NOT NULL,
                bulan_tagihan TINYINT UNSIGNED NOT NULL,
                pos_slug VARCHAR(32) NOT NULL,
                tier ENUM(\'muadalah\',\'wustho\',\'ulya\') NOT NULL,
                nominal INT UNSIGNED NOT NULL DEFAULT 0,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_tarif_bulan (tahun_ajaran_mulai, tahun_ajaran_selesai, bulan_tagihan, pos_slug, tier),
                KEY idx_ta_bulan (tahun_ajaran_mulai, tahun_ajaran_selesai, bulan_tagihan)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        // Abaikan — halaman lain tetap pakai tarif default global.
    }
    $done = true;
}

function keuangan_tarif_bulanan_invalidate(): void
{
    if (function_exists('tagihan_syahriyah_cache_invalidate')) {
        tagihan_syahriyah_cache_invalidate();
    }
    if (function_exists('keuangan_santri_opsional_cache_invalidate')) {
        keuangan_santri_opsional_cache_invalidate();
    }
    if (function_exists('keuangan_dashboard_cache_invalidate')) {
        require_once __DIR__ . '/keuangan_dashboard.php';
        keuangan_dashboard_cache_invalidate();
    }
}

/**
 * Tarif default global (app_settings) untuk satu pos & tier.
 */
function keuangan_tarif_bulanan_default_nominal(PDO $pdo, string $posSlug, string $tier): int
{
    if (!in_array($tier, ['muadalah', 'wustho', 'ulya'], true)) {
        $tier = 'wustho';
    }
    $defaults = [
        'syahriyah' => ['muadalah' => 200000, 'wustho' => 210000, 'ulya' => 215000],
        'makan' => ['muadalah' => 220000, 'wustho' => 220000, 'ulya' => 220000],
    ];
    $fallback = (int) ($defaults[$posSlug][$tier] ?? 0);
    $key = 'keuangan_fee_' . $posSlug . '_' . $tier;

    return max(0, (int) app_setting($pdo, $key, (string) $fallback));
}

/**
 * Peta override tarif per bulan: [bulan][slug][tier] => nominal.
 *
 * @return array<int, array<string, array<string, int>>>
 */
function keuangan_tarif_bulanan_map(PDO $pdo, int $tahunAjaranMulai, int $tahunAjaranSelesai): array
{
    static $cache = [];
    $ts = max($tahunAjaranMulai, $tahunAjaranSelesai);
    $key = $tahunAjaranMulai . ':' . $ts;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $map = [];
    if ($tahunAjaranMulai <= 0) {
        $cache[$key] = $map;

        return $map;
    }

    ensure_keuangan_tarif_bulanan_table($pdo);
    if (!table_exists($pdo, 'keuangan_tarif_bulanan')) {
        $cache[$key] = $map;

        return $map;
    }

    $stmt = $pdo->prepare('
        SELECT bulan_tagihan, pos_slug, tier, nominal
        FROM keuangan_tarif_bulanan
        WHERE tahun_ajaran_mulai = :tm AND tahun_ajaran_selesai = :ts
    ');
    $stmt->execute(['tm' => $tahunAjaranMulai, 'ts' => $ts]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $bulan = (int) ($row['bulan_tagihan'] ?? 0);
        $slug = strtolower(trim((string) ($row['pos_slug'] ?? '')));
        $tier = (string) ($row['tier'] ?? '');
        if ($bulan < 1 || $bulan > 12 || $slug === '' || !in_array($tier, ['muadalah', 'wustho', 'ulya'], true)) {
            continue;
        }
        $map[$bulan][$slug][$tier] = max(0, (int) ($row['nominal'] ?? 0));
    }

    $cache[$key] = $map;

    return $map;
}

/** Nominal khusus bulan (null = pakai tarif default global). */
function keuangan_tarif_bulanan_lookup(
    PDO $pdo,
    string $posSlug,
    string $tier,
    int $bulanTagihan,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai
): ?int {
    if ($bulanTagihan < 1 || $bulanTagihan > 12 || $tahunAjaranMulai <= 0) {
        return null;
    }
    $posSlug = strtolower(trim($posSlug));
    if (!in_array($posSlug, keuangan_tarif_bulanan_pos_slugs(), true)) {
        return null;
    }
    $map = keuangan_tarif_bulanan_map($pdo, $tahunAjaranMulai, $tahunAjaranSelesai);

    return isset($map[$bulanTagihan][$posSlug][$tier])
        ? (int) $map[$bulanTagihan][$posSlug][$tier]
        : null;
}

/** Tarif efektif: override bulan ini bila ada, selain itu tarif default global. */
function keuangan_tarif_bulanan_resolve(
    PDO $pdo,
    string $posSlug,
    string $tier,
    int $bulanTagihan,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai
): int {
    if (!in_array($tier, ['muadalah', 'wustho', 'ulya'], true)) {
        $tier = 'wustho';
    }
    if ($bulanTagihan >= 1 && $bulanTagihan <= 12 && $tahunAjaranMulai > 0) {
        $custom = keuangan_tarif_bulanan_lookup($pdo, $posSlug, $tier, $bulanTagihan, $tahunAjaranMulai, $tahunAjaranSelesai);
        if ($custom !== null) {
            return $custom;
        }
    }

    return keuangan_tarif_bulanan_default_nominal($pdo, $posSlug, $tier);
}

/**
 * Matriks tarif syahriyah/makan per tier untuk satu bulan tagihan.
 *
 * @return array<string, array<string, int>>
 */
function keuangan_tarif_bulanan_matrix_for_month(
    PDO $pdo,
    int $bulanTagihan,
    int $tahunAjaranMulai,
    int $tahunAjaranSelesai
): array
{
    $out = [];
    foreach (keuangan_tarif_bulanan_pos_slugs() as $slug) {
        $out[$slug] = [];
        foreach (['muadalah', 'wustho', 'ulya'] as $tier) {
            $out[$slug][$tier] = keuangan_tarif_bulanan_resolve(
                $pdo,
                $slug,
                $tier,
                $bulanTagihan,
                $tahunAjaranMulai,
                $tahunAjaranSelesai
            );
        }
    }

    return $out;
}

/**
 * Simpan tarif khusus per bulan (kosong = hapus override, pakai default).
 *
 * @return array{ok:bool,message:string}
 */
function keuangan_save_tarif_bulanan_settings(PDO $pdo, array $post): array
{
    require_once __DIR__ . '/pondok_kalender.php';

    $ta = pondok_normalisasi_tahun_ajaran_input(
        $pdo,
        (int) ($post['tarif_bulan_ta_mulai'] ?? 0),
        (int) ($post['tarif_bulan_ta_selesai'] ?? 0)
    );
    $tm = (int) $ta['mulai'];
    $ts = (int) $ta['selesai'];
    if ($tm <= 0) {
        return ['ok' => false, 'message' => 'Tahun ajaran tidak valid.'];
    }

    $fees = $post['fee_bulan'] ?? [];
    if (!is_array($fees)) {
        return ['ok' => false, 'message' => 'Data tarif bulanan tidak valid.'];
    }

    ensure_keuangan_tarif_bulanan_table($pdo);
    if (!table_exists($pdo, 'keuangan_tarif_bulanan')) {
        return ['ok' => false, 'message' => 'Tabel tarif bulanan belum tersedia. Jalankan migrasi terbaru.'];
    }

    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare('
            DELETE FROM keuangan_tarif_bulanan
            WHERE tahun_ajaran_mulai = :tm AND tahun_ajaran_selesai = :ts
        ');
        $del->execute(['tm' => $tm, 'ts' => $ts]);

        $ins = $pdo->prepare('
            INSERT INTO keuangan_tarif_bulanan
                (tahun_ajaran_mulai, tahun_ajaran_selesai, bulan_tagihan, pos_slug, tier, nominal)
            VALUES (:tm, :ts, :bulan, :slug, :tier, :nominal)
        ');

        $saved = 0;
        foreach ($fees as $bulanRaw => $slugRows) {
            $bulan = (int) $bulanRaw;
            if ($bulan < 1 || $bulan > 12 || !is_array($slugRows)) {
                continue;
            }
            foreach ($slugRows as $slug => $tierRows) {
                $slug = strtolower(trim((string) $slug));
                if (!in_array($slug, keuangan_tarif_bulanan_pos_slugs(), true) || !is_array($tierRows)) {
                    continue;
                }
                foreach (['muadalah', 'wustho', 'ulya'] as $tier) {
                    if (!array_key_exists($tier, $tierRows)) {
                        continue;
                    }
                    $raw = trim((string) $tierRows[$tier]);
                    if ($raw === '') {
                        continue;
                    }
                    $nom = keuangan_money_input_to_int($raw);
                    if ($nom <= 0) {
                        continue;
                    }
                    $ins->execute([
                        'tm' => $tm,
                        'ts' => $ts,
                        'bulan' => $bulan,
                        'slug' => $slug,
                        'tier' => $tier,
                        'nominal' => $nom,
                    ]);
                    $saved++;
                }
            }
        }

        $pdo->commit();
        keuangan_tarif_bulanan_invalidate();

        return [
            'ok' => true,
            'message' => 'Tarif syahriyah & makan per bulan disimpan (' . $saved . ' sel nominal khusus). Bulan kosong memakai tarif default di tab Tarif biaya.',
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'message' => 'Gagal menyimpan: ' . $e->getMessage()];
    }
}
