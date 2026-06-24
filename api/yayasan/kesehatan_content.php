<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/yayasan_kesehatan.php';
require_once __DIR__ . '/../../helpers/rekap_periode.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesi habis, login ulang.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_roles(['admin', 'pengurus']);

try {
    require_once __DIR__ . '/../../helpers/yayasan.php';
    $periode = yayasan_periode_berjalan($pdo);
    $pack = yayasan_kesehatan_pack_cached($pdo, [
        'mode' => $periode['mode'],
        'month' => $periode['month'],
        'year' => $periode['year'],
        'portal_light' => true,
        'refresh' => $_GET['refresh'] ?? '',
    ]);

    if (empty($pack['ready'])) {
        echo json_encode(['ok' => false, 'message' => 'Modul perizinan belum tersedia.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    ob_start();
    require __DIR__ . '/../../yayasan/partials/kesehatan_content.php';
    $html = (string) ob_get_clean();

    echo json_encode([
        'ok' => true,
        'html' => $html,
        'periode_label' => (string) ($pack['periode_label'] ?? ''),
        'charts' => [
            'bulan' => $pack['chart_bulan'] ?? [],
            'tingkatan' => $pack['chart_tingkatan'] ?? [],
            'status' => $pack['chart_status'] ?? [],
            'suhu' => $pack['chart_suhu'] ?? [],
        ],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[yayasan_kesehatan_content] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Gagal memuat laporan kesehatan.'], JSON_UNESCAPED_UNICODE);
}
