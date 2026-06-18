<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/santri_izin_tetap.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
require_roles(['admin', 'pengurus', 'petugas_absensi']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

ensure_santri_izin_tetap_tables($pdo);

$slots = santri_izin_tetap_slots_dari_post($_POST);
$normalizedSlots = [];
foreach ($slots as $slot) {
    $hari = (int) ($slot['hari_ke'] ?? 0);
    $jm = trim((string) ($slot['jam_mulai'] ?? ''));
    $js = trim((string) ($slot['jam_selesai'] ?? ''));
    if ($hari < 1 || $hari > 7 || $jm === '' || $js === '') {
        continue;
    }
    if (strlen($jm) === 5) {
        $jm .= ':00';
    }
    if (strlen($js) === 5) {
        $js .= ':00';
    }
    if ($jm >= $js) {
        continue;
    }
    $normalizedSlots[] = ['hari_ke' => $hari, 'jam_mulai' => $jm, 'jam_selesai' => $js];
}

$santriIds = [];
if ((int) ($_POST['santri_id'] ?? 0) > 0) {
    $santriIds[] = (int) $_POST['santri_id'];
}
$rawIds = $_POST['santri_ids'] ?? [];
if (is_array($rawIds)) {
    foreach ($rawIds as $sid) {
        $sid = (int) $sid;
        if ($sid > 0) {
            $santriIds[$sid] = $sid;
        }
    }
}
$santriIds = array_values($santriIds);

$tingkatanList = santri_izin_tetap_tingkatan_for_santri_ids($pdo, $santriIds);
$hanyaJamaah = strtoupper(trim((string) ($_POST['jenis'] ?? 'HIDMAH'))) !== 'TUGAS';
$items = $normalizedSlots === []
    ? []
    : santri_izin_tetap_kegiatan_overlap_dari_jadwal($pdo, $normalizedSlots, $tingkatanList, $hanyaJamaah);

echo json_encode([
    'ok' => true,
    'items' => $items,
    'count' => count($items),
    'tingkatan' => $tingkatanList,
], JSON_UNESCAPED_UNICODE);
