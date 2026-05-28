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
    header('Location: ' . app_href('/dashboard.php'));
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
        header('Location: ' . app_href('/settings/pesantren.php'));
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
        'wa_petugas_pendidikan',
        'wa_notif_mudabir_enabled',
        'mudabir_batas_menit',
        'wa_kelas_kosong_enabled',
        'wa_kelas_kosong_batas_menit',
        'wa_kelas_kosong_target_1',
        'wa_kelas_kosong_target_3',
        'jam_kirim_wa_auto',
        'wa_tagihan_auto_enabled',
        'keterangan_pengurus_bidang_keuangan',
        'batas_alpa_notif',
        'batas_telat_menit',
        'kategori_baik_max',
        'kategori_sedang_max',
        'izin_perpanjangan_max_hari',
        'izin_perpanjangan_jenis',
    ];

    foreach ($fields as $field) {
        if (!array_key_exists($field, $_POST)) {
            continue;
        }
        save_setting($pdo, $field, trim((string) ($_POST[$field] ?? '')));
    }
    if (array_key_exists('wa_tagihan_auto_enabled', $_POST)) {
        save_setting($pdo, 'wa_tagihan_auto_enabled', (string) ((int) ($_POST['wa_tagihan_auto_enabled'] ?? 0) === 1 ? 1 : 0));
    }
    if (array_key_exists('wa_notif_mudabir_enabled', $_POST)) {
        save_setting($pdo, 'wa_notif_mudabir_enabled', (string) ((int) ($_POST['wa_notif_mudabir_enabled'] ?? 0) === 1 ? 1 : 0));
    }
    if (array_key_exists('wa_kelas_kosong_enabled', $_POST)) {
        save_setting($pdo, 'wa_kelas_kosong_enabled', (string) ((int) ($_POST['wa_kelas_kosong_enabled'] ?? 0) === 1 ? 1 : 0));
    }

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
            header('Location: ' . app_href('/settings/pesantren.php'));
            exit;
        }
    }

    set_flash('success', 'Pengaturan berhasil disimpan.');
    header('Location: ' . app_href('/settings/pesantren.php'));
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
    'wa_petugas_pendidikan',
    'wa_notif_mudabir_enabled',
    'mudabir_batas_menit',
    'wa_kelas_kosong_enabled',
    'wa_kelas_kosong_batas_menit',
    'wa_kelas_kosong_target_1',
    'wa_kelas_kosong_target_3',
    'wa_kelas_kosong_last_sent_at',
    'wa_kelas_kosong_last_level',
    'jam_kirim_wa_auto',
    'wa_tagihan_auto_enabled',
    'keterangan_pengurus_bidang_keuangan',
    'batas_alpa_notif',
    'batas_telat_menit',
    'kategori_baik_max',
    'kategori_sedang_max',
    'izin_perpanjangan_max_hari',
    'izin_perpanjangan_jenis',
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
$values['wa_notif_mudabir_enabled'] = ($values['wa_notif_mudabir_enabled'] ?? '') === '1' ? '1' : '0';
$values['wa_kelas_kosong_enabled'] = ($values['wa_kelas_kosong_enabled'] ?? '') === '1' ? '1' : '0';
