<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/rekap_periode.php';
require_once __DIR__ . '/../../helpers/rekap_keaktifan.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=30');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesi habis, login ulang.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_roles(['admin', 'pengurus', 'petugas_absensi']);

$tingkatan = trim((string) ($_GET['tingkatan'] ?? ''));
if ($tingkatan === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Parameter tingkatan wajib.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$periode = rekap_resolve_periode($pdo, $_GET);
$kegiatanId = (int) ($_GET['kegiatan_id'] ?? 0);
$goodMax = (int) app_setting($pdo, 'kategori_baik_max', '1');
$mediumMax = (int) app_setting($pdo, 'kategori_sedang_max', '3');
$forceRefresh = isset($_GET['refresh']) && (string) $_GET['refresh'] === '1';

$byTingkatan = rekap_keaktifan_by_tingkatan_for_periode(
    $pdo,
    (string) $periode['start_date'],
    (string) $periode['end_date'],
    $goodMax,
    $mediumMax,
    $kegiatanId,
    $periode['kalender_hijriyah_key'] ?? null,
    $forceRefresh
);

$data = null;
foreach ($byTingkatan as $tg => $row) {
    if (strcasecmp((string) $tg, $tingkatan) === 0) {
        $data = $row;
        break;
    }
}

if ($data === null) {
    echo json_encode(['ok' => true, 'tingkatan' => $tingkatan, 'html' => '<p class="small text-muted mb-0">Tidak ada data.</p>'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rekapKeaktifanPagePath = '/rekap/santri_bagus.php';

$buildQuery = static function (array $overrides = []) use ($periode, $kegiatanId, $rekapKeaktifanPagePath): string {
    $q = [
        'mode' => $periode['mode'],
        'month' => $periode['month'],
        'year' => $periode['year'],
        'tampilan' => 'tingkatan',
        'kegiatan_id' => $kegiatanId,
    ];
    foreach ($overrides as $k => $v) {
        $q[$k] = $v;
    }

    return app_href($rekapKeaktifanPagePath . '?' . http_build_query(array_filter($q, static fn ($v) => $v !== '' && $v !== 0)));
};

ob_start();
$tg = $tingkatan;
require __DIR__ . '/../../includes/partials/rekap_tingkatan_santri_tables.php';
$html = (string) ob_get_clean();

echo json_encode([
    'ok' => true,
    'tingkatan' => $tingkatan,
    'html' => $html,
], JSON_UNESCAPED_UNICODE);
