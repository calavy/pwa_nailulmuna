<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/keuangan_fragment.php';
require_once __DIR__ . '/../../helpers/user_permissions.php';

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

$normalizedPath = keuangan_fragment_normalize_path($path);
if (!keuangan_fragment_path_in_whitelist($normalizedPath)) {
    echo json_encode(['ok' => false, 'message' => 'Halaman tidak didukung fragment.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$permissionPathMap = user_permission_path_map();
if (!keuangan_fragment_path_allowed($pdo, $normalizedPath, $permissionPathMap)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Akses ditolak.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $result = keuangan_fragment_render($pdo, $path, $query);
    if ($result === null) {
        echo json_encode(['ok' => false, 'message' => 'Halaman tidak didukung fragment.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[keuangan_fragment] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Gagal memuat halaman.'], JSON_UNESCAPED_UNICODE);
}
