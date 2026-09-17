<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/santri_export.php';

require_roles(['admin', 'pengurus']);

$onlyAktif = !isset($_GET['semua']) || (string) ($_GET['semua'] ?? '') !== '1';

require_once __DIR__ . '/../helpers/santri_list_sort.php';
santri_list_sort_mode($_GET['santri_sort'] ?? null);

$rows = santri_master_export_fetch($pdo, $onlyAktif);
$headers = santri_master_export_headers();

$fn = 'Data_Santri_Aktif_' . date('Y-m-d') . ($onlyAktif ? '' : '_semua') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $fn . '"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, $headers, ';');
foreach ($rows as $r) {
    fputcsv($out, santri_master_export_row_from_record($r), ';');
}
fclose($out);
