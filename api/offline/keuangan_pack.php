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

$chunk = trim((string) ($_GET['chunk'] ?? ''));
if ($chunk === '') {
    http_response_code(400);
    keuangan_offline_pack_json_response(['ok' => false, 'message' => 'Parameter chunk wajib.']);
    exit;
}

$afterId = (int) ($_GET['after_id'] ?? 0);
$afterKey = isset($_GET['after_key']) ? (string) $_GET['after_key'] : null;
$years = (int) ($_GET['years'] ?? keuangan_offline_pack_years_default());
$allTime = isset($_GET['all']) && (string) $_GET['all'] === '1';
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : null;

try {
    $result = keuangan_offline_pack_fetch_chunk(
        $pdo,
        $chunk,
        $afterId,
        $afterKey,
        $years,
        $allTime,
        $limit
    );
    if (empty($result['ok'])) {
        http_response_code(400);
    }
    $result['pack_version'] = keuangan_offline_pack_version($pdo, $allTime ? null : ($result['since_date'] ?? null));
    $result['schema_version'] = keuangan_offline_pack_schema_version();
    $result['generated_at'] = date('c');
    keuangan_offline_pack_json_response($result);
} catch (Throwable $e) {
    http_response_code(500);
    keuangan_offline_pack_json_response(['ok' => false, 'message' => $e->getMessage()]);
}
