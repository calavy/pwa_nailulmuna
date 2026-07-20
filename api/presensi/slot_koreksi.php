<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/presensi_tanpa_scan_koreksi.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesi habis, login ulang.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Metode tidak didukung.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!user_can_presensi_tanpa_scan_koreksi()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Hanya admin super yang dapat melakukan koreksi slot tanpa scan.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$action = trim((string) ($payload['action'] ?? ''));
$kegiatanId = (int) ($payload['kegiatan_id'] ?? 0);
$tanggal = trim((string) ($payload['tanggal'] ?? ''));
$tingkatan = trim((string) ($payload['tingkatan'] ?? ''));
$santriIds = array_map('intval', (array) ($payload['santri_ids'] ?? []));
$userId = (int) ($_SESSION['user']['id'] ?? 0);

if ($kegiatanId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Parameter kegiatan_id dan tanggal wajib.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'hapus_alpa') {
    $result = presensi_slot_hapus_alpa($pdo, $kegiatanId, $tanggal, $santriIds, $userId, $tingkatan);
} elseif ($action === 'catat_hadir') {
    $result = presensi_slot_catat_hadir_manual($pdo, $kegiatanId, $tanggal, $santriIds, $userId, $tingkatan);
} else {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Aksi tidak dikenali.'], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code($result['ok'] ? 200 : 422);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
