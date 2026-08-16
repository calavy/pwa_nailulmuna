<?php
/**
 * Smoke test Bagian 3 — menu mega-kategori & ACL filter.
 * Jalankan: php scripts/_test_menu_bagian3.php
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';

$raw = require __DIR__ . '/../includes/menu_data.php';
$structure = $raw['menuStructure'];

$groupIds = [];
foreach ($structure as $node) {
    if (($node['type'] ?? '') === 'group') {
        $groupIds[] = (string) ($node['id'] ?? '');
    }
}

$expected = [
    'menu-grp-santri',
    'menu-grp-ketertiban',
    'menu-grp-keuangan',
    'menu-grp-akademik',
    'menu-grp-yayasan',
];

$missing = array_diff($expected, $groupIds);
$extra = array_diff($groupIds, $expected);

echo "Mega-groups: " . implode(', ', $groupIds) . PHP_EOL;
if ($missing !== []) {
    echo "FAIL missing: " . implode(', ', $missing) . PHP_EOL;
    exit(1);
}
if ($extra !== []) {
    echo "FAIL unexpected groups: " . implode(', ', $extra) . PHP_EOL;
    exit(1);
}

$aliases = menu_hub_id_aliases();
$resolved = menu_hub_resolve_id('menu-grp-pengaturan');
if ($resolved !== 'menu-grp-yayasan') {
    echo "FAIL alias pengaturan -> yayasan, got: {$resolved}" . PHP_EOL;
    exit(1);
}
echo "Alias menu-grp-pengaturan -> {$resolved} OK" . PHP_EOL;

// Simulasi ACL terbatas (keuangan saja)
$_SESSION['user'] = ['id' => 99999, 'role' => 'pengurus', 'is_super_admin' => 0];
$allowedMap = [
    'dashboard' => 1,
    'keuangan_laporan' => 1,
    'keuangan_transaksi' => 1,
];
$filtered = array_filter(
    $raw['menuItems'],
    static fn(string $label, string $path) => app_acl_menu_path_allowed($path, $raw['permissionPathMap'], $allowedMap),
    ARRAY_FILTER_USE_BOTH
);
$hasIkhtibar = isset($filtered['/akademik/ikhtibar.php']);
$hasKeuangan = isset($filtered['/keuangan/index.php']);
if ($hasIkhtibar) {
    echo "FAIL bendahara simulasi masih lihat ikhtibar" . PHP_EOL;
    exit(1);
}
if (!$hasKeuangan) {
    echo "FAIL bendahara simulasi tidak lihat keuangan" . PHP_EOL;
    exit(1);
}
echo "ACL filter bendahara simulasi OK (keuangan ya, ikhtibar tidak)" . PHP_EOL;

unset($_SESSION['user']);
echo "All smoke tests passed." . PHP_EOL;
