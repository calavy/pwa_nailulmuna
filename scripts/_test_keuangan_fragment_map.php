<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/keuangan_fragment.php';

$root = dirname(__DIR__);
$map = keuangan_fragment_route_map();
$missing = [];
foreach ($map as $path => $relative) {
    if (!is_file($root . '/' . $relative)) {
        $missing[] = $path . ' → ' . $relative;
    }
}

echo 'routes: ' . count($map) . PHP_EOL;
if ($missing !== []) {
    echo "missing files:\n" . implode("\n", $missing) . PHP_EOL;
    exit(1);
}
echo "ok\n";
