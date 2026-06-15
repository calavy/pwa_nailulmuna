<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/perizinan_jenis.php';

/** @return list<string> */
function perizinan_syari_kategori_kodes(): array
{
    return array_keys(perizinan_syari_kategori_defaults());
}

/** @return array<string, array{label:string,enabled:bool,durasi_hari:int}> */
function perizinan_syari_kategori_defaults(): array
{
    return [
        'walimah_saudara' => [
            'label' => 'Izin Walimah Saudara Kandung',
            'enabled' => true,
            'durasi_hari' => 2,
        ],
        'musibah_saudara' => [
            'label' => 'Izin Musibah / Saudara Kandung Wafat',
            'enabled' => true,
            'durasi_hari' => 7,
        ],
        'berobat_santri' => [
            'label' => 'Izin Berobat Santri',
            'enabled' => true,
            'durasi_hari' => 1,
        ],
        'menjenguk_ortu_saudara_sakit' => [
            'label' => 'Izin Menjenguk Orang Tua / Saudara Kandung Sakit',
            'enabled' => true,
            'durasi_hari' => 3,
        ],
        'menjenguk_keluarga_sakit' => [
            'label' => 'Izin Menjenguk Kakek / Nenek / Paman / Bibi Sekandung Sakit',
            'enabled' => true,
            'durasi_hari' => 2,
        ],
    ];
}

function perizinan_syari_kategori_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done || !table_exists($pdo, 'perizinan')) {
        return;
    }
    if (column_exists($pdo, 'perizinan', 'syari_kategori')) {
        $done = true;

        return;
    }
    try {
        $pdo->exec('ALTER TABLE perizinan ADD COLUMN syari_kategori VARCHAR(64) NULL DEFAULT NULL');
    } catch (Throwable $e) {
        return;
    }
    if (column_exists($pdo, 'perizinan', 'syari_kategori')) {
        $done = true;
    }
}

/** @param array<string, mixed> $row */
function perizinan_syari_kategori_normalize_row(array $row, array $fallback): array
{
    return [
        'label' => trim((string) ($row['label'] ?? $fallback['label'] ?? '')) ?: (string) ($fallback['label'] ?? ''),
        'enabled' => array_key_exists('enabled', $row) ? !empty($row['enabled']) : !empty($fallback['enabled']),
        'durasi_hari' => max(1, (int) ($row['durasi_hari'] ?? $fallback['durasi_hari'] ?? 1)),
    ];
}

/** @return array<string, array<string, mixed>> */
function perizinan_syari_kategori_settings(PDO $pdo): array
{
    perizinan_syari_kategori_ensure_schema($pdo);
    $defaults = perizinan_syari_kategori_defaults();
    $raw = trim((string) app_setting($pdo, 'izin_syari_kategori_json', ''));
    $saved = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($saved)) {
        return $defaults;
    }

    $merged = [];
    foreach ($defaults as $kode => $def) {
        $row = is_array($saved[$kode] ?? null) ? $saved[$kode] : [];
        $merged[$kode] = perizinan_syari_kategori_normalize_row($row, $def);
    }
    foreach ($saved as $kode => $row) {
        if (!is_string($kode) || isset($merged[$kode]) || !is_array($row)) {
            continue;
        }
        $kodeNorm = perizinan_syari_kategori_sanitize_kode($kode);
        if ($kodeNorm === '') {
            continue;
        }
        $merged[$kodeNorm] = perizinan_syari_kategori_normalize_row($row, [
            'label' => (string) ($row['label'] ?? $kodeNorm),
            'enabled' => true,
            'durasi_hari' => 1,
        ]);
    }

    return $merged;
}

/** @return list<array{kode:string,label:string,durasi_hari:int}> */
function perizinan_syari_kategori_list_portal(PDO $pdo): array
{
    $out = [];
    foreach (perizinan_syari_kategori_settings($pdo) as $kode => $row) {
        if (empty($row['enabled'])) {
            continue;
        }
        $out[] = [
            'kode' => (string) $kode,
            'label' => (string) ($row['label'] ?? ''),
            'durasi_hari' => (int) ($row['durasi_hari'] ?? 1),
        ];
    }

    return $out;
}

/** @return array<string, mixed>|null */
function perizinan_syari_kategori_by_kode(PDO $pdo, string $kode): ?array
{
    $kode = perizinan_syari_kategori_sanitize_kode($kode);
    if ($kode === '') {
        return null;
    }
    $all = perizinan_syari_kategori_settings($pdo);

    return isset($all[$kode]) ? array_merge(['kode' => $kode], $all[$kode]) : null;
}

function perizinan_syari_kategori_label(PDO $pdo, string $kode): string
{
    $kat = perizinan_syari_kategori_by_kode($pdo, $kode);

    return $kat ? (string) ($kat['label'] ?? $kode) : $kode;
}

function perizinan_syari_kategori_sanitize_kode(string $raw): string
{
    $k = strtolower(trim($raw));
    $k = preg_replace('/[^a-z0-9_]+/', '_', $k) ?? '';
    $k = trim($k, '_');
    if ($k === '' || !preg_match('/^[a-z][a-z0-9_]{0,62}$/', $k)) {
        return '';
    }

    return $k;
}

function perizinan_syari_kategori_slug_from_label(string $label, array $usedKodes = []): string
{
    $slug = perizinan_syari_kategori_sanitize_kode($label);
    if ($slug === '') {
        $slug = 'keperluan';
    }
    $base = $slug;
    $n = 2;
    while (isset($usedKodes[$slug])) {
        $slug = $base . '_' . $n;
        $n++;
    }

    return $slug;
}

function perizinan_syari_kategori_normalize_kode(PDO $pdo, string $raw): string
{
    $k = perizinan_syari_kategori_sanitize_kode($raw);
    if ($k === '') {
        return '';
    }
    $all = perizinan_syari_kategori_settings($pdo);

    return isset($all[$k]) ? $k : '';
}

/** @param array<string, mixed> $post */
function perizinan_syari_kategori_save_from_post(PDO $pdo, array $post): void
{
    $items = $post['syari_item'] ?? null;
    if (!is_array($items)) {
        $items = perizinan_syari_kategori_legacy_post_rows($post);
    }

    $payload = [];
    $usedKodes = [];
    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (!empty($row['delete'])) {
            continue;
        }
        $label = trim((string) ($row['label'] ?? ''));
        if ($label === '') {
            continue;
        }
        $kode = perizinan_syari_kategori_sanitize_kode((string) ($row['kode'] ?? ''));
        if ($kode === '') {
            $kode = perizinan_syari_kategori_slug_from_label($label, $usedKodes);
        }
        while (isset($usedKodes[$kode])) {
            $kode = perizinan_syari_kategori_slug_from_label($kode . '_2', $usedKodes);
        }
        $usedKodes[$kode] = true;
        $payload[$kode] = [
            'label' => $label,
            'enabled' => !empty($row['enabled']) ? 1 : 0,
            'durasi_hari' => max(1, min(90, (int) ($row['durasi'] ?? $row['durasi_hari'] ?? 1))),
        ];
    }

    if ($payload === []) {
        $payload = perizinan_syari_kategori_defaults();
        foreach ($payload as $k => $v) {
            $payload[$k]['enabled'] = !empty($v['enabled']) ? 1 : 0;
        }
    }

    save_setting($pdo, 'izin_syari_kategori_json', json_encode($payload, JSON_UNESCAPED_UNICODE));
}

/** @return list<array<string, mixed>> */
function perizinan_syari_kategori_legacy_post_rows(array $post): array
{
    $defaults = perizinan_syari_kategori_defaults();
    $rows = [];
    foreach (array_keys($defaults) as $kode) {
        $rows[] = [
            'kode' => $kode,
            'label' => trim((string) ($post['syari_kat_' . $kode . '_label'] ?? $defaults[$kode]['label'] ?? $kode)),
            'enabled' => isset($post['syari_kat_' . $kode . '_enabled']) ? 1 : 0,
            'durasi' => max(1, (int) ($post['syari_kat_' . $kode . '_durasi'] ?? $defaults[$kode]['durasi_hari'] ?? 1)),
        ];
    }

    return $rows;
}

function perizinan_syari_kategori_hitung_hari(string $tanggalMulai, string $tanggalSelesai): int
{
    $ts1 = strtotime($tanggalMulai);
    $ts2 = strtotime($tanggalSelesai);
    if ($ts1 === false || $ts2 === false) {
        return 0;
    }
    if ($ts2 < $ts1) {
        return 0;
    }

    return (int) round(($ts2 - $ts1) / 86400) + 1;
}

/** Hitung tanggal selesai otomatis dari tanggal mulai dan durasi (hari) kategori. */
function perizinan_syari_kategori_tanggal_selesai(string $tanggalMulai, int $durasiHari): string
{
    $durasiHari = max(1, $durasiHari);
    $ts1 = strtotime($tanggalMulai);
    if ($ts1 === false) {
        return '';
    }

    return date('Y-m-d', strtotime('+' . ($durasiHari - 1) . ' days', $ts1));
}

function perizinan_syari_kategori_validasi_durasi(PDO $pdo, string $kode, string $tanggalMulai, string $tanggalSelesai): ?string
{
    $kat = perizinan_syari_kategori_by_kode($pdo, $kode);
    if (!$kat || empty($kat['enabled'])) {
        return 'Keperluan syar\'i tidak valid atau tidak aktif.';
    }
    if ($tanggalMulai === '' || $tanggalSelesai === '') {
        return 'Tanggal mulai dan selesai wajib diisi.';
    }
    $ts1 = strtotime($tanggalMulai);
    $ts2 = strtotime($tanggalSelesai);
    if ($ts1 === false || $ts2 === false || $ts2 < $ts1) {
        return 'Tanggal selesai harus sama atau setelah tanggal mulai.';
    }
    $hari = perizinan_syari_kategori_hitung_hari($tanggalMulai, $tanggalSelesai);
    $maxHari = (int) ($kat['durasi_hari'] ?? 1);
    if ($hari > $maxHari) {
        return 'Durasi izin untuk "' . ($kat['label'] ?? $kode) . '" maksimal ' . $maxHari . ' hari (Anda mengajukan ' . $hari . ' hari).';
    }

    return null;
}

function perizinan_syari_kategori_susun_alasan(PDO $pdo, string $kode, string $keteranganTambahan): string
{
    $kat = perizinan_syari_kategori_by_kode($pdo, $kode);
    $label = $kat ? (string) ($kat['label'] ?? $kode) : $kode;
    $detail = trim($keteranganTambahan);

    return $detail !== '' ? $label . ' — ' . $detail : $label;
}
