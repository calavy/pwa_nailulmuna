<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/poin_offline.php';
require_once __DIR__ . '/../../helpers/offline_sync_http.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'type' => 'error', 'message' => 'Sesi habis, login ulang.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_roles(['admin', 'pengurus']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'type' => 'error', 'message' => 'Method not allowed.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$result = poin_offline_submit($pdo, $_POST, $userId);

offline_sync_json_response(
    (string) ($result['type'] ?? ($result['ok'] ? 'success' : 'error')),
    (string) ($result['message'] ?? 'OK'),
    array_filter([
        'ledger_id' => $result['ledger_id'] ?? null,
    ], static fn($v): bool => $v !== null)
);
