<?php

declare(strict_types=1);

require_once __DIR__ . '/presensi_scan_jadwal.php';
require_once __DIR__ . '/kegiatan_khusus.php';
require_once __DIR__ . '/presensi_scan_client.php';

/**
 * Jalankan alur POST presensi portal (scan.php?portal=1) dan kembalikan array JSON.
 *
 * @param array<string, mixed> $input
 * @return array<string, mixed>
 */
function presensi_scan_portal_json(PDO $pdo, array $input): array
{
    presensi_scan_ensure_schema_deferred($pdo);

    /** @var bool $pbPortalScan */
    $pbPortalScan = true;
    /** @var int $createdBy */
    $createdBy = 0;
    /** @var string|null $resultMessage */
    $resultMessage = null;
    /** @var string $resultType */
    $resultType = 'success';
    /** @var string|null $scanRedirect */
    $scanRedirect = null;
    /** @var string $izinSelesaiMsgPreset */
    $izinSelesaiMsgPreset = '';

    $savedPost = $_POST;
    $_POST = array_merge([
        'scan_source' => 'camera',
        'pb_portal_scan' => '1',
        'kode_qr' => '',
    ], $input);

    require __DIR__ . '/presensi_scan_post.inc.php';

    $_POST = $savedPost;

    $extra = [];
    $pending = $_SESSION['munawib_scan_pending'] ?? null;
    if (is_array($pending) && !empty($pending['slots'])) {
        $extra['munawib_pending'] = true;
        $extra['munawib_id'] = (int) ($pending['munawib_id'] ?? 0);
        $extra['munawib_slots'] = $pending['slots'] ?? [];
        $extra['munawib_nama'] = (string) ($pending['munawib_nama'] ?? '');
    } else {
        $extra['munawib_pending'] = false;
    }
    if ($scanRedirect !== null && $scanRedirect !== '') {
        $extra['redirect'] = $scanRedirect;
    }

    $type = $resultType ?: 'success';

    return array_merge([
        'ok' => $type === 'success',
        'type' => $type,
        'message' => $resultMessage ?: 'OK',
    ], $extra);
}
