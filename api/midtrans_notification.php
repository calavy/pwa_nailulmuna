<?php

declare(strict_types=1);

/**
 * Webhook Midtrans Payment Notification — tanpa session login.
 * Verifikasi signature_key sebelum mencatat pembayaran.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/midtrans.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$notif = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($notif) || $notif === []) {
    $notif = $_POST;
}
if (!is_array($notif) || $notif === []) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Payload kosong']);
    exit;
}

$result = midtrans_handle_notification($pdo, $notif);
if (!$result['ok']) {
    http_response_code(400);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
