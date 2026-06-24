<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/yayasan_fragment.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=15');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesi habis.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_roles(['admin', 'pengurus']);

$path = trim((string) ($_GET['path'] ?? ''));
if ($path === '') {
    echo json_encode(['ok' => false, 'message' => 'Path kosong.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$query = $_GET;
unset($query['path']);

try {
    $result = yayasan_fragment_render($pdo, $path, $query);
    if ($result === null) {
        echo json_encode(['ok' => false, 'message' => 'Halaman tidak didukung fragment.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[yayasan_fragment] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Gagal memuat halaman.'], JSON_UNESCAPED_UNICODE);
}
