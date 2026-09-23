<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/laporan_snapshot.php';

$test = laporan_snapshot_test_google_access($pdo);
echo 'ok=' . (($test['ok'] ?? false) ? '1' : '0') . PHP_EOL;
echo 'client_email=' . (string) ($test['client_email'] ?? '') . PHP_EOL;
echo 'spreadsheet_id=' . (string) ($test['spreadsheet_id'] ?? '') . PHP_EOL;
foreach ((array) ($test['steps'] ?? []) as $label => $info) {
    $flag = !empty($info['ok']) ? 'OK' : 'FAIL';
    echo $flag . ' ' . $label . ': ' . (string) ($info['message'] ?? '') . PHP_EOL;
}
if (!($test['ok'] ?? false)) {
    echo 'error=' . (string) ($test['error'] ?? '') . PHP_EOL;
    exit(1);
}

exit(0);
