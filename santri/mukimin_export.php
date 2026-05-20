<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/akademik.php';
require_once __DIR__ . '/../helpers/excel.php';

require_roles(['admin', 'pengurus']);

$filters = [
    'cari' => trim((string) ($_GET['cari'] ?? '')),
    'dusun' => trim((string) ($_GET['dusun'] ?? '')),
    'desa_kelurahan' => trim((string) ($_GET['desa_kelurahan'] ?? '')),
    'kecamatan' => trim((string) ($_GET['kecamatan'] ?? '')),
    'kabupaten' => trim((string) ($_GET['kabupaten'] ?? '')),
    'th_masuk' => trim((string) ($_GET['th_masuk'] ?? '')),
    'th_keluar' => trim((string) ($_GET['th_keluar'] ?? '')),
    'keterangan' => trim((string) ($_GET['keterangan'] ?? '')),
];
$rows = alumni_fetch_rows($pdo, $filters);

$fn = 'Data_Mukimin_' . date('Y-m-d') . '.xlsx';
send_xlsx_download($fn, alumni_db_rows_to_xlsx($rows), 'Mukimin');
