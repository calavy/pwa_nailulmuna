<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/perizinan_approval.php';

require_roles(['admin', 'pengurus', 'kiai']);

$back = app_href('/pengasuh/perizinan.php');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ' . $back, true, 303);
    exit;
}

$userId = (int) ($_SESSION['user']['id'] ?? 0);
$action = (string) ($_POST['action'] ?? '');
$bypassAlpa = perizinan_request_bypass_alpa_pengasuh($pdo, $_POST);
$isRombongan = $action === 'setujui_rombongan_pengasuh' || $action === 'tolak_rombongan_pengasuh';
if ($isRombongan) {
    require_once __DIR__ . '/../helpers/perizinan_rombongan.php';
}

$res = ['ok' => false, 'message' => 'Aksi tidak dikenal.'];
if ($action === 'setujui_pengasuh') {
    $res = perizinan_pengasuh_setujui($pdo, (int) ($_POST['izin_id'] ?? 0), $userId, $bypassAlpa);
} elseif ($action === 'tolak_pengasuh') {
    $res = perizinan_tolak_izin_satu($pdo, (int) ($_POST['izin_id'] ?? 0), $userId, 'pengasuh');
} elseif ($action === 'setujui_rombongan_pengasuh') {
    $res = perizinan_pengasuh_setujui_rombongan($pdo, (int) ($_POST['rombongan_id'] ?? 0), $userId, $bypassAlpa);
} elseif ($action === 'tolak_rombongan_pengasuh') {
    $res = perizinan_rombongan_tolak($pdo, (int) ($_POST['rombongan_id'] ?? 0), $userId, 'pengasuh');
}

$ok = !empty($res['ok']);
$message = (string) ($res['message'] ?? 'Aksi gagal.');
$wantJson = strtolower(trim((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''))) === 'xmlhttprequest'
    || (str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
        && !str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'text/html'));

if ($wantJson) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $ok, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

set_flash($ok ? 'success' : 'error', $message);
perizinan_redirect_lalu_notif($back);
