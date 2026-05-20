<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/push_fcm.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode(is_string($raw) ? $raw : '', true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$token = trim((string) ($payload['token'] ?? ''));
$audienceType = strtolower(trim((string) ($payload['audience_type'] ?? '')));
$deviceLabel = trim((string) ($payload['device_label'] ?? ''));
$categories = $payload['categories'] ?? [];
if (!is_array($categories)) {
    $categories = [];
}

$waliSantriId = null;
$userId = null;

if (isset($_SESSION['wali']) && is_array($_SESSION['wali'])) {
    $audienceType = 'wali';
    $waliSantriId = (int) ($_SESSION['wali']['wali_santri_id'] ?? 0);
    if ($waliSantriId <= 0) {
        $waliSantriId = (int) ($_SESSION['wali']['santri_id'] ?? 0);
    }
} elseif (isset($_SESSION['santri_portal']) && is_array($_SESSION['santri_portal'])) {
    $audienceType = 'wali';
    $waliSantriId = (int) ($_SESSION['santri_portal']['santri_id'] ?? 0);
} elseif (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    $userId = (int) ($_SESSION['user']['id'] ?? 0);
    $role = strtolower((string) ($_SESSION['user']['role'] ?? 'pengurus'));
    if ($audienceType === '' || !in_array($audienceType, ['staff', 'kiai'], true)) {
        $audienceType = $role === 'kiai' ? 'kiai' : 'staff';
    }
    if ($role === 'kiai') {
        $audienceType = 'kiai';
    } elseif (!empty($payload['subscribe_kiai']) && in_array($role, ['admin', 'pengurus'], true)) {
        $audienceType = 'kiai';
    } elseif ($audienceType === 'kiai' && $role !== 'kiai') {
        $audienceType = 'staff';
    }
} else {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Login diperlukan']);
    exit;
}

if ($token === '' || strlen($token) < 20) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Token tidak valid']);
    exit;
}

if (!in_array($audienceType, ['wali', 'staff', 'kiai'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Audience tidak valid']);
    exit;
}

$ok = push_register_token($pdo, $token, $audienceType, $waliSantriId, $userId, $categories, $deviceLabel);

echo json_encode([
    'ok' => $ok,
    'audience' => $audienceType,
    'fcm_enabled' => push_fcm_enabled($pdo),
]);
