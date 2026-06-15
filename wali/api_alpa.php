<?php

declare(strict_types=1);

require_once __DIR__ . '/inc_portal.php';
require_once __DIR__ . '/../helpers/wali_perizinan.php';

header('Content-Type: application/json; charset=utf-8');

$santriId = (int) ($_GET['santri_id'] ?? $waliSantriId);
$tanggal = trim((string) ($_GET['tanggal'] ?? date('Y-m-d')));
if ($santriId <= 0 || !in_array($santriId, $waliAnakIds, true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Akses ditolak.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    $tanggal = date('Y-m-d');
}

$info = wali_perizinan_alpa_info_portal($pdo, $santriId, $tanggal);
echo json_encode(['ok' => true] + $info, JSON_UNESCAPED_UNICODE);
