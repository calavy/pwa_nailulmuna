<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/keuangan_typography.php';
require_once __DIR__ . '/../../helpers/yayasan_dashboard.php';
require_once __DIR__ . '/../../helpers/yayasan_portal.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesi habis, login ulang.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_roles(['admin', 'pengurus']);

try {
    $snap = yayasan_pengawasan_keuangan_pack_cached($pdo);
    $ketertiban = yayasan_ketertiban_ringkasan_cached($pdo);
} catch (Throwable $e) {
    error_log('[yayasan_pengawasan_data] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Gagal memuat data pengawasan.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$fmt = static fn (int $n): string => keuangan_format_rupiah($n);

$months = $snap['months'] ?? [];
$masuk = $snap['keuangan_masuk'] ?? [];
$keluar = $snap['keuangan_keluar'] ?? [];
$maxKeu = max(1, (int) ($snap['max_keuangan'] ?? 1));
$chartCols = [];
foreach ($months as $i => $label) {
    $mIn = (int) ($masuk[$i] ?? 0);
    $mOut = (int) ($keluar[$i] ?? 0);
    $chartCols[] = [
        'label' => (string) $label,
        'masuk' => $mIn,
        'keluar' => $mOut,
        'masuk_fmt' => $fmt($mIn),
        'keluar_fmt' => $fmt($mOut),
        'h_in' => $maxKeu > 0 ? round(100 * $mIn / $maxKeu) : 0,
        'h_out' => $maxKeu > 0 ? round(100 * $mOut / $maxKeu) : 0,
    ];
}

echo json_encode([
    'ok' => true,
    'keuangan' => [
        'masuk_bulan_ini' => (int) ($snap['masuk_bulan_ini'] ?? 0),
        'keluar_bulan_ini' => (int) ($snap['keluar_bulan_ini'] ?? 0),
        'net_bulan_ini' => (int) ($snap['net_bulan_ini'] ?? 0),
        'masuk_bulan_ini_fmt' => $fmt((int) ($snap['masuk_bulan_ini'] ?? 0)),
        'keluar_bulan_ini_fmt' => $fmt((int) ($snap['keluar_bulan_ini'] ?? 0)),
        'net_bulan_ini_fmt' => $fmt((int) ($snap['net_bulan_ini'] ?? 0)),
        'chart' => $chartCols,
    ],
    'ketertiban' => [
        'izin_lewat' => (int) ($ketertiban['izin_lewat'] ?? 0),
        'sakit' => (int) ($ketertiban['sakit'] ?? 0),
        'alpa_beruntun' => (int) ($ketertiban['alpa_beruntun'] ?? 0),
        'total' => (int) ($ketertiban['total'] ?? 0),
    ],
], JSON_UNESCAPED_UNICODE);
