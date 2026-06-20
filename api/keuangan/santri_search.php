<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/keuangan_transaksi.php';

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

$q = trim((string) ($_GET['q'] ?? ''));
$id = (int) ($_GET['id'] ?? 0);
$limit = max(1, min(30, (int) ($_GET['limit'] ?? 20)));

if ($id > 0) {
    $aktif = function_exists('santri_sql_aktif_only') ? santri_sql_aktif_only('s') : '1=1';
    $cols = ['id', 'nis', 'nama_santri'];
    if (column_exists($pdo, 'santri', 'kategori_kelas')) {
        $cols[] = 'kategori_kelas';
    }
    if (column_exists($pdo, 'santri', 'tingkatan')) {
        $cols[] = 'tingkatan';
    }
    $st = $pdo->prepare('SELECT ' . implode(', ', $cols) . ' FROM santri s WHERE s.id = :id AND ' . $aktif . ' LIMIT 1');
    $st->execute(['id' => $id]);
    $match = $st->fetch(PDO::FETCH_ASSOC);
    $rows = $match ? [$match] : [];
} else {
    if (mb_strlen($q) < 2) {
        echo json_encode(['ok' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $rows = keuangan_search_santri_aktif($pdo, $q, $limit);
}

$tierMap = keuangan_build_santri_tier_label_map($pdo, $rows);
$items = [];
foreach ($rows as $row) {
    $sid = (int) ($row['id'] ?? 0);
    if ($sid <= 0) {
        continue;
    }
    $nis = trim((string) ($row['nis'] ?? ''));
    $nama = trim((string) ($row['nama_santri'] ?? ''));
    $tier = $tierMap[(string) $sid] ?? null;
    $items[] = [
        'id' => $sid,
        'label' => ($nis !== '' ? $nis : '-') . ' — ' . $nama,
        'nis' => $nis,
        'nama' => $nama,
        'tier' => $tier,
    ];
}

echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
