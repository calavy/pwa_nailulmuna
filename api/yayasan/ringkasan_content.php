<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/yayasan.php';
require_once __DIR__ . '/../../helpers/yayasan_portal.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=45');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

require_roles(['admin', 'pengurus']);
yayasan_ensure_tables($pdo);

$todos = yayasan_todo_mendesak($pdo);
$agenda = yayasan_kegiatan_mendatang($pdo);

ob_start();
require __DIR__ . '/../../yayasan/partials/ringkasan_body.php';
$html = (string) ob_get_clean();

echo json_encode(['ok' => true, 'html' => $html], JSON_UNESCAPED_UNICODE);
