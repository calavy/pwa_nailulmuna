<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';

$query = $_GET;
$query['panel'] = 'jadwal';
if (!isset($query['kegiatan_id']) && isset($_GET['kegiatan_id'])) {
    $query['kegiatan_id'] = (int) $_GET['kegiatan_id'];
}
header('Location: ' . app_href('/jadwal/index.php?' . http_build_query($query)));
exit;
