<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/app_path.php';
require_once __DIR__ . '/../../helpers/yayasan_timeline.php';

$token = trim((string) ($_GET['token'] ?? ''));
if ($token === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Token kalender tidak valid.';
    exit;
}

$user = yayasan_task_user_by_calendar_token($pdo, $token);
if ($user === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Feed kalender tidak ditemukan.';
    exit;
}

$userId = (int) ($user['id'] ?? 0);
$rows = yayasan_tugas_list_for_pic($pdo, $userId, false);
$nama = trim((string) ($user['nama'] ?? $user['username'] ?? 'Pembimbing'));
$ics = yayasan_tugas_build_ics($pdo, $rows, 'Tugas — ' . $nama);

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="tugas-' . $userId . '.ics"');
header('Cache-Control: private, max-age=300');
echo $ics;
