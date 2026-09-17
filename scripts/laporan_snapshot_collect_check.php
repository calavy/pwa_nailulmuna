<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/laporan_snapshot.php';
require_once __DIR__ . '/../helpers/keuangan_impor_ekspor.php';
require_once __DIR__ . '/../helpers/santri_export.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

ensure_pondok_settings_defaults($pdo);
$asOf = laporan_snapshot_as_of_date();
$collected = laporan_snapshot_collect_all($pdo, $asOf);

foreach (laporan_snapshot_tab_keys() as $key) {
    $pack = (array) ($collected[$key] ?? []);
    $err = trim((string) ($pack['error'] ?? ''));
    echo $key . "\trows=" . (int) ($pack['rows_count'] ?? 0);
    if ($err !== '') {
        echo "\tERR=" . $err;
    }
    echo "\n";
}

$masukDirect = keuangan_impor_ekspor_build_masuk_rows($pdo);
$masukSnap = (array) (($collected['Pemasukan_Detail']['rows'] ?? []));
echo 'pemasukan_row_parity=' . (count($masukDirect) === count($masukSnap) ? 'ok' : 'mismatch') . "\n";

$keluarDirect = keuangan_impor_ekspor_build_keluar_rows($pdo);
$keluarSnap = (array) (($collected['Pengeluaran_Detail']['rows'] ?? []));
echo 'pengeluaran_row_parity=' . (count($keluarDirect) === count($keluarSnap) ? 'ok' : 'mismatch') . "\n";

$sakuDirect = laporan_snapshot_uang_saku_to_rows($pdo);
$sakuSnap = (array) (($collected['Uang_Saku_Titipan']['rows'] ?? []));
echo 'uang_saku_row_parity=' . (count($sakuDirect) === count($sakuSnap) ? 'ok' : 'mismatch') . "\n";

$tabCount = count(laporan_snapshot_tab_keys());
echo 'tab_keys_count=' . $tabCount . ($tabCount === 11 ? ' ok' : ' mismatch') . "\n";

$santriDirect = santri_master_export_rows($pdo, true);
$santriSnap = (array) (($collected['Data_Master_Santri']['rows'] ?? []));
echo 'data_master_santri_parity=' . (count($santriDirect) === count($santriSnap) ? 'ok' : 'mismatch') . "\n";
