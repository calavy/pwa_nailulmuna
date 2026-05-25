<?php

declare(strict_types=1);

require_once __DIR__ . '/pondok_kalender.php';

/** Bulan Hijriyah pertama dalam satu tahun ajaran (1=Muharram, 7=Rajab, …). */
function pondok_ta_bulan_awal_hijri(PDO $pdo): int
{
    $v = (int) app_setting($pdo, 'pondok_ta_bulan_awal_hijri', '1');

    return max(1, min(12, $v > 0 ? $v : 1));
}

/** Bulan Masehi pertama dalam satu tahun ajaran (default Juli = 7). */
function pondok_ta_bulan_awal_masehi(PDO $pdo): int
{
    $v = (int) app_setting($pdo, 'pondok_ta_bulan_awal_masehi', '7');

    return max(1, min(12, $v > 0 ? $v : 7));
}

function pondok_ta_bulan_awal(PDO $pdo): int
{
    return pondok_kalender_hijriyah($pdo)
        ? pondok_ta_bulan_awal_hijri($pdo)
        : pondok_ta_bulan_awal_masehi($pdo);
}

/** Hapus pilihan TA per-halaman (semua modul mengikuti pengaturan terpusat). */
function pondok_ta_clear_browse_session(): void
{
    unset(
        $_SESSION['pondok_ta_browse'],
        $_SESSION['keuangan_ta_browse'],
        $_SESSION['pondok_ta_options_cache_v1']
    );
}

/** Satu-satunya tempat ubah TA aktif pondok. */
function pondok_ta_central_settings_href(): string
{
    return app_href('/keuangan/pengaturan.php?bagian=umum');
}

/** Apakah user login boleh membuka halaman pengaturan TA terpusat. */
function pondok_ta_user_can_edit_central(PDO $pdo): bool
{
    if (!isset($_SESSION['user'])) {
        return false;
    }
    if (!empty($_SESSION['user']['is_super_admin'])) {
        return true;
    }
    if (!function_exists('get_allowed_permission_key_map')) {
        require_once __DIR__ . '/user_permissions.php';
    }
    $allowed = get_allowed_permission_key_map($pdo);
    if ($allowed === null) {
        $role = strtolower((string) ($_SESSION['user']['role'] ?? ''));

        return in_array($role, ['admin', 'pengurus'], true);
    }

    return isset($allowed['keuangan_pengaturan_modul']);
}

/**
 * Tahun ajaran operasional — selalu dari pengaturan terpusat (Keuangan → Umum & periode).
 * Parameter URL/sesi tm/ts tidak lagi mengganti TA di tiap halaman.
 *
 * @return array{mulai:int,selesai:int,is_aktif:bool,label:string}
 */
function pondok_ta_resolve(PDO $pdo, ?array $input = null): array
{
    unset($input);
    $aktif = pondok_tahun_ajaran_aktif($pdo);
    pondok_ta_persist_session($aktif);

    return pondok_ta_enrich($pdo, $aktif, $aktif);
}

/** @param array{mulai:int,selesai:int} $ta */
function pondok_ta_persist_session(array $ta): void
{
    $_SESSION['pondok_ta_browse'] = [
        'mulai' => (int) $ta['mulai'],
        'selesai' => (int) $ta['selesai'],
    ];
    $_SESSION['keuangan_ta_browse'] = $_SESSION['pondok_ta_browse'];
}

/**
 * @param array{mulai:int,selesai:int} $ta
 * @param array{mulai:int,selesai:int} $aktif
 * @return array{mulai:int,selesai:int,is_aktif:bool,label:string}
 */
function pondok_ta_enrich(PDO $pdo, array $ta, array $aktif): array
{
    return [
        'mulai' => (int) $ta['mulai'],
        'selesai' => (int) $ta['selesai'],
        'is_aktif' => (int) $ta['mulai'] === (int) $aktif['mulai'] && (int) $ta['selesai'] === (int) $aktif['selesai'],
        'label' => pondok_tahun_ajaran_label($pdo, $ta),
    ];
}

/**
 * @return list<array{mulai:int,selesai:int,label:string,is_aktif:bool,is_berjalan:bool}>
 */
function pondok_ta_pilihan_options(PDO $pdo): array
{
    static $cache = null;
    static $cacheKey = '';
    $aktif = pondok_tahun_ajaran_aktif($pdo);
    $key = (int) $aktif['mulai'] . ':' . pondok_ta_bulan_awal($pdo) . ':' . (pondok_kalender_hijriyah($pdo) ? 'h' : 'm');
    if ($cache !== null && $cacheKey === $key) {
        return $cache;
    }
    if (isset($_SESSION['user'])) {
        $sess = $_SESSION['pondok_ta_options_cache_v1'] ?? null;
        if (is_array($sess) && ($sess['key'] ?? '') === $key && (int) ($sess['expires'] ?? 0) > time()) {
            $cache = is_array($sess['data'] ?? null) ? $sess['data'] : [];
            $cacheKey = $key;

            return $cache;
        }
    }

    $berjalan = pondok_tahun_ajaran_from_date($pdo);
    $min = pondok_ta_tahun_min($pdo);
    $max = pondok_ta_tahun_max($pdo);
    $hijri = pondok_kalender_hijriyah($pdo);

    $keys = [];
    $add = static function (int $mulai, int $selesai) use (&$keys, $pdo, $min, $max): void {
        $ta = pondok_normalisasi_tahun_ajaran_input($pdo, $mulai, $selesai);
        $m = (int) $ta['mulai'];
        if ($m >= $min && $m <= $max) {
            $keys[$m] = $ta;
        }
    };

    $center = (int) $aktif['mulai'];
    for ($y = $center - 5; $y <= $center + 2; $y++) {
        $add($y, $hijri ? $y + 1 : $y + 1);
    }
    $add((int) $aktif['mulai'], (int) $aktif['selesai']);
    $add((int) $berjalan['mulai'], (int) $berjalan['selesai']);

    if (table_exists($pdo, 'keuangan_pembayaran')) {
        $rows = $pdo->query('
            SELECT DISTINCT tahun_ajaran_mulai, tahun_ajaran_selesai
            FROM keuangan_pembayaran
            ORDER BY tahun_ajaran_mulai DESC
            LIMIT 24
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $add((int) ($row['tahun_ajaran_mulai'] ?? 0), (int) ($row['tahun_ajaran_selesai'] ?? 0));
        }
    }
    if (table_exists($pdo, 'santri_riwayat_tingkatan')) {
        $rows = $pdo->query('
            SELECT DISTINCT tahun_ajaran_mulai, tahun_ajaran_selesai
            FROM santri_riwayat_tingkatan
            ORDER BY tahun_ajaran_mulai DESC
            LIMIT 24
        ')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as $row) {
            $add((int) ($row['tahun_ajaran_mulai'] ?? 0), (int) ($row['tahun_ajaran_selesai'] ?? 0));
        }
    }

    krsort($keys, SORT_NUMERIC);
    $out = [];
    foreach ($keys as $ta) {
        $out[] = [
            'mulai' => (int) $ta['mulai'],
            'selesai' => (int) $ta['selesai'],
            'label' => pondok_tahun_ajaran_label($pdo, $ta),
            'is_aktif' => (int) $ta['mulai'] === (int) $aktif['mulai'],
            'is_berjalan' => (int) $ta['mulai'] === (int) $berjalan['mulai'],
        ];
    }

    $cache = $out;
    $cacheKey = $key;
    if (isset($_SESSION['user'])) {
        $_SESSION['pondok_ta_options_cache_v1'] = [
            'key' => $key,
            'expires' => time() + 300,
            'data' => $out,
        ];
    }

    return $out;
}

/** Query string tanpa tm/ts — TA aktif selalu dari pengaturan terpusat. */
function pondok_ta_query_active(array $extra = []): string
{
    return http_build_query($extra);
}

/** @param array{mulai?:int,selesai?:int} $ta Diabaikan (kompatibilitas). */
function pondok_ta_query(array $ta, array $extra = []): string
{
    return pondok_ta_query_active($extra);
}

/** Nomor slot tagihan 1–12 dari bulan kalender (Hijriyah/Masehi) dalam TA tertentu. */
function pondok_bulan_tagihan_dari_bulan_kalender(
    PDO $pdo,
    int $bulanKalender,
    int $tahunAjaranMulai
): int {
    $awal = pondok_ta_bulan_awal($pdo);
    $bulanKalender = max(1, min(12, $bulanKalender));
    if ($bulanKalender >= $awal) {
        return $bulanKalender - $awal + 1;
    }

    return $bulanKalender + 12 - $awal + 1;
}
