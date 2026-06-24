<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/yayasan_timeline.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=30');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesi habis, login ulang.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_roles(['admin', 'pengurus']);

yayasan_timeline_ensure_schema($pdo);
$userId = (int) ($_SESSION['user']['id'] ?? 0);
$filter = trim((string) ($_GET['filter'] ?? 'aktif'));
$listFilter = $filter === 'semua' ? null : ($filter === 'aktif' ? 'aktif' : $filter);
$rows = yayasan_tugas_list($pdo, $listFilter);
$conflicts = yayasan_tugas_all_conflicts($pdo);
$workload = yayasan_tugas_workload($pdo);
$gantt = yayasan_tugas_gantt_pack($rows);

echo json_encode([
    'ok' => true,
    'stats' => yayasan_tugas_stats(yayasan_tugas_list($pdo, null)),
    'gantt' => $gantt,
    'workload' => $workload,
    'conflicts' => array_map(static function (array $c): array {
        return [
            'pic_id' => (int) ($c['pic_id'] ?? 0),
            'pic_nama' => (string) ($c['pic_nama'] ?? ''),
            'task_a' => [
                'id' => (int) ($c['task_a']['id'] ?? 0),
                'judul' => (string) ($c['task_a']['judul'] ?? ''),
                'start_at' => (string) ($c['task_a']['start_at'] ?? ''),
                'due_at' => (string) ($c['task_a']['due_at'] ?? ''),
            ],
            'task_b' => [
                'id' => (int) ($c['task_b']['id'] ?? 0),
                'judul' => (string) ($c['task_b']['judul'] ?? ''),
                'start_at' => (string) ($c['task_b']['start_at'] ?? ''),
                'due_at' => (string) ($c['task_b']['due_at'] ?? ''),
            ],
        ];
    }, $conflicts),
    'conflict_count' => count($conflicts),
    'access' => yayasan_task_user_access($pdo, $userId),
], JSON_UNESCAPED_UNICODE);
