<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/user_catatan.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Metode tidak diizinkan.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$catatanId = (int) ($_POST['catatan_id'] ?? 0);
$gridRaw = $_POST['grid'] ?? null;

if ($userId <= 0 || $catatanId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Parameter tidak valid.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (is_string($gridRaw)) {
    $gridDecoded = json_decode($gridRaw, true);
} else {
    $gridDecoded = $gridRaw;
}

if (!is_array($gridDecoded)) {
    echo json_encode(['ok' => false, 'error' => 'Data grid tidak valid.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $saved = user_catatan_save_grid($pdo, $catatanId, $userId, $gridDecoded);
    if (!$saved) {
        echo json_encode(['ok' => false, 'error' => 'Catatan tidak ditemukan atau tidak bisa disimpan.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'updated_at' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
