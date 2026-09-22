<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/poin_presensi_pull.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
if (!in_array($role, ['admin', 'pengurus'], true) && !is_super_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$santriId = (int) ($_GET['santri_id'] ?? 0);
$pack = poin_presensi_pending_pack($pdo, $santriId);
echo json_encode($pack, JSON_UNESCAPED_UNICODE);
