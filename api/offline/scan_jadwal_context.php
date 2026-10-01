<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/presensi_scan_jadwal.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$tanggal = trim((string) ($_GET['tanggal'] ?? ''));
if ($tanggal === '') {
    $tanggal = date('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Format tanggal tidak valid.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ctx = presensi_scan_jadwal_context($pdo, $tanggal, null, null);

echo json_encode([
    'ok' => true,
    'tanggal' => $tanggal,
    'ctx' => $ctx,
], JSON_UNESCAPED_UNICODE);
