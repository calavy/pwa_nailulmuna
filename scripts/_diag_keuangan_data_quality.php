<?php

declare(strict_types=1);

/**
 * Diagnostik kualitas data keuangan (read-only).
 *
 *   php scripts/_diag_keuangan_data_quality.php
 *   php scripts/_diag_keuangan_data_quality.php --csv=C:\tmp\keu_dq.csv
 */

$root = dirname(__DIR__);
require_once $root . '/config/database.php';
require_once $root . '/helpers/keuangan_data_quality.php';
require_once $root . '/helpers/keuangan_tanggal_validasi.php';
require_once $root . '/helpers/keuangan_transaksi.php';

if (!($pdo instanceof PDO)) {
    echo "FAIL: DB tidak tersedia\n";
    exit(1);
}

$csvPath = null;
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--csv=')) {
        $csvPath = substr($arg, 6);
    }
}

keuangan_ensure_schema_deferred($pdo);

$badDates = keuangan_data_quality_bad_dates($pdo);
$dupSantri = keuangan_data_quality_santri_duplicates($pdo);
$noBukti = keuangan_data_quality_pengeluaran_tanpa_bukti($pdo);
$posOut = keuangan_data_quality_pos_outliers($pdo);
$bounds = keuangan_tanggal_ta_bounds($pdo);

echo "=== Kualitas data keuangan ===\n";
echo 'TA bounds: ' . $bounds['min'] . ' .. ' . $bounds['max'] . "\n";
echo 'Tanggal bermasalah: ' . count($badDates) . "\n";
echo 'Santri NIS shadow/duplikat aktif: ' . count($dupSantri) . "\n";
echo 'Pengeluaran tanpa no_bukti: ' . count($noBukti) . "\n";
echo 'Outlier POS (flag): ' . count($posOut) . "\n";

if ($badDates !== []) {
    echo "\n-- Sample tanggal (max 5) --\n";
    foreach (array_slice($badDates, 0, 5) as $r) {
        echo sprintf(
            "  [%s #%d] %s (%s)\n",
            (string) $r['kind'],
            (int) $r['id'],
            (string) $r['tanggal'],
            (string) $r['reason']
        );
    }
}

if ($csvPath !== null && $csvPath !== '') {
    $fh = fopen($csvPath, 'wb');
    if ($fh === false) {
        echo "WARN: tidak bisa tulis CSV\n";
    } else {
        fputcsv($fh, ['section', 'payload_json']);
        foreach ($badDates as $r) {
            fputcsv($fh, ['bad_date', json_encode($r, JSON_UNESCAPED_UNICODE)]);
        }
        foreach ($dupSantri as $r) {
            fputcsv($fh, ['santri_dup', json_encode($r, JSON_UNESCAPED_UNICODE)]);
        }
        foreach ($noBukti as $r) {
            fputcsv($fh, ['no_bukti', json_encode($r, JSON_UNESCAPED_UNICODE)]);
        }
        foreach ($posOut as $r) {
            fputcsv($fh, ['pos_outlier', json_encode($r, JSON_UNESCAPED_UNICODE)]);
        }
        fclose($fh);
        echo "\nCSV: {$csvPath}\n";
    }
}

exit(0);
