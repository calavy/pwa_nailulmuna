<?php

declare(strict_types=1);

/**
 * CLI: kosongkan pembayaran, pengeluaran, izin, presensi (santri tetap).
 *
 * php tools/kosongkan_data_operasional.php --confirm
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Jalankan dari terminal: php tools/kosongkan_data_operasional.php --confirm\n");
    exit(1);
}

$confirm = in_array('--confirm', $argv ?? [], true);
if (!$confirm) {
    echo "PERINGATAN: Menghapus semua data pembayaran, pengeluaran, izin, dan presensi.\n";
    echo "Data santri TIDAK dihapus.\n";
    echo "Lanjutkan dengan: php tools/kosongkan_data_operasional.php --confirm\n";
    exit(1);
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app_reset_operasional.php';

$result = app_kosongkan_data_operasional($pdo);

if (!$result['ok']) {
    fwrite(STDERR, $result['message'] . "\n");
    exit(1);
}

echo $result['message'] . "\n\n";
foreach ($result['deleted'] as $table => $count) {
    echo sprintf("  %-32s %d baris\n", $table, $count);
}
echo "\nSelesai.\n";
