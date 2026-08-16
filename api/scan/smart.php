<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/santri_operasional.php';
require_once __DIR__ . '/../../helpers/akademik.php';
require_once __DIR__ . '/../../helpers/presensi_admin.php';
require_once __DIR__ . '/../../helpers/presensi_notif.php';
require_once __DIR__ . '/../../helpers/munawib.php';
require_once __DIR__ . '/../../helpers/kegiatan_khusus.php';
require_once __DIR__ . '/../../helpers/pkpps.php';
require_once __DIR__ . '/../../helpers/perizinan_aktif.php';
require_once __DIR__ . '/../../helpers/offline_sync_dedup.php';
require_once __DIR__ . '/../../helpers/offline_sync_http.php';
require_once __DIR__ . '/../../helpers/presensi_scan_portal_json.php';
require_once __DIR__ . '/../../helpers/scan_smart_route.php';
require_once __DIR__ . '/../../helpers/login_qr_auth.php';
require_once __DIR__ . '/../../helpers/login_pembimbing.php';

app_scan_page_no_cache_headers();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    offline_sync_json_response('error', 'Method not allowed.');
}

$input = $_POST;
$contentType = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
if (str_contains($contentType, 'application/json')) {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $input = $decoded;
        }
    }
}

if (!table_exists($pdo, 'presensi')) {
    offline_sync_json_response('error', 'Tabel presensi belum ada.');
}

$loginDest = login_pembimbing_sanitize_dest($input['login_dest'] ?? '');
$action = trim((string) ($input['action'] ?? ''));
$qrCode = trim((string) ($input['qr_code'] ?? $input['kode_qr'] ?? ''));

/**
 * @param array<string, mixed> $presensiResult
 */
$portalAfterPresensi = static function (array $presensiResult) use ($pdo, $qrCode, $loginDest): void {
    if (!empty($presensiResult['munawib_pending'])) {
        offline_sync_json_response(
            (string) ($presensiResult['type'] ?? 'info'),
            (string) ($presensiResult['message'] ?? 'Pilih jadwal munawib.'),
            array_filter([
                'munawib_pending' => true,
                'munawib_id' => $presensiResult['munawib_id'] ?? null,
                'munawib_slots' => $presensiResult['munawib_slots'] ?? [],
                'munawib_nama' => $presensiResult['munawib_nama'] ?? '',
            ], static fn($v): bool => $v !== null)
        );
    }

    $auth = login_qr_authenticate($pdo, $qrCode, $loginDest);
    if (!$auth['ok']) {
        offline_sync_json_response('error', (string) ($auth['error'] ?? 'Gagal masuk portal.'));
    }

    $redirectPath = trim((string) ($auth['redirect'] ?? ''));
    $redirect = $redirectPath !== '' ? app_url($redirectPath) : app_url('login.php');

    if (($auth['flash_success'] ?? '') !== '') {
        set_flash('success', (string) $auth['flash_success']);
    }

    offline_sync_json_response(
        'success',
        (string) ($presensiResult['message'] ?? $auth['flash_success'] ?? 'Berhasil.'),
        array_filter([
            'redirect' => $redirect,
            'presensi_message' => $presensiResult['message'] ?? null,
            'presensi_type' => $presensiResult['type'] ?? null,
        ], static fn($v): bool => $v !== null && $v !== '')
    );
};

if ($action === 'munawib_pick_schedule') {
    if ($qrCode === '') {
        offline_sync_json_response('error', 'Kode QR munawib tidak ditemukan. Scan ulang kartu.');
    }
    $presensiResult = presensi_scan_portal_json($pdo, $input);
    $portalAfterPresensi($presensiResult);
}

if ($qrCode === '') {
    offline_sync_json_response('danger', 'Kode QR kosong.');
}

if ($loginDest === 'setoran') {
    $auth = login_qr_authenticate($pdo, $qrCode, 'setoran');
    if (!$auth['ok']) {
        offline_sync_json_response('error', (string) ($auth['error'] ?? 'Gagal masuk portal setoran.'));
    }
    $redirectPath = trim((string) ($auth['redirect'] ?? ''));
    offline_sync_json_response(
        'success',
        (string) ($auth['flash_success'] ?? 'Masuk portal setoran.'),
        ['redirect' => $redirectPath !== '' ? app_url($redirectPath) : app_url('pembimbing/setoran_dashboard.php')]
    );
}

$class = scan_smart_classify($pdo, $qrCode);

if ($class['entity'] === 'unknown') {
    offline_sync_json_response('danger', 'Peringatan: kode QR tidak terdaftar (santri, pembimbing, atau munawib).');
}

if ($class['entity'] === 'santri') {
    $presensiResult = presensi_scan_portal_json($pdo, array_merge($input, ['kode_qr' => $qrCode]));
    offline_sync_json_response(
        (string) ($presensiResult['type'] ?? 'success'),
        (string) ($presensiResult['message'] ?? 'OK'),
        array_filter([
            'munawib_pending' => $presensiResult['munawib_pending'] ?? false,
        ], static fn($v): bool => $v !== false && $v !== null)
    );
}

$clock = scan_smart_resolve_clock($input);
$hasJadwal = false;
if ($class['entity'] === 'pembimbing' && is_array($class['pembimbing'])) {
    $hasJadwal = scan_smart_pembimbing_has_jadwal(
        $pdo,
        (int) ($class['pembimbing']['id'] ?? 0),
        $clock['tanggal'],
        $clock['jam']
    );
} elseif ($class['entity'] === 'munawib' && is_array($class['munawib'])) {
    $hasJadwal = scan_smart_munawib_has_slots(
        $pdo,
        (int) ($class['munawib']['id'] ?? 0),
        $clock['tanggal'],
        $clock['jam']
    );
}

$presensiResult = ['type' => 'success', 'message' => '', 'munawib_pending' => false];
if ($hasJadwal) {
    $presensiResult = presensi_scan_portal_json($pdo, array_merge($input, ['kode_qr' => $qrCode]));
}

$portalAfterPresensi($presensiResult);
