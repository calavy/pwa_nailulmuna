<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/yayasan_portal.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=30');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesi habis, login ulang.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_roles(['admin', 'pengurus']);

$forceRefresh = isset($_GET['refresh']) && (string) $_GET['refresh'] === '1';
if ($forceRefresh) {
    unset($_SESSION['yayasan_kas_status_v1']);
}

$kas = yayasan_kas_status_cached($pdo, 180);

echo json_encode([
    'ok' => true,
    'kas' => yayasan_kas_status_payload($kas),
], JSON_UNESCAPED_UNICODE);
