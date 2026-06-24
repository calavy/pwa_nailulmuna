<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/rekap_periode.php';
require_once __DIR__ . '/../../helpers/rekap_keaktifan.php';
require_once __DIR__ . '/../../helpers/rekap_keaktifan_hari.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=30');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesi habis, login ulang.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_roles(['admin', 'pengurus']);

$tingkatan = trim((string) ($_GET['tingkatan'] ?? ''));
if ($tingkatan === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Parameter tingkatan wajib.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$periode = rekap_resolve_periode($pdo, $_GET);
$goodMax = (int) app_setting($pdo, 'kategori_baik_max', '1');
$mediumMax = (int) app_setting($pdo, 'kategori_sedang_max', '3');
$kategoriRaw = trim((string) ($_GET['kategori'] ?? ''));
$kategori = rekap_keaktifan_hari_normalize_kategori($kategoriRaw !== '' ? $kategoriRaw : null);
$kategoriLabel = match ($kategori) {
    'JAMAAH' => 'Jamaah',
    'TAALIM' => 'Taalim',
    'PKPPS' => 'PKPPS',
    default => 'Semua kategori',
};
$forceRefresh = isset($_GET['refresh']) && (string) $_GET['refresh'] === '1';

$santriList = rekap_keaktifan_rank_tingkatan_santri_list(
    $pdo,
    (string) $periode['start_date'],
    (string) $periode['end_date'],
    $tingkatan,
    $goodMax,
    $mediumMax,
    $kategori,
    $periode['kalender_hijriyah_key'] ?? null,
    $forceRefresh
);

ob_start();
$tingkatanNama = $tingkatan;
require __DIR__ . '/../../includes/partials/rekap_rank_santri_list.php';
$html = (string) ob_get_clean();

echo json_encode([
    'ok' => true,
    'tingkatan' => $tingkatan,
    'count' => count($santriList),
    'html' => $html,
], JSON_UNESCAPED_UNICODE);
