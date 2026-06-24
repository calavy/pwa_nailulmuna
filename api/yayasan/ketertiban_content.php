<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/yayasan.php';
require_once __DIR__ . '/../../helpers/yayasan_portal.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
    exit;
}

require_roles(['admin', 'pengurus']);
yayasan_ensure_tables($pdo);

$tab = trim((string) ($_GET['tab'] ?? 'izin'));
if (!in_array($tab, ['izin', 'sakit', 'alpa'], true)) {
    $tab = 'izin';
}
$ket = yayasan_ketertiban_ringkasan_cached($pdo);

ob_start();
require __DIR__ . '/../../yayasan/partials/ketertiban_body.php';
$html = (string) ob_get_clean();

echo json_encode(['ok' => true, 'html' => $html, 'tab' => $tab], JSON_UNESCAPED_UNICODE);
