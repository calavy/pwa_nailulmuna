<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/perizinan_jenis.php';

/** @return list<string> */
function perizinan_syari_kategori_kodes(): array
{
    return [
        'walimah_saudara',
        'musibah_saudara',
        'berobat_santri',
        'menjenguk_ortu_saudara_sakit',
        'menjenguk_keluarga_sakit',
    ];
}

/** @return array<string, array{label:string,enabled:bool,durasi_hari:int,alpa_max:int,alpa_hari:int}> */
function perizinan_syari_kategori_defaults(): array
{
    return [
        'walimah_saudara' => [
            'label' => 'Izin Walimah Saudara Kandung',
            'enabled' => true,
            'durasi_hari' => 2,
            'alpa_max' => 3,
            'alpa_hari' => 4,
        ],
        'musibah_saudara' => [
            'label' => 'Izin Musibah / Saudara Kandung Wafat',
            'enabled' => true,
            'durasi_hari' => 7,
            'alpa_max' => 3,
            'alpa_hari' => 4,
        ],
        'berobat_santri' => [
            'label' => 'Izin Berobat Santri',
            'enabled' => true,
            'durasi_hari' => 1,
            'alpa_max' => 3,
            'alpa_hari' => 4,
        ],
        'menjenguk_ortu_saudara_sakit' => [
            'label' => 'Izin Menjenguk Orang Tua / Saudara Kandung Sakit',
            'enabled' => true,
            'durasi_hari' => 3,
            'alpa_max' => 3,
            'alpa_hari' => 4,
        ],
        'menjenguk_keluarga_sakit' => [
            'label' => 'Izin Menjenguk Kakek / Nenek / Paman / Bibi Sekandung Sakit',
            'enabled' => true,
            'durasi_hari' => 2,
            'alpa_max' => 3,
            'alpa_hari' => 4,
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
        $merged[$kode] = [
            'label' => trim((string) ($row['label'] ?? $def['label'])) ?: $def['label'],
            'enabled' => array_key_exists('enabled', $row) ? !empty($row['enabled']) : $def['enabled'],
            'durasi_hari' => max(1, (int) ($row['durasi_hari'] ?? $def['durasi_hari'])),
            'alpa_max' => max(0, (int) ($row['alpa_max'] ?? $def['alpa_max'])),
            'alpa_hari' => max(1, (int) ($row['alpa_hari'] ?? $def['alpa_hari'])),
        ];
    }

    return $merged;
}

/** @return list<array{kode:string,label:string,durasi_hari:int,alpa_max:int,alpa_hari:int}> */
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
            'alpa_max' => (int) ($row['alpa_max'] ?? 0),
            'alpa_hari' => (int) ($row['alpa_hari'] ?? 4),
        ];
    }

    return $out;
}

/** @return array<string, mixed>|null */
function perizinan_syari_kategori_by_kode(PDO $pdo, string $kode): ?array
{
    $kode = trim($kode);
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

function perizinan_syari_kategori_normalize_kode(string $raw): string
{
    $k = strtolower(trim($raw));

    return in_array($k, perizinan_syari_kategori_kodes(), true) ? $k : '';
}

/** @param array<string, mixed> $post */
function perizinan_syari_kategori_save_from_post(PDO $pdo, array $post): void
{
    $defaults = perizinan_syari_kategori_defaults();
    $payload = [];
    foreach (array_keys($defaults) as $kode) {
        $payload[$kode] = [
            'label' => (string) ($defaults[$kode]['label'] ?? $kode),
            'enabled' => isset($post['syari_kat_' . $kode . '_enabled']) ? 1 : 0,
            'durasi_hari' => max(1, (int) ($post['syari_kat_' . $kode . '_durasi'] ?? $defaults[$kode]['durasi_hari'])),
            'alpa_max' => max(0, (int) ($post['syari_kat_' . $kode . '_alpa_max'] ?? $defaults[$kode]['alpa_max'])),
            'alpa_hari' => max(1, (int) ($post['syari_kat_' . $kode . '_alpa_hari'] ?? $defaults[$kode]['alpa_hari'])),
        ];
    }
    save_setting($pdo, 'izin_syari_kategori_json', json_encode($payload, JSON_UNESCAPED_UNICODE));
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

/**
 * Ambil batas ALPA khusus kategori syar'i (null = pakai aturan umum izin syar'i).
 *
 * @return array{max:int,hari:int,label:string}|null
 */
function perizinan_syari_kategori_alpa_batas(PDO $pdo, string $kode): ?array
{
    $kat = perizinan_syari_kategori_by_kode($pdo, $kode);
    if (!$kat || empty($kat['enabled'])) {
        return null;
    }

    return [
        'max' => max(0, (int) ($kat['alpa_max'] ?? 0)),
        'hari' => max(1, (int) ($kat['alpa_hari'] ?? 4)),
        'label' => (string) ($kat['label'] ?? 'izin syar\'i'),
    ];
}
