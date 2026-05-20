<?php

declare(strict_types=1);

/**
 * Menu cepat dashboard — hanya path yang lolos filter ACL (menuItems).
 */

/** @return list<array{path:string,class:string,icon:string,label:string}> */
function dashboard_quick_action_definitions(): array
{
    return [
        [
            'path' => '/pwa_nailulmuna/presensi/scan.php',
            'class' => 'btn btn-primary shadow-sm',
            'icon' => 'fa-qrcode',
            'label' => 'Scan presensi',
        ],
        [
            'path' => '/pwa_nailulmuna/perizinan/index.php',
            'class' => 'btn btn-outline-secondary',
            'icon' => 'fa-person-walking-arrow-right',
            'label' => 'Perizinan',
        ],
        [
            'path' => '/pwa_nailulmuna/santri/index.php',
            'class' => 'btn btn-outline-secondary',
            'icon' => 'fa-user-group',
            'label' => 'Data santri',
        ],
        [
            'path' => '/pwa_nailulmuna/keuangan/index.php',
            'class' => 'btn btn-outline-secondary',
            'icon' => 'fa-wallet',
            'label' => 'Keuangan',
        ],
        [
            'path' => '/pwa_nailulmuna/pembayaran/tagihan_syahriyah.php',
            'class' => 'btn btn-outline-secondary',
            'icon' => 'fa-receipt',
            'label' => 'Tagihan syahriyah',
        ],
        [
            'path' => '/pwa_nailulmuna/jadwal/index.php',
            'class' => 'btn btn-outline-secondary',
            'icon' => 'fa-calendar-days',
            'label' => 'Jadwal',
        ],
    ];
}

/** Path prioritas untuk tile menu cepat (urutan tampil). */
function dashboard_quick_tile_priority_paths(): array
{
    return [
        '/pwa_nailulmuna/dashboard.php',
        '/pwa_nailulmuna/presensi/scan.php',
        '/pwa_nailulmuna/santri/index.php',
        '/pwa_nailulmuna/perizinan/index.php',
        '/pwa_nailulmuna/perizinan/permohonan.php',
        '/pwa_nailulmuna/keuangan/index.php',
        '/pwa_nailulmuna/keuangan/pembayaran.php',
        '/pwa_nailulmuna/pembayaran/tagihan_syahriyah.php',
        '/pwa_nailulmuna/pembayaran/riwayat.php',
        '/pwa_nailulmuna/keuangan/neraca.php',
        '/pwa_nailulmuna/keuangan/arus-kas.php',
        '/pwa_nailulmuna/pembayaran/laporan.php',
        '/pwa_nailulmuna/keuangan/pemasukan.php',
        '/pwa_nailulmuna/keuangan/pengeluaran.php',
        '/pwa_nailulmuna/jadwal/index.php',
        '/pwa_nailulmuna/poin/input.php',
        '/pwa_nailulmuna/poin/rekap.php',
        '/pwa_nailulmuna/rekap/index.php',
        '/pwa_nailulmuna/akademik/hafalan.php',
        '/pwa_nailulmuna/settings/pesantren.php',
        '/pwa_nailulmuna/keuangan/pengaturan.php',
    ];
}

function user_can_access_menu_path(string $path, array $menuItems): bool
{
    return $path !== '' && isset($menuItems[$path]);
}

/**
 * @param callable(string): string $iconForPath
 * @return list<array{path:string,label:string,icon:string}>
 */
function dashboard_build_quick_tiles(
    array $menuItems,
    array $menuStructure,
    callable $iconForPath,
    int $maxTiles = 28
): array {
    $tiles = [];
    $seen = [];
    $push = static function (string $path) use (&$tiles, &$seen, $menuItems, $iconForPath, $maxTiles): void {
        if ($path === '' || isset($seen[$path]) || !isset($menuItems[$path]) || count($tiles) >= $maxTiles) {
            return;
        }
        $seen[$path] = true;
        $tiles[] = [
            'path' => $path,
            'label' => (string) $menuItems[$path],
            'icon' => $iconForPath($path),
        ];
    };

    foreach (dashboard_quick_tile_priority_paths() as $path) {
        $push($path);
    }

    foreach ($menuStructure as $node) {
        $type = (string) ($node['type'] ?? 'item');
        if ($type === 'item') {
            $push((string) ($node['path'] ?? ''));
            continue;
        }
        $sections = $node['sections'] ?? null;
        if (is_array($sections) && $sections !== []) {
            foreach ($sections as $sec) {
                foreach ((array) ($sec['paths'] ?? []) as $p) {
                    if (is_string($p)) {
                        $push($p);
                    }
                }
            }
        } else {
            foreach ((array) ($node['paths'] ?? []) as $p) {
                if (is_string($p)) {
                    $push($p);
                }
            }
        }
    }

    return $tiles;
}

/**
 * @return list<array{path:string,class:string,icon:string,label:string}>
 */
function dashboard_filter_quick_actions(array $menuItems): array
{
    $out = [];
    foreach (dashboard_quick_action_definitions() as $act) {
        $path = (string) ($act['path'] ?? '');
        if (user_can_access_menu_path($path, $menuItems)) {
            $out[] = $act;
        }
    }

    return $out;
}
