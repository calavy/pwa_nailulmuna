<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/pembimbing_dashboard.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=120');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesi habis, login ulang.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
$bolehSemua = is_super_admin() || in_array($role, ['admin', 'pengurus'], true);
if (!$bolehSemua && $role !== 'pembimbing') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Akses ditolak.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$pembimbingInfo = $bolehSemua ? null : pembimbing_dashboard_current_pembimbing($pdo, $userId);
$pembimbingId = $pembimbingInfo !== null ? (int) ($pembimbingInfo['id'] ?? 0) : 0;

$tingkatanList = pembimbing_dashboard_tingkatan_list($pdo, $pembimbingId > 0 ? $pembimbingId : null, $bolehSemua);
if ($tingkatanList === [] && $bolehSemua) {
    $tingkatanList = pembimbing_dashboard_semua_tingkatan($pdo);
}

$map = pembimbing_dashboard_santri_list_map(
    $pdo,
    $tingkatanList,
    400,
    !$bolehSemua && $pembimbingId > 0 ? $pembimbingId : null
);

echo json_encode(['ok' => true, 'map' => $map], JSON_UNESCAPED_UNICODE);
