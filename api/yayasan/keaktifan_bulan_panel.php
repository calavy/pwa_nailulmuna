<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/rekap_keaktifan.php';
require_once __DIR__ . '/../../helpers/yayasan_keaktifan_bulan.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=30');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesi habis, login ulang.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_roles(['admin', 'pengurus']);

require_once __DIR__ . '/../../helpers/yayasan.php';

$periode = yayasan_periode_berjalan($pdo);
$kbGet = [
    'mode' => $periode['mode'],
    'month' => $periode['month'],
    'year' => $periode['year'],
    'kb_refresh' => $_GET['kb_refresh'] ?? $_GET['refresh'] ?? '',
    'tanpa_scan' => $_GET['tanpa_scan'] ?? '',
];
$kb = yayasan_keaktifan_bulan_pack_cached($pdo, $kbGet);
if (empty($kb['ready'])) {
    echo json_encode(['ok' => false, 'message' => 'Data presensi belum tersedia.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$kbFormAction = '/yayasan/operasional.php';
$kbSaran = yayasan_keaktifan_bulan_saran($kb);
$kbKegiatanKosongCount = rekap_keaktifan_kegiatan_tanpa_scan_total_jadwal((array) ($kb['kegiatan_tanpa_scan'] ?? []));
$kbSantriKosongCount = count((array) ($kb['santri_tanpa_scan'] ?? []));
$kbPerhatianCount = $kbKegiatanKosongCount + $kbSantriKosongCount;

ob_start();
require __DIR__ . '/../../yayasan/partials/keaktifan_bulan_panel.php';
$html = (string) ob_get_clean();

echo json_encode([
    'ok' => true,
    'html' => $html,
    'perhatian_count' => $kbPerhatianCount,
    'periode_label' => (string) ($kb['periode_label'] ?? ''),
], JSON_UNESCAPED_UNICODE);
