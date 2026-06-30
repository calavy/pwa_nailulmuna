<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/excel.php';
require_once __DIR__ . '/../../helpers/user_catatan.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metode tidak diizinkan.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$catatanId = (int) ($_POST['catatan_id'] ?? 0);

if ($userId <= 0 || $catatanId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Parameter tidak valid.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (user_catatan_get($pdo, $catatanId, $userId) === null) {
    echo json_encode(['ok' => false, 'error' => 'Catatan tidak ditemukan.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_FILES['file_import']) || (int) ($_FILES['file_import']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    echo json_encode(['ok' => false, 'error' => 'File tidak valid.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$name = strtolower((string) ($_FILES['file_import']['name'] ?? ''));
$tmp = (string) ($_FILES['file_import']['tmp_name'] ?? '');
$size = (int) ($_FILES['file_import']['size'] ?? 0);

if (!str_ends_with($name, '.xlsx')) {
    echo json_encode(['ok' => false, 'error' => 'Format harus .xlsx'], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($size <= 0 || $size > USER_CATATAN_IMPORT_MAX_BYTES) {
    echo json_encode(['ok' => false, 'error' => 'Ukuran file maksimal 2 MB.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $rows = parse_xlsx_rows($tmp);
    $grid = user_catatan_xlsx_rows_to_grid($rows);
    user_catatan_save_grid($pdo, $catatanId, $userId, $grid);
    echo json_encode([
        'ok' => true,
        'grid' => $grid,
        'updated_at' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
