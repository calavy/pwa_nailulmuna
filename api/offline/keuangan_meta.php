<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/keuangan_offline_pack.php';

if (empty($_SESSION['user'])) {
    http_response_code(401);
    keuangan_offline_pack_json_response(['ok' => false, 'message' => 'Sesi habis, login ulang.']);
    exit;
}

$role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
if (!is_super_admin() && !in_array($role, ['admin', 'pengurus'], true)) {
    http_response_code(403);
    keuangan_offline_pack_json_response(['ok' => false, 'message' => 'Akses ditolak.']);
    exit;
}

$years = (int) ($_GET['years'] ?? keuangan_offline_pack_years_default());
$allTime = isset($_GET['all']) && (string) $_GET['all'] === '1';

try {
    $meta = keuangan_offline_pack_meta($pdo, $years, $allTime);
    keuangan_offline_pack_json_response($meta);
} catch (Throwable $e) {
    http_response_code(500);
    keuangan_offline_pack_json_response(['ok' => false, 'message' => $e->getMessage()]);
}
