<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/kegiatan_kategori.php';

function keaktifan_alpa_jika_tanpa_scan_enabled(PDO $pdo): bool
{
    return trim((string) app_setting($pdo, 'keaktifan_alpa_jika_tanpa_scan', '1')) !== '0';
}

/** Jama'ah dan Ta'lim masuk laporan tanpa scan (bukan EXTRA/PKPPS). */
function keaktifan_tanpa_scan_kategori_dihitung(?string $kat): bool
{
    $k = kegiatan_kategori_normalize($kat);

    return $k === 'JAMAAH' || $k === 'TAALIM';
}

function keaktifan_kegiatan_tanggal_punya_hadir(PDO $pdo, int $kegiatanId, string $tanggal): bool
{
    static $cache = [];
    if ($kegiatanId <= 0 || $tanggal === '') {
        return false;
    }
    $key = $kegiatanId . '|' . $tanggal;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    if (!table_exists($pdo, 'presensi')) {
        return $cache[$key] = false;
    }
    $st = $pdo->prepare('
        SELECT 1 FROM presensi
        WHERE kegiatan_id = :kid
          AND tanggal_presensi = :tgl
          AND status_presensi = "HADIR"
        LIMIT 1
    ');
    $st->execute(['kid' => $kegiatanId, 'tgl' => $tanggal]);

    return $cache[$key] = (bool) $st->fetchColumn();
}

/**
 * Saklar OFF + slot Jamaah/Ta'lim tanpa satu pun HADIR → jangan hitung/tulis ALPA.
 */
function keaktifan_skip_alpa_karena_tanpa_scan(PDO $pdo, int $kegiatanId, string $tanggal, ?string $kategori = null): bool
{
    if ($kegiatanId <= 0 || $tanggal === '') {
        return false;
    }
    if (keaktifan_alpa_jika_tanpa_scan_enabled($pdo)) {
        return false;
    }
    if ($kategori === null || trim($kategori) === '') {
        $kategori = kegiatan_kategori_fetch($pdo, $kegiatanId);
    }
    if (!keaktifan_tanpa_scan_kategori_dihitung($kategori)) {
        return false;
    }

    return !keaktifan_kegiatan_tanggal_punya_hadir($pdo, $kegiatanId, $tanggal);
}

/**
 * Buang baris ALPA dari slot Jamaah/Ta'lim yang tidak ada HADIR (saklar OFF).
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function keaktifan_exclude_alpa_slot_kosong(PDO $pdo, array $rows): array
{
    if ($rows === [] || keaktifan_alpa_jika_tanpa_scan_enabled($pdo)) {
        return $rows;
    }
    $hadirKeys = [];
    foreach ($rows as $row) {
        if (strtoupper(trim((string) ($row['status_presensi'] ?? ''))) !== 'HADIR') {
            continue;
        }
        $kid = (int) ($row['kegiatan_id'] ?? 0);
        $tgl = (string) ($row['tanggal_presensi'] ?? '');
        if ($kid > 0 && $tgl !== '') {
            $hadirKeys[$kid . '|' . $tgl] = true;
        }
    }
    $katCache = [];
    $out = [];
    foreach ($rows as $row) {
        $status = strtoupper(trim((string) ($row['status_presensi'] ?? '')));
        if ($status !== 'ALPA') {
            $out[] = $row;
            continue;
        }
        $kid = (int) ($row['kegiatan_id'] ?? 0);
        $tgl = (string) ($row['tanggal_presensi'] ?? '');
        $kat = trim((string) ($row['kategori_kegiatan'] ?? ''));
        if ($kat === '') {
            if (!isset($katCache[$kid])) {
                $katCache[$kid] = kegiatan_kategori_fetch($pdo, $kid);
            }
            $kat = $katCache[$kid];
        }
        if (!keaktifan_tanpa_scan_kategori_dihitung($kat)) {
            $out[] = $row;
            continue;
        }
        $slotKey = $kid . '|' . $tgl;
        if (isset($hadirKeys[$slotKey]) || keaktifan_kegiatan_tanggal_punya_hadir($pdo, $kid, $tgl)) {
            $out[] = $row;
            continue;
        }
    }

    return $out;
}

function keaktifan_alpa_tanpa_scan_try_save(PDO $pdo): bool
{
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') {
        return false;
    }
    if (trim((string) ($_POST['action'] ?? '')) !== 'save_keaktifan_alpa_tanpa_scan') {
        return false;
    }
    if (!function_exists('is_super_admin') || !is_super_admin()) {
        set_flash('error', 'Hanya super admin yang dapat mengubah pengaturan ALPA tanpa scan.');

        return true;
    }
    $on = trim((string) ($_POST['keaktifan_alpa_jika_tanpa_scan'] ?? '0')) === '1' ? '1' : '0';
    save_setting($pdo, 'keaktifan_alpa_jika_tanpa_scan', $on);
    if (function_exists('app_settings_cache_reset')) {
        app_settings_cache_reset($pdo);
    }
    if (!function_exists('rekap_keaktifan_rank_tingkatan_cache_invalidate')) {
        require_once __DIR__ . '/rekap_keaktifan.php';
    }
    rekap_keaktifan_rank_tingkatan_cache_invalidate();
    set_flash(
        'success',
        $on === '1'
            ? 'Jama\'ah/Ta\'lim tanpa scan dihitung ALPA.'
            : 'Jama\'ah/Ta\'lim tanpa scan tidak dihitung ALPA.'
    );

    return true;
}

function keaktifan_alpa_tanpa_scan_redirect_if_saved(PDO $pdo): void
{
    if (!keaktifan_alpa_tanpa_scan_try_save($pdo)) {
        return;
    }
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
    if ($uri === '') {
        $uri = app_href('/dashboard.php');
    }
    header('Location: ' . $uri);
    exit;
}
