<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/login_rate_limit.php';
require_once __DIR__ . '/../helpers/wali_portal.php';

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($pdo instanceof PDO) {
    $ip = login_rate_limit_client_ip();
    if (login_rate_limit_is_blocked($pdo, $ip, '')) {
        echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$items = ($pdo instanceof PDO) ? wali_portal_suggest_santri($pdo, $q, 8) : [];

echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
