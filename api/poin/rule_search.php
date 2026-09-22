<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/poin_calc.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$role = strtolower((string) ($_SESSION['user']['role'] ?? ''));
if (!in_array($role, ['admin', 'pengurus'], true) && !is_super_admin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

ensure_point_tables($pdo);
$q = trim((string) ($_GET['q'] ?? ''));
$jenis = strtoupper(trim((string) ($_GET['jenis'] ?? 'PLUS')));
if (!in_array($jenis, ['PLUS', 'MINUS'], true)) {
    $jenis = 'PLUS';
}
$limit = max(1, min(20, (int) ($_GET['limit'] ?? 15)));

if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$like = '%' . $q . '%';
$st = $pdo->prepare('
    SELECT id, kode_rule, kategori, nama_rule, bobot_poin, contoh_pelanggaran, jenis_rule
    FROM point_rules
    WHERE is_active = 1 AND jenis_rule = :jenis
      AND (
        kode_rule LIKE :q OR nama_rule LIKE :q OR kategori LIKE :q OR contoh_pelanggaran LIKE :q
      )
    ORDER BY urutan ASC, kategori ASC
    LIMIT ' . (int) $limit . '
');
$st->execute(['jenis' => $jenis, 'q' => $like]);
$items = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $items[] = [
        'id' => (int) $r['id'],
        'kode_rule' => (string) $r['kode_rule'],
        'kategori' => (string) $r['kategori'],
        'nama_rule' => (string) $r['nama_rule'],
        'bobot_poin' => (int) $r['bobot_poin'],
        'contoh_pelanggaran' => (string) ($r['contoh_pelanggaran'] ?? ''),
        'allows_modifier' => poin_rule_allows_modifier((int) $r['bobot_poin'], (string) $r['kategori']),
    ];
}

echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
