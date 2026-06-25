<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/rekap_periode.php';
require_once __DIR__ . '/../../helpers/rekap_keaktifan.php';
require_once __DIR__ . '/../../helpers/rekap_keaktifan_hari.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=60');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Sesi habis, login ulang.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_roles(['admin', 'pengurus']);

if (!table_exists($pdo, 'presensi')) {
    echo json_encode(['ok' => false, 'message' => 'Tabel presensi belum ada.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../../helpers/yayasan.php';
    $periode = rekap_resolve_periode($pdo, $_GET);
    $startDate = $periode['start_date'];
    $endDate = $periode['end_date'];
    $periodeLabel = $periode['label'];
    $mode = $periode['mode'];
    $month = $periode['month'];
    $year = $periode['year'];

    $kategoriRaw = trim((string) ($_GET['kategori'] ?? ''));
    $kategori = rekap_keaktifan_hari_normalize_kategori($kategoriRaw !== '' ? $kategoriRaw : null);
    $kategoriLabel = match ($kategori) {
        'JAMAAH' => 'Jamaah',
        'TAALIM' => 'Taalim',
        'PKPPS' => 'PKPPS',
        default => 'Semua kategori',
    };

    $goodMax = (int) app_setting($pdo, 'kategori_baik_max', '1');
    $mediumMax = (int) app_setting($pdo, 'kategori_sedang_max', '3');
    $openDetail = trim((string) ($_GET['tingkatan'] ?? ''));
    $forceRefresh = isset($_GET['refresh']) && (string) $_GET['refresh'] === '1';

    $ranking = rekap_keaktifan_rank_tingkatan_for_periode(
        $pdo,
        $startDate,
        $endDate,
        $goodMax,
        $mediumMax,
        $forceRefresh,
        $kategori,
        $periode['kalender_hijriyah_key'] ?? null,
        true
    );

    $chartPayload = rekap_keaktifan_rank_tingkatan_chart_payload($ranking);
    $chartUid = 'rankTg' . substr(md5($startDate . $endDate . ($kategori ?? '')), 0, 8);

    $rankBadgeClass = static function (int $rank): string {
        return match ($rank) {
            1 => 'rekap-rank-pos rekap-rank-pos--1',
            2 => 'rekap-rank-pos rekap-rank-pos--2',
            3 => 'rekap-rank-pos rekap-rank-pos--3',
            default => 'rekap-rank-pos',
        };
    };

    ob_start();
    require __DIR__ . '/../../includes/partials/rekap_rank_tingkatan_content.php';
    $html = (string) ob_get_clean();

    echo json_encode([
        'ok' => true,
        'html' => $html,
        'chart' => [
            'uid' => $chartUid,
            'labels' => $chartPayload['labels'],
            'persen_hadir' => $chartPayload['persen_hadir'],
            'bar_colors' => $chartPayload['bar_colors'],
            'stacked_datasets' => $chartPayload['stacked_datasets'],
        ],
        'lazy' => [
            'apiUrl' => app_href('/api/rekap/rank_tingkatan_santri.php'),
            'params' => array_filter([
                'mode' => $mode,
                'month' => $month,
                'year' => $year,
                'kategori' => $kategoriRaw !== '' ? $kategoriRaw : null,
            ], static fn ($v) => $v !== null && $v !== ''),
        ],
        'stats' => [
            'tingkatan_count' => count($ranking),
            'periode_label' => $periodeLabel,
            'kategori_label' => $kategoriLabel,
        ],
        'open_tingkatan' => $openDetail,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[yayasan_rank_tingkatan] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Gagal memuat ranking.'], JSON_UNESCAPED_UNICODE);
}
