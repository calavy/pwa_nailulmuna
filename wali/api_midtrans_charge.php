<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/inc_portal.php';
require_once __DIR__ . '/../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../helpers/midtrans.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$santriId = (int) ($payload['santri_id'] ?? $waliSantriId);
$bulan = (int) ($payload['bulan'] ?? $payload['bulan_tagihan'] ?? 0);
$tahunMulai = (int) ($payload['tahun_ajaran_mulai'] ?? 0);
$tahunSelesai = (int) ($payload['tahun_ajaran_selesai'] ?? 0);

if ($santriId <= 0 || !in_array($santriId, $waliAnakIds, true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Akses ditolak.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($tahunMulai <= 0 || $tahunSelesai <= 0) {
    $berjalan = keuangan_periode_berjalan($pdo);
    $tahunMulai = (int) ($berjalan['mulai'] ?? 0);
    $tahunSelesai = (int) ($berjalan['selesai'] ?? 0);
}

$posFilter = $payload['bayar_pos'] ?? null;
if (is_array($posFilter)) {
    $posFilter = array_values(array_filter(array_map('strval', $posFilter), static fn(string $s): bool => $s !== ''));
} else {
    $posFilter = null;
}

$waliId = (int) ($_SESSION['wali']['wali_santri_id'] ?? $_SESSION['wali']['id'] ?? 0);
$result = midtrans_create_snap_for_tagihan(
    $pdo,
    $santriId,
    $bulan,
    $tahunMulai,
    $tahunSelesai,
    $waliId > 0 ? $waliId : null,
    $posFilter
);

if (!$result['ok']) {
    http_response_code(400);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
