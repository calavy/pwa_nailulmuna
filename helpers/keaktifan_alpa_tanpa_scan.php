<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/kegiatan_kategori.php';

function keaktifan_alpa_jika_tanpa_scan_enabled(PDO $pdo): bool
{
    return trim((string) app_setting($pdo, 'keaktifan_alpa_jika_tanpa_scan', '0')) !== '0';
}

function keaktifan_tanpa_scan_dihitung_hadir(PDO $pdo): bool
{
    return trim((string) app_setting($pdo, 'keaktifan_tanpa_scan_dihitung_hadir', '0')) === '1';
}

function keaktifan_catatan_hadir_tanpa_scan(): string
{
    return 'tanpa_scan:hadir';
}

function keaktifan_hadir_baris_asli(?string $catatan): bool
{
    return !str_starts_with(trim((string) $catatan), 'tanpa_scan:');
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
    $catatanSql = '';
    if (column_exists($pdo, 'presensi', 'catatan')) {
        $catatanSql = ' AND (catatan IS NULL OR catatan NOT LIKE "tanpa_scan:%")';
    }
    $st = $pdo->prepare('
        SELECT 1 FROM presensi
        WHERE kegiatan_id = :kid
          AND tanggal_presensi = :tgl
          AND status_presensi = "HADIR"
          ' . $catatanSql . '
        LIMIT 1
    ');
    $st->execute(['kid' => $kegiatanId, 'tgl' => $tanggal]);

    return $cache[$key] = (bool) $st->fetchColumn();
}

/**
 * Slot Jamaah/Ta'lim tanpa scan petugas (tidak ada HADIR asli) → jangan tulis ALPA.
 */
function keaktifan_skip_alpa_karena_tanpa_scan(PDO $pdo, int $kegiatanId, string $tanggal, ?string $kategori = null): bool
{
    if ($kegiatanId <= 0 || $tanggal === '') {
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
 * Saklar nyala: ALPA slot kosong → Hadir. Saklar mati: buang ALPA slot kosong dari N.HARI.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function keaktifan_exclude_alpa_slot_kosong(PDO $pdo, array $rows): array
{
    if ($rows === []) {
        return $rows;
    }
    $hadirAsli = keaktifan_tanpa_scan_dihitung_hadir($pdo);
    $hadirKeys = [];
    foreach ($rows as $row) {
        if (strtoupper(trim((string) ($row['status_presensi'] ?? ''))) !== 'HADIR') {
            continue;
        }
        if (!keaktifan_hadir_baris_asli(isset($row['catatan']) ? (string) $row['catatan'] : null)) {
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
        $kelasAdaScan = isset($hadirKeys[$slotKey]) || keaktifan_kegiatan_tanggal_punya_hadir($pdo, $kid, $tgl);
        if ($kelasAdaScan) {
            $out[] = $row;
            continue;
        }
        if ($hadirAsli) {
            $row['status_presensi'] = 'HADIR';
            unset($row['_bucket']);
            $out[] = $row;
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
        set_flash('error', 'Hanya super admin yang dapat mengubah pengaturan keaktifan.');

        return true;
    }
    $hadirKosong = trim((string) ($_POST['keaktifan_tanpa_scan_dihitung_hadir'] ?? '0')) === '1' ? '1' : '0';
    $telatHadir = trim((string) ($_POST['keaktifan_telat_dihitung_hadir'] ?? '0')) === '1' ? '1' : '0';
    require_once __DIR__ . '/penilaian_kehadiran.php';
    $bobot = [
        'alpa' => penilaian_kehadiran_bobot_clamp((int) ($_POST['penilaian_bobot_alpa'] ?? PENILAIAN_KEHADIRAN_BOBOT_ALPA)),
        'izin' => penilaian_kehadiran_bobot_clamp((int) ($_POST['penilaian_bobot_izin'] ?? PENILAIAN_KEHADIRAN_BOBOT_IZIN)),
        'sakit' => penilaian_kehadiran_bobot_clamp((int) ($_POST['penilaian_bobot_sakit'] ?? PENILAIAN_KEHADIRAN_BOBOT_SAKIT)),
        'telat' => penilaian_kehadiran_bobot_clamp((int) ($_POST['penilaian_bobot_telat'] ?? PENILAIAN_KEHADIRAN_BOBOT_TELAT)),
        'hadir' => penilaian_kehadiran_bobot_clamp((int) ($_POST['penilaian_bobot_hadir'] ?? PENILAIAN_KEHADIRAN_BOBOT_HADIR)),
    ];
    save_setting($pdo, 'keaktifan_tanpa_scan_dihitung_hadir', $hadirKosong);
    save_setting($pdo, 'keaktifan_alpa_jika_tanpa_scan', '0');
    save_setting($pdo, 'keaktifan_telat_dihitung_hadir', $telatHadir);
    save_setting($pdo, 'penilaian_bobot_alpa', (string) $bobot['alpa']);
    save_setting($pdo, 'penilaian_bobot_izin', (string) $bobot['izin']);
    save_setting($pdo, 'penilaian_bobot_sakit', (string) $bobot['sakit']);
    save_setting($pdo, 'penilaian_bobot_telat', (string) $bobot['telat']);
    save_setting($pdo, 'penilaian_bobot_hadir', (string) $bobot['hadir']);
    if (function_exists('app_settings_cache_reset')) {
        app_settings_cache_reset($pdo);
    }
    if (!function_exists('rekap_keaktifan_rank_tingkatan_cache_invalidate')) {
        require_once __DIR__ . '/rekap_keaktifan.php';
    }
    rekap_keaktifan_rank_tingkatan_cache_invalidate();
    foreach (array_keys($_SESSION ?? []) as $sk) {
        if (is_string($sk) && str_starts_with($sk, 'skbt_laporan_')) {
            unset($_SESSION[$sk]);
        }
    }
    $pesanKosong = $hadirKosong === '1'
        ? 'Kegiatan tanpa scan (kendala petugas) dihitung Hadir.'
        : 'Kegiatan tanpa scan tidak dihitung ALPA dan tidak masuk N.HARI.';
    $pesanTelat = $telatHadir === '1'
        ? 'Telat dihitung Hadir di penilaian.'
        : 'Telat tetap dihitung Telat di penilaian.';
    set_flash(
        'success',
        $pesanKosong . ' ' . $pesanTelat
        . ' Bobot: Alpa×' . $bobot['alpa']
        . ', Izin×' . $bobot['izin']
        . ', Sakit×' . $bobot['sakit']
        . ', Telat×' . $bobot['telat']
        . ', Hadir×' . $bobot['hadir'] . '.'
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
