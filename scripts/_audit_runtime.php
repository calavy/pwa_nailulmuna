<?php
/** One-off audit helper — delete after audit if desired */
require_once __DIR__ . '/../config/database.php';

echo "=== USERS (sample) ===\n";
$rows = $pdo->query('SELECT id, username, role, is_super_admin FROM users ORDER BY id LIMIT 15')->fetchAll();
foreach ($rows as $r) {
    echo implode(' | ', $r) . "\n";
}

echo "\n=== PEEMBIMBING with QR (sample) ===\n";
if (table_exists($pdo, 'pembimbing')) {
    $pb = $pdo->query('SELECT id, nip, nama_pembimbing, qr FROM pembimbing WHERE COALESCE(is_aktif,1)=1 LIMIT 5')->fetchAll();
    foreach ($pb as $p) {
        echo ($p['nip'] ?? '') . ' | ' . ($p['nama_pembimbing'] ?? '') . ' | qr=' . ($p['qr'] ?? '') . "\n";
    }
}

echo "\n=== PAGE RENDER (CLI include smoke) ===\n";
$pages = [
    'yayasan/keaktifan.php',
    'pembimbing/dashboard.php',
    'presensi/scan.php',
];
foreach ($pages as $rel) {
    $path = realpath(__DIR__ . '/../' . $rel);
    if (!$path) {
        echo "$rel | MISSING\n";
        continue;
    }
    $lint = shell_exec('"' . PHP_BINARY . '" -l ' . escapeshellarg($path) . ' 2>&1');
    echo "$rel | lint=" . (str_contains((string)$lint, 'No syntax errors') ? 'OK' : 'FAIL') . "\n";
}
