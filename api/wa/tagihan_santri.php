<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/keuangan_transaksi.php';
require_once __DIR__ . '/../../helpers/tagihan_bulanan.php';
require_once __DIR__ . '/../../helpers/pondok_kalender.php';

require_login();
require_roles(['admin', 'pengurus']);

$santriId = (int) ($_GET['santri_id'] ?? $_POST['santri_id'] ?? 0);
$bulanTagihan = max(1, min(12, (int) ($_GET['bulan'] ?? $_POST['bulan'] ?? 0)));
$periodeTa = keuangan_tahun_ajaran_aktif($pdo);
$tahunMulai = (int) ($_GET['ta_mulai'] ?? $_POST['ta_mulai'] ?? $periodeTa['mulai']);
$tahunSelesai = (int) ($_GET['ta_selesai'] ?? $_POST['ta_selesai'] ?? $periodeTa['selesai']);

if ($bulanTagihan < 1) {
    $berjalan = keuangan_periode_berjalan($pdo);
    $bulanTagihan = max(1, min(12, (int) ($berjalan['bulan'] ?? 1)));
}

if ($santriId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'ID santri tidak valid.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$preview = wa_tagihan_preview_santri($pdo, $santriId, $bulanTagihan, $tahunMulai, $tahunSelesai);
if ($preview === null) {
    echo json_encode(['ok' => false, 'error' => 'Data tidak ditemukan.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['mode'] ?? '') === 'gateway') {
    if (!($preview['ok'] ?? false)) {
        echo json_encode([
            'ok' => false,
            'error' => (string) ($preview['error'] ?? $preview['message'] ?? 'Tidak bisa mengirim.'),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = send_wa_message_with_result($pdo, (string) $preview['phone'], (string) ($preview['pesan'] ?? $preview['message'] ?? ''), ['kind' => 'tagihan']);
    echo json_encode([
        'ok' => (bool) ($result['success'] ?? false),
        'error' => ($result['success'] ?? false) ? '' : 'Gagal kirim via gateway. ' . trim((string) ($result['response'] ?? '')),
        'nama' => (string) ($preview['nama'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => (bool) ($preview['ok'] ?? false),
    'error' => (string) ($preview['error'] ?? ($preview['ok'] ? '' : (string) ($preview['message'] ?? ''))),
    'nama' => (string) ($preview['nama'] ?? ''),
    'sisa' => (int) ($preview['sisa'] ?? 0),
    'phone' => (string) ($preview['phone'] ?? ''),
    'wa_url' => (string) ($preview['wa_url'] ?? ''),
    'pesan' => (string) ($preview['pesan'] ?? $preview['message'] ?? ''),
], JSON_UNESCAPED_UNICODE);
