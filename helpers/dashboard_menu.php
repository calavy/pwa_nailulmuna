<?php

declare(strict_types=1);

/**
 * Aksi cepat & pencarian modul dashboard — hanya path yang lolos filter ACL (menuItems).
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
            'path' => '/keuangan/pembayaran.php',
            'class' => 'dash-quick-action',
            'icon' => 'fa-circle-plus',
            'label' => 'Input pembayaran',
        ],
        [
            'path' => '/jadwal/index.php',
            'class' => 'dash-quick-action',
            'icon' => 'fa-calendar-days',
            'label' => 'Jadwal',
        ],
    ];
}

/**
 * Semua modul yang boleh diakses, untuk pencarian cepat dashboard.
 *
 * @param callable(string): string $iconForPath
 * @return list<array{path:string,label:string,icon:string}>
 */
function dashboard_build_search_items(array $menuItems, callable $iconForPath): array
{
    $items = [];
    foreach ($menuItems as $path => $label) {
        if (!is_string($path) || $path === '' || !is_string($label) || trim($label) === '') {
            continue;
        }
        $items[] = [
            'path' => $path,
            'label' => trim($label),
            'icon' => $iconForPath($path),
        ];
    }
    usort($items, static fn(array $a, array $b): int => strcasecmp($a['label'], $b['label']));

    return $items;
}

/** Path prioritas untuk tile menu (legacy). */
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
        '/keuangan/neraca-perbaikan.php',
        '/keuangan/arus-kas.php',
        '/keuangan/rekap-kas-bulan.php',
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
