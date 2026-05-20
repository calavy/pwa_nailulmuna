<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';

require_roles(['admin', 'pengurus']);
$waTestResult = null;

ensure_pondok_settings_defaults($pdo);
$pondokDefaults = pondok_settings_defaults();
$appNama = app_brand_nama_ponpes($pdo);

if (!table_exists($pdo, 'app_settings')) {
    set_flash('error', 'Tabel app_settings belum ada. Jalankan schema_presensi.sql di phpMyAdmin.');
    header('Location: /pwa_nailulmuna/dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));
    if ($action === 'test_wa') {
        $testTarget = trim((string) ($_POST['wa_test_target'] ?? ''));
        $testMessage = trim((string) ($_POST['wa_test_message'] ?? ('Tes WA dari ' . $appNama . '.')));
        $override = [
            'endpoint' => trim((string) ($_POST['wa_gateway_url'] ?? app_setting($pdo, 'wa_gateway_url', ''))),
            'token' => trim((string) ($_POST['wa_gateway_token'] ?? app_setting($pdo, 'wa_gateway_token', ''))),
            'sender' => trim((string) ($_POST['wa_sender'] ?? app_setting($pdo, 'wa_sender', ''))),
        ];
        $waTestResult = send_wa_message_with_result($pdo, $testTarget, $testMessage, $override);
    }

    if ($action === 'save_settings') {
    $namaPonpes = trim((string) ($_POST['nama_ponpes'] ?? ''));
    if ($namaPonpes === '') {
        set_flash('error', 'Nama pesantren wajib diisi.');
        header('Location: /pwa_nailulmuna/settings/pesantren.php');
        exit;
    }

    $fields = [
        'nama_ponpes',
        'jenis_pendidikan',
        'alamat_ponpes',
        'nama_pengasuh',
        'wa_gateway_url',
        'wa_gateway_token',
        'wa_sender',
        'wa_pengurus',
        'jam_kirim_wa_auto',
        'wa_tagihan_auto_enabled',
        'wa_tagihan_calendar',
        'wa_tagihan_day',
        'wa_tagihan_send_time',
        'batas_alpa_notif',
        'batas_telat_menit',
        'kategori_baik_max',
        'kategori_sedang_max',
        'izin_perpanjangan_max_hari',
        'izin_perpanjangan_jenis',
    ];

    foreach ($fields as $field) {
        save_setting($pdo, $field, trim((string) ($_POST[$field] ?? '')));
    }
    $calendar = strtoupper(trim((string) ($_POST['wa_tagihan_calendar'] ?? 'MASEHI')));
    if (!in_array($calendar, ['MASEHI', 'HIJRIYAH'], true)) {
        $calendar = 'MASEHI';
    }
    save_setting($pdo, 'wa_tagihan_calendar', $calendar);
    $dueDay = max(1, min(30, (int) ($_POST['wa_tagihan_day'] ?? 5)));
    save_setting($pdo, 'wa_tagihan_day', (string) $dueDay);
    save_setting($pdo, 'wa_tagihan_auto_enabled', (string) ((int) ($_POST['wa_tagihan_auto_enabled'] ?? 0) === 1 ? 1 : 0));

    $tmMode = strtoupper(trim((string) ($_POST['app_tahun_masehi_mode'] ?? 'BERJALAN')));
    if (!in_array($tmMode, ['BERJALAN', 'TETAP'], true)) {
        $tmMode = 'BERJALAN';
    }
    save_setting($pdo, 'app_tahun_masehi_mode', $tmMode);
    $tmTetap = (int) ($_POST['app_tahun_masehi_tetap'] ?? date('Y'));
    save_setting($pdo, 'app_tahun_masehi_tetap', (string) max(1900, min(2100, $tmTetap)));

    if (trim((string) ($_POST['presensi_password'] ?? '')) !== '') {
        save_setting($pdo, 'presensi_password', password_hash(trim((string) $_POST['presensi_password']), PASSWORD_DEFAULT));
    }

    if (isset($_FILES['logo_file']) && is_array($_FILES['logo_file']) && (int) $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
        $tmpFile = (string) $_FILES['logo_file']['tmp_name'];
        $originalName = (string) $_FILES['logo_file']['name'];
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];

        if (in_array($extension, $allowed, true)) {
            $targetDir = __DIR__ . '/../../uploads/logos';
            if (!is_dir($targetDir)) {
                mkdir($targetDir, 0777, true);
            }

            $safeName = 'logo-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
            $targetPath = $targetDir . '/' . $safeName;

            if (move_uploaded_file($tmpFile, $targetPath)) {
                save_setting($pdo, 'logo_path', 'uploads/logos/' . $safeName);
            }
        } else {
            set_flash('error', 'Format logo tidak didukung. Gunakan JPG, PNG, atau WEBP.');
            header('Location: /pwa_nailulmuna/settings/pesantren.php');
            exit;
        }
    }

    set_flash('success', 'Pengaturan berhasil disimpan.');
    header('Location: /pwa_nailulmuna/settings/pesantren.php');
    exit;
    }
}

$values = [];
foreach ([
    'nama_ponpes',
    'jenis_pendidikan',
    'alamat_ponpes',
    'nama_pengasuh',
    'logo_path',
    'wa_gateway_url',
    'wa_gateway_token',
    'wa_sender',
    'wa_pengurus',
    'jam_kirim_wa_auto',
    'wa_tagihan_auto_enabled',
    'wa_tagihan_calendar',
    'wa_tagihan_day',
    'wa_tagihan_send_time',
    'batas_alpa_notif',
    'batas_telat_menit',
    'kategori_baik_max',
    'kategori_sedang_max',
    'izin_perpanjangan_max_hari',
    'izin_perpanjangan_jenis',
    'app_tahun_masehi_mode',
    'app_tahun_masehi_tetap',
] as $key) {
    $values[$key] = app_setting($pdo, $key, $pondokDefaults[$key] ?? '');
}
$waConfigured = trim((string) ($values['wa_gateway_token'] ?? '')) !== '';
$logoConfigured = trim((string) ($values['logo_path'] ?? '')) !== '';
$pengurusWaCount = 0;
if (trim((string) ($values['wa_pengurus'] ?? '')) !== '') {
    $pengurusWaCount = count(preg_split('/[\s,;]+/', (string) $values['wa_pengurus'], -1, PREG_SPLIT_NO_EMPTY) ?: []);
}
$values['wa_tagihan_auto_enabled'] = ($values['wa_tagihan_auto_enabled'] ?? '') === '1' ? '1' : '0';
$values['wa_tagihan_calendar'] = in_array(strtoupper((string) ($values['wa_tagihan_calendar'] ?? '')), ['MASEHI', 'HIJRIYAH'], true)
    ? strtoupper((string) $values['wa_tagihan_calendar'])
    : 'MASEHI';
$tagihanDayRaw = (int) ($values['wa_tagihan_day'] ?? 5);
$values['wa_tagihan_day'] = (string) max(1, min(30, $tagihanDayRaw > 0 ? $tagihanDayRaw : 5));

$values['app_tahun_masehi_mode'] = in_array(strtoupper((string) ($values['app_tahun_masehi_mode'] ?? '')), ['BERJALAN', 'TETAP'], true)
    ? strtoupper((string) $values['app_tahun_masehi_mode'])
    : 'BERJALAN';
$tetapRaw = (int) ($values['app_tahun_masehi_tetap'] ?? (int) date('Y'));
$values['app_tahun_masehi_tetap'] = (string) max(1900, min(2100, $tetapRaw > 0 ? $tetapRaw : (int) date('Y')));
