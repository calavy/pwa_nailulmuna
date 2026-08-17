<?php

declare(strict_types=1);

require_once __DIR__ . '/../helpers/app.php';

$target = app_href('/rekap/alpa_santri_putra.php');
$query = $_SERVER['QUERY_STRING'] ?? '';
if ($query !== '') {
    $target .= '?' . $query;
}

header('Location: ' . $target, true, 301);
exit;
