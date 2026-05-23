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
            'path' => '/presensi/scan.php',
            'class' => 'dash-quick-action dash-quick-action--primary',
            'icon' => 'fa-qrcode',
            'label' => 'Scan presensi',
        ],
        [
            'path' => '/perizinan/index.php',
            'class' => 'dash-quick-action',
            'icon' => 'fa-person-walking-arrow-right',
            'label' => 'Perizinan',
        ],
        [
            'path' => '/santri/index.php',
            'class' => 'dash-quick-action',
            'icon' => 'fa-user-group',
            'label' => 'Data santri',
        ],
        [
            'path' => '/keuangan/index.php',
            'class' => 'dash-quick-action',
            'icon' => 'fa-wallet',
            'label' => 'Keuangan',
        ],
        [
            'path' => '/pembayaran/tagihan_syahriyah.php',
            'class' => 'dash-quick-action',
            'icon' => 'fa-receipt',
            'label' => 'Tagihan syahriyah',
        ],
        [
            'path' => '/jadwal/index.php',
            'class' => 'dash-quick-action',
            'icon' => 'fa-calendar-days',
            'label' => 'Jadwal',
        ],
    ];
}

/** Path prioritas untuk tile menu cepat (urutan tampil). */
function dashboard_quick_tile_priority_paths(): array
{
    return [
        '/dashboard.php',
        '/presensi/scan.php',
        '/santri/index.php',
        '/perizinan/index.php',
        '/perizinan/permohonan.php',
        '/keuangan/index.php',
        '/keuangan/pembayaran.php',
        '/pembayaran/tagihan_syahriyah.php',
        '/pembayaran/riwayat.php',
        '/keuangan/neraca.php',
        '/keuangan/arus-kas.php',
        '/pembayaran/laporan.php',
        '/keuangan/pemasukan.php',
        '/keuangan/pengeluaran.php',
        '/jadwal/index.php',
        '/poin/input.php',
        '/poin/rekap.php',
        '/rekap/index.php',
        '/akademik/hafalan.php',
        '/settings/pesantren.php',
        '/keuangan/pengaturan.php',
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
