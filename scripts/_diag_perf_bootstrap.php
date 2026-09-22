<?php

declare(strict_types=1);

/**
 * Ukur komponen bootstrap yang memengaruhi TTFB (hosting).
 *
 *   php scripts/_diag_perf_bootstrap.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/app_cache.php';

$_SESSION['user'] = ['id' => 1, 'role' => 'admin', 'nama' => 'Diag'];

function bench(string $label, callable $fn): float
{
    $t0 = microtime(true);
    $fn();
    $ms = (microtime(true) - $t0) * 1000;
    echo sprintf("%-32s %6.1f ms\n", $label, $ms);

    return $ms;
}

echo "=== Bootstrap perf (local CLI) ===\n\n";

bench('SHOW TABLES (pondok_schema_tables)', static fn () => pondok_schema_tables($pdo));
app_settings_cache($pdo, true);
bench('app_settings_cache (cold DB)', static fn () => app_settings_cache($pdo, true));
bench('app_settings_cache (warm static)', static fn () => app_settings_cache($pdo));
bench('app_menu_pack', static fn () => app_menu_pack($pdo));

$settingsCount = (int) ($pdo->query('SELECT COUNT(*) FROM app_settings')->fetchColumn() ?: 0);
echo "\napp_settings rows: {$settingsCount}\n";
echo 'schema_skip_migrations: ' . (app_skip_deferred_schema_migrations($pdo) ? 'yes' : 'no') . "\n";
echo "Done.\n";
