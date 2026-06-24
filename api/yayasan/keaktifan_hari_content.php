<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/rekap_keaktifan_hari.php';
require_once __DIR__ . '/../../helpers/yayasan_keaktifan_hari_view.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=30');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesi habis, login ulang.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_roles(['admin', 'pengurus']);

$tanggal = trim((string) ($_GET['tanggal'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    $tanggal = date('Y-m-d');
}
$tingkatan = trim((string) ($_GET['tingkatan'] ?? ''));
$kategori = rekap_keaktifan_hari_normalize_kategori($_GET['kategori'] ?? null);
$kegiatanId = (int) ($_GET['kegiatan_id'] ?? 0);
$tkFilter = $tingkatan !== '' ? $tingkatan : null;
$forceRefresh = isset($_GET['refresh']) && (string) $_GET['refresh'] === '1';

try {
    $pack = rekap_keaktifan_hari_page_pack($pdo, $tanggal, $tkFilter, $kategori, $forceRefresh);
    extract(yayasan_keaktifan_hari_view_vars($pack, $tanggal, $tingkatan, $kategori, $kegiatanId), EXTR_SKIP);

    ob_start();
    require __DIR__ . '/../../yayasan/partials/keaktifan_hari_body.php';
    $html = (string) ob_get_clean();

    echo json_encode([
        'ok' => true,
        'html' => $html,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[keaktifan_hari_content] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Gagal memuat keaktifan hari ini.'], JSON_UNESCAPED_UNICODE);
}
