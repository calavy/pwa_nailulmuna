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
