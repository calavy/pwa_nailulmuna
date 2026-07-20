<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/rekap_keaktifan.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=15');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesi habis, login ulang.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_roles(['admin', 'pengurus', 'petugas_absensi']);

$kegiatanId = (int) ($_GET['kegiatan_id'] ?? 0);
$tanggal = trim((string) ($_GET['tanggal'] ?? ''));
$tingkatan = trim((string) ($_GET['tingkatan'] ?? ''));

if ($kegiatanId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Parameter kegiatan_id dan tanggal wajib.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$namaKegiatan = '';
if (table_exists($pdo, 'kegiatan')) {
    $st = $pdo->prepare('SELECT nama_kegiatan FROM kegiatan WHERE id = :id LIMIT 1');
    $st->execute(['id' => $kegiatanId]);
    $namaKegiatan = trim((string) ($st->fetchColumn() ?: ''));
}

$items = rekap_keaktifan_slot_santri_roster(
    $pdo,
    $kegiatanId,
    $tanggal,
    $tingkatan !== '' ? $tingkatan : null
);

require_once __DIR__ . '/../../helpers/presensi_tanpa_scan_koreksi.php';
$allowKoreksi = user_can_presensi_tanpa_scan_koreksi();

$hadir = 0;
foreach ($items as $it) {
    if (!empty($it['hadir'])) {
        $hadir++;
    }
}

echo json_encode([
    'ok' => true,
    'kegiatan_id' => $kegiatanId,
    'kegiatan' => $namaKegiatan !== '' ? $namaKegiatan : ('Kegiatan #' . $kegiatanId),
    'tanggal' => $tanggal,
    'tingkatan' => $tingkatan,
    'total' => count($items),
    'hadir' => $hadir,
    'allow_koreksi' => $allowKoreksi,
    'items' => $items,
], JSON_UNESCAPED_UNICODE);
