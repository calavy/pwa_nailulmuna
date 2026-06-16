<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../helpers/app.php';
require_once __DIR__ . '/../helpers/yayasan_musyawarah.php';

require_roles(['admin', 'pengurus']);

yayasan_musyawarah_ensure_schema($pdo);

$rapatFilter = (int) ($_GET['rapat_id'] ?? 0);
$rapatFilter = $rapatFilter > 0 ? $rapatFilter : null;
$createdBy = (int) ($_SESSION['user']['id'] ?? 1);

$resultMessage = null;
$resultType = 'success';
$scanRedirect = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $hasil = yayasan_musyawarah_proses_scan_post($pdo, $_POST, $createdBy, $rapatFilter);
    $resultType = (string) ($hasil['resultType'] ?? 'success');
    $resultMessage = $hasil['resultMessage'] ?? null;
    $scanRedirect = $hasil['scanRedirect'] ?? null;

    if ($resultMessage !== null && $resultMessage !== '' && $resultType === 'warning') {
        $pendingMs = $_SESSION['yayasan_musyawarah_scan_pending'] ?? null;
        if (is_array($pendingMs) && !empty($pendingMs['rapats'])) {
            $resultType = 'info';
        } elseif (preg_match('/sudah tercatat|Scan ditolak/i', $resultMessage)) {
            $resultType = 'duplicate';
        } else {
            $resultType = 'danger';
        }
    }

    require_once __DIR__ . '/../helpers/offline_sync_http.php';
    if (offline_sync_wants_json()) {
        $pendingMs = $_SESSION['yayasan_musyawarah_scan_pending'] ?? null;
        $extra = ['musyawarah_pending' => false];
        if (is_array($pendingMs)) {
            $extra['musyawarah_pending'] = true;
            $extra['pengurus_id'] = (int) ($pendingMs['pengurus_id'] ?? 0);
            $extra['musyawarah_rapats'] = $pendingMs['rapats'] ?? [];
            $extra['pengurus_nama'] = (string) ($pendingMs['pengurus_nama'] ?? '');
        }
        if ($scanRedirect !== null && $scanRedirect !== '') {
            $extra['redirect'] = $scanRedirect;
        }
        offline_sync_json_response(
            $resultType ?: 'success',
            $resultMessage ?: 'OK',
            $extra
        );
    }
}

$pendingMusyawarahPick = $_SESSION['yayasan_musyawarah_scan_pending'] ?? null;
$scanJadwalCtx = yayasan_musyawarah_scan_jadwal_context($pdo, $rapatFilter);
$timerState = (string) ($scanJadwalCtx['state'] ?? 'none');
$timerClass = in_array($timerState, ['active', 'upcoming', 'ended', 'libur', 'none'], true) ? $timerState : 'none';
$timerSec = $timerState === 'active'
    ? (int) ($scanJadwalCtx['seconds_remaining'] ?? 0)
    : ($timerState === 'upcoming' ? (int) ($scanJadwalCtx['seconds_until_start'] ?? 0) : 0);
$timerClockInit = sprintf('%02d:%02d', (int) floor($timerSec / 60), $timerSec % 60);

$rapatJudul = '';
if ($rapatFilter !== null) {
    $stR = $pdo->prepare('SELECT judul FROM yayasan_rapat WHERE id = :id LIMIT 1');
    $stR->execute(['id' => $rapatFilter]);
    $rapatJudul = trim((string) ($stR->fetchColumn() ?: ''));
}

$scanBackUrl = $rapatFilter !== null
    ? app_href('/yayasan/musyawarah_presensi.php?rapat_id=' . $rapatFilter)
    : app_href('/yayasan/rapat.php');
$scanBackLabel = $rapatFilter !== null ? 'Rekap presensi' : 'Rapat yayasan';
$scanTopTitle = $rapatJudul !== '' ? ('Scan · ' . $rapatJudul) : 'Scan Musyawarah';
$scanTimerNoneLabel = 'Belum ada rapat musyawarah aktif';
$scanFormExtraHtml = $rapatFilter !== null
    ? '<input type="hidden" name="rapat_id_filter" value="' . (int) $rapatFilter . '">'
    : '';

$pageTitle = 'Scan Musyawarah';
$bodyClass = 'scan-simple-page';
$pageStylesheets = [app_asset_href('/assets/css/presensi-scan.css')];
require_once __DIR__ . '/../includes/header.php';

require __DIR__ . '/../includes/partials/presensi_scan_ui.php';

require_once __DIR__ . '/../includes/footer.php';
