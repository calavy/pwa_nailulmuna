<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

/** @return array<string, array{label:string,enabled:bool}> */
function izin_tetap_hidmah_kategori_defaults(): array
{
    return [
        'pondok' => ['label' => 'Pondok', 'enabled' => true],
        'koperasi' => ['label' => 'Koperasi', 'enabled' => true],
        'ndalem' => ['label' => 'Ndalem', 'enabled' => true],
    ];
}

function izin_tetap_hidmah_kategori_sanitize_kode(string $raw): string
{
    $k = strtolower(trim($raw));
    $k = preg_replace('/[^a-z0-9_]+/', '_', $k) ?? '';
    $k = trim($k, '_');
    if ($k === '' || !preg_match('/^[a-z][a-z0-9_]{0,62}$/', $k)) {
        return '';
    }

    return $k;
}

/** @return array<string, array{label:string,enabled:bool}> */
function izin_tetap_hidmah_kategori_settings(PDO $pdo): array
{
    $defaults = izin_tetap_hidmah_kategori_defaults();
    $raw = trim((string) app_setting($pdo, 'izin_tetap_hidmah_kategori_json', ''));
    $saved = $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($saved)) {
        return $defaults;
    }

    $merged = [];
    foreach ($defaults as $kode => $def) {
        $row = is_array($saved[$kode] ?? null) ? $saved[$kode] : [];
        $merged[$kode] = [
            'label' => trim((string) ($row['label'] ?? $def['label'])) ?: $def['label'],
            'enabled' => array_key_exists('enabled', $row) ? !empty($row['enabled']) : !empty($def['enabled']),
        ];
    }
    foreach ($saved as $kode => $row) {
        if (!is_string($kode) || isset($merged[$kode]) || !is_array($row)) {
            continue;
        }
        $kodeNorm = izin_tetap_hidmah_kategori_sanitize_kode($kode);
        if ($kodeNorm === '') {
            continue;
        }
        $label = trim((string) ($row['label'] ?? $kodeNorm));
        if ($label === '') {
            continue;
        }
        $merged[$kodeNorm] = [
            'label' => $label,
            'enabled' => !empty($row['enabled']),
        ];
    }

    return $merged;
}

/** @return list<array{kode:string,label:string}> */
function izin_tetap_hidmah_kategori_list_aktif(PDO $pdo): array
{
    $out = [];
    foreach (izin_tetap_hidmah_kategori_settings($pdo) as $kode => $row) {
        if (empty($row['enabled'])) {
            continue;
        }
        $out[] = [
            'kode' => (string) $kode,
            'label' => (string) ($row['label'] ?? $kode),
        ];
    }

    return $out;
}

function izin_tetap_hidmah_kategori_label(PDO $pdo, string $kode): string
{
    $kode = izin_tetap_hidmah_kategori_sanitize_kode($kode);
    if ($kode === '') {
        return '';
    }
    $all = izin_tetap_hidmah_kategori_settings($pdo);

    return (string) ($all[$kode]['label'] ?? $kode);
}

function izin_tetap_hidmah_kategori_normalize_kode(PDO $pdo, string $raw): string
{
    $k = izin_tetap_hidmah_kategori_sanitize_kode($raw);
    if ($k === '') {
        return '';
    }
    $all = izin_tetap_hidmah_kategori_settings($pdo);
    if (!isset($all[$k]) || empty($all[$k]['enabled'])) {
        return '';
    }

    return $k;
}

/** @param array<string, mixed> $post */
function izin_tetap_hidmah_kategori_save_from_post(PDO $pdo, array $post): void
{
    $items = $post['hidmah_kat_item'] ?? null;
    if (!is_array($items)) {
        return;
    }

    $payload = [];
    $used = [];
    foreach ($items as $row) {
        if (!is_array($row) || !empty($row['delete'])) {
            continue;
        }
        $label = trim((string) ($row['label'] ?? ''));
        if ($label === '') {
            continue;
        }
        $kode = izin_tetap_hidmah_kategori_sanitize_kode((string) ($row['kode'] ?? ''));
        if ($kode === '') {
            $base = strtolower(preg_replace('/[^a-z0-9]+/', '_', $label) ?? 'kategori');
            $kode = trim($base, '_') ?: 'kategori';
        }
        while (isset($used[$kode])) {
            $kode .= '_2';
        }
        $used[$kode] = true;
        $payload[$kode] = [
            'label' => $label,
            'enabled' => !empty($row['enabled']) ? 1 : 0,
        ];
    }

    if ($payload === []) {
        $payload = izin_tetap_hidmah_kategori_defaults();
        foreach ($payload as $k => $v) {
            $payload[$k]['enabled'] = !empty($v['enabled']) ? 1 : 0;
        }
    }

    save_setting($pdo, 'izin_tetap_hidmah_kategori_json', json_encode($payload, JSON_UNESCAPED_UNICODE));
}
