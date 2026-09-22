<?php

declare(strict_types=1);

/**
 * Cache sesi & memori yang memengaruhi kecepatan navigasi.
 * Panggil setelah deploy, ubah pengaturan besar, atau jika data tampak basi.
 */

/** @deprecated Gunakan app_menu_pack_invalidate() */
function app_menu_pack_reset(): void
{
    if (function_exists('app_menu_pack_invalidate')) {
        app_menu_pack_invalidate();
    }
}

/**
 * Hapus entri cache sesi yang sudah kedaluwarsa (ringan, aman tiap request).
 */
function app_performance_cache_prune_expired(): int
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return 0;
    }
    $now = time();
    $removed = 0;
    foreach (array_keys($_SESSION) as $sk) {
        if (!is_string($sk)) {
            continue;
        }
        if (!str_starts_with($sk, 'keu_alokasi_real_')) {
            continue;
        }
        $entry = $_SESSION[$sk] ?? null;
        if (!is_array($entry)) {
            unset($_SESSION[$sk]);
            $removed++;
            continue;
        }
        if ((int) ($entry['expires'] ?? 0) < $now) {
            unset($_SESSION[$sk]);
            $removed++;
        }
    }
    $dash = $_SESSION['keuangan_dash_snap_cache'] ?? null;
    if (is_array($dash) && (int) ($dash['expires'] ?? 0) > 0 && (int) $dash['expires'] < $now) {
        unset($_SESSION['keuangan_dash_snap_cache']);
        $removed++;
    }
    if (isset($_SESSION['tagihan_syahriyah_list_v1']) && is_array($_SESSION['tagihan_syahriyah_list_v1'])) {
        foreach ($_SESSION['tagihan_syahriyah_list_v1'] as $tk => $entry) {
            if (!is_array($entry) || (int) ($entry['expires'] ?? 0) < $now) {
                unset($_SESSION['tagihan_syahriyah_list_v1'][$tk]);
                $removed++;
            }
        }
        if ($_SESSION['tagihan_syahriyah_list_v1'] === []) {
            unset($_SESSION['tagihan_syahriyah_list_v1']);
        }
    }
    if (isset($_SESSION['pondok_bulan_slots_v1']) && is_array($_SESSION['pondok_bulan_slots_v1'])) {
        foreach ($_SESSION['pondok_bulan_slots_v1'] as $sk => $entry) {
            if (!is_array($entry) || (int) ($entry['expires'] ?? 0) < $now) {
                unset($_SESSION['pondok_bulan_slots_v1'][$sk]);
                $removed++;
            }
        }
        if ($_SESSION['pondok_bulan_slots_v1'] === []) {
            unset($_SESSION['pondok_bulan_slots_v1']);
        }
    }
    $ta = $_SESSION['pondok_ta_options_cache_v1'] ?? null;
    if (is_array($ta) && (int) ($ta['expires'] ?? 0) > 0 && (int) $ta['expires'] < $now) {
        unset($_SESSION['pondok_ta_options_cache_v1']);
        $removed++;
    }

    return $removed;
}

/**
 * @param array{schema_flags?:bool,opcache?:bool,all_users_acl?:bool} $options
 * @return array{cleared:int,pruned:int,opcache:bool}
 */
function app_performance_cache_clear(PDO $pdo, array $options = []): array
{
    $schemaFlags = (bool) ($options['schema_flags'] ?? false);
    $tryOpcache = (bool) ($options['opcache'] ?? false);
    $allUsersAcl = (bool) ($options['all_users_acl'] ?? true);

    $cleared = 0;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $exactKeys = [
            'keuangan_dash_snap_cache',
            'keuangan_pos_options_v1',
            'tagihan_syahriyah_list_v1',
            'pondok_bulan_slots_v1',
            'pondok_ta_options_cache_v1',
            'app_header_brand_v1',
            'push_fcm_cfg_cache_v1',
        ];
        foreach ($exactKeys as $k) {
            if (isset($_SESSION[$k])) {
                unset($_SESSION[$k]);
                $cleared++;
            }
        }
        if ($schemaFlags) {
            foreach ([
                'app_schema_ready_v1',
                'keuangan_schema_ready_v1',
                'pondok_santri_identity_v1',
                'pondok_kelas_keuangan_v1',
                'kelas_keuangan_cleanup_v1',
                'pondok_hijri_mappings_v1',
                'pondok_santri_compat_v1',
            ] as $k) {
                if (isset($_SESSION[$k])) {
                    unset($_SESSION[$k]);
                    $cleared++;
                }
            }
        }
        $prefixes = ['keu_alokasi_real_', 'user_profil_loaded_'];
        if ($allUsersAcl) {
            $prefixes[] = 'acl_map_';
            $prefixes[] = 'menu_items_acl_';
        }
        foreach (array_keys($_SESSION) as $sk) {
            if (!is_string($sk)) {
                continue;
            }
            foreach ($prefixes as $prefix) {
                if (str_starts_with($sk, $prefix)) {
                    unset($_SESSION[$sk]);
                    $cleared++;
                    break;
                }
            }
        }
    }

    if (function_exists('app_settings_cache')) {
        app_settings_cache($pdo, true);
    }
    if (function_exists('app_menu_pack_invalidate')) {
        app_menu_pack_invalidate();
    } elseif (function_exists('app_menu_pack_reset')) {
        app_menu_pack_reset();
    }

    $opcacheOk = false;
    if ($tryOpcache && function_exists('opcache_reset')) {
        $opcacheOk = @opcache_reset();
    }

    $pruned = app_performance_cache_prune_expired();

    return ['cleared' => $cleared, 'pruned' => $pruned, 'opcache' => $opcacheOk];
}

/** TTL cache app_settings antar-request (hosting). Override: env PONDOK_SETTINGS_CACHE_SEC (30–600). */
function app_settings_shared_cache_ttl(): int
{
    $raw = getenv('PONDOK_SETTINGS_CACHE_SEC');
    if ($raw !== false && trim((string) $raw) !== '') {
        return max(30, min(600, (int) $raw));
    }

    return 120;
}

function app_settings_shared_cache_id(PDO $pdo): string
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }
    $db = '';
    try {
        $db = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
    } catch (Throwable $e) {
        $db = 'default';
    }
    $id = md5($db . '|' . (string) (getenv('PONDOK_SETTINGS_CACHE_SALT') ?: 'pondok'));

    return $id;
}

/** @return array<string, string>|null */
function app_settings_shared_cache_read(PDO $pdo): ?array
{
    $ttl = app_settings_shared_cache_ttl();
    $cacheId = app_settings_shared_cache_id($pdo);
    $apcuKey = 'pondok_app_settings_' . $cacheId;

    if (function_exists('apcu_fetch')) {
        $ok = false;
        $payload = apcu_fetch($apcuKey, $ok);
        if ($ok && is_array($payload) && isset($payload['expires'], $payload['data']) && is_array($payload['data'])) {
            if ((int) $payload['expires'] >= time()) {
                return $payload['data'];
            }
            apcu_delete($apcuKey);
        }
    }

    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pondok_settings_' . $cacheId . '.json';
    if (!is_readable($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['expires'], $decoded['data']) || !is_array($decoded['data'])) {
        return null;
    }
    if ((int) $decoded['expires'] < time()) {
        @unlink($path);

        return null;
    }

    return $decoded['data'];
}

/** @param array<string, string> $data */
function app_settings_shared_cache_write(PDO $pdo, array $data): void
{
    $ttl = app_settings_shared_cache_ttl();
    $cacheId = app_settings_shared_cache_id($pdo);
    $payload = ['expires' => time() + $ttl, 'data' => $data];
    $apcuKey = 'pondok_app_settings_' . $cacheId;

    if (function_exists('apcu_store')) {
        apcu_store($apcuKey, $payload, $ttl + 30);
    }

    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pondok_settings_' . $cacheId . '.json';
    @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE), LOCK_EX);
}

function app_settings_shared_cache_bust(PDO $pdo): void
{
    $cacheId = app_settings_shared_cache_id($pdo);
    $apcuKey = 'pondok_app_settings_' . $cacheId;
    if (function_exists('apcu_delete')) {
        apcu_delete($apcuKey);
    }
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pondok_settings_' . $cacheId . '.json';
    if (is_file($path)) {
        @unlink($path);
    }
}
