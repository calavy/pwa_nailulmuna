<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/keuangan_perbaikan_kas.php';
require_once __DIR__ . '/../helpers/keuangan_diagnostik.php';

echo "ringkas...\n";
$r = keuangan_perbaikan_kas_ringkas($pdo);
echo 'jumlah=' . ($r['jumlah'] ?? 0) . ' nb=' . count($r['nominal_berlebihan'] ?? []) . "\n";

echo "diagnostik...\n";
$d = keuangan_diagnostik_menyeluruh($pdo);
echo 'items=' . count($d['items'] ?? []) . "\n";

echo "OK\n";
