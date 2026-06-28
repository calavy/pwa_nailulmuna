<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/akademik_rapor.php';
require_once __DIR__ . '/pkpps.php';

/** @return array<string, string> */
function pkpps_rapor_setting_defaults(): array
{
    return [
        'pkpps_rapor_judul_cetak' => 'Rapor PKPPS',
        'pkpps_rapor_judul_placeholder' => 'Rapor PKPPS Semester …',
        'pkpps_rapor_label_presensi' => 'Keaktivan PKPPS',
        'pkpps_rapor_label_setoran' => 'Setoran hafalan',
        'pkpps_rapor_label_tugas' => 'Hasil tugas PKPPS per pembimbing',
        'pkpps_rapor_info_portal' => 'Rapor program PKPPS. Termasuk keaktivan, setoran, dan nilai tugas PKPPS.',
    ];
}

/** @return list<string> */
function pkpps_rapor_setting_keys(): array
{
    return array_keys(pkpps_rapor_setting_defaults());
}

/** @return array<string, string> */
function pkpps_rapor_settings_values(PDO $pdo): array
{
    $defaults = pkpps_rapor_setting_defaults();
    $out = [];
    foreach ($defaults as $key => $default) {
        $out[$key] = trim((string) app_setting($pdo, $key, $default));
        if ($out[$key] === '') {
            $out[$key] = $default;
        }
    }

    return $out;
}

function pkpps_rapor_setting(PDO $pdo, string $key, string $default = ''): string
{
    $defaults = pkpps_rapor_setting_defaults();
    $fallback = $defaults[$key] ?? $default;
    $val = trim((string) app_setting($pdo, $key, $fallback));

    return $val !== '' ? $val : $fallback;
}

/**
 * @return array{ok:bool, message:string}
 */
function pkpps_rapor_settings_save(PDO $pdo, array $post): array
{
    foreach (pkpps_rapor_setting_defaults() as $key => $default) {
        if (!array_key_exists($key, $post)) {
            continue;
        }
        $val = trim((string) $post[$key]);
        if ($val === '' || $val === $default) {
            $st = $pdo->prepare('DELETE FROM app_settings WHERE setting_key = :k LIMIT 1');
            $st->execute(['k' => $key]);
        } else {
            save_setting($pdo, $key, $val);
        }
    }
    if (function_exists('app_settings_cache_reset')) {
        app_settings_cache_reset($pdo);
    }

    return ['ok' => true, 'message' => 'Pengaturan rapor PKPPS disimpan.'];
}

/** @return array<string, string> */
function pkpps_rapor_section_labels(PDO $pdo): array
{
    return [
        'presensi' => pkpps_rapor_setting($pdo, 'pkpps_rapor_label_presensi', 'Keaktivan PKPPS'),
        'setoran' => pkpps_rapor_setting($pdo, 'pkpps_rapor_label_setoran', 'Setoran hafalan'),
        'tugas' => pkpps_rapor_setting($pdo, 'pkpps_rapor_label_tugas', 'Hasil tugas PKPPS per pembimbing'),
    ];
}
