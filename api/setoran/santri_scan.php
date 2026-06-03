<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../helpers/app.php';
require_once __DIR__ . '/../../helpers/akademik_setoran.php';

header('Content-Type: application/json; charset=UTF-8');

akademik_setoran_require_access();
ensure_akademik_setoran_extended_schema($pdo);

$code = trim((string) ($_GET['code'] ?? $_POST['code'] ?? ''));
if ($code === '') {
    echo json_encode(['ok' => false, 'message' => 'Kode QR kosong.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$santri = akademik_setoran_resolve_santri_qr($pdo, $code);
if ($santri === null) {
    echo json_encode(['ok' => false, 'message' => 'QR santri tidak terdaftar atau tidak aktif.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ctx = akademik_setoran_petugas_context($pdo);
if (!akademik_setoran_can_terima_santri($pdo, $santri, $ctx)) {
    echo json_encode([
        'ok' => false,
        'message' => 'Santri tingkatan ' . ($santri['tingkatan'] ?? '-') . ' di luar cakupan setoran Anda.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$today = date('Y-m-d');
$tingkatan = (string) ($santri['tingkatan'] ?? '');
$kitabRows = akademik_setoran_kitab_rows_for_tingkatan($pdo, $tingkatan);
$kitabPayload = [];
foreach ($kitabRows as $kr) {
    $kid = (int) ($kr['id'] ?? 0);
    $kitabPayload[] = [
        'id' => $kid,
        'nama_kitab' => (string) ($kr['nama_kitab'] ?? ''),
        'target_harian' => (int) ($kr['target_baris_per_hari'] ?? 1),
        'jumlah_baris' => (int) ($kr['jumlah_baris'] ?? 0),
        'perolehan' => akademik_setoran_perolehan_bait($pdo, (int) $santri['id'], $kid),
        'last_baris' => akademik_setoran_last_baris($pdo, (int) $santri['id'], $kid),
    ];
}

$libur = akademik_libur_info($pdo, $today, 'setoran');
$sudah = akademik_setoran_sudah_hari_ini($pdo, (int) $santri['id'], $today);
$izin = akademik_setoran_izin_atau_sakit($pdo, (int) $santri['id'], $today);

echo json_encode([
    'ok' => true,
    'santri' => [
        'id' => (int) $santri['id'],
        'nis' => (string) ($santri['nis'] ?? ''),
        'nama_santri' => (string) ($santri['nama_santri'] ?? ''),
        'tingkatan' => $tingkatan,
    ],
    'tanggal' => $today,
    'kitab' => $kitabPayload,
    'sudah_setor_hari_ini' => $sudah,
    'izin_atau_sakit' => $izin,
    'libur' => $libur !== null ? (string) ($libur['nama'] ?? 'Libur') : null,
    'redirect' => app_href('/pembimbing/setoran.php?santri_id=' . (int) $santri['id']),
], JSON_UNESCAPED_UNICODE);
